<?php

namespace App\Console\Commands;

use App\George\Ensemble;
use Codewithkyrian\Transformers\Models\Auto\AutoModelForCausalLM;
use Codewithkyrian\Transformers\PreTrainedTokenizers\AutoTokenizer;
use Codewithkyrian\Transformers\Transformers;
use Codewithkyrian\Transformers\Utils\Hub;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Process\Process;
use Throwable;

#[AsCommand(name: 'george:download', description: 'Download the ONNX models George uses for local inference.')]
class GeorgeDownloadCommand extends Command
{
    protected $signature = 'george:download
        {model? : Hugging Face model id (NLI slot only)}
        {--quantized=true : Download the quantized ONNX weights}
        {--skip-reasoner : Do not download the reasoner model}';

    public function handle(): int
    {
        $cacheDir = (string) config('george.cache_dir');
        $quantized = filter_var($this->option('quantized'), FILTER_VALIDATE_BOOLEAN) ? 'true' : 'false';

        $models = $this->argument('model')
            ? [(string) $this->argument('model')]
            : array_values(array_unique(array_filter([
                (string) config('george.model'),
                Ensemble::configured() ? (string) config('george.model_b') : null,
            ])));

        foreach ($models as $model) {
            $status = $this->downloadModel($model, $cacheDir, $quantized);

            if ($status !== self::SUCCESS) {
                return $status;
            }
        }

        if (! $this->argument('model') && ! $this->option('skip-reasoner') && Ensemble::reasonerConfigured()) {
            $status = $this->downloadReasoner($cacheDir);

            if ($status !== self::SUCCESS) {
                return $status;
            }
        }

        $this->components->info('Models ready. Start George with `composer run dev` or `php artisan george:work`.');

        return self::SUCCESS;
    }

    private function downloadModel(string $model, string $cacheDir, string $quantized): int
    {
        if (! is_dir($cacheDir)) {
            mkdir($cacheDir, 0755, true);
        }

        $binary = base_path('vendor/bin/transformers');

        if (! is_file($binary)) {
            $this->components->error('TransformersPHP CLI not found. Run composer install first.');

            return self::FAILURE;
        }

        $this->components->info("Downloading {$model} into {$cacheDir}");

        $process = new Process(
            [
                PHP_BINARY,
                '-d',
                'memory_limit=2048M',
                $binary,
                'download',
                $model,
                'zero-shot-classification',
                '--cache-dir',
                $cacheDir,
                '--quantized',
                $quantized,
                '--no-interaction',
            ],
            base_path(),
        );

        $process->setTimeout(600);
        $process->setIdleTimeout(600);

        $process->run(function (string $type, string $buffer): void {
            $this->output->write($buffer);
        });

        if (! $process->isSuccessful()) {
            $this->components->error("Download failed for {$model}.");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * The reasoner is a causal LM with a specific ONNX variant (q4 by
     * default), which the TransformersPHP CLI cannot pick, so it goes
     * through the library directly.
     */
    private function downloadReasoner(string $cacheDir): int
    {
        $model = (string) config('george.reasoner_model');
        $file = (string) config('george.reasoner_file', 'model_q4');

        ini_set('memory_limit', '4096M');

        $this->components->info("Downloading reasoner {$model} ({$file}.onnx) into {$cacheDir}");

        $lastPercent = -1;
        $progress = function (string $event, string $fileName, int|float $size = 0, int|float $done = 0) use (&$lastPercent): void {
            if ($event === 'begin_download') {
                $this->line("  ↓ {$fileName}");
                $lastPercent = -1;

                return;
            }

            if ($event === 'advance_download' && $size > 0) {
                $percent = (int) floor(($done / $size) * 100);

                if ($percent !== $lastPercent && $percent % 5 === 0) {
                    $lastPercent = $percent;
                    $this->output->write(sprintf("\r    %3d%% of %.2f GB", $percent, $size / 1e9));
                }

                return;
            }

            if ($event === 'complete_download') {
                $this->output->write("\r");
                $this->line("  ✓ {$fileName}");
            }
        };

        try {
            Transformers::setup()->setCacheDir($cacheDir)->apply();

            AutoTokenizer::fromPretrained($model, $cacheDir, onProgress: $progress);

            // Models over about 2 GB keep their weights in sibling files that
            // ONNX Runtime opens by name. The library only fetches the graph,
            // so without these the session fails to load.
            $external = $this->downloadExternalData($model, $file, $cacheDir, $progress);

            if ($external > 0) {
                $this->line(sprintf('  ✓ %d external weight file%s', $external, $external === 1 ? '' : 's'));
            }

            AutoModelForCausalLM::fromPretrained(
                $model,
                true,
                null,
                $cacheDir,
                'main',
                $file,
                $progress,
            );
        } catch (Throwable $e) {
            $this->components->error("Reasoner download failed for {$model}: ".$e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * Fetch model_q4.onnx_data, model_q4.onnx_data_1, … until one is missing.
     */
    private function downloadExternalData(string $model, string $file, string $cacheDir, callable $progress): int
    {
        $found = 0;

        for ($i = 0; $i < 16; $i++) {
            $name = $file.'.onnx_data'.($i === 0 ? '' : '_'.$i);

            $path = Hub::getFile(
                pathOrRepoID: $model,
                fileName: $name,
                cacheDir: $cacheDir,
                revision: 'main',
                subFolder: 'onnx',
                fatal: false,
                onProgress: $progress,
            );

            if ($path === null) {
                break;
            }

            $found++;
        }

        return $found;
    }
}

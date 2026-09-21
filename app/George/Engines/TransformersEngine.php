<?php

namespace App\George\Engines;

use App\George\Contracts\Engine;
use App\George\Support\Hypotheses;
use Codewithkyrian\Transformers\Transformers;
use Codewithkyrian\Transformers\Utils\ImageDriver;
use RuntimeException;

use function Codewithkyrian\Transformers\Pipelines\pipeline;

final class TransformersEngine implements Engine
{
    private mixed $pipeline = null;

    private bool $booted = false;

    public function __construct(private readonly ?string $model = null) {}

    public function classify(
        string $sequence,
        array $labels,
        bool $multiLabel = false,
        string $hypothesisTemplate = '{}',
    ): array {
        $pipeline = $this->pipeline();

        return $pipeline(
            $sequence,
            $labels,
            multiLabel: $multiLabel,
            hypothesisTemplate: $hypothesisTemplate,
        );
    }

    public function warmup(): void
    {
        $this->pipeline();
        $this->classify('George is warming up.', ['ready'], true, Hypotheses::TEMPLATE);
    }

    public function modelName(): string
    {
        return $this->model ?? (string) config('george.model');
    }

    public function driver(): string
    {
        return 'transformers';
    }

    public function isReady(): bool
    {
        return self::modelIsCached($this->modelName());
    }

    public static function modelIsCached(?string $model = null, ?string $cacheDir = null): bool
    {
        $model ??= (string) config('george.model');
        $cacheDir ??= (string) config('george.cache_dir');

        $relative = str_replace('/', DIRECTORY_SEPARATOR, $model);
        $configPath = rtrim($cacheDir, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$relative.DIRECTORY_SEPARATOR.'config.json';

        return is_file($configPath);
    }

    private function pipeline(): mixed
    {
        if ($this->pipeline !== null) {
            return $this->pipeline;
        }

        $this->boot();

        $cacheDir = (string) config('george.cache_dir');

        if (! is_dir($cacheDir)) {
            mkdir($cacheDir, 0755, true);
        }

        $this->pipeline = pipeline(
            'zero-shot-classification',
            $this->modelName(),
            quantized: (bool) config('george.quantized'),
            cacheDir: $cacheDir,
        );

        return $this->pipeline;
    }

    private function boot(): void
    {
        if ($this->booted) {
            return;
        }

        if (! extension_loaded('ffi')) {
            throw new RuntimeException('PHP FFI is required to run George. Enable the ffi extension and set ffi.enable=true.');
        }

        $memory = ini_get('memory_limit');
        if ($memory !== '-1' && $this->memoryToBytes((string) $memory) < 1536 * 1024 * 1024) {
            ini_set('memory_limit', '2048M');
        }

        Transformers::setup()
            ->setCacheDir((string) config('george.cache_dir'))
            ->setImageDriver(ImageDriver::GD)
            ->apply();

        $this->booted = true;
    }

    private function memoryToBytes(string $value): int
    {
        $value = trim($value);
        $unit = strtolower(substr($value, -1));
        $number = (int) $value;

        return match ($unit) {
            'g' => $number * 1024 * 1024 * 1024,
            'm' => $number * 1024 * 1024,
            'k' => $number * 1024,
            default => (int) $value,
        };
    }
}

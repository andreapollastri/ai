<?php

namespace App\Providers;

use App\George\Classifier;
use App\George\Contracts\Engine;
use App\George\Contracts\Reasoner;
use App\George\EngineFactory;
use App\George\Support\TemperatureScaler;
use Illuminate\Foundation\DevCommands;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(EngineFactory::class);

        $this->app->singleton(
            Engine::class,
            fn (): Engine => $this->app->make(EngineFactory::class)->make(),
        );

        $this->app->singleton(
            Reasoner::class,
            fn (): Reasoner => $this->app->make(EngineFactory::class)->makeReasoner(),
        );

        $this->app->singleton(
            TemperatureScaler::class,
            fn (): TemperatureScaler => new TemperatureScaler((float) config('george.temperature')),
        );

        $this->app->singleton(Classifier::class);
    }

    public function boot(): void
    {
        DevCommands::artisan('george:work --slot=all --tries=1 --timeout=180 --memory=4096', 'queue');
    }
}

<?php

declare(strict_types=1);

namespace gunner42\Eet;

use gunner42\Eet\Console\Commands\EetCommand;
use Illuminate\Support\ServiceProvider;

class EetServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/laravel-eet-module.php', 'laravel-eet-module');

        $this->app->singleton(Eet::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../routes/laravel-eet-module.php');

        $this->loadViewsFrom(__DIR__.'/../resources/views', 'laravel-eet-module');

        $this->loadTranslationsFrom(__DIR__.'/../lang', 'laravel-eet-module');

        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__.'/../config/laravel-eet-module.php' => config_path('laravel-eet-module.php'),
        ], ['laravel-eet-module', 'laravel-eet-module-config']);

        $this->publishes([
            __DIR__.'/../resources/views' => resource_path('views/vendor/laravel-eet-module'),
        ], ['laravel-eet-module', 'laravel-eet-module-views']);

        $this->publishes([
            __DIR__.'/../lang' => $this->app->langPath('vendor/laravel-eet-module'),
        ], ['laravel-eet-module', 'laravel-eet-module-lang']);

        $this->publishes([
            __DIR__.'/../public' => public_path('vendor/laravel-eet-module'),
        ], ['laravel-eet-module', 'laravel-eet-module-assets']);

        $this->publishesMigrations([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], ['laravel-eet-module', 'laravel-eet-module-migrations']);

        $this->commands([
            EetCommand::class,
        ]);
    }
}

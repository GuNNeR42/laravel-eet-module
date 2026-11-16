<?php

declare(strict_types=1);

namespace Gunner42\LaravelEetModule;

use Gunner42\LaravelEetModule\Console\Commands\ImportCertificateCommand;
use Gunner42\LaravelEetModule\Console\Commands\PruneCommand;
use Gunner42\LaravelEetModule\Console\Commands\ResubmitCommand;
use Gunner42\LaravelEetModule\Console\Commands\StatusCommand;
use Gunner42\LaravelEetModule\Console\Commands\VerifyCommand;
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
        $this->loadTranslationsFrom(__DIR__.'/../lang', 'laravel-eet-module');

        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__.'/../config/laravel-eet-module.php' => config_path('laravel-eet-module.php'),
        ], ['laravel-eet-module', 'laravel-eet-module-config']);

        $this->publishesMigrations([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], ['laravel-eet-module', 'laravel-eet-module-migrations']);

        $this->publishes([
            __DIR__.'/../lang' => $this->app->langPath('vendor/laravel-eet-module'),
        ], ['laravel-eet-module', 'laravel-eet-module-lang']);

        $this->commands([
            ImportCertificateCommand::class,
            VerifyCommand::class,
            ResubmitCommand::class,
            StatusCommand::class,
            PruneCommand::class,
        ]);
    }
}

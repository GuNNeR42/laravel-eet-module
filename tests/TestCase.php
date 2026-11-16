<?php

declare(strict_types=1);

namespace Gunner42\LaravelEetModule\Tests;

use Gunner42\LaravelEetModule\EetServiceProvider;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    use RefreshDatabase;

    /** Base64 key used as APP_KEY in the tests (fixed, so encrypted columns are reproducible). */
    public const APP_KEY = 'base64:dGVzdHMtYXBwLWtleS1mb3ItZWV0LW1vZHVsZS0zMmI=';

    protected function getPackageProviders($app): array
    {
        return [
            EetServiceProvider::class,
        ];
    }

    /**
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', self::APP_KEY);
        $app['config']->set('app.timezone', 'UTC');
        $app['config']->set('database.default', 'testing');
        $app['config']->set('cache.default', 'array');
        $app['config']->set('queue.default', 'sync');
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }
}

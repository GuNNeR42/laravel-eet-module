<?php

declare(strict_types=1);

namespace Gunner42\LaravelEetModule\Tests;

use Gunner42\LaravelEetModule\EetServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            EetServiceProvider::class,
        ];
    }
}

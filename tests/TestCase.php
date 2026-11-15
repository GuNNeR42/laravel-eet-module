<?php

declare(strict_types=1);

namespace gunner42\Eet\Tests;

use gunner42\Eet\EetServiceProvider;
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

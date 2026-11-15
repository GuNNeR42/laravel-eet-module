<?php

declare(strict_types=1);

use Gunner42\LaravelEetModule\Eet;

it('resolves the singleton', function () {
    expect(app(Eet::class))->toBeInstanceOf(Eet::class);
});

it('returns the same instance from the container', function () {
    expect(app(Eet::class))->toBe(app(Eet::class));
});

it('merges the package config', function () {
    expect(config('laravel-eet-module.placeholder'))->toBe('default');
});

it('loads the package translations', function () {
    expect(trans('laravel-eet-module::messages.placeholder'))->toBe('Eet placeholder translation.');
});

it('loads the package views', function () {
    expect(view()->exists('laravel-eet-module::placeholder'))->toBeTrue();
});

it('registers the artisan command', function () {
    $this->artisan('laravel-eet-module:placeholder')
        ->expectsOutputToContain('Eet placeholder command executed.')
        ->assertSuccessful();
});

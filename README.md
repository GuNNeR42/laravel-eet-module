<div align="center">
    <h1>EET 2.0 Module</h1>
</div>

<p align="center">
    <a href="https://packagist.org/packages/gunner42/laravel-eet-module"><img src="https://img.shields.io/packagist/v/gunner42/laravel-eet-module.svg?style=flat-square" alt="Packagist"></a>
    <a href="https://packagist.org/packages/gunner42/laravel-eet-module"><img src="https://img.shields.io/packagist/php-v/gunner42/laravel-eet-module.svg?style=flat-square" alt="PHP from Packagist"></a>
    <a href="https://packagist.org/packages/gunner42/laravel-eet-module"><img src="https://badge.laravel.cloud/badge/gunner42/laravel-eet-module?style=flat" alt="Laravel versions"></a>
    <a href="https://github.com/gunner42/laravel-eet-module/actions"><img alt="GitHub Workflow Status (main)" src="https://img.shields.io/github/actions/workflow/status/gunner42/laravel-eet-module/tests.yml?branch=main&label=Tests&style=flat-square"></a>
    <a href="https://packagist.org/packages/gunner42/laravel-eet-module"><img src="https://img.shields.io/packagist/dt/gunner42/laravel-eet-module.svg?style=flat-square" alt="Total Downloads"></a>
</p>

Module for CZ EET 2.0

## Installation

You can install the package via Composer:

```bash
composer require gunner42/laravel-eet-module
```

You may publish all of the package's resources at once:

```bash
php artisan vendor:publish --tag="laravel-eet-module"
```

Or, you may publish each resource individually:

### Publishing the Configuration File

```bash
php artisan vendor:publish --tag="laravel-eet-module-config"
```

### Publishing and Running the Migrations

```bash
php artisan vendor:publish --tag="laravel-eet-module-migrations"
php artisan migrate
```

### Publishing the Views

```bash
php artisan vendor:publish --tag="laravel-eet-module-views"
```

### Publishing the Translations

```bash
php artisan vendor:publish --tag="laravel-eet-module-lang"
```

### Publishing the Public Assets

```bash
php artisan vendor:publish --tag="laravel-eet-module-assets"
```

## Usage

<!-- Add a basic usage example here. -->

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Contributing

Thank you for considering contributing to EET 2.0 Module! Please review our [contributing guide](.github/CONTRIBUTING.md) to get started.

## Security Vulnerabilities

Please review [our security policy](.github/SECURITY.md) on how to report security vulnerabilities.

## Credits

- [GuNNeR42](https://github.com/gunner42)
- [All Contributors](../../contributors)

## License

EET 2.0 Module is open-sourced software licensed under the [MIT license](LICENSE.md).

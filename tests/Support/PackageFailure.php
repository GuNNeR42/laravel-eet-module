<?php

declare(strict_types=1);

namespace Gunner42\LaravelEetModule\Tests\Support;

use Gunner42\LaravelEetModule\Exceptions\EetException;

/**
 * A package exception (EetException) thrown by test stubs, to check how
 * commands report exceptions of the package (R105, Q72).
 *
 * This is test support code, not package code.
 */
final class PackageFailure extends EetException {}

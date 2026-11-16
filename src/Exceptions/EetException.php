<?php

declare(strict_types=1);

namespace Gunner42\LaravelEetModule\Exceptions;

use RuntimeException;

/**
 * Base class of every package exception. Messages never contain a private
 * key, certificate contents or a password (R15).
 */
abstract class EetException extends RuntimeException {}

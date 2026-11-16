<?php

declare(strict_types=1);

namespace Gunner42\LaravelEetModule\Exceptions;

use RuntimeException;

/**
 * Aborts the download of a response larger than response_max_bytes (R66).
 * Never leaves the transport.
 *
 * @internal
 */
final class ResponseTooLarge extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('too_large');
    }
}

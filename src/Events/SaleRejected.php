<?php

declare(strict_types=1);

namespace Gunner42\LaravelEetModule\Events;

use Gunner42\LaravelEetModule\Models\Sale;

/**
 * The sale was rejected; carries the error code of the last attempt (R39, R41).
 */
final class SaleRejected
{
    public function __construct(public readonly Sale $sale, public readonly ?int $errorCode) {}
}

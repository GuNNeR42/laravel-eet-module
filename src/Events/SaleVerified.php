<?php

declare(strict_types=1);

namespace Gunner42\LaravelEetModule\Events;

use Gunner42\LaravelEetModule\Models\Sale;

/**
 * The sale was verified in verification mode (R39, R46).
 */
final class SaleVerified
{
    public function __construct(public readonly Sale $sale) {}
}

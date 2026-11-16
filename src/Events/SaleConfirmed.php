<?php

declare(strict_types=1);

namespace Gunner42\LaravelEetModule\Events;

use Gunner42\LaravelEetModule\Models\Sale;

/**
 * The sale was confirmed (R39).
 */
final class SaleConfirmed
{
    public function __construct(public readonly Sale $sale) {}
}

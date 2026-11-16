<?php

declare(strict_types=1);

namespace Gunner42\LaravelEetModule\Events;

use Gunner42\LaravelEetModule\Models\Sale;

/**
 * A live production sale is unconfirmed for more than 48 h (R44).
 */
final class SaleOverdue
{
    public function __construct(public readonly Sale $sale) {}
}

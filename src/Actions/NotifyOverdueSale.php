<?php

declare(strict_types=1);

namespace Gunner42\LaravelEetModule\Actions;

use Gunner42\LaravelEetModule\Events\SaleOverdue;
use Gunner42\LaravelEetModule\Models\Sale;

/**
 * Dispatches SaleOverdue once for an overdue sale (R44).
 *
 * @internal
 */
final class NotifyOverdueSale
{
    public function handle(Sale $sale): bool
    {
        if ($sale->overdue_notified_at !== null || ! $sale->isOverdue()) {
            return false;
        }

        $updated = Sale::query()
            ->whereKey($sale->id)
            ->whereNull('overdue_notified_at')
            ->update(['overdue_notified_at' => now(), 'updated_at' => now()]);

        if ($updated !== 1) {
            return false;
        }

        $sale->refresh();
        event(new SaleOverdue($sale));

        return true;
    }
}

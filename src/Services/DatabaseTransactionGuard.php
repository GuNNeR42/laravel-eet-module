<?php

declare(strict_types=1);

namespace Gunner42\LaravelEetModule\Services;

use Gunner42\LaravelEetModule\Exceptions\ActiveDatabaseTransaction;
use Gunner42\LaravelEetModule\Models\Sale;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseTransactionRecord;
use Illuminate\Foundation\Testing\DatabaseTransactionsManager as TestingTransactionsManager;

/**
 * register(), reverse() and resubmit() refuse to run inside an open database
 * transaction of the host (R72). In tests the transaction opened by
 * RefreshDatabase or DatabaseTransactions does not count.
 */
final class DatabaseTransactionGuard
{
    public function ensureNoTransaction(): void
    {
        $connection = (new Sale)->getConnection();

        if ($this->hostTransactionIsOpen($connection)) {
            throw new ActiveDatabaseTransaction;
        }
    }

    private function hostTransactionIsOpen(Connection $connection): bool
    {
        if ($connection->transactionLevel() === 0) {
            return false;
        }

        $manager = app()->bound('db.transactions') ? app('db.transactions') : null;

        if (app()->runningUnitTests() && $manager instanceof TestingTransactionsManager) {
            return $manager->callbackApplicableTransactions()
                ->contains(static fn (DatabaseTransactionRecord $record): bool => $record->connection === $connection->getName());
        }

        return true;
    }
}

<?php

declare(strict_types=1);

namespace Gunner42\LaravelEetModule\Console\Commands;

use Illuminate\Console\Command;

class EetCommand extends Command
{
    /**
     * The command signature.
     */
    protected $signature = 'laravel-eet-module:placeholder';

    /**
     * The command description.
     */
    protected $description = 'Placeholder Artisan command shipped by the package laravel-eet-module.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->line('Eet placeholder command executed.');

        return self::SUCCESS;
    }
}

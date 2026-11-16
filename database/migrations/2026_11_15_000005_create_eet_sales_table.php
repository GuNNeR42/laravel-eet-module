<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('eet_sales', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('taxpayer_id')->constrained('eet_taxpayers');
            $table->foreignId('cash_register_id')->constrained('eet_cash_registers');
            $table->foreignId('reversed_sale_id')->nullable()->constrained('eet_sales');
            $table->string('eic', 12);
            $table->string('delegating_eic', 12)->nullable();
            $table->boolean('delegated_by_multiple_taxpayers')->default(false);
            $table->unsignedInteger('registration_unit_number');
            $table->string('cash_register_identifier', 20);
            $table->string('sequence_number', 25);
            $table->string('occurred_at', 25);
            $table->bigInteger('total_amount');
            $table->bigInteger('amount_for_later_drawing')->nullable();
            $table->bigInteger('drawn_amount')->nullable();
            $table->string('unique_sale_code');
            $table->string('environment');
            $table->string('mode');
            $table->string('status');
            $table->string('confirmation_code', 39)->nullable();
            $table->boolean('is_test')->default(false);
            $table->json('preflight_warnings');
            $table->dateTime('confirmed_at')->nullable();
            $table->dateTime('overdue_notified_at')->nullable();
            $table->unsignedBigInteger('rejecting_attempt_id')->nullable();
            $table->dateTime('reopened_at')->nullable();
            $table->unsignedBigInteger('reopened_after_attempt_id')->nullable();
            $table->boolean('is_fake')->default(false);
            $table->timestamps();

            $table->unique(['environment', 'unique_sale_code']);
            $table->unique(
                ['environment', 'eic', 'registration_unit_number', 'cash_register_identifier', 'sequence_number', 'occurred_at'],
                'eet_sales_sale_identity_unique',
            );
            $table->index(['status', 'environment']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('eet_sales');
    }
};

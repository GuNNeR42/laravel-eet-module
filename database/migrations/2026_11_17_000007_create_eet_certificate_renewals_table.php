<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('eet_certificate_renewals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('certificate_id')->constrained('eet_cash_register_certificates');
            $table->foreignId('successor_id')->nullable()->constrained('eet_cash_register_certificates');
            // not unique: a later renewal may take over the reqId of a failed one (R93)
            $table->uuid('request_id')->nullable()->index();
            $table->string('status', 32);
            $table->string('problem', 64)->nullable();
            $table->string('problem_detail', 255)->nullable();
            $table->dateTime('acknowledged_at')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->dateTime('failed_at')->nullable();
            $table->timestamps();

            $table->index(['certificate_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('eet_certificate_renewals');
    }
};

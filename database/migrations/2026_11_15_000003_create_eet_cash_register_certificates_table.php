<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('eet_cash_register_certificates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('taxpayer_id')->constrained('eet_taxpayers');
            $table->string('serial_number');
            $table->string('subject_eic', 64);
            $table->dateTime('valid_from');
            $table->dateTime('valid_to');
            $table->string('fingerprint_sha256', 64);
            $table->text('certificate');
            $table->text('chain')->nullable();
            $table->text('private_key');
            $table->timestamps();

            $table->unique(['taxpayer_id', 'fingerprint_sha256']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('eet_cash_register_certificates');
    }
};

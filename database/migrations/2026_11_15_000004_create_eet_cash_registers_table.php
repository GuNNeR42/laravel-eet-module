<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('eet_cash_registers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('registration_unit_id')->constrained('eet_registration_units');
            $table->string('identifier', 20);
            $table->string('name')->nullable();
            $table->foreignId('certificate_id')->nullable()->constrained('eet_cash_register_certificates');
            $table->timestamps();

            $table->unique(['registration_unit_id', 'identifier']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('eet_cash_registers');
    }
};

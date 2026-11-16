<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('eet_registration_units', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('taxpayer_id')->constrained('eet_taxpayers');
            $table->unsignedInteger('number');
            $table->string('name')->nullable();
            $table->timestamps();

            $table->unique(['taxpayer_id', 'number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('eet_registration_units');
    }
};

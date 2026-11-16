<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('eet_taxpayers', function (Blueprint $table): void {
            $table->id();
            $table->string('eic', 12)->unique();
            $table->string('name')->nullable();
            $table->string('environment');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('eet_taxpayers');
    }
};

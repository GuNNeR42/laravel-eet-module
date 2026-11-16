<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('eet_submission_attempts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sale_id')->constrained('eet_sales');
            $table->uuid('message_uuid')->unique();
            $table->boolean('is_first_submission');
            $table->foreignId('certificate_id')->nullable()->constrained('eet_cash_register_certificates');
            $table->string('certificate_fingerprint', 64)->nullable();
            $table->dateTime('sent_at');
            $table->string('outcome');
            $table->integer('error_code')->nullable();
            $table->string('error_message')->nullable();
            $table->json('warnings');
            $table->dateTime('received_at')->nullable();
            $table->dateTime('rejected_at')->nullable();
            $table->string('confirmation_code', 39)->nullable();
            $table->boolean('is_test')->default(false);
            $table->string('global_transaction_id', 64)->nullable();
            $table->smallInteger('http_status')->nullable();
            $table->unsignedInteger('duration_ms');
            $table->longText('request_xml')->nullable();
            $table->longText('response_xml')->nullable();
            $table->string('signer_subject')->nullable();
            $table->string('signer_fingerprint', 64)->nullable();
            $table->boolean('is_fake')->default(false);
            $table->timestamps();

            $table->index(['sale_id', 'outcome']);
        });

        Schema::table('eet_sales', function (Blueprint $table): void {
            $table->foreign('rejecting_attempt_id')->references('id')->on('eet_submission_attempts');
            $table->foreign('reopened_after_attempt_id')->references('id')->on('eet_submission_attempts');
        });
    }

    public function down(): void
    {
        Schema::table('eet_sales', function (Blueprint $table): void {
            $table->dropForeign(['rejecting_attempt_id']);
            $table->dropForeign(['reopened_after_attempt_id']);
        });

        Schema::dropIfExists('eet_submission_attempts');
    }
};

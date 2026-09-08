<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gateway_call_attempts', function (Blueprint $table) {
            $table->id();
            $table->uuid('request_id')->unique();
            $table->foreignId('application_id')->constrained()->restrictOnDelete();
            $table->foreignId('machine_credential_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('public_model_alias_id')->constrained()->restrictOnDelete();
            $table->foreignId('upstream_target_id')->constrained()->restrictOnDelete();
            $table->foreignId('model_pricing_version_id')->constrained()->restrictOnDelete();
            $table->string('operation');
            $table->boolean('streaming');
            $table->string('status')->index();
            $table->char('idempotency_key_hash', 64)->nullable();
            $table->unsignedInteger('alias_configuration_version');
            $table->unsignedInteger('target_configuration_version');
            $table->unsignedInteger('grant_configuration_version');
            $table->unsignedInteger('prompt_tokens')->nullable();
            $table->unsignedInteger('completion_tokens')->nullable();
            $table->unsignedInteger('total_tokens')->nullable();
            $table->string('upstream_correlation_id')->nullable();
            $table->string('error_code')->nullable();
            $table->timestampTz('started_at');
            $table->timestampTz('completed_at')->nullable();
            $table->timestamps();
            $table->unique(
                ['application_id', 'operation', 'idempotency_key_hash'],
                'gateway_attempt_idempotency_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gateway_call_attempts');
    }
};

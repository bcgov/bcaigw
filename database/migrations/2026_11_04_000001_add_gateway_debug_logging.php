<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('application_environments', function (Blueprint $table) {
            $table->boolean('debug_enabled')->default(false)->after('budget_currency');
        });

        Schema::create('gateway_debug_logs', function (Blueprint $table) {
            $table->id();
            $table->uuid('request_id')->index();
            $table->foreignId('application_id')->constrained()->cascadeOnDelete();
            $table->string('environment')->index();
            $table->string('operation');
            $table->string('requested_model')->nullable();
            $table->string('upstream_model')->nullable();
            $table->unsignedSmallInteger('http_status');
            $table->unsignedSmallInteger('upstream_status')->nullable();
            $table->string('error_code')->nullable();
            $table->boolean('has_image')->default(false);
            $table->boolean('has_file')->default(false);
            $table->json('attachments')->nullable();
            // Prompt/response bodies are encrypted at rest (APP_KEY).
            $table->longText('request_payload')->nullable();
            $table->longText('response_payload')->nullable();
            $table->boolean('request_truncated')->default(false);
            $table->boolean('response_truncated')->default(false);
            $table->unsignedInteger('latency_ms')->nullable();
            $table->unsignedInteger('prompt_tokens')->nullable();
            $table->unsignedInteger('completion_tokens')->nullable();
            $table->timestamp('created_at')->useCurrent()->index();

            $table->index(['application_id', 'environment', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gateway_debug_logs');

        Schema::table('application_environments', function (Blueprint $table) {
            $table->dropColumn('debug_enabled');
        });
    }
};

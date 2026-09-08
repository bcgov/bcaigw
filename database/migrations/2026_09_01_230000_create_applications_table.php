<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('applications', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->string('name');
            $table->string('ministry_organization');
            $table->text('purpose_use_case');
            $table->string('primary_contact_name');
            $table->string('primary_contact_email');
            $table->string('technical_contact_name')->nullable();
            $table->string('technical_contact_email')->nullable();
            $table->json('environments');
            $table->unsignedInteger('expected_requests_per_minute');
            $table->unsignedBigInteger('expected_tokens_per_month');
            $table->string('data_classification');
            $table->json('requested_models');
            $table->json('requested_capabilities');
            $table->boolean('prompt_response_retention_enabled')->default(true);
            $table->unsignedInteger('rate_limit_per_minute')->nullable();
            $table->unsignedBigInteger('token_budget_monthly')->nullable();
            $table->decimal('cost_budget_monthly', 12, 2)->nullable();
            $table->boolean('configuration_ready')->default(false);
            $table->string('status')->default('draft')->index();
            $table->unsignedInteger('status_version')->default(0);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });

        Schema::create('application_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('role')->default('member');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['application_id', 'user_id']);
            $table->index(['application_id', 'role']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('application_user');
        Schema::dropIfExists('applications');
    }
};

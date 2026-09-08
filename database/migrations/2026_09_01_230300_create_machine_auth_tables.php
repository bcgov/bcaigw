<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('machine_credentials', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('application_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('client_identifier', 80)->unique();
            $table->text('secret_hash');
            $table->json('abilities');
            $table->unsignedInteger('version')->default(1);
            $table->timestampTz('expires_at')->nullable()->index();
            $table->timestampTz('revoked_at')->nullable()->index();
            $table->timestampTz('rotation_overlap_ends_at')->nullable()->index();
            $table->foreignId('rotated_from_id')->nullable()
                ->constrained('machine_credentials')->nullOnDelete();
            $table->timestampTz('last_used_at')->nullable();
            $table->char('last_used_ip_hash', 64)->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->index(['application_id', 'revoked_at']);
        });

        Schema::create('machine_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained()->cascadeOnDelete();
            $table->foreignId('machine_credential_id')->constrained()->cascadeOnDelete();
            $table->char('token_hash', 64)->unique();
            $table->json('abilities');
            $table->unsignedInteger('credential_version');
            $table->unsignedInteger('application_status_version');
            $table->timestampTz('expires_at')->index();
            $table->timestampTz('revoked_at')->nullable()->index();
            $table->timestampTz('last_used_at')->nullable();
            $table->char('last_used_ip_hash', 64)->nullable();
            $table->timestamps();
            $table->index(['machine_credential_id', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('machine_access_tokens');
        Schema::dropIfExists('machine_credentials');
    }
};

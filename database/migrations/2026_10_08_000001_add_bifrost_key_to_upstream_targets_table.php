<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('upstream_targets', function (Blueprint $table) {
            // Bifrost provider key (account) the target is pinned to via x-bf-api-key-id.
            $table->string('bifrost_key_id')->nullable()->after('provider_model_identifier');
            $table->string('bifrost_key_name')->nullable()->after('bifrost_key_id');
        });
    }

    public function down(): void
    {
        Schema::table('upstream_targets', function (Blueprint $table) {
            $table->dropColumn(['bifrost_key_id', 'bifrost_key_name']);
        });
    }
};

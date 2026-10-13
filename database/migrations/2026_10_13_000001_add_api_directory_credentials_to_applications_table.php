<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            // Issued automatically on activation, so no longer required up front.
            $table->string('api_directory_client_id')->nullable()->change();
            $table->text('api_directory_client_secret')->nullable()->after('api_directory_client_id');
            $table->string('api_directory_token_endpoint', 2048)->nullable()->after('api_directory_client_secret');
            $table->string('api_directory_flow')->nullable()->after('api_directory_token_endpoint');
            $table->timestampTz('api_directory_issued_at')->nullable()->after('api_directory_flow');
        });
    }

    public function down(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->dropColumn([
                'api_directory_client_secret',
                'api_directory_token_endpoint',
                'api_directory_flow',
                'api_directory_issued_at',
            ]);
        });
    }
};

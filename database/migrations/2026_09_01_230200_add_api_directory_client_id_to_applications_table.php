<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->string('api_directory_client_id')
                ->after('public_id');
                // ->unique();
        });
    }

    public function down(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            // $table->dropUnique(['api_directory_client_id']);
            $table->dropColumn('api_directory_client_id');
        });
    }
};

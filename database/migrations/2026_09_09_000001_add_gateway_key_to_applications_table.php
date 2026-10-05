<?php

use App\Models\Application;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->uuid('gateway_key')->nullable()->unique()->after('public_id');
        });

        Application::query()->whereNull('gateway_key')->cursor()->each(function (Application $application): void {
            $application->forceFill(['gateway_key' => (string) Str::uuid()])->saveQuietly();
        });
    }

    public function down(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->dropColumn('gateway_key');
        });
    }
};

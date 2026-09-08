<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('name')->nullable()->change();
            $table->string('email')->nullable()->change();
            $table->string('password')->nullable()->change();
            $table->string('keycloak_subject')->nullable()->unique()->after('id');
            $table->uuid('idir_user_guid')->nullable()->unique()->after('keycloak_subject');
            $table->string('idir_username')->nullable()->index()->after('idir_user_guid');
            $table->string('first_name')->nullable()->after('name');
            $table->string('last_name')->nullable()->after('first_name');
            $table->string('portal_role')->default('application_owner')->index()->after('password');
            $table->timestampTz('last_login_at')->nullable()->after('portal_role');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'keycloak_subject',
                'idir_user_guid',
                'idir_username',
                'first_name',
                'last_name',
                'portal_role',
                'last_login_at',
            ]);
        });
    }
};

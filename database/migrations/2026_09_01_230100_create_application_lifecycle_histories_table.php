<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('application_lifecycle_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained()->cascadeOnDelete();
            $table->string('from_status')->nullable();
            $table->string('to_status');
            $table->unsignedBigInteger('actor_user_id')->nullable()->index();
            $table->text('note')->nullable();
            $table->json('metadata')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['application_id', 'created_at']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION bcaigw_reject_lifecycle_mutation() RETURNS trigger AS $$
                BEGIN
                    RAISE EXCEPTION 'application_lifecycle_histories is append-only';
                END;
                $$ LANGUAGE plpgsql;

                CREATE TRIGGER application_lifecycle_histories_append_only
                BEFORE UPDATE OR DELETE ON application_lifecycle_histories
                FOR EACH ROW EXECUTE FUNCTION bcaigw_reject_lifecycle_mutation();
                SQL);
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS application_lifecycle_histories_append_only ON application_lifecycle_histories');
            DB::unprepared('DROP FUNCTION IF EXISTS bcaigw_reject_lifecycle_mutation()');
        }

        Schema::dropIfExists('application_lifecycle_histories');
    }
};

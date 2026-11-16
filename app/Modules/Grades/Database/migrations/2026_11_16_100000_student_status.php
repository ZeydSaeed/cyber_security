<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A student's status (owner's request, 2026-11-16): "external" for a
 * student enrolled by correspondence (intisab), empty for a regular one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('grade_students', function (Blueprint $table) {
            $table->string('status', 20)->nullable();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE grade_students ADD CONSTRAINT grade_students_status_check CHECK (status IS NULL OR status IN ('external'))");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE grade_students DROP CONSTRAINT IF EXISTS grade_students_status_check');
        }

        Schema::table('grade_students', function (Blueprint $table) {
            $table->dropColumn('status');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Teachers reading their own students' results (ADR-0040, owner's request
 * of 2026-11-19): whether they may (never, once published, always) and
 * what they see of them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('grade_settings', function (Blueprint $table) {
            // Domain\TeacherResults.
            $table->string('teacher_results', 10)->default('off');
            // Null: the defaults (Domain\ResultDisplay::TEACHER_OPTIONS).
            $table->json('teacher_view')->nullable();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE grade_settings ADD CONSTRAINT grade_settings_teacher_results_check CHECK (teacher_results IN ('off', 'published', 'always'))");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE grade_settings DROP CONSTRAINT IF EXISTS grade_settings_teacher_results_check');
        }

        Schema::table('grade_settings', function (Blueprint $table) {
            $table->dropColumn(['teacher_results', 'teacher_view']);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What a result says (ADR-0039, owner's request of 2026-11-19):
 *
 * - each subject's maximum (100 unless set: 80, 50, any up to 1000), and
 *   its own pass and exemption marks (null: the department's percentages);
 * - marks up to 1000 in the table (each subject's own maximum is checked
 *   by SaveMarks);
 * - the department's pass and exemption percentages, grade bands, what a
 *   result card shows, and the status tones of marks;
 * - each student's behaviour and notes for a period.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('grade_subjects', function (Blueprint $table) {
            $table->unsignedSmallInteger('max_mark')->default(100);
            $table->unsignedSmallInteger('pass_mark')->nullable();
            $table->unsignedSmallInteger('exempt_mark')->nullable();
        });

        Schema::table('grade_settings', function (Blueprint $table) {
            $table->unsignedSmallInteger('pass_percent')->default(50);
            // Null: no exemption of that kind.
            $table->unsignedSmallInteger('exempt_subject_percent')->nullable();
            $table->unsignedSmallInteger('exempt_overall_percent')->nullable();
            // Null: the defaults (Domain\GradeBands, Domain\ResultDisplay).
            $table->json('grade_bands')->nullable();
            $table->json('result_options')->nullable();
            $table->json('mark_tones')->nullable();
        });

        Schema::create('grade_student_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained('grade_students')->cascadeOnDelete();
            $table->string('period', 20);
            // Domain\Behaviour; null: not stated.
            $table->string('behaviour', 12)->nullable();
            $table->string('notes', 1000)->nullable();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->unique(['student_id', 'period']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE grade_subjects ADD CONSTRAINT grade_subjects_scale_check CHECK (max_mark BETWEEN 1 AND 1000 AND (pass_mark IS NULL OR pass_mark <= max_mark) AND (exempt_mark IS NULL OR exempt_mark <= max_mark))');
            DB::statement('ALTER TABLE grade_marks DROP CONSTRAINT IF EXISTS grade_marks_mark_check');
            DB::statement('ALTER TABLE grade_marks ADD CONSTRAINT grade_marks_mark_check CHECK ((is_absent AND mark IS NULL) OR (NOT is_absent AND mark IS NOT NULL AND mark BETWEEN 0 AND 1000))');
            DB::statement('ALTER TABLE grade_settings ADD CONSTRAINT grade_settings_percents_check CHECK (pass_percent BETWEEN 1 AND 100 AND (exempt_subject_percent IS NULL OR exempt_subject_percent BETWEEN 1 AND 100) AND (exempt_overall_percent IS NULL OR exempt_overall_percent BETWEEN 1 AND 100))');
            DB::statement("ALTER TABLE grade_student_notes ADD CONSTRAINT grade_student_notes_behaviour_check CHECK (behaviour IS NULL OR behaviour IN ('excellent', 'very_good', 'good', 'acceptable', 'poor'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('grade_student_notes');

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE grade_subjects DROP CONSTRAINT IF EXISTS grade_subjects_scale_check');
            DB::statement('ALTER TABLE grade_settings DROP CONSTRAINT IF EXISTS grade_settings_percents_check');
            // Marks above 100 would break the old rule: it comes back only when none are left.
            DB::statement('ALTER TABLE grade_marks DROP CONSTRAINT IF EXISTS grade_marks_mark_check');
            DB::statement('ALTER TABLE grade_marks ADD CONSTRAINT grade_marks_mark_check CHECK ((is_absent AND mark IS NULL) OR (NOT is_absent AND mark IS NOT NULL AND mark BETWEEN 0 AND 100))');
        }

        Schema::table('grade_settings', function (Blueprint $table) {
            $table->dropColumn(['pass_percent', 'exempt_subject_percent', 'exempt_overall_percent', 'grade_bands', 'result_options', 'mark_tones']);
        });

        Schema::table('grade_subjects', function (Blueprint $table) {
            $table->dropColumn(['max_mark', 'pass_mark', 'exempt_mark']);
        });
    }
};

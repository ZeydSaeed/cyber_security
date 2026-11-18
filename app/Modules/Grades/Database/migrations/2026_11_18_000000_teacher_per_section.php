<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A teacher per section of a subject (owner's request, 2026-11-18): in a
 * divided stage, section A and section B of one subject may have different
 * teachers, and each teacher reaches only the students of their own
 * sections. Replaces grade_subjects.teacher_id; each subject's teacher
 * keeps every section of its stage.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('grade_subject_teachers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subject_id')->constrained('grade_subjects')->cascadeOnDelete();
            $table->char('section', 1);
            $table->foreignId('teacher_id')->constrained('grade_teachers')->restrictOnDelete();
            $table->timestampsTz();

            // One teacher per section of a subject.
            $table->unique(['subject_id', 'section']);
            $table->index('teacher_id');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE grade_subject_teachers ADD CONSTRAINT grade_subject_teachers_section_check CHECK (section IN ('A', 'B'))");
        }

        // The first stage has section A only; the others A and B.
        $now = now();
        foreach (DB::table('grade_subjects')->whereNotNull('teacher_id')->orderBy('id')->get(['id', 'stage', 'teacher_id']) as $subject) {
            foreach ((int) $subject->stage === 1 ? ['A'] : ['A', 'B'] as $section) {
                DB::table('grade_subject_teachers')->insert([
                    'subject_id' => $subject->id, 'section' => $section, 'teacher_id' => $subject->teacher_id,
                    'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        }

        Schema::table('grade_subjects', function (Blueprint $table) {
            $table->dropForeign(['teacher_id']);
            $table->dropIndex(['teacher_id']);
            $table->dropColumn('teacher_id');
        });
    }

    public function down(): void
    {
        Schema::table('grade_subjects', function (Blueprint $table) {
            $table->foreignId('teacher_id')->nullable()->after('name')->constrained('grade_teachers')->restrictOnDelete();
            $table->index('teacher_id');
        });

        // Section A's teacher becomes the subject's teacher.
        foreach (DB::table('grade_subject_teachers')->where('section', 'A')->get(['subject_id', 'teacher_id']) as $row) {
            DB::table('grade_subjects')->where('id', $row->subject_id)->update(['teacher_id' => $row->teacher_id]);
        }

        Schema::drop('grade_subject_teachers');
    }
};

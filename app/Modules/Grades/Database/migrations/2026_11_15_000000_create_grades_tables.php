<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The Grades module (ADR-0034): teachers, subjects, students, marking
 * periods and marks. The rules of Domain\* are repeated as CHECK
 * constraints, and composite foreign keys make a mark for a subject of the
 * other stage impossible in the database itself.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('grade_teachers', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name', 120);
            // HMAC-SHA256 of the code (TeacherCodes); the code is never stored.
            $table->char('code_digest', 64)->unique();
            $table->boolean('is_active')->default(true);
            $table->timestampTz('last_seen_at')->nullable();
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestampsTz();
        });

        Schema::create('grade_subjects', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedSmallInteger('stage');
            $table->string('name', 120);
            // Assigned when the owner knows who teaches it.
            $table->foreignId('teacher_id')->nullable()->constrained('grade_teachers')->restrictOnDelete();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestampsTz();

            $table->unique(['stage', 'name']);
            // Target of grade_marks (subject_id, stage).
            $table->unique(['id', 'stage']);
            $table->index('teacher_id');
        });

        Schema::create('grade_students', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('full_name', 160);
            // NameKey::of(full_name): matches imports to existing students.
            $table->string('name_key', 160);
            $table->unsignedSmallInteger('stage');
            $table->char('section', 1);
            $table->string('student_number', 40)->nullable()->unique();
            $table->timestampsTz();

            // Target of grade_marks (student_id, stage).
            $table->unique(['id', 'stage']);
            // A section's sheet, in name order.
            $table->index(['stage', 'section', 'full_name']);
        });

        Schema::create('grade_periods', function (Blueprint $table) {
            $table->id();
            $table->string('period', 20)->unique();
            $table->string('status', 10)->default('closed');
            $table->timestampTz('opened_at')->nullable();
            $table->timestampTz('closed_at')->nullable();
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestampsTz();
        });

        Schema::create('grade_marks', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('student_id');
            $table->unsignedBigInteger('subject_id');
            $table->unsignedSmallInteger('stage');
            $table->string('period', 20);
            $table->unsignedSmallInteger('mark')->nullable();
            $table->boolean('is_absent')->default(false);
            // Who wrote it: a teacher (mark entry) or the owner (a correction).
            $table->foreignId('entered_by_teacher_id')->nullable()->constrained('grade_teachers')->restrictOnDelete();
            $table->foreignId('entered_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampsTz();

            // One mark per student, subject and period (the stage follows
            // from the student). Also the index of (student_id, stage).
            $table->unique(['student_id', 'stage', 'subject_id', 'period']);
            // A subject's sheet for a period. Also the index of (subject_id, stage).
            $table->index(['subject_id', 'stage', 'period']);
            $table->index('period');
            $table->index('entered_by_teacher_id');
            $table->index('entered_by_user_id');

            $table->foreign(['student_id', 'stage'])->references(['id', 'stage'])->on('grade_students')->restrictOnDelete();
            $table->foreign(['subject_id', 'stage'])->references(['id', 'stage'])->on('grade_subjects')->restrictOnDelete();
            $table->foreign('period')->references('period')->on('grade_periods')->restrictOnDelete();
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $stages = '1, 2';
        $sections = "'A', 'B'";
        $periods = "'month_1'";

        // NULL is named in each rule: a CHECK passes when its expression is NULL.
        $rules = [
            'grade_teachers' => [
                'name' => 'length(btrim(name)) >= 2',
                'code_digest' => "code_digest ~ '^[0-9a-f]{64}$'",
                'lock_version' => 'lock_version >= 1',
            ],
            'grade_subjects' => [
                'stage' => "stage IN ({$stages})",
                'name' => 'length(btrim(name)) >= 2',
            ],
            'grade_students' => [
                'stage' => "stage IN ({$stages})",
                'section' => "section IN ({$sections})",
                'full_name' => 'length(btrim(full_name)) >= 3',
                'name_key' => 'length(name_key) >= 3',
                'student_number' => 'student_number IS NULL OR length(btrim(student_number)) >= 1',
            ],
            'grade_periods' => [
                'period' => "period IN ({$periods})",
                'status' => "status IN ('open', 'closed')",
                // Open means opened and not closed since.
                'open' => "status <> 'open' OR (opened_at IS NOT NULL AND closed_at IS NULL)",
                'closed' => "status <> 'closed' OR opened_at IS NULL OR closed_at IS NOT NULL",
                'lock_version' => 'lock_version >= 1',
            ],
            'grade_marks' => [
                'stage' => "stage IN ({$stages})",
                // Absent with no mark, or present with a mark from 0 to 100.
                'mark' => '(is_absent AND mark IS NULL) OR (NOT is_absent AND mark IS NOT NULL AND mark BETWEEN 0 AND 100)',
                // Exactly one author.
                'author' => '(entered_by_teacher_id IS NULL) <> (entered_by_user_id IS NULL)',
            ],
        ];

        foreach ($rules as $table => $checks) {
            foreach ($checks as $name => $rule) {
                DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_{$name}_check CHECK ({$rule})");
            }
        }

        // Without a student number, a name is unique inside its stage (the
        // import matches on it); with one, the number is.
        DB::statement('CREATE UNIQUE INDEX grade_students_stage_name_key_unique ON grade_students (stage, name_key) WHERE student_number IS NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('grade_marks');
        Schema::dropIfExists('grade_periods');
        Schema::dropIfExists('grade_students');
        Schema::dropIfExists('grade_subjects');
        Schema::dropIfExists('grade_teachers');
    }
};

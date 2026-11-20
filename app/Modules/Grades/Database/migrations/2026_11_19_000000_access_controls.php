<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Who may sign in and change marks, and for how long (ADR-0037, owner's
 * request of 2026-11-19):
 *
 * - `grade_settings`: one row of department-wide rules: sign-in attempt
 *   limits, an optional cap on sign-ins, the teachers' default access and
 *   editing time, and whether students may sign in.
 * - teachers and sections of subjects: an access mode that overrides the
 *   default (null: inherited), the reason for a lock, and on a section a
 *   grant to edit until a time (an approved request or the owner's unlock).
 * - teachers and students: how many times they signed in, and when last.
 * - `grade_edit_requests`: a teacher asking to change locked marks.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('grade_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('teacher_attempts_per_minute')->default(5);
            $table->unsignedSmallInteger('teacher_attempts_per_hour')->default(20);
            $table->unsignedSmallInteger('student_attempts_per_minute')->default(5);
            $table->unsignedSmallInteger('student_attempts_per_hour')->default(20);
            // Null: no cap.
            $table->unsignedInteger('teacher_max_logins')->nullable();
            $table->unsignedInteger('student_max_logins')->nullable();
            // Domain\TeacherAccess.
            $table->string('teacher_mode', 12)->default('edit');
            // For the timed mode: hours a mark stays editable after it is first entered.
            $table->unsignedSmallInteger('teacher_edit_hours')->nullable();
            // Domain\StudentAccess.
            $table->string('student_access', 12)->default('allowed');
            $table->timestampsTz();
        });

        Schema::table('grade_teachers', function (Blueprint $table) {
            $table->string('access_mode', 12)->nullable();
            $table->unsignedSmallInteger('edit_hours')->nullable();
            $table->string('access_reason', 255)->nullable();
            $table->unsignedInteger('login_count')->default(0);
            $table->timestampTz('last_login_at')->nullable();
        });

        Schema::table('grade_subject_teachers', function (Blueprint $table) {
            $table->string('access_mode', 12)->nullable();
            $table->string('access_reason', 255)->nullable();
            $table->timestampTz('edit_until')->nullable();
        });

        Schema::table('grade_students', function (Blueprint $table) {
            $table->boolean('access_blocked')->default(false);
            $table->unsignedInteger('login_count')->default(0);
            $table->timestampTz('last_login_at')->nullable();
        });

        Schema::create('grade_edit_requests', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('teacher_id')->constrained('grade_teachers')->cascadeOnDelete();
            $table->foreignId('subject_id')->constrained('grade_subjects')->cascadeOnDelete();
            $table->char('section', 1);
            $table->string('period', 20);
            $table->string('reason', 500);
            // Domain\EditRequestStatus.
            $table->string('status', 10)->default('pending');
            $table->unsignedSmallInteger('granted_hours')->nullable();
            $table->foreignId('decided_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('decided_at')->nullable();
            $table->timestampsTz();
            $table->index(['status', 'created_at']);
            $table->index(['teacher_id', 'subject_id', 'section']);
        });

        if (DB::getDriverName() === 'pgsql') {
            $modes = "'edit', 'timed', 'view_only', 'blocked'";
            DB::statement("ALTER TABLE grade_settings ADD CONSTRAINT grade_settings_mode_check CHECK (teacher_mode IN ({$modes}))");
            DB::statement("ALTER TABLE grade_settings ADD CONSTRAINT grade_settings_student_check CHECK (student_access IN ('allowed', 'blocked'))");
            DB::statement('ALTER TABLE grade_settings ADD CONSTRAINT grade_settings_limits_check CHECK (teacher_attempts_per_minute BETWEEN 1 AND 100 AND teacher_attempts_per_hour BETWEEN 1 AND 1000 AND student_attempts_per_minute BETWEEN 1 AND 100 AND student_attempts_per_hour BETWEEN 1 AND 1000)');
            DB::statement('ALTER TABLE grade_settings ADD CONSTRAINT grade_settings_hours_check CHECK (teacher_edit_hours IS NULL OR teacher_edit_hours BETWEEN 1 AND 8760)');
            DB::statement("ALTER TABLE grade_teachers ADD CONSTRAINT grade_teachers_access_check CHECK (access_mode IS NULL OR access_mode IN ({$modes}))");
            DB::statement('ALTER TABLE grade_teachers ADD CONSTRAINT grade_teachers_hours_check CHECK (edit_hours IS NULL OR edit_hours BETWEEN 1 AND 8760)');
            DB::statement("ALTER TABLE grade_subject_teachers ADD CONSTRAINT grade_subject_teachers_access_check CHECK (access_mode IS NULL OR access_mode IN ({$modes}))");
            DB::statement("ALTER TABLE grade_edit_requests ADD CONSTRAINT grade_edit_requests_status_check CHECK (status IN ('pending', 'approved', 'rejected'))");
            DB::statement("ALTER TABLE grade_edit_requests ADD CONSTRAINT grade_edit_requests_section_check CHECK (section IN ('A', 'B'))");
            // One open request per teacher and section at a time.
            DB::statement("CREATE UNIQUE INDEX grade_edit_requests_one_pending ON grade_edit_requests (teacher_id, subject_id, section, period) WHERE status = 'pending'");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('grade_edit_requests');

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE grade_teachers DROP CONSTRAINT IF EXISTS grade_teachers_access_check');
            DB::statement('ALTER TABLE grade_teachers DROP CONSTRAINT IF EXISTS grade_teachers_hours_check');
            DB::statement('ALTER TABLE grade_subject_teachers DROP CONSTRAINT IF EXISTS grade_subject_teachers_access_check');
        }

        Schema::table('grade_students', function (Blueprint $table) {
            $table->dropColumn(['access_blocked', 'login_count', 'last_login_at']);
        });
        Schema::table('grade_subject_teachers', function (Blueprint $table) {
            $table->dropColumn(['access_mode', 'access_reason', 'edit_until']);
        });
        Schema::table('grade_teachers', function (Blueprint $table) {
            $table->dropColumn(['access_mode', 'edit_hours', 'access_reason', 'login_count', 'last_login_at']);
        });

        Schema::dropIfExists('grade_settings');
    }
};

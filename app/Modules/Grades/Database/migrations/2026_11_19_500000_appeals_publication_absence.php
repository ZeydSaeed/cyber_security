<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The last of the owner's approved suggestions (ADR-0042, 2026-11-19):
 *
 * - students appealing a mark, within days the owner sets after their
 *   results are published;
 * - results published to one section at a time (besides all at once);
 * - a flag on students absent from as many subjects as the owner sets.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('grade_appeals', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('student_id')->constrained('grade_students')->cascadeOnDelete();
            $table->foreignId('subject_id')->constrained('grade_subjects')->cascadeOnDelete();
            $table->string('period', 20);
            $table->string('reason', 500);
            // Domain\AppealStatus.
            $table->string('status', 10)->default('pending');
            $table->string('response', 500)->nullable();
            $table->foreignId('decided_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('decided_at')->nullable();
            $table->timestampsTz();
            $table->index(['status', 'created_at']);
            $table->index(['subject_id', 'period']);
        });

        Schema::create('grade_section_publications', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('stage');
            $table->char('section', 1);
            $table->string('period', 20);
            $table->timestampTz('published_at');
            $table->timestampsTz();
            $table->unique(['stage', 'section', 'period']);
        });

        Schema::table('grade_settings', function (Blueprint $table) {
            // Days after publication a student may appeal; null: no appeals.
            $table->unsignedSmallInteger('appeal_days')->nullable();
            // Absent from this many subjects or more: flagged; null: no flag.
            $table->unsignedSmallInteger('absence_flag')->nullable();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE grade_appeals ADD CONSTRAINT grade_appeals_status_check CHECK (status IN ('pending', 'accepted', 'rejected'))");
            DB::statement("CREATE UNIQUE INDEX grade_appeals_one_pending ON grade_appeals (student_id, subject_id, period) WHERE status = 'pending'");
            DB::statement("ALTER TABLE grade_section_publications ADD CONSTRAINT grade_section_publications_section_check CHECK (section IN ('A', 'B'))");
            DB::statement('ALTER TABLE grade_settings ADD CONSTRAINT grade_settings_appeal_check CHECK ((appeal_days IS NULL OR appeal_days BETWEEN 1 AND 90) AND (absence_flag IS NULL OR absence_flag BETWEEN 1 AND 50))');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE grade_settings DROP CONSTRAINT IF EXISTS grade_settings_appeal_check');
        }

        Schema::table('grade_settings', function (Blueprint $table) {
            $table->dropColumn(['appeal_days', 'absence_flag']);
        });
        Schema::dropIfExists('grade_section_publications');
        Schema::dropIfExists('grade_appeals');
    }
};

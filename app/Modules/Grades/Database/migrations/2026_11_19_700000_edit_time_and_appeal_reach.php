<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The owner's changes of 2026-11-19 (ADR-0043):
 *
 * - a mark's editing time is given in minutes (days, hours and minutes on
 *   the page) and may end at a fixed date and time, for the department
 *   and for one teacher;
 * - appeals stay open for a time in minutes and/or until a fixed date,
 *   and are open to every student or only to students the owner chooses.
 *
 * Existing hours and days are carried over as minutes.
 */
return new class extends Migration
{
    private const MAX_EDIT_MINUTES = 525600;

    private const MAX_APPEAL_MINUTES = 129600;

    public function up(): void
    {
        $pgsql = DB::getDriverName() === 'pgsql';

        if ($pgsql) {
            DB::statement('ALTER TABLE grade_settings DROP CONSTRAINT IF EXISTS grade_settings_hours_check');
            DB::statement('ALTER TABLE grade_teachers DROP CONSTRAINT IF EXISTS grade_teachers_hours_check');
            DB::statement('ALTER TABLE grade_settings DROP CONSTRAINT IF EXISTS grade_settings_appeal_check');
        }

        Schema::table('grade_settings', function (Blueprint $table) {
            $table->unsignedInteger('teacher_edit_minutes')->nullable();
            $table->timestampTz('teacher_edit_until')->nullable();
            $table->unsignedInteger('appeal_minutes')->nullable();
            $table->timestampTz('appeal_until')->nullable();
            // Domain\AppealScope: every student, or only those allowed.
            $table->string('appeal_scope', 10)->default('all');
        });
        Schema::table('grade_teachers', function (Blueprint $table) {
            $table->unsignedInteger('edit_minutes')->nullable();
            $table->timestampTz('edit_until')->nullable();
        });
        Schema::table('grade_students', function (Blueprint $table) {
            // May appeal when appeals are open to chosen students only.
            $table->boolean('appeal_allowed')->default(false);
        });

        DB::table('grade_settings')->whereNotNull('teacher_edit_hours')->update(['teacher_edit_minutes' => DB::raw('teacher_edit_hours * 60')]);
        DB::table('grade_settings')->whereNotNull('appeal_days')->update(['appeal_minutes' => DB::raw('appeal_days * 1440')]);
        DB::table('grade_teachers')->whereNotNull('edit_hours')->update(['edit_minutes' => DB::raw('edit_hours * 60')]);
        // The time limit now applies to "enter and change" too (ADR-0043);
        // where that mode was chosen explicitly, behaviour stays unlimited.
        DB::table('grade_settings')->where('teacher_mode', 'edit')->update(['teacher_edit_minutes' => null]);
        DB::table('grade_teachers')->where('access_mode', 'edit')->update(['edit_minutes' => null]);

        Schema::table('grade_settings', function (Blueprint $table) {
            $table->dropColumn(['teacher_edit_hours', 'appeal_days']);
        });
        Schema::table('grade_teachers', function (Blueprint $table) {
            $table->dropColumn('edit_hours');
        });

        if ($pgsql) {
            $edit = self::MAX_EDIT_MINUTES;
            $appeal = self::MAX_APPEAL_MINUTES;
            DB::statement("ALTER TABLE grade_settings ADD CONSTRAINT grade_settings_edit_minutes_check CHECK (teacher_edit_minutes IS NULL OR teacher_edit_minutes BETWEEN 1 AND {$edit})");
            DB::statement("ALTER TABLE grade_teachers ADD CONSTRAINT grade_teachers_edit_minutes_check CHECK (edit_minutes IS NULL OR edit_minutes BETWEEN 1 AND {$edit})");
            DB::statement("ALTER TABLE grade_settings ADD CONSTRAINT grade_settings_appeal_check CHECK ((appeal_minutes IS NULL OR appeal_minutes BETWEEN 1 AND {$appeal}) AND (absence_flag IS NULL OR absence_flag BETWEEN 1 AND 50) AND appeal_scope IN ('all', 'selected'))");
        }
    }

    public function down(): void
    {
        $pgsql = DB::getDriverName() === 'pgsql';

        if ($pgsql) {
            DB::statement('ALTER TABLE grade_settings DROP CONSTRAINT IF EXISTS grade_settings_edit_minutes_check');
            DB::statement('ALTER TABLE grade_teachers DROP CONSTRAINT IF EXISTS grade_teachers_edit_minutes_check');
            DB::statement('ALTER TABLE grade_settings DROP CONSTRAINT IF EXISTS grade_settings_appeal_check');
        }

        Schema::table('grade_settings', function (Blueprint $table) {
            $table->unsignedSmallInteger('teacher_edit_hours')->nullable();
            $table->unsignedSmallInteger('appeal_days')->nullable();
        });
        Schema::table('grade_teachers', function (Blueprint $table) {
            $table->unsignedSmallInteger('edit_hours')->nullable();
        });

        // Rounded up, and within the old ranges.
        foreach (DB::table('grade_settings')->get(['id', 'teacher_edit_minutes', 'appeal_minutes']) as $row) {
            DB::table('grade_settings')->where('id', $row->id)->update([
                'teacher_edit_hours' => $row->teacher_edit_minutes === null ? null : min(8760, intdiv((int) $row->teacher_edit_minutes + 59, 60)),
                'appeal_days' => $row->appeal_minutes === null ? null : min(90, intdiv((int) $row->appeal_minutes + 1439, 1440)),
            ]);
        }
        foreach (DB::table('grade_teachers')->whereNotNull('edit_minutes')->get(['id', 'edit_minutes']) as $row) {
            DB::table('grade_teachers')->where('id', $row->id)->update(['edit_hours' => min(8760, intdiv((int) $row->edit_minutes + 59, 60))]);
        }

        Schema::table('grade_settings', function (Blueprint $table) {
            $table->dropColumn(['teacher_edit_minutes', 'teacher_edit_until', 'appeal_minutes', 'appeal_until', 'appeal_scope']);
        });
        Schema::table('grade_teachers', function (Blueprint $table) {
            $table->dropColumn(['edit_minutes', 'edit_until']);
        });
        Schema::table('grade_students', function (Blueprint $table) {
            $table->dropColumn('appeal_allowed');
        });

        if ($pgsql) {
            DB::statement('ALTER TABLE grade_settings ADD CONSTRAINT grade_settings_hours_check CHECK (teacher_edit_hours IS NULL OR teacher_edit_hours BETWEEN 1 AND 8760)');
            DB::statement('ALTER TABLE grade_teachers ADD CONSTRAINT grade_teachers_hours_check CHECK (edit_hours IS NULL OR edit_hours BETWEEN 1 AND 8760)');
            DB::statement('ALTER TABLE grade_settings ADD CONSTRAINT grade_settings_appeal_check CHECK ((appeal_days IS NULL OR appeal_days BETWEEN 1 AND 90) AND (absence_flag IS NULL OR absence_flag BETWEEN 1 AND 50))');
        }
    }
};

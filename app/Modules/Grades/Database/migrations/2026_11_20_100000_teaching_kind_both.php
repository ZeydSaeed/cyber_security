<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A section may be taught both theoretically and practically (owner's
 * request, 2026-11-20): Domain\TeachingKind gains "both". The column is a
 * string already; only PostgreSQL's check needs the new value.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE grade_subject_teachers DROP CONSTRAINT IF EXISTS grade_subject_teachers_kind_check');
            DB::statement("ALTER TABLE grade_subject_teachers ADD CONSTRAINT grade_subject_teachers_kind_check CHECK (kind IN ('theory', 'practical', 'both'))");
        }
    }

    public function down(): void
    {
        // "Both" has no older equivalent; such sections go back to "not stated".
        DB::table('grade_subject_teachers')->where('kind', 'both')->update(['kind' => null]);

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE grade_subject_teachers DROP CONSTRAINT IF EXISTS grade_subject_teachers_kind_check');
            DB::statement("ALTER TABLE grade_subject_teachers ADD CONSTRAINT grade_subject_teachers_kind_check CHECK (kind IN ('theory', 'practical'))");
        }
    }
};

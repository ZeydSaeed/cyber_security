<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The owner's decisions of 2026-11-15 (ADR-0034):
 *
 * - The first stage has no sections: its students are all in section A
 *   (Stage::sections()), and the database refuses anything else.
 * - Students see their own result with a code the application makes. It is
 *   kept twice: an HMAC digest to find the student (unique), and encrypted
 *   with the application key so the owner can print the list again.
 * - Results are shown to students only once the owner publishes them, and
 *   only while mark entry is closed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('grade_students', function (Blueprint $table) {
            $table->char('code_digest', 64)->nullable()->unique();
            $table->text('code_encrypted')->nullable();
        });

        Schema::table('grade_periods', function (Blueprint $table) {
            $table->timestampTz('results_published_at')->nullable();
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach ([
            'grade_students' => [
                'stage_sections' => "stage <> 1 OR section = 'A'",
                'code_digest' => "code_digest IS NULL OR code_digest ~ '^[0-9a-f]{64}$'",
                // Both or neither.
                'code_pair' => '(code_digest IS NULL) = (code_encrypted IS NULL)',
            ],
            'grade_periods' => [
                'published_closed' => "results_published_at IS NULL OR status = 'closed'",
            ],
        ] as $table => $checks) {
            foreach ($checks as $name => $rule) {
                DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_{$name}_check CHECK ({$rule})");
            }
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE grade_students DROP CONSTRAINT IF EXISTS grade_students_stage_sections_check');
            DB::statement('ALTER TABLE grade_students DROP CONSTRAINT IF EXISTS grade_students_code_digest_check');
            DB::statement('ALTER TABLE grade_students DROP CONSTRAINT IF EXISTS grade_students_code_pair_check');
            DB::statement('ALTER TABLE grade_periods DROP CONSTRAINT IF EXISTS grade_periods_published_closed_check');
        }

        Schema::table('grade_periods', function (Blueprint $table) {
            $table->dropColumn('results_published_at');
        });
        Schema::table('grade_students', function (Blueprint $table) {
            $table->dropUnique(['code_digest']);
            $table->dropColumn(['code_digest', 'code_encrypted']);
        });
    }
};

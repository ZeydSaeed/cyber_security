<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Brings the tables of ADR-0037 to ADR-0042 to the database conventions
 * (ADR-0012, PostgresConventionsTest): JSON is stored as jsonb, and every
 * foreign key has an index. Found by CI on PostgreSQL (PR #7).
 */
return new class extends Migration
{
    /** @var array<string, list<string>> table => foreign key columns without an index */
    private const UNINDEXED = [
        'grade_appeals' => ['decided_by_user_id'],
        'grade_edit_requests' => ['decided_by_user_id', 'subject_id'],
        'grade_section_approvals' => ['teacher_id'],
        'grade_student_notes' => ['updated_by_user_id'],
    ];

    private const JSON = ['grade_bands', 'result_options', 'mark_tones', 'teacher_view'];

    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            foreach (self::JSON as $column) {
                DB::statement("ALTER TABLE grade_settings ALTER COLUMN {$column} TYPE jsonb USING {$column}::jsonb");
            }
        }

        foreach (self::UNINDEXED as $table => $columns) {
            Schema::table($table, function (Blueprint $blueprint) use ($columns) {
                foreach ($columns as $column) {
                    $blueprint->index($column);
                }
            });
        }
    }

    public function down(): void
    {
        foreach (self::UNINDEXED as $table => $columns) {
            Schema::table($table, function (Blueprint $blueprint) use ($columns) {
                foreach ($columns as $column) {
                    $blueprint->dropIndex([$column]);
                }
            });
        }

        if (DB::getDriverName() === 'pgsql') {
            foreach (self::JSON as $column) {
                DB::statement("ALTER TABLE grade_settings ALTER COLUMN {$column} TYPE json USING {$column}::json");
            }
        }
    }
};

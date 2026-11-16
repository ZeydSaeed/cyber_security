<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The third stage (owner's request, 2026-11-17): sections A and B, like the
 * second. Only the CHECK rules name the stages; Domain\Stage holds the rest.
 */
return new class extends Migration
{
    private const TABLES = ['grade_subjects', 'grade_students', 'grade_marks'];

    public function up(): void
    {
        $this->stages('1, 2, 3');
    }

    public function down(): void
    {
        $this->stages('1, 2');
    }

    private function stages(string $list): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach (self::TABLES as $table) {
            DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$table}_stage_check");
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_stage_check CHECK (stage IN ({$list}))");
        }
    }
};

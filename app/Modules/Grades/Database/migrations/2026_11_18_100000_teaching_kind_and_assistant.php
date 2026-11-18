<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * How a section of a subject is taught (owner's request, 2026-11-18):
 * theoretical or practical, and the teacher's assistant, another teacher,
 * if there is one. Both are optional and chosen in the teacher's form.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('grade_subject_teachers', function (Blueprint $table) {
            // Domain\TeachingKind; null: not said.
            $table->string('kind', 16)->nullable();
            // Shown only; the assistant reaches no marks through it.
            $table->foreignId('assistant_id')->nullable()->constrained('grade_teachers')->nullOnDelete();
            $table->index('assistant_id');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE grade_subject_teachers ADD CONSTRAINT grade_subject_teachers_kind_check CHECK (kind IN ('theory', 'practical'))");
            DB::statement('ALTER TABLE grade_subject_teachers ADD CONSTRAINT grade_subject_teachers_assistant_check CHECK (assistant_id <> teacher_id)');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE grade_subject_teachers DROP CONSTRAINT IF EXISTS grade_subject_teachers_kind_check');
            DB::statement('ALTER TABLE grade_subject_teachers DROP CONSTRAINT IF EXISTS grade_subject_teachers_assistant_check');
        }

        Schema::table('grade_subject_teachers', function (Blueprint $table) {
            $table->dropForeign(['assistant_id']);
            $table->dropIndex(['assistant_id']);
            $table->dropColumn(['kind', 'assistant_id']);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Teachers answering appeals of their own subjects, when the owner allows
 * it (ADR-0044): the setting, and which teacher answered an appeal.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('grade_settings', function (Blueprint $table) {
            $table->boolean('teacher_appeal_replies')->default(false);
        });

        Schema::table('grade_appeals', function (Blueprint $table) {
            // Indexed, as every foreign key (2026_11_19_600000).
            $table->foreignId('decided_by_teacher_id')->nullable()->index()->constrained('grade_teachers')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('grade_appeals', function (Blueprint $table) {
            // The index goes before the column (SQLite refuses otherwise).
            $table->dropForeign(['decided_by_teacher_id']);
            $table->dropIndex(['decided_by_teacher_id']);
            $table->dropColumn('decided_by_teacher_id');
        });

        Schema::table('grade_settings', function (Blueprint $table) {
            $table->dropColumn('teacher_appeal_replies');
        });
    }
};

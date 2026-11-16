<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The owner's decision of 2026-11-16 (ADR-0034, amended): a teacher's code
 * is their employee number, and the owner wants to see it again. It is now
 * kept as the students' codes are: the HMAC digest still finds the teacher,
 * and a copy encrypted with the application key can be shown to the owner
 * (each showing is audited). Teachers saved before this keep no copy until
 * their code is set again.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('grade_teachers', function (Blueprint $table) {
            $table->text('code_encrypted')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('grade_teachers', function (Blueprint $table) {
            $table->dropColumn('code_encrypted');
        });
    }
};

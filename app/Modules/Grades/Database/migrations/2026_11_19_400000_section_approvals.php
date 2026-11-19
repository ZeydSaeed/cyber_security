<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A teacher approving a section's marks (ADR-0041, owner's approval of
 * 2026-11-19): once approved, the teacher's sheet locks; the owner may
 * withdraw the approval, and a later change by the teacher (under a
 * grant) clears it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('grade_section_approvals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subject_id')->constrained('grade_subjects')->cascadeOnDelete();
            $table->char('section', 1);
            $table->string('period', 20);
            $table->foreignId('teacher_id')->nullable()->constrained('grade_teachers')->nullOnDelete();
            $table->timestampTz('approved_at');
            $table->timestampsTz();
            $table->unique(['subject_id', 'section', 'period']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('grade_section_approvals');
    }
};

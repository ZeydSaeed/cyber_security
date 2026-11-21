<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Students' appeals get their own settings tab (owner's request,
 * 2026-11-20, ADR-0034 "Appeals tab"): besides a length of time after
 * publication and a closing date, appeals may open from a fixed date.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('grade_settings', function (Blueprint $table) {
            // Appeals open no earlier than this moment; null: at publication.
            $table->timestampTz('appeal_from')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('grade_settings', function (Blueprint $table) {
            $table->dropColumn('appeal_from');
        });
    }
};

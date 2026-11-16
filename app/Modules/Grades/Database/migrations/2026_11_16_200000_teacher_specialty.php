<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A teacher's specialty, as the school's staff list writes it (owner's
 * request, 2026-11-16), e.g. "Electricity" or "Arabic". Optional.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('grade_teachers', function (Blueprint $table) {
            $table->string('specialty', 80)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('grade_teachers', function (Blueprint $table) {
            $table->dropColumn('specialty');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * The template's Requests module was removed from this application
 * (ADR-0034); databases created before the removal still have its table.
 * Empty in this application, so nothing is lost. Not reversible on purpose:
 * the module's code is gone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('approval_requests');
    }

    public function down(): void
    {
        // Nothing: the Requests module no longer exists.
    }
};

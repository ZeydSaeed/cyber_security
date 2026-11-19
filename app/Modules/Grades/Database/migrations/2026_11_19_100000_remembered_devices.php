<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * "Remember this device" for teachers and students (ADR-0038, owner's
 * request of 2026-11-19). A device keeps a random token in a cookie, never
 * the code; here are only a selector to find the row and a hash of the
 * rest, with a fingerprint of the code it was made with, so a new code
 * ends it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('grade_remembered_devices', function (Blueprint $table) {
            $table->id();
            // Domain\DeviceHolder: teacher or student.
            $table->string('holder', 10);
            $table->unsignedBigInteger('holder_id');
            $table->char('selector', 24)->unique();
            $table->char('validator_hash', 64);
            // HMAC of the holder's code fingerprint when the device was remembered.
            $table->char('fingerprint', 64);
            $table->string('user_agent', 255)->nullable();
            $table->timestampTz('expires_at');
            $table->timestampTz('last_used_at')->nullable();
            $table->timestampsTz();
            $table->index(['holder', 'holder_id']);
            $table->index('expires_at');
        });

        Schema::table('grade_settings', function (Blueprint $table) {
            // Days a device stays remembered; 0: the option is off.
            $table->unsignedSmallInteger('remember_days')->default(30);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE grade_remembered_devices ADD CONSTRAINT grade_remembered_devices_holder_check CHECK (holder IN ('teacher', 'student'))");
            DB::statement('ALTER TABLE grade_settings ADD CONSTRAINT grade_settings_remember_check CHECK (remember_days BETWEEN 0 AND 365)');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE grade_settings DROP CONSTRAINT IF EXISTS grade_settings_remember_check');
        }

        Schema::table('grade_settings', function (Blueprint $table) {
            $table->dropColumn('remember_days');
        });

        Schema::dropIfExists('grade_remembered_devices');
    }
};

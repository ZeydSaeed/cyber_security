<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Owner's request (2026-11-18):
 *
 * - permission_grants: permissions granted to one user directly, besides
 *   those their roles grant. Rows change only through SyncPermissions,
 *   which checks and audits.
 * - users.admin_password: the password an administrator set, encrypted
 *   with the application key (reversible, unlike `password`, which stays
 *   the hash used to sign in), so administrators can show it. It is
 *   cleared when the person changes their own password;
 *   admin_password_set_at then tells "changed by the user" from "unknown".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('permission_grants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('permission', 64);
            $table->foreignId('granted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('created_at')->useCurrent();

            // Also serves the foreign key on user_id.
            $table->unique(['user_id', 'permission']);
            $table->index('granted_by');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->text('admin_password')->nullable();
            $table->timestampTz('admin_password_set_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['admin_password', 'admin_password_set_at']);
        });

        Schema::dropIfExists('permission_grants');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Per-user notification preferences for PRD-010
            // (push/sound/daily-digest). The defaults service
            // backfills missing keys at read time, so this column
            // can stay empty for every existing user.
            //
            // `nullable()` instead of `default('{}')` because
            // MySQL 5.7 / 8.0 reject literal DEFAULT values on
            // JSON columns (ERROR 1101). The accessor's null
            // branch already returns the merged defaults, so
            // fresh users see exactly the same shape either way.
            $table->json('notification_settings')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('notification_settings');
        });
    }
};

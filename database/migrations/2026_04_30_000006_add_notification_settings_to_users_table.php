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
            // can stay empty `{}` for every existing user — no
            // data migration needed.
            $table->json('notification_settings')->default('{}');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('notification_settings');
        });
    }
};

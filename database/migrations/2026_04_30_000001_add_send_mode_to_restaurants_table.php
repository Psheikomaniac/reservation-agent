<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('restaurants', function (Blueprint $table) {
            $table->string('send_mode')->default('manual')->after('imap_password');
            $table->unsignedInteger('auto_send_party_size_max')->default(10)->after('send_mode');
            $table->unsignedInteger('auto_send_min_lead_time_minutes')->default(90)->after('auto_send_party_size_max');
            $table->dateTime('send_mode_changed_at')->nullable()->after('auto_send_min_lead_time_minutes');
            $table->foreignId('send_mode_changed_by')
                ->nullable()
                ->after('send_mode_changed_at')
                ->constrained('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('restaurants', function (Blueprint $table) {
            $table->dropForeign(['send_mode_changed_by']);
            $table->dropColumn([
                'send_mode',
                'auto_send_party_size_max',
                'auto_send_min_lead_time_minutes',
                'send_mode_changed_at',
                'send_mode_changed_by',
            ]);
        });
    }
};

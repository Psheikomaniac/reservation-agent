<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reservation_replies', function (Blueprint $table) {
            $table->string('send_mode_at_creation')->nullable()->after('outbound_message_id');
            $table->dateTime('shadow_compared_at')->nullable()->after('send_mode_at_creation');
            $table->boolean('shadow_was_modified')->default(false)->after('shadow_compared_at');
            $table->json('auto_send_decision')->nullable()->after('shadow_was_modified');
            $table->dateTime('auto_send_scheduled_for')->nullable()->after('auto_send_decision');
        });
    }

    public function down(): void
    {
        Schema::table('reservation_replies', function (Blueprint $table) {
            $table->dropColumn([
                'send_mode_at_creation',
                'shadow_compared_at',
                'shadow_was_modified',
                'auto_send_decision',
                'auto_send_scheduled_for',
            ]);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reservation_replies', function (Blueprint $table) {
            $table->boolean('is_fallback')->default(false)->after('auto_send_scheduled_for');
        });
    }

    public function down(): void
    {
        Schema::table('reservation_replies', function (Blueprint $table) {
            $table->dropColumn('is_fallback');
        });
    }
};

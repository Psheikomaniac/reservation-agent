<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reservation_replies', function (Blueprint $table) {
            $table->string('outbound_message_id')->nullable()->after('error_message');
        });
    }

    public function down(): void
    {
        Schema::table('reservation_replies', function (Blueprint $table) {
            $table->dropColumn('outbound_message_id');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reservation_requests', function (Blueprint $table) {
            $table->string('email_message_id')->nullable()->after('raw_payload');
            $table->unique('email_message_id');
        });
    }

    public function down(): void
    {
        Schema::table('reservation_requests', function (Blueprint $table) {
            $table->dropUnique(['email_message_id']);
            $table->dropColumn('email_message_id');
        });
    }
};

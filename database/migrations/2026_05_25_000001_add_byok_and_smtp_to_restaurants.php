<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('restaurants', function (Blueprint $table) {
            $table->text('openai_api_key')->nullable()->after('tonality');
            $table->string('smtp_host')->nullable()->after('imap_password');
            $table->unsignedInteger('smtp_port')->nullable()->after('smtp_host');
            $table->string('smtp_username')->nullable()->after('smtp_port');
            $table->text('smtp_password')->nullable()->after('smtp_username');
            $table->string('smtp_from_address')->nullable()->after('smtp_password');
            $table->string('smtp_from_name')->nullable()->after('smtp_from_address');
        });
    }

    public function down(): void
    {
        Schema::table('restaurants', function (Blueprint $table) {
            $table->dropColumn([
                'openai_api_key',
                'smtp_host',
                'smtp_port',
                'smtp_username',
                'smtp_password',
                'smtp_from_address',
                'smtp_from_name',
            ]);
        });
    }
};

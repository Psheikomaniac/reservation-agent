<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const string INDEX_NAME = 'reservation_requests_dashboard_index';

    public function up(): void
    {
        Schema::table('reservation_requests', function (Blueprint $table): void {
            $table->index(['restaurant_id', 'status', 'created_at'], self::INDEX_NAME);
        });
    }

    public function down(): void
    {
        Schema::table('reservation_requests', function (Blueprint $table): void {
            $table->dropIndex(self::INDEX_NAME);
        });
    }
};

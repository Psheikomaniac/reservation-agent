<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('auto_send_audits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reservation_reply_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->foreignId('restaurant_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->string('send_mode');
            $table->string('decision');
            $table->string('reason');
            $table->foreignId('triggered_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            // Append-only: only created_at, no updated_at, no soft-deletes.
            $table->dateTime('created_at')->useCurrent();

            $table->index(['restaurant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auto_send_audits');
    }
};

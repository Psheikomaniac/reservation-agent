<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reservation_table_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reservation_request_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->foreignId('table_id')
                ->constrained('tables')
                ->restrictOnDelete();
            $table->dateTime('assigned_at');
            $table->foreignId('assigned_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamps();

            $table->unique(['reservation_request_id', 'table_id']);
            $table->index('table_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reservation_table_assignments');
    }
};

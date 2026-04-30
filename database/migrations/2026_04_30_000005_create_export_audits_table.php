<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('export_audits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('restaurant_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->foreignId('user_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->string('format');
            $table->json('filter_snapshot');
            $table->unsignedInteger('record_count');
            // `storage_path` is null for sync exports (≤100 records,
            // streamed straight to the operator) and for async ones
            // after the cleanup job has expired the file. The audit
            // row itself is retained for reproducibility / GDPR.
            $table->string('storage_path')->nullable();
            $table->dateTime('downloaded_at')->nullable();
            // 24-hour shelf life for async-path artefacts; null for
            // the sync path that never persists a file.
            $table->dateTime('expires_at')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['restaurant_id', 'created_at']);
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('export_audits');
    }
};

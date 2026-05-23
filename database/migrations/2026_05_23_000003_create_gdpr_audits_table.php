<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gdpr_audits', function (Blueprint $table) {
            $table->id();
            // view | delete | owner_bulk_delete
            $table->string('action');
            $table->foreignId('restaurant_id')
                ->constrained()
                ->cascadeOnDelete();
            // Append-only and deliberately PII-FREE (PRD-015 § Datenmodell):
            // no guest_email, reservation_id, IP or user-agent — the table
            // answers "how many access/erasure requests in restaurant X", not
            // "who did what", so it never falls under the same erasure claim.
            $table->dateTime('created_at')->useCurrent();

            $table->index(['restaurant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gdpr_audits');
    }
};

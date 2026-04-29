<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reservation_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reservation_request_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->string('direction');
            $table->string('message_id')->unique();
            $table->string('in_reply_to')->nullable();
            $table->text('references')->nullable();
            $table->string('subject');
            $table->string('from_address');
            $table->string('to_address');
            $table->text('body_plain');
            $table->text('raw_headers');
            $table->dateTime('sent_at')->nullable();
            $table->dateTime('received_at')->nullable();
            $table->timestamps();

            $table->index(['reservation_request_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reservation_messages');
    }
};

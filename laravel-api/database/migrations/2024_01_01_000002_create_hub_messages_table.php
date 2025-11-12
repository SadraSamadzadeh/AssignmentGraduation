<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hub_messages', function (Blueprint $table) {
            $table->id();
            $table->string('request_id')->unique();
            $table->string('message_type'); // 'tracking_data', 'video_data', 'response'
            $table->string('source_backend'); // 'tracking', 'video'
            $table->string('target_backend')->nullable();
            $table->json('message_data');
            $table->string('status')->default('pending'); // 'pending', 'processed', 'error'
            $table->text('error_message')->nullable();
            $table->timestamp('received_at');
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->index(['request_id']);
            $table->index(['message_type', 'status']);
            $table->index(['source_backend']);
            $table->index(['received_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hub_messages');
    }
};
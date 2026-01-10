<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('video_dashboard', function (Blueprint $table) {
            $table->id();
            $table->string('video_id');
            $table->string('video_reference');
            $table->json('video_data');
            $table->string('source_system', 100);
            $table->enum('status', ['unmatched', 'pending', 'processed', 'ignored'])->default('unmatched');
            $table->integer('match_attempts')->default(0);
            $table->timestamp('last_match_attempt_at')->nullable();
            $table->foreignId('assigned_to_user_id')->nullable()->constrained('users')->onDelete('set null');
            $table->enum('priority', ['low', 'medium', 'high', 'urgent'])->default('medium');
            $table->text('notes')->nullable();
            $table->timestamp('received_at');
            $table->timestamps();

            $table->index(['video_id']);
            $table->index(['status']);
            $table->index(['priority']);
            $table->index(['assigned_to_user_id']);
            $table->index(['received_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('video_dashboard');
    }
};

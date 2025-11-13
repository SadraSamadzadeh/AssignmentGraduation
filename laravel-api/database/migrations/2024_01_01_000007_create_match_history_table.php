<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('match_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('global_match_id')->nullable()->constrained('global_matches')->onDelete('cascade');
            $table->bigInteger('tracking_id');
            $table->string('video_id');
            $table->enum('action', ['created', 'updated', 'deleted', 'verified', 'rejected']);
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->onDelete('set null');
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('created_at');

            $table->index(['global_match_id']);
            $table->index(['tracking_id']);
            $table->index(['video_id']);
            $table->index(['user_id']);
            $table->index(['action']);
            $table->index(['created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('match_history');
    }
};

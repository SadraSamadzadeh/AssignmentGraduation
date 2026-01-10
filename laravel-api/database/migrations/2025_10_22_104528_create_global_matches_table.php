<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('global_matches', function (Blueprint $table) {
            $table->id();
            $table->string('global_match_id')->unique();
            $table->bigInteger('tracking_id');
            $table->string('video_id');
            $table->string('confidence_level', 20);
            $table->json('tracking_data');
            $table->json('video_data');
            $table->string('status', 50)->default('pending');
            
            // Match quality scores
            $table->decimal('match_score', 5, 2)->nullable();
            $table->decimal('time_proximity_score', 5, 2)->nullable();
            $table->decimal('duration_similarity_score', 5, 2)->nullable();
            $table->decimal('temporal_overlap_score', 5, 2)->nullable();
            $table->json('match_details')->nullable();
            
            // Audit fields
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->onDelete('set null');
            $table->foreignId('reviewed_by_user_id')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamp('reviewed_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestamp('matched_at')->nullable();
            $table->timestamps();

            $table->index('tracking_id');
            $table->index('video_id');
            $table->index('status');
            $table->index('confidence_level');
            $table->index('match_score');
            $table->index(['status', 'match_score']);
            $table->index('matched_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('global_matches');
    }
};

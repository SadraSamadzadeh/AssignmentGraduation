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
            $table->integer('tracking_id');
            $table->string('video_id');
            $table->decimal('match_score', 5, 2);
            $table->string('confidence_level');
            $table->json('match_details');
            $table->json('tracking_data');
            $table->json('video_data');
            $table->string('processed_by')->default('hub');
            $table->timestamp('matched_at');
            $table->timestamps();

            $table->index(['tracking_id', 'video_id']);
            $table->index(['match_score']);
            $table->index(['matched_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('global_matches');
    }
};
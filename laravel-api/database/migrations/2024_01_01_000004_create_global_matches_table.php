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
            $table->string('global_id')->unique();
            $table->foreignId('tracking_match_id')->constrained('tracking_matches')->onDelete('cascade');
            $table->foreignId('video_match_id')->constrained('video_matches')->onDelete('cascade');
            $table->decimal('match_score', 5, 2);
            $table->string('confidence_level');
            $table->json('reasons')->nullable();
            $table->timestamps();
            
            $table->index('global_id');
            $table->index('match_score');
            $table->index('confidence_level');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('global_matches');
    }
};
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('video_matches', function (Blueprint $table) {
            $table->id();
            $table->string('video_id');
            $table->string('club_id')->nullable();
            $table->string('club_name')->nullable();
            $table->string('home_team')->nullable();
            $table->string('away_team')->nullable();
            $table->timestamp('start_time');
            $table->timestamp('end_time')->nullable();
            $table->string('timezone')->default('UTC');
            $table->timestamps();
            
            $table->index('video_id');
            $table->index(['start_time', 'end_time']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('video_matches');
    }
};
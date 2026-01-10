<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('confirmed_matches', function (Blueprint $table) {
            $table->id();
            $table->string('video_team_id')->index();
            $table->string('primeplay_team_id')->index();
            $table->decimal('match_score', 5, 2);
            $table->json('match_details')->nullable();
            $table->timestamp('matched_at');
            $table->timestamps();
            
            $table->unique(['video_team_id', 'primeplay_team_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('confirmed_matches');
    }
};

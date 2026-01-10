<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('team_mappings', function (Blueprint $table) {
            $table->id();
            $table->string('video_team_id');
            $table->string('primeplay_team_id');
            $table->string('video_team_name');
            $table->string('primeplay_team_name');
            $table->decimal('confidence_score', 5, 2)->nullable();
            $table->integer('times_matched')->default(0);
            $table->timestamp('last_matched_at')->nullable();
            $table->string('status', 20)->default('active');
            $table->json('match_details')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();

            $table->index('video_team_id');
            $table->index('primeplay_team_id');
            $table->index('status');
            $table->unique(['video_team_id', 'primeplay_team_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('team_mappings');
    }
};

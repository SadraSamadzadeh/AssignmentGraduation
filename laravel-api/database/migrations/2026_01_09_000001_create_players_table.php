<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('players', function (Blueprint $table) {
            $table->id();
            $table->string('player_name');
            $table->string('device_id')->unique();
            $table->string('dataset_id'); // From Primeplay event
            $table->foreignId('tracking_dashboard_id')
                ->nullable()
                ->constrained('tracking_dashboard')
                ->onDelete('cascade');
            $table->json('player_data')->nullable(); // Additional player metadata
            $table->string('jersey_number')->nullable();
            $table->string('position')->nullable();
            $table->string('team_name')->nullable();
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            // Indexes for performance
            $table->index(['device_id']);
            $table->index(['dataset_id']);
            $table->index(['tracking_dashboard_id']);
            $table->index(['player_name']);
            $table->index(['team_name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('players');
    }
};

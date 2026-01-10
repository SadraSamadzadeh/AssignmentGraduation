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
            $table->string('player_name')->nullable();
            $table->string('device_id');
            $table->string('dataset_id');
            $table->foreignId('tracking_dashboard_id')->nullable()->constrained('tracking_dashboard')->onDelete('cascade');
            $table->json('player_data')->nullable();
            $table->string('jersey_number')->nullable();
            $table->string('position')->nullable();
            $table->string('team_name')->nullable();
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->index('device_id');
            $table->index('dataset_id');
            $table->index(['dataset_id', 'device_id']);
            $table->index('tracking_dashboard_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('players');
    }
};

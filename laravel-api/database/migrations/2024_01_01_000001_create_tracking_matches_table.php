<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tracking_matches', function (Blueprint $table) {
            $table->id();
            $table->integer('tracking_id');
            $table->string('name')->nullable();
            $table->string('team_name')->nullable();
            $table->timestamp('start_time');
            $table->timestamp('end_time');
            $table->string('avg_total_time_active')->nullable();
            $table->timestamps();
            
            $table->index('tracking_id');
            $table->index(['start_time', 'end_time']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tracking_matches');
    }
};
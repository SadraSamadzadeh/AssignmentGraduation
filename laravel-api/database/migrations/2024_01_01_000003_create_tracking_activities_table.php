<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tracking_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tracking_match_id')->constrained('tracking_matches')->onDelete('cascade');
            $table->string('activity_type')->nullable();
            $table->string('duration')->nullable();
            $table->string('intensity')->nullable();
            $table->json('details')->nullable();
            $table->timestamps();
            
            $table->index('tracking_match_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tracking_activities');
    }
};
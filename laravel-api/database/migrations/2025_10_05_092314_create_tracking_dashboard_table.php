<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tracking_dashboard', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('tracking_id');
            $table->string('tracking_reference')->nullable();
            $table->json('tracking_data');
            $table->string('source_system', 100);
            
            // Extracted fields for matching
            $table->date('event_date')->nullable();
            $table->timestamp('start_time')->nullable();
            $table->timestamp('end_time')->nullable();
            $table->integer('duration_minutes')->nullable();
            $table->string('dataset_name')->nullable();
            $table->string('team_name')->nullable();
            
            // Workflow fields
            $table->string('status', 50)->default('unmatched');
            $table->integer('match_attempts')->default(0);
            $table->timestamp('last_match_attempt_at')->nullable();
            $table->foreignId('assigned_to_user_id')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamp('received_at');
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index('tracking_id');
            $table->index('status');
            $table->index('event_date');
            $table->index('start_time');
            $table->index(['status', 'event_date']);
            $table->index('assigned_to_user_id');
            $table->index('received_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tracking_dashboard');
    }
};

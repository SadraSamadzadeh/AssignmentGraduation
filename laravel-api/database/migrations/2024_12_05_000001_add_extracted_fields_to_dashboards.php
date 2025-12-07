<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * This migration adds extracted fields from JSON to enable efficient
     * time-based matching algorithm (70% time, 20% duration, 10% overlap).
     * 
     * Critical for performance: Avoids JSON parsing on every comparison.
     */
    public function up(): void
    {
        // Add extracted fields to tracking_dashboard
        Schema::table('tracking_dashboard', function (Blueprint $table) {
            // Matching algorithm fields
            $table->date('event_date')->nullable()->after('tracking_data');
            $table->timestamp('start_time')->nullable()->after('event_date');
            $table->timestamp('end_time')->nullable()->after('start_time');
            $table->integer('duration_minutes')->nullable()->after('end_time');
            
            // Metadata fields
            $table->string('dataset_name')->nullable()->after('duration_minutes');
            $table->string('team_name')->nullable()->after('dataset_name');
            
            // Workflow fields
            $table->string('status', 50)->default('unmatched')->after('source_system');
            $table->timestamp('expires_at')->nullable()->after('received_at');
            
            // Indexes for matching performance
            $table->index('event_date');
            $table->index('start_time');
            $table->index('status');
            $table->index(['status', 'event_date']);
        });

        // Add extracted fields to video_dashboard
        Schema::table('video_dashboard', function (Blueprint $table) {
            // Matching algorithm fields
            $table->date('event_date')->nullable()->after('video_data');
            $table->timestamp('start_time')->nullable()->after('event_date');
            $table->timestamp('end_time')->nullable()->after('start_time');
            $table->integer('duration_minutes')->nullable()->after('end_time');
            
            // Metadata fields
            $table->string('home_club_name')->nullable()->after('duration_minutes');
            $table->string('away_club_name')->nullable()->after('home_club_name');
            $table->string('field_name')->nullable()->after('away_club_name');
            $table->boolean('is_training')->default(false)->after('field_name');
            
            // Workflow fields
            $table->string('status', 50)->default('unmatched')->after('source_system');
            $table->timestamp('expires_at')->nullable()->after('received_at');
            
            // Indexes for matching performance
            $table->index('event_date');
            $table->index('start_time');
            $table->index('status');
            $table->index(['status', 'event_date']);
            $table->index(['home_club_name', 'away_club_name']);
        });

        // Add CHECK constraints (PostgreSQL)
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement("
                ALTER TABLE tracking_dashboard 
                ADD CONSTRAINT tracking_status_check 
                CHECK (status IN ('unmatched', 'matched', 'expired', 'ignored'))
            ");
            
            DB::statement("
                ALTER TABLE video_dashboard 
                ADD CONSTRAINT video_status_check 
                CHECK (status IN ('unmatched', 'matched', 'expired', 'ignored'))
            ");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tracking_dashboard', function (Blueprint $table) {
            $table->dropIndex(['tracking_dashboard_event_date_index']);
            $table->dropIndex(['tracking_dashboard_start_time_index']);
            $table->dropIndex(['tracking_dashboard_status_index']);
            $table->dropIndex(['tracking_dashboard_status_event_date_index']);
            
            $table->dropColumn([
                'event_date',
                'start_time',
                'end_time',
                'duration_minutes',
                'dataset_name',
                'team_name',
                'status',
                'expires_at'
            ]);
        });

        Schema::table('video_dashboard', function (Blueprint $table) {
            $table->dropIndex(['video_dashboard_event_date_index']);
            $table->dropIndex(['video_dashboard_start_time_index']);
            $table->dropIndex(['video_dashboard_status_index']);
            $table->dropIndex(['video_dashboard_status_event_date_index']);
            $table->dropIndex(['video_dashboard_home_club_name_away_club_name_index']);
            
            $table->dropColumn([
                'event_date',
                'start_time',
                'end_time',
                'duration_minutes',
                'home_club_name',
                'away_club_name',
                'field_name',
                'is_training',
                'status',
                'expires_at'
            ]);
        });
    }
};

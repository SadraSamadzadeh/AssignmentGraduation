<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * This migration simplifies the database structure based on updated requirements:
     * - Removes unnecessary fields from global_matches (match_score, match_details, processed_by, verified_by_user_id)
     * - Removes workflow fields from tracking_dashboard (tracking_reference, status, priority, notes)
     * - Removes workflow fields from video_dashboard (status, priority, notes, assigned_to_user_id)
     * - Drops match_history and dashboard_activity_log tables (audit features removed)
     */
    public function up(): void
    {
        // Drop audit tables that are no longer needed
        Schema::dropIfExists('dashboard_activity_log');
        Schema::dropIfExists('match_history');

        // Simplify global_matches table
        Schema::table('global_matches', function (Blueprint $table) {
            // Drop foreign key constraints first
            $table->dropForeign(['verified_by_user_id']);
            
            // Drop indexes
            $table->dropIndex(['match_score']);
            $table->dropIndex(['verified_by_user_id']);
            
            // Drop columns
            $table->dropColumn([
                'match_score',
                'match_details',
                'processed_by',
                'verified_by_user_id'
            ]);
        });

        // Simplify tracking_dashboard table
        Schema::table('tracking_dashboard', function (Blueprint $table) {
            // Drop indexes
            $table->dropIndex(['status']);
            $table->dropIndex(['priority']);
            
            // Drop columns
            $table->dropColumn([
                'tracking_reference',
                'status',
                'priority',
                'notes'
            ]);
        });

        // Simplify video_dashboard table
        Schema::table('video_dashboard', function (Blueprint $table) {
            // Drop foreign key constraint first
            $table->dropForeign(['assigned_to_user_id']);
            
            // Drop indexes
            $table->dropIndex(['status']);
            $table->dropIndex(['priority']);
            $table->dropIndex(['assigned_to_user_id']);
            
            // Drop columns
            $table->dropColumn([
                'status',
                'priority',
                'notes',
                'assigned_to_user_id'
            ]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Restore video_dashboard columns
        Schema::table('video_dashboard', function (Blueprint $table) {
            $table->enum('status', ['unmatched', 'pending', 'processed', 'ignored'])->default('unmatched')->after('source_system');
            $table->foreignId('assigned_to_user_id')->nullable()->after('last_match_attempt_at')->constrained('users')->onDelete('set null');
            $table->enum('priority', ['low', 'medium', 'high', 'urgent'])->default('medium')->after('assigned_to_user_id');
            $table->text('notes')->nullable()->after('priority');
            
            $table->index(['status']);
            $table->index(['priority']);
            $table->index(['assigned_to_user_id']);
        });

        // Restore tracking_dashboard columns
        Schema::table('tracking_dashboard', function (Blueprint $table) {
            $table->string('tracking_reference')->after('tracking_id');
            $table->enum('status', ['unmatched', 'pending', 'processed', 'ignored'])->default('unmatched')->after('source_system');
            $table->enum('priority', ['low', 'medium', 'high', 'urgent'])->default('medium')->after('assigned_to_user_id');
            $table->text('notes')->nullable()->after('priority');
            
            $table->index(['status']);
            $table->index(['priority']);
        });

        // Restore global_matches columns
        Schema::table('global_matches', function (Blueprint $table) {
            $table->decimal('match_score', 5, 2)->after('video_id');
            $table->json('match_details')->after('confidence_level');
            $table->string('processed_by')->default('hub')->after('video_data');
            $table->foreignId('verified_by_user_id')->nullable()->after('created_by_user_id')->constrained('users')->onDelete('set null');
            
            $table->index(['match_score']);
            $table->index(['verified_by_user_id']);
        });

        // Recreate match_history table
        Schema::create('match_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('global_match_id')->nullable()->constrained('global_matches')->onDelete('cascade');
            $table->bigInteger('tracking_id');
            $table->string('video_id');
            $table->enum('action', ['created', 'updated', 'deleted', 'verified', 'rejected']);
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->onDelete('set null');
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('created_at');

            $table->index(['global_match_id']);
            $table->index(['tracking_id']);
            $table->index(['video_id']);
            $table->index(['user_id']);
            $table->index(['action']);
            $table->index(['created_at']);
        });

        // Recreate dashboard_activity_log table
        Schema::create('dashboard_activity_log', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->enum('dashboard_type', ['tracking', 'video']);
            $table->bigInteger('record_id');
            $table->enum('action', ['viewed', 'updated', 'assigned', 'status_changed', 'note_added']);
            $table->json('details')->nullable();
            $table->timestamp('created_at');

            $table->index(['user_id']);
            $table->index(['dashboard_type']);
            $table->index(['record_id']);
            $table->index(['action']);
            $table->index(['created_at']);
        });
    }
};

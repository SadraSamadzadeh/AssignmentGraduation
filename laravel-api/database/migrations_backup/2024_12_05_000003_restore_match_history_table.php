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
     * This migration restores the match_history table for audit trail.
     * Critical for debugging, compliance, and algorithm improvement.
     */
    public function up(): void
    {
        // Drop if exists from old structure
        Schema::dropIfExists('match_history');
        
        // Clean up any orphaned indexes
        DB::statement('DROP INDEX IF EXISTS match_history_action_index CASCADE');
        DB::statement('DROP INDEX IF EXISTS match_history_global_match_id_index CASCADE');
        DB::statement('DROP INDEX IF EXISTS match_history_performed_at_index CASCADE');
        DB::statement('DROP INDEX IF EXISTS match_history_performed_by_user_id_index CASCADE');
        
        Schema::create('match_history', function (Blueprint $table) {
            $table->id();
            
            // Reference to match
            $table->foreignId('global_match_id')
                  ->constrained('global_matches')
                  ->onDelete('cascade');
            
            // Action type
            $table->enum('action', [
                'created',
                'confirmed', 
                'rejected',
                'score_updated',
                'data_updated',
                'status_changed',
                'reassigned'
            ]);
            
            // State changes
            $table->string('previous_status', 50)->nullable();
            $table->string('new_status', 50);
            $table->decimal('previous_score', 5, 2)->nullable();
            $table->decimal('new_score', 5, 2)->nullable();
            
            // Detailed change log
            $table->jsonb('changes')->nullable();
            $table->text('reason')->nullable();
            
            // Audit info
            $table->foreignId('performed_by_user_id')
                  ->nullable()
                  ->constrained('users')
                  ->onDelete('set null');
            $table->timestamp('performed_at')->useCurrent();
            
            $table->timestamp('created_at')->useCurrent();
        });

        // Add GIN index for JSONB changes field (PostgreSQL)
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement('CREATE INDEX idx_match_history_changes ON match_history USING GIN (changes)');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('match_history');
    }
};

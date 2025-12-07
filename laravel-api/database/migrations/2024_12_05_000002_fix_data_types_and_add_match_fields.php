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
     * This migration fixes critical data type inconsistencies:
     * - tracking_id: INTEGER → BIGINT in global_matches
     * - Adds missing match_score field
     * - Adds score breakdown fields for algorithm transparency
     */
    public function up(): void
    {
        Schema::table('global_matches', function (Blueprint $table) {
            // Fix data type mismatch: tracking_id should be BIGINT
            $table->bigInteger('tracking_id')->change();
            
            // Add missing match quality fields
            $table->decimal('match_score', 5, 2)->nullable()->after('confidence_level');
            $table->decimal('time_proximity_score', 5, 2)->nullable()->after('match_score');
            $table->decimal('duration_similarity_score', 5, 2)->nullable()->after('time_proximity_score');
            $table->decimal('temporal_overlap_score', 5, 2)->nullable()->after('duration_similarity_score');
            $table->jsonb('match_details')->nullable()->after('temporal_overlap_score');
            
            // Add review fields
            $table->bigInteger('reviewed_by_user_id')->nullable()->after('status');
            $table->timestamp('reviewed_at')->nullable()->after('reviewed_by_user_id');
            $table->text('rejection_reason')->nullable()->after('reviewed_at');
            
            // Add indexes
            $table->index('match_score');
            $table->index(['status', 'match_score']);
            
            // Add foreign key for reviewed_by
            $table->foreign('reviewed_by_user_id')
                  ->references('id')
                  ->on('users')
                  ->onDelete('set null');
        });

        // Add CHECK constraints (PostgreSQL)
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement("
                ALTER TABLE global_matches 
                ADD CONSTRAINT match_score_range 
                CHECK (match_score IS NULL OR (match_score >= 0 AND match_score <= 100))
            ");
            
            // Update existing status check to include more states
            DB::statement("
                ALTER TABLE global_matches 
                DROP CONSTRAINT IF EXISTS global_matches_status_check
            ");
            
            DB::statement("
                ALTER TABLE global_matches 
                ADD CONSTRAINT global_matches_status_check 
                CHECK (status IN ('pending', 'pending_review', 'auto_confirmed', 'confirmed', 'rejected'))
            ");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('global_matches', function (Blueprint $table) {
            $table->dropForeign(['reviewed_by_user_id']);
            
            $table->dropIndex(['global_matches_match_score_index']);
            $table->dropIndex(['global_matches_status_match_score_index']);
            
            $table->dropColumn([
                'match_score',
                'time_proximity_score',
                'duration_similarity_score',
                'temporal_overlap_score',
                'match_details',
                'reviewed_by_user_id',
                'reviewed_at',
                'rejection_reason'
            ]);
            
            // Revert tracking_id back to integer
            $table->integer('tracking_id')->change();
        });
    }
};

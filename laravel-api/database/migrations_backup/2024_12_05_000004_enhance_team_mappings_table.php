<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * This migration enhances the confirmed_matches table to track
     * persistent team relationships with confidence scoring.
     */
    public function up(): void
    {
        Schema::table('confirmed_matches', function (Blueprint $table) {
            // Add confidence tracking
            $table->integer('times_matched')->default(1)->after('match_score');
            $table->timestamp('last_matched_at')->useCurrent()->after('times_matched');
            
            // Add status for active/inactive mappings
            $table->enum('status', ['active', 'inactive', 'disputed'])
                  ->default('active')
                  ->after('last_matched_at');
            
            // Add notes field
            $table->text('notes')->nullable()->after('match_details');
            
            // Add audit tracking
            $table->foreignId('created_by_user_id')
                  ->nullable()
                  ->after('notes')
                  ->constrained('users')
                  ->onDelete('set null');
            
            // Add indexes
            $table->index('status');
            $table->index('last_matched_at');
            $table->index('created_by_user_id');
        });

        // Rename for clarity
        Schema::rename('confirmed_matches', 'team_mappings');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::rename('team_mappings', 'confirmed_matches');
        
        Schema::table('confirmed_matches', function (Blueprint $table) {
            $table->dropForeign(['created_by_user_id']);
            
            $table->dropIndex(['confirmed_matches_status_index']);
            $table->dropIndex(['confirmed_matches_last_matched_at_index']);
            $table->dropIndex(['confirmed_matches_created_by_user_id_index']);
            
            $table->dropColumn([
                'times_matched',
                'last_matched_at',
                'status',
                'notes',
                'created_by_user_id'
            ]);
        });
    }
};

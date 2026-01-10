<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('global_matches', function (Blueprint $table) {
            $table->enum('status', ['pending', 'confirmed', 'rejected'])->default('pending')->after('video_data');
            $table->foreignId('created_by_user_id')->nullable()->after('processed_by')->constrained('users')->onDelete('set null');
            $table->foreignId('verified_by_user_id')->nullable()->after('created_by_user_id')->constrained('users')->onDelete('set null');
            
            $table->index(['status']);
            $table->index(['created_by_user_id']);
            $table->index(['verified_by_user_id']);
        });
    }

    public function down(): void
    {
        Schema::table('global_matches', function (Blueprint $table) {
            $table->dropForeign(['created_by_user_id']);
            $table->dropForeign(['verified_by_user_id']);
            $table->dropColumn(['status', 'created_by_user_id', 'verified_by_user_id']);
        });
    }
};

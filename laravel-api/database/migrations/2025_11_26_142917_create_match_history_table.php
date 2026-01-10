<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('match_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('global_match_id')->constrained('global_matches')->onDelete('cascade');
            $table->string('action', 50);
            $table->string('previous_status', 50)->nullable();
            $table->string('new_status', 50)->nullable();
            $table->decimal('previous_score', 5, 2)->nullable();
            $table->decimal('new_score', 5, 2)->nullable();
            $table->json('changes')->nullable();
            $table->text('reason')->nullable();
            $table->foreignId('performed_by_user_id')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamp('performed_at');
            $table->timestamp('created_at')->nullable();

            $table->index('global_match_id');
            $table->index('action');
            $table->index('performed_at');
            $table->index('performed_by_user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('match_history');
    }
};

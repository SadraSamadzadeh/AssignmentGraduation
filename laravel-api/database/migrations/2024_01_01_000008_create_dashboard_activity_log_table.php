<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
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

    public function down(): void
    {
        Schema::dropIfExists('dashboard_activity_log');
    }
};

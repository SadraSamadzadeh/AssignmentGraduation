<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('auth_system')->default('local')->after('email'); // local, primeplay, video_dashboard
            $table->string('external_user_id')->nullable()->after('auth_system');
            $table->json('external_credentials')->nullable()->after('external_user_id');
            $table->timestamp('external_token_expires_at')->nullable()->after('external_credentials');
            
            $table->index(['auth_system']);
            $table->index(['external_user_id']);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'auth_system',
                'external_user_id',
                'external_credentials',
                'external_token_expires_at'
            ]);
        });
    }
};

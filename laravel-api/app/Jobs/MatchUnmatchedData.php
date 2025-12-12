<?php

namespace App\Jobs;

use App\Services\MatchCoordinator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class MatchUnmatchedData implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 300;
    public $tries = 3;

    /**
     * Batch matching process - runs on schedule (e.g., every 15 minutes)
     * Delegates to MatchCoordinator service
     */
    public function handle(): void
    {
        Log::info('Starting scheduled batch matching process');
        
        $coordinator = app(MatchCoordinator::class);
        
        try {
            $stats = $coordinator->processBatchMatching();
            
            Log::info('Scheduled batch matching completed successfully', $stats);
            
        } catch (\Exception $e) {
            Log::error('Batch matching process failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            throw $e;
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('MatchUnmatchedData job failed', [
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString()
        ]);
    }
}

<?php

namespace App\Console\Commands;

use App\Models\TrackingDashboard;
use App\Models\VideoDashboard;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class CleanupExpiredUnmatchedData extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'cleanup:expired-unmatched 
                            {--dry-run : Run without actually deleting data}
                            {--hours=24 : Age threshold in hours}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Remove unmatched tracking and video data that has expired from cache (older than 24h)';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $dryRun = $this->option('dry-run');
        $hoursThreshold = (int) $this->option('hours');
        $expiryTime = now()->subHours($hoursThreshold);
        
        $this->info("Cleaning up unmatched data older than {$hoursThreshold} hours...");
        $this->info("Cutoff time: {$expiryTime->toDateTimeString()}");
        
        if ($dryRun) {
            $this->warn("DRY RUN MODE - No data will be deleted");
        }
        
        // Clean up expired tracking data
        $expiredTracking = TrackingDashboard::where('status', 'unmatched')
            ->where('received_at', '<', $expiryTime)
            ->get();
            
        $trackingCount = 0;
        foreach ($expiredTracking as $record) {
            $cacheKey = "primeplay:match:{$record->tracking_id}";
            
            // Check if data still exists in cache
            if (!Cache::has($cacheKey)) {
                // Cache expired, so remove from database too
                $this->line("📋 Tracking ID: {$record->tracking_id} - Received: {$record->received_at} - Cache expired");
                
                if (!$dryRun) {
                    $record->delete();
                    $trackingCount++;
                }
            } else {
                $this->line("⏳ Tracking ID: {$record->tracking_id} - Still in cache, keeping...");
            }
        }
        
        // Clean up expired video data
        $expiredVideo = VideoDashboard::where('status', 'unmatched')
            ->where('received_at', '<', $expiryTime)
            ->get();
            
        $videoCount = 0;
        foreach ($expiredVideo as $record) {
            // Try to extract dataset_id from video_id or video_reference
            $datasetId = $this->extractDatasetId($record);
            $cacheKey = $datasetId ? "video:match:{$datasetId}" : null;
            
            // Check if data still exists in cache
            if (!$cacheKey || !Cache::has($cacheKey)) {
                // Cache expired or no cache key, so remove from database too
                $this->line("🎥 Video ID: {$record->video_id} - Received: {$record->received_at} - Cache expired");
                
                if (!$dryRun) {
                    $record->delete();
                    $videoCount++;
                }
            } else {
                $this->line("⏳ Video ID: {$record->video_id} - Still in cache, keeping...");
            }
        }
        
        // Summary
        $this->newLine();
        if ($dryRun) {
            $this->info("DRY RUN RESULTS:");
            $this->info("  Would delete {$expiredTracking->count()} tracking records (cache-expired: {$trackingCount})");
            $this->info("  Would delete {$expiredVideo->count()} video records (cache-expired: {$videoCount})");
        } else {
            $this->info("CLEANUP COMPLETE:");
            $this->info("  Deleted {$trackingCount} expired tracking records");
            $this->info("  Deleted {$videoCount} expired video records");
            
            Log::info('Expired unmatched data cleanup completed', [
                'tracking_deleted' => $trackingCount,
                'video_deleted' => $videoCount,
                'hours_threshold' => $hoursThreshold,
            ]);
        }
        
        return Command::SUCCESS;
    }
    
    /**
     * Extract dataset ID from video record
     */
    protected function extractDatasetId($videoRecord)
    {
        // Try to get from video_data JSON
        if (isset($videoRecord->video_data['datasetId'])) {
            return $videoRecord->video_data['datasetId'];
        }
        
        if (isset($videoRecord->video_data['matchId'])) {
            return $videoRecord->video_data['matchId'];
        }
        
        // Try to parse from video_id if it follows pattern "video_123"
        if (preg_match('/video_(\d+)/', $videoRecord->video_id, $matches)) {
            return $matches[1];
        }
        
        // Try to parse as number if video_id is numeric
        if (is_numeric($videoRecord->video_id)) {
            return $videoRecord->video_id;
        }
        
        return null;
    }
}

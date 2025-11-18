<?php

namespace App\Jobs;

use App\Models\TrackingDashboard;
use App\Models\VideoDashboard;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ProcessIncomingMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private array $data;
    private string $type; // 'tracking' or 'video'

    /**
     * Create a new job instance.
     */
    public function __construct(array $data, string $type)
    {
        $this->data = $data;
        $this->type = $type;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        try {
            if ($this->type === 'tracking') {
                $this->processTrackingData();
            } elseif ($this->type === 'video') {
                $this->processVideoData();
            } else {
                Log::warning('Unknown message type received', ['type' => $this->type]);
            }
        } catch (\Exception $e) {
            Log::error('Failed to process incoming message', [
                'type' => $this->type,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Process tracking data message
     * Store in database (tracking_dashboard) and cache
     */
    private function processTrackingData(): void
    {
        $trackingId = $this->data['id'];
        
        Log::info('Processing tracking data message', [
            'tracking_id' => $trackingId,
            'team_name' => $this->data['teamName'] ?? 'unknown'
        ]);

        // Store in database (tracking_dashboard table)
        $trackingRecord = TrackingDashboard::updateOrCreate(
            ['tracking_id' => $trackingId],
            [
                'tracking_data' => json_encode($this->data),
                'source_system' => 'tracking_solution',
                'match_attempts' => 0,
                'last_match_attempt_at' => null,
                'assigned_to_user_id' => null,
                'received_at' => now()
            ]
        );

        Log::info('Tracking data stored in database', [
            'id' => $trackingRecord->id,
            'tracking_id' => $trackingId
        ]);

        // Store in cache for quick access (expire after 24 hours)
        $cacheKey = "tracking_data_{$trackingId}";
        Cache::put($cacheKey, $this->data, now()->addHours(24));

        Log::info('Tracking data cached', [
            'cache_key' => $cacheKey,
            'ttl' => '24 hours'
        ]);

        // Also add to a list of unmatched tracking IDs
        $unmatchedList = Cache::get('unmatched_tracking_ids', []);
        if (!in_array($trackingId, $unmatchedList)) {
            $unmatchedList[] = $trackingId;
            Cache::put('unmatched_tracking_ids', $unmatchedList, now()->addHours(24));
        }

        Log::info('Processing tracking data completed', [
            'tracking_id' => $trackingId,
            'unmatched_count' => count($unmatchedList)
        ]);
    }

    /**
     * Process video data message
     * Store in database (video_dashboard) and cache
     */
    private function processVideoData(): void
    {
        $videoId = $this->data['id'];
        
        Log::info('Processing video data message', [
            'video_id' => $videoId,
            'home_team' => $this->data['home']['name'] ?? 'unknown'
        ]);

        // Extract video reference
        $videoReference = $this->data['match_group_id'] ?? $this->data['id'];

        // Store in database (video_dashboard table)
        $videoRecord = VideoDashboard::updateOrCreate(
            ['video_id' => $videoId],
            [
                'video_reference' => $videoReference,
                'video_data' => json_encode($this->data),
                'source_system' => 'video_solution',
                'match_attempts' => 0,
                'last_match_attempt_at' => null,
                'received_at' => now()
            ]
        );

        Log::info('Video data stored in database', [
            'id' => $videoRecord->id,
            'video_id' => $videoId
        ]);

        // Store in cache for quick access (expire after 24 hours)
        $cacheKey = "video_data_{$videoId}";
        Cache::put($cacheKey, $this->data, now()->addHours(24));

        Log::info('Video data cached', [
            'cache_key' => $cacheKey,
            'ttl' => '24 hours'
        ]);

        // Also add to a list of unmatched video IDs
        $unmatchedList = Cache::get('unmatched_video_ids', []);
        if (!in_array($videoId, $unmatchedList)) {
            $unmatchedList[] = $videoId;
            Cache::put('unmatched_video_ids', $unmatchedList, now()->addHours(24));
        }

        Log::info('Processing video data completed', [
            'video_id' => $videoId,
            'unmatched_count' => count($unmatchedList)
        ]);
    }
}

<?php

namespace App\Jobs;

use App\Models\TrackingDashboard;
use App\Models\VideoDashboard;
use App\Models\GlobalMatches;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class MatchUnmatchedData implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 300;
    public $tries = 3;

    public function handle(): void
    {
        Log::info('Starting automated matching process for unmatched data');
        
        $matchCount = 0;
        
        // Get all unmatched tracking data
        $unmatchedTracking = TrackingDashboard::where('status', 'unmatched')
            ->orWhereNull('status')
            ->get();
            
        Log::info('Found unmatched tracking records', ['count' => $unmatchedTracking->count()]);
        
        foreach ($unmatchedTracking as $tracking) {
            $trackingId = $tracking->tracking_id;
            
            // Try to find matching video data by looking for the tracking ID
            // Check multiple possible ID fields in video data
            $matchingVideos = VideoDashboard::where(function($query) use ($trackingId) {
                $query->where('video_id', $trackingId)
                    ->orWhere('video_reference', $trackingId)
                    ->orWhereRaw("video_data->>'match_id' = ?", [$trackingId])
                    ->orWhereRaw("video_data->'match'->>'id' = ?", [$trackingId])
                    ->orWhereRaw("video_data->'match'->>'genius_match_id' = ?", [$trackingId]);
            })->get();
            
            foreach ($matchingVideos as $video) {
                try {
                    DB::transaction(function () use ($tracking, $video, &$matchCount) {
                        // Extract video ID
                        $videoData = $video->video_data;
                        $videoId = $video->video_id;
                        
                        if (is_string($videoData)) {
                            $videoData = json_decode($videoData, true);
                        }
                        
                        // Try to extract better video ID from nested data
                        $saRecordingId = $videoData['match']['sa_recording_id'] ?? null;
                        if ($saRecordingId) {
                            $videoId = $saRecordingId;
                        }
                        
                        // Create global match
                        GlobalMatches::create([
                            'global_match_id' => "match_{$tracking->tracking_id}_{$videoId}_" . now()->timestamp,
                            'tracking_id' => $tracking->tracking_id,
                            'video_id' => $videoId,
                            'match_score' => 100.00,
                            'confidence_level' => 'high',
                            'match_details' => [
                                'match_type' => 'scheduled_matching',
                                'matched_by' => 'system',
                                'match_criteria' => 'dataset_id',
                                'matched_at_job' => 'MatchUnmatchedData',
                            ],
                            'tracking_data' => $tracking->tracking_data,
                            'video_data' => $videoData,
                            'status' => 'confirmed',
                            'processed_by' => 'scheduled_job',
                            'matched_at' => now(),
                        ]);
                        
                        // Update status to matched
                        $tracking->update(['status' => 'matched']);
                        $video->delete();
                        
                        // Clean up cache
                        $videoCacheKey = "video:match:{$tracking->tracking_id}";
                        $trackingCacheKey = "primeplay:match:{$tracking->tracking_id}";
                        Cache::forget($videoCacheKey);
                        Cache::forget($trackingCacheKey);
                        
                        $matchCount++;
                        
                        Log::info('Automated match created', [
                            'tracking_id' => $tracking->tracking_id,
                            'video_id' => $videoId,
                        ]);
                    });
                } catch (\Exception $e) {
                    Log::error('Failed to create automated match', [
                        'tracking_id' => $tracking->tracking_id,
                        'video_id' => $video->video_id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }
        
        // Also check for videos that might match existing tracking
        $unmatchedVideos = VideoDashboard::whereDoesntHave('globalMatches')
            ->get();
            
        Log::info('Found unmatched video records', ['count' => $unmatchedVideos->count()]);
        
        foreach ($unmatchedVideos as $video) {
            $videoData = $video->video_data;
            
            if (is_string($videoData)) {
                $videoData = json_decode($videoData, true);
            }
            
            // Extract possible match IDs from video data
            $possibleMatchIds = [
                $videoData['match_id'] ?? null,
                $videoData['match']['id'] ?? null,
                $videoData['match']['genius_match_id'] ?? null,
                $video->video_reference ?? null,
            ];
            
            $possibleMatchIds = array_filter($possibleMatchIds);
            
            if (empty($possibleMatchIds)) {
                continue;
            }
            
            // Try to find matching tracking data
            $matchingTracking = TrackingDashboard::whereIn('tracking_id', $possibleMatchIds)
                ->where(function($query) {
                    $query->where('status', 'unmatched')
                        ->orWhereNull('status');
                })
                ->first();
                
            if ($matchingTracking) {
                try {
                    DB::transaction(function () use ($matchingTracking, $video, &$matchCount) {
                        $videoId = $video->video_id;
                        
                        // Try to extract better video ID
                        $videoData = is_string($video->video_data) 
                            ? json_decode($video->video_data, true) 
                            : $video->video_data;
                            
                        $saRecordingId = $videoData['match']['sa_recording_id'] ?? null;
                        if ($saRecordingId) {
                            $videoId = $saRecordingId;
                        }
                        
                        GlobalMatches::create([
                            'global_match_id' => "match_{$matchingTracking->tracking_id}_{$videoId}_" . now()->timestamp,
                            'tracking_id' => $matchingTracking->tracking_id,
                            'video_id' => $videoId,
                            'match_score' => 100.00,
                            'confidence_level' => 'high',
                            'match_details' => [
                                'match_type' => 'scheduled_matching',
                                'matched_by' => 'system',
                                'match_criteria' => 'dataset_id',
                                'matched_at_job' => 'MatchUnmatchedData',
                            ],
                            'tracking_data' => $matchingTracking->tracking_data,
                            'video_data' => $videoData,
                            'status' => 'confirmed',
                            'processed_by' => 'scheduled_job',
                            'matched_at' => now(),
                        ]);
                        
                        $matchingTracking->update(['status' => 'matched']);
                        $video->delete();
                        
                        // Clean up cache
                        $videoCacheKey = "video:match:{$matchingTracking->tracking_id}";
                        $trackingCacheKey = "primeplay:match:{$matchingTracking->tracking_id}";
                        Cache::forget($videoCacheKey);
                        Cache::forget($trackingCacheKey);
                        
                        $matchCount++;
                        
                        Log::info('Automated match created (video-first)', [
                            'tracking_id' => $matchingTracking->tracking_id,
                            'video_id' => $videoId,
                        ]);
                    });
                } catch (\Exception $e) {
                    Log::error('Failed to create automated match (video-first)', [
                        'video_id' => $video->video_id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }
        
        Log::info('Automated matching process completed', [
            'matches_created' => $matchCount,
            'total_tracking_checked' => $unmatchedTracking->count(),
            'total_videos_checked' => $unmatchedVideos->count(),
        ]);
    }
}

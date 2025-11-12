<?php

/**
 * Simple Matching Function - Laravel API
 * 
 * This function replicates the exact TypeScript matching logic you had before.
 * Give it tracking data and video data, it returns a score and stores successful matches.
 */

require_once __DIR__ . '/vendor/autoload.php';

use App\Services\MatchingService;
use App\Services\MatchStorageService;

/**
 * Main matching function - exactly like your TypeScript version
 * 
 * @param array $trackingData - Tracking data from your dashboard
 * @param array $videoData - Video data from your dashboard
 * @return array - Match result with score, confidence, and storage status
 */
function performMatching(array $trackingData, array $videoData): array
{
    // Initialize services (same logic as TypeScript)
    $matchingService = new MatchingService();
    $storageService = new MatchStorageService();
    
    // Perform matching algorithm (same as TypeScript implementation)
    $matchResult = $matchingService->compareTrackingAndVideo($trackingData, $videoData);
    
    $result = [
        'tracking_id' => $trackingData['id'],
        'video_id' => $videoData['id'],
        'match_score' => $matchResult['score'],
        'confidence_level' => $matchResult['confidence'],
        'reasons' => $matchResult['reasons'],
        'stored' => false,
        'global_match_id' => null
    ];
    
    // Store in database if score >= 60 (same threshold as TypeScript)
    if ($matchResult['score'] >= 60) {
        try {
            $storedMatch = $storageService->storeMatch($trackingData, $videoData, $matchResult);
            $result['stored'] = true;
            $result['global_match_id'] = $storedMatch['global_id'];
            $result['database_record'] = $storedMatch;
        } catch (Exception $e) {
            $result['error'] = 'Failed to store in database: ' . $e->getMessage();
        }
    }
    
    return $result;
}

/**
 * Test the matching function with your sample data
 */
function testMatching(): void
{
    echo "🚀 Testing Laravel Matching Function\n";
    echo "====================================\n\n";
    
    // Your sample tracking data (same as before)
    $trackingData = [
        'id' => 184,
        'name' => 'Training Capelle 1',
        'teamName' => 'Capelle 1',
        'startTime' => '2025-10-16T17:30:13.300Z',
        'endTime' => '2025-10-16T23:59:59.800Z',
        'avgTotalTimeActive' => '01:06:08.5472727',
        'activities' => [
            [
                'type' => 'running',
                'duration' => '00:30:00',
                'intensity' => 'high'
            ]
        ]
    ];
    
    // Your sample video data (same as before)
    $videoData = [
        'id' => 'e5652e87-3409-4907-90d8-e95343014452',
        'club' => [
            'id' => 'club-123',
            'name' => 'VV Capelle'
        ],
        'home' => ['name' => 'VV Capelle'],
        'away' => ['name' => 'VV Capelle'],
        'starting_at' => ['date' => '2025-10-16T20:25:00+02:00'],
        'stopping_at' => ['date' => '2025-10-16T20:59:00+02:00'],
        'timezone' => 'Europe/Amsterdam'
    ];
    
    // Perform the matching
    $result = performMatching($trackingData, $videoData);
    
    // Display results (same format as TypeScript)
    echo "📊 MATCH RESULTS:\n";
    echo "Tracking ID: {$result['tracking_id']}\n";
    echo "Video ID: {$result['video_id']}\n";
    echo "🎯 Match Score: {$result['match_score']}\n";
    echo "🎖️  Confidence: {$result['confidence_level']}\n";
    echo "💾 Stored in DB: " . ($result['stored'] ? 'Yes' : 'No') . "\n";
    
    if ($result['stored']) {
        echo "🆔 Global Match ID: {$result['global_match_id']}\n";
    }
    
    echo "\n📋 Scoring Breakdown:\n";
    foreach ($result['reasons'] as $reason) {
        echo "  • {$reason}\n";
    }
    
    echo "\n" . ($result['match_score'] >= 80 ? "✅" : ($result['match_score'] >= 60 ? "⚠️" : "❌"));
    echo " Match " . ($result['stored'] ? "STORED SUCCESSFULLY!" : "not stored (score too low)") . "\n";
}

/**
 * Simple API endpoint simulation
 */
function matchingAPI(): void
{
    // Get JSON input (for API usage)
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);
    
    if (!$data || !isset($data['trackingData']) || !isset($data['videoData'])) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Please provide trackingData and videoData'
        ]);
        return;
    }
    
    $result = performMatching($data['trackingData'], $data['videoData']);
    
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'data' => $result
    ], JSON_PRETTY_PRINT);
}

// Run test if called from command line
if (php_sapi_name() === 'cli') {
    testMatching();
}
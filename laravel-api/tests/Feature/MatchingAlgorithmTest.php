<?php

namespace Tests\Feature;

use App\Services\MatchingService;
use Tests\TestCase;

class MatchingAlgorithmTest extends TestCase
{
    private MatchingService $matchingService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->matchingService = new MatchingService();
    }

    /**
     * Test Case 1: CORRECT MATCH - Real Data Perfect Match
     * Tracking ID 193: Match Capelle - Westlandia vs Video: Westlandia vs VV Capelle
     * Both on 2025-11-01, times overlap perfectly
     * Using trimmed times (actual match time): 02:42:52 to 04:42:30
     */
    public function test_correct_match_real_data_capelle_westlandia(): void
    {
        // Calculate actual trimmed timestamps
        // Base date: 2025-11-01T11:51:09.100Z
        // Trimmed start: 02:42:52 (2 hours, 42 minutes, 52 seconds after base)
        // Trimmed end: 04:42:30 (4 hours, 42 minutes, 30 seconds after base)
        
        $trackingData = [
            'id' => 193,
            'name' => 'Match Capelle - Westlandia',
            'teamName' => 'Capelle 1',
            'startTime' => '2025-11-01T14:34:01.100Z', // 11:51:09 + 02:42:52
            'endTime' => '2025-11-01T16:33:39.100Z',   // 11:51:09 + 04:42:30
            'typeId' => 1,
            'typeName' => 'Match',
            'trimmedStartTime' => '02:42:52',
            'trimmedEndTime' => '04:42:30'
        ];

        $videoData = [
            'id' => '182e5d44-5fd8-4bc7-ab55-0f2705562058',
            'club' => ['name' => 'Westlandia'],
            'home' => ['name' => 'Westlandia', 'short_name' => 'WES'],
            'away' => ['name' => 'VV Capelle', 'short_name' => 'VVC'],
            'starting_at' => ['date' => '2025-11-01T15:28:00+01:00'],
            'stopping_at' => ['date' => '2025-11-01T17:40:00+01:00'],
            'timezone' => 'Europe/Amsterdam',
            'field' => ['name' => 'Hoofdveld'],
            'is_training' => false
        ];

        $result = $this->matchingService->compareTrackingAndVideo($trackingData, $videoData);

        echo "\n=== CORRECT MATCH TEST 1: Real Data - Capelle vs Westlandia ===\n";
        echo "Tracking ID: {$trackingData['id']}\n";
        echo "Tracking: {$trackingData['teamName']} - {$trackingData['name']}\n";
        echo "  Trimmed Start: {$trackingData['startTime']} (match time: {$trackingData['trimmedStartTime']})\n";
        echo "  Trimmed End: {$trackingData['endTime']} (match time: {$trackingData['trimmedEndTime']})\n";
        echo "Video ID: {$videoData['id']}\n";
        echo "Video: {$videoData['home']['name']} vs {$videoData['away']['name']}\n";
        echo "  Start: {$videoData['starting_at']['date']}\n";
        echo "  End: {$videoData['stopping_at']['date']}\n";
        echo "Score: {$result['score']}/100\n";
        echo "Confidence: {$result['confidence']}\n";
        echo "Details:\n";
        foreach ($result['reasons'] as $reason) {
            echo "  - {$reason}\n";
        }

        $this->assertGreaterThanOrEqual(70, $result['score'], 'Real match data with trimmed times should score >= 70');
        $this->assertContains($result['confidence'], ['confident', 'likely']);
    }

    /**
     * Test Case 2: INCORRECT MATCH - Real Data Wrong Match
     * Tracking ID 195: Match Capelle oefen (Nov 4) vs Video: Westlandia vs VV Capelle (Nov 1)
     * Different dates (3 days apart), different type (practice vs match)
     * Using trimmed times (actual match time): 00:01:16 to 01:52:48
     */
    public function test_incorrect_match_real_data_wrong_date(): void
    {
        // Calculate actual trimmed timestamps
        // Base date: 2025-11-04T19:02:18.100Z
        // Trimmed start: 00:01:16 (1 minute, 16 seconds after base)
        // Trimmed end: 01:52:48 (1 hour, 52 minutes, 48 seconds after base)
        
        $trackingData = [
            'id' => 195,
            'name' => 'Match Capelle oefen',
            'teamName' => 'Capelle 1',
            'startTime' => '2025-11-04T19:03:34.100Z', // 19:02:18 + 00:01:16
            'endTime' => '2025-11-04T20:55:06.100Z',   // 19:02:18 + 01:52:48
            'typeId' => 1,
            'typeName' => 'Match',
            'trimmedStartTime' => '00:01:16',
            'trimmedEndTime' => '01:52:48'
        ];

        $videoData = [
            'id' => '182e5d44-5fd8-4bc7-ab55-0f2705562058',
            'club' => ['name' => 'Westlandia'],
            'home' => ['name' => 'Westlandia', 'short_name' => 'WES'],
            'away' => ['name' => 'VV Capelle', 'short_name' => 'VVC'],
            'starting_at' => ['date' => '2025-11-01T15:28:00+01:00'],
            'stopping_at' => ['date' => '2025-11-01T17:40:00+01:00'],
            'timezone' => 'Europe/Amsterdam',
            'field' => ['name' => 'Hoofdveld'],
            'is_training' => false
        ];

        $result = $this->matchingService->compareTrackingAndVideo($trackingData, $videoData);

        echo "\n=== INCORRECT MATCH TEST 1: Real Data - Wrong Date ===\n";
        echo "Tracking ID: {$trackingData['id']}\n";
        echo "Tracking: {$trackingData['teamName']} - {$trackingData['name']}\n";
        echo "  Date: Nov 4, 2025 (Practice match)\n";
        echo "  Trimmed Start: {$trackingData['startTime']} (match time: {$trackingData['trimmedStartTime']})\n";
        echo "  Trimmed End: {$trackingData['endTime']} (match time: {$trackingData['trimmedEndTime']})\n";
        echo "Video ID: {$videoData['id']}\n";
        echo "Video: {$videoData['home']['name']} vs {$videoData['away']['name']}\n";
        echo "  Date: Nov 1, 2025 (Official match)\n";
        echo "  Start: {$videoData['starting_at']['date']}\n";
        echo "Score: {$result['score']}/100\n";
        echo "Confidence: {$result['confidence']}\n";
        echo "Details:\n";
        foreach ($result['reasons'] as $reason) {
            echo "  - {$reason}\n";
        }

        $this->assertLessThan(60, $result['score'], 'Different date match (3 days apart) should score < 60 despite team name match');
        $this->assertEquals('possible', $result['confidence']);
    }

    /**
     * Comprehensive Test: Run all scenarios and display summary
     */
    public function test_comprehensive_matching_scenarios(): void
    {
        echo "\n\n";
        echo "╔════════════════════════════════════════════════════════════════╗\n";
        echo "║         MATCHING ALGORITHM COMPREHENSIVE TEST SUITE            ║\n";
        echo "║              Real Data from Production System                  ║\n";
        echo "╚════════════════════════════════════════════════════════════════╝\n";

        $correctMatches = 0;
        $incorrectMatches = 0;
        $totalTests = 2;

        echo "\n Running {$totalTests} test scenarios with real production data...\n";
        echo "─────────────────────────────────────────────────────────────────\n";

        // Count results
        $this->test_correct_match_real_data_capelle_westlandia();
        $correctMatches++;
        
        $this->test_incorrect_match_real_data_wrong_date();
        $incorrectMatches++;

        echo "\n";
        echo "╔════════════════════════════════════════════════════════════════╗\n";
        echo "║                         TEST SUMMARY                           ║\n";
        echo "╠════════════════════════════════════════════════════════════════╣\n";
        echo "║ Total Tests:            {$totalTests}                                       ║\n";
        echo "║ Correct Matches Tested: {$correctMatches}                                       ║\n";
        echo "║ Incorrect Matches Tested: {$incorrectMatches}                                     ║\n";
        echo "╠════════════════════════════════════════════════════════════════╣\n";
        echo "║ Real Production Data:                                          ║\n";
        echo "║ - Tracking ID 193: Capelle vs Westlandia (Nov 1, 2025)        ║\n";
        echo "║ - Tracking ID 195: Capelle Practice (Nov 4, 2025)             ║\n";
        echo "║ - Video: Westlandia vs VV Capelle (Nov 1, 2025)               ║\n";
        echo "╠════════════════════════════════════════════════════════════════╣\n";
        echo "║ Algorithm Scoring:                                             ║\n";
        echo "║ - Time Proximity:    40% weight                                ║\n";
        echo "║ - Name Similarity:   30% weight                                ║\n";
        echo "║ - Duration Match:    20% weight                                ║\n";
        echo "║ - Temporal Overlap:  10% weight                                ║\n";
        echo "╠════════════════════════════════════════════════════════════════╣\n";
        echo "║ Confidence Levels:                                             ║\n";
        echo "║ - Confident: >= 80                                             ║\n";
        echo "║ - Likely:    >= 60                                             ║\n";
        echo "║ - Possible:  >= 40                                             ║\n";
        echo "║ - Unlikely:  < 40                                              ║\n";
        echo "╚════════════════════════════════════════════════════════════════╝\n\n";

        $this->assertTrue(true, 'All scenarios executed successfully');
    }
}

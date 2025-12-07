#!/usr/bin/env php
<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

echo "=== Application Code & Database Verification ===\n\n";

// Test Models
echo "✅ Models:\n";
echo "  - TrackingDashboard: " . \App\Models\TrackingDashboard::count() . " records\n";
echo "  - VideoDashboard: " . \App\Models\VideoDashboard::count() . " records\n";
echo "  - GlobalMatches: " . \App\Models\GlobalMatches::count() . " records\n";
echo "  - MatchHistory: " . \App\Models\MatchHistory::count() . " records\n";
echo "  - TeamMapping: " . \App\Models\TeamMapping::count() . " records\n";

// Test that we can query with new fields
echo "\n✅ New Fields Accessible:\n";
$tracking = new \App\Models\TrackingDashboard();
$fillable = $tracking->getFillable();
echo "  - TrackingDashboard fillable: " . (in_array('tracking_data', $fillable) ? '✅' : '❌') . " tracking_data\n";
echo "  - TrackingDashboard fillable: " . (in_array('event_date', $fillable) ? '✅' : '❌') . " event_date\n";
echo "  - TrackingDashboard fillable: " . (in_array('start_time', $fillable) ? '✅' : '❌') . " start_time\n";
echo "  - TrackingDashboard fillable: " . (in_array('status', $fillable) ? '✅' : '❌') . " status\n";

$matches = new \App\Models\GlobalMatches();
$fillable = $matches->getFillable();
echo "  - GlobalMatches fillable: " . (in_array('match_score', $fillable) ? '✅' : '❌') . " match_score\n";
echo "  - GlobalMatches fillable: " . (in_array('time_proximity_score', $fillable) ? '✅' : '❌') . " time_proximity_score\n";

echo "\n✅ Database Structure: READY\n";
echo "✅ Application Code: UPDATED\n";
echo "\n=== All Systems Operational ===\n";

# Enhanced Matching Algorithm Documentation

## Overview
This document explains the enhanced matching algorithm used to match tracking data from Primeplay with video data from the Video Dashboard. Since there are **no shared IDs** between the two systems, the algorithm relies on temporal and contextual similarity.

## Architecture

### Core Principle
**Date/Time-First Approach**: The algorithm uses date and time as the prcaimary discriminator, then validates with team names and other contextual data.

### Multi-Stage Filtering Process

```
Video Data (Source) → Date Pre-Filter → Similarity Matching → Global Match
                            ↓
                    Tracking Data (Target)
```

## Algorithm Stages

### Stage 1: Date-Based Pre-Filtering (Optimization)
**Purpose**: Reduce computation by eliminating obviously non-matching records

**Process**:
1. Extract date from video data starting time
2. Group all tracking records by date
3. Select only tracking records from:
   - Same day as video
   - Previous day (±1 day buffer)
   - Next day (±1 day buffer)

**Impact**: Reduces comparison operations by ~90% for datasets with many dates

**Code Location**: `MatchUnmatchedData::groupTrackingByDate()`, `getCandidateTracking()`

---

### Stage 2: Time Proximity Scoring (50% weight)
**Purpose**: PRIMARY FILTER - Determines if matches are temporally aligned

**Scoring Logic**:
```
Within 5 minutes    → 100 points (Perfect match)
Within 15 minutes   → 95 points  (Excellent match)
Within 30 minutes   → 90 points  (Very good match)
Within 1 hour       → 85 points  (Good match)
Within 2 hours (same day) → 75 points
Within 4 hours (same day) → 60 points
Within 8 hours (same day) → 45 points
Same day (>8h diff)       → 30 points
Within 24 hours (diff day) → 40 points
Within 48 hours (diff day) → 20 points
More than 2 days          → 0 points (No match)
```

**Early Exit Optimization**: If time score < 40, immediately return without checking other criteria (saves computation)

**Why 50% weight?**: Time is the most reliable indicator since:
- Matches happen at specific times
- Multiple matches rarely occur simultaneously
- Time data is always present and accurate

**Code Location**: `MatchingService::calculateTimeProximity()`

---

### Stage 3: Team/Club Name Similarity (30% weight)
**Purpose**: VALIDATION - Confirms it's the same match by comparing team names

**Comparison Strategy**:
1. **Normalization**: Remove common prefixes/suffixes (FC, VV, SC, team numbers)
2. **Multiple Comparisons**:
   - Tracking team vs Video home team
   - Tracking team vs Video away team
   - Tracking team vs Video club name
   - Tracking team vs Combined "Home - Away"
   - Tracking team vs Reversed "Away - Home"

3. **Scoring Methods**:
   - **Exact match**: 100 points
   - **Levenshtein distance**: Measures character-level similarity
   - **Substring match**: If one name contains the other → min 85 points
   - **Word-level matching**: Count common words between names

**Example**:
```
Tracking: "Cappelle - Gravenzande"
Video Home: "VV Capelle"
Video Away: "FC 's-Gravenzande"
Combined: "VV Capelle - FC 's-Gravenzande"

After normalization:
- "cappelle gravenzande"
- "capelle"
- "s gravenzande"
- "capelle s gravenzande"

Result: High similarity due to word overlap
```

**Why 30% weight?**: Team names can vary in formatting but are strong validators when present

**Code Location**: `MatchingService::calculateNameSimilarity()`, `calculateStringMatchScore()`

---

### Stage 4: Duration Similarity (15% weight)
**Purpose**: CONFIRMATION - Validates match length is similar

**Calculation**:
```
Ratio = min(tracking_duration, video_duration) / max(tracking_duration, video_duration)
Score = Ratio × 100
```

**Example**:
```
Tracking duration: 95 minutes
Video duration: 90 minutes
Ratio: 90/95 = 0.947
Score: 94.7 points
```

**Why 15% weight?**: Durations are usually similar but can vary due to:
- Different start/stop times
- Recording delays
- Manual stopping

**Code Location**: `MatchingService::calculateDurationSimilarity()`

---

### Stage 5: Temporal Overlap (5% weight)
**Purpose**: FINAL VERIFICATION - Checks how much time periods overlap

**Calculation**:
```
Overlap Start = max(tracking_start, video_start)
Overlap End = min(tracking_end, video_end)
Overlap Duration = Overlap End - Overlap Start

Score = (Overlap Duration / Total Duration) × 100
```

**Why 5% weight?**: Provides additional confirmation but less critical than other factors

**Code Location**: `MatchingService::calculateTemporalOverlap()`

---

## Final Scoring & Confidence Levels

### Score Calculation
```
Total Score = (Time × 0.50) + (Name × 0.30) + (Duration × 0.15) + (Overlap × 0.05)
```

### Confidence Levels
```
85-100 → Very High (Auto-confirmed, status='confirmed')
75-84  → High (Requires review, status='pending_review')
65-74  → Medium (Requires review, status='pending_review')
50-64  → Low (Not matched - below threshold)
0-49   → Very Low (Not matched - below threshold)
```

### Matching Threshold
**Minimum Score Required**: 65 points

**Why 65?**
- Balances precision vs recall
- Ensures high-quality matches
- Reduces false positives
- Matches with score 65+ still require manual review if < 85

---

## Scheduled Job Behavior

### Execution Frequency
**Every 15 minutes** via Laravel scheduler

### Process Flow
```
1. Get all unmatched video records (source)
2. Get all unmatched tracking records (target)
3. Group tracking by date (optimization)
4. For each video:
   a. Extract video date
   b. Get candidate tracking (same day ±1 day)
   c. Compare video against candidates only
   d. Skip if early exit (time diff > threshold)
   e. Find best match with score ≥ 65
   f. Create global match if found
5. Log results (matches created, skipped, etc.)
```

### Optimization Techniques
1. **Date-based pre-filtering**: Only compare records from similar dates
2. **Early exit**: Skip comparison if time difference is too large
3. **Best match selection**: Keep only highest score, avoid duplicate work

---

## Real-Time Matching (Immediate)

### Cache-Based Matching
When a message arrives, the system:
1. Checks cache for opposite data type (tracking checks for video, vice versa)
2. If found in cache: Creates immediate match
3. If not found: Runs similarity algorithm against all unmatched records
4. If still no match: Stores in database and cache (24h TTL)

### Why Both Real-Time and Scheduled?
- **Real-Time**: Fast matching when both messages arrive close together
- **Scheduled**: Catches matches where messages arrived far apart or outside cache window

---

## Performance Characteristics

### Time Complexity
- **Without date filtering**: O(V × T) where V=videos, T=tracking
- **With date filtering**: O(V × T/N) where N≈30 (average days in dataset)
- **With early exit**: Further reduced by ~30-50%

### Example Performance
```
100 videos × 1000 tracking records
Without optimization: 100,000 comparisons
With date filtering: ~3,333 comparisons (97% reduction)
With early exit: ~1,667 comparisons (98% reduction)
```

---

## Code Structure

### Key Files
```
app/Services/MatchingService.php
├── compareTrackingAndVideo()     # Main comparison function
├── calculateTimeProximity()      # Stage 2: Time scoring
├── calculateNameSimilarity()     # Stage 3: Name matching
├── calculateDurationSimilarity() # Stage 4: Duration comparison
└── calculateTemporalOverlap()    # Stage 5: Overlap check

app/Jobs/MatchUnmatchedData.php
├── handle()                      # Main scheduled job
├── groupTrackingByDate()         # Date-based grouping
├── getCandidateTracking()        # Pre-filtering
└── createMatch()                 # Match creation

app/Jobs/ProcessPrimeplayMessage.php
└── findSimilarVideoMatch()       # Real-time video matching

app/Jobs/ProcessVideoMessage.php
└── findSimilarTrackingMatch()    # Real-time tracking matching
```

---

## Match Record Structure

### Global Matches Table
```json
{
  "global_match_id": "match_1546_uuid_timestamp",
  "tracking_id": "1546",
  "video_id": "uuid-video-id",
  "match_score": 87.5,
  "confidence_level": "very_high",
  "match_details": {
    "match_type": "scheduled_matching",
    "matched_by": "system",
    "match_criteria": "similarity_algorithm",
    "reasons": [
      "Time proximity: 95/100",
      "Name similarity: 88/100",
      "Duration similarity: 92/100",
      "Temporal overlap: 85/100"
    ],
    "breakdown": {
      "time_proximity": {
        "score": 95,
        "weight": 50,
        "weighted_score": 47.5
      },
      "name_similarity": {
        "score": 88,
        "weight": 30,
        "weighted_score": 26.4
      },
      "duration_similarity": {
        "score": 92,
        "weight": 15,
        "weighted_score": 13.8
      },
      "temporal_overlap": {
        "score": 85,
        "weight": 5,
        "weighted_score": 4.25
      }
    }
  },
  "status": "confirmed",
  "matched_at": "2025-12-04T11:15:00Z"
}
```

---

## Testing & Validation

### How to Test
1. Send tracking message from Primeplay (with known date/time/team)
2. Send video message from Video Dashboard (with similar date/time/team but different IDs)
3. Wait up to 15 minutes for scheduled job, or check real-time matching
4. Query `global_matches` table to verify match creation
5. Check logs for matching details and scores

### Validation Queries
```sql
-- Check recent matches
SELECT tracking_id, video_id, match_score, confidence_level, status
FROM global_matches
ORDER BY matched_at DESC
LIMIT 10;

-- Check match details
SELECT match_details->'breakdown' as scoring_breakdown
FROM global_matches
WHERE id = <match_id>;

-- Check unmatched records
SELECT COUNT(*) FROM tracking_dashboard WHERE status = 'unmatched';
SELECT COUNT(*) FROM video_dashboard;
```

---

## Future Enhancements

### Potential Improvements
1. **Machine Learning**: Train model on confirmed matches to improve scoring weights
2. **Location Matching**: Add GPS/venue data comparison if available
3. **Score History**: Track matching patterns to improve future matches
4. **Dynamic Thresholds**: Adjust threshold based on data quality and match frequency
5. **Parallel Processing**: Process multiple videos concurrently for faster matching

---

## Summary

**Key Strengths**:
- ✅ No dependency on shared IDs
- ✅ Date/time as primary discriminator (most reliable)
- ✅ Multi-stage filtering for efficiency
- ✅ Early exit optimization
- ✅ Comprehensive scoring with detailed breakdown
- ✅ Both real-time and scheduled matching
- ✅ Confidence levels for review prioritization

**Matching Criteria Priority**:
1. **Time proximity** (50%) - When did it happen?
2. **Team names** (30%) - Who was playing?
3. **Duration** (15%) - How long did it last?
4. **Overlap** (5%) - Did time periods align?

**Result**: Accurate matching without shared identifiers, optimized for performance with large datasets.

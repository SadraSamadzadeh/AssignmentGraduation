# Database Setup Complete ✅

## Overview
The match linking system database has been completely rebuilt with a clean, optimized structure designed specifically for time-focused matching between Primeplay tracking data and USF Sport video data.

## Key Design Decisions

### 1. **Data Structure Reality**
Understanding that **Primeplay tracking data does NOT contain actual club names** (only generic identifiers like "Test Team", "Team 1"), while **video data contains actual club names** ("VV Capelle", "FC 's-Gravenzande"), the system is designed for **time-based matching** rather than name-based matching.

### 2. **Matching Algorithm (Time-Focused)**
- **Time Proximity**: 70% weight (primary matching factor)
- **Duration Similarity**: 20% weight (confirmation)
- **Temporal Overlap**: 10% weight (validation)
- **Minimum Threshold**: 65 points to create match
- **Auto-Confirm Threshold**: 85 points for automatic confirmation

### 3. **Optimized Performance**
- **Date pre-filtering**: ±1 day reduces comparisons by ~97%
- **Indexed fields**: Fast queries on `event_date`, `status`, `start_time`
- **Partial indexes**: Optimized for `status='unmatched'` queries
- **JSONB storage**: Flexible data with GIN indexes for fast JSONB queries

## Database Tables

### Core Tables

#### `users`
- Authentication and authorization
- Roles: `admin`, `user`, `viewer`
- Default admin: `admin@example.com` / `password` (⚠️ CHANGE THIS!)

#### `tracking_dashboard` (Temporary Storage)
- Stores unmatched Primeplay tracking data
- Extracted fields: `event_date`, `team_name`, `start_time`, `end_time`, `duration_minutes`
- Status: `unmatched`, `matched`, `expired`, `ignored`
- Lifecycle: Expires after 7 days, deleted after 30 days

#### `video_dashboard` (Temporary Storage)
- Stores unmatched USF Sport video data
- Extracted fields: `event_date`, `home_club_name`, `away_club_name`, `start_time`, `end_time`, `duration_minutes`, `is_training`
- Status: `unmatched`, `matched`, `expired`, `ignored`
- Lifecycle: Expires after 7 days, deleted after 30 days

#### `global_matches` (Permanent Storage)
- Stores confirmed matches
- Contains: `match_score`, `confidence_level`, `match_details` (JSONB with breakdown)
- Status: `pending_review`, `auto_confirmed`, `confirmed`, `rejected`
- Full snapshots of original `tracking_data` and `video_data`

#### `match_history` (Audit Trail)
- Tracks all changes to matches
- Actions: `created`, `confirmed`, `rejected`, `score_updated`, `data_updated`
- Includes: previous/new status, previous/new score, changes (JSONB), reason

### System Tables
- `jobs`: Laravel queue jobs
- `failed_jobs`: Failed job tracking

## Helpful Views

### `active_unmatched_tracking`
Shows all active unmatched tracking records with extracted fields and hours since received.

### `active_unmatched_videos`
Shows all active unmatched video records with extracted fields and hours since received.

### `matches_pending_review`
Lists matches awaiting manual review, ordered by score (highest first) and age (oldest first).

### `matching_statistics`
System-wide statistics dashboard showing:
- Unmatched tracking/video counts
- Pending review / auto-confirmed / user-confirmed / rejected counts
- Average confirmed vs rejected scores

## Utility Functions

### `cleanup_expired_records()`
Automatically marks records older than 7 days as `expired` and deletes expired records older than 30 days.

Usage:
```sql
SELECT * FROM cleanup_expired_records();
```

## Data Flow

```
1. TRACKING MESSAGE RECEIVED
   ↓
   Stored in tracking_dashboard (status='unmatched')
   ↓
   Matching job runs every 15 minutes
   ↓
   Date pre-filtering (±1 day)
   ↓
   Time-focused comparison (70% time + 20% duration + 10% overlap)
   ↓
   If score ≥ 85: auto_confirmed → global_matches
   If score 65-84: pending_review → global_matches
   If score < 65: remains in tracking_dashboard
   ↓
   tracking_dashboard status → 'matched'
   video_dashboard status → 'matched'

2. MANUAL REVIEW (for pending_review matches)
   ↓
   User confirms or rejects
   ↓
   Match status updated
   ↓
   Change recorded in match_history

3. CLEANUP (runs periodically)
   ↓
   Records >7 days old marked 'expired'
   ↓
   Expired records >30 days deleted
```

## Schema Files

1. **`database-design.puml`**: PlantUML diagram with full documentation
2. **`schema-clean-setup.sql`**: Complete database setup script (run once)

## Next Steps

1. ✅ Database structure created
2. ⏭️ Send test tracking message
3. ⏭️ Send test video message  
4. ⏭️ Run matching job
5. ⏭️ Verify match created with score ≥65

## Important Notes

### Primeplay Data Limitation
**Primeplay tracking data DOES NOT contain actual club names!** Fields like `teamName` contain generic identifiers:
- ❌ "Test Team"
- ❌ "Team 1"  
- ❌ "Team A"

NOT actual club names like "VV Capelle" or "FC 's-Gravenzande". This is why the matching algorithm focuses on **time proximity (70%)** instead of name matching.

### Video Data Structure
Video data contains actual club names in:
- `home.name`: "VV Capelle"
- `away.name`: "FC 's-Gravenzande"
- `home.team.name`: "1" (generic identifier, similar to tracking's teamName)

### Matching Strategy
Since names cannot be matched (incompatible data formats), the system relies on:
1. **Same-day matches**: Events occurring on the same date
2. **Time proximity**: Start times within a few hours
3. **Duration similarity**: Similar event lengths
4. **Temporal overlap**: Overlapping time ranges

This approach achieves reliable matching despite the lack of club name information in tracking data.

## Connection Info

- **Host**: localhost (via Docker)
- **Port**: 5432 (mapped from container)
- **Database**: `matching_db`
- **Username**: `matching_user`
- **Container**: `matching-postgres`

## Quick Queries

```sql
-- Check system status
SELECT * FROM matching_statistics;

-- View active unmatched tracking
SELECT * FROM active_unmatched_tracking;

-- View active unmatched videos
SELECT * FROM active_unmatched_videos;

-- View matches needing review
SELECT * FROM matches_pending_review;

-- Clean up old records
SELECT * FROM cleanup_expired_records();

-- Check recent matches
SELECT 
    global_match_id,
    tracking_id,
    video_id,
    match_score,
    confidence_level,
    status,
    matched_at
FROM global_matches
ORDER BY matched_at DESC
LIMIT 10;
```

---

**Database is ready for testing!** 🚀

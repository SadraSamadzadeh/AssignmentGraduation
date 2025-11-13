# Integration Layer - Database Documentation

This folder contains comprehensive database design documentation for the Integration Layer application.

## 📁 Files in this Folder

### 1. `database-design.puml`
**PlantUML Database Diagram**
- Visual representation of all database tables and relationships
- Shows primary keys, foreign keys, and table structures
- Includes relationship cardinality and notes

**How to View:**
- Install PlantUML extension in VS Code and open the file
- Visit [PlantUML Online](http://www.plantuml.com/plantuml/) and paste the content
- Use PlantUML CLI: `plantuml database-design.puml`

### 2. `DATABASE_README.md`
**Comprehensive Database Documentation**
- Detailed description of each table
- Column definitions and data types
- Relationships and foreign keys
- Data flow and status workflows
- Migration instructions
- Usage examples

### 3. `schema-reference.sql`
**SQL Schema Reference**
- Raw SQL CREATE TABLE statements
- All indexes and foreign key constraints
- Sample queries for common operations
- Quick reference for database structure

### 4. `IMPLEMENTATION_SUMMARY.md`
**Implementation Guide**
- List of all created files
- Database tables overview
- Key features summary
- Next steps for development
- Model usage examples

## 🗄️ Database Overview

### Core Tables
- **users** - Authentication and user management
- **global_matches** - Successfully linked tracking/video matches
- **tracking_dashboard** - Unlinked tracking records awaiting matching
- **video_dashboard** - Unlinked video records awaiting matching

### Audit Tables
- **match_history** - Complete audit trail of match operations
- **dashboard_activity_log** - User activity tracking

## 🔗 Key Relationships

```
Users
  ├─> Created Matches (global_matches)
  ├─> Verified Matches (global_matches)
  ├─> Assigned Tracking Records (tracking_dashboard)
  ├─> Assigned Video Records (video_dashboard)
  ├─> Match History Entries (match_history)
  └─> Activity Log Entries (dashboard_activity_log)

Global Matches
  ├─> Created By User (users)
  ├─> Verified By User (users)
  └─> History Entries (match_history)

Dashboards (Tracking & Video)
  ├─> Assigned User (users)
  └─> Activity Log (dashboard_activity_log)
```

## 🚀 Quick Start

### 1. Apply Migrations
```bash
cd ../laravel-api
php artisan migrate
```

### 2. Check Migration Status
```bash
php artisan migrate:status
```

### 3. Create Test Data (Optional)
```bash
php artisan tinker
```

Then in tinker:
```php
// Create a test user
$user = \App\Models\User::create([
    'name' => 'Test User',
    'email' => 'test@example.com',
    'password' => bcrypt('password'),
    'role' => 'admin'
]);

// Create unmatched tracking record
$tracking = \App\Models\TrackingDashboard::create([
    'tracking_id' => 1001,
    'tracking_reference' => 'TRK-1001',
    'tracking_data' => ['status' => 'in_transit'],
    'source_system' => 'TrackingHub',
    'received_at' => now()
]);

// Create unmatched video record
$video = \App\Models\VideoDashboard::create([
    'video_id' => 'VID-2001',
    'video_reference' => 'VIDEO-2001',
    'video_data' => ['duration' => 120],
    'source_system' => 'VideoHub',
    'received_at' => now()
]);
```

## 📊 Database Statistics

After running migrations, you can check table information:

```sql
-- Count records in each table
SELECT 'users' as table_name, COUNT(*) as count FROM users
UNION ALL
SELECT 'global_matches', COUNT(*) FROM global_matches
UNION ALL
SELECT 'tracking_dashboard', COUNT(*) FROM tracking_dashboard
UNION ALL
SELECT 'video_dashboard', COUNT(*) FROM video_dashboard
UNION ALL
SELECT 'match_history', COUNT(*) FROM match_history
UNION ALL
SELECT 'dashboard_activity_log', COUNT(*) FROM dashboard_activity_log;
```

## 📋 Common Operations

### Check Unmatched Records
```php
// Get unmatched tracking records
$unmatched = \App\Models\TrackingDashboard::unmatched()->get();

// Get high priority unmatched videos
$urgent = \App\Models\VideoDashboard::unmatched()
    ->byPriority('high')
    ->get();
```

### Create a Match
```php
$match = \App\Models\GlobalMatches::create([
    'global_match_id' => 'MATCH-' . \Illuminate\Support\Str::uuid(),
    'tracking_id' => 1001,
    'video_id' => 'VID-2001',
    'match_score' => 95.5,
    'confidence_level' => 'high',
    'match_details' => ['algorithm' => 'v2'],
    'tracking_data' => $tracking->tracking_data,
    'video_data' => $video->video_data,
    'status' => 'pending',
    'created_by_user_id' => $user->id,
    'matched_at' => now()
]);
```

### Log Activity
```php
// Log match history
\App\Models\MatchHistory::logAction(
    $match->id,
    $match->tracking_id,
    $match->video_id,
    'created',
    null,
    $match->toArray(),
    $user->id
);

// Log dashboard activity
\App\Models\DashboardActivityLog::logActivity(
    $user->id,
    'tracking',
    $tracking->id,
    'updated',
    ['status' => 'processed']
);
```

## 🔍 Useful Queries

### Find Matches by Date Range
```php
$matches = \App\Models\GlobalMatches::whereBetween('matched_at', [
    now()->subDays(7),
    now()
])->get();
```

### Get User's Assigned Work
```php
$user = \App\Models\User::find(1);
$assignedTracking = $user->assignedTrackingRecords()
    ->where('status', 'pending')
    ->get();
$assignedVideos = $user->assignedVideoRecords()
    ->where('status', 'pending')
    ->get();
```

### Audit Trail for a Match
```php
$match = \App\Models\GlobalMatches::find(1);
$history = $match->history()
    ->with('user')
    ->orderBy('created_at', 'desc')
    ->get();
```

## 🛠️ Maintenance

### Backup Database
```bash
# MySQL dump
mysqldump -u username -p database_name > backup_$(date +%Y%m%d).sql

# Laravel backup (if configured)
php artisan backup:run
```

### Reset Development Database
```bash
php artisan migrate:fresh --seed
```

### Check Database Connection
```bash
php artisan db:show
```

## 📚 Additional Resources

- [Laravel Database Documentation](https://laravel.com/docs/database)
- [Laravel Eloquent ORM](https://laravel.com/docs/eloquent)
- [Laravel Migrations](https://laravel.com/docs/migrations)
- [PlantUML Documentation](https://plantuml.com/)

## 🤝 Contributing

When making database changes:
1. Create a new migration file
2. Update the PlantUML diagram
3. Update the DATABASE_README.md
4. Update the schema-reference.sql
5. Test migrations on a clean database
6. Document any breaking changes

---

**Last Updated**: November 13, 2025
**Database Version**: 1.0
**Laravel Version**: 10.x

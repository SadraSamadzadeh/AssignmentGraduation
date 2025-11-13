# Laravel Matching API

A Laravel-based REST API for matching tracking and video data using fuzzy string matching and intelligent scoring algorithms.

## Features

✅ **Fuzzy String Matching** - Handles team name variations (e.g., "Capelle 1" vs "VV Capelle")  
✅ **Timezone Normalization** - Automatic UTC conversion for accurate time comparisons  
✅ **Multi-factor Scoring** - Time proximity, name similarity, duration, and overlap analysis  
✅ **Confidence Levels** - Confident (80+), Likely (60-79), Possible (40-59), Unlikely (<40)  
✅ **Database Integration** - PostgreSQL with Eloquent ORM  
✅ **REST API** - Full CRUD operations with validation  
✅ **Batch Processing** - Handle multiple matches simultaneously  

## Quick Start

```bash
# Install dependencies
composer install

# Setup environment
cp .env.example .env
# Edit .env with your database credentials

# Generate app key
php artisan key:generate

# Run migrations
php artisan migrate

# Start server
php artisan serve
```

## API Endpoints

- `GET /health` - Health check and database status
- `GET /api/v1/matches` - Get all matches (paginated)
- `GET /api/v1/matches/{id}` - Get specific match
- `POST /api/v1/matches` - Create single match
- `POST /api/v1/matches/batch` - Batch process matches
- `DELETE /api/v1/matches/{id}` - Delete match
- `GET /api/v1/statistics` - Match statistics

## Testing

```bash
# Run PHP test script
php test-api.php

# Or use PowerShell
Invoke-RestMethod -Uri "http://localhost:8000/health" -Method GET
```

See `QUICK_TEST.md` for detailed testing instructions.

## Architecture

- **Models**: Eloquent ORM (TrackingMatch, VideoMatch, GlobalMatches, TrackingActivity)
- **Services**: MatchingService (algorithm), MatchStorageService (database)
- **Controllers**: HealthController, MatchController (API endpoints)
- **Database**: PostgreSQL with optimized schema and relationships

## Algorithm

The matching algorithm evaluates:
- **Time Proximity** (40%): How close events are chronologically
- **Name Similarity** (30%): Fuzzy matching using Levenshtein distance  
- **Duration Similarity** (20%): Event duration comparison
- **Temporal Overlap** (10%): Whether events actually overlap

Only matches with scores ≥60 are stored in the database.

## Project Structure

```
Integration Layer/
├── laravel-api/
│   ├── app/
│   │   ├── Http/Controllers/    # API controllers
│   │   ├── Models/             # Database models (User, GlobalMatches, etc.)
│   │   └── Services/           # Business logic
│   ├── database/migrations/    # Database schema
│   ├── routes/api.php         # API routes
│   └── test-api.php          # Test script
└── docs/                      # 📚 Database Documentation
    ├── database-design.puml   # PlantUML diagram
    ├── DATABASE_README.md     # Comprehensive DB docs
    ├── schema-reference.sql   # SQL schema reference
    ├── IMPLEMENTATION_SUMMARY.md
    ├── QUICK_REFERENCE.md     # Developer quick reference
    └── README.md              # Documentation index
```

## Database Design

The application uses a comprehensive database schema with the following tables:

### Core Tables
- **users** - User authentication and authorization (admin, user, viewer roles)
- **global_matches** - Successfully linked tracking-video matches with verification workflow
- **tracking_dashboard** - Unlinked tracking records awaiting matching
- **video_dashboard** - Unlinked video records awaiting matching

### Audit Tables
- **match_history** - Complete audit trail of all match operations
- **dashboard_activity_log** - User activity tracking on dashboards

**📖 See `/docs` folder for detailed database documentation including:**
- Entity Relationship Diagram (PlantUML)
- Table structures and relationships
- SQL schema reference
- Usage examples and best practices
- Quick reference card for developers
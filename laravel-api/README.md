# Laravel Matching API - Dockerized

A central hub message broker for matching tracking and video data using Laravel, RabbitMQ, and PostgreSQL.

## 🏗️ Architecture

```
Backend A (Tracking) ─┐
                      ├─► Laravel Hub ─► RabbitMQ ─► PostgreSQL
Backend B (Video) ────┘
```

## 🚀 Quick Start

### Prerequisites
- Docker and Docker Compose
- Git

### 1. Clone and Setup

```bash
git clone <repository-url>
cd laravel-api
```

### 2. Environment Configuration

Copy the Docker environment file:
```bash
cp .env.docker .env
```

Generate application key:
```bash
# We'll do this inside the container after it starts
```

### 3. Start the Stack

```bash
# Start all services (PostgreSQL, RabbitMQ, Laravel App, Queue Worker)
docker-compose up -d

# View logs
docker-compose logs -f

# Check service status
docker-compose ps
```

### 4. Initialize the Application

```bash
# Generate app key
docker-compose exec app php artisan key:generate

# Run database migrations
docker-compose exec app php artisan migrate

# (Optional) Seed some test data
docker-compose exec app php artisan db:seed
```

## 🔗 Service URLs

| Service | URL | Description |
|---------|-----|-------------|
| Laravel API | http://localhost:8000 | Main application |
| RabbitMQ Management | http://localhost:15672 | RabbitMQ admin panel |
| PostgreSQL | localhost:5432 | Database connection |
| Redis | localhost:6379 | Cache/Sessions |

### Login Credentials
- **RabbitMQ**: admin / admin
- **PostgreSQL**: matching_user / matching_password

## 📡 API Endpoints

### Basic Matching
```bash
# Health check
GET http://localhost:8000/api/health

# Test matching with sample data
GET http://localhost:8000/api/test

# Manual matching
POST http://localhost:8000/api/match
Content-Type: application/json
{
  "trackingData": {
    "id": 184,
    "startTime": "2025-10-16T17:30:13.300Z",
    "endTime": "2025-10-16T23:59:59.800Z"
  },
  "videoData": {
    "id": "video_123",
    "starting_at": {"date": "2025-10-16T20:25:00+02:00"}
  }
}
```

### Central Hub Endpoints
```bash
# Tracking backend sends data
POST http://localhost:8000/api/hub/tracking-data
{
  "request_id": "req_12345",
  "tracking_data": {...}
}

# Video backend sends data
POST http://localhost:8000/api/hub/video-data
{
  "request_id": "req_12345", 
  "video_data": {...}
}

# Hub status and statistics
GET http://localhost:8000/api/hub/status

# Test hub functionality
GET http://localhost:8000/api/hub/test

# Route messages between backends
POST http://localhost:8000/api/hub/route-message
{
  "from_backend": "tracking",
  "to_backend": "video",
  "message_data": {...}
}
```

## 🔧 Development Commands

```bash
# View application logs
docker-compose logs -f app

# View queue worker logs  
docker-compose logs -f queue-worker

# Access Laravel container shell
docker-compose exec app bash

# Run artisan commands
docker-compose exec app php artisan <command>

# Install new dependencies
docker-compose exec app composer require <package>

# Database operations
docker-compose exec app php artisan migrate
docker-compose exec app php artisan migrate:rollback
docker-compose exec app php artisan db:seed

# Queue operations
docker-compose exec app php artisan queue:work
docker-compose exec app php artisan queue:restart
```

## 🛠️ Troubleshooting

### Service Won't Start
```bash
# Check service logs
docker-compose logs <service-name>

# Rebuild containers
docker-compose down
docker-compose build --no-cache
docker-compose up -d
```

### Database Connection Issues
```bash
# Check PostgreSQL logs
docker-compose logs postgres

# Test database connection
docker-compose exec app php artisan migrate:status
```

### RabbitMQ Connection Issues
```bash
# Check RabbitMQ logs
docker-compose logs rabbitmq

# Check queue configuration
docker-compose exec app php artisan queue:work --once
```

### Permission Issues
```bash
# Fix Laravel permissions
docker-compose exec app chown -R www-data:www-data /var/www/html/storage
docker-compose exec app chmod -R 775 /var/www/html/storage
```

## 📊 Monitoring

### RabbitMQ Management UI
- URL: http://localhost:15672
- Username: admin
- Password: admin

Monitor queues, exchanges, and message throughput.

### Database Access
```bash
# Connect to PostgreSQL
docker-compose exec postgres psql -U matching_user -d matching_db

# View tables
\dt

# View global matches
SELECT * FROM global_matches;
```

## 🔄 Queue Processing

The system includes automatic queue processing:

```bash
# Manual queue processing
docker-compose exec app php artisan queue:work rabbitmq

# Process specific number of jobs
docker-compose exec app php artisan queue:work rabbitmq --max-jobs=10

# Process with timeout
docker-compose exec app php artisan queue:work rabbitmq --timeout=60
```

## 🧪 Testing

```bash
# Run PHPUnit tests
docker-compose exec app php artisan test

# Test RabbitMQ message sending
curl http://localhost:8000/api/rabbitMq

# Test hub functionality
curl http://localhost:8000/api/hub/test
```

## 📁 Project Structure

```
laravel-api/
├── docker-compose.yml          # Multi-service Docker setup
├── Dockerfile                  # Laravel application container
├── .env.docker                 # Docker environment variables
├── app/
│   ├── Http/Controllers/
│   │   ├── MatchController.php  # Basic matching API
│   │   └── HubController.php    # Central hub endpoints
│   ├── Services/
│   │   ├── MatchingService.php  # Core matching algorithm
│   │   ├── RabbitMQService.php  # RabbitMQ integration
│   │   └── MessageBrokerService.php # Hub coordination
│   ├── Jobs/
│   │   └── ProcessRabbitMQMessage.php # Queue job processing
│   └── Models/
│       └── GlobalMatches.php    # Database model
├── database/migrations/         # Database schema
└── config/                     # Laravel configuration
```

## 🚦 Production Deployment

For production deployment:

1. Update `.env` with production values
2. Set `APP_DEBUG=false` 
3. Configure proper SSL/TLS
4. Use environment-specific Docker Compose overrides
5. Set up proper monitoring and logging
6. Configure backup strategies for PostgreSQL

```bash
# Production build
docker-compose -f docker-compose.yml -f docker-compose.prod.yml up -d
```
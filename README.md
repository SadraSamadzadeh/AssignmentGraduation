# USF.Sport Integration Platform

**Tracking & Video Dashboard Matching System**

A Laravel-based microservice that integrates USF.Sport's tracking, camera, and video dashboards into a unified platform. The system receives real-time tracking and video events via webhooks, correlates them using an intelligent matching algorithm, and provides a consolidated view of sports match data.

---

## Table of Contents

- [Overview](#overview)
- [Architecture](#architecture)
- [Features](#features)
- [Technology Stack](#technology-stack)
- [System Requirements](#system-requirements)
- [Quick Start](#quick-start)
- [Deployment](#deployment)
- [Scaling](#scaling)
- [Maintenance](#maintenance)
- [Troubleshooting](#troubleshooting)
- [API Documentation](#api-documentation)
- [Contributing](#contributing)

---

## Overview

### Problem Statement

USF.Sport operates three separate systems:
- **Tracking Dashboard** - Real-time match tracking data (from Primeplay)
- **Camera Dashboard** - Live camera feeds and controls
- **Video Dashboard** - Recorded match videos

These systems operate independently, making it difficult to correlate tracking data with video recordings.

### Solution

This application provides:
- **Automated matching** between tracking and video data
- **Fuzzy matching algorithm** using club names and timestamps
- **Webhook-based integration** for real-time data ingestion
- **Asynchronous processing** via RabbitMQ for high performance
- **Docker Swarm deployment** for production scalability

### How It Works

```
1. External systems send webhook events (tracking/video data)
   ↓
2. API receives event → Stores in database → Dispatches job to RabbitMQ
   ↓
3. Queue workers process jobs asynchronously
   ↓
4. MatchCoordinator service correlates tracking ↔ video data
   ↓
5. Matched records stored in global_matches table
   ↓
6. Unmatched records retry every 5 minutes (scheduler)
```

---

## Architecture

### Docker Swarm Deployment

The application runs on Docker Swarm with the following services:

| Service | Replicas | Purpose | Resources |
|---------|----------|---------|-----------|
| **postgres** | 1 | PostgreSQL 15 database | 2 CPU, 2 GB RAM |
| **redis** | 1 | Cache and session storage | 0.5 CPU, 512 MB RAM |
| **rabbitmq** | 1 | Message queue broker | 1 CPU, 1 GB RAM |
| **api** | 3 (scalable to 10) | Laravel API endpoints | 1 CPU, 512 MB RAM each |
| **worker** | 5 (scalable to 20) | Queue job processors | 0.5 CPU, 512 MB RAM each |
| **scheduler** | 1 | Cron job runner | 0.25 CPU, 256 MB RAM |

**Total Resources (Default):**
- CPU: ~8 cores
- Memory: ~8.5 GB
- Storage: 50-100 GB (persistent volumes)

### Architecture Diagrams

See detailed architecture diagrams:
- `docs/diagrams/docker-swarm-architecture.puml` - Complete infrastructure overview
- `docs/diagrams/docker-swarm-dataflow.puml` - Data flow and processing pipeline

---

## Features

### Core Functionality

- **Real-time Event Processing** - Webhook endpoints for tracking and video events
- **Intelligent Matching Algorithm** - Fuzzy matching with confidence scoring
- **Asynchronous Job Processing** - Non-blocking queue-based architecture
- **Automatic Retry Logic** - Unmatched records retry every 5 minutes
- **Monthly Data Partitioning** - Database optimization for large datasets
- **Health Monitoring** - Built-in health check endpoints

### Matching Algorithm

**Criteria:**
- Club name similarity (Levenshtein distance < 3)
- Event date/time proximity (within 2-hour window)
- Home and away team matching
- Confidence score (0-100)

**Performance:**
- 50-100 matches per second
- <200ms API response time
- <50ms database query time

---

## Technology Stack

### Backend
- **Laravel 8.2** - PHP framework
- **PHP 8.2** - Programming language
- **PostgreSQL 15** - Primary database
- **Redis 7** - Cache and session storage
- **RabbitMQ 3** - Message queue

### Infrastructure
- **Docker Swarm** - Container orchestration
- **Nginx** - Web server (in API containers)
- **Supervisor** - Process manager

### Key Libraries
- **php-amqplib** - RabbitMQ client
- **vladimir-yuldashev/laravel-queue-rabbitmq** - Laravel RabbitMQ integration
- **Guzzle** - HTTP client for external APIs

---

## System Requirements

### Minimum Requirements (Development/Testing)
- 4 CPU cores
- 8 GB RAM
- 50 GB SSD storage
- Ubuntu 20.04 LTS or later
- Docker Engine 20.10+

### Recommended (Production)
- 8 CPU cores
- 16 GB RAM
- 100 GB SSD storage
- Ubuntu 22.04 LTS
- Docker Engine 24.0+

### Network Requirements
- Ports 80/443 (HTTP/HTTPS)
- Port 15672 (RabbitMQ Management UI)
- Outbound internet access for webhooks

---

## Quick Start

### Prerequisites

1. **Install Docker**
   ```bash
   curl -fsSL https://get.docker.com -o get-docker.sh
   sudo sh get-docker.sh
   sudo usermod -aG docker $USER
   newgrp docker
   ```

2. **Clone Repository**
   ```bash
   git clone https://github.com/SadraSamadzadeh/AssignmentGraduation.git
   cd AssignmentGraduation
   ```

### 3-Step Deployment

```bash
# Step 1: Make scripts executable
chmod +x swarm-deploy.sh swarm-setup-secrets.sh

# Step 2: Initialize Swarm and create secrets
./swarm-deploy.sh init

# Step 3: Deploy application
./swarm-deploy.sh deploy

# Step 4: Run database migrations
./swarm-deploy.sh migrate
```

### Verify Deployment

```bash
# Check service status
./swarm-deploy.sh status

# Test API health
curl http://localhost/api/health

# Access RabbitMQ Management UI
# Open browser: http://localhost:15672
# Login: guest / guest (change in production!)
```

---

## Deployment

### Complete Deployment Guide

For detailed deployment instructions, see **[DOCKER_SWARM_GUIDE.md](DOCKER_SWARM_GUIDE.md)**

### Initial Setup

1. **Initialize Docker Swarm**
   ```bash
   ./swarm-deploy.sh init
   ```
   
   This will:
   - Initialize Docker Swarm on your server
   - Create secure secrets (APP_KEY, passwords)
   - Display created secrets

2. **Configure Environment (Optional)**
   
   Edit `docker-compose.swarm.yml` if needed:
   ```yaml
   environment:
     DB_DATABASE: assignment_graduation
     DB_USERNAME: assignment_user
     LOG_LEVEL: info
     TZ: Europe/Amsterdam
   ```

3. **Deploy Stack**
   ```bash
   ./swarm-deploy.sh deploy
   ```
   
   This will:
   - Build Docker images for API and workers
   - Deploy all 6 services
   - Set up networking and volumes
   - Configure load balancing

4. **Run Database Migrations**
   ```bash
   ./swarm-deploy.sh migrate
   ```

5. **Monitor Deployment**
   ```bash
   # Watch services come online
   watch docker service ls
   
   # Check logs
   docker service logs assignmentgraduation_api -f
   ```

### Database Schema Setup

The application uses the following schema:

```bash
# Import schema (if needed)
container=$(docker ps -q -f "name=assignmentgraduation_postgres" | head -n 1)
cat docs/schema-final-optimized.sql | docker exec -i $container psql -U assignment_user assignment_graduation
```

**Schema files:**
- `docs/schema-final-optimized.sql` - Production schema (recommended)
- `docs/schema-clean-setup.sql` - Clean initial setup
- `docs/schema-reference.sql` - Reference documentation

---

## Scaling

### Manual Scaling

**Scale API servers (3 → 10 replicas):**
```bash
docker service scale assignmentgraduation_api=10
```

**Scale queue workers (5 → 15 replicas):**
```bash
docker service scale assignmentgraduation_worker=15
```

**Interactive scaling:**
```bash
./swarm-deploy.sh scale
```

### When to Scale

**Scale API UP when:**
- Response time > 500ms
- CPU usage > 70%
- Request rate > 1000/min

**Scale Workers UP when:**
- RabbitMQ queue depth > 1000
- Message processing lag > 5 minutes
- Match day with high event volume

**Recommended configurations:**

| Scenario | API Replicas | Worker Replicas |
|----------|--------------|-----------------|
| Low traffic (< 100 req/min) | 3 | 5 |
| Medium traffic (< 500 req/min) | 5 | 10 |
| High traffic (< 2000 req/min) | 8 | 15 |
| Peak/Match day | 10 | 20 |

---

## Maintenance

### Updates

**Deploy new version:**
```bash
# Build and update with zero downtime
./swarm-deploy.sh update v1.0.1
```

**Rollback if issues:**
```bash
./swarm-deploy.sh rollback
```

### Backups

**Database backup (automated recommended):**
```bash
# Manual backup
POSTGRES_CONTAINER=$(docker ps -q -f "name=assignmentgraduation_postgres")
docker exec $POSTGRES_CONTAINER pg_dump -U assignment_user assignment_graduation | gzip > backup_$(date +%Y%m%d).sql.gz
```

**Automated daily backup script:**
```bash
#!/bin/bash
# Save as /usr/local/bin/backup-db.sh
DATE=$(date +%Y%m%d_%H%M%S)
POSTGRES_CONTAINER=$(docker ps -q -f "name=assignmentgraduation_postgres")
BACKUP_DIR="/backups"

mkdir -p $BACKUP_DIR
docker exec $POSTGRES_CONTAINER pg_dump -U assignment_user assignment_graduation | gzip > $BACKUP_DIR/backup_$DATE.sql.gz

# Keep only last 7 days
find $BACKUP_DIR -name "backup_*.sql.gz" -mtime +7 -delete
```

**Add to crontab:**
```bash
crontab -e
# Add: 0 2 * * * /usr/local/bin/backup-db.sh
```

### Monitoring

**Service status:**
```bash
./swarm-deploy.sh status
```

**View logs:**
```bash
# API logs
docker service logs assignmentgraduation_api --tail 100 -f

# Worker logs
docker service logs assignmentgraduation_worker --tail 100 -f

# All services
./swarm-deploy.sh logs
```

**Resource usage:**
```bash
docker stats
```

**RabbitMQ monitoring:**
- Management UI: http://your-server:15672
- Check queue depths, message rates, consumer counts

### Common Maintenance Tasks

**Clear cache:**
```bash
container=$(docker ps -q -f "name=assignmentgraduation_api" | head -n 1)
docker exec $container php artisan cache:clear
docker exec $container php artisan config:clear
```

**Restart service:**
```bash
docker service update --force assignmentgraduation_api
```

**Check database connections:**
```bash
container=$(docker ps -q -f "name=assignmentgraduation_postgres")
docker exec $container psql -U assignment_user -d assignment_graduation -c "SELECT count(*) FROM pg_stat_activity;"
```

---

## Troubleshooting

### Service Won't Start

**Check logs:**
```bash
docker service ps assignmentgraduation_api --no-trunc
docker service logs assignmentgraduation_api
```

**Common issues:**
- Missing secrets: `docker secret ls`
- Resource limits: `docker stats`
- Image not found: `./swarm-deploy.sh deploy`

### Database Connection Errors

**Verify PostgreSQL is running:**
```bash
docker service ps assignmentgraduation_postgres
```

**Test connection from API:**
```bash
container=$(docker ps -q -f "name=assignmentgraduation_api" | head -n 1)
docker exec $container nc -zv postgres 5432
```

### Queue Jobs Not Processing

**Check worker logs:**
```bash
docker service logs assignmentgraduation_worker -f
```

**Check RabbitMQ:**
```bash
# Access RabbitMQ container
container=$(docker ps -q -f "name=assignmentgraduation_rabbitmq")
docker exec $container rabbitmqctl list_queues
```

**Scale workers if needed:**
```bash
docker service scale assignmentgraduation_worker=10
```

### High Memory Usage

**Check resource usage:**
```bash
docker stats
```

**Increase limits in docker-compose.swarm.yml:**
```yaml
deploy:
  resources:
    limits:
      memory: 1G  # Increase from 512M
```

**Redeploy:**
```bash
./swarm-deploy.sh update
```

For more troubleshooting, see **[DOCKER_SWARM_GUIDE.md](DOCKER_SWARM_GUIDE.md#troubleshooting)**

---

## API Documentation

### Health Check

```bash
GET /api/health
```

**Response:**
```json
{
  "status": "healthy",
  "timestamp": "2025-12-12T10:30:00Z"
}
```

### Webhook Endpoints

#### Tracking Events

```bash
POST /api/webhooks/tracking
Content-Type: application/json

{
  "messageType": "LiveDataRecordingStopped",
  "datasetId": "12345",
  "eventDate": "2025-12-12",
  "homeTeam": "Ajax",
  "awayTeam": "PSV",
  "duration": 5400
}
```

#### Video Events

```bash
POST /api/webhooks/video
Content-Type: application/json

{
  "messageType": "LiveDataRecordingStopped",
  "videoId": "67890",
  "recordingDate": "2025-12-12",
  "homeClub": "Ajax",
  "awayClub": "PSV",
  "duration": 5400
}
```

### Database Schema

**Main Tables:**
- `tracking_dashboard` - Tracking event data
- `video_dashboard` - Video event data
- `global_matches` - Matched tracking ↔ video records
- `confirmed_matches` - User-confirmed matches
- `match_history` - Audit log of all matches

**See:** `docs/schema-final-optimized.sql` for complete schema

---

## Performance

### Optimization Features

- **Database Partitioning** - Monthly partitions on `event_date`
- **Indexed Columns** - Optimized queries on club names, dates
- **Redis Caching** - Configuration and session caching
- **Connection Pooling** - Reused database connections
- **OPcache** - PHP opcode caching enabled
- **Gzip Compression** - Reduced network transfer

### Performance Metrics

**Target Performance:**
- API Response Time: < 200ms (p95)
- Database Query Time: < 50ms (p95)
- Queue Throughput: 50-100 jobs/sec
- Match Processing: 1000+ matches/hour

**Load Testing:**
```bash
# Install Apache Bench
sudo apt install apache2-utils

# Test API endpoint
ab -n 1000 -c 10 http://localhost/api/health
```

---

## Contributing

### Development Setup

1. **Clone repository**
   ```bash
   git clone https://github.com/SadraSamadzadeh/AssignmentGraduation.git
   cd AssignmentGraduation
   ```

2. **Install Laravel dependencies**
   ```bash
   cd laravel-api
   composer install
   cp .env.example .env
   php artisan key:generate
   ```

3. **Run locally with Docker Compose**
   ```bash
   # Use development compose file
   docker-compose -f laravel-api/Dockerfile up -d
   ```

### Code Style

- Follow PSR-12 coding standards
- Use meaningful variable names
- Add PHPDoc comments for all methods
- Write unit tests for new features

### Testing

```bash
cd laravel-api
php artisan test
```

---

## License

This project is part of the USF.Sport graduation assignment at Saxion University of Applied Sciences.

**Author:** Sadra Samadzadeh Gharehghaieh  
**Institution:** Saxion University of Applied Sciences  
**Program:** Software Engineering  
**Year:** 2025

---

## Support

For issues, questions, or contributions:

- **GitHub Issues:** https://github.com/SadraSamadzadeh/AssignmentGraduation/issues
- **Documentation:** See `DOCKER_SWARM_GUIDE.md` for detailed deployment guide
- **Architecture Diagrams:** See `docs/diagrams/` for visual documentation

---

## Quick Reference

### Essential Commands

```bash
# Deployment
./swarm-deploy.sh init      # Initialize Swarm and secrets
./swarm-deploy.sh deploy    # Deploy application
./swarm-deploy.sh migrate   # Run database migrations

# Management
./swarm-deploy.sh status    # Check service status
./swarm-deploy.sh logs      # View service logs
./swarm-deploy.sh scale     # Scale services

# Updates
./swarm-deploy.sh update    # Deploy new version
./swarm-deploy.sh rollback  # Rollback to previous version

# Maintenance
./swarm-deploy.sh remove    # Remove entire stack
docker service ls           # List all services
docker stats                # Resource usage
```

### Key Files

- `docker-compose.swarm.yml` - Production stack configuration
- `swarm-deploy.sh` - Deployment management script
- `DOCKER_SWARM_GUIDE.md` - Complete deployment documentation
- `docs/diagrams/` - Architecture diagrams
- `docs/schema-final-optimized.sql` - Database schema

---

**Ready to deploy?** Start with `./swarm-deploy.sh init` and follow the [Quick Start](#quick-start) guide!

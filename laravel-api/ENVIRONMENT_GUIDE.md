# Environment Configuration Guide

## Overview

This Laravel application supports both **local development** and **Docker deployment** environments. Each has its own configuration requirements.

## Environment Files

### 1. `.env` - Local Development
Use this for development on your local machine with PostgreSQL and RabbitMQ installed locally.

```bash
# Database connects to localhost PostgreSQL
DB_HOST=localhost

# RabbitMQ connects to localhost
RABBITMQ_HOST=127.0.0.1
QUEUE_CONNECTION=rabbitmq

# File-based cache and sessions
CACHE_DRIVER=file
SESSION_DRIVER=file
```

### 2. `.env.docker` - Docker Deployment
Use this for running the application in Docker containers.

```bash
# Database connects to Docker container
DB_HOST=postgres

# RabbitMQ connects to Docker container
RABBITMQ_HOST=rabbitmq
QUEUE_CONNECTION=rabbitmq

# Redis cache and sessions
CACHE_DRIVER=redis
SESSION_DRIVER=redis
```

## Starting the Application

### Local Development
```bash
# Ensure PostgreSQL and RabbitMQ are running locally
# Use the default .env file
php artisan serve
```

### Docker Deployment
```bash
# Copy Docker environment
copy .env.docker .env

# Start Docker containers
docker-compose up -d

# Or use the startup script
start.bat
```

## Key Differences

| Configuration | Local | Docker |
|---------------|-------|--------|
| Database Host | `localhost` | `postgres` |
| RabbitMQ Host | `127.0.0.1` | `rabbitmq` |
| Cache Driver | `file` | `redis` |
| Session Driver | `file` | `redis` |
| Redis Host | `127.0.0.1` | `redis` |

## Troubleshooting

### Connection Issues
- **Local**: Ensure PostgreSQL and RabbitMQ services are running
- **Docker**: Ensure all containers are up with `docker-compose ps`

### Environment Conflicts
- Always use the correct `.env` file for your deployment method
- Check that host names match your environment (localhost vs container names)

### Database Issues
- **Local**: Create database `graduation_matching` manually
- **Docker**: Database is auto-created via init scripts

## Best Practices

1. **Never commit** `.env` files to version control
2. **Always backup** your environment files before changes
3. **Test both environments** after configuration changes
4. **Use the startup scripts** to ensure proper environment setup
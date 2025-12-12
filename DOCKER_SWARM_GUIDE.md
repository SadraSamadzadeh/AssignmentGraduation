# Docker Swarm Deployment Guide

Complete guide for deploying and managing the application using Docker Swarm.

---

## Table of Contents

- [Overview](#overview)
- [Prerequisites](#prerequisites)
- [Quick Start](#quick-start)
- [Service Architecture](#service-architecture)
- [Deployment Steps](#deployment-steps)
- [Common Operations](#common-operations)
- [Multi-Server Setup](#multi-server-setup)
- [Backup and Restore](#backup-and-restore)
- [Troubleshooting](#troubleshooting)
- [Security Best Practices](#security-best-practices)
- [Performance Tuning](#performance-tuning)
- [Comparison with Kubernetes](#comparison-with-kubernetes)

---

## Overview

Docker Swarm provides a simple yet powerful orchestration solution for running the application in production. It offers:

- **Automatic load balancing** across API replicas
- **Zero-downtime rolling updates** for all services
- **Service discovery** and internal DNS
- **Secrets management** for sensitive data
- **Health checks** with automatic recovery
- **Horizontal scaling** for API and workers
- **Simple management** with familiar Docker commands

**Estimated setup time**: 5-10 minutes

---

## Prerequisites

### System Requirements

**Minimum for single server:**
- 4 CPU cores
- 8 GB RAM
- 50 GB SSD storage
- Ubuntu 20.04 LTS or later (or any Linux with Docker 20.10+)

**Recommended for production:**
- 8 CPU cores
- 16 GB RAM
- 100 GB SSD storage
- Ubuntu 22.04 LTS

### Software Requirements

1. **Docker Engine 20.10 or later**
   ```bash
   # Install Docker
   curl -fsSL https://get.docker.com -o get-docker.sh
   sudo sh get-docker.sh
   
   # Add user to docker group
   sudo usermod -aG docker $USER
   newgrp docker
   ```

2. **Docker Compose** (usually included with Docker)
   ```bash
   docker compose version
   ```

3. **Git** (for cloning repository)
   ```bash
   sudo apt update
   sudo apt install git -y
   ```

---

## Quick Start

### 1. Clone Repository

```bash
git clone <repository-url>
cd AssignmentGraduation
```

### 2. Initialize Swarm and Create Secrets

```bash
chmod +x swarm-deploy.sh swarm-setup-secrets.sh
./swarm-deploy.sh init
```

This will:
- Initialize Docker Swarm
- Create secure secrets (APP_KEY, passwords)
- Display all created secrets

### 3. Configure Environment

Edit `docker-compose.swarm.yml` if needed:
- Database name: `DB_DATABASE`
- Time zone: `TZ`
- Log level: `LOG_LEVEL`

### 4. Deploy Stack

```bash
./swarm-deploy.sh deploy
```

This will:
- Build Docker images
- Deploy all services
- Set up networking and volumes

### 5. Run Database Migrations

```bash
./swarm-deploy.sh migrate
```

### 6. Verify Deployment

```bash
./swarm-deploy.sh status
```

**Test API:**
```bash
curl http://localhost/api/health
```

---

## Service Architecture

### Services Overview

| Service | Replicas | Purpose | Ports |
|---------|----------|---------|-------|
| **postgres** | 1 | PostgreSQL 15 database | 5432 (internal) |
| **redis** | 1 | Cache and session storage | 6379 (internal) |
| **rabbitmq** | 1 | Message queue | 5672, 15672 (management) |
| **api** | 3 | Laravel API endpoints | 80, 443 |
| **worker** | 5 | Queue workers | - |
| **scheduler** | 1 | Cron jobs | - |

### Resource Allocation

**Per service limits:**
- **postgres**: 2 CPU cores, 2 GB RAM
- **redis**: 0.5 CPU cores, 512 MB RAM
- **rabbitmq**: 1 CPU core, 1 GB RAM
- **api** (each): 1 CPU core, 512 MB RAM
- **worker** (each): 0.5 CPU cores, 512 MB RAM
- **scheduler**: 0.25 CPU cores, 256 MB RAM

**Total for default configuration:**
- CPU: ~8 cores
- RAM: ~8.5 GB

### Network Architecture

All services communicate over an encrypted overlay network (`app-network`). External access is only available for:
- **API**: Port 80/443
- **RabbitMQ Management**: Port 15672

---

## Deployment Steps

### Step 1: Prepare Environment

```bash
cd AssignmentGraduation
chmod +x swarm-deploy.sh swarm-setup-secrets.sh
```

### Step 2: Initialize Swarm

```bash
./swarm-deploy.sh init
```

**Expected output:**
```
Initializing Docker Swarm...
Swarm initialized: current node (xyz) is now a manager.
Creating secrets...
Generate Laravel APP_KEY? (y/N): y
Generated Laravel APP_KEY
Created secret: app_key
...
```

### Step 3: Review Configuration

Check `docker-compose.swarm.yml` and adjust if needed:

```bash
nano docker-compose.swarm.yml
```

**Common changes:**
- Database credentials
- Application domain
- Resource limits
- Replica counts

### Step 4: Deploy Stack

```bash
./swarm-deploy.sh deploy
```

**Monitor deployment:**
```bash
watch docker service ls
```

Wait until all services show `REPLICAS` as desired count (e.g., `3/3` for API).

### Step 5: Run Migrations

```bash
./swarm-deploy.sh migrate
```

### Step 6: Verify Health

```bash
# Check service status
./swarm-deploy.sh status

# Check API health
curl http://localhost/api/health

# Check RabbitMQ
curl http://localhost:15672
# Login: guest / guest (change in production!)
```

---

## Common Operations

### Scaling Services

**Scale API servers (e.g., to 5 replicas):**
```bash
docker service scale assignmentgraduation_api=5
```

**Scale workers (e.g., to 10 replicas):**
```bash
docker service scale assignmentgraduation_worker=10
```

**Interactive scaling:**
```bash
./swarm-deploy.sh scale
```

**View current replicas:**
```bash
docker service ls
```

### Updating Application

**Deploy new version:**
```bash
# Build new version
./swarm-deploy.sh update v1.0.1

# Or update specific service
docker service update --image assignmentgraduation-api:v1.0.1 assignmentgraduation_api
```

**Monitor rolling update:**
```bash
watch docker service ps assignmentgraduation_api
```

### Rollback

**Rollback to previous version:**
```bash
docker service rollback assignmentgraduation_api
```

**Interactive rollback:**
```bash
./swarm-deploy.sh rollback
```

### Viewing Logs

**View API logs:**
```bash
docker service logs -f assignmentgraduation_api
```

**View worker logs:**
```bash
docker service logs -f assignmentgraduation_worker --tail 100
```

**Interactive log viewer:**
```bash
./swarm-deploy.sh logs
```

### Running Artisan Commands

**Run migrations:**
```bash
./swarm-deploy.sh migrate
```

**Clear cache:**
```bash
container=$(docker ps -q -f "name=assignmentgraduation_api" | head -n 1)
docker exec -it $container php artisan cache:clear
```

**Run any artisan command:**
```bash
container=$(docker ps -q -f "name=assignmentgraduation_api" | head -n 1)
docker exec -it $container php artisan <command>
```

### Monitoring Services

**Service status:**
```bash
./swarm-deploy.sh status
```

**Detailed service info:**
```bash
docker service inspect assignmentgraduation_api
```

**Resource usage:**
```bash
docker stats
```

**Health checks:**
```bash
docker service ps assignmentgraduation_api --format "table {{.Name}}\t{{.CurrentState}}\t{{.Error}}"
```

---

## Multi-Server Setup

Docker Swarm can distribute services across multiple servers for high availability.

### Manager Node (First Server)

```bash
# Initialize Swarm
docker swarm init --advertise-addr <MANAGER-IP>

# Save join token
docker swarm join-token worker
```

### Worker Nodes (Additional Servers)

```bash
# Join Swarm (use token from manager)
docker swarm join --token <TOKEN> <MANAGER-IP>:2377
```

### Deploy to Cluster

```bash
# From manager node
./swarm-deploy.sh deploy
```

**Services will automatically distribute across nodes based on:**
- Available resources
- Placement constraints
- Load balancing

### Node Management

**List nodes:**
```bash
docker node ls
```

**Label nodes for specific workloads:**
```bash
# Label node for database
docker node update --label-add type=database node1

# Update compose file to use label
services:
  postgres:
    deploy:
      placement:
        constraints:
          - node.labels.type == database
```

**Drain node for maintenance:**
```bash
docker node update --availability drain node2
```

---

## Backup and Restore

### Database Backup

**Manual backup:**
```bash
# Find postgres container
POSTGRES_CONTAINER=$(docker ps -q -f "name=assignmentgraduation_postgres")

# Create backup
docker exec $POSTGRES_CONTAINER pg_dump -U assignment_user assignment_graduation > backup.sql
```

**Automated backup script:**
```bash
#!/bin/bash
DATE=$(date +%Y%m%d_%H%M%S)
POSTGRES_CONTAINER=$(docker ps -q -f "name=assignmentgraduation_postgres")
BACKUP_DIR="/backups"

mkdir -p $BACKUP_DIR
docker exec $POSTGRES_CONTAINER pg_dump -U assignment_user assignment_graduation | gzip > $BACKUP_DIR/backup_$DATE.sql.gz

# Keep only last 7 days
find $BACKUP_DIR -name "backup_*.sql.gz" -mtime +7 -delete
```

**Add to cron:**
```bash
crontab -e
# Add line:
0 2 * * * /path/to/backup-script.sh
```

### Database Restore

```bash
POSTGRES_CONTAINER=$(docker ps -q -f "name=assignmentgraduation_postgres")

# Restore from backup
gunzip -c backup.sql.gz | docker exec -i $POSTGRES_CONTAINER psql -U assignment_user assignment_graduation
```

### Volume Backup

**Backup all volumes:**
```bash
# Stop stack
docker stack rm assignmentgraduation

# Backup volumes
docker run --rm -v assignmentgraduation_postgres_data:/data -v $(pwd):/backup alpine tar czf /backup/postgres_data.tar.gz -C /data .
docker run --rm -v assignmentgraduation_redis_data:/data -v $(pwd):/backup alpine tar czf /backup/redis_data.tar.gz -C /data .
docker run --rm -v assignmentgraduation_rabbitmq_data:/data -v $(pwd):/backup alpine tar czf /backup/rabbitmq_data.tar.gz -C /data .

# Redeploy stack
./swarm-deploy.sh deploy
```

### Secrets Backup

**Export secrets (store securely!):**
```bash
# Cannot read secret values directly
# Best practice: Store secrets in password manager during creation
```

---

## Troubleshooting

### Service Won't Start

**Check service logs:**
```bash
docker service logs assignmentgraduation_api
```

**Check service events:**
```bash
docker service ps assignmentgraduation_api --no-trunc
```

**Common issues:**
- Missing secrets: Verify with `docker secret ls`
- Resource limits: Check with `docker stats`
- Image not found: Run `./swarm-deploy.sh deploy` to rebuild

### Database Connection Errors

**Verify postgres is running:**
```bash
docker service ps assignmentgraduation_postgres
```

**Check connection from API:**
```bash
container=$(docker ps -q -f "name=assignmentgraduation_api" | head -n 1)
docker exec -it $container nc -zv postgres 5432
```

**View postgres logs:**
```bash
docker service logs assignmentgraduation_postgres
```

### High Memory Usage

**Check resource usage:**
```bash
docker stats
```

**Increase service limits:**
```bash
# Edit docker-compose.swarm.yml
services:
  api:
    deploy:
      resources:
        limits:
          memory: 1G  # Increase from 512M
```

**Redeploy:**
```bash
./swarm-deploy.sh update
```

### Queue Jobs Not Processing

**Check worker logs:**
```bash
docker service logs assignmentgraduation_worker -f
```

**Verify RabbitMQ connection:**
```bash
# Access RabbitMQ management
curl http://localhost:15672
```

**Scale workers if needed:**
```bash
docker service scale assignmentgraduation_worker=10
```

### API Timeouts

**Check API logs:**
```bash
docker service logs assignmentgraduation_api --tail 200
```

**Possible causes:**
- Slow database queries (check postgres logs)
- Worker queue backup (scale workers)
- Insufficient API replicas (scale API)

### Disk Space Issues

**Check disk usage:**
```bash
df -h
docker system df
```

**Clean up:**
```bash
# Remove unused images
docker image prune -a

# Remove unused volumes (CAREFUL!)
docker volume prune

# Remove old logs
docker service logs assignmentgraduation_api 2>&1 | head -n 1000 > /dev/null
```

---

## Security Best Practices

### 1. Change Default Passwords

**RabbitMQ:**
Edit `docker-compose.swarm.yml`:
```yaml
environment:
  RABBITMQ_DEFAULT_USER: admin
  RABBITMQ_DEFAULT_PASS_FILE: /run/secrets/rabbitmq_password
```

### 2. Use Strong Secrets

Always generate random secrets:
```bash
# During init, always choose "y" for generation
./swarm-deploy.sh init
```

### 3. Restrict Network Access

**Use firewall to limit access:**
```bash
# Allow only necessary ports
sudo ufw allow 22/tcp    # SSH
sudo ufw allow 80/tcp    # HTTP
sudo ufw allow 443/tcp   # HTTPS
sudo ufw enable
```

### 4. Enable SSL/TLS

**Option 1: Using Traefik (Recommended)**

Add Traefik service to stack:
```yaml
services:
  traefik:
    image: traefik:v2.10
    command:
      - "--providers.docker=true"
      - "--providers.docker.swarmMode=true"
      - "--entrypoints.web.address=:80"
      - "--entrypoints.websecure.address=:443"
      - "--certificatesresolvers.letsencrypt.acme.email=admin@example.com"
      - "--certificatesresolvers.letsencrypt.acme.storage=/letsencrypt/acme.json"
      - "--certificatesresolvers.letsencrypt.acme.httpchallenge.entrypoint=web"
    ports:
      - "80:80"
      - "443:443"
    volumes:
      - "/var/run/docker.sock:/var/run/docker.sock:ro"
      - "traefik-certs:/letsencrypt"
    deploy:
      placement:
        constraints:
          - node.role == manager
```

Update API labels:
```yaml
services:
  api:
    deploy:
      labels:
        - "traefik.enable=true"
        - "traefik.http.routers.api.rule=Host(`api.yourdomain.com`)"
        - "traefik.http.routers.api.entrypoints=websecure"
        - "traefik.http.routers.api.tls.certresolver=letsencrypt"
```

**Option 2: Using Certbot + Nginx**

See BARE_METAL_DEPLOYMENT.md for detailed instructions.

### 5. Regular Updates

```bash
# Update base images monthly
./swarm-deploy.sh update
```

### 6. Limit Container Privileges

All services already run as non-root users in Dockerfiles.

### 7. Enable Docker Content Trust

```bash
export DOCKER_CONTENT_TRUST=1
```

---

## Performance Tuning

### 1. Database Optimization

**Increase shared_buffers (PostgreSQL):**

Create custom postgres config:
```bash
# postgres.conf
shared_buffers = 512MB
effective_cache_size = 2GB
maintenance_work_mem = 128MB
checkpoint_completion_target = 0.9
```

Mount in compose file:
```yaml
services:
  postgres:
    volumes:
      - postgres_data:/var/lib/postgresql/data
      - ./postgres.conf:/etc/postgresql/postgresql.conf
    command: postgres -c config_file=/etc/postgresql/postgresql.conf
```

### 2. Redis Optimization

**Enable persistence and compression:**
```yaml
services:
  redis:
    command: redis-server --appendonly yes --maxmemory 512mb --maxmemory-policy allkeys-lru
```

### 3. PHP-FPM Tuning

Edit `Dockerfile.production`:
```dockerfile
# Increase PHP-FPM workers
RUN echo "pm.max_children = 50" >> /usr/local/etc/php-fpm.d/www.conf && \
    echo "pm.start_servers = 10" >> /usr/local/etc/php-fpm.d/www.conf && \
    echo "pm.min_spare_servers = 5" >> /usr/local/etc/php-fpm.d/www.conf && \
    echo "pm.max_spare_servers = 20" >> /usr/local/etc/php-fpm.d/www.conf
```

### 4. Optimize API Replicas

**Scale based on load:**
```bash
# For 1000 req/min
docker service scale assignmentgraduation_api=5

# For 5000 req/min
docker service scale assignmentgraduation_api=10
```

### 5. Worker Queue Optimization

**Scale workers based on queue depth:**
```bash
# Check RabbitMQ queue depth
# If depth > 1000, scale workers
docker service scale assignmentgraduation_worker=15
```

### 6. Enable OPcache

Already enabled in `Dockerfile.production`:
```dockerfile
RUN docker-php-ext-enable opcache
```

### 7. Connection Pooling

Laravel uses persistent Redis connections by default. For PostgreSQL, consider using PgBouncer:

```yaml
services:
  pgbouncer:
    image: pgbouncer/pgbouncer:latest
    environment:
      DATABASES_HOST: postgres
      DATABASES_PORT: 5432
      DATABASES_USER: assignment_user
      DATABASES_PASSWORD_FILE: /run/secrets/db_password
      DATABASES_DBNAME: assignment_graduation
      PGBOUNCER_POOL_MODE: transaction
      PGBOUNCER_MAX_CLIENT_CONN: 1000
      PGBOUNCER_DEFAULT_POOL_SIZE: 25
```

---

## Comparison with Kubernetes

| Feature | Docker Swarm | Kubernetes |
|---------|--------------|------------|
| **Setup Time** | 5 minutes | 2+ hours |
| **Learning Curve** | Low | High |
| **Configuration** | 1 YAML file | 10+ YAML files |
| **Scaling** | Manual/scripted | Auto-scaling (HPA) |
| **Load Balancing** | Built-in | Requires Ingress |
| **Secrets** | Built-in | Built-in |
| **Health Checks** | Built-in | Built-in |
| **Rolling Updates** | Built-in | Built-in |
| **Multi-cloud** | No | Yes |
| **Community** | Smaller | Large |
| **Complexity** | Simple | Complex |
| **Best For** | Small-medium deployments | Large enterprise |
| **Cost (managed)** | N/A | $72+/month |
| **Monitoring** | Basic | Advanced (Prometheus) |
| **Service Mesh** | No | Yes (Istio) |

**When to use Swarm:**
- Team size < 10 developers
- Single datacenter/region
- Simple architecture (< 20 services)
- Budget < $500/month
- Quick deployment needed

**When to use Kubernetes:**
- Enterprise scale (100+ services)
- Multi-region deployment
- Advanced features needed (service mesh, auto-scaling)
- Large DevOps team
- Compliance requirements

---

## Additional Resources

### Documentation
- Docker Swarm: https://docs.docker.com/engine/swarm/
- Docker Compose Spec: https://docs.docker.com/compose/compose-file/
- Laravel Deployment: https://laravel.com/docs/deployment

### Monitoring Solutions
- **Portainer**: Web UI for Docker Swarm management
  ```bash
  docker service create \
    --name portainer \
    --publish 9000:9000 \
    --constraint 'node.role == manager' \
    --mount type=bind,src=/var/run/docker.sock,dst=/var/run/docker.sock \
    portainer/portainer-ce:latest
  ```

- **Grafana + Prometheus**: Metrics and dashboards
  - Guide: See MONITORING_SETUP.md (separate document)

### Support
- GitHub Issues: <repository-url>/issues
- Docker Community: https://forums.docker.com/
- Stack Overflow: Tag `docker-swarm`

---

## Conclusion

Docker Swarm provides a production-ready orchestration solution with minimal complexity. For most small to medium deployments, it offers 80% of Kubernetes benefits with 20% of the effort.

**Key advantages:**
- Deployment in under 10 minutes
- Simple management with familiar Docker commands
- Built-in load balancing and service discovery
- Zero-downtime updates
- Cost-effective (no managed K8s fees)

**Next steps:**
1. Complete initial deployment
2. Set up automated backups
3. Configure SSL/TLS
4. Implement monitoring (optional)
5. Document your specific configuration

For questions or issues, refer to the Troubleshooting section or open a GitHub issue.

# Deployment Instructions - Quick Reference

**USF.Sport Integration Platform - Docker Swarm Deployment**

---

## Prerequisites Checklist

Before deployment, ensure you have:

- [ ] Linux server (Ubuntu 20.04+ recommended)
- [ ] Minimum 8 GB RAM, 4 CPU cores
- [ ] 50 GB+ available disk space
- [ ] Docker Engine 20.10+ installed
- [ ] SSH access to the server
- [ ] Firewall configured (ports 80, 443, 15672)

---

## Step-by-Step Deployment

### Step 1: Prepare Your Server

```bash
# Update system
sudo apt update && sudo apt upgrade -y

# Install Docker
curl -fsSL https://get.docker.com -o get-docker.sh
sudo sh get-docker.sh

# Add user to docker group
sudo usermod -aG docker $USER
newgrp docker

# Verify Docker installation
docker --version
```

### Step 2: Clone Repository

```bash
# Clone from GitHub
git clone https://github.com/SadraSamadzadeh/AssignmentGraduation.git
cd AssignmentGraduation

# Make scripts executable
chmod +x swarm-deploy.sh swarm-setup-secrets.sh
```

### Step 3: Initialize Docker Swarm

```bash
# Initialize Swarm and create secrets
./swarm-deploy.sh init
```

**This will:**
- Initialize Docker Swarm on your server
- Create secure random secrets (APP_KEY, db_password, rabbitmq_password)
- Display all created secrets

**Expected Output:**
```
Initializing Docker Swarm...
Swarm initialized successfully!

Creating secrets...
Generate Laravel APP_KEY? (y/N): y
Generated Laravel APP_KEY
Created secret: app_key

Generate PostgreSQL Password? (y/N): y
Generated PostgreSQL Password
Created secret: db_password

Generate RabbitMQ Password? (y/N): y
Generated RabbitMQ Password
Created secret: rabbitmq_password

All secrets created successfully!
```

### Step 4: Deploy Application Stack

```bash
# Deploy all services
./swarm-deploy.sh deploy
```

**This will:**
- Build Docker images for API and workers
- Deploy 6 services (postgres, redis, rabbitmq, api, worker, scheduler)
- Configure networking and persistent volumes
- Set up automatic load balancing

**Monitor deployment:**
```bash
# Watch services come online (wait until all show desired replicas)
watch docker service ls
```

**Expected Output:**
```
ID             NAME                        REPLICAS   IMAGE
abc123         assignmentgraduation_api        3/3    assignmentgraduation-api:latest
def456         assignmentgraduation_worker     5/5    assignmentgraduation-worker:latest
ghi789         assignmentgraduation_scheduler  1/1    assignmentgraduation-worker:latest
jkl012         assignmentgraduation_postgres   1/1    postgres:15-alpine
mno345         assignmentgraduation_redis      1/1    redis:7-alpine
pqr678         assignmentgraduation_rabbitmq   1/1    rabbitmq:3-management
```

### Step 5: Run Database Migrations

```bash
# Run Laravel migrations
./swarm-deploy.sh migrate
```

**Expected Output:**
```
Running database migrations...
Migration table created successfully.
Migrating: 2024_01_01_create_tracking_dashboard_table
Migrated:  2024_01_01_create_tracking_dashboard_table
...
Migrations completed!
```

### Step 6: Verify Deployment

```bash
# Check stack status
./swarm-deploy.sh status

# Test API health endpoint
curl http://localhost/api/health

# Expected response:
# {"status":"healthy","timestamp":"2025-12-12T10:30:00Z"}
```

**Access RabbitMQ Management UI:**
```bash
# Open in browser: http://your-server-ip:15672
# Default login: guest / guest
# IMPORTANT: Change default password in production!
```

---

## Post-Deployment Configuration

### 1. Configure External Webhooks

Point your external systems to send webhooks to:

**Tracking Events:**
```
POST http://your-server-ip/api/webhooks/tracking
```

**Video Events:**
```
POST http://your-server-ip/api/webhooks/video
```

### 2. Set Up SSL/HTTPS (Recommended for Production)

```bash
# Install Certbot
sudo apt install certbot

# Get SSL certificate
sudo certbot certonly --standalone -d yourdomain.com

# Configure Nginx in docker-compose.swarm.yml to use certificates
# See DOCKER_SWARM_GUIDE.md for detailed SSL setup
```

### 3. Configure Firewall

```bash
# Allow HTTP/HTTPS
sudo ufw allow 80/tcp
sudo ufw allow 443/tcp

# Allow SSH (IMPORTANT!)
sudo ufw allow 22/tcp

# Optional: Allow RabbitMQ management (restrict to your IP)
sudo ufw allow from YOUR_IP_ADDRESS to any port 15672

# Enable firewall
sudo ufw enable
```

### 4. Set Up Automated Backups

```bash
# Create backup script
sudo nano /usr/local/bin/backup-db.sh
```

**Paste this content:**
```bash
#!/bin/bash
DATE=$(date +%Y%m%d_%H%M%S)
POSTGRES_CONTAINER=$(docker ps -q -f "name=assignmentgraduation_postgres")
BACKUP_DIR="/backups"

mkdir -p $BACKUP_DIR
docker exec $POSTGRES_CONTAINER pg_dump -U assignment_user assignment_graduation | gzip > $BACKUP_DIR/backup_$DATE.sql.gz

# Keep only last 7 days
find $BACKUP_DIR -name "backup_*.sql.gz" -mtime +7 -delete

echo "Backup completed: backup_$DATE.sql.gz"
```

**Make executable and schedule:**
```bash
sudo chmod +x /usr/local/bin/backup-db.sh

# Add to crontab (runs daily at 2 AM)
crontab -e
# Add this line:
0 2 * * * /usr/local/bin/backup-db.sh >> /var/log/db-backup.log 2>&1
```

---

## Common Operations

### Scaling Services

**Scale API servers:**
```bash
# Increase to 10 replicas for high traffic
docker service scale assignmentgraduation_api=10

# Decrease to 3 replicas for normal traffic
docker service scale assignmentgraduation_api=3
```

**Scale queue workers:**
```bash
# Increase to 15 workers during match days
docker service scale assignmentgraduation_worker=15

# Decrease to 5 workers during low activity
docker service scale assignmentgraduation_worker=5
```

**Interactive scaling:**
```bash
./swarm-deploy.sh scale
```

### Viewing Logs

```bash
# View API logs (last 100 lines, follow)
docker service logs assignmentgraduation_api --tail 100 -f

# View worker logs
docker service logs assignmentgraduation_worker --tail 100 -f

# View specific service logs
./swarm-deploy.sh logs
```

### Updating Application

```bash
# After making code changes, deploy new version
./swarm-deploy.sh update v1.0.1

# Services will update with zero downtime (rolling update)
```

### Rollback

```bash
# If update causes issues, rollback
./swarm-deploy.sh rollback
```

### Restart Services

```bash
# Restart specific service
docker service update --force assignmentgraduation_api

# Restart all services (redeploy)
./swarm-deploy.sh update
```

### Clear Application Cache

```bash
# Get API container
container=$(docker ps -q -f "name=assignmentgraduation_api" | head -n 1)

# Clear cache
docker exec $container php artisan cache:clear
docker exec $container php artisan config:clear
docker exec $container php artisan route:clear
docker exec $container php artisan view:clear
```

### Database Operations

**Run migrations:**
```bash
./swarm-deploy.sh migrate
```

**Access database directly:**
```bash
# Get PostgreSQL container
container=$(docker ps -q -f "name=assignmentgraduation_postgres")

# Connect to database
docker exec -it $container psql -U assignment_user assignment_graduation

# Inside psql:
# \dt              - List tables
# \d+ table_name   - Describe table
# SELECT * FROM global_matches LIMIT 10;
# \q               - Quit
```

**Manual backup:**
```bash
POSTGRES_CONTAINER=$(docker ps -q -f "name=assignmentgraduation_postgres")
docker exec $POSTGRES_CONTAINER pg_dump -U assignment_user assignment_graduation | gzip > backup_$(date +%Y%m%d).sql.gz
```

**Restore from backup:**
```bash
gunzip -c backup_20251212.sql.gz | docker exec -i $POSTGRES_CONTAINER psql -U assignment_user assignment_graduation
```

---

## Monitoring

### Service Status

```bash
# Check all services
./swarm-deploy.sh status

# List services with replicas
docker service ls

# Detailed service info
docker service ps assignmentgraduation_api

# Resource usage
docker stats
```

### RabbitMQ Monitoring

**Web UI:**
- URL: http://your-server-ip:15672
- Login: guest / guest (change in production!)

**Command line:**
```bash
container=$(docker ps -q -f "name=assignmentgraduation_rabbitmq")

# List queues
docker exec $container rabbitmqctl list_queues

# List connections
docker exec $container rabbitmqctl list_connections

# Check status
docker exec $container rabbitmqctl status
```

### Health Checks

```bash
# API health
curl http://localhost/api/health

# PostgreSQL health
container=$(docker ps -q -f "name=assignmentgraduation_postgres")
docker exec $container pg_isready -U assignment_user

# Redis health
container=$(docker ps -q -f "name=assignmentgraduation_redis")
docker exec $container redis-cli ping
```

---

## Troubleshooting

### Service Won't Start

1. **Check logs:**
   ```bash
   docker service ps assignmentgraduation_api --no-trunc
   docker service logs assignmentgraduation_api
   ```

2. **Check secrets exist:**
   ```bash
   docker secret ls
   ```

3. **Check resource limits:**
   ```bash
   docker stats
   free -h
   df -h
   ```

### Database Connection Errors

1. **Verify PostgreSQL is running:**
   ```bash
   docker service ps assignmentgraduation_postgres
   ```

2. **Check connection:**
   ```bash
   container=$(docker ps -q -f "name=assignmentgraduation_api" | head -n 1)
   docker exec $container nc -zv postgres 5432
   ```

3. **Check logs:**
   ```bash
   docker service logs assignmentgraduation_postgres
   ```

### Queue Jobs Not Processing

1. **Check worker logs:**
   ```bash
   docker service logs assignmentgraduation_worker -f
   ```

2. **Check RabbitMQ queue depth:**
   ```bash
   container=$(docker ps -q -f "name=assignmentgraduation_rabbitmq")
   docker exec $container rabbitmqctl list_queues
   ```

3. **Scale workers if queue is backed up:**
   ```bash
   docker service scale assignmentgraduation_worker=10
   ```

### High Memory/CPU Usage

1. **Check resource usage:**
   ```bash
   docker stats
   ```

2. **Identify problematic service:**
   ```bash
   docker service ps assignmentgraduation_api
   ```

3. **Increase resource limits** in `docker-compose.swarm.yml`:
   ```yaml
   deploy:
     resources:
       limits:
         memory: 1G
         cpus: '2.0'
   ```

4. **Redeploy:**
   ```bash
   ./swarm-deploy.sh update
   ```

---

## Removal

To completely remove the application:

```bash
# Remove stack
./swarm-deploy.sh remove

# Optional: Remove volumes (WARNING: This deletes all data!)
docker volume ls | grep assignmentgraduation
docker volume rm assignmentgraduation_postgres_data
docker volume rm assignmentgraduation_redis_data
docker volume rm assignmentgraduation_rabbitmq_data

# Optional: Leave Swarm
docker swarm leave --force
```

---

## Support

For detailed documentation, see:
- **README.md** - Complete project documentation
- **DOCKER_SWARM_GUIDE.md** - Comprehensive deployment guide
- **docs/diagrams/** - Architecture diagrams

For issues or questions:
- GitHub Issues: https://github.com/SadraSamadzadeh/AssignmentGraduation/issues

---

**Deployment complete!** Your application is now running on Docker Swarm.

**Quick status check:**
```bash
./swarm-deploy.sh status
curl http://localhost/api/health
```

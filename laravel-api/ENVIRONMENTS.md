# Environment Configuration

## Overview
This application now supports two distinct environments:

### 🏠 Development (Local)
- Uses **Docker Compose** - Simple, no Kubernetes needed
- All services run in containers on your machine
- Easy debugging with exposed ports
- Fast startup and teardown

### 🚀 Production
- Uses **Kubernetes** for orchestration
- Scalable, resilient, production-ready
- Managed secrets and config
- Load balancing and auto-scaling

---

## Quick Start

### Development
```powershell
# Windows
.\deploy-local.ps1 up

# Linux/Mac
./deploy-local.sh up
```

### Production
```powershell
# Windows
.\deploy-production.ps1 apply

# Linux/Mac
./deploy-production.sh apply
```

---

## Environment Files

| File | Purpose |
|------|---------|
| `.env.local` | Development configuration (Docker Compose) |
| `.env.production` | Production configuration (Kubernetes) |
| `.env.example` | Template with all available variables |

**Note:** Never commit `.env` files with real credentials!

---

## Development Environment

### What You Get
✅ PostgreSQL database (port 5432)  
✅ Redis cache (port 6379)  
✅ RabbitMQ message queue (ports 5672, 15672)  
✅ Laravel API (port 80)  
✅ Queue workers  
✅ Scheduler (cron)  

### Access URLs
- API: http://localhost
- RabbitMQ UI: http://localhost:15672 (guest/rabbitmq_password_123)
- Database: localhost:5432

### Commands
```powershell
# Start everything
.\deploy-local.ps1 up

# Stop everything
.\deploy-local.ps1 down

# View logs
.\deploy-local.ps1 logs

# Restart
.\deploy-local.ps1 restart

# Clean up (removes all data)
.\deploy-local.ps1 clean
```

---

## Production Environment

### What You Get
✅ Kubernetes Deployments for all services  
✅ Persistent storage for database  
✅ ConfigMaps and Secrets management  
✅ Health checks and auto-restart  
✅ Migration jobs  
✅ Scalable worker pods  

### Prerequisites
- Kubernetes cluster (AKS, EKS, GKE, Minikube)
- kubectl installed and configured
- Docker registry access

### Before First Deploy
1. **Update secrets** in `k8s/secrets.yaml`
2. **Build image**: `docker build -t your-registry/api:latest .`
3. **Push image**: `docker push your-registry/api:latest`
4. **Update image names** in `k8s/*.yaml` files

### Commands
```powershell
# Deploy everything
.\deploy-production.ps1 apply

# Check status
.\deploy-production.ps1 status

# View logs
.\deploy-production.ps1 logs

# Remove everything
.\deploy-production.ps1 delete
```

---

## Key Differences

| Aspect | Development | Production |
|--------|-------------|------------|
| Orchestration | Docker Compose | Kubernetes |
| Debug Mode | Enabled | Disabled |
| Log Level | debug | warning |
| Secrets | In .env file | Kubernetes Secrets |
| Scaling | Single instance | Multiple replicas |
| Persistence | Docker volumes | Persistent Volumes |
| Networking | Docker network | K8s Services |

---

## File Structure

```
laravel-api/
├── .env.local              # Dev environment config
├── .env.production         # Prod environment config
├── docker-compose.yml      # Dev orchestration
├── deploy-local.ps1        # Dev deployment script (Windows)
├── deploy-local.sh         # Dev deployment script (Linux/Mac)
├── deploy-production.ps1   # Prod deployment script (Windows)
├── deploy-production.sh    # Prod deployment script (Linux/Mac)
├── DEPLOYMENT.md           # Detailed deployment guide
├── k8s/                    # Kubernetes manifests
│   ├── namespace.yaml
│   ├── secrets.yaml
│   ├── configmap.yaml
│   ├── postgres.yaml
│   ├── redis.yaml
│   ├── rabbitmq.yaml
│   ├── api.yaml
│   ├── worker.yaml
│   ├── scheduler.yaml
│   └── job-migrate.yaml
└── ...
```

---

## Migration Guide

### Existing Projects
If you're migrating from an existing setup:

1. **Copy your current .env to .env.local**:
   ```powershell
   Copy-Item .env .env.local
   ```

2. **Update .env.local** with Docker Compose service names:
   - `DB_HOST=postgres`
   - `REDIS_HOST=redis`
   - `RABBITMQ_HOST=rabbitmq`

3. **Start development environment**:
   ```powershell
   .\deploy-local.ps1 up
   ```

### For Production
1. **Create .env.production** based on your needs
2. **Update Kubernetes secrets** with real credentials
3. **Build and push your Docker image**
4. **Deploy**: `.\deploy-production.ps1 apply`

---

## Troubleshooting

### "Services won't start"
```powershell
# Check Docker is running
docker ps

# View logs
docker-compose logs

# Rebuild
docker-compose build --no-cache
```

### "Can't connect to database"
```powershell
# Check if PostgreSQL is running
docker-compose ps postgres

# Check logs
docker-compose logs postgres
```

### "Kubernetes pods failing"
```powershell
# Check pod status
kubectl get pods -n assignment-graduation

# View logs
kubectl logs <pod-name> -n assignment-graduation

# Describe pod
kubectl describe pod <pod-name> -n assignment-graduation
```

---

## Next Steps

1. ✅ Review `.env.local` and adjust as needed
2. ✅ Run `.\deploy-local.ps1 up` to test locally
3. ✅ Update `k8s/secrets.yaml` for production
4. ✅ Build and push Docker image
5. ✅ Deploy to Kubernetes

For detailed instructions, see **DEPLOYMENT.md**.

---

## Support

- Docker Compose docs: https://docs.docker.com/compose/
- Kubernetes docs: https://kubernetes.io/docs/
- Laravel deployment: https://laravel.com/docs/deployment

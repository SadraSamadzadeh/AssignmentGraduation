# ============================================================================
# DEPLOYMENT GUIDE
# ============================================================================

## Overview
This application supports two deployment environments:
- **Development**: Uses Docker Compose (no Kubernetes)
- **Production**: Uses Kubernetes

## Environment Files
- `.env.local` - Local development configuration
- `.env.production` - Production Kubernetes configuration
- `.env.example` - Template for all environments

---

## Development Environment (Docker Compose)

### Prerequisites
- Docker Desktop installed
- Docker Compose installed

### Quick Start

**Windows (PowerShell):**
```powershell
.\deploy-local.ps1 up
```

**Linux/Mac (Bash):**
```bash
chmod +x deploy-local.sh
./deploy-local.sh up
```

### Available Commands

| Command | Description |
|---------|-------------|
| `up` or `start` | Start all services |
| `down` or `stop` | Stop all services |
| `restart` | Restart all services |
| `logs` | View logs (follow mode) |
| `clean` | Remove all containers and volumes |

### Access Points
- **API**: http://localhost
- **RabbitMQ Management**: http://localhost:15672
  - Username: `guest`
  - Password: `rabbitmq_password_123`
- **PostgreSQL**: `localhost:5432`
  - Database: `assignment_graduation`
  - Username: `assignment_user`
  - Password: `postgres_password_123`
- **Redis**: `localhost:6379`
  - Password: `redis_password_123`

### Manual Docker Compose
```bash
# Copy environment file
cp .env.local .env

# Start services
docker-compose up -d

# Run migrations
docker-compose exec api php artisan migrate

# View logs
docker-compose logs -f

# Stop services
docker-compose down
```

---

## Production Environment (Kubernetes)

### Prerequisites
- Kubernetes cluster (AKS, EKS, GKE, or local like Minikube)
- kubectl configured and connected to your cluster
- Docker registry for images (Docker Hub, ACR, ECR, etc.)

### Before Deployment

1. **Update Kubernetes Secrets** (`k8s/secrets.yaml`):
   - Generate a new `APP_KEY`: `php artisan key:generate --show`
   - Update database credentials
   - Update Redis password
   - Update RabbitMQ credentials

2. **Build and Push Docker Image**:
   ```bash
   docker build -t your-registry/assignment-api:latest .
   docker push your-registry/assignment-api:latest
   ```

3. **Update Image References** in Kubernetes manifests:
   - `k8s/api.yaml`
   - `k8s/worker.yaml`
   - `k8s/scheduler.yaml`
   - `k8s/job-migrate.yaml`

### Deploy to Kubernetes

**Windows (PowerShell):**
```powershell
.\deploy-production.ps1 apply
```

**Linux/Mac (Bash):**
```bash
chmod +x deploy-production.sh
./deploy-production.sh apply
```

### Available Commands

| Command | Description |
|---------|-------------|
| `apply` or `deploy` | Deploy all resources to Kubernetes |
| `delete` or `remove` | Remove all resources from Kubernetes |
| `status` | Check deployment status |
| `logs` | View API pod logs |

### Manual Kubernetes Deployment
```bash
# Create namespace
kubectl apply -f k8s/namespace.yaml

# Apply secrets and config
kubectl apply -f k8s/secrets.yaml
kubectl apply -f k8s/configmap.yaml

# Deploy infrastructure
kubectl apply -f k8s/postgres.yaml
kubectl apply -f k8s/redis.yaml
kubectl apply -f k8s/rabbitmq.yaml

# Wait for infrastructure to be ready
kubectl wait --for=condition=ready pod -l app=postgres -n assignment-graduation --timeout=120s

# Run migrations
kubectl apply -f k8s/job-migrate.yaml
kubectl wait --for=condition=complete job/api-migrate -n assignment-graduation --timeout=300s

# Deploy application
kubectl apply -f k8s/api.yaml
kubectl apply -f k8s/worker.yaml
kubectl apply -f k8s/scheduler.yaml

# Check status
kubectl get all -n assignment-graduation
```

### Accessing Production Services
```bash
# Get external IP/LoadBalancer
kubectl get services -n assignment-graduation

# Port-forward for testing
kubectl port-forward -n assignment-graduation svc/api-service 8080:80

# View logs
kubectl logs -f -l app=api -n assignment-graduation
```

---

## Environment Variables

### Key Differences

| Variable | Local (Docker Compose) | Production (Kubernetes) |
|----------|------------------------|-------------------------|
| `APP_ENV` | `local` | `production` |
| `APP_DEBUG` | `true` | `false` |
| `DB_HOST` | `postgres` | Service name from K8s |
| `REDIS_HOST` | `redis` | Service name from K8s |
| `RABBITMQ_HOST` | `rabbitmq` | Service name from K8s |
| `LOG_LEVEL` | `debug` | `warning` |

---

## Troubleshooting

### Development Issues

**Services won't start:**
```bash
# Check Docker is running
docker ps

# View detailed logs
docker-compose logs

# Rebuild containers
docker-compose build --no-cache
docker-compose up -d
```

**Database connection issues:**
```bash
# Ensure PostgreSQL is ready
docker-compose ps postgres

# Connect to database
docker-compose exec postgres psql -U assignment_user -d assignment_graduation
```

### Production Issues

**Pods not starting:**
```bash
# Check pod status
kubectl describe pod <pod-name> -n assignment-graduation

# View pod logs
kubectl logs <pod-name> -n assignment-graduation

# Check events
kubectl get events -n assignment-graduation --sort-by='.lastTimestamp'
```

**ImagePullBackOff:**
- Verify image exists in registry
- Check image pull secrets
- Verify image name in manifests

**Database migrations failing:**
```bash
# Check migration job logs
kubectl logs job/api-migrate -n assignment-graduation

# Manually run migrations
kubectl exec -it <api-pod> -n assignment-graduation -- php artisan migrate --force
```

---

## CI/CD Integration

### GitHub Actions Example
```yaml
name: Deploy to Production

on:
  push:
    branches: [main]

jobs:
  deploy:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v3
      
      - name: Build Docker Image
        run: docker build -t ${{ secrets.REGISTRY }}/api:${{ github.sha }} .
      
      - name: Push to Registry
        run: docker push ${{ secrets.REGISTRY }}/api:${{ github.sha }}
      
      - name: Deploy to Kubernetes
        run: |
          kubectl set image deployment/api api=${{ secrets.REGISTRY }}/api:${{ github.sha }} -n assignment-graduation
```

---

## Switching Between Environments

**From Development to Production:**
1. Ensure all code is committed
2. Build and push Docker image
3. Update Kubernetes manifests with new image tag
4. Run `deploy-production.sh apply`

**From Production to Development:**
1. Pull latest code
2. Run `deploy-local.sh up`

---

## Security Notes

### Development
- Uses simple passwords (not for production!)
- Debug mode enabled
- All ports exposed to host

### Production
- Use strong passwords in Kubernetes secrets
- Debug mode disabled
- Use TLS/SSL certificates
- Implement network policies
- Use image scanning
- Enable RBAC
- Use secrets management (Azure Key Vault, AWS Secrets Manager, etc.)

---

## Additional Resources

- [Docker Compose Documentation](https://docs.docker.com/compose/)
- [Kubernetes Documentation](https://kubernetes.io/docs/)
- [Laravel Deployment](https://laravel.com/docs/deployment)

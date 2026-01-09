# ============================================================================
# Kubernetes Production Deployment Script (PowerShell)
# ============================================================================
# Usage:
#   .\deploy-production.ps1 apply     # Deploy to Kubernetes
#   .\deploy-production.ps1 delete    # Remove from Kubernetes
#   .\deploy-production.ps1 status    # Check deployment status
# ============================================================================

param(
    [Parameter(Position=0)]
    [ValidateSet('apply', 'delete', 'status', 'logs', 'deploy', 'remove')]
    [string]$Command = 'apply'
)

# Configuration
$Namespace = "assignment-graduation"
$K8sDir = "k8s"

function Write-Header {
    param([string]$Message)
    Write-Host "============================================" -ForegroundColor Blue
    Write-Host $Message -ForegroundColor Blue
    Write-Host "============================================" -ForegroundColor Blue
}

function Write-Success {
    param([string]$Message)
    Write-Host "✓ $Message" -ForegroundColor Green
}

function Write-Warning {
    param([string]$Message)
    Write-Host "⚠ $Message" -ForegroundColor Yellow
}

function Write-Error {
    param([string]$Message)
    Write-Host "✗ $Message" -ForegroundColor Red
}

function Test-Kubectl {
    if (-not (Get-Command kubectl -ErrorAction SilentlyContinue)) {
        Write-Error "kubectl not found! Please install kubectl first."
        exit 1
    }
}

function Test-K8sFiles {
    if (-not (Test-Path $K8sDir)) {
        Write-Error "Kubernetes manifests directory ($K8sDir) not found!"
        exit 1
    }
}

switch ($Command) {
    { $_ -in 'apply', 'deploy' } {
        Write-Header "Deploying to Kubernetes (Production)"
        Test-Kubectl
        Test-K8sFiles
        
        # Create namespace
        Write-Success "Creating namespace..."
        kubectl apply -f "$K8sDir/namespace.yaml"
        
        # Apply secrets and configmaps
        Write-Success "Applying secrets and configmaps..."
        kubectl apply -f "$K8sDir/secrets.yaml"
        kubectl apply -f "$K8sDir/configmap.yaml"
        
        # Apply infrastructure
        Write-Success "Deploying infrastructure (PostgreSQL, Redis, RabbitMQ)..."
        kubectl apply -f "$K8sDir/postgres.yaml"
        kubectl apply -f "$K8sDir/redis.yaml"
        kubectl apply -f "$K8sDir/rabbitmq.yaml"
        
        # Wait for infrastructure
        Write-Success "Waiting for infrastructure to be ready..."
        kubectl wait --for=condition=ready pod -l app=postgres -n $Namespace --timeout=120s
        kubectl wait --for=condition=ready pod -l app=redis -n $Namespace --timeout=120s
        kubectl wait --for=condition=ready pod -l app=rabbitmq -n $Namespace --timeout=120s
        
        # Run migrations
        Write-Success "Running database migrations..."
        kubectl apply -f "$K8sDir/job-migrate.yaml"
        kubectl wait --for=condition=complete job/api-migrate -n $Namespace --timeout=300s
        
        # Deploy application
        Write-Success "Deploying application services..."
        kubectl apply -f "$K8sDir/api.yaml"
        kubectl apply -f "$K8sDir/worker.yaml"
        kubectl apply -f "$K8sDir/scheduler.yaml"
        
        Write-Host ""
        Write-Header "Deployment Complete!"
        Write-Host ""
        kubectl get all -n $Namespace
    }
    
    { $_ -in 'delete', 'remove' } {
        Write-Header "Removing from Kubernetes"
        Test-Kubectl
        
        Write-Warning "This will delete all resources in namespace: $Namespace"
        $response = Read-Host "Are you sure? (yes/no)"
        if ($response -match '^[Yy]es$') {
            kubectl delete namespace $Namespace
            Write-Success "Resources removed"
        } else {
            Write-Warning "Deletion cancelled"
        }
    }
    
    'status' {
        Write-Header "Deployment Status"
        Test-Kubectl
        
        Write-Host ""
        Write-Host "Namespace:"
        kubectl get namespace $Namespace 2>$null
        if ($LASTEXITCODE -ne 0) { Write-Host "Namespace not found" }
        
        Write-Host ""
        Write-Host "Pods:"
        kubectl get pods -n $Namespace 2>$null
        if ($LASTEXITCODE -ne 0) { Write-Host "No pods found" }
        
        Write-Host ""
        Write-Host "Services:"
        kubectl get services -n $Namespace 2>$null
        if ($LASTEXITCODE -ne 0) { Write-Host "No services found" }
        
        Write-Host ""
        Write-Host "Deployments:"
        kubectl get deployments -n $Namespace 2>$null
        if ($LASTEXITCODE -ne 0) { Write-Host "No deployments found" }
    }
    
    'logs' {
        Write-Header "Application Logs"
        Test-Kubectl
        
        $pod = kubectl get pods -n $Namespace -l app=api -o jsonpath='{.items[0].metadata.name}' 2>$null
        if ([string]::IsNullOrEmpty($pod)) {
            Write-Error "No API pod found"
            exit 1
        }
        
        kubectl logs -f $pod -n $Namespace
    }
    
    default {
        Write-Host "Usage: .\deploy-production.ps1 {apply|delete|status|logs}"
        Write-Host ""
        Write-Host "Commands:"
        Write-Host "  apply    - Deploy to Kubernetes"
        Write-Host "  delete   - Remove from Kubernetes"
        Write-Host "  status   - Check deployment status"
        Write-Host "  logs     - View API logs"
        exit 1
    }
}

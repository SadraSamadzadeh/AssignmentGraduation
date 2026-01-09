# ============================================================================
# Local Development Deployment Script (PowerShell)
# ============================================================================
# Usage:
#   .\deploy-local.ps1        # Start local development environment
#   .\deploy-local.ps1 down   # Stop local development environment
#   .\deploy-local.ps1 logs   # View logs
# ============================================================================

param(
    [Parameter(Position=0)]
    [ValidateSet('up', 'down', 'restart', 'logs', 'clean', 'start', 'stop')]
    [string]$Command = 'up'
)

# Configuration
$EnvFile = ".env.local"
$ComposeFile = "docker-compose.yml"

# Functions
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

# Check if .env.local exists
function Test-EnvFile {
    if (-not (Test-Path $EnvFile)) {
        Write-Error "Environment file $EnvFile not found!"
        Write-Host "Please create it first or copy from .env.example"
        exit 1
    }
}

# Main commands
switch ($Command) {
    { $_ -in 'up', 'start' } {
        Write-Header "Starting Local Development Environment"
        Test-EnvFile
        
        # Copy .env.local to .env
        Write-Success "Loading local environment configuration..."
        Copy-Item $EnvFile .env -Force
        
        # Start Docker Compose
        Write-Success "Starting Docker containers..."
        docker-compose -f $ComposeFile up -d
        
        if ($LASTEXITCODE -ne 0) {
            Write-Error "Failed to start Docker containers"
            exit 1
        }
        
        # Wait for services to be healthy
        Write-Host ""
        Write-Success "Waiting for services to be ready..."
        Start-Sleep -Seconds 10
        
        # Run migrations
        Write-Success "Running database migrations..."
        docker-compose exec -T api php artisan migrate --force
        
        Write-Host ""
        Write-Header "Development Environment Ready!"
        Write-Host ""
        Write-Host "API:              http://localhost"
        Write-Host "RabbitMQ UI:      http://localhost:15672 (guest/rabbitmq_password_123)"
        Write-Host "Database:         localhost:5432 (assignment_graduation)"
        Write-Host "Redis:            localhost:6379"
        Write-Host ""
        Write-Success "Use 'docker-compose logs -f' to view logs"
    }
    
    { $_ -in 'down', 'stop' } {
        Write-Header "Stopping Local Development Environment"
        docker-compose -f $ComposeFile down
        Write-Success "Environment stopped"
    }
    
    'restart' {
        Write-Header "Restarting Local Development Environment"
        docker-compose -f $ComposeFile restart
        Write-Success "Environment restarted"
    }
    
    'logs' {
        docker-compose -f $ComposeFile logs -f
    }
    
    'clean' {
        Write-Header "Cleaning Up Development Environment"
        Write-Warning "This will remove all containers, volumes, and data!"
        $response = Read-Host "Are you sure? (yes/no)"
        if ($response -match '^[Yy]es$') {
            docker-compose -f $ComposeFile down -v
            if (Test-Path .env) {
                Remove-Item .env -Force
            }
            Write-Success "Environment cleaned"
        } else {
            Write-Warning "Cleanup cancelled"
        }
    }
    
    default {
        Write-Host "Usage: .\deploy-local.ps1 {up|down|restart|logs|clean}"
        Write-Host ""
        Write-Host "Commands:"
        Write-Host "  up       - Start development environment"
        Write-Host "  down     - Stop development environment"
        Write-Host "  restart  - Restart all containers"
        Write-Host "  logs     - View container logs"
        Write-Host "  clean    - Remove all containers and volumes"
        exit 1
    }
}

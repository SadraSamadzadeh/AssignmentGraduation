#!/bin/bash

# ============================================================================
# Environment Switcher for Local Development
# ============================================================================
# Usage:
#   ./deploy-local.sh        # Start local development environment
#   ./deploy-local.sh down   # Stop local development environment
#   ./deploy-local.sh logs   # View logs
# ============================================================================

set -e

# Colors for output
GREEN='\033[0;32m'
BLUE='\033[0;34m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
NC='\033[0m' # No Color

# Configuration
ENV_FILE=".env.local"
COMPOSE_FILE="docker-compose.yml"

# Functions
print_header() {
    echo -e "${BLUE}============================================${NC}"
    echo -e "${BLUE}$1${NC}"
    echo -e "${BLUE}============================================${NC}"
}

print_success() {
    echo -e "${GREEN}✓ $1${NC}"
}

print_warning() {
    echo -e "${YELLOW}⚠ $1${NC}"
}

print_error() {
    echo -e "${RED}✗ $1${NC}"
}

# Check if .env.local exists
check_env_file() {
    if [ ! -f "$ENV_FILE" ]; then
        print_error "Environment file $ENV_FILE not found!"
        echo "Please create it first or copy from .env.example"
        exit 1
    fi
}

# Main commands
case "${1:-up}" in
    up|start)
        print_header "Starting Local Development Environment"
        check_env_file
        
        # Copy .env.local to .env
        print_success "Loading local environment configuration..."
        cp "$ENV_FILE" .env
        
        # Start Docker Compose
        print_success "Starting Docker containers..."
        docker-compose -f "$COMPOSE_FILE" up -d
        
        # Wait for services to be healthy
        echo ""
        print_success "Waiting for services to be ready..."
        sleep 10
        
        # Run migrations
        print_success "Running database migrations..."
        docker-compose exec -T api php artisan migrate --force
        
        echo ""
        print_header "Development Environment Ready!"
        echo ""
        echo "API:              http://localhost"
        echo "RabbitMQ UI:      http://localhost:15672 (guest/rabbitmq_password_123)"
        echo "Database:         localhost:5432 (assignment_graduation)"
        echo "Redis:            localhost:6379"
        echo ""
        print_success "Use 'docker-compose logs -f' to view logs"
        ;;
        
    down|stop)
        print_header "Stopping Local Development Environment"
        docker-compose -f "$COMPOSE_FILE" down
        print_success "Environment stopped"
        ;;
        
    restart)
        print_header "Restarting Local Development Environment"
        docker-compose -f "$COMPOSE_FILE" restart
        print_success "Environment restarted"
        ;;
        
    logs)
        docker-compose -f "$COMPOSE_FILE" logs -f
        ;;
        
    clean)
        print_header "Cleaning Up Development Environment"
        print_warning "This will remove all containers, volumes, and data!"
        read -p "Are you sure? (yes/no): " -r
        if [[ $REPLY =~ ^[Yy]es$ ]]; then
            docker-compose -f "$COMPOSE_FILE" down -v
            rm -f .env
            print_success "Environment cleaned"
        else
            print_warning "Cleanup cancelled"
        fi
        ;;
        
    *)
        echo "Usage: $0 {up|down|restart|logs|clean}"
        echo ""
        echo "Commands:"
        echo "  up       - Start development environment"
        echo "  down     - Stop development environment"
        echo "  restart  - Restart all containers"
        echo "  logs     - View container logs"
        echo "  clean    - Remove all containers and volumes"
        exit 1
        ;;
esac

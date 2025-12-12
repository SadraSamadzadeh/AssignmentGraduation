#!/bin/bash

# Docker Swarm Deployment Script
# Manages deployment, updates, and operations for the application

set -e

STACK_NAME="assignmentgraduation"
COMPOSE_FILE="docker-compose.swarm.yml"

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Function to print colored output
print_success() { echo -e "${GREEN}$1${NC}"; }
print_error() { echo -e "${RED}$1${NC}"; }
print_warning() { echo -e "${YELLOW}$1${NC}"; }
print_info() { echo -e "$1"; }

# Check if Swarm is initialized
check_swarm() {
    if ! docker info | grep -q "Swarm: active"; then
        print_error "ERROR: Docker Swarm is not initialized!"
        echo "Run: docker swarm init"
        exit 1
    fi
}

# Build Docker images
build_images() {
    local version=${1:-latest}
    
    print_info "Building Docker images (version: ${version})..."
    
    # Build API image
    docker build \
        -f laravel-api/Dockerfile.production \
        -t assignmentgraduation-api:${version} \
        laravel-api/
    
    # Build Worker image
    docker build \
        -f laravel-api/Dockerfile.worker \
        -t assignmentgraduation-worker:${version} \
        laravel-api/
    
    print_success "Images built successfully!"
}

# Deploy stack
deploy_stack() {
    check_swarm
    
    print_info "Deploying stack '${STACK_NAME}'..."
    
    docker stack deploy -c ${COMPOSE_FILE} ${STACK_NAME}
    
    print_success "Stack deployed successfully!"
    print_info ""
    print_info "Run 'docker service ls' to see all services"
    print_info "Run './swarm-deploy.sh status' to check stack status"
    print_info ""
    print_warning "Don't forget to run database migrations:"
    print_info "./swarm-deploy.sh migrate"
}

# Update stack with new version
update_stack() {
    local version=${1:-latest}
    
    check_swarm
    
    print_info "Updating stack '${STACK_NAME}' to version ${version}..."
    
    # Build new images
    build_images ${version}
    
    # Update services with new images
    docker service update --image assignmentgraduation-api:${version} ${STACK_NAME}_api
    docker service update --image assignmentgraduation-worker:${version} ${STACK_NAME}_worker
    
    print_success "Stack updated successfully!"
    print_info "Services will be updated with rolling updates (zero downtime)"
}

# Scale services
scale_services() {
    check_swarm
    
    echo "Current service replicas:"
    docker service ls --filter "name=${STACK_NAME}" --format "table {{.Name}}\t{{.Replicas}}"
    echo ""
    
    read -p "Service to scale (api/worker/scheduler): " service
    read -p "Number of replicas: " replicas
    
    if [[ ! "$replicas" =~ ^[0-9]+$ ]]; then
        print_error "ERROR: Invalid number of replicas"
        exit 1
    fi
    
    docker service scale ${STACK_NAME}_${service}=${replicas}
    print_success "Service scaled successfully!"
}

# Rollback service
rollback_service() {
    check_swarm
    
    read -p "Service to rollback (api/worker/scheduler): " service
    
    docker service rollback ${STACK_NAME}_${service}
    print_success "Service rolled back successfully!"
}

# Show stack status
show_status() {
    check_swarm
    
    print_info "Stack Status:"
    docker stack ps ${STACK_NAME}
    
    echo ""
    print_info "Service Status:"
    docker service ls --filter "name=${STACK_NAME}"
}

# Show logs
show_logs() {
    check_swarm
    
    read -p "Service to show logs (api/worker/scheduler/postgres/redis/rabbitmq): " service
    read -p "Number of lines (default: 100): " lines
    lines=${lines:-100}
    
    docker service logs --tail ${lines} --follow ${STACK_NAME}_${service}
}

# Run migrations
run_migrations() {
    check_swarm
    
    print_info "Running database migrations..."
    
    # Get one of the API containers
    container=$(docker ps -q -f "name=${STACK_NAME}_api" | head -n 1)
    
    if [ -z "$container" ]; then
        print_error "ERROR: No API container found!"
        exit 1
    fi
    
    docker exec -it $container php artisan migrate --force
    
    print_success "Migrations completed!"
}

# Initialize Swarm and create secrets
init_swarm() {
    print_info "Initializing Docker Swarm..."
    
    if docker info | grep -q "Swarm: active"; then
        print_warning "Swarm is already initialized"
    else
        docker swarm init
        print_success "Swarm initialized successfully!"
    fi
    
    echo ""
    print_info "Creating secrets..."
    ./swarm-setup-secrets.sh
}

# Remove stack
remove_stack() {
    check_swarm
    
    read -p "Are you sure you want to remove the stack? (y/N): " confirm
    if [[ ! $confirm =~ ^[Yy]$ ]]; then
        print_info "Aborted"
        exit 0
    fi
    
    print_warning "Removing stack '${STACK_NAME}'..."
    docker stack rm ${STACK_NAME}
    
    print_success "Stack removed successfully!"
    print_warning "Note: Volumes are not removed. To remove volumes:"
    print_info "docker volume ls | grep ${STACK_NAME}"
    print_info "docker volume rm <volume_name>"
}

# Show help
show_help() {
    cat << EOF
Docker Swarm Deployment Script

Usage: ./swarm-deploy.sh [command] [options]

Commands:
    init                Initialize Swarm and create secrets
    deploy              Deploy the stack
    update [version]    Update stack with new version (default: latest)
    scale               Scale services interactively
    rollback            Rollback a service to previous version
    status              Show stack and service status
    logs                Show service logs
    migrate             Run database migrations
    remove              Remove the entire stack
    help                Show this help message

Examples:
    ./swarm-deploy.sh init
    ./swarm-deploy.sh deploy
    ./swarm-deploy.sh update v1.0.0
    ./swarm-deploy.sh scale
    ./swarm-deploy.sh migrate
    ./swarm-deploy.sh status

EOF
}

# Main script
case "$1" in
    init)
        init_swarm
        ;;
    deploy)
        build_images latest
        deploy_stack
        ;;
    update)
        update_stack ${2:-latest}
        ;;
    scale)
        scale_services
        ;;
    rollback)
        rollback_service
        ;;
    status)
        show_status
        ;;
    logs)
        show_logs
        ;;
    migrate)
        run_migrations
        ;;
    remove)
        remove_stack
        ;;
    help|--help|-h)
        show_help
        ;;
    *)
        print_error "Unknown command: $1"
        echo ""
        show_help
        exit 1
        ;;
esac

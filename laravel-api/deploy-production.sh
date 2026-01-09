#!/bin/bash

# ============================================================================
# Kubernetes Production Deployment Script
# ============================================================================
# Usage:
#   ./deploy-production.sh apply     # Deploy to Kubernetes
#   ./deploy-production.sh delete    # Remove from Kubernetes
#   ./deploy-production.sh status    # Check deployment status
# ============================================================================

set -e

# Colors
GREEN='\033[0;32m'
BLUE='\033[0;34m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
NC='\033[0m'

# Configuration
NAMESPACE="assignment-graduation"
K8S_DIR="k8s"

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

check_kubectl() {
    if ! command -v kubectl &> /dev/null; then
        print_error "kubectl not found! Please install kubectl first."
        exit 1
    fi
}

check_k8s_files() {
    if [ ! -d "$K8S_DIR" ]; then
        print_error "Kubernetes manifests directory ($K8S_DIR) not found!"
        exit 1
    fi
}

case "${1:-apply}" in
    apply|deploy)
        print_header "Deploying to Kubernetes (Production)"
        check_kubectl
        check_k8s_files
        
        # Create namespace
        print_success "Creating namespace..."
        kubectl apply -f "$K8S_DIR/namespace.yaml"
        
        # Apply secrets and configmaps
        print_success "Applying secrets and configmaps..."
        kubectl apply -f "$K8S_DIR/secrets.yaml"
        kubectl apply -f "$K8S_DIR/configmap.yaml"
        
        # Apply infrastructure
        print_success "Deploying infrastructure (PostgreSQL, Redis, RabbitMQ)..."
        kubectl apply -f "$K8S_DIR/postgres.yaml"
        kubectl apply -f "$K8S_DIR/redis.yaml"
        kubectl apply -f "$K8S_DIR/rabbitmq.yaml"
        
        # Wait for infrastructure
        print_success "Waiting for infrastructure to be ready..."
        kubectl wait --for=condition=ready pod -l app=postgres -n "$NAMESPACE" --timeout=120s
        kubectl wait --for=condition=ready pod -l app=redis -n "$NAMESPACE" --timeout=120s
        kubectl wait --for=condition=ready pod -l app=rabbitmq -n "$NAMESPACE" --timeout=120s
        
        # Run migrations
        print_success "Running database migrations..."
        kubectl apply -f "$K8S_DIR/job-migrate.yaml"
        kubectl wait --for=condition=complete job/api-migrate -n "$NAMESPACE" --timeout=300s
        
        # Deploy application
        print_success "Deploying application services..."
        kubectl apply -f "$K8S_DIR/api.yaml"
        kubectl apply -f "$K8S_DIR/worker.yaml"
        kubectl apply -f "$K8S_DIR/scheduler.yaml"
        
        echo ""
        print_header "Deployment Complete!"
        echo ""
        kubectl get all -n "$NAMESPACE"
        ;;
        
    delete|remove)
        print_header "Removing from Kubernetes"
        check_kubectl
        
        print_warning "This will delete all resources in namespace: $NAMESPACE"
        read -p "Are you sure? (yes/no): " -r
        if [[ $REPLY =~ ^[Yy]es$ ]]; then
            kubectl delete namespace "$NAMESPACE"
            print_success "Resources removed"
        else
            print_warning "Deletion cancelled"
        fi
        ;;
        
    status)
        print_header "Deployment Status"
        check_kubectl
        
        echo ""
        echo "Namespace:"
        kubectl get namespace "$NAMESPACE" 2>/dev/null || echo "Namespace not found"
        
        echo ""
        echo "Pods:"
        kubectl get pods -n "$NAMESPACE" 2>/dev/null || echo "No pods found"
        
        echo ""
        echo "Services:"
        kubectl get services -n "$NAMESPACE" 2>/dev/null || echo "No services found"
        
        echo ""
        echo "Deployments:"
        kubectl get deployments -n "$NAMESPACE" 2>/dev/null || echo "No deployments found"
        ;;
        
    logs)
        print_header "Application Logs"
        check_kubectl
        
        POD=$(kubectl get pods -n "$NAMESPACE" -l app=api -o jsonpath='{.items[0].metadata.name}' 2>/dev/null)
        if [ -z "$POD" ]; then
            print_error "No API pod found"
            exit 1
        fi
        
        kubectl logs -f "$POD" -n "$NAMESPACE"
        ;;
        
    *)
        echo "Usage: $0 {apply|delete|status|logs}"
        echo ""
        echo "Commands:"
        echo "  apply    - Deploy to Kubernetes"
        echo "  delete   - Remove from Kubernetes"
        echo "  status   - Check deployment status"
        echo "  logs     - View API logs"
        exit 1
        ;;
esac

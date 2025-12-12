#!/bin/bash

# Docker Swarm Secrets Setup Script
# This script creates all necessary secrets for the application

set -e

echo "Setting up Docker Swarm Secrets"
echo ""

# Check if Swarm is initialized
if ! docker info | grep -q "Swarm: active"; then
    echo "ERROR: Docker Swarm is not initialized!"
    echo "Run: docker swarm init"
    exit 1
fi

# Function to create secret from user input
create_secret() {
    local secret_name=$1
    local secret_prompt=$2
    local generate_cmd=$3
    
    # Check if secret already exists
    if docker secret ls --format '{{.Name}}' | grep -q "^${secret_name}$"; then
        echo "WARNING: Secret '${secret_name}' already exists. Skipping..."
        return
    fi
    
    # If generate command provided and user wants to generate
    if [ -n "$generate_cmd" ]; then
        read -p "Generate ${secret_prompt}? (y/N): " generate
        if [[ $generate =~ ^[Yy]$ ]]; then
            secret_value=$(eval "$generate_cmd")
            echo "Generated ${secret_prompt}"
        else
            read -sp "${secret_prompt}: " secret_value
            echo ""
        fi
    else
        read -sp "${secret_prompt}: " secret_value
        echo ""
    fi
    
    # Create secret
    echo "$secret_value" | docker secret create "$secret_name" -
    echo "Created secret: ${secret_name}"
    echo ""
}

# Generate APP_KEY if not provided
generate_app_key() {
    # Use OpenSSL to generate a base64 key similar to Laravel
    openssl rand -base64 32 | tr -d '\n'
}

# Generate strong password
generate_password() {
    openssl rand -base64 32 | tr -d '=' | tr '+/' '-_' | cut -c1-32
}

echo "Creating application secrets..."
echo "Press Enter to generate secure random values, or input your own."
echo ""

# Create APP_KEY
create_secret "app_key" "Laravel APP_KEY (base64:...)" "echo 'base64:'$(generate_app_key)"

# Create DB Password
create_secret "db_password" "PostgreSQL Password" "generate_password"

# Create RabbitMQ Password
create_secret "rabbitmq_password" "RabbitMQ Password" "generate_password"

echo ""
echo "All secrets created successfully!"
echo ""
echo "Created secrets:"
docker secret ls
echo ""
echo "WARNING: Secrets cannot be retrieved later. Make sure to save them securely!"

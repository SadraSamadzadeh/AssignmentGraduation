#!/bin/bash

# Laravel Matching API - Docker Startup Script

echo "🚀 Starting Laravel Matching API with Docker..."
echo ""

# Check if Docker is running
if ! docker info > /dev/null 2>&1; then
    echo "❌ Docker is not running. Please start Docker and try again."
    exit 1
fi

# Create .env file if it doesn't exist
if [ ! -f .env ]; then
    echo "📄 Creating .env file from Docker template..."
    cp .env.docker .env
fi

# Stop any existing containers
echo "🛑 Stopping any existing containers..."
docker-compose down

# Build and start services
echo "🏗️  Building and starting services..."
docker-compose up -d --build

# Wait for services to be ready
echo "⏳ Waiting for services to start..."
sleep 10

# Generate app key if not set
echo "🔑 Generating application key..."
docker-compose exec -T app php artisan key:generate --force

# Run database migrations
echo "🗄️  Running database migrations..."
docker-compose exec -T app php artisan migrate --force

# Show service status
echo ""
echo "✅ Services started successfully!"
echo ""
docker-compose ps

echo ""
echo "🌐 Service URLs:"
echo "   Laravel API:       http://localhost:8000"
echo "   RabbitMQ Management: http://localhost:15672 (admin/admin)"
echo "   PostgreSQL:        localhost:5432 (matching_user/matching_password)"
echo ""

echo "🧪 Test the API:"
echo "   curl http://localhost:8000/api/health"
echo "   curl http://localhost:8000/api/test"
echo "   curl http://localhost:8000/api/hub/status"
echo ""

echo "📊 View logs:"
echo "   docker-compose logs -f app"
echo "   docker-compose logs -f rabbitmq"
echo "   docker-compose logs -f postgres"
echo ""

echo "🔥 The Laravel Matching API Hub is ready!"
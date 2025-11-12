@echo off
echo 🚀 Starting Laravel Matching API with Docker...
echo.

REM Check if Docker is running
docker info >nul 2>&1
if %errorlevel% neq 0 (
    echo ❌ Docker is not running. Please start Docker and try again.
    exit /b 1
)

REM Create .env file if it doesn't exist
if not exist .env (
    echo 📄 Creating .env file from Docker template...
    copy .env.docker .env
) else (
    echo 💾 Backing up current .env file...
    copy .env .env.backup
    echo 📄 Setting up Docker environment...
    copy .env.docker .env
)

REM Stop any existing containers
echo 🛑 Stopping any existing containers...
docker-compose down

REM Build and start services
echo 🏗️  Building and starting services...
docker-compose up -d --build

REM Wait for services to be ready
echo ⏳ Waiting for services to start...
timeout /t 10 /nobreak >nul

REM Generate app key if not set
echo 🔑 Generating application key...
docker-compose exec -T app php artisan key:generate --force

REM Run database migrations
echo 🗄️  Running database migrations...
docker-compose exec -T app php artisan migrate --force

REM Show service status
echo.
echo ✅ Services started successfully!
echo.
docker-compose ps

echo.
echo 🌐 Service URLs:
echo    Laravel API:         http://localhost:8000
echo    RabbitMQ Management: http://localhost:15672 (admin/admin)
echo    PostgreSQL:          localhost:5432 (matching_user/matching_password)
echo.

echo 🧪 Test the API:
echo    curl http://localhost:8000/api/health
echo    curl http://localhost:8000/api/test
echo    curl http://localhost:8000/api/hub/status
echo.

echo 📊 View logs:
echo    docker-compose logs -f app
echo    docker-compose logs -f rabbitmq
echo    docker-compose logs -f postgres
echo.

echo 🔥 The Laravel Matching API Hub is ready!
pause
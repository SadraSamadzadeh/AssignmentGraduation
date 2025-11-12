@echo off
echo 🚀 Starting Laravel Matching API for Local Development...
echo.

REM Check if .env.backup exists and restore it
if exist .env.backup (
    echo 🔄 Restoring local environment from backup...
    copy .env.backup .env
    del .env.backup
    echo ✅ Local environment restored!
) else (
    echo ⚠️  No backup found. Using current .env file.
    echo ⚠️  Make sure your .env file is configured for local development.
)

echo.

REM Check if local PostgreSQL is running
echo 🔍 Checking PostgreSQL connection...
php artisan tinker --execute="DB::connection()->getPdo(); echo 'Database connected successfully!'; exit();" 2>nul
if %errorlevel% neq 0 (
    echo ❌ Cannot connect to PostgreSQL. Please ensure:
    echo    1. PostgreSQL is installed and running
    echo    2. Database 'graduation_matching' exists
    echo    3. Username 'postgres' with password 'password' is configured
    echo.
    echo 💡 To create the database, run:
    echo    psql -U postgres -c "CREATE DATABASE graduation_matching;"
    echo.
    pause
    exit /b 1
)

REM Check if RabbitMQ is running
echo 🐰 Checking RabbitMQ connection...
curl -s -u admin:admin http://127.0.0.1:15672/api/overview >nul 2>&1
if %errorlevel% neq 0 (
    echo ❌ Cannot connect to RabbitMQ. Please ensure:
    echo    1. RabbitMQ is installed and running
    echo    2. Management plugin is enabled
    echo    3. Username 'admin' with password 'admin' is configured
    echo.
    echo 💡 To enable management plugin, run:
    echo    rabbitmq-plugins enable rabbitmq_management
    echo.
    pause
    exit /b 1
)

REM Generate app key if not set
echo 🔑 Checking application key...
php artisan key:generate --show >nul 2>&1
if %errorlevel% neq 0 (
    echo 🔑 Generating application key...
    php artisan key:generate
)

REM Run database migrations
echo 🗄️  Running database migrations...
php artisan migrate

REM Clear caches
echo 🧹 Clearing caches...
php artisan cache:clear
php artisan config:clear
php artisan route:clear

REM Start the queue worker in background (optional)
echo 🔄 Starting queue worker...
start /min php artisan queue:work rabbitmq --verbose

REM Start the Laravel development server
echo.
echo ✅ Starting Laravel development server...
echo.
echo 🌐 Laravel API will be available at: http://localhost:8000
echo.
echo 🧪 Test the API:
echo    curl http://localhost:8000/api/health
echo    curl http://localhost:8000/api/test
echo    curl http://localhost:8000/api/hub/status
echo.
echo 📊 RabbitMQ Management: http://localhost:15672 (admin/admin)
echo.
echo 🔥 The Laravel Matching API is ready for local development!
echo.

php artisan serve
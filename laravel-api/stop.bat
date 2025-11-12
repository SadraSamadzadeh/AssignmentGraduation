@echo off
echo 🛑 Stopping Laravel Matching API Docker services...
echo.

REM Stop and remove containers
echo 📦 Stopping containers...
docker-compose down

REM Restore local environment if backup exists
if exist .env.backup (
    echo 🔄 Restoring local environment...
    copy .env.backup .env
    del .env.backup
    echo ✅ Local environment restored!
)

echo.
echo ✅ All Docker services stopped successfully!
echo 💡 To start local development, run: start-local.bat
echo 💡 To start Docker again, run: start.bat
echo.

pause
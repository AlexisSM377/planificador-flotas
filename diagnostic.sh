#!/bin/bash
# Diagnostic script for Hostinger deployment issues

echo "==============================================="
echo "BITACORA TRACKER - HOSTINGER DIAGNOSTIC"
echo "==============================================="
echo ""

# Check if we're in the right directory
echo "1. Checking directory structure..."
if [ -f "src/index.html" ]; then
    echo "   ✅ src/index.html found"
else
    echo "   ❌ src/index.html NOT found"
    echo "   Current directory: $(pwd)"
    echo "   Files:"
    ls -la
fi
echo ""

# Check .env
echo "2. Checking .env configuration..."
if [ -f ".env" ]; then
    echo "   ✅ .env exists"
    echo "   ENVIRONMENT: $(grep ENVIRONMENT .env || echo 'NOT SET')"
    echo "   API_KEY set: $(grep -c API_KEY .env) times"
else
    echo "   ❌ .env NOT found"
fi
echo ""

# Check credentials
echo "3. Checking credentials..."
if [ -f "credentials/google.json" ]; then
    echo "   ✅ credentials/google.json found"
    echo "   File size: $(wc -c < credentials/google.json) bytes"
    echo "   Permissions: $(ls -l credentials/google.json | awk '{print $1, $3, $4}')"
else
    echo "   ❌ credentials/google.json NOT found"
fi
echo ""

# Check PHP files
echo "4. Checking PHP files..."
for file in src/config.php src/RequestValidator.php src/api/sheets.php src/api/test.php; do
    if [ -f "$file" ]; then
        echo "   ✅ $file exists"
    else
        echo "   ❌ $file NOT found"
    fi
done
echo ""

# Check permissions
echo "5. Checking file permissions..."
echo "   .env permissions: $(ls -l .env | awk '{print $1}' 2>/dev/null || echo 'NOT FOUND')"
echo "   credentials/ permissions: $(ls -ld credentials | awk '{print $1}' 2>/dev/null || echo 'NOT FOUND')"
echo "   logs/ permissions: $(ls -ld logs | awk '{print $1}' 2>/dev/null || echo 'NOT FOUND')"
echo ""

# Check vendor
echo "6. Checking vendor (Composer)..."
if [ -d "vendor" ]; then
    echo "   ✅ vendor/ directory found"
    if [ -f "vendor/autoload.php" ]; then
        echo "   ✅ vendor/autoload.php found"
    else
        echo "   ❌ vendor/autoload.php NOT found - run: composer install"
    fi
else
    echo "   ❌ vendor/ NOT found - run: composer install"
fi
echo ""

# Test PHP syntax
echo "7. Testing PHP syntax..."
php -l src/config.php 2>&1 | grep -q "No syntax errors" && echo "   ✅ src/config.php - OK" || echo "   ❌ src/config.php - ERROR"
php -l src/api/test.php 2>&1 | grep -q "No syntax errors" && echo "   ✅ src/api/test.php - OK" || echo "   ❌ src/api/test.php - ERROR"
echo ""

# Check if we can access test API
echo "8. Testing API locally (PHP CLI)..."
php -r "
require 'src/config.php';
echo 'ENVIRONMENT: ' . ENVIRONMENT . PHP_EOL;
echo 'API_KEY set: ' . (!empty(API_KEY) ? 'YES' : 'NO') . PHP_EOL;
echo 'SPREADSHEET_ID set: ' . (!empty(SPREADSHEET_ID) ? 'YES' : 'NO') . PHP_EOL;
echo 'Credentials file: ' . (file_exists(GOOGLE_CREDENTIALS_PATH) ? 'FOUND' : 'NOT FOUND') . PHP_EOL;
" 2>&1
echo ""

# Show common issues
echo "9. Common Issues & Solutions:"
echo ""
echo "   If error 404:"
echo "   - Check your domain structure"
echo "   - Verify files are in: public_html/bitacora_tracker/"
echo ""
echo "   If error 500:"
echo "   - Check logs/error.log for details"
echo "   - Verify .env exists and is readable"
echo ""
echo "   If credentials error:"
echo "   - Upload credentials/google.json via FTP"
echo "   - Set permissions: chmod 600 credentials/google.json"
echo ""

echo "==============================================="
echo "DIAGNOSTIC COMPLETE"
echo "==============================================="

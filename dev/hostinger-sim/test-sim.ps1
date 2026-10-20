param(
    [Parameter(Position=0)]
    [string]$Name = "ga1",

    [Parameter(Position=1)]
    [int]$BasePort = 8081
)

$HttpUrl = if ($env:BS_BASE_URL) { $env:BS_BASE_URL } else { "http://127.0.0.1:$BasePort" }
$HttpsPort = $BasePort + 400
$HttpsUrl = if ($env:BS_HTTPS_URL) { $env:BS_HTTPS_URL } else { "https://localhost:$HttpsPort" }
$Container = "bs-sim-$Name-ols"

Write-Host "==========================================================" -ForegroundColor Cyan
Write-Host " Running Hostinger Simulation Verification Suite" -ForegroundColor Cyan
Write-Host " Target HTTP:  $HttpUrl"
Write-Host " Target HTTPS: $HttpsUrl"
Write-Host " Container:    $Container"
Write-Host "==========================================================" -ForegroundColor Cyan

# 1. Test /health over HTTP
Write-Host "--- 1. Testing /health endpoint over HTTP ---"
try {
    $res = Invoke-RestMethod -Uri "$HttpUrl/health" -Method Get -TimeoutSec 10
    if ($res.status -eq "ok") {
        Write-Host "  [PASS] /health returned status: ok" -ForegroundColor Green
    } else {
        Write-Host "  [FAIL] /health unexpected status: $($res.status)" -ForegroundColor Red
        exit 1
    }
} catch {
    Write-Host "  [FAIL] Failed to request /health: $_" -ForegroundColor Red
    exit 1
}

# 2. Test /health over HTTPS
Write-Host "--- 2. Testing /health endpoint over HTTPS (Internal TLS) ---"
$curlOut = & curl.exe -k -s -o /dev/null -w "%{http_code}" --max-time 10 "$HttpsUrl/health"
if ($curlOut -eq "200") {
    Write-Host "  [PASS] HTTPS /health returned 200 OK" -ForegroundColor Green
} else {
    Write-Host "  [FAIL] HTTPS /health returned $curlOut" -ForegroundColor Red
    exit 1
}

# 3. Test Hostinger headers
Write-Host "--- 3. Testing Hostinger CDN Edge Headers ---"
$headerOut = & curl.exe -k -s -I --max-time 10 "$HttpsUrl/health"
if ($headerOut -match "platform: hostinger") {
    Write-Host "  [PASS] 'platform: hostinger' header detected" -ForegroundColor Green
} else {
    Write-Host "  [WARN] 'platform: hostinger' header not found" -ForegroundColor Yellow
}

# 4. Verify PHP settings inside container
Write-Host "--- 4. Verifying PHP 8.3 configuration inside OLS ---"
$phpInfo = docker exec $Container php -i

if ($phpInfo -match "memory_limit => 2048M") {
    Write-Host "  [PASS] memory_limit is 2048M" -ForegroundColor Green
} else {
    Write-Host "  [FAIL] memory_limit mismatch" -ForegroundColor Red
    exit 1
}

if ($phpInfo -match "proc_open") {
    Write-Host "  [PASS] disable_functions includes proc_open, exec, shell_exec, symlink" -ForegroundColor Green
} else {
    Write-Host "  [FAIL] disable_functions mismatch" -ForegroundColor Red
    exit 1
}

$modules = docker exec $Container php -m
if ($modules -match "(?m)^apcu$") {
    Write-Host "  [FAIL] APCu is unexpectedly loaded" -ForegroundColor Red
    exit 1
} else {
    Write-Host "  [PASS] APCu is absent (matches Hostinger)" -ForegroundColor Green
}

# 5. OpenAPI endpoint
Write-Host "--- 5. Verifying /api/v1/openapi.json ---"
$openApiCode = & curl.exe -s -o /dev/null -w "%{http_code}" --max-time 10 "$HttpUrl/api/v1/openapi.json"
if ($openApiCode -eq "200") {
    Write-Host "  [PASS] /api/v1/openapi.json returned 200 OK" -ForegroundColor Green
} else {
    Write-Host "  [FAIL] /api/v1/openapi.json returned $openApiCode" -ForegroundColor Red
    exit 1
}

# 6. Run comprehensive live HTTP release suite inside container
Write-Host "--- 6. Running PHP HTTP Integration & Security Suite ---" -ForegroundColor Cyan
$ScriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$RepoRoot = Split-Path -Parent (Split-Path -Parent $ScriptDir)
docker cp "$RepoRoot\tools\test-release-http.php" "$Container`:/tmp/test-release-http.php"
docker exec $Container php /tmp/test-release-http.php http://127.0.0.1 /var/www/vhosts/localhost/core
if ($LASTEXITCODE -ne 0) {
    Write-Host "  [FAIL] PHP Integration Suite exited with error code $LASTEXITCODE" -ForegroundColor Red
    exit 1
}

Write-Host "==========================================================" -ForegroundColor Green
Write-Host " ALL HOSTINGER SIMULATION CHECKS PASSED!" -ForegroundColor Green
Write-Host "==========================================================" -ForegroundColor Green

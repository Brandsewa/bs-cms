# Quick refresh script for running simulation instance
# Builds the admin SPA and copies the build output directly into the container and host volume.
#
# Usage:
#   .\dev\refresh-admin.ps1 [instance_name]
# Example:
#   .\dev\refresh-admin.ps1 dev
#   .\dev\refresh-admin.ps1 s3

param(
    [string]$Instance = "dev"
)

$ErrorActionPreference = "Stop"

$rootDir = Split-Path -Parent $PSScriptRoot
Write-Host ">>> Building admin SPA..." -ForegroundColor Cyan
npm --prefix "$rootDir\admin" run build

Write-Host ">>> Copying build to instance '$Instance'..." -ForegroundColor Cyan
$containerName = "bs-sim-$Instance-ols"

# Ensure container is running
$running = docker ps --filter "name=$containerName" --format "{{.Names}}"
if (-not $running) {
    Write-Warning "Container '$containerName' is not running. If using host mounts, copying to instance folder only."
} else {
    docker cp "$rootDir\admin\dist\." "${containerName}:/var/www/vhosts/localhost/public_html/admin/"
    Write-Host ">>> Copied dist to container '$containerName' at /var/www/vhosts/localhost/public_html/admin/" -ForegroundColor Green
}

$localPath = "$rootDir\dev\hostinger-sim\instances\$Instance\public\admin"
if (Test-Path "$rootDir\dev\hostinger-sim\instances\$Instance") {
    if (-not (Test-Path $localPath)) {
        New-Item -ItemType Directory -Path $localPath -Force | Out-Null
    }
    Copy-Item -Path "$rootDir\admin\dist\*" -Destination $localPath -Recurse -Force
    Write-Host ">>> Copied dist to local path: $localPath" -ForegroundColor Green
}

Write-Host ">>> Admin refresh complete!" -ForegroundColor Green

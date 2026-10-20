param(
    [Parameter(Position=0, Mandatory=$true)]
    [string]$Command,

    [Parameter(Position=1)]
    [string]$Name = "ga1",

    [Parameter(Position=2)]
    [string]$Arg1 = "",

    [Parameter(Position=3)]
    [string]$Arg2 = ""
)

$ScriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$SimDir = Join-Path $ScriptDir "hostinger-sim"

function Get-Ports($Port) {
    $p = [int]$Port
    return @{
        Http  = $p
        Https = $p + 400
        Ols   = $p + 800
        Sftp  = $p + 1000
    }
}

function Get-DbImage($flavor) {
    switch ($flavor) {
        "mysql8"    { return "mysql:8.4" }
        "mariadb10" { return "mariadb:10.6" }
        default     { return "mariadb:11.8" }
    }
}

switch ($Command) {
    "up" {
        $BasePort = if ($Arg1) { [int]$Arg1 } else { 8081 }
        $Flavor = if ($Arg2) { $Arg2 } else { "mariadb11" }
        $Ports = Get-Ports $BasePort
        $DbImage = Get-DbImage $Flavor

        Write-Host ">>> Starting Hostinger simulation instance '$Name'..." -ForegroundColor Cyan
        Write-Host "    HTTP:  http://localhost:$($Ports.Http)"
        Write-Host "    HTTPS: https://localhost:$($Ports.Https)"
        Write-Host "    OLS:   http://localhost:$($Ports.Ols)"
        Write-Host "    SFTP:  localhost:$($Ports.Sftp) (user: u497383174, pass: hostinger)"
        Write-Host "    DB:    $DbImage"

        $env:SIM_NAME = $Name
        $env:SIM_HTTP_PORT = $Ports.Http
        $env:SIM_HTTPS_PORT = $Ports.Https
        $env:SIM_OLS_PORT = $Ports.Ols
        $env:SIM_SFTP_PORT = $Ports.Sftp
        $env:SIM_DB_IMAGE = $DbImage

        docker compose -f "$SimDir\docker-compose.yml" -p "bs-sim-$Name" up -d --build
        Write-Host ">>> Instance '$Name' is up." -ForegroundColor Green
    }

    "down" {
        Write-Host ">>> Stopping simulation instance '$Name'..." -ForegroundColor Cyan
        $env:SIM_NAME = $Name
        docker compose -f "$SimDir\docker-compose.yml" -p "bs-sim-$Name" down
    }

    "reset" {
        Write-Host ">>> Resetting simulation instance '$Name' (destroying volumes)..." -ForegroundColor Yellow
        $env:SIM_NAME = $Name
        docker compose -f "$SimDir\docker-compose.yml" -p "bs-sim-$Name" down -v
    }

    "logs" {
        $Service = $Arg1
        docker compose -f "$SimDir\docker-compose.yml" -p "bs-sim-$Name" logs -f $Service
    }

    "shell" {
        $Service = if ($Arg1) { $Arg1 } else { "ols" }
        docker compose -f "$SimDir\docker-compose.yml" -p "bs-sim-$Name" exec -it $Service /bin/sh
    }

    "deploy" {
        $ZipFile = $Arg1
        if (-not $ZipFile -or -not (Test-Path $ZipFile)) {
            Write-Error "Valid release zip path required. Run tools/build-release first."
            exit 1
        }
        $ZipBasename = Split-Path -Leaf $ZipFile
        $Container = "bs-sim-$Name-ols"

        Write-Host ">>> Copying $ZipBasename to $Container..." -ForegroundColor Cyan
        docker cp $ZipFile "$Container`:/tmp/$ZipBasename"
        if ($LASTEXITCODE -ne 0) {
            Write-Error "Failed to copy release zip to container."
            exit 1
        }

        Write-Host ">>> Extracting release and deploying docroot inside container..." -ForegroundColor Cyan
        # The real host structure:
        # Release zip contains `core/` at the top level.
        # Extracting into /var/www/vhosts/localhost puts files in /var/www/vhosts/localhost/core
        # Docroot at /var/www/vhosts/localhost/public_html -> core/public
        docker exec $Container /bin/sh -c "set -e; rm -rf /var/www/vhosts/localhost/core; rm -rf /var/www/vhosts/localhost/public_html; mkdir -p /var/www/vhosts/localhost; php /usr/local/bin/extract.php /tmp/$ZipBasename /var/www/vhosts/localhost; rm /tmp/$ZipBasename; ln -sf /var/www/vhosts/localhost/core/public /var/www/vhosts/localhost/public_html; chown -R nobody:nogroup /var/www/vhosts/localhost; chmod -R 777 /var/www/vhosts/localhost/core/storage; /usr/local/lsws/bin/lswsctrl restart"
        if ($LASTEXITCODE -ne 0) {
            Write-Error "Failed to extract release or create docroot symlink."
            exit 1
        }

        Write-Host ">>> Running automated web installation over HTTP..." -ForegroundColor Cyan
        docker exec $Container php /usr/local/bin/auto-install.php http://127.0.0.1 db 3306 bs_cms bs_user bs_password
        if ($LASTEXITCODE -ne 0) {
            Write-Error "Automated installation failed."
            exit 1
        }

        Write-Host ">>> Seeding 5 system role test users..." -ForegroundColor Cyan
        docker exec $Container php /usr/local/bin/seed-users.php
        if ($LASTEXITCODE -ne 0) {
            Write-Error "Seeding test users failed."
            exit 1
        }

        Write-Host ">>> Deployment & installation complete for instance '$Name'." -ForegroundColor Green
    }

    "test" {
        $BasePort = if ($Arg1) { [int]$Arg1 } else { 8081 }
        $Ports = Get-Ports $BasePort
        $env:BS_BASE_URL = "http://127.0.0.1:$($Ports.Http)"
        $env:BS_HTTPS_URL = "https://localhost:$($Ports.Https)"
        $env:SIM_NAME = $Name

        & "$SimDir\test-sim.ps1" $Name $BasePort
    }

    default {
        Write-Host "Usage: .\dev\sim.ps1 [up|down|reset|deploy|logs|shell|test] <name> [arg1] [arg2]"
    }
}

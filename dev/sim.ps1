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

        # Determine instance HTTPS site_url by inspecting caddy container port or default calculation
        $CaddyContainer = "bs-sim-$Name-caddy"
        $HttpsPort = 8481
        try {
            $portsJson = docker inspect $CaddyContainer --format '{{json .NetworkSettings.Ports}}' | ConvertFrom-Json
            foreach ($prop in $portsJson.PSObject.Properties) {
                if ($prop.Name -match "^(\d+)/tcp$") {
                    $p = [int]$Matches[1]
                    if ($p -ge 8400) {
                        $HttpsPort = $p
                        break
                    }
                }
            }
        } catch {}
        $SiteUrl = "https://localhost:$HttpsPort"

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

        Write-Host ">>> Running automated web installation over HTTP with Site URL $SiteUrl..." -ForegroundColor Cyan
        docker exec $Container php /usr/local/bin/auto-install.php http://127.0.0.1 $SiteUrl db 3306 bs_cms bs_user bs_password
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

    "site" {
        $Container = "bs-sim-$Name-ols"
        $CaddyContainer = "bs-sim-$Name-caddy"
        $HttpsPort = 8481
        try {
            $portsJson = docker inspect $CaddyContainer --format '{{json .NetworkSettings.Ports}}' | ConvertFrom-Json
            foreach ($prop in $portsJson.PSObject.Properties) {
                if ($prop.Name -match "^(\d+)/tcp$") {
                    $p = [int]$Matches[1]
                    if ($p -ge 8400) {
                        $HttpsPort = $p
                        break
                    }
                }
            }
        } catch {}
        $CmsUrl = "https://localhost:$HttpsPort"

        Write-Host ">>> Generating build token from instance '$Name'..." -ForegroundColor Cyan
        # Copy gen-build-token.php into container if not already present
        docker cp "$SimDir\ols\gen-build-token.php" "$Container`:/usr/local/bin/gen-build-token.php" 2>$null
        $Token = (docker exec $Container php /usr/local/bin/gen-build-token.php).Trim()
        if (-not $Token -or -not ($Token.StartsWith("bsbuild_"))) {
            Write-Error "Failed to generate build token from container $Container"
            exit 1
        }
        Write-Host ">>> Obtained build token: $($Token.Substring(0, 16))..." -ForegroundColor Green

        $StarterDir = Join-Path (Split-Path -Parent $ScriptDir) "starter"
        Write-Host ">>> Building Astro starter against $CmsUrl..." -ForegroundColor Cyan
        $env:BS_CMS_URL = $CmsUrl
        $env:BS_BUILD_TOKEN = $Token
        $env:SITE_URL = $CmsUrl
        $env:NODE_TLS_REJECT_UNAUTHORIZED = "0"

        Push-Location $StarterDir
        try {
            npm run build
            if ($LASTEXITCODE -ne 0) {
                Write-Error "Astro starter build failed."
                exit 1
            }
            Write-Host ">>> Static build complete. Starter dist is ready at $StarterDir\dist" -ForegroundColor Green

            $PreviewPort = if ($Arg1) { [int]$Arg1 } else { $HttpsPort + 100 }
            Write-Host ">>> Serving static site at http://localhost:$PreviewPort..." -ForegroundColor Cyan
            npx astro preview --port $PreviewPort
        } finally {
            Pop-Location
        }
    }

    default {
        Write-Host "Usage: .\dev\sim.ps1 [up|down|reset|deploy|logs|shell|test|site] <name> [arg1] [arg2]"
    }
}

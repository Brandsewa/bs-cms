# Hostinger Simulation Environment

This document describes how to use the local Hostinger LiteSpeed + PHP 8.3 simulation environment so that development and agent workflows can run without sharing or risking the live test site (`bskickstarter.com`).

---

## Architecture & Components

The simulation mirrors the Hostinger Business Web Hosting tier:

1. **OpenLiteSpeed (`ols`)**:
   - Running `litespeedtech/openlitespeed:1.8.3-lsphp83` with PHP 8.3 matching Hostinger's environment.
   - PHP settings matching the host:
     - `disable_functions = system, exec, shell_exec, passthru, mysql_list_dbs, ini_alter, dl, symlink, link, chgrp, leak, popen, apache_child_terminate, virtual, mb_send_mail, proc_open`
     - `memory_limit = 2048M`
     - `max_execution_time`: Measured as `0` on CLI on the real host; web SAPI value is unmeasured on the host. In simulation, CLI is `0` and web default is `360`.
     - Extension & module parity:
       - APCu: Omitted in simulation, matching real host where APCu is absent.
       - Modules verified in both: `bcmath`, `bz2`, `calendar`, `ctype`, `curl`, `dom`, `exif`, `fileinfo`, `filter`, `ftp`, `gd`, `gettext`, `gmp`, `hash`, `iconv`, `igbinary`, `imagick`, `intl`, `json`, `libxml`, `mbstring`, `memcached`, `msgpack`, `mysqli`, `mysqlnd`, `openssl`, `pcntl`, `pcre`, `PDO`, `pdo_mysql`, `pdo_sqlite`, `Phar`, `posix`, `random`, `readline`, `redis`, `session`, `SimpleXML`, `soap`, `sockets`, `sodium`, `sqlite3`, `standard`, `sysvmsg`, `sysvsem`, `sysvshm`, `tokenizer`, `xml`, `xmlreader`, `xmlwriter`, `xsl`, `zip`, `Zend OPcache`.
       - Real-host extra modules (CLI differences not required or used by BS CMS core): `imap`, `shmop`, `tidy`, `timezonedb`.
   - Native `.htaccess` execution with rewrite rules, including authorization header preservation (`RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]`).

2. **Caddy Edge Proxy (`caddy`)**:
   - Listens on `http://localhost:PORT` and `https://localhost:(PORT+400)`.
   - Generates internal TLS certificates for full HTTPS emulation.
   - Mimics Hostinger CDN edge behavior, injecting `Server: hcdn`, `platform: hostinger`, and `panel: hpanel` headers.

3. **Database (`db`)**:
   - Default: MariaDB 11.8 (matches Hostinger production).
   - Alternate flavors supported via profile: MySQL 8.4 (`mysql8`) and MariaDB 10.6 (`mariadb10`).

4. **Cron Service (`cron`)**:
   - Runs `bin/bs jobs:run` every minute, matching hPanel background cron execution.

5. **SFTP / Rsync Service (`sftp`)**:
   - Debian bookworm with OpenSSH server and `rsync` installed on port `PORT + 1000`.
   - User `u497383174` (password: `hostinger`), volume-mapped directly to the web docroot (`domains/bskickstarter.com/public_html`) and core directory.
   - Used for validating continuous deployment and `rsync --delete` safeguards.

---

## Per-Agent Isolation

Every instance is isolated with its own Docker network, named volumes, database, and ports:
- `SIM_NAME` determines the container prefix: `bs-sim-<name>-<service>`.
- Port allocation scheme based on `BASE_PORT` (default 8081):
  - **HTTP**: `BASE_PORT` (e.g. 8081)
  - **HTTPS**: `BASE_PORT + 400` (e.g. 8481)
  - **OLS Direct**: `BASE_PORT + 800` (e.g. 8881)
  - **SFTP / SSH**: `BASE_PORT + 1000` (e.g. 9081)

---

## Usage

Drivers are provided for both Bash (`dev/sim`) and PowerShell (`dev/sim.ps1`):

### 1. Starting an Instance
```bash
# Start instance 'ga1' on port 8081 with MariaDB 11.8 (default)
./dev/sim up ga1 8081 mariadb11

# In PowerShell:
.\dev\sim.ps1 up ga1 8081 mariadb11
```

Supported database flavors:
- `mariadb11` (default): `mariadb:11.8`
- `mysql8`: `mysql:8.4`
- `mariadb10`: `mariadb:10.6`

### 2. Deploying a Release Zip & Automated Setup
Build a release first using `tools/build-release`, then deploy it into the instance:
```bash
# Build release zip
./tools/build-release

# Deploy, extract, run web installer over HTTP, and seed 5 role test users:
./dev/sim deploy ga1 build/bs-cms-1.0.0.zip
```

The automated installer provisions:
- Database connection to container `db`
- Migrations and schema registry seeding
- Administrator owner account (`admin_release@example.com` / `AdminPass2026!Secure`)
- 5 test users for all system roles (`owner`, `admin`, `developer`, `editor`, `author`) with password `TestUser2026!Secure`.

### 3. Running Verification Tests
```bash
# Run local smoke tests and live HTTP integration test suite:
./dev/sim test ga1 8081

# In PowerShell:
.\dev\sim.ps1 test ga1 8081
```

### 4. Viewing Logs & Opening Container Shells
```bash
# Follow logs for OpenLiteSpeed:
./dev/sim logs ga1 ols

# Follow cron runner output:
./dev/sim logs ga1 cron

# Open interactive shell in OLS:
./dev/sim shell ga1 ols
```

### 5. Stopping and Resetting
```bash
# Stop containers (preserves volumes and DB):
./dev/sim down ga1

# Completely reset instance (wipes volumes and DB):
./dev/sim reset ga1
```

---

## What the Simulation Cannot Reproduce

1. **Hostinger Multi-Tenant CloudLinux LVE**: Real CPU throttling, IOPS quotas, and memory capping under concurrent noisy neighbors.
2. **Real DNS & Let's Encrypt**: Emulated via Caddy's internal CA and local TLS rather than public Let's Encrypt certificates.
3. **Hostinger Proprietary CDN Edge Network**: Real geo-distributed caching, Cloudflare Magic Transit integration, and Hostinger proprietary static caching layers.
4. **hPanel UI & File Manager**: Web GUI configuration changes (e.g. changing PHP versions via UI or modifying htaccess via web file manager).

---

## Checklist for Real-Host Sign-Off

Before signing off a major milestone on the live host:
- [ ] Take deploy lock: `ssh ... 'mkdir ~/deploy.lock && echo "GA2 $(date -u)" > ~/deploy.lock/owner'`.
- [ ] Build release zip and deploy via `tools/build-release` and SSH/rsync.
- [ ] Check `/api/v1/openapi.json` returns HTTP 200 with `--max-time 20`.
- [ ] Verify `Authorization: Bearer <token>` requests are not stripped.
- [ ] Verify background cron runs through real hPanel scheduler.
- [ ] Clean up any test posts, media, or temporary test users created.
- [ ] Release deploy lock: `ssh ... 'rm -rf ~/deploy.lock'`.


---

## Fast UI Development Loop (Vite Dev Server & Hot Reload)

To develop and iterate on admin screens with instant hot module replacement (HMR) against real backend data without building full release bundles:

### 1. Start the Simulation Instance
Ensure a local simulation instance is running (default port 8081 / HTTPS 8481):
```powershell
.\dev\sim.ps1 up dev 8081 mariadb11
```
Build a release zip and deploy it once so core database tables and seed users exist:
```powershell
bash tools/build-release 0.1.0
.\dev\sim.ps1 deploy dev build\bs-cms-0.1.0.zip
```

Seeded credentials for testing:
- **Users**: `owner@example.com`, `admin@example.com`, `developer@example.com`, `editor@example.com`, `author@example.com`, `contributor@example.com`, `viewer@example.com`
- **Password**: `TestUser2026!Secure`

### 2. Run the Vite Dev Proxy
In your terminal, start the UI dev server:
```powershell
npm run dev:sim -w admin
```
This launches Vite on `http://localhost:5173/admin/` with HMR enabled. All backend calls (`/api`, `/oauth`, `/mcp`, `/.well-known`) are automatically proxied to `https://localhost:8481` (with `secure: false` for local self-signed TLS certificates), preserving session cookies seamlessly across requests.

To target a different simulation instance or port:
```powershell
$env:BS_SIM_PROXY_TARGET = "https://localhost:8501"
npm run dev:sim -w admin
```

### 3. Production Build Refresh Helper (`dev\refresh-admin.ps1`)
When you want to test the production build inside the simulation container without a full release repackage:
```powershell
.\dev\refresh-admin.ps1 dev
```
This builds `admin/dist` and directly synchronizes the build assets into the running OpenLiteSpeed container (`bs-sim-dev-ols:/var/www/vhosts/localhost/public_html/admin/`).

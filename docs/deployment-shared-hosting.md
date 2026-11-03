# Deploying BS CMS 1.1.0 to shared hosting (manual install)

Step-by-step for installing the packaged release (`bs-cms-1.1.0.zip`) on
shared hosting such as Hostinger. Verified against the same layout the sim
environments use.

## Requirements

- PHP 8.2, 8.3 or 8.4 with `pdo_mysql`, `mbstring`, `gd`, `fileinfo`, `curl`
- MySQL 8.x or MariaDB 10.6+ (an empty database + user)
- Apache or LiteSpeed with `.htaccess` / mod_rewrite support
- An SSL certificate on the domain (the app enforces HTTPS)
- SSH access is strongly recommended (Hostinger includes it)

## 1. Prepare the hosting

1. Create an empty MySQL database and user; note host (`localhost`), name,
   user, password.
2. Ensure SSL is active for the domain.
3. Set the PHP version to 8.3 (or 8.2/8.4) and enable
   `pdo_mysql`, `mbstring`, `gd`, `fileinfo`, `curl`.

## 2. Upload and extract

**Option A — the shared-hosting zip (recommended, WordPress-style).**
Upload `bs-cms-1.1.0-shared.zip` directly **into** your web root
(`public_html`), then extract it there. The zip root IS the docroot:

```
public_html/
├── index.php      # front controller (paths already point into core/)
├── .htaccess      # static files served directly; /api,/admin → index.php
├── admin -> core/public/admin   (real symlink, recreated by unzip)
├── media -> core/public/media   (uploads written here via PHP)
└── core/          # the application
```

No wrapper files, no docroot symlink, no path edits — works on the main
domain even where the docroot folder cannot be swapped (verified on
Hostinger + LiteSpeed, where a symlinked docroot does NOT execute PHP).

```bash
cd ~/domains/yourdomain.com/public_html
unzip ~/bs-cms-1.1.0-shared.zip
```

**Option B — the standard zip.** Upload `bs-cms-1.1.0.zip` to a folder
**next to** (not inside) the web root, e.g. `~/bscms.zip`, and extract it.
You get a `core/` folder containing
`bin, config, migrations, public, resources, storage, vendor, ...`.

**Web root layout — pick one:**

- **With SSH:** point the (sub)domain web root at
  `core/public` with a symlink (only works where the web server executes
  PHP through a symlinked docroot — LiteSpeed on Hostinger does not):
  ```bash
  ln -s ~/bscms/core/public ~/public_html
  ```
- **Without SSH:** create a subdomain (e.g. `app.yoursite.com`) in the panel
  and set its folder to the extracted `core/public` path. Uploading the zip
  into the parent folder and extracting there usually lets you pick that
  folder directly.

## 3. Permissions

`core/storage/` (recursively: `cache, keys, logs, tmp, uploads`) must be
writable by the PHP user. On most hosts the default ownership already
allows this; otherwise set `755` on the directories.

## 4. Installer

1. Open `https://yourdomain.com/admin/` — you are redirected into the
   installer wizard.
2. Requirements check → database (host `localhost`, name/user/password from
   step 1) → owner account → finish.
3. All migrations run automatically, including the content-engine,
   plugin/form tables and the page-builder `content` field conversion.

## 5. Cron (required for scheduling + deploy pipeline)

In the hosting panel's Cron Jobs section, every 5 minutes:

```
php /home/<user>/<extract-folder>/core/bin/bs schedule:run
```

Use the exact command shown by the installer's final step. This drives
scheduled publishing, deploy debouncing and background jobs.

## 6. First login and setup

- Log in at `/admin/` with the owner account created in the wizard.
- Install plugins from **Marketplace** (e.g. Contact Form & Inquiries).
- Configure **Branding** (logo, colours, typography, borders) — tabs on the
  left, live preview on the right.
- Review **Settings → Publishing** for the Astro build token and URL
  patterns.

## 7. Publishing the static site (optional — read before deploying)

The Astro starter builds a static site separately. Two important rules:

1. **Same-root deploys overwrite the CMS `.htaccess`.** The starter's
   `dist/.htaccess` contains only the static-site rules; deploying it into
   a folder where the CMS front-controller `.htaccess` lives removes the
   `/api` and `/admin` rewrites. Merge the rule sets first (CMS front
   controller rules + static caching/markdown rules), or:
2. **Serve the static site from a subdomain** with its own folder — no
   rule collisions, and the CMS API stays reachable on the main domain.

With the shared-hosting zip (Option A) you can also publish **into the same
`public_html`**: upload `starter/dist/` there (excluding `.htaccess`), then
replace the docroot `.htaccess` with the merged template at
`tools/templates/htaccess.shared-docroot` (CMS front-controller rules +
`DirectoryIndex index.html index.php` so the static pages win at `/` +
markdown content negotiation + static-asset caching). This exact template is
verified live on Hostinger LiteSpeed at bskickstarter.com: `/` and `/home/`
serve the static site, `/admin/` and `/api` still hit the CMS, and
`Accept: text/markdown` requests get the Markdown twins.

Build the static site locally against the live CMS:

```bash
cd starter
BS_CMS_URL=https://yourdomain.com \
BS_BUILD_TOKEN=<token from Settings → Publishing> \
SITE_URL=https://yourdomain.com \
npm run build
```

Then upload `starter/dist/` to the target folder (excluding `.htaccess`
unless you merged the rules per above).

## Troubleshooting

| Symptom | Cause / fix |
|---|---|
| `/api` or `/admin` returns 404 after a static deploy | The dist `.htaccess` replaced the CMS front-controller rules — restore the CMS rules and merge the static ones |
| White page / old code still running after uploading PHP files | PHP OPcache — request a PHP restart in the panel, or wait for revalidation |
| Installer says a PHP extension is missing | Enable it in the panel's PHP configuration (Extensions) |
| Scheduled publishing not firing | Cron missing or wrong path — re-copy the command from the installer |
| Media upload fails | `core/storage/uploads` not writable — fix ownership/permissions |

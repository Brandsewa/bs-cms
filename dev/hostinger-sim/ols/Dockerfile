FROM litespeedtech/openlitespeed:1.8.3-lsphp83

# Copy Hostinger-specific PHP overrides directly into mods-available where lsphp scans
COPY 99-hostinger.ini /usr/local/lsws/lsphp83/etc/php/8.3/mods-available/99-hostinger.ini

# Copy virtual host template with .htaccess and proper docroot
COPY docker.conf /usr/local/lsws/conf/templates/docker.conf

# Copy release extraction and install helpers
COPY extract.php /usr/local/bin/extract.php
COPY auto-install.php /usr/local/bin/auto-install.php
COPY seed-users.php /usr/local/bin/seed-users.php
COPY gen-build-token.php /usr/local/bin/gen-build-token.php

# BS CMS Core

The PHP backend application (the CMS server).

## Requirements
- PHP >= 8.2
- MySQL 8.0+ or MariaDB 10.6+

## Structure
- `public/`: Document root (the only web-exposed directory).
- `src/`: Namespaced `BsCms\` PSR-4 application code.
- `config/`: Default configuration files (no secrets).
- `migrations/core/`: Database migrations.
- `resources/`: Email templates, bot catalogue, message catalog.
- `storage/`: Private storage (`cache/`, `logs/`, `keys/`, `tmp/`).
- `bin/bs`: CLI entrypoint.
- `tests/`: PHPUnit test suites (`Unit/`, `Integration/`, `Http/`).

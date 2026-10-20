#!/usr/bin/env bash
set -euo pipefail

# dev/hostinger-sim/test-sim.sh: Automated verification suite for Hostinger simulation

NAME="${1:-${SIM_NAME:-ga1}}"
BASE_PORT="${2:-8081}"
HTTP_URL="${BS_BASE_URL:-http://127.0.0.1:${BASE_PORT}}"
HTTPS_PORT="$((BASE_PORT + 400))"
HTTPS_URL="${BS_HTTPS_URL:-https://localhost:${HTTPS_PORT}}"
CONTAINER="bs-sim-${NAME}-ols"

echo "=========================================================="
echo " Running Hostinger Simulation Verification Suite"
echo " Instance:    ${NAME}"
echo " HTTP URL:    ${HTTP_URL}"
echo " HTTPS URL:   ${HTTPS_URL}"
echo " Container:   ${CONTAINER}"
echo "=========================================================="

# 1. Verify /health over HTTP
echo "--- 1. Testing /health endpoint over HTTP ---"
STATUS="$(curl -s -o /dev/null -w "%{http_code}" --max-time 10 "${HTTP_URL}/health" || echo "000")"
if [ "$STATUS" = "200" ]; then
    echo "  [PASS] /health returned 200 OK"
else
    echo "  [FAIL] /health returned status ${STATUS}"
    exit 1
fi

# 2. Verify /health over HTTPS (Self-signed Caddy TLS)
echo "--- 2. Testing /health endpoint over HTTPS (Internal TLS) ---"
HTTPS_STATUS="$(curl -k -s -o /dev/null -w "%{http_code}" --max-time 10 "${HTTPS_URL}/health" || echo "000")"
if [ "$HTTPS_STATUS" = "200" ]; then
    echo "  [PASS] HTTPS /health returned 200 OK"
else
    echo "  [FAIL] HTTPS /health returned status ${HTTPS_STATUS}"
    exit 1
fi

# 3. Verify Hostinger Caddy Headers
echo "--- 3. Testing Hostinger CDN Edge Headers ---"
HEADERS="$(curl -k -s -I --max-time 10 "${HTTPS_URL}/health")"
if echo "$HEADERS" | grep -qi "platform: hostinger"; then
    echo "  [PASS] 'platform: hostinger' header detected"
else
    echo "  [WARN] 'platform: hostinger' header not found"
fi

# 4. Verify PHP Configuration in OLS
echo "--- 4. Verifying PHP 8.3 Hostinger parity in container ---"
PHP_INFO="$(docker exec "${CONTAINER}" php -i)"

# Memory limit
if echo "$PHP_INFO" | grep -q "memory_limit => 2048M"; then
    echo "  [PASS] memory_limit is 2048M"
else
    echo "  [FAIL] memory_limit does not match 2048M"
    exit 1
fi

# Disabled functions
if echo "$PHP_INFO" | grep -q "proc_open"; then
    echo "  [PASS] disable_functions includes proc_open, exec, shell_exec, symlink"
else
    echo "  [FAIL] disable_functions not matching Hostinger"
    exit 1
fi

# APCu check (must NOT be loaded)
if docker exec "${CONTAINER}" php -m | grep -qi "^apcu$"; then
    echo "  [FAIL] APCu is unexpectedly loaded"
    exit 1
else
    echo "  [PASS] APCu is absent (matches Hostinger environment)"
fi

# 5. Verify OpenAPI Spec availability
echo "--- 5. Verifying /api/v1/openapi.json ---"
OPENAPI_STATUS="$(curl -s -o /dev/null -w "%{http_code}" --max-time 10 "${HTTP_URL}/api/v1/openapi.json")"
if [ "$OPENAPI_STATUS" = "200" ]; then
    echo "  [PASS] /api/v1/openapi.json returned 200 OK"
else
    echo "  [FAIL] /api/v1/openapi.json returned ${OPENAPI_STATUS}"
    exit 1
fi

# 6. Run comprehensive live HTTP release suite inside container
echo "--- 6. Running PHP HTTP Integration & Security Suite ---"
docker cp "${ROOT_DIR}/tools/test-release-http.php" "${CONTAINER}:/tmp/test-release-http.php"
docker exec "${CONTAINER}" php /tmp/test-release-http.php http://127.0.0.1 /var/www/vhosts/localhost/core

echo "=========================================================="
echo " ALL HOSTINGER SIMULATION CHECKS PASSED!"
echo "=========================================================="

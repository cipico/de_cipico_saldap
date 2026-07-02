#!/bin/bash
set -e

# Fix amp config location
ln -sf /buildkit/.amp /root/.amp

# Write amp services config
cat > /buildkit/.amp/services.yml << YAML
parameters:
    version: 2
    db_type: mysql_dsn
    mysql_dsn: "mysql://root:buildkit@mysql:3306"
    perm_type: none
    perm_user: www-data
    hosts_type: file
    httpd_type: apache24
    httpd_visibility: all
    httpd_shared_ports: "7890"
    httpd_restart_command: "true"
services: {  }
YAML

# Git safe directory workaround
git config --global --add safe.directory "*"

BUILD_NAME="ci_test_$$"

echo "=== Creating CiviCRM build ==="
civibuild create "$BUILD_NAME" --type standalone-clean --civi-ver 6.13 --url http://localhost --force

echo "=== Symlinking extension ==="
EXT_DIR="/buildkit/build/${BUILD_NAME}/web/ext/de_cipico_saldap"
ln -sf /workspace "$EXT_DIR"

echo "=== Enabling extension ==="
cd "/buildkit/build/${BUILD_NAME}/web"
cv ext:enable de_cipico_saldap

echo "=== Running PHPUnit tests ==="
cd "$EXT_DIR"
env CIVICRM_UF=UnitTests phpunit8 tests/phpunit/Api4/SaldapTest.php

echo "=== Cleanup ==="
civibuild destroy "$BUILD_NAME"

echo "=== All tests passed ==="

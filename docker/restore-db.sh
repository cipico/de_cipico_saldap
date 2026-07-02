#!/bin/bash
# Restore CiviCRM database from cached .sh config + SQL snapshots.
# Usage: restore-db.sh <build_name> <app_container> <mysql_container>

set -e
BUILD_NAME="$1"
APP_CONT="$2"
MYSQL_CONT="$3"
SH_FILE="/buildkit/build/${BUILD_NAME}.sh"

if [ ! -f "$SH_FILE" ]; then
  echo "ERROR: .sh file not found: $SH_FILE"
  exit 1
fi

# Read DSNs from .sh file
CIVI_DSN=$(grep "^CIVI_DB_DSN" "$SH_FILE" | cut -d= -f2- | tr -d '"')
CMS_DSN=$(grep "^CMS_DB_DSN" "$SH_FILE" | cut -d= -f2- | tr -d '"')
TEST_DSN=$(grep "^TEST_DB_DSN" "$SH_FILE" 2>/dev/null | cut -d= -f2- | tr -d '"')

for dsn in "$CIVI_DSN" "$CMS_DSN" "$TEST_DSN"; do
  [ -z "$dsn" ] && continue
  user=$(echo "$dsn" | sed 's|mysql://\([^:]*\):\([^@]*\)@.*|\1|')
  pass=$(echo "$dsn" | sed 's|mysql://\([^:]*\):\([^@]*\)@.*|\2|')
  db=$(echo "$dsn" | sed 's|.*/\([^?]*\)?.*|\1|')
  docker exec -i "$MYSQL_CONT" mysql -u root -pbuildkit <<SQL
    CREATE USER IF NOT EXISTS \`$user\`@"%" IDENTIFIED BY "$pass";
    CREATE DATABASE IF NOT EXISTS \`$db\`;
    GRANT ALL ON \`$db\`.* TO \`$user\`@"%";
SQL
  echo "Created: $user / $db"
done

docker exec -i "$MYSQL_CONT" mysql -u root -pbuildkit -e "FLUSH PRIVILEGES"

# Import Civi SQL dump
CIVI_DB=$(echo "$CIVI_DSN" | sed 's|.*/\([^?]*\)?.*|\1|')
echo "Importing into: $CIVI_DB"
docker exec "$APP_CONT" zcat "/buildkit/app/snapshot/${BUILD_NAME}/civi.sql.gz" | \
  docker exec -i "$MYSQL_CONT" mysql -u root -pbuildkit "$CIVI_DB"
echo "=== DB restore completed ==="

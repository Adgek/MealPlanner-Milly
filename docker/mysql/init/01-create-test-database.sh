#!/bin/bash
# Runs once, the first time the `db` volume is initialised.
#
# Creates the companion test database (`<DB_DATABASE>_test`) so the test suite
# runs against real MySQL 8.4 instead of a different engine, without ever
# touching the household's own data. phpunit.xml points at this database.
set -euo pipefail

TEST_DATABASE="${MYSQL_DATABASE}_test"

echo "[db-init] creating test database ${TEST_DATABASE} for ${MYSQL_USER}"

mysql --protocol=socket -uroot -p"${MYSQL_ROOT_PASSWORD}" <<SQL
CREATE DATABASE IF NOT EXISTS \`${TEST_DATABASE}\`
    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
GRANT ALL PRIVILEGES ON \`${TEST_DATABASE}\`.* TO '${MYSQL_USER}'@'%';
FLUSH PRIVILEGES;
SQL

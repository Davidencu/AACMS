#!/bin/bash
# Runs once on first initialisation of an empty data directory.
# On the bootstrap node these users get replicated to the whole cluster; on
# joiners the data directory is replaced by SST anyway.
set -Eeuo pipefail

mariadb -uroot -p"${MARIADB_ROOT_PASSWORD}" <<SQL
-- State snapshot transfer (mariabackup)
CREATE USER IF NOT EXISTS 'sst'@'localhost' IDENTIFIED BY '${GALERA_SST_PASSWORD}';
GRANT RELOAD, PROCESS, LOCK TABLES, BINLOG MONITOR ON *.* TO 'sst'@'localhost';

-- ProxySQL health monitoring (read-only status access)
CREATE USER IF NOT EXISTS 'proxysql_monitor'@'%' IDENTIFIED BY '${PROXYSQL_MONITOR_PASSWORD}';
GRANT USAGE, BINLOG MONITOR ON *.* TO 'proxysql_monitor'@'%';

FLUSH PRIVILEGES;
SQL

#!/bin/zsh
# Runs one query with the SELECT-only login, using the client inside the MariaDB container.
set -euo pipefail
set -a; source ~/.config/nntmux-indexer/mariadb-ro.env; set +a
export MYSQL_PWD="$MARIADB_RO_PASSWORD"
exec /usr/local/bin/docker exec -i -e MYSQL_PWD nntmux-mariadb mariadb -h127.0.0.1 -u"$MARIADB_RO_USER" --ssl nntmux --quick -B "$@"

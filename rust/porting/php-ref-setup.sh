#!/usr/bin/env bash
# Builds a migrated, seeded database for the PHP reference on the test MariaDB, so it can
# generate fixtures. Needs: docker compose -f rust/compose.test.yml up -d
# Usage: php-ref-setup.sh [path to the php-final checkout]
set -euo pipefail

ref="${1:-$HOME/git.xbmc4lyfe/newznab-tmux-php-ref}"
container=nntmux-rust-test-mariadb-1

# Laravel shells out to a mariadb client to load its schema dump.
shim="$(mktemp -d)"
trap 'rm -rf "$shim"' EXIT
cat >"$shim/mariadb" <<SHIM
#!/bin/sh
exec docker exec -i $container mariadb -uroot -proot nntmux
SHIM
chmod +x "$shim/mariadb"

docker exec "$container" mariadb -uroot -proot \
    -e 'DROP DATABASE IF EXISTS nntmux; CREATE DATABASE nntmux CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'

cd "$ref"
PATH="$shim:$PATH" php artisan migrate --force --no-interaction
php artisan db:seed --force --no-interaction

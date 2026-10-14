#!/bin/sh
set -eu
cd /app

child=''
interrupted=0
request_stop() {
    interrupted="$1"
    if [ -n "$child" ]; then
        kill -TERM "$child" 2>/dev/null || true
    fi
}
trap 'request_stop 143' TERM
trap 'request_stop 130' INT

# Waiting on a background child lets the shell forward Docker's signal immediately.
# Every initialization step checks the shutdown flag before launching another process.
initialize() {
    [ "$interrupted" -eq 0 ] || exit "$interrupted"
    "$@" &
    child=$!
    if [ "$interrupted" -ne 0 ]; then
        kill -TERM "$child" 2>/dev/null || true
    fi
    status=0
    wait "$child" || status=$?
    if [ "$interrupted" -ne 0 ]; then
        wait "$child" 2>/dev/null || true
        exit "$interrupted"
    fi
    child=''
    return "$status"
}

for directory in storage/app/public storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs; do
    mkdir -p "$directory"
done
# CLI operations must work before initialization and must never initialize implicitly.
if [ "$1" != php ]; then
    test -f /app/_install/install.lock || { echo "Initialize the database explicitly before starting services." >&2; exit 1; }
    initialize php artisan config:cache
    initialize php artisan route:cache
    initialize php artisan view:cache
    initialize php artisan event:cache
    initialize php artisan data:cache-structures
fi
[ "$interrupted" -eq 0 ] || exit "$interrupted"
# The service takes ownership of Docker's signals after exec.
trap - TERM INT
exec "$@"

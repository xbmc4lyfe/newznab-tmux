#!/bin/sh
# Supervises the NNTmux tmux engine inside the indexer container.
# The health check repairs a dead monitor pane or a missing session here (where tmux lives);
# after repeated failures the container exits so Docker's restart policy recreates it.
set -eu
cd /app

SESSION="${TMUX_SESSION_NAME:-nntmux}"
INTERVAL="${INDEXER_HEALTH_INTERVAL:-30}"
MAX_FAILURES="${INDEXER_MAX_FAILURES:-10}"
READY_FILE=/var/tmp/nntmux/indexer.ready
child=''
stopping=0

request_stop() {
    stopping=1
    if [ -n "$child" ]; then
        kill -TERM "$child" 2>/dev/null || true
    fi
}
trap 'request_stop' TERM INT

stop() {
    # A repeated signal must not interrupt drain or invoke cleanup a second time.
    trap '' TERM INT
    rm -f "$READY_FILE"
    exit_status="$1"
    if ! php artisan tmux:drain --session="$SESSION" --timeout="${INDEXER_DRAIN_TIMEOUT:-90}" --no-interaction; then
        echo "tmux drain failed; stopping remaining workers within the container stop grace." >&2
        [ "$exit_status" -ne 0 ] || exit_status=1
    fi
    php artisan tmux:stop --session="$SESSION" --force --no-interaction || true
    exit "$exit_status"
}

run() {
    [ "$stopping" -eq 0 ] || stop 0
    "$@" &
    child=$!
    if [ "$stopping" -ne 0 ]; then
        kill -TERM "$child" 2>/dev/null || true
    fi
    status=0
    wait "$child" || status=$?
    if [ "$stopping" -ne 0 ]; then
        wait "$child" 2>/dev/null || true
        child=''
        stop 0
    fi
    child=''
    return "$status"
}

mkdir -p /var/tmp/nntmux/unrar /var/tmp/nntmux/unzip
rm -f "$READY_FILE"

if run php artisan tmux:start --session="$SESSION" --no-interaction; then
    run touch "$READY_FILE"
else
    stop "$?"
fi

failures=0
while :; do
    run sleep "$INTERVAL"
    if run php artisan tmux:health-check --session="$SESSION" --auto-restart --quiet; then
        failures=0
    else
        failures=$((failures + 1))
        echo "tmux health check failed (${failures}/${MAX_FAILURES})" >&2
        if [ "$failures" -ge "$MAX_FAILURES" ]; then
            stop 1
        fi
    fi
done

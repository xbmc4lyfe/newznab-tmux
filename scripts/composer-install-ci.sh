#!/usr/bin/env bash
set -euo pipefail

evidence_dir=${1:?Pass an installation evidence directory}
mkdir -p "$evidence_dir"

for attempt in 1 2 3 4; do
    log="$evidence_dir/attempt-$attempt.log"
    # Keep earlier failures intact, including when a job is invoked again.
    if [[ -e "$log" || -L "$log" ]]; then
        printf 'Installation evidence already exists: %s\n' "$log" >&2
        exit 73
    fi
    if composer install --prefer-dist --no-interaction --no-progress --no-scripts 2>&1 | tee "$log"; then
        exit 0
    else
        pipeline_status=("${PIPESTATUS[@]}")
    fi
    if (( pipeline_status[1] != 0 )); then
        exit "${pipeline_status[1]}"
    fi
    install_status=${pipeline_status[0]}
    if (( attempt == 4 )) || ! LC_ALL=C grep -Eq 'HTTP(/[0-9.]+)?[[:space:]]+429([^0-9]|$)' "$log"; then
        exit "$install_status"
    fi
    delay=$((60 * (1 << (attempt - 1))))
    printf 'Composer received HTTP 429; retrying locked install in %s seconds.\n' "$delay" >&2
    sleep "$delay"
done

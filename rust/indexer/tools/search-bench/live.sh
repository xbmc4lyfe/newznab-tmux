#!/bin/zsh
# Publishes while searching against an already built index, which it then dirties, so run it last.
# Usage: live.sh <label> <corpus> <queries> <secs> <burn> <variant...>
set -u
LABEL=$1; CORPUS=$2; QUERIES=$3; SECS=$4; BURN=$5; shift 5
BASE=~/search-bench
BIN=$BASE/harness/target/release/search-bench
MEILI=$BASE/meili/v1.56.0/meilisearch-macos-apple-silicon
IDX=$BASE/idx/$LABEL
OUT=$BASE/results/$LABEL.jsonl
URL=http://127.0.0.1:7700
for V in "$@"; do
  case $V in
    indexer) D=$IDX/indexer;; fts5-date) D=$IDX/fts5;; fts5none-date) D=$IDX/fts5none;; tantivy-date) D=$IDX/tantivy;; meili-tuned) D=$IDX/meili-tuned;; meili-all|meili-default) D=$IDX/meili-default;;
    *) echo "unknown $V"; exit 1;;
  esac
  MPID=
  if [[ $V == meili-* ]]; then
    $MEILI --db-path $D/data.ms --dump-dir $D/dumps --snapshot-dir $D/snap --http-addr 127.0.0.1:7700 --env development --no-analytics >> $D/server.log 2>&1 &
    MPID=$!
    for i in {1..120}; do curl -fs -m 1 $URL/health >/dev/null && break; sleep 0.5; done
  fi
  $BIN live --engine $V --corpus $CORPUS --queries $QUERIES --dir $D --url $URL --secs $SECS --burn $BURN --label $LABEL --out $OUT
  [ -n "$MPID" ] && { kill $MPID; wait $MPID 2>/dev/null; }
done

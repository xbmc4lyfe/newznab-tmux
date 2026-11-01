#!/bin/zsh
# Runs builds and benchmarks for one tier. Usage: run.sh <label> <corpus> <queries> <passes> <engine...>
# Engines: indexer fts5 fts5none tantivy meili-default meili-tuned meili-typo7
set -u
: ${BIN:=$HOME/search-bench/harness/target/release/search-bench}
LABEL=$1; CORPUS=$2; QUERIES=$3; PASSES=$4; shift 4
BASE=~/search-bench
MEILI=$BASE/meili/v1.56.0/meilisearch-macos-apple-silicon
IDX=$BASE/idx/$LABEL
OUT=$BASE/results/$LABEL.jsonl
mkdir -p $IDX $BASE/results
URL=http://127.0.0.1:7700
: ${CLIENTS:=1,8}
: ${BURN:=0}
: ${REBUILD:=1}
: ${DATE_ONLY:=0}
: ${SKIP_QUALITY:=0}
: ${PAGE_OFFSET:=}
EXTRA=(); [ "$SKIP_QUALITY" = 1 ] && EXTRA+=(--skip-quality); [ -n "$PAGE_OFFSET" ] && EXTRA+=(--page-offset $PAGE_OFFSET)
load_row() { echo "{\"kind\":\"load\",\"label\":\"$LABEL\",\"at\":\"$1\",\"engine\":\"$2\",\"loadavg\":\"$(sysctl -n vm.loadavg | tr -d '{}' | xargs)\",\"import_running\":$(pgrep -f indexer-import | wc -l | xargs)}" >> $OUT; }

rss_watch() { # pid label
  local peak=0 r
  while kill -0 $1 2>/dev/null; do
    r=$(ps -o rss= -p $1 2>/dev/null | tr -d ' '); [ -n "$r" ] && [ "$r" -gt "$peak" ] && peak=$r
    echo $peak > $IDX/$2.peak_rss_kb; sleep 1
  done
}

meili_up() { # dir
  mkdir -p $1
  $MEILI --db-path $1/data.ms --dump-dir $1/dumps --snapshot-dir $1/snap --http-addr 127.0.0.1:7700 --env development --no-analytics > $1/server.log 2>&1 &
  MPID=$!
  for i in {1..120}; do curl -fs -m 1 $URL/health >/dev/null && return 0; sleep 0.5; done
  echo "meilisearch did not start"; return 1
}
meili_down() { kill $MPID 2>/dev/null; wait $MPID 2>/dev/null; }

for ENGINE in "$@"; do
  D=$IDX/$ENGINE
  load_row start $ENGINE
  case $ENGINE in
    fts5|fts5none)
      if [ "$REBUILD" = 1 ]; then rm -rf $D; /usr/bin/time -l $BIN build --engine $ENGINE --corpus $CORPUS --dir $D --label $LABEL --out $OUT 2> $D.time; grep -E "maximum resident" $D.time; fi
      VARIANTS=($ENGINE-date); [ $ENGINE = fts5 ] && [ $DATE_ONLY = 0 ] && VARIANTS+=(fts5-bm25)
      for V in $VARIANTS; do $BIN bench --engine $V --corpus $CORPUS --queries $QUERIES --dir $D --clients $CLIENTS --passes $PASSES --burn $BURN --label $LABEL --out $OUT $EXTRA; done
      ;;
    indexer)
      if [ "$REBUILD" = 1 ]; then rm -rf $D; /usr/bin/time -l $BIN build --engine indexer --corpus $CORPUS --dir $D --label $LABEL --out $OUT 2> $D.time; grep -E "maximum resident" $D.time; fi
      $BIN bench --engine indexer --corpus $CORPUS --queries $QUERIES --dir $D --clients $CLIENTS --passes $PASSES --burn $BURN --label $LABEL --out $OUT $EXTRA
      ;;
    tantivy)
      if [ "$REBUILD" = 1 ]; then rm -rf $D; /usr/bin/time -l $BIN build --engine tantivy --corpus $CORPUS --dir $D --label $LABEL --out $OUT 2> $D.time; grep -E "maximum resident" $D.time; fi
      VARIANTS=(tantivy-date); [ $DATE_ONLY = 0 ] && VARIANTS+=(tantivy-bm25)
      for V in $VARIANTS; do $BIN bench --engine $V --corpus $CORPUS --queries $QUERIES --dir $D --clients $CLIENTS --passes $PASSES --burn $BURN --label $LABEL --out $OUT $EXTRA; done
      ;;
    meili-default|meili-tuned|meili-typo7)
      [ "$REBUILD" = 1 ] && rm -rf $D
      meili_up $D || exit 1
      rss_watch $MPID $ENGINE.build &
      if [ "$REBUILD" = 1 ]; then $BIN build --engine $ENGINE --corpus $CORPUS --dir $D --url $URL --label $LABEL --out $OUT; fi
      VARIANTS=($ENGINE); [ $ENGINE = meili-default ] && [ $DATE_ONLY = 0 ] && VARIANTS+=(meili-all)
      for V in $VARIANTS; do $BIN bench --engine $V --corpus $CORPUS --queries $QUERIES --dir $D --url $URL --clients $CLIENTS --passes $PASSES --burn $BURN --label $LABEL --out $OUT $EXTRA; done
      echo "{\"kind\":\"rss\",\"label\":\"$LABEL\",\"engine\":\"$ENGINE\",\"rss_kb_now\":$(ps -o rss= -p $MPID | tr -d ' '),\"rss_kb_peak\":$(cat $IDX/$ENGINE.build.peak_rss_kb)}" >> $OUT
      meili_down
      ;;
    *) echo "unknown engine $ENGINE"; exit 1;;
  esac
  load_row end $ENGINE
done

#!/bin/zsh
# The full benchmark sequence. Live publishing goes last, since it dirties the indexes.
set -u
BASE=~/search-bench; H=$BASE/harness; T=$BASE/tiers; BIN=$H/target/release/search-bench
cd $BASE
rm -f results/1m.jsonl results/big.jsonl results/all.jsonl
PAGE_OFFSET=200 CLIENTS=1,8 $H/run.sh 1m $T/1m.tsv $T/1m.queries.jsonl 3 indexer fts5 fts5none tantivy meili-default meili-tuned meili-typo7 > 1m.log 2>&1
for t in big all; do
  $BIN queries --corpus $T/$t.tsv --per-class 1000 --out $T/$t.queries.jsonl 2> $t.queries.log
  DATE_ONLY=1 PAGE_OFFSET=200 CLIENTS=1,8 $H/run.sh $t $T/$t.tsv $T/$t.queries.jsonl 3 indexer fts5 tantivy meili-tuned > $t.log 2>&1
done
REBUILD=0 SKIP_QUALITY=1 DATE_ONLY=1 BURN=6 CLIENTS=1,8 $H/run.sh 1m $T/1m.tsv $T/1m.queries.jsonl 3 indexer fts5 tantivy meili-tuned > 1m.burn.log 2>&1
$H/live.sh 1m $T/1m.tsv $T/1m.queries.jsonl 60 0 indexer fts5-date tantivy-date meili-tuned > 1m.live.log 2>&1
$H/live.sh 1m $T/1m.tsv $T/1m.queries.jsonl 60 6 indexer fts5-date tantivy-date meili-tuned >> 1m.live.log 2>&1
echo finished

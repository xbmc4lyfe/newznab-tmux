# search-bench

Compares the indexer's Tantivy engine (`indexer-search`) with a plain Tantivy index, SQLite FTS5 and Meilisearch, for free-text search over release names. Every engine answers the same queries: all words required, 100 results, newest first unless a variant says otherwise. Results are checked against a brute-force answer for each query.

## Running it

The scripts expect this directory at `~/search-bench/harness`, so symlink it there, plus a Meilisearch binary at `~/search-bench/meili/v1.56.0/meilisearch-macos-apple-silicon`.

1. Export the names with a read-only MariaDB login. `dbq.sh` reads the login from `~/.config/nntmux-indexer/mariadb-ro.env` and runs the client inside the `nntmux-mariadb` container.

   ```bash
   ./dbq.sh -N -e "SELECT id, categories_id, size, UNIX_TIMESTAMP(postdate), videos_id, imdbid, searchname, name FROM releases" > ~/search-bench/releases.tsv
   ```

2. Build the tiers and the 1M query set.

   ```bash
   cargo build --release
   cd ~/search-bench && mkdir -p tiers
   harness/target/release/search-bench prep --export releases.tsv --tier 1000000 --out tiers/1m.tsv
   harness/target/release/search-bench prep --export releases.tsv --only-big --out tiers/big.tsv
   harness/target/release/search-bench prep --export releases.tsv --tier 0 --out tiers/all.tsv
   harness/target/release/search-bench queries --corpus tiers/1m.tsv --out tiers/1m.queries.jsonl
   ```

3. Run everything and print the tables. This takes about 30 minutes on an M4. Stop other heavy work first, because `run.sh` records the load but can't remove it.

   ```bash
   harness/all.sh
   python3 harness/summ.py results/1m.jsonl
   ```

To measure only the indexer's engine, use `harness/run.sh <label> <corpus> <queries> 3 indexer`.

`live.sh` adds releases to an index while searching it, so run it last or rebuild afterwards. To test FTS5 without SQLite's memory statistics, build a second binary with `LIBSQLITE3_FLAGS=-DSQLITE_DEFAULT_MEMSTATUS=0 CARGO_TARGET_DIR=target-mem0 cargo build --release` and pass it to `run.sh` as `BIN`.

Don't commit `releases.tsv`, the tiers or the results, because they hold real release names.

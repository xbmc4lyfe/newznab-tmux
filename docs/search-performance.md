# Search performance and index maintenance

The website, Newznab API, and API v2 share the release search contract in
`app/Services/Search/DTO`. Offset responses keep exact totals. API v2 also accepts an
empty `cursor` parameter to start cursor-mode pagination and returns the next opaque,
signed cursor in `pagination.next_cursor`.

## Baseline and validation

Capture warm- and cold-cache measurements for both `SEARCH_DRIVER=manticore` and
`SEARCH_DRIVER=elasticsearch`. Record p50/p95 response time, search backend duration,
SQL query count, peak memory, result count, offset, and whether fuzzy fallback ran.
Use the same query/category/sort matrix before and after a change. Include offsets 0,
1,000, and 5,000 plus API v2 cursor traversal.

Do not set a performance target until the baseline is recorded. A normal successful
exact search should make one search request; fuzzy search may make a second request
only after an exact zero-result response.

## Maintenance rebuild

Search schema changes require a maintenance window. Before rebuilding, enable
`nntmux.mysql_search_fallback` if bounded database fallback is desired while indexes
are empty.

1. Run `php artisan tmux:stop` and pause queue workers that update search indexes.
2. Recreate Manticore indexes with `php artisan manticore:create-indexes --drop`, or
   recreate Elasticsearch indexes with the project's Elasticsearch index command.
3. Repopulate enabled indexes with `php artisan nntmux:populate --manticore --all` or
   the equivalent Elasticsearch population flags.
4. Compare source and index document counts and run representative filtered searches.
5. Increment `SEARCH_INDEX_GENERATION` to invalidate outstanding API v2 cursors.
6. Restart queue workers and run `php artisan tmux:start`.

If count or query validation fails, leave processing stopped, restore/rebuild the
previous schema, and keep database fallback enabled until indexes are healthy.

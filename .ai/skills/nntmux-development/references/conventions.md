## Project Conventions

### Models (`app/Models/`)
- Casts in `casts()` method, not `$casts` property
- Foreign keys: `{table}_id` (e.g., `groups_id`)
- Key: `Release`, `Video`, `TvEpisode`, `MovieInfo`, `UsenetGroup`

### API (`app/Http/Controllers/Api/`)
- v1: XML (newznab compat) - `ApiController.php`
- v2: JSON REST - `ApiV2Controller.php`
- HTTP `QUERY` (RFC 10008) is accepted on v1 `/api/v1/api` and the v2 read endpoints (`search`, `tv`, `movies`, `audio`, `books`, `anime`, `details`); never on `getnzb`/`nzbadd`. Route middleware `acceptQuery:v1|v2` (`ValidateHttpQueryInput`) enforces the JSON body contract and normalizes input via `App\Services\Api\ApiInputCanonicalizer`, which the v2 cursor hash and `ApiUsageService` audit also use. The global outermost `DecorateHttpQueryResponses` sets `Cache-Control: private, no-store` and `Accept-Query`. v1 limits QUERY to `ApiController::QUERY_SAFE_FUNCTIONS`. QUERY body size is bounded in `docker/8.5/nginx.conf`. Client docs: `docs/nntmux_api_v2.md`. Run `php artisan route:cache` after deploy
- RSS feeds are separate from `/api`: edit `routes/rss.php` + `App\Http\Controllers\RssController`; `/rss/*` is mounted from `bootstrap/app.php` and `RssController::userCheck()` validates `api_token`

### Config
- App configs: `config/nntmux*.php`, `config/tmux.php`, `config/search.php`
- Never `env()` outside config - use `config('key')`
- **Whenever you add or rename an `env()` key — in any file, whether under `config/*.php` or anywhere else in the codebase — you MUST also add it (with a sensible default and a short comment) to `.env.example`.** Treat any new env setting without the matching `.env.example` entry as an incomplete task.
- Runtime settings: `Settings::settingValue()`
- Laravel 13 route/middleware wiring lives in `bootstrap/app.php`; use that file when adding route groups, aliases, or middleware (for example the `/rss` mount)
- Custom global middleware in `app/Http/Middleware/`: `DegradeWhenRedisUnreachable` (prepended; short-circuits requests when Redis is down via `StatusProbes`), `BlockAbusiveServices` (blocks AIOStreams, Oracle Cloud, UsenetStreamer, Cloudflare WARP), `NoCacheForAuthenticatedUsers` (CDN cache busting), `ContentSecurityPolicy`, `EnforceSessionToken`, `TrustedDevice2FAMiddleware`
- In Docker/Sail, the root `Justfile` exports `.env` `SEARCH_DRIVER` as `COMPOSE_PROFILES`, so only the matching Manticore service starts

### Manticore `releases_rt` signed columns

- In Manticore, `integer` is **unsigned 32-bit**; negative DB values (e.g. `passwordstatus = -1`, `haspreview = -1`) are stored as large positives (e.g. `4294967295`), so `passwordstatus <= 1` filters never match. The `releases_rt` schema uses **`bigint`** for `passwordstatus` and `haspreview` so values stay signed.
- Changing column types requires dropping and recreating the RT table(s); Manticore cannot `ALTER` attribute types in place. **`php artisan manticore:create-indexes --drop`** drops and recreates **every** index defined in [`app/Console/Commands/CreateManticoreIndexes.php`](../../../../app/Console/Commands/CreateManticoreIndexes.php) (releases, predb, movies, tvshows, secondaries, etc.) as empty shells. Then repopulate what you use, e.g. **`php artisan nntmux:populate --manticore --all`** or at minimum **`--releases`** (and other index flags as needed). Prefer a maintenance window: run **`php artisan tmux:stop`** (and pause queue workers that touch search) during drop/repopulate, then **`php artisan tmux:start`**. The scheduler also runs `multiprocessing:fixrelnames predbft` every five minutes; against an empty releases index it marks every PreDB title it tries as searched with no match (`predb.searched = -1`), so stop the scheduler too (`docker compose --project-directory docker -f docker/docker-compose.yml stop scheduler`, from the repository root) and, after repopulating, reset them: `UPDATE predb SET searched = 0 WHERE searched = -1`, then start the scheduler again (same command with `start`). Optionally enable MySQL search fallback via `nntmux.mysql_search_fallback` while indexes are empty.

### Commands
- 80+ auto-registered in `app/Console/Commands/`
- Create with `php artisan make:` + `--no-interaction`
- Prefer host `php artisan …` / `./vendor/bin/…` (see Command execution precedence). Use the `Justfile` / Sail only for Compose lifecycle or when the user asks for containerized runs (`just artisan cmd="..."`, `just test filter=TestName`, etc.)
- Use macOS or Linux host tools. If Docker is unavailable, check Docker Desktop or the configured Linux VM on macOS. Check the Docker service and socket access on Linux.
- This workspace may have cached routes under `bootstrap/cache/routes-*.php`; after adding/changing routes, refresh with `php artisan route:cache` if a route appears missing

### Admin Content
- Admin content ordering is scoped by `contenttype`, not global: Homepage rows only reorder Homepage rows, Useful Links only reorder Useful Links
- The admin list at `resources/views/admin/content/index.blade.php` renders one draggable table per content group and uses Alpine component `contentToggle`
- `resources/js/alpine/components/content-toggle.js` is the integration point for admin content interactions: grouped drag ordering, enable/disable toggles, and delete confirmations all live there
- Reorder requests go to `AdminContentController::reorder()` and must include the exact ID set for one `contenttype`; mixed-type or partial payloads are rejected
- The ordinal field is intentionally hidden on `resources/views/admin/content/add.blade.php`; the server assigns new items to the bottom of their own group in `AdminContentController::nextBottomOrdinal()`
- Deleting content does not renumber remaining items; gaps in per-group ordinals are expected

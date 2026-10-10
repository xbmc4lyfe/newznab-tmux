# Bugs found while building the docker/ stack

These bugs turned up while building and running the self-hosted stack in `docker/` (see `docker/docs/`). Each entry was verified against the code at `0ffee3e9f`, or observed on the running stack.

**Status key:** **Open** (not fixed), **Fixed** (fixed in this working tree, changes staged), **Mitigated** (worked around in the docker/ stack, upstream code unchanged).

## Application code

### 1. `UsenetGroup::deleteGroup()` throws a TypeError after deleting the group (Open)

- **Where:** `app/Models/UsenetGroup.php:316-321`. Called from `AdminAjaxController.php:96`, which handles the admin "delete group" action.
- **Bug:** the method is declared `: bool`, but it returns `self::query()->where('id', $id)->delete()`, which is an `int`.
- **Symptom:** `Return value must be of type bool, int returned`. By the time the error fires, the purge and the delete have already happened, so the admin UI reports an error for an action that actually succeeded.
- **Reproduce:** call `UsenetGroup::deleteGroup($id)` for any group. I hit this while pruning groups.
- **Fix:** `return self::query()->where('id', $id)->delete() > 0;`

### 2. Purging or deleting a group leaves its collections, binaries and parts behind (Open)

- **Where:** `UsenetGroup::purge($id)` → `reset($id)` (`app/Models/UsenetGroup.php:330-345, 390-410`).
- **Bug:** `reset($id)` deletes only `missed_parts` for the group. `collections` rows are removed only by `resetall()`, which truncates every group.
- **Symptom:** after deleting a group, its unfinished collections stay in the database with a `groups_id` that no longer exists. Binaries and parts cascade from collections, so they stay too. Pruning about 40k groups left **16,756** orphaned collections that had to be deleted by hand.
- **Fix:** in `reset($id)`, run `Collection::query()->where('groups_id', $id)->delete()`. The binaries and parts foreign keys are `ON DELETE CASCADE`, so this also removes them.

### 3. Music and Games browse pages show broken cover images (Fixed)

- **Where:** `resources/views/music/index.blade.php:116` and `resources/views/games/index.blade.php:119`.
- **Bug:** the views build the image URL as `url('/covers/music/' . $result->cover)`. But `cover` is only a 0/1 "has cover" flag; the file is stored as `covers/{type}/{id}.webp|jpg`.
- **Symptom:** every cover requests `/covers/music/1`, which doesn't exist, so the browser shows the alt text.
- **Fix (staged):** use `getImageAssetUrl('music'|'games', (string) $result->id, url('/images/no-cover.png'))`, the same approach as the books view. A regression test was added in `tests/Unit/CoverBrowseComponentsTest.php`.

### 4. The scheduled `tmux:health-check --auto-restart` starts a second tmux session when the scheduler runs in its own container (Mitigated)

- **Where:** `routes/console.php`, the `tmux:health-check --auto-restart` schedule entry, and `deploy/cloud/compose.yaml`, which runs separate `scheduler` and `indexer` containers.
- **Bug:** the scheduler container can't see the indexer's tmux server, so it finds no session and calls `tmux:start` inside the scheduler container.
- **Symptom:** a second ingest session starts, running against the same database and provider.
- **Mitigation (staged):** new config `tmux.scheduled_health_check` (`TMUX_SCHEDULED_HEALTH_CHECK`, default `true`) gates that entry. The docker/ stack sets it to `false`, and its indexer supervises itself with `tmux:health-check --auto-restart`. `deploy/cloud` should do the same.

### 5. Horizon doesn't consume the mail queues by default (Mitigated)

- **Where:** `config/horizon.php:202` (`HORIZON_QUEUES` default `api-audit,default`).
- **Bug:** jobs are dispatched to `emails`, `incidents`, `contactemail` and `newreg`. Sources: `app/Mail/InvitationMail.php:26`, `app/Mail/IncidentDetected.php:24`, `app/Models/User.php:1278,1373`, `ContactUsController.php:36` and `UserServiceObserver.php:32`.
- **Symptom:** invitation, expiry, contact-form, registration and incident emails sit in Redis and are never sent.
- **Mitigation:** the docker/ stack sets `HORIZON_QUEUES=api-audit,default,emails,incidents,contactemail,newreg,welcomeemails`. Upstream should either change the default or derive it from the configured queue names.

### 6. `nntmux:install` always warns that the unzip path isn't configured (Open)

- **Where:** `app/Console/Commands/InstallNntmux.php:195,218`.
- **Bug:** it reads `config('nntmux_settings.tmp_unzip_path')`, but the key is `nntmux.tmp_unzip_path` (`config/nntmux.php:78`).
- **Symptom:** the warning always prints, and `updatePaths()` returns early without checking or creating the path.
- **Fix:** read `config('nntmux.tmp_unzip_path')`.

### 7. `nntmux:install` replaces an existing `APP_KEY` (Open, design)

- **Where:** `app/Console/Commands/InstallNntmux.php:85` (`key:generate --force`).
- **Symptom:** re-running the installer on a configured `.env` makes existing encrypted data and sessions unreadable. `nntmux:deploy-init` doesn't do this.
- **Fix:** only generate a key when `APP_KEY` is empty.

### 8. `nntmux:populate --disable-keys` silently skips its buffer tuning (Open)

- **Where:** `app/Console/Commands/NntmuxPopulateSearchIndexes.php:1244`.
- **Bug:** `SET SESSION innodb_buffer_pool_size = …` is invalid, because that variable is global-only. The error is caught, which also skips the `bulk_insert_buffer_size`, `read_buffer_size` and `sort_buffer_size` statements after it.
- **Fix:** drop the `innodb_buffer_pool_size` line, or wrap each statement in its own try/catch.

### 9. Dead configuration keys (Open, low priority)

- `NNTP_CONNECTIONS_A` is read into `NNTPService::$_alternateNntpConnections` (`NNTPService.php:172`) but never used.
- `lame_path` (`LAME_PATH`) is defined in `config/nntmux_settings.php`, but nothing in `app/` references it.
- `FFProbe::create()` (`MediaExtractionService.php:481`) ignores `FFPROBE_PATH`. It finds `ffprobe` on `PATH` instead.

### 10. Misleading forced-logout message (Open, low priority)

- **Where:** `app/Http/Middleware/EnforceSessionToken.php:45`.
- **Bug:** a new session token is issued by both password login (`LoginController.php:315`) and passkey login. Any login therefore logs out the user's other sessions, but the message always says "a passkey login was started on another device".
- **Symptom:** confusing when two browsers or tabs, or a script, log in as the same user. This made the Grafana debugging look like an auth failure.

### 18. `CHECK_PASSWORDED_RARS=false` (the default) silently disables all additional post-processing (Open)

- **Where:** `app/Models/Release.php:207`: `$passwordStatus = config('nntmux_settings.check_passworded_rars') === true ? -1 : 0;`. The default is `false` in `config/nntmux_settings.php:6` and `.env.example:339`.
- **Bug:** `passwordstatus = -1` is the only "needs additional processing" marker. `AdditionalCandidateQuery::applyPredicates()` (`app/Services/AdditionalProcessing/AdditionalCandidateQuery.php:107`) selects `passwordstatus = -1 AND haspreview = -1`. With the flag off, every new release is inserted with `passwordstatus = 0`, so it never enters the queue.
- **Symptom:** none of the additional-processing work ever runs:
  - no ffmpeg samples or previews, and no mediainfo (`video_data` and `audio_data` stay empty)
  - no RAR/ZIP file lists (`release_files`) and no JPG previews
  - no PAR2/file-name based renames

  The tmux pane only says "no additional work". On the docker stack, 480,988 releases built up with zero media data, and 99% sat in Misc, because obfuscated posts can only be renamed from their PAR2 and file lists.
- **Fix:** decouple the queue marker from the password-check feature. Always insert `-1`, and let `ProcessingConfiguration::$processPasswords` decide only whether a password result is recorded; `ReleaseFileManager.php:274` already does this. At minimum, document that the flag gates all post-processing.

### 19. Every `unrar` extraction fails: a lone `-` is passed as the end-of-switches marker (Fixed)

- **Where:** three call sites:
  - `app/Services/AdditionalProcessing/ArchiveExtractionService.php:236`: `'-@', '-',` in `extractArchive()`
  - `ArchiveExtractionService.php:399`: `extractSpecificFileWithExternalTools()`
  - `app/Services/NfoService.php:678-679`: `extractNfoViaUnrar()`
- **Bug:** `unrar` (6.21 confirmed) treats the lone `-` as an unknown option. It prints `ERROR: Unknown option:` and exits 7 before opening the archive. RAR's documented end-of-switches marker is `--`.
- **Symptom:** nothing is ever extracted from RAR archives, so mediainfo, ffmpeg samples, video previews, inline JPGs and NFOs inside RARs are never produced. File lists still appear, because they come from the PHP RAR parser. Pane output shows `(cB)` downloads with no `(vRAW)`/`m`/`s`.
- **Reproduce:** run `unrar e -ai -ep -c- -id -inul -kb -or -p- -r -y -@ - first.rar out/`. It exits 7. With `--` it extracts. On a real release, the `--` form pulled 2.3 MB of an `.mkv` from the first volume, and mediainfo read it as HEVC 1920×1072.
- **Fix (staged):** use `'--'` at all three sites. Regression test: `tests/Feature/UnrarArgumentsTest.php`, using the stored-RAR fixture `tests/Fixtures/archives/stored-sample.rar`. After the fix, the live pipeline produced `(vRAW)`, `m` and `s`, with rows in `media_infos`, `video_data` and `audio_data`, and a preview.

### 20. `extract_using_rar_info = 1` turns off all archive extraction without saying so (Open, documentation)

- **Where:** `post_processing_configurations.extract_using_rar_info`. It is read in `ArchiveExtractionService::extractArchive()` and `prepareExtractionDirectories()`. Its default is `false` (`database/support/LegacySettingsManifest.php:121`).
- **Bug:** with it on, only the PHP RAR/ZIP parser runs. It lists the files but extracts nothing, so mediainfo, samples and previews never get a file.
- **Symptom:** this stack's database had it at `1`, even though the manifest default is `false`; I didn't find what set it. That hid bug #19, because `unrar` was never even called.
- **Fix:** label the admin setting so it's clear that enabling it disables media extraction. The docker stack's `tuning.sql` now sets it to `0`.

### 21. The alternate NNTP provider can't be used as an article fallback alone (Fixed)

- **Where:** `config('nntmux_nntp.use_alternate_nntp_server')` (`USE_ALTERNATE_NNTP_SERVER`). The header, backfill and post-process commands (e.g. `UpdateGroupHeaders.php:66`, `UpdatePostProcess.php:133`) connect to the alternate when it is true, and the article fetchers (`ProcessingConfiguration`, `PostProcessService`, `NfoService`, `NzbContentsService`) pass the same flag to `NNTPService::getMessagesByMessageID()`/`getMessages()` as the retry-on-alternate switch.
- **Bug:** one flag means both "connect to the alternate" and "retry missing articles on the other provider". There is no way to keep headers on the primary and still fetch articles missing there from the alternate.
- **Symptom:** with `USE_ALTERNATE_NNTP_SERVER=false` (the docker/ stack, which keeps headers on the primary), articles missing on the primary fail post-processing even though alternate credentials are configured.
- **Fix (staged):** new `nntmux_nntp.alternate_article_fallback` (`NNTP_ALTERNATE_FALLBACK`, blank = follow `USE_ALTERNATE_NNTP_SERVER`, so upstream behavior is unchanged) drives the four article fetchers. The docker/ stack sets it to `true`. Test: `tests/Feature/NntpAlternateFallbackConfigTest.php`.

### 24. The IRC scraper's only channel announces no pres, and it can't read the active pre channels (Fixed)

- **Where:** `app/Services/IRCScraper.php` (one server from `scrape_irc_server`, one message regex for the NNTmux bot's `NEW: [DT: …]` format) and the `SCRAPE_IRC_CHANNELS` default `#PreNNTmux`.
- **Symptom:** on 2026-10-09, a 2-minute capture of synirc `#PreNNTmux` showed only the bot's "still active" message, and the running `irc:scrape` stored no pres in 12 minutes. `#nZEDbPRE`'s topic reads "DEAD.". In the same window, corrupt-net `#pre` and zenet `#pre` each announced about 15 pres. The scraper can't use them: it connects to only one server, and those channels use different formats (`PRE: [FLAC] Name-GRP` and `(PRE) (MP3-WEB) (Name-GRP)`).
- **Fix (staged):** `irc_settings.networks` profiles (`synirc`, `corruptnet`, `zenet`, and `predatabase`, which is off by default). Plain `irc:scrape` supervises one child process per enabled network (`irc:scrape --network=<key>`). The public channels are parsed by `PreAnnounceParser` and stored through `PredbFeedImporter`. zenet's TLS certificate doesn't name `irc.zenet.org`, so that profile turns off host name verification only. Tests: `tests/Unit/Predb/PreAnnounceParserTest.php` and `tests/Feature/IrcNetworksTest.php`.

## Packaging and deployment

### 11. `docker-compose.yml.prod-dist` starts a command that doesn't exist (Open)

- **Where:** `docker-compose.yml.prod-dist:33`: `php artisan tmux-ui:start & php artisan horizon`.
- **Bug:** there is no `tmux-ui:start` command; the real command is `tmux:start`.
- **Symptom:** the worker container never starts the indexer.

### 12. Production image ships `unrar-free`, which can't read RAR5 (Mitigated)

- **Where:** root `Dockerfile:9,30`.
- **Symptom:** archive inspection, password detection and NFO extraction fail on most current posts, which use RAR5.
- **Mitigation:** the docker/ overlay image swaps in Debian non-free `unrar` (6.21).

### 13. Native yEnc (RapidYenc) can never load in the FrankenPHP image (Open, documentation)

- **Where:** root `Dockerfile` (`dunglas/frankenphp`), `config/yenc.php`, wiki "RapidYenc".
- **Bug:** FrankenPHP's PHP is thread-safe (ZTS), but RapidYenc needs a non-threaded (NTS) CLI build.
- **Symptom:** with `YENC_DECODER=auto`, workers log "Native yEnc is unavailable … requires Linux CLI, non-threaded PHP and FFI" and fall back to the PHP decoder.
- **Fix:** document this limitation, or run CLI workers on an NTS PHP image.

### 14. Unanchored `docker-compose.yml` rule in `.gitignore` (Fixed)

- **Bug:** the rule ignored every `docker-compose.yml` in the repository, including `docker/docker-compose.yml`.
- **Fix (staged):** added `!docker/docker-compose.yml`.

## Documentation

### 15. Stale README and wiki content (Open)

- **predb.ovh no longer resolves in DNS** (checked 2026-10-08), but the README (`README.md:346`) and the wiki recommend it. Live alternatives: predb.club (JSON and RSS), api.predb.net (JSON) and predb.me (RSS). These are now supported by `predb:import-feed`.
- **Env names in the README that the code doesn't read:**

  | README uses | Code reads |
  |---|---|
  | `SEARCH_ENGINE` | `SEARCH_DRIVER` |
  | `MANTICORE_HOST` | `MANTICORESEARCH_HOST` |
  | `TMDB_API_KEY` | `TMDB_APIKEY` |
  | `TRAKT_CLIENT_ID` | `TRAKTTV_APIKEY` |
  | `SCRAPE_IRC_CHANNELS` | nothing: channels are hard-coded in `config/irc_settings.php` |

- **Commands in the README that don't exist:** `nntmux:create-admin` (`README.md:312`), `nntmux:convert-collation` (`:205`), `nntmux:index-manticore`, `nntmux:reset-password` and `nntmux:test-nntp`, among others.
- **Wiki "Database Tuning"** lists `innodb_buffer_pool_instances`, which MariaDB removed in 10.6.
- **DOCKER.md** describes a GetPageSpeed nginx build with Brotli. Neither is in `docker/8.5/Dockerfile` or `docker/8.5/nginx.conf`.
- **MySQL 8 support:** the README claims "MySQL 8+", but the schema dumps use the MariaDB-only collation `utf8mb4_uca1400_ai_ci`.

## Observed on the running stack (not yet root-caused)

### 16. Binary header storage hits lock-retry exhaustion under concurrent binaries and backfill

- **Log:** `production.ERROR: Binary header storage chunk rolled back {"groups_id":11,…,"attempts":10,"reason":"Lock retries exhausted"}`. Seen once about 25k groups were active with backfill on, even with `transaction-isolation = READ-COMMITTED`.
- **Status:** this matches the known contention described in the wiki (issue #1874 / PR #1872). Not seen since cutting back to the 207 groups.

### 17. Compressed XOVER headers fail against Newshosting

- **Symptom:** with `NNTP_COMPRESSED_HEADERS=true`, binaries workers fail in `NNTPService::_getXFeatureTextResponse()` → `throwError()` (`NNTPService.php:892,960`).
- **Status:** the docker/ stack uses the upstream default (`false`). The failure could be provider-specific or a decode bug; not investigated further.

## Issues in the docker/ stack itself (all fixed)

| Issue | Fix |
|---|---|
| Manticore restart loop: the image entrypoint `chown`s its config file, which fails on a read-only mount | Mounted the config read/write |
| tmux panes died with "This account is currently not available": `www-data`'s login shell is `nologin` | Set `SHELL=/bin/bash` for the app services |
| Grafana showed its own login page: Caddy's default directive order ran `request_header -X-JWT-Assertion` after `forward_auth`, deleting the JWT `forward_auth` had just added | Wrapped the handler in `route { }` |
| Bulk-deleting groups while the indexer was running left 7,072 collections behind | Second cleanup pass. Prefer pausing the indexer (`make -C docker down` or `restart`) before large deletes |
| `make backup` reported success and rotated out good backups when `mariadb-dump` failed (no `pipefail`); dumps were world-readable under umask 022 | `pipefail`, write to a `.partial` file, publish and prune only on success; backups dir `0700`, files `0600` |
| `make init` stopped before `docker compose up -d` whenever every public PreDB feed was down | The PreDB seed is best effort (the scheduler polls every 5 minutes) |
| `APP_URL` and Grafana's root URL were pinned to `localhost:8080`, so a changed `APP_PORT` or LAN access produced wrong absolute links and Grafana redirects | `APP_URL` in `docker/.env` (blank = `http://localhost:$APP_PORT`); `generate-env` syncs it into `config/app.env`, and `GF_SERVER_ROOT_URL` uses it |
| `generate-env` exited on "Unresolved placeholders" when `config/app.env` had NNTP host/user/password but no port, SSL or connection count | Missing NNTP settings fall back to the same defaults as the JSON path |
| On native Linux, app containers (UID 33) couldn't write `data/storage` or read the `0600` `config/app.env` created by the host user | `make perms` (run by `make env`) chowns the app mounts to 33 and gives group 33 read on `config/app.env`; a no-op on macOS |
| Compose's `MARIADB_MEMORY` fallback (10g) disagreed with `docker/.env.example` and the tuning comment (13g) | Fallback raised to 13g |

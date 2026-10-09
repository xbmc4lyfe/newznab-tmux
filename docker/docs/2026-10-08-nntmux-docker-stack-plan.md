# NNTmux Docker Stack Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Run the complete NNTmux production stack from `docker/docker-compose.yml`, with every container's state in bind mounts under `docker/`, and have it indexing Usenet and ingesting PreDB from IRC and RSS/JSON feeds.

**Architecture:** A thin overlay image (`docker/app/Dockerfile`) is built on the repo's FrankenPHP production image. That one image runs four services: web, horizon, scheduler, and indexer (tmux). They sit behind a Caddy front proxy alongside tuned MariaDB 11.4.8, Manticore 28.4.4 and Redis 7.4.2, plus Mailpit and Prometheus/Grafana. There are two small app changes: a new `predb:import-feed` command, and a config gate for the scheduled tmux health check.

**Tech Stack:** Docker Compose v2 (OrbStack, linux/arm64), FrankenPHP and PHP 8.5, Laravel 13, MariaDB 11.4, Manticore 28.4.4, Redis 7.4, Caddy 2.10, PHPUnit 12.

**Spec:** [`docker/docs/2026-10-08-nntmux-docker-stack-design.md`](./2026-10-08-nntmux-docker-stack-design.md)

## Global Constraints

- Image pins:
  - `mariadb:11.4.8`
  - `manticoresearch/manticore:28.4.4`
  - `redis:7.4.2-alpine`
  - `caddy:2.10.2-alpine`
  - Monitoring images as in `docker-compose.monitoring.yml`
- **Persistence:**
  - All persistent state lives under `docker/data/` and `docker/logs/`.
  - The only exception is unrar/unzip scratch space, which is a `tmpfs` at `/var/tmp/nntmux`.
- **Secrets:**
  - Never commit secrets. `docker/.env` and `docker/config/app.env` are gitignored and mode 600.
  - NNTP credentials come only from `USENET_SERVERS_JSON` (default `docker/config/usenet_servers.json`, gitignored).
- **NNTP providers:**
  - Primary is `news.newshosting.com`; alternate is `news.frugalusenet.com` (fallback only).
  - `USE_ALTERNATE_NNTP_SERVER=false`.
- **Exposure:** the app is published on `127.0.0.1:8080` only (`PROXY_BIND`). Mailpit, Prometheus and Grafana are reachable only on loopback or through the proxy.
- **AGENTS.md rules:**
  - Every new env key goes into `.env.example`.
  - Run Pint, PHPStan and `php -l` on PHP changes.
  - Use PHPUnit (no Pest), with `#[Test]` attributes.
  - Agents never `git commit` or `git push`. They stage the files they created or changed with `git add <path>`.
- **No host PHP:** run PHP tooling inside `nntmux/build:local` with the repo mounted. Use the helper `docker run --rm -v "$PWD":/app -w /app nntmux/build:local <cmd>`.

## File Map

| Path | Responsibility |
|---|---|
| `config/predb_feeds.php` | Feed importer config (sources, endpoints, page size, timeout, UA) |
| `app/Services/Predb/Feeds/PredbFeedEntry.php` | Normalised feed row DTO and size/file helpers |
| `app/Services/Predb/Feeds/Contracts/PredbFeedSource.php` | Source interface: `key()`, `fetch(int $page): list<PredbFeedEntry>` |
| `app/Services/Predb/Feeds/Sources/HttpFeedSource.php` | Shared HTTP client: timeout, UA, retry |
| `app/Services/Predb/Feeds/Sources/PredbClubSource.php` | predb.club JSON |
| `app/Services/Predb/Feeds/Sources/PredbNetSource.php` | api.predb.net JSON |
| `app/Services/Predb/Feeds/Sources/RssFeedSource.php` | Generic RSS 2.0 (predb.me) |
| `app/Services/Predb/Feeds/PredbFeedSourceFactory.php` | Builds sources from config |
| `app/Services/Predb/Feeds/PredbFeedImporter.php` | Upsert by title and search-index sync |
| `app/Console/Commands/PredbImportFeed.php` | `predb:import-feed` CLI |
| `routes/console.php` | Schedules the importer; gates the scheduled tmux health check |
| `config/tmux.php` | `scheduled_health_check` flag |
| `.env.example` | New `PREDB_FEED*` and `TMUX_SCHEDULED_HEALTH_CHECK` keys |
| `tests/Unit/Predb/PredbFeedSourcesTest.php`, `tests/Feature/PredbImportFeedCommandTest.php`, `tests/Fixtures/predb/*` | Tests and fixtures |
| `.gitignore`, `.dockerignore` | Ignore `docker/` runtime state and secrets; un-ignore `docker/docker-compose.yml` |
| `docker/app/{Dockerfile,Caddyfile,php/zz-docker.ini,bin/indexer.sh}` | Overlay image |
| `docker/config/**` | Service configs, seed SQL, `app.env` template |
| `docker/bin/generate-env` | Secrets and NNTP credentials into env files |
| `docker/docker-compose.yml`, `docker/Makefile`, `docker/.env.example` | Stack definition and lifecycle |

---

### Task 1: Ignore rules for docker/ runtime state

**Files:**
- Modify: `.gitignore`
- Modify: `.dockerignore`

**Interfaces:**
- Produces: `docker/data/`, `docker/logs/`, `docker/backups/`, `docker/.env` and `docker/config/app.env` are never tracked and never sent to the build context. `docker/docker-compose.yml` is trackable.

- [ ] **Step 1: Check the current behaviour.** `git check-ignore -v docker/docker-compose.yml` matches `.gitignore:6: docker-compose.yml`, because that rule is unanchored.
- [ ] **Step 2: Insert `!docker/docker-compose.yml` right after that line.** Then append:

```gitignore
# Self-hosted docker/ stack runtime state and secrets
/docker/data/
/docker/logs/
/docker/backups/
/docker/.env
/docker/config/app.env
```

- [ ] **Step 3: Append to `.dockerignore`:**

```
# Self-hosted docker/ stack runtime state (bind mounts, secrets, docs).
docker/data/
docker/logs/
docker/backups/
docker/.env
docker/config/app.env
docker/docs/
```

- [ ] **Step 4: Verify.** Run `git check-ignore -v docker/docker-compose.yml docker/data/x docker/.env`. The first path should resolve to the `!` rule; the others should be ignored.
- [ ] **Step 5: Stage.** `git add .gitignore .dockerignore`

### Task 2: PreDB feed sources (parsers)

**Files:**
- Create: `config/predb_feeds.php`, `app/Services/Predb/Feeds/PredbFeedEntry.php`, `app/Services/Predb/Feeds/Contracts/PredbFeedSource.php`, `app/Services/Predb/Feeds/Sources/{HttpFeedSource,PredbClubSource,PredbNetSource,RssFeedSource}.php`
- Test: `tests/Unit/Predb/PredbFeedSourcesTest.php`, `tests/Fixtures/predb/{predb_club.json,predb_net.json,predb_me.xml}`

**Interfaces:**
- Produces:
  - `PredbFeedEntry(title, source, ?category, ?size, ?files, ?CarbonImmutable predate, int nuked = Predb::PRE_NONUKE, ?nukeReason)`
  - `PredbFeedSource::key(): string`
  - `PredbFeedSource::fetch(int $page = 1): array<PredbFeedEntry>`
  - `PredbClubSource::parseRows(array): list<PredbFeedEntry>`
  - `PredbNetSource::parseRows(array): list<PredbFeedEntry>`
  - `RssFeedSource::__construct(string $sourceKey, string $sourceLabel, string $endpoint, int $pageSize, int $timeout, string $userAgent)`
  - `RssFeedSource::parseXml(string): list<PredbFeedEntry>`

Feed facts, verified live on 2026-10-08:
- predb.club caps `count` at 100 and returns `data.rows[]` with `name`, `team`, `cat`, `size` (MB), `files`, `preAt` and `nuke`.
- api.predb.net returns `data[]` with `release`, `section`, `size`, `files`, `pretime`, `status` and `reason`.
- predb.me RSS items carry only `<title>`.
- predb.ovh no longer resolves in DNS.

- [ ] **Step 1: Write the fixtures**

`tests/Fixtures/predb/predb_club.json`:
```json
{
    "status": "success",
    "message": "",
    "data": {
        "rowCount": 3,
        "rows": [
            {"id": 15542037, "name": "WAR.2026.S01E02.FRENCH.WEB.H264-BAWLS", "team": "BAWLS", "cat": "TV-SD-FR", "size": 457, "files": 32, "preAt": 1791513593, "nuke": null},
            {"id": 15542036, "name": "Nybble_and_Nibble_CD32_AMIGA-bADkARMA", "team": "bADkARMA", "cat": "GAMES-AMIGA", "size": 0, "files": 0, "preAt": 1791513524, "nuke": null},
            {"id": 15542000, "name": "Some.Movie.2026.1080p.WEB.H264-NUKED", "team": "NUKED", "cat": "X264-1080", "size": 2150.5, "files": 46, "preAt": 1791510000, "nuke": {"id": 1, "type": "nuke", "reason": "dupe.2026-10-01", "net": "LocalNet"}}
        ],
        "offset": 0,
        "reqCount": 3,
        "total": 13794744,
        "time": 0.05
    }
}
```

`tests/Fixtures/predb/predb_net.json`:
```json
{"status":"success","results":2,"time":"0.129s","results_total":14357119,"data":[
{"id":14492896,"pretime":1791513594,"release":"WAR.2026.S01E02.FRENCH.WEB.H264-BAWLS","section":"TV-WEB-X264","files":0,"size":0,"status":0,"reason":"","group":"BAWLS","genre":"","url":"\/rls\/WAR.2026.S01E02.FRENCH.WEB.H264-BAWLS","nfo":"","nfo_img":""},
{"id":14492890,"pretime":1791513000,"release":"Other.Show.S02E05.720p.HDTV.x264-GRP","section":"TV-720P","files":20,"size":850,"status":1,"reason":"bad.ivtc","group":"GRP","genre":"","url":"","nfo":"","nfo_img":""}
],"page":1}
```

`tests/Fixtures/predb/predb_me.xml`:
```xml
<?xml version="1.0" encoding="UTF-8"?><rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom"><channel><title>PreDB.me</title><link>https://predb.me</link><description>Everything</description>
<item><title>Nybble_and_Nibble_CD32_AMIGA-bADkARMA</title><link>https://predb.me/?post=12768130</link><guid>https://predb.me/?post=12768130</guid></item>
<item><title>Elsbeth.S04E01.1080p.HDTV.x264-SYNCOPY</title><link>https://predb.me/?post=12768129</link><pubDate>Fri, 09 Oct 2026 04:37:37 +0200</pubDate></item>
<item><title>Not a release title</title><link>https://predb.me/?post=1</link></item>
</channel></rss>
```

- [ ] **Step 2: Write the failing unit test**

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Predb;

use App\Models\Predb;
use App\Services\Predb\Feeds\PredbFeedEntry;
use App\Services\Predb\Feeds\Sources\PredbClubSource;
use App\Services\Predb\Feeds\Sources\PredbNetSource;
use App\Services\Predb\Feeds\Sources\RssFeedSource;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PredbFeedSourcesTest extends TestCase
{
    #[Test]
    public function predb_club_rows_are_normalised(): void
    {
        $entries = (new PredbClubSource('https://predb.club/api/v1/'))->parseRows($this->json('predb_club.json')['data']['rows']);

        $this->assertCount(3, $entries);
        $this->assertSame('WAR.2026.S01E02.FRENCH.WEB.H264-BAWLS', $entries[0]->title);
        $this->assertSame('predb.club', $entries[0]->source);
        $this->assertSame('TV-SD-FR', $entries[0]->category);
        $this->assertSame('457MB', $entries[0]->size);
        $this->assertSame('32', $entries[0]->files);
        $this->assertSame(1791513593, $entries[0]->predate?->getTimestamp());
        $this->assertSame(Predb::PRE_NONUKE, $entries[0]->nuked);
    }

    #[Test]
    public function predb_club_zero_size_and_files_are_unknown_and_nukes_are_mapped(): void
    {
        $entries = (new PredbClubSource('https://predb.club/api/v1/'))->parseRows($this->json('predb_club.json')['data']['rows']);

        $this->assertNull($entries[1]->size);
        $this->assertNull($entries[1]->files);
        $this->assertSame('2150.5MB', $entries[2]->size);
        $this->assertSame(Predb::PRE_NUKED, $entries[2]->nuked);
        $this->assertSame('dupe.2026-10-01', $entries[2]->nukeReason);
    }

    #[Test]
    public function predb_net_rows_are_normalised_with_status_mapping(): void
    {
        $entries = (new PredbNetSource('https://api.predb.net/'))->parseRows($this->json('predb_net.json')['data']);

        $this->assertCount(2, $entries);
        $this->assertSame('predb.net', $entries[0]->source);
        $this->assertSame('TV-WEB-X264', $entries[0]->category);
        $this->assertNull($entries[0]->size);
        $this->assertNull($entries[0]->nukeReason);
        $this->assertSame(Predb::PRE_NUKED, $entries[1]->nuked);
        $this->assertSame('bad.ivtc', $entries[1]->nukeReason);
        $this->assertSame('850MB', $entries[1]->size);
    }

    #[Test]
    public function rss_items_keep_release_titles_and_parse_pub_dates_as_utc(): void
    {
        $source = new RssFeedSource('predb_me', 'predb.me', 'https://predb.me/?rss=1');
        $entries = $source->parseXml((string) file_get_contents($this->fixture('predb_me.xml')));

        $this->assertSame(['Nybble_and_Nibble_CD32_AMIGA-bADkARMA', 'Elsbeth.S04E01.1080p.HDTV.x264-SYNCOPY'], array_map(static fn (PredbFeedEntry $e): string => $e->title, $entries));
        $this->assertNull($entries[0]->predate);
        $this->assertSame('2026-10-09 02:37:37', $entries[1]->predate?->format('Y-m-d H:i:s'));
        $this->assertSame('predb.me', $entries[1]->source);
    }

    #[Test]
    public function malformed_rss_yields_no_entries(): void
    {
        $source = new RssFeedSource('predb_me', 'predb.me', 'https://predb.me/?rss=1');

        $this->assertSame([], $source->parseXml('<html>not rss'));
    }

    /**
     * @return array<string, mixed>
     */
    private function json(string $name): array
    {
        return json_decode((string) file_get_contents($this->fixture($name)), true, flags: JSON_THROW_ON_ERROR);
    }

    private function fixture(string $name): string
    {
        return dirname(__DIR__, 2).'/Fixtures/predb/'.$name;
    }
}
```

- [ ] **Step 3: Run it and confirm it fails.** Run `docker run --rm -v "$PWD":/app -w /app nntmux/build:local php artisan test --compact tests/Unit/Predb`. Expected: FAIL with `Class "App\Services\Predb\Feeds\Sources\PredbClubSource" not found`.

- [ ] **Step 4: Implement**

`config/predb_feeds.php`:
```php
<?php

return [
    /*
    |--------------------------------------------------------------------------
    | PreDB Feed Importer
    |--------------------------------------------------------------------------
    |
    | Polls public PreDB JSON APIs / RSS feeds and upserts entries into the
    | predb table (same semantics as the IRC scraper). Scheduled every five
    | minutes when enabled; run manually with `php artisan predb:import-feed`.
    |
    */

    'enabled' => (bool) env('PREDB_FEEDS_ENABLED', false),

    'sources' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('PREDB_FEED_SOURCES', 'predb_club,predb_net,predb_me'))
    ))),

    'page_size' => (int) env('PREDB_FEED_PAGE_SIZE', 100),

    'timeout' => (int) env('PREDB_FEED_TIMEOUT', 15),

    'user_agent' => (string) env('PREDB_FEED_USER_AGENT', 'NNTmux-PreDB-Importer/1.0'),

    'endpoints' => [
        'predb_club' => 'https://predb.club/api/v1/',
        'predb_net' => 'https://api.predb.net/',
        'predb_me' => (string) env('PREDB_FEED_RSS_URL', 'https://predb.me/?rss=1'),
    ],
];
```

`app/Services/Predb/Feeds/PredbFeedEntry.php`:
```php
<?php

declare(strict_types=1);

namespace App\Services\Predb\Feeds;

use App\Models\Predb;
use Carbon\CarbonImmutable;

/**
 * One PRE announced by a remote PreDB feed, normalised to predb table semantics.
 */
final readonly class PredbFeedEntry
{
    public function __construct(
        public string $title,
        public string $source,
        public ?string $category = null,
        public ?string $size = null,
        public ?string $files = null,
        public ?CarbonImmutable $predate = null,
        public int $nuked = Predb::PRE_NONUKE,
        public ?string $nukeReason = null,
    ) {}

    /**
     * Format a size given in megabytes the way IRC pre bots announce it, or null when unknown.
     */
    public static function sizeFromMegabytes(int|float|null $megabytes): ?string
    {
        if ($megabytes === null || $megabytes <= 0) {
            return null;
        }

        return rtrim(rtrim(number_format((float) $megabytes, 2, '.', ''), '0'), '.').'MB';
    }

    /**
     * Normalise a file count, treating zero as unknown.
     */
    public static function filesFromCount(int|string|null $count): ?string
    {
        if ($count === null || (int) $count <= 0) {
            return null;
        }

        return (string) (int) $count;
    }
}
```

`app/Services/Predb/Feeds/Contracts/PredbFeedSource.php`:
```php
<?php

declare(strict_types=1);

namespace App\Services\Predb\Feeds\Contracts;

use App\Services\Predb\Feeds\PredbFeedEntry;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;

interface PredbFeedSource
{
    /**
     * Stable key used in config (`predb_feeds.sources`) and stored as predb.source.
     */
    public function key(): string;

    /**
     * Fetch one page (1-based, newest first) of PREs.
     *
     * @return list<PredbFeedEntry>
     *
     * @throws ConnectionException|RequestException When the remote feed cannot be read.
     */
    public function fetch(int $page = 1): array;
}
```

`app/Services/Predb/Feeds/Sources/HttpFeedSource.php`:
```php
<?php

declare(strict_types=1);

namespace App\Services\Predb\Feeds\Sources;

use App\Services\Predb\Feeds\Contracts\PredbFeedSource;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Shared HTTP plumbing for remote PreDB feeds.
 */
abstract class HttpFeedSource implements PredbFeedSource
{
    public function __construct(
        protected readonly string $endpoint,
        protected readonly int $pageSize = 100,
        protected readonly int $timeout = 15,
        protected readonly string $userAgent = 'NNTmux-PreDB-Importer/1.0',
    ) {}

    protected function http(): PendingRequest
    {
        return Http::timeout($this->timeout)
            ->connectTimeout(min($this->timeout, 10))
            ->withUserAgent($this->userAgent)
            ->retry(2, 500, throw: false);
    }
}
```

`app/Services/Predb/Feeds/Sources/PredbClubSource.php`:
```php
<?php

declare(strict_types=1);

namespace App\Services\Predb\Feeds\Sources;

use App\Models\Predb;
use App\Services\Predb\Feeds\PredbFeedEntry;
use Carbon\CarbonImmutable;

/**
 * predb.club JSON API (predb.ovh-compatible v1 schema).
 *
 * Row shape: {id, name, team, cat, size (MB), files, preAt (unix), nuke: null|{type, reason, ...}}
 */
final class PredbClubSource extends HttpFeedSource
{
    /** predb.club caps `count` at 100. */
    private const int MAX_PAGE_SIZE = 100;

    public function key(): string
    {
        return 'predb_club';
    }

    public function fetch(int $page = 1): array
    {
        $response = $this->http()->get($this->endpoint, [
            'count' => min($this->pageSize, self::MAX_PAGE_SIZE),
            'page' => max(1, $page),
        ])->throw();

        $rows = $response->json('data.rows');

        return is_array($rows) ? $this->parseRows($rows) : [];
    }

    /**
     * @param  array<int, mixed>  $rows
     * @return list<PredbFeedEntry>
     */
    public function parseRows(array $rows): array
    {
        $entries = [];

        foreach ($rows as $row) {
            if (! is_array($row) || ! is_string($row['name'] ?? null) || trim($row['name']) === '') {
                continue;
            }

            [$nuked, $reason] = $this->nukeStatus($row['nuke'] ?? null);

            $entries[] = new PredbFeedEntry(
                title: trim($row['name']),
                source: 'predb.club',
                category: is_string($row['cat'] ?? null) && $row['cat'] !== '' ? $row['cat'] : null,
                size: PredbFeedEntry::sizeFromMegabytes(is_numeric($row['size'] ?? null) ? (float) $row['size'] : null),
                files: PredbFeedEntry::filesFromCount(is_numeric($row['files'] ?? null) ? (int) $row['files'] : null),
                predate: is_numeric($row['preAt'] ?? null) ? CarbonImmutable::createFromTimestampUTC((int) $row['preAt']) : null,
                nuked: $nuked,
                nukeReason: $reason,
            );
        }

        return $entries;
    }

    /**
     * @return array{0: int, 1: string|null}
     */
    private function nukeStatus(mixed $nuke): array
    {
        if (! is_array($nuke)) {
            return [Predb::PRE_NONUKE, null];
        }

        $reason = is_string($nuke['reason'] ?? null) && $nuke['reason'] !== '' ? $nuke['reason'] : null;
        $status = match (strtolower((string) ($nuke['type'] ?? 'nuke'))) {
            'unnuke' => Predb::PRE_UNNUKED,
            'modnuke' => Predb::PRE_MODNUKE,
            'renuke' => Predb::PRE_RENUKED,
            'oldnuke' => Predb::PRE_OLDNUKE,
            default => Predb::PRE_NUKED,
        };

        return [$status, $reason];
    }
}
```

`app/Services/Predb/Feeds/Sources/PredbNetSource.php`:
```php
<?php

declare(strict_types=1);

namespace App\Services\Predb\Feeds\Sources;

use App\Models\Predb;
use App\Services\Predb\Feeds\PredbFeedEntry;
use Carbon\CarbonImmutable;

/**
 * api.predb.net JSON API.
 *
 * Row shape: {release, section, size (MB), files, pretime (unix), status (0 = ok, 1 = nuked, 2 = unnuked), reason, group}
 */
final class PredbNetSource extends HttpFeedSource
{
    public function key(): string
    {
        return 'predb_net';
    }

    public function fetch(int $page = 1): array
    {
        $response = $this->http()->get($this->endpoint, [
            'limit' => $this->pageSize,
            'page' => max(1, $page),
        ])->throw();

        $rows = $response->json('data');

        return is_array($rows) ? $this->parseRows($rows) : [];
    }

    /**
     * @param  array<int, mixed>  $rows
     * @return list<PredbFeedEntry>
     */
    public function parseRows(array $rows): array
    {
        $entries = [];

        foreach ($rows as $row) {
            if (! is_array($row) || ! is_string($row['release'] ?? null) || trim($row['release']) === '') {
                continue;
            }

            $status = (int) ($row['status'] ?? 0);
            $reason = is_string($row['reason'] ?? null) && $row['reason'] !== '' ? $row['reason'] : null;

            $entries[] = new PredbFeedEntry(
                title: trim($row['release']),
                source: 'predb.net',
                category: is_string($row['section'] ?? null) && $row['section'] !== '' ? $row['section'] : null,
                size: PredbFeedEntry::sizeFromMegabytes(is_numeric($row['size'] ?? null) ? (float) $row['size'] : null),
                files: PredbFeedEntry::filesFromCount(is_numeric($row['files'] ?? null) ? (int) $row['files'] : null),
                predate: is_numeric($row['pretime'] ?? null) ? CarbonImmutable::createFromTimestampUTC((int) $row['pretime']) : null,
                nuked: match ($status) {
                    0 => Predb::PRE_NONUKE,
                    2 => Predb::PRE_UNNUKED,
                    default => Predb::PRE_NUKED,
                },
                nukeReason: $status === 0 ? null : $reason,
            );
        }

        return $entries;
    }
}
```

`app/Services/Predb/Feeds/Sources/RssFeedSource.php`:
```php
<?php

declare(strict_types=1);

namespace App\Services\Predb\Feeds\Sources;

use App\Services\Predb\Feeds\PredbFeedEntry;
use Carbon\CarbonImmutable;
use SimpleXMLElement;
use Throwable;

/**
 * Generic RSS 2.0 PreDB feed (e.g. predb.me). Only the item title (and pubDate when present) is used.
 */
final class RssFeedSource extends HttpFeedSource
{
    public function __construct(
        private readonly string $sourceKey,
        private readonly string $sourceLabel,
        string $endpoint,
        int $pageSize = 100,
        int $timeout = 15,
        string $userAgent = 'NNTmux-PreDB-Importer/1.0',
    ) {
        parent::__construct($endpoint, $pageSize, $timeout, $userAgent);
    }

    public function key(): string
    {
        return $this->sourceKey;
    }

    /**
     * RSS feeds expose only their latest items, so every page past the first is empty.
     */
    public function fetch(int $page = 1): array
    {
        if ($page > 1) {
            return [];
        }

        return $this->parseXml($this->http()->get($this->endpoint)->throw()->body());
    }

    /**
     * @return list<PredbFeedEntry>
     */
    public function parseXml(string $xml): array
    {
        $previous = libxml_use_internal_errors(true);

        try {
            $document = simplexml_load_string($xml, SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        if ($document === false || ! isset($document->channel->item)) {
            return [];
        }

        $entries = [];

        foreach ($document->channel->item as $item) {
            $title = trim((string) $item->title);

            if ($title === '' || str_contains($title, ' ')) {
                continue;
            }

            $entries[] = new PredbFeedEntry(
                title: $title,
                source: $this->sourceLabel,
                predate: $this->parseDate((string) $item->pubDate),
            );

            if (count($entries) >= $this->pageSize) {
                break;
            }
        }

        return $entries;
    }

    private function parseDate(string $value): ?CarbonImmutable
    {
        if (trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value)->utc();
        } catch (Throwable) {
            return null;
        }
    }
}
```

- [ ] **Step 5: Run the test again.** Expected: PASS (5 tests).
- [ ] **Step 6: Stage.** `git add config/predb_feeds.php app/Services/Predb tests/Unit/Predb tests/Fixtures/predb`

### Task 3: Importer, command, schedule, and health-check gate

**Files:**
- Create: `app/Services/Predb/Feeds/PredbFeedImporter.php`, `app/Services/Predb/Feeds/PredbFeedSourceFactory.php`, `app/Console/Commands/PredbImportFeed.php`
- Modify: `routes/console.php` (the tmux health-check entry and the new predb entry), `config/tmux.php` (after the `session` block), `.env.example`
- Test: `tests/Feature/PredbImportFeedCommandTest.php`

**Interfaces:**
- Consumes: Task 2 sources and `App\Facades\Search::insertPredb(array{id,title,filename,source})` / `updatePreDb(...)`.
- Produces:
  - `PredbFeedImporter::import(iterable<PredbFeedEntry>, bool $dryRun = false): array{inserted:int, updated:int, skipped:int}`
  - `PredbFeedSourceFactory::make(?list<string>): list<PredbFeedSource>`
  - `predb:import-feed {--source=*} {--pages=1} {--dry-run}`
  - Config keys `predb_feeds.enabled` and `tmux.scheduled_health_check`

Upsert semantics mirror `App\Services\IRCScraper::_checkForDupe()`:
- **New title:** insert the row, then call `Search::insertPredb`.
- **Existing row:** fill only fields that are empty in the database, plus nuke status changes, then call `Search::updatePreDb`. Never overwrite `source`, so IRC-sourced rows keep their channel name.
- **Concurrent insert of the same title** (`UniqueConstraintViolationException`): treat it as an update.

- [ ] **Step 1: Write the failing feature test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Facades\Search;
use App\Models\Predb;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PredbImportFeedCommandTest extends TestCase
{
    private MockInterface $search;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'cache.default' => 'array',
            'predb_feeds.enabled' => true,
            'predb_feeds.sources' => ['predb_club', 'predb_net', 'predb_me'],
            'predb_feeds.endpoints.predb_club' => 'https://predb.club/api/v1/',
            'predb_feeds.endpoints.predb_net' => 'https://api.predb.net/',
            'predb_feeds.endpoints.predb_me' => 'https://predb.me/?rss=1',
        ]);

        DB::purge();
        DB::reconnect();

        Schema::create('predb', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('title')->default('')->unique();
            $table->string('nfo')->nullable();
            $table->string('size', 50)->nullable();
            $table->string('category')->nullable();
            $table->dateTime('predate')->nullable();
            $table->string('source', 50)->default('');
            $table->unsignedInteger('requestid')->default(0);
            $table->unsignedInteger('groups_id')->default(0);
            $table->tinyInteger('nuked')->default(0);
            $table->string('nukereason')->nullable();
            $table->string('files', 50)->nullable();
            $table->string('filename')->default('');
            $table->boolean('searched')->default(false);
        });

        $this->search = Search::spy();
    }

    #[Test]
    public function it_inserts_new_pres_from_every_source_and_indexes_them(): void
    {
        $this->fakeFeeds();

        $this->artisan('predb:import-feed')->assertSuccessful();

        // 3 predb.club + 1 new predb.net (the other duplicates predb.club) + 1 new predb.me RSS title.
        $this->assertSame(5, Predb::query()->count());
        $club = Predb::query()->where('title', 'WAR.2026.S01E02.FRENCH.WEB.H264-BAWLS')->firstOrFail();
        $this->assertSame('predb.club', $club->source);
        $this->assertSame('TV-SD-FR', $club->category);
        $this->assertSame('457MB', $club->size);
        $this->assertSame(Predb::PRE_NUKED, (int) Predb::query()->where('title', 'Some.Movie.2026.1080p.WEB.H264-NUKED')->value('nuked'));
        $this->assertSame('predb.me', Predb::query()->where('title', 'Elsbeth.S04E01.1080p.HDTV.x264-SYNCOPY')->value('source'));

        $this->search->shouldHaveReceived('insertPredb')->times(5);
    }

    #[Test]
    public function existing_rows_only_gain_missing_details(): void
    {
        DB::table('predb')->insert([
            'title' => 'WAR.2026.S01E02.FRENCH.WEB.H264-BAWLS',
            'source' => '#PreNNTmux',
            'category' => 'TV-IRC',
            'size' => null,
            'predate' => '2026-10-09 02:39:00',
        ]);
        $this->fakeFeeds();

        $this->artisan('predb:import-feed', ['--source' => ['predb_club']])->assertSuccessful();

        $row = Predb::query()->where('title', 'WAR.2026.S01E02.FRENCH.WEB.H264-BAWLS')->firstOrFail();
        $this->assertSame('#PreNNTmux', $row->source);
        $this->assertSame('TV-IRC', $row->category);
        $this->assertSame('457MB', $row->size);
        $this->assertSame('32', $row->files);
        $this->search->shouldHaveReceived('updatePreDb')->once();
    }

    #[Test]
    public function a_failing_source_does_not_stop_the_others(): void
    {
        Http::fake([
            'predb.club/*' => Http::response('down', 503),
            'api.predb.net/*' => Http::response((string) file_get_contents($this->fixture('predb_net.json'))),
            'predb.me/*' => Http::response('', 500),
        ]);

        $this->artisan('predb:import-feed')->assertSuccessful();

        $this->assertSame(2, Predb::query()->count());
    }

    #[Test]
    public function it_fails_when_every_source_fails(): void
    {
        Http::fake(['*' => Http::response('down', 503)]);

        $this->artisan('predb:import-feed')->assertFailed();
    }

    #[Test]
    public function dry_run_writes_nothing(): void
    {
        $this->fakeFeeds();

        $this->artisan('predb:import-feed', ['--dry-run' => true])->assertSuccessful();

        $this->assertSame(0, Predb::query()->count());
        $this->search->shouldNotHaveReceived('insertPredb');
    }

    #[Test]
    public function unknown_sources_are_rejected(): void
    {
        $this->artisan('predb:import-feed', ['--source' => ['nope']])->assertFailed();
    }

    #[Test]
    public function the_schedule_entry_follows_the_enabled_flag(): void
    {
        $event = $this->scheduledEvent('predb:import-feed');

        config(['predb_feeds.enabled' => true]);
        $this->assertTrue($event->filtersPass($this->app));

        config(['predb_feeds.enabled' => false]);
        $this->assertFalse($event->filtersPass($this->app));
    }

    #[Test]
    public function the_scheduled_tmux_health_check_follows_its_flag(): void
    {
        $event = $this->scheduledEvent('tmux:health-check --auto-restart');

        config(['tmux.scheduled_health_check' => true]);
        $this->assertTrue($event->filtersPass($this->app));

        config(['tmux.scheduled_health_check' => false]);
        $this->assertFalse($event->filtersPass($this->app));
    }

    private function fakeFeeds(): void
    {
        Http::fake([
            'predb.club/*' => Http::response((string) file_get_contents($this->fixture('predb_club.json'))),
            'api.predb.net/*' => Http::response((string) file_get_contents($this->fixture('predb_net.json'))),
            'predb.me/*' => Http::response((string) file_get_contents($this->fixture('predb_me.xml')), 200, ['Content-Type' => 'application/xml']),
        ]);
    }

    private function scheduledEvent(string $command): Event
    {
        foreach ($this->app->make(Schedule::class)->events() as $event) {
            if (str_contains((string) $event->command, $command)) {
                return $event;
            }
        }

        $this->fail("No scheduled event for [{$command}].");
    }

    private function fixture(string $name): string
    {
        return dirname(__DIR__).'/Fixtures/predb/'.$name;
    }
}
```

- [ ] **Step 2: Run it and confirm it fails.** Run `... php artisan test --compact tests/Feature/PredbImportFeedCommandTest.php`. Expected: FAIL with `Command "predb:import-feed" is not defined` and `No scheduled event for [predb:import-feed]`.

- [ ] **Step 3: Implement the importer, the factory and the command**

`app/Services/Predb/Feeds/PredbFeedImporter.php`:
```php
<?php

declare(strict_types=1);

namespace App\Services\Predb\Feeds;

use App\Facades\Search;
use App\Models\Predb;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Upserts feed entries into the predb table by title, mirroring the IRC scraper:
 * new titles are inserted and indexed; existing rows only gain missing details or nuke changes.
 */
class PredbFeedImporter
{
    /**
     * @param  iterable<PredbFeedEntry>  $entries
     * @return array{inserted: int, updated: int, skipped: int}
     */
    public function import(iterable $entries, bool $dryRun = false): array
    {
        $result = ['inserted' => 0, 'updated' => 0, 'skipped' => 0];

        foreach ($entries as $entry) {
            try {
                $outcome = $this->importEntry($entry, $dryRun);
            } catch (Throwable $e) {
                Log::warning('PreDB feed entry import failed', ['title' => $entry->title, 'source' => $entry->source, 'error' => $e->getMessage()]);
                $outcome = 'skipped';
            }

            $result[$outcome]++;
        }

        return $result;
    }

    /**
     * @return 'inserted'|'updated'|'skipped'
     */
    private function importEntry(PredbFeedEntry $entry, bool $dryRun): string
    {
        $title = mb_substr(trim($entry->title), 0, 255);

        if ($title === '') {
            return 'skipped';
        }

        $existing = Predb::query()->where('title', $title)->first();

        if ($existing === null) {
            if ($dryRun) {
                return 'inserted';
            }

            try {
                return $this->insert($title, $entry);
            } catch (UniqueConstraintViolationException) {
                // Another ingester (IRC scraper) inserted the title concurrently; fall through to an update.
                $existing = Predb::query()->where('title', $title)->first();

                if ($existing === null) {
                    return 'skipped';
                }
            }
        }

        $changes = $this->changesFor($existing, $entry);

        if ($changes === []) {
            return 'skipped';
        }

        if (! $dryRun) {
            DB::table('predb')->where('id', $existing->id)->update($changes);

            Search::updatePreDb([
                'id' => (int) $existing->id,
                'title' => $existing->title,
                'filename' => $existing->filename,
                'source' => $existing->source,
            ]);
        }

        return 'updated';
    }

    private function insert(string $title, PredbFeedEntry $entry): string
    {
        $id = DB::table('predb')->insertGetId([
            'title' => $title,
            'source' => mb_substr($entry->source, 0, 50),
            'category' => $entry->category !== null ? mb_substr($entry->category, 0, 255) : null,
            'size' => $entry->size !== null ? mb_substr($entry->size, 0, 50) : null,
            'files' => $entry->files !== null ? mb_substr($entry->files, 0, 50) : null,
            'predate' => ($entry->predate ?? now())->format('Y-m-d H:i:s'),
            'nuked' => $entry->nuked,
            'nukereason' => $entry->nukeReason !== null ? mb_substr($entry->nukeReason, 0, 255) : null,
            'filename' => '',
        ]);

        Search::insertPredb([
            'id' => (int) $id,
            'title' => $title,
            'filename' => '',
            'source' => $entry->source,
        ]);

        return 'inserted';
    }

    /**
     * Only fill details the row is missing, and record nuke status changes.
     *
     * @return array<string, mixed>
     */
    private function changesFor(Predb $existing, PredbFeedEntry $entry): array
    {
        $changes = [];

        if (empty($existing->category) && $entry->category !== null) {
            $changes['category'] = mb_substr($entry->category, 0, 255);
        }

        if (empty($existing->size) && $entry->size !== null) {
            $changes['size'] = mb_substr($entry->size, 0, 50);
        }

        if (empty($existing->files) && $entry->files !== null) {
            $changes['files'] = mb_substr($entry->files, 0, 50);
        }

        if ($existing->predate === null && $entry->predate !== null) {
            $changes['predate'] = $entry->predate->format('Y-m-d H:i:s');
        }

        if ($entry->nuked !== Predb::PRE_NONUKE && (int) $existing->nuked !== $entry->nuked) {
            $changes['nuked'] = $entry->nuked;
            $changes['nukereason'] = $entry->nukeReason !== null ? mb_substr($entry->nukeReason, 0, 255) : $existing->nukereason;
        }

        return $changes;
    }
}
```

`app/Services/Predb/Feeds/PredbFeedSourceFactory.php`:
```php
<?php

declare(strict_types=1);

namespace App\Services\Predb\Feeds;

use App\Services\Predb\Feeds\Contracts\PredbFeedSource;
use App\Services\Predb\Feeds\Sources\PredbClubSource;
use App\Services\Predb\Feeds\Sources\PredbNetSource;
use App\Services\Predb\Feeds\Sources\RssFeedSource;
use InvalidArgumentException;

/**
 * Builds configured PreDB feed sources from config/predb_feeds.php.
 */
class PredbFeedSourceFactory
{
    /**
     * @return list<string>
     */
    public function availableKeys(): array
    {
        return ['predb_club', 'predb_net', 'predb_me'];
    }

    /**
     * @param  list<string>|null  $keys  Restrict to these keys; null uses `predb_feeds.sources`.
     * @return list<PredbFeedSource>
     */
    public function make(?array $keys = null): array
    {
        $keys ??= (array) config('predb_feeds.sources', []);

        return array_values(array_map(fn (string $key): PredbFeedSource => $this->makeOne($key), array_unique($keys)));
    }

    public function makeOne(string $key): PredbFeedSource
    {
        $endpoint = (string) config("predb_feeds.endpoints.{$key}", '');
        $pageSize = max(1, (int) config('predb_feeds.page_size', 100));
        $timeout = max(1, (int) config('predb_feeds.timeout', 15));
        $userAgent = (string) config('predb_feeds.user_agent', 'NNTmux-PreDB-Importer/1.0');

        return match ($key) {
            'predb_club' => new PredbClubSource($endpoint, $pageSize, $timeout, $userAgent),
            'predb_net' => new PredbNetSource($endpoint, $pageSize, $timeout, $userAgent),
            'predb_me' => new RssFeedSource('predb_me', 'predb.me', $endpoint, $pageSize, $timeout, $userAgent),
            default => throw new InvalidArgumentException("Unknown PreDB feed source [{$key}]. Available: ".implode(', ', $this->availableKeys())),
        };
    }
}
```

`app/Console/Commands/PredbImportFeed.php`:
```php
<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Predb\Feeds\PredbFeedImporter;
use App\Services\Predb\Feeds\PredbFeedSourceFactory;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

class PredbImportFeed extends Command
{
    /**
     * @var string
     */
    protected $signature = 'predb:import-feed
                            {--source=* : Feed source key(s) to poll (default: predb_feeds.sources)}
                            {--pages=1 : Number of pages to fetch per source (newest first)}
                            {--dry-run : Fetch and report without writing to the database}';

    /**
     * @var string
     */
    protected $description = 'Import PREs from public PreDB JSON/RSS feeds into the predb table';

    public function handle(PredbFeedSourceFactory $factory, PredbFeedImporter $importer): int
    {
        $pages = max(1, (int) $this->option('pages'));
        $dryRun = (bool) $this->option('dry-run');
        /** @var list<string> $requested */
        $requested = array_values(array_filter((array) $this->option('source')));

        try {
            $sources = $factory->make($requested === [] ? null : $requested);
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($sources === []) {
            $this->warn('No PreDB feed sources configured (PREDB_FEED_SOURCES).');

            return self::FAILURE;
        }

        $succeeded = 0;
        $rows = [];

        foreach ($sources as $source) {
            $totals = ['inserted' => 0, 'updated' => 0, 'skipped' => 0];
            $status = 'ok';

            try {
                for ($page = 1; $page <= $pages; $page++) {
                    $entries = $source->fetch($page);

                    if ($entries === []) {
                        break;
                    }

                    foreach ($importer->import($entries, $dryRun) as $key => $count) {
                        $totals[$key] += $count;
                    }
                }

                $succeeded++;
            } catch (Throwable $e) {
                $status = 'failed: '.$e->getMessage();
                Log::warning('PreDB feed source failed', ['source' => $source->key(), 'error' => $e->getMessage()]);
            }

            $rows[] = [$source->key(), $totals['inserted'], $totals['updated'], $totals['skipped'], $status];
        }

        if (! $this->option('quiet')) {
            $this->table(['Source', 'Inserted', 'Updated', 'Skipped', 'Status'], $rows);

            if ($dryRun) {
                $this->comment('Dry run: nothing was written.');
            }
        }

        return $succeeded > 0 ? self::SUCCESS : self::FAILURE;
    }
}
```

- [ ] **Step 4: Schedule the importer and gate the health check.** In `routes/console.php`, replace the tmux health-check line and add the predb entry after `admin:warm-dashboard`:

```php
Schedule::command('tmux:health-check --auto-restart')->everyThirtyMinutes()->withoutOverlapping()->when(static fn (): bool => (bool) config('tmux.scheduled_health_check'));
// ...
// Poll public PreDB JSON/RSS feeds (config/predb_feeds.php); the IRC scraper remains the realtime source
Schedule::command('predb:import-feed --quiet')->everyFiveMinutes()->withoutOverlapping()->runInBackground()->when(static fn (): bool => (bool) config('predb_feeds.enabled'));
```

In `config/tmux.php`, after `'session' => [...]`:

```php
    /*
    | Run the scheduled `tmux:health-check --auto-restart` from the Laravel scheduler.
    | Disable when the scheduler runs in a different container than the tmux engine
    | (e.g. docker/docker-compose.yml): there it would start a second tmux session
    | inside the scheduler container. The indexer container supervises itself instead.
    */
    'scheduled_health_check' => (bool) env('TMUX_SCHEDULED_HEALTH_CHECK', true),
```

- [ ] **Step 5: Add the env keys to `.env.example`.** Put these after `SCRAPE_IRC_PASSWORD=`:

```dotenv
# PreDB feed importer (predb:import-feed, scheduled every 5 minutes when enabled).
# Polls public PreDB JSON/RSS feeds alongside the IRC scraper.
PREDB_FEEDS_ENABLED=false
# Comma-separated: predb_club (JSON), predb_net (JSON), predb_me (RSS)
PREDB_FEED_SOURCES=predb_club,predb_net,predb_me
# Entries requested per page/poll (predb.club caps at 100)
PREDB_FEED_PAGE_SIZE=100
# HTTP timeout in seconds per request
PREDB_FEED_TIMEOUT=15
PREDB_FEED_USER_AGENT=NNTmux-PreDB-Importer/1.0
# RSS URL used by the predb_me source
PREDB_FEED_RSS_URL=https://predb.me/?rss=1
```

Put this after `TMUX_REFRESH_INTERVAL=60`:

```dotenv
# Run `tmux:health-check --auto-restart` from the scheduler. Set false when the scheduler
# runs in a different container than tmux (docker/docker-compose.yml does this).
TMUX_SCHEDULED_HEALTH_CHECK=true
```

- [ ] **Step 6: Run the tests.** Run `... php artisan test --compact tests/Unit/Predb tests/Feature/PredbImportFeedCommandTest.php`. Expected: PASS (13 tests, 51 assertions).
- [ ] **Step 7: Run quality gates in the build image.**
  1. `./vendor/bin/pint <changed files>`
  2. `find <changed> -name '*.php' | xargs -n1 php -l`
  3. `APP_KEY=base64:... APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=/tmp/s.sqlite ./vendor/bin/phpstan analyse --memory-limit=2G`

  Expected: clean. Larastan needs those env vars to boot, because there is no `.env`.
- [ ] **Step 8: Stage.** `git add app/Services/Predb app/Console/Commands/PredbImportFeed.php routes/console.php config/tmux.php .env.example tests/Feature/PredbImportFeedCommandTest.php`

### Task 4: Overlay application image

**Files:**
- Create: `docker/app/Dockerfile`, `docker/app/Caddyfile`, `docker/app/php/zz-docker.ini`, `docker/app/bin/indexer.sh`

**Interfaces:**
- Consumes: `nntmux/base:local`, built from the root `Dockerfile` with build context at the repo root.
- Produces:
  - Image `nntmux/app:local` running as `www-data` (33:33)
  - FrankenPHP serving vhosts `:80` (public) and `:8081` (internal Grafana auth)
  - `/usr/local/bin/nntmux-indexer`
  - The base entrypoint `/app/deploy/cloud/entrypoint.sh`, unchanged: it refuses to start services without `_install/install.lock` and warms the Laravel caches.

- [ ] **Step 1: Write the files**

`docker/app/Dockerfile`:
```dockerfile
# syntax=docker/dockerfile:1
# Overlay for the self-hosted docker/ stack, built FROM the repository's production image
# (root Dockerfile, tagged nntmux/base:local by `make -C docker build`).
# Build context: repository root.
# RapidYenc is not built: FrankenPHP's PHP is thread-safe (ZTS) and RapidYenc's FFI path needs NTS.
ARG BASE_IMAGE=nntmux/base:local

FROM ${BASE_IMAGE}
USER root
# - unrar (non-free): unrar-free cannot open RAR5 archives used by most current posts.
# - iproute2: NNTmux counts live NNTP sockets with `ss`.
# - file, htop, git: helpers used by post-processing, the tmux htop pane and the monitor header.
RUN sed -i 's/^Components: main$/Components: main non-free/' /etc/apt/sources.list.d/debian.sources \
    && apt-get update \
    && apt-get purge -y unrar-free \
    && apt-get install -y --no-install-recommends unrar iproute2 file htop git \
    && rm -rf /var/lib/apt/lists/* \
    && git config --system --add safe.directory /app \
    && install -d -o www-data -g www-data /var/tmp/nntmux
COPY docker/app/php/zz-docker.ini /usr/local/etc/php/conf.d/zz-docker.ini
COPY docker/app/Caddyfile /etc/frankenphp/nntmux.Caddyfile
COPY --chmod=0755 docker/app/bin/indexer.sh /usr/local/bin/nntmux-indexer
USER www-data
CMD ["frankenphp", "run", "--config", "/etc/frankenphp/nntmux.Caddyfile", "--adapter", "caddyfile"]
```

`docker/app/Caddyfile`:
```caddyfile
{
	auto_https off
	admin off
	frankenphp
}

# Public application vhost (reached through the front proxy).
:80 {
	root * /app/public
	encode zstd gzip
	php_server
}

# Internal-only vhost for the front proxy's Grafana forward_auth. It sets the server
# variable that AdminMonitoringController::authorizeGrafana requires (the role nginx's
# auth_request plays in docker/8.5). Port 8081 is never published outside the compose network.
:8081 {
	root * /app/public
	php_server {
		env NNTMUX_GRAFANA_AUTH_REQUEST 1
	}
}
```

`docker/app/php/zz-docker.ini`:
```ini
; docker/ stack overrides (loaded after docker/8.5/php.ini -> 99-nntmux.ini).
[PHP]
expose_php = Off

[opcache]
opcache.enable = 1
opcache.enable_cli = 0
opcache.memory_consumption = 256
opcache.interned_strings_buffer = 32
opcache.max_accelerated_files = 50000
; The image is immutable; code changes require a rebuild.
opcache.validate_timestamps = 0
```

`docker/app/bin/indexer.sh`:
```sh
#!/bin/sh
# Supervises the NNTmux tmux engine inside the indexer container.
# The health check repairs a dead monitor pane or a missing session here (where tmux lives);
# after repeated failures the container exits so Docker's restart policy recreates it.
set -eu
cd /app

SESSION="${TMUX_SESSION_NAME:-nntmux}"
INTERVAL="${INDEXER_HEALTH_INTERVAL:-30}"
MAX_FAILURES="${INDEXER_MAX_FAILURES:-5}"

# Docker signals invoke this callback; it is not reached by the main loop.
# shellcheck disable=SC2317
stop() {
    php artisan tmux:stop --session="$SESSION" --force --no-interaction || true
    exit 0
}
trap 'stop' TERM INT

mkdir -p /var/tmp/nntmux/unrar /var/tmp/nntmux/unzip

php artisan tmux:start --session="$SESSION" --no-interaction

failures=0
while :; do
    sleep "$INTERVAL" &
    wait "$!" || true
    if php artisan tmux:health-check --session="$SESSION" --auto-restart --quiet; then
        failures=0
    else
        failures=$((failures + 1))
        echo "tmux health check failed (${failures}/${MAX_FAILURES})" >&2
        if [ "$failures" -ge "$MAX_FAILURES" ]; then
            exit 1
        fi
    fi
done
```

- [ ] **Step 2: Build.** Run `docker build -t nntmux/base:local -f Dockerfile . && docker build -t nntmux/app:local -f docker/app/Dockerfile .`. Expected: both succeed.
- [ ] **Step 3: Verify the toolchain.** Run `docker run --rm --entrypoint sh nntmux/app:local -c 'unrar | head -1; which ss file htop tmux; php -m | grep OPcache'`. Expected: `UNRAR 6.21 freeware`, all four binaries found, and Zend OPcache listed. RapidYenc is intentionally absent: FrankenPHP's PHP is ZTS, so the stack uses `YENC_DECODER=php`.
- [ ] **Step 4: Stage.** `git add docker/app`

### Task 5: Service configuration, seeds, and env generation

**Files:**
- Create: `docker/config/mariadb/conf.d/99-nntmux.cnf`, `docker/config/manticore/manticore.conf`, `docker/config/redis/redis.conf`, `docker/config/caddy/Caddyfile`, `docker/config/prometheus/prometheus.yml` (a copy of `docker/monitoring/prometheus/prometheus.dev.yml` with an adjusted header), `docker/config/seed/groups.sql`, `docker/config/seed/tuning.sql`, `docker/config/app.env.example`, `docker/.env.example`, `docker/bin/generate-env`

**Interfaces:**
- Produces:
  - `docker/.env`, with compose interpolation for `DB_*`, ports, image tags and memory caps
  - `docker/config/app.env`, the Laravel `.env` mounted at `/app/.env`
  - Seed SQL that `make seed-db` applies

- [ ] **Step 1: Write the configs**

`docker/config/mariadb/conf.d/99-nntmux.cnf`:
```ini
# MariaDB 11.4 tuning for NNTmux in the docker/ stack (container mem cap 13g).
# Derived from the NNTmux wiki "Database Tuning" medium profile; see docker/docs for rationale.
[mysqld]
character-set-server           = utf8mb4
collation-server               = utf8mb4_unicode_ci
skip-name-resolve
# Single node, no replicas: no binary log.
skip-log-bin
# Reduces gap-lock contention between concurrent binaries/backfill/release workers (wiki).
transaction-isolation          = READ-COMMITTED
# Interleaved auto-increment locking (safe without binlog) for concurrent header inserts.
innodb_autoinc_lock_mode       = 2

# InnoDB
innodb_buffer_pool_size        = 8G
innodb_log_file_size           = 2G
innodb_log_buffer_size         = 64M
# Throughput over last-second durability on crash (documented trade-off).
innodb_flush_log_at_trx_commit = 2
innodb_flush_method            = O_DIRECT
innodb_file_per_table          = 1
innodb_io_capacity             = 4000
innodb_io_capacity_max         = 8000
innodb_read_io_threads         = 8
innodb_write_io_threads        = 8
innodb_open_files              = 8000
# NB: innodb_buffer_pool_instances was removed in MariaDB 10.6; do not set it.

# Connections: every tmux worker process holds its own connection.
max_connections                = 400
thread_cache_size              = 100
max_allowed_packet             = 128M
# NntmuxOffsetWorker sets 65535 per session; populate sets 16384.
group_concat_max_len           = 65535

# Per-session buffers and temp tables
tmp_table_size                 = 256M
max_heap_table_size            = 256M
join_buffer_size               = 4M
sort_buffer_size               = 4M
read_buffer_size               = 2M
read_rnd_buffer_size           = 4M

# Table cache
table_open_cache               = 8000
table_definition_cache         = 4000
open_files_limit               = 65535

# Query cache off (contention under concurrent writes)
query_cache_type               = 0
query_cache_size               = 0

# Logging
slow_query_log                 = 1
slow_query_log_file            = /var/log/mysql/slow.log
long_query_time                = 2
log_error                      = /var/log/mysql/error.log
```

`docker/config/manticore/manticore.conf`:
```text
# Manticore 28.4.4 for the docker/ stack (derived from config/manticore.conf).
# Data lives in docker/data/manticore (bind mount), logs in docker/logs/manticore.
searchd
{
	listen			= 9306:mysql
	listen			= 9308:http

	data_dir		= /var/lib/manticore
	binlog_path		= /var/lib/manticore
	log			= /var/log/manticore/searchd.log
	pid_file		= /var/run/manticore/searchd.pid

	network_timeout		= 5
	client_timeout		= 300
	seamless_rotate		= 1
	preopen_tables		= 1
	unlink_old		= 1
	max_packet_size		= 16M
	# The container's nofile ulimit (docker-compose.yml) must be >= this value.
	max_open_files		= 524288
	max_filters		= 256
	max_filter_values	= 4096

	binlog_flush		= 2
	binlog_max_log_size	= 512M
	rt_flush_period		= 900
	expansion_limit		= 1000

	threads			= 6
	qcache_max_bytes	= 64M
	secondary_indexes	= 1
	pseudo_sharding		= 1
}
```

`docker/config/redis/redis.conf`:
```text
# Redis for NNTmux (queues, locks, sessions, cache). Never evict: losing queue/lock keys corrupts work.
bind 0.0.0.0
protected-mode no
port 6379
dir /data
appendonly yes
appendfsync everysec
save ""
maxmemory 768mb
maxmemory-policy noeviction
tcp-keepalive 60
```

`docker/config/caddy/Caddyfile`:
```caddyfile
{
	admin off
	auto_https off
}

# Front proxy for the NNTmux docker/ stack. Set APP_URL to match the published port.
:8080 {
	encode zstd gzip

	handle /healthz {
		respond "ok" 200
	}

	# Grafana: every request is authorised against the Laravel admin session by the web
	# container's internal vhost (:8081). The JWT it returns is passed to Grafana as a
	# header and never reaches the browser; a client-supplied header is always stripped.
	# `route` keeps this literal order; otherwise Caddy sorts request_header after
	# forward_auth and deletes the JWT forward_auth just copied in.
	handle /grafana* {
		route {
			request_header -X-JWT-Assertion
			forward_auth web:8081 {
				uri /admin/monitoring/grafana-auth
				copy_headers X-NNTmux-Grafana-JWT>X-JWT-Assertion
			}
			reverse_proxy grafana:3000
		}
	}

	handle {
		reverse_proxy web:80
	}
}
```

`docker/config/seed/groups.sql`:
```sql
-- Curated starter groups for the docker/ stack: active for new headers and backfilled 3 days.
-- Re-runnable: existing groups are (re)activated without touching their article pointers.
INSERT INTO usenet_groups (name, backfill_target, active, backfill, description)
VALUES
    ('alt.binaries.teevee', 3, 1, 1, 'TV'),
    ('alt.binaries.moovee', 3, 1, 1, 'Movies'),
    ('alt.binaries.hdtv.x264', 3, 1, 1, 'HD TV'),
    ('alt.binaries.hdtv', 3, 1, 1, 'HD TV'),
    ('alt.binaries.boneless', 3, 1, 1, 'Misc'),
    ('alt.binaries.multimedia', 3, 1, 1, 'Multimedia'),
    ('alt.binaries.tv', 3, 1, 1, 'TV'),
    ('alt.binaries.x264', 3, 1, 1, 'x264'),
    ('alt.binaries.movies.divx', 3, 1, 1, 'Movies'),
    ('alt.binaries.movies.x264', 3, 1, 1, 'Movies'),
    ('alt.binaries.misc', 3, 1, 1, 'Misc'),
    ('alt.binaries.cores', 3, 1, 1, 'Misc'),
    ('alt.binaries.mom', 3, 1, 1, 'Misc'),
    ('alt.binaries.etc', 3, 1, 1, 'Misc'),
    ('alt.binaries.dvd', 3, 1, 1, 'DVD'),
    ('alt.binaries.bloaf', 3, 1, 1, 'Misc'),
    ('alt.binaries.ath', 3, 1, 1, 'Misc'),
    ('alt.binaries.sounds.lossless', 3, 1, 1, 'Music (lossless)'),
    ('alt.binaries.sounds.mp3.complete_cd', 3, 1, 1, 'Music (mp3)'),
    ('alt.binaries.e-book', 3, 1, 1, 'Books')
ON DUPLICATE KEY UPDATE active = 1, backfill = 1, backfill_target = VALUES(backfill_target);
```

`docker/config/seed/tuning.sql`:
```sql
-- Runtime processing settings for the docker/ stack (typed single-row configuration tables).
-- Sized to stay well under the primary provider's connection limit (~30 of 73 NNTP connections).
-- Settings are cached for 300s; `make -C docker seed` clears the cache afterwards.
UPDATE ingestion_configurations SET
    binary_threads = 6,
    backfill_threads = 4,
    release_threads = 2,
    max_messages = 20000,
    backfill_days_mode = 1,
    backfill_quantity = 200000,
    updated_at = NOW();

UPDATE post_processing_configurations SET
    post_threads = 8,
    nfo_threads = 2,
    post_threads_non = 3,
    post_threads_amazon = 2,
    fix_name_threads = 2,
    -- 0 = extract the first RAR/ZIP volume with unrar/unzip so ffmpeg and mediainfo get a video file;
    -- 1 only lists archive contents (no samples, previews or mediainfo).
    extract_using_rar_info = 0,
    updated_at = NOW();

UPDATE tmux_configurations SET
    sequential_mode = 0,
    binaries_enabled = 1,
    backfill_mode = 1,
    releases_enabled = 1,
    post_mode = 3,
    post_non_mode = 1,
    post_amazon_mode = 1,
    fix_names_enabled = 1,
    run_irc_scraper = 1,
    htop_enabled = 1,
    updated_at = NOW();
```

`docker/config/app.env.example`:
```dotenv
# Laravel .env overrides for the docker/ stack.
#
# docker/bin/generate-env builds docker/config/app.env (gitignored, mode 600) from:
#   1. the repository's .env.example (every key, upstream defaults),
#   2. the overrides in this file,
#   3. generated secrets (__GENERATED__) and NNTP credentials read from USENET_SERVERS_JSON (__FROM_CONFIG__).
# Existing values in app.env are never overwritten, so re-running generate-env is safe.

APP_NAME=NNTmux
APP_ENV=production
APP_DEBUG=false
APP_URL=http://localhost:8080
APP_KEY=__GENERATED__
TRUSTED_PROXIES=172.31.42.0/24
LOG_CHANNEL=daily

DB_CONNECTION=mariadb
DB_HOST=mariadb
DB_PORT=3306
DB_DATABASE=nntmux
DB_USERNAME=nntmux
DB_PASSWORD=__GENERATED__
DB_ROOTPASSWORD=__GENERATED__

REDIS_HOST=redis
REDIS_PORT=6379
REDIS_PASSWORD=null
CACHE_STORE=redis
QUEUE_CONNECTION=redis
SESSION_DRIVER=redis
HORIZON_QUEUES=api-audit,default,emails,incidents,contactemail,newreg,welcomeemails

SEARCH_DRIVER=manticore
MANTICORESEARCH_HOST=manticore
MANTICORESEARCH_PORT=9308

MAIL_MAILER=smtp
MAIL_HOST=mailpit
MAIL_PORT=1025
MAIL_USERNAME=null
MAIL_PASSWORD=null
MAIL_ENCRYPTION=null
MAIL_FROM_ADDRESS=nntmux@example.com
MAIL_FROM_NAME=NNTmux

ADMIN_USER=admin
ADMIN_EMAIL=admin@example.com
ADMIN_PASS=__GENERATED__

# Primary = header source; alternate = fallback for articles missing on the primary.
NNTP_SERVER=__FROM_CONFIG__
NNTP_PORT=__FROM_CONFIG__
NNTP_USERNAME=__FROM_CONFIG__
NNTP_PASSWORD=__FROM_CONFIG__
NNTP_SSLENABLED=__FROM_CONFIG__
NNTP_CONNECTIONS=__FROM_CONFIG__
NNTP_SOCKET_TIMEOUT=120
NNTP_SERVER_A=__FROM_CONFIG__
NNTP_PORT_A=__FROM_CONFIG__
NNTP_USERNAME_A=__FROM_CONFIG__
NNTP_PASSWORD_A=__FROM_CONFIG__
NNTP_SSLENABLED_A=__FROM_CONFIG__
NNTP_CONNECTIONS_A=__FROM_CONFIG__
NNTP_SOCKET_TIMEOUT_A=120
USE_ALTERNATE_NNTP_SERVER=false
# XFEATURE GZIP headers produced decode errors against Newshosting; upstream default is false.
NNTP_COMPRESSED_HEADERS=false

# Keep every upload built from different articles (re-posts/alternates); see xbmc4lyfe/newznab-tmux#1.
RELEASE_DEDUPE_ENABLED=false
# Per-article NZB import lock store (cache_locks table); see xbmc4lyfe/newznab-tmux#1.
RELEASE_DEDUPE_LOCK_STORE=database

# Despite the name, this gates ALL additional post-processing (ffmpeg/mediainfo samples,
# RAR/ZIP file lists, PAR2 renames): releases are only queued (passwordstatus=-1) when true.
# See BUGS.md #18.
CHECK_PASSWORDED_RARS=true

COVERS_PATH=/app/storage/covers
PATH_TO_NZBS=/app/storage/nzb
TEMP_UNRAR_PATH=/var/tmp/nntmux/unrar
TEMP_UNZIP_PATH=/var/tmp/nntmux/unzip

# FrankenPHP's PHP is thread-safe (ZTS); RapidYenc needs NTS, so use the PHP decoder.
YENC_DECODER=php
YENC_NATIVE_LIBRARY=

SCRAPE_IRC_SERVER=irc.synirc.net
SCRAPE_IRC_PORT=6697
SCRAPE_IRC_TLS=true
SCRAPE_IRC_USERNAME=__GENERATED__

PREDB_FEEDS_ENABLED=true
PREDB_FEED_SOURCES=predb_club,predb_net,predb_me

# The scheduler runs in its own container; the indexer supervises tmux itself.
TMUX_SCHEDULED_HEALTH_CHECK=false
# The monitor exact-COUNTs parts/binaries/collections each slow refresh; at tens of millions of
# rows that is a full scan, so refresh every 15 minutes instead of every minute.
TMUX_REFRESH_INTERVAL=900
TMUX_USE_POWERLINE=false
TMUX_USE_NERD_FONTS=false

MONITORING_ENABLED=true
MONITORING_PUSHGATEWAY_URL=http://pushgateway:9091
GRAFANA_URL=/grafana
GRAFANA_AUTH=proxy
GRAFANA_JWT_PRIVATE_KEY_PATH=storage/app/monitoring/grafana-jwt.key
```

`docker/.env.example`:
```dotenv
# Compose-level settings for docker/docker-compose.yml (copied to docker/.env by bin/generate-env).
# Secrets below are generated; keep docker/.env out of git (it is ignored).

COMPOSE_PROJECT_NAME=nntmux

# Front proxy (Caddy). 127.0.0.1 = this Mac only; 0.0.0.0 = LAN.
PROXY_BIND=127.0.0.1
APP_PORT=8080
MAILPIT_PORT=8025
PROMETHEUS_PORT=9090

TZ=UTC

# Image tags
APP_IMAGE=nntmux/app:local
MARIADB_IMAGE=mariadb:11.4.8
REDIS_IMAGE=redis:7.4.2-alpine
MANTICORE_IMAGE=manticoresearch/manticore:28.4.4
CADDY_IMAGE=caddy:2.10.2-alpine
MAILPIT_IMAGE=axllent/mailpit:v1.27.10

# Database credentials (must match DB_* in config/app.env; generate-env writes both).
DB_DATABASE=nntmux
DB_USERNAME=nntmux
DB_PASSWORD=__GENERATED__
DB_ROOTPASSWORD=__GENERATED__

# Memory caps (OrbStack currently allows ~24.5 GB). MariaDB's buffer pool lives in
# config/mariadb/conf.d/99-nntmux.cnf; keep it ~2 GB below MARIADB_MEMORY.
MARIADB_MEMORY=13g
MANTICORE_MEMORY=3g
REDIS_MEMORY=1g
INDEXER_MEMORY=6g
WEB_MEMORY=1g
HORIZON_MEMORY=1g
SCHEDULER_MEMORY=1g
```

`docker/bin/generate-env` (`chmod +x`):
```python
#!/usr/bin/env python3
"""Generate docker/.env and docker/config/app.env for the NNTmux docker/ stack.

- docker/.env            <- docker/.env.example with generated secrets
- docker/config/app.env  <- repo .env.example + docker/config/app.env.example overrides
                            + generated secrets + NNTP credentials from USENET_SERVERS_JSON

Existing non-placeholder values are never overwritten, so this is safe to re-run.
Environment overrides:
  USENET_SERVERS_JSON  JSON file with a "usenet_servers" list (default: docker/config/usenet_servers.json,
                       gitignored). Only read when config/app.env has no NNTP credentials yet.
  NNTP_PRIMARY_HOST    host to use as primary   (default: news.newshosting.com)
  NNTP_ALT_HOST        host to use as alternate (default: news.frugalusenet.com)
"""

from __future__ import annotations

import base64
import json
import os
import re
import secrets
import string
import sys
from pathlib import Path

DOCKER_DIR = Path(__file__).resolve().parent.parent
REPO_DIR = DOCKER_DIR.parent
PLACEHOLDERS = {"__GENERATED__", "__FROM_CONFIG__", ""}
LINE = re.compile(r"^\s*([A-Za-z_][A-Za-z0-9_]*)\s*=(.*)$")


def parse(path: Path) -> dict[str, str]:
    values: dict[str, str] = {}
    if not path.exists():
        return values
    for raw in path.read_text().splitlines():
        match = LINE.match(raw)
        if match:
            values[match.group(1)] = match.group(2).strip()
    return values


def render(template: Path, values: dict[str, str]) -> str:
    """Keep the template's comments/order, substitute values, append keys the template lacks."""
    seen: set[str] = set()
    out: list[str] = []
    for raw in template.read_text().splitlines():
        match = LINE.match(raw)
        if match and match.group(1) in values:
            key = match.group(1)
            if key in seen:
                continue
            seen.add(key)
            out.append(f"{key}={values[key]}")
        else:
            out.append(raw)
    extra = [k for k in values if k not in seen]
    if extra:
        out.append("")
        out.append("# ── docker/ stack overrides ───────────────────────────────────────")
        out.extend(f"{k}={values[k]}" for k in extra)
    return "\n".join(out) + "\n"


def password(length: int = 32) -> str:
    alphabet = string.ascii_letters + string.digits
    return "".join(secrets.choice(alphabet) for _ in range(length))


def irc_nick() -> str:
    words = ["quiet", "amber", "north", "maple", "river", "cobalt", "ember", "harbor", "willow", "cedar"]
    animals = ["fox", "owl", "lynx", "heron", "otter", "wren", "badger", "falcon", "marten", "finch"]
    return f"{secrets.choice(words)}{secrets.choice(animals)}{secrets.randbelow(9000) + 1000}"


def nntp_servers() -> tuple[dict, dict]:
    config_path = Path(os.environ.get("USENET_SERVERS_JSON", DOCKER_DIR / "config/usenet_servers.json"))
    try:
        servers = json.loads(config_path.read_text())["usenet_servers"]
    except (OSError, KeyError, json.JSONDecodeError) as exc:
        sys.exit(f"Cannot read usenet_servers from {config_path}: {exc}")
    by_host = {s["host"]: s for s in servers}
    primary_host = os.environ.get("NNTP_PRIMARY_HOST", "news.newshosting.com")
    alt_host = os.environ.get("NNTP_ALT_HOST", "news.frugalusenet.com")
    for host in (primary_host, alt_host):
        if host not in by_host:
            sys.exit(f"{host} not found in {config_path}; set NNTP_PRIMARY_HOST / NNTP_ALT_HOST")
    return by_host[primary_host], by_host[alt_host]


def nntp_values(server: dict, suffix: str) -> dict[str, str]:
    return {
        f"NNTP_SERVER{suffix}": server["host"],
        f"NNTP_PORT{suffix}": str(server.get("port", 563)),
        f"NNTP_USERNAME{suffix}": server["username"],
        f"NNTP_PASSWORD{suffix}": server["password"],
        f"NNTP_SSLENABLED{suffix}": "true" if server.get("ssl", True) else "false",
        f"NNTP_CONNECTIONS{suffix}": str(server.get("connections", 10)),
    }


def merge(existing: dict[str, str], wanted: dict[str, str]) -> dict[str, str]:
    merged = dict(wanted)
    for key, value in existing.items():
        if value not in PLACEHOLDERS:
            merged[key] = value
    return merged


def write_secret(path: Path, content: str) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    fd = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_TRUNC, 0o600)
    with os.fdopen(fd, "w") as handle:
        handle.write(content)
    os.chmod(path, 0o600)


def main() -> None:
    compose_env_path = DOCKER_DIR / ".env"
    app_env_path = DOCKER_DIR / "config/app.env"

    existing_compose = parse(compose_env_path)
    existing_app = parse(app_env_path)

    db_password = existing_app.get("DB_PASSWORD") or existing_compose.get("DB_PASSWORD") or ""
    db_root = existing_app.get("DB_ROOTPASSWORD") or existing_compose.get("DB_ROOTPASSWORD") or ""
    db_password = db_password if db_password not in PLACEHOLDERS else password()
    db_root = db_root if db_root not in PLACEHOLDERS else password()

    # docker/.env
    compose_values = parse(DOCKER_DIR / ".env.example")
    compose_values.update({"DB_PASSWORD": db_password, "DB_ROOTPASSWORD": db_root})
    compose_values = merge(existing_compose, compose_values)
    write_secret(compose_env_path, render(DOCKER_DIR / ".env.example", compose_values))

    # docker/config/app.env
    app_values = parse(REPO_DIR / ".env.example")
    app_values.update(parse(DOCKER_DIR / "config/app.env.example"))
    nntp_keys = ("NNTP_SERVER", "NNTP_USERNAME", "NNTP_PASSWORD", "NNTP_SERVER_A", "NNTP_USERNAME_A", "NNTP_PASSWORD_A")
    if all(existing_app.get(key, "") not in PLACEHOLDERS for key in nntp_keys):
        primary = {"host": existing_app["NNTP_SERVER"]}
        alternate = {"host": existing_app["NNTP_SERVER_A"]}
    else:
        primary, alternate = nntp_servers()
        app_values.update(nntp_values(primary, ""))
        app_values.update(nntp_values(alternate, "_A"))
    app_values.update({
        "APP_KEY": "base64:" + base64.b64encode(secrets.token_bytes(32)).decode(),
        "DB_PASSWORD": db_password,
        "DB_ROOTPASSWORD": db_root,
        "DB_DATABASE": compose_values["DB_DATABASE"],
        "DB_USERNAME": compose_values["DB_USERNAME"],
        "ADMIN_PASS": password(24),
        "SCRAPE_IRC_USERNAME": irc_nick(),
    })
    app_values = merge(existing_app, app_values)
    unresolved = [k for k, v in app_values.items() if v in ("__GENERATED__", "__FROM_CONFIG__")]
    if unresolved:
        sys.exit(f"Unresolved placeholders in app.env: {', '.join(unresolved)}")
    write_secret(app_env_path, render(REPO_DIR / ".env.example", app_values))

    print(f"Wrote {compose_env_path.relative_to(REPO_DIR)} and {app_env_path.relative_to(REPO_DIR)} (mode 600).")
    print(f"NNTP primary={primary['host']} alternate={alternate['host']}  IRC nick={app_values['SCRAPE_IRC_USERNAME']}")
    print(f"Admin login: {app_values['ADMIN_USER']} (password: grep ADMIN_PASS {app_env_path.relative_to(REPO_DIR)})")


if __name__ == "__main__":
    main()
```

- [ ] **Step 2: Generate the env files.** Run `make -C docker env`. Expected output: `NNTP primary=news.newshosting.com alternate=news.frugalusenet.com`. Both files should be mode 600. Run it a second time and confirm `APP_KEY` is unchanged (idempotence).
- [ ] **Step 3: Check for leaks.** Run `git status --short docker/ | grep -E '\.env$|app\.env$'`. Expected: no output.
- [ ] **Step 4: Stage.** `git add docker/config docker/.env.example docker/bin`. The ignore rules from Task 1 keep the secrets out.

### Task 6: Compose stack and Makefile

**Files:**
- Create: `docker/docker-compose.yml`, `docker/Makefile`

**Interfaces:**
- Consumes: Tasks 4 and 5.
- Produces: `make -C docker {env,dirs,build,init,seed-db,seed,up,down,restart,ps,logs,tmux,shell,artisan,mysql,backup,status}`

- [ ] **Step 1: Write the files**

`docker/docker-compose.yml`:
```yaml
# NNTmux self-hosted stack. All persistent state is bind-mounted under docker/data and docker/logs.
# Design: docker/docs/2026-10-08-nntmux-docker-stack-design.md   Lifecycle: make -C docker help
name: ${COMPOSE_PROJECT_NAME:-nntmux}

x-app: &app
  image: ${APP_IMAGE:-nntmux/app:local}
  init: true
  user: "33:33"
  restart: unless-stopped
  stop_grace_period: 120s
  environment:
    TZ: ${TZ:-UTC}
    # www-data's login shell is nologin; tmux panes spawn $SHELL.
    SHELL: /bin/bash
    # Pulse's storage ingest upserts pulse_aggregates from every short-lived worker
    # process and deadlocks under the tmux fan-out; only the web container records.
    PULSE_ENABLED: "false"
  volumes:
    - ./config/app.env:/app/.env
    - ./data/storage:/app/storage
    - ./data/install:/app/_install
  tmpfs:
    - /var/tmp/nntmux:uid=33,gid=33,mode=0770,size=2g
  networks: [nntmux]
  depends_on:
    mariadb:
      condition: service_healthy
    redis:
      condition: service_healthy
    manticore:
      condition: service_healthy

services:
  proxy:
    image: ${CADDY_IMAGE:-caddy:2.10.2-alpine}
    restart: unless-stopped
    ports:
      - "${PROXY_BIND:-127.0.0.1}:${APP_PORT:-8080}:8080"
    volumes:
      - ./config/caddy/Caddyfile:/etc/caddy/Caddyfile:ro
      - ./data/caddy/data:/data
      - ./data/caddy/config:/config
      - ./logs/caddy:/var/log/caddy
    networks: [nntmux]
    depends_on:
      web:
        condition: service_healthy
    healthcheck:
      test: ["CMD", "wget", "-qO-", "http://127.0.0.1:8080/healthz"]
      interval: 15s
      timeout: 5s
      retries: 3
    mem_limit: 256m

  web:
    <<: *app
    environment:
      TZ: ${TZ:-UTC}
      SHELL: /bin/bash
      PULSE_ENABLED: "true"
    healthcheck:
      test: ["CMD", "curl", "-fsS", "http://127.0.0.1/up"]
      interval: 15s
      timeout: 5s
      retries: 5
      start_period: 60s
    mem_limit: ${WEB_MEMORY:-1g}

  horizon:
    <<: *app
    command: ["php", "artisan", "horizon"]
    healthcheck:
      test: ["CMD", "php", "artisan", "horizon:status"]
      interval: 30s
      timeout: 15s
      retries: 3
      start_period: 60s
    mem_limit: ${HORIZON_MEMORY:-1g}

  scheduler:
    <<: *app
    command: ["php", "artisan", "schedule:work"]
    healthcheck:
      test: ["CMD", "pgrep", "-f", "schedule:work"]
      interval: 30s
      timeout: 5s
      retries: 3
      start_period: 60s
    mem_limit: ${SCHEDULER_MEMORY:-1g}

  indexer:
    <<: *app
    command: ["/usr/local/bin/nntmux-indexer"]
    tty: true
    stdin_open: true
    environment:
      TZ: ${TZ:-UTC}
      SHELL: /bin/bash
      PULSE_ENABLED: "false"
      TERM: xterm-256color
      TMUX_SESSION_NAME: nntmux
    healthcheck:
      test: ["CMD", "php", "artisan", "tmux:health-check", "--session=nntmux", "--require-session", "--quiet"]
      interval: 60s
      timeout: 20s
      retries: 3
      start_period: 120s
    mem_limit: ${INDEXER_MEMORY:-6g}
    depends_on:
      mariadb:
        condition: service_healthy
      redis:
        condition: service_healthy
      manticore:
        condition: service_healthy
      web:
        condition: service_healthy

  mariadb:
    image: ${MARIADB_IMAGE:-mariadb:11.4.8}
    restart: unless-stopped
    stop_grace_period: 120s
    environment:
      TZ: ${TZ:-UTC}
      MARIADB_DATABASE: ${DB_DATABASE:-nntmux}
      MARIADB_USER: ${DB_USERNAME:-nntmux}
      MARIADB_PASSWORD: ${DB_PASSWORD:?run docker/bin/generate-env}
      MARIADB_ROOT_PASSWORD: ${DB_ROOTPASSWORD:?run docker/bin/generate-env}
      MARIADB_AUTO_UPGRADE: "1"
    volumes:
      - ./data/mariadb:/var/lib/mysql
      - ./config/mariadb/conf.d:/etc/mysql/conf.d:ro
      - ./logs/mariadb:/var/log/mysql
    networks: [nntmux]
    ulimits:
      nofile:
        soft: 65535
        hard: 65535
    healthcheck:
      test: ["CMD", "healthcheck.sh", "--connect", "--innodb_initialized"]
      interval: 10s
      timeout: 5s
      retries: 10
      start_period: 60s
    mem_limit: ${MARIADB_MEMORY:-10g}

  redis:
    image: ${REDIS_IMAGE:-redis:7.4.2-alpine}
    restart: unless-stopped
    command: ["redis-server", "/usr/local/etc/redis/redis.conf"]
    volumes:
      - ./config/redis/redis.conf:/usr/local/etc/redis/redis.conf:ro
      - ./data/redis:/data
    networks: [nntmux]
    healthcheck:
      test: ["CMD", "redis-cli", "ping"]
      interval: 10s
      timeout: 5s
      retries: 5
    mem_limit: ${REDIS_MEMORY:-1g}

  manticore:
    image: ${MANTICORE_IMAGE:-manticoresearch/manticore:28.4.4}
    restart: unless-stopped
    environment:
      TZ: ${TZ:-UTC}
      EXTRA: "1"
    volumes:
      - ./config/manticore/manticore.conf:/etc/manticoresearch/manticore.conf
      - ./data/manticore:/var/lib/manticore
      - ./logs/manticore:/var/log/manticore
    networks: [nntmux]
    ulimits:
      nproc: 65535
      nofile:
        soft: 1048576
        hard: 1048576
      memlock:
        soft: -1
        hard: -1
    healthcheck:
      test: ["CMD", "mysql", "-h127.0.0.1", "-P9306", "-e", "SHOW TABLES"]
      interval: 15s
      timeout: 5s
      retries: 5
      start_period: 30s
    mem_limit: ${MANTICORE_MEMORY:-3g}

  mailpit:
    image: ${MAILPIT_IMAGE:-axllent/mailpit:v1.27}
    restart: unless-stopped
    ports:
      - "127.0.0.1:${MAILPIT_PORT:-8025}:8025"
    networks: [nntmux]
    mem_limit: 128m

  # ── Monitoring (Prometheus + Grafana); dashboards shared with docker/monitoring ──
  prometheus:
    image: prom/prometheus:v3.15.0
    restart: unless-stopped
    user: "0:0"
    command:
      - --config.file=/etc/prometheus/prometheus.yml
      - --storage.tsdb.path=/prometheus
      - --storage.tsdb.retention.time=15d
    ports:
      - "127.0.0.1:${PROMETHEUS_PORT:-9090}:9090"
    volumes:
      - ./config/prometheus/prometheus.yml:/etc/prometheus/prometheus.yml:ro
      - ./data/prometheus:/prometheus
    networks: [nntmux]
    mem_limit: 512m

  pushgateway:
    image: prom/pushgateway:v1.11.3
    restart: unless-stopped
    networks: [nntmux]
    mem_limit: 128m

  grafana:
    image: grafana/grafana:13.2.3
    restart: unless-stopped
    user: "0:0"
    environment:
      PROMETHEUS_URL: http://prometheus:9090
      GF_SERVER_ROOT_URL: "%(protocol)s://%(domain)s:${APP_PORT:-8080}/grafana/"
      GF_SERVER_SERVE_FROM_SUB_PATH: "true"
      GF_SECURITY_ADMIN_USER: nntmux-grafana-admin
      GF_SECURITY_ALLOW_EMBEDDING: "true"
      GF_SECURITY_COOKIE_SAMESITE: lax
      GF_AUTH_DISABLE_LOGIN_FORM: "true"
      GF_AUTH_BASIC_ENABLED: "false"
      GF_AUTH_ANONYMOUS_ENABLED: "false"
      GF_USERS_ALLOW_SIGN_UP: "false"
      GF_AUTH_JWT_ENABLED: "true"
      GF_AUTH_JWT_URL_LOGIN: "false"
      GF_AUTH_JWT_HEADER_NAME: X-JWT-Assertion
      GF_AUTH_JWT_KEY_FILE: /etc/grafana/nntmux-jwt/grafana-jwt.pub
      GF_AUTH_JWT_USERNAME_CLAIM: sub
      GF_AUTH_JWT_EMAIL_CLAIM: email
      GF_AUTH_JWT_EXPECT_CLAIMS: '{"iss": "nntmux", "aud": "grafana"}'
      GF_AUTH_JWT_AUTO_SIGN_UP: "true"
      GF_AUTH_JWT_ROLE_ATTRIBUTE_PATH: role
      GF_AUTH_JWT_ROLE_ATTRIBUTE_STRICT: "true"
      GF_LIVE_MAX_CONNECTIONS: "0"
      GF_ANALYTICS_REPORTING_ENABLED: "false"
      GF_ANALYTICS_CHECK_FOR_UPDATES: "false"
      GF_ANALYTICS_CHECK_FOR_PLUGIN_UPDATES: "false"
      GF_NEWS_NEWS_FEED_ENABLED: "false"
      GF_DASHBOARDS_DEFAULT_HOME_DASHBOARD_PATH: /etc/grafana/dashboards/nntmux/nntmux-host.json
    volumes:
      - ../docker/monitoring/grafana/provisioning:/etc/grafana/provisioning:ro
      - ../docker/monitoring/grafana/dashboards:/etc/grafana/dashboards/nntmux:ro
      - ./data/storage/app/monitoring/public:/etc/grafana/nntmux-jwt:ro
      - ./data/grafana:/var/lib/grafana
    networks: [nntmux]
    mem_limit: 512m

  node-exporter:
    image: prom/node-exporter:v1.12.1
    restart: unless-stopped
    pid: host
    command:
      - --path.procfs=/host/proc
      - --path.sysfs=/host/sys
      - --path.rootfs=/host/root
    volumes:
      - /proc:/host/proc:ro
      - /sys:/host/sys:ro
      - /:/host/root:ro
    networks: [nntmux]
    mem_limit: 64m

  mysqld-exporter:
    image: prom/mysqld-exporter:v0.20.0
    restart: unless-stopped
    command:
      - --mysqld.address=mariadb:3306
      - --mysqld.username=root
    environment:
      MYSQLD_EXPORTER_PASSWORD: ${DB_ROOTPASSWORD}
    networks: [nntmux]
    depends_on: [mariadb]
    mem_limit: 64m

  redis-exporter:
    image: oliver006/redis_exporter:v1.93.0
    restart: unless-stopped
    environment:
      REDIS_ADDR: redis://redis:6379
    networks: [nntmux]
    depends_on: [redis]
    mem_limit: 64m

networks:
  nntmux:
    driver: bridge
    ipam:
      config:
        - subnet: 172.31.42.0/24
```

`docker/Makefile`:
```makefile
# NNTmux docker/ stack lifecycle. Run from anywhere: make -C docker <target>
# Design: docs/2026-10-08-nntmux-docker-stack-design.md
.DEFAULT_GOAL := help
SHELL := /bin/bash

DC       := docker compose --project-directory $(CURDIR) -f $(CURDIR)/docker-compose.yml
APP_RUN  := $(DC) run --rm --no-deps web
ARTISAN  := $(DC) exec -T web php artisan
REPO     := $(abspath $(CURDIR)/..)
-include $(CURDIR)/.env
DB_EXEC   = $(DC) exec -T mariadb mariadb -uroot -p"$(DB_ROOTPASSWORD)" $(DB_DATABASE)
s        ?=
cmd      ?=

DATA_DIRS := data/storage data/install data/mariadb data/redis data/manticore data/caddy/data data/caddy/config \
             data/prometheus data/grafana logs/mariadb logs/manticore logs/caddy backups

.PHONY: help
help: ## Show targets
	@grep -hE '^[a-zA-Z_-]+:.*?## ' $(MAKEFILE_LIST) | awk 'BEGIN{FS=":.*?## "}{printf "  \033[36m%-12s\033[0m %s\n",$$1,$$2}'

.PHONY: env
env: ## Generate docker/.env and config/app.env (secrets, NNTP creds); safe to re-run
	@$(CURDIR)/bin/generate-env

.PHONY: dirs
dirs: ## Create bind-mount directories
	@cd $(CURDIR) && mkdir -p $(DATA_DIRS)

.PHONY: build
build: ## Build nntmux/base:local (root Dockerfile) and nntmux/app:local (overlay)
	docker build -t nntmux/base:local -f $(REPO)/Dockerfile $(REPO)
	docker build -t nntmux/app:local -f $(CURDIR)/app/Dockerfile $(REPO)

.PHONY: init
init: env dirs build ## First-time install: build, migrate+seed, indexes, groups, tuning, predb seed, start
	$(DC) up -d --wait mariadb redis manticore mailpit
	@if [ -f $(CURDIR)/data/install/install.lock ]; then echo "install.lock present; skipping nntmux:deploy-init"; \
	else $(APP_RUN) php artisan nntmux:deploy-init --no-interaction; fi
	$(APP_RUN) php artisan monitoring:install --sail --no-interaction
	$(MAKE) -C $(CURDIR) seed-db
	$(APP_RUN) php artisan predb:import-feed --pages=20
	$(DC) up -d
	@echo "NNTmux: http://localhost:$(or $(APP_PORT),8080)  (admin password: grep ADMIN_PASS docker/config/app.env)"

.PHONY: seed-db
seed-db: ## Apply config/seed/groups.sql + tuning.sql and clear the settings cache
	$(DB_EXEC) < $(CURDIR)/config/seed/groups.sql
	$(DB_EXEC) < $(CURDIR)/config/seed/tuning.sql
	$(APP_RUN) php artisan cache:clear

.PHONY: seed
seed: seed-db ## Re-seed groups/tuning and restart the indexer to pick it up
	$(DC) restart indexer

.PHONY: up
up: ## Start the whole stack
	$(DC) up -d

.PHONY: down
down: ## Stop the stack (tmux session stops cleanly)
	$(DC) down

.PHONY: restart
restart: ## Restart app services (s=service for one)
	$(DC) restart $(or $(s),web horizon scheduler indexer)

.PHONY: ps
ps: ## Service status / health
	$(DC) ps

.PHONY: logs
logs: ## Follow logs (s=service)
	$(DC) logs -f --tail=200 $(s)

.PHONY: tmux
tmux: ## Attach to the indexer tmux session (detach: Ctrl-a d)
	$(DC) exec indexer tmux attach -t nntmux

.PHONY: shell
shell: ## Shell in the web container
	$(DC) exec web bash

.PHONY: artisan
artisan: ## Run artisan: make artisan cmd="predb:import-feed --dry-run"
	$(DC) exec web php artisan $(cmd)

.PHONY: mysql
mysql: ## MariaDB client as root
	$(DC) exec mariadb mariadb -uroot -p"$(DB_ROOTPASSWORD)" $(DB_DATABASE)

.PHONY: backup
backup: ## Dump MariaDB to backups/ (keeps 7)
	@mkdir -p $(CURDIR)/backups
	$(DC) exec -T mariadb mariadb-dump -uroot -p"$(DB_ROOTPASSWORD)" --single-transaction --quick --routines $(DB_DATABASE) \
		| gzip > $(CURDIR)/backups/nntmux-$$(date +%Y%m%d-%H%M%S).sql.gz
	@ls -1t $(CURDIR)/backups/nntmux-*.sql.gz | tail -n +8 | xargs -r rm --
	@ls -lh $(CURDIR)/backups | tail -n 3

.PHONY: status
status: ## Indexing progress snapshot (groups, binaries, releases, predb)
	@echo "SELECT COUNT(*) active_groups, SUM(last_record>0) scanned FROM usenet_groups WHERE active=1; \
	SELECT (SELECT COUNT(*) FROM collections) collections, (SELECT COUNT(*) FROM binaries) binaries, (SELECT COUNT(*) FROM releases) releases; \
	SELECT source, COUNT(*) pres FROM predb GROUP BY source ORDER BY 2 DESC LIMIT 10;" | $(DB_EXEC) -t
```

- [ ] **Step 2: Validate.** Run `docker compose --project-directory docker -f docker/docker-compose.yml config -q`. Expected: exits 0 with no output.
- [ ] **Step 3: Probe bind-mount ownership on OrbStack.** Run `docker run --rm -u 33:33 -v $PWD/docker/data/_probe:/p alpine sh -c 'touch /p/a'`. Expected: success. OrbStack presents bind-mounted files as owned by whichever user is reading them, so no host `chown` is needed.
- [ ] **Step 4: Stage.** `git add docker/docker-compose.yml docker/Makefile`

### Task 7: Bootstrap and verification

**Files:** none, runtime only.

- [ ] **Step 1: Initialise.** Run `make -C docker init`. It builds the images, then:
  1. starts the data services and waits for them to be healthy
  2. runs `nntmux:deploy-init`: migrate and seed, create the admin, create the Manticore indexes, write `install.lock`
  3. runs `monitoring:install --sail` to create the Grafana JWT keypair and set the monitoring `.env` keys
  4. runs `seed-db`
  5. runs `predb:import-feed --pages=20`
  6. runs `up -d`
- [ ] **Step 2: Check health.** `make -C docker ps` should list every service as `running` or `healthy`.
- [ ] **Step 3: Check the web app.** `curl -fsS http://127.0.0.1:8080/up` should return 200. Logging in as `admin` with the password from `ADMIN_PASS` in `docker/config/app.env` should succeed.
- [ ] **Step 4: Check the database settings.** `make -C docker mysql` and run `SHOW VARIABLES WHERE Variable_name IN ('innodb_buffer_pool_size','innodb_flush_method','transaction_isolation','max_connections');`. Expected: `8589934592`, `O_DIRECT`, `READ-COMMITTED`, `400`. If `O_DIRECT` was rejected on the bind mount, set it to `fsync` in `99-nntmux.cnf`.
- [ ] **Step 5: Check indexing.** Run `make -C docker status`. Expected:
  - 20 active groups, with `scanned` rising as `last_record` gets set
  - `collections` and `binaries` growing
  - `releases` above 0 within about an hour

  `make -C docker tmux` shows the panes, with NNTP connections on the primary.
- [ ] **Step 6: Check PreDB.** In `make -C docker status`, `predb` sources should include `predb.club`, `predb.net`, `predb.me`, and IRC (`#PreNNTmux`, once the scraper has joined).
- [ ] **Step 7: Check Grafana.** While logged in as admin, `http://localhost:8080/grafana/` should render the NNTmux dashboards.

## Self-review

- **Spec coverage:**

  | Spec section | Task(s) |
  |---|---|
  | §2 D1–D11 | Tasks 4–7 |
  | §4 layout | Tasks 1, 5, 6 |
  | §5 tuning | Task 5 |
  | §6.1 predb importer | Tasks 2–3 |
  | §6.2 health-check gate | Task 3 |
  | §6.3 Grafana auth | Tasks 4 and 5 (Caddyfiles) |
  | §7 bootstrap | Task 7 |
  | §8 operations | Makefile targets (Task 6) |
  | §10 risks | Checks in Tasks 4, 6, 7 |

- **Placeholders:** none. Every code step embeds the final file.
- **Type consistency:** `PredbFeedEntry`, `PredbFeedSource::fetch()`, `PredbFeedImporter::import()` and the factory's `make()` are used with the same signatures across Tasks 2 and 3.

<?php

declare(strict_types=1);

namespace App\Services\Predb\Feeds;

use App\Services\Predb\Feeds\Contracts\PredbFeedSource;
use App\Services\Predb\Feeds\Sources\ApibaySource;
use App\Services\Predb\Feeds\Sources\CrowdnfoSource;
use App\Services\Predb\Feeds\Sources\DatsPcIsoSource;
use App\Services\Predb\Feeds\Sources\G4uSource;
use App\Services\Predb\Feeds\Sources\GabberSource;
use App\Services\Predb\Feeds\Sources\KnabenSource;
use App\Services\Predb\Feeds\Sources\Nima4kSource;
use App\Services\Predb\Feeds\Sources\NswdbSource;
use App\Services\Predb\Feeds\Sources\NzedbArchiveSource;
use App\Services\Predb\Feeds\Sources\PredatabaseSource;
use App\Services\Predb\Feeds\Sources\PredbClubSource;
use App\Services\Predb\Feeds\Sources\PredbNetSource;
use App\Services\Predb\Feeds\Sources\PredbOrgSource;
use App\Services\Predb\Feeds\Sources\PredbOvhSource;
use App\Services\Predb\Feeds\Sources\RssFeedSource;
use App\Services\Predb\Feeds\Sources\SceneArchiveSqlSource;
use App\Services\Predb\Feeds\Sources\SeriousAboutSoundSource;
use App\Services\Predb\Feeds\Sources\SrrdbSource;
use App\Services\Predb\Feeds\Sources\ThreeDsDbSource;
use App\Services\Predb\Feeds\Sources\XrelSource;
use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use InvalidArgumentException;
use Throwable;

/**
 * Builds configured PreDB feed sources from config/predb_feeds.php.
 */
class PredbFeedSourceFactory
{
    public const string FFI_CDEF = <<<'C'
        ssize_t nntmux_wave21_feed_keys(const char *input, size_t len, char *out, size_t cap);
        ssize_t nntmux_wave21_feed_key_index(const char *input, size_t len);
        C;

    private static bool $useRustFfi = false;

    private static FFI|false|null $ffi = null;

    /**
     * Wave 48.2 production default for classifySourceKeyBatch() is Rust: the paired benchmark in
     * tests/Unit/RustPredbEntryFactoryBoundedHttpUpdateLockW48ParityTest.php measures 5.78-6.14x mean and 4.13-5.23x p99 on common batches and 6.08-7.21x mean and 4.80-10.45x p99 on uncommon batches.
     * PHP stays the fallback when the library is unavailable or a key holds a NUL byte.
     */
    public const bool W48_SOURCE_KEY_RUST_FFI_DEFAULT = true;

    public const string W48_FFI_CDEF = <<<'C'
        ssize_t nntmux_w48_predb_source_key_batch(const char *blob, size_t blob_len, const char *aux, size_t aux_len, int64_t count, int64_t reserved, char *out_buf, size_t out_cap);
        C;

    private const array W48_SOURCE_KEY_KEYS = ['total', 'blank', 'resolved', 'unknown', 'exact', 'normalized', 'duplicate', 'distinct', 'dedicated',
        'rss', 'file', 'map_category', 'publication_dates_only', 'spaced_titles', 'strip_display_size_suffix', 'bounded_body', 'extra_config', 'p2p',
        'tier_local', 'tier_rss', 'tier_standard', 'tier_api', 'first_unknown_index', 'source_mask', 'key_bytes', 'digest'];

    /**
     * Per makeOne() key: [availableKeysPhp() index, kind (0 dedicated, 1 RSS, 2 local file), option flags, rate tier]. Flags: 1
     * mapCategory, 2 publicationDatesOnly, 4 spacedTitles, 8 stripDisplaySizeSuffix, 16 bounded body, 32 extra config, 64 p2p.
     */
    private const array W48_SOURCES = ['predb_club' => [0, 0, 0, 2], 'predb_net' => [1, 0, 0, 2], 'predb_me' => [2, 1, 16, 1],
        'predb_org' => [3, 0, 0, 2], 'predatabase' => [4, 0, 32, 3], 'srrdb' => [5, 0, 32, 3], 'xrel' => [6, 0, 0, 3], 'xrel_p2p' => [7, 0, 64, 3],
        'scnsrc_me' => [8, 1, 21, 1], 'eztv' => [9, 1, 20, 1], 'predb_ovh' => [10, 0, 0, 2], 'apibay' => [11, 0, 0, 2], 'nfodb' => [12, 1, 19, 1],
        'one_techno' => [13, 1, 19, 1], 'one_gabba' => [14, 1, 19, 1], 'ddlvalley' => [15, 1, 18, 1], 'hdencode' => [16, 1, 26, 1],
        'hdencode_tv' => [17, 1, 26, 1], 'mp3scene' => [18, 1, 18, 1], 'nima4k' => [19, 0, 0, 2], 'gload' => [20, 1, 18, 1],
        'hdworld' => [21, 1, 18, 1], 'g4u' => [22, 0, 0, 2], 'nswdb' => [23, 0, 0, 2], '3dsdb' => [24, 0, 0, 2], 'gabber' => [25, 0, 0, 2],
        'movieblog' => [26, 1, 18, 1], 'scnlog' => [27, 1, 19, 1], 'knaben' => [28, 0, 0, 2], 'crowdnfo' => [29, 0, 0, 2],
        'seriousaboutsound' => [30, 0, 0, 2], 'dats_pciso_1998' => [31, 0, 0, 2], 'nzedb_archive' => [32, 0, 0, 2],
        'scene_archive_sql' => [33, 2, 32, 0]];

    private static ?bool $w48SourceKeyRustFfiOverride = null;

    private static FFI|false|null $w48SourceKeyRustFfiHandle = null;

    public static function enableRustFfi(): void
    {
        self::$useRustFfi = true;
    }

    public static function disableRustFfi(): void
    {
        self::$useRustFfi = false;
    }

    public static function resetRustFfi(): void
    {
        self::$useRustFfi = false;
        self::$ffi = null;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi;
    }

    /**
     * @return list<string>
     */
    public function availableKeys(): array
    {
        return (self::$useRustFfi ? $this->availableKeysNative() : null) ?? $this->availableKeysPhp();
    }

    /** @return list<string>|null */
    public function availableKeysNative(): ?array
    {
        try {
            self::$ffi ??= RustPipelineLibrary::resolveDomain('cleaning', self::FFI_CDEF) ?? false;
            if (self::$ffi === false) {
                return null;
            }
            $capacity = 1024;
            $buffer = self::$ffi->new("char[{$capacity}]");
            // @phpstan-ignore method.notFound
            $written = self::$ffi->nntmux_wave21_feed_keys('', 0, $buffer, $capacity);
            if (! is_int($written) || $written <= 0 || $written > $capacity) {
                return null;
            }

            return explode("\0", FFI::string($buffer, $written));
        } catch (Throwable) {
            return null;
        }
    }

    /** @return list<string> */
    public function availableKeysPhp(): array
    {
        return ['predb_club', 'predb_net', 'predb_me', 'predb_org', 'predatabase', 'srrdb', 'xrel', 'xrel_p2p', 'scnsrc_me', 'eztv', 'predb_ovh', 'apibay', 'nfodb', 'one_techno', 'one_gabba', 'ddlvalley', 'hdencode', 'hdencode_tv', 'mp3scene', 'nima4k', 'gload', 'hdworld', 'g4u', 'nswdb', '3dsdb', 'gabber', 'movieblog', 'scnlog', 'knaben', 'crowdnfo', 'seriousaboutsound', 'dats_pciso_1998', 'nzedb_archive', 'scene_archive_sql'];
    }

    public function isAvailableKey(string $key): bool
    {
        return (self::$useRustFfi ? $this->isAvailableKeyNative($key) : null) ?? $this->isAvailableKeyPhp($key);
    }

    public function isAvailableKeyNative(string $key): ?bool
    {
        try {
            self::$ffi ??= RustPipelineLibrary::resolveDomain('cleaning', self::FFI_CDEF) ?? false;
            if (self::$ffi === false) {
                return null;
            }
            // @phpstan-ignore method.notFound
            $index = self::$ffi->nntmux_wave21_feed_key_index($key, strlen($key));
            if ($index === -2) {
                return false;
            }

            return is_int($index) && $index >= 0 && $index < 34 ? true : null;
        } catch (Throwable) {
            return null;
        }
    }

    public function isAvailableKeyPhp(string $key): bool
    {
        return in_array($key, $this->availableKeysPhp(), true);
    }

    /** Force the Wave 48.2 key classifier onto Rust (true) or PHP (false); null restores W48_SOURCE_KEY_RUST_FFI_DEFAULT. */
    public static function useW48SourceKeyRustFfi(?bool $enable = true): void
    {
        self::$w48SourceKeyRustFfiOverride = $enable;
    }

    public static function usesW48SourceKeyRustFfi(): bool
    {
        return self::$w48SourceKeyRustFfiOverride ?? self::W48_SOURCE_KEY_RUST_FFI_DEFAULT;
    }

    public static function resetW48SourceKeyRustFfi(): void
    {
        self::$w48SourceKeyRustFfiOverride = null;
        self::$w48SourceKeyRustFfiHandle = null;
    }

    /**
     * Classify configured feed keys the way make() would build them: blank, exact, normalized (trimmed, lower-cased,
     * dashes and spaces as underscores, at most 32 bytes) or unknown keys, repeated sources, each distinct source's kind,
     * option flags and rate tier, the first unknown index, a bit mask of the resolved sources and an FNV-1a digest of
     * every key's status, source index and trimmed length in order.
     *
     * @param  list<string>  $keys
     * @return array<string, int>
     */
    public static function classifySourceKeyBatch(array $keys): array
    {
        return (self::usesW48SourceKeyRustFfi() ? self::classifySourceKeyBatchNative($keys) : null) ?? self::classifySourceKeyBatchPhp($keys);
    }

    /**
     * @param  list<string>  $keys
     * @return array<string, int>
     */
    public static function classifySourceKeyBatchPhp(array $keys): array
    {
        $c = array_fill(0, \count(self::W48_SOURCE_KEY_KEYS), 0);
        $c[22] = -1;
        $mask = 0;
        $stream = '';
        foreach (array_values($keys) as $i => $raw) {
            $c[0]++;
            $key = trim($raw);
            $length = \strlen($key);
            $c[24] += $length;
            $entry = null;
            if ($length === 0) {
                $c[1]++;
                $status = 0;
            } elseif (($entry = self::W48_SOURCES[$raw] ?? null) !== null) {
                $c[4]++;
                $status = 1;
            } elseif ($length <= 32 && ($entry = self::W48_SOURCES[strtolower(strtr($key, '- ', '__'))] ?? null) !== null) {
                $c[5]++;
                $status = 2;
            } else {
                $c[3]++;
                if ($c[22] < 0) {
                    $c[22] = $i;
                }
                $status = 3;
            }
            $index = -1;
            if ($entry !== null) {
                [$index, $kind, $flags, $tier] = $entry;
                $c[2]++;
                if (($mask >> $index & 1) === 1) {
                    $c[6]++;
                } else {
                    $mask |= 1 << $index;
                    $c[7]++;
                    $c[8 + $kind]++;
                    for ($bit = 0; $bit < 7; $bit++) {
                        $c[11 + $bit] += $flags >> $bit & 1;
                    }
                    $c[18 + $tier]++;
                }
            }
            $stream .= pack('CCV', $status, $index & 0xFF, $length);
        }
        $c[23] = $mask;
        $c[25] = PredbFeedEntry::w48Fnv($stream);

        return array_combine(self::W48_SOURCE_KEY_KEYS, $c);
    }

    /**
     * Null when the library is missing, a key holds a NUL byte or the batch exceeds the native limits.
     *
     * @param  list<string>  $keys
     * @return array<string, int>|null
     */
    public static function classifySourceKeyBatchNative(array $keys): ?array
    {
        $count = \count($keys);
        $ffi = self::w48SourceKeyRustFfi();
        if ($ffi === null || $count > PredbFeedEntry::W48_MAX_ITEMS) {
            return null;
        }
        $values = PredbFeedEntry::callW48Native($ffi, 'nntmux_w48_predb_source_key_batch', implode("\0", $keys), '', $count, 0,
            \count(self::W48_SOURCE_KEY_KEYS));
        if ($values === null || $values[0] !== $count || $count !== $values[1] + $values[2] + $values[3]) {
            return null;
        }

        return array_combine(self::W48_SOURCE_KEY_KEYS, $values);
    }

    private static function w48SourceKeyRustFfi(): ?FFI
    {
        self::$w48SourceKeyRustFfiHandle ??= RustPipelineLibrary::resolveDomain(PredbFeedEntry::W48_FFI_DOMAIN, self::W48_FFI_CDEF) ?? false;

        return self::$w48SourceKeyRustFfiHandle === false ? null : self::$w48SourceKeyRustFfiHandle;
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
        $maximumBodyBytes = max(1, (int) config('predb_feeds.max_body_bytes', 1048576));

        if (! $this->isAvailableKey($key)) {
            throw new InvalidArgumentException("Unknown PreDB feed source [{$key}]. Available: ".implode(', ', $this->availableKeys()));
        }

        return match ($key) {
            'predb_club' => new PredbClubSource($endpoint, $pageSize, $timeout, $userAgent),
            'predb_net' => new PredbNetSource($endpoint, $pageSize, $timeout, $userAgent),
            'predb_me' => new RssFeedSource('predb_me', 'predb.me', $endpoint, $pageSize, $timeout, $userAgent, maximumBodyBytes: $maximumBodyBytes),
            'predb_org' => new PredbOrgSource($endpoint, $pageSize, $timeout, $userAgent),
            'predatabase' => new PredatabaseSource($endpoint, $pageSize, $timeout, $userAgent, (string) config('predb_feeds.predatabase_api_key', '')),
            'srrdb' => new SrrdbSource($endpoint, $pageSize, $timeout, $userAgent, (string) config('predb_feeds.srrdb_timezone', 'Europe/Brussels')),
            'xrel' => new XrelSource($endpoint, $pageSize, $timeout, $userAgent),
            'xrel_p2p' => new XrelSource($endpoint, $pageSize, $timeout, $userAgent, p2p: true),
            'scnsrc_me' => new RssFeedSource('scnsrc_me', 'scnsrc.me', $endpoint, $pageSize, $timeout, $userAgent, spacedTitles: true, mapCategory: true, maximumBodyBytes: $maximumBodyBytes),
            'eztv' => new RssFeedSource('eztv', 'eztv', $endpoint, $pageSize, $timeout, $userAgent, spacedTitles: true, maximumBodyBytes: $maximumBodyBytes),
            'predb_ovh' => new PredbOvhSource($endpoint, $pageSize, $timeout, $userAgent),
            'apibay' => new ApibaySource($endpoint, $pageSize, $timeout, $userAgent),
            'nfodb' => new RssFeedSource('nfodb', 'nfodb.net.ru', $endpoint, $pageSize, $timeout, $userAgent, mapCategory: true, publicationDatesOnly: true, maximumBodyBytes: $maximumBodyBytes),
            'one_techno' => new RssFeedSource('one_techno', '1techno.org', $endpoint, $pageSize, $timeout, $userAgent, mapCategory: true, publicationDatesOnly: true, maximumBodyBytes: $maximumBodyBytes),
            'one_gabba' => new RssFeedSource('one_gabba', '1gabba.pw', $endpoint, $pageSize, $timeout, $userAgent, mapCategory: true, publicationDatesOnly: true, maximumBodyBytes: $maximumBodyBytes),
            'ddlvalley' => new RssFeedSource('ddlvalley', 'ddlvalley.me', $endpoint, $pageSize, $timeout, $userAgent, publicationDatesOnly: true, maximumBodyBytes: $maximumBodyBytes),
            'hdencode' => new RssFeedSource('hdencode', 'hdencode.org', $endpoint, $pageSize, $timeout, $userAgent, publicationDatesOnly: true, stripDisplaySizeSuffix: true, maximumBodyBytes: $maximumBodyBytes),
            'hdencode_tv' => new RssFeedSource('hdencode_tv', 'hdencode.org', $endpoint, $pageSize, $timeout, $userAgent, publicationDatesOnly: true, stripDisplaySizeSuffix: true, maximumBodyBytes: $maximumBodyBytes),
            'mp3scene' => new RssFeedSource('mp3scene', 'mp3scene.info', $endpoint, $pageSize, $timeout, $userAgent, publicationDatesOnly: true, maximumBodyBytes: $maximumBodyBytes),
            'nima4k' => new Nima4kSource($endpoint, $pageSize, $timeout, $userAgent),
            'gload' => new RssFeedSource('gload', 'gload.to', $endpoint, $pageSize, $timeout, $userAgent, publicationDatesOnly: true, maximumBodyBytes: $maximumBodyBytes),
            'movieblog' => new RssFeedSource('movieblog', 'movieblog.to', $endpoint, $pageSize, $timeout, $userAgent, publicationDatesOnly: true, maximumBodyBytes: $maximumBodyBytes),
            'hdworld' => new RssFeedSource('hdworld', 'hd-world.cc', $endpoint, $pageSize, $timeout, $userAgent, publicationDatesOnly: true, maximumBodyBytes: $maximumBodyBytes),
            'scnlog' => new RssFeedSource('scnlog', 'scnlog.me', $endpoint, $pageSize, $timeout, $userAgent, mapCategory: true, publicationDatesOnly: true, maximumBodyBytes: $maximumBodyBytes),
            'knaben' => new KnabenSource($endpoint, $pageSize, $timeout, $userAgent),
            'crowdnfo' => new CrowdnfoSource($endpoint, $pageSize, $timeout, $userAgent),
            'seriousaboutsound' => new SeriousAboutSoundSource($endpoint, $pageSize, $timeout, $userAgent),
            'g4u' => new G4uSource($endpoint, $pageSize, $timeout, $userAgent),
            'gabber' => new GabberSource($endpoint, $pageSize, $timeout, $userAgent),
            'nzedb_archive' => new NzedbArchiveSource($endpoint, $pageSize, $timeout, $userAgent),
            'dats_pciso_1998' => new DatsPcIsoSource($endpoint, $pageSize, $timeout, $userAgent),
            'scene_archive_sql' => new SceneArchiveSqlSource((string) config('predb_feeds.scene_archive_sql_file', ''), $pageSize),
            'nswdb' => new NswdbSource($endpoint, $pageSize, $timeout, $userAgent),
            '3dsdb' => new ThreeDsDbSource($endpoint, $pageSize, $timeout, $userAgent),
            default => throw new InvalidArgumentException("Unknown PreDB feed source [{$key}]. Available: ".implode(', ', $this->availableKeys())),
        };
    }

    // ========================================================================
    // WAVE 61.3 BATCH HELPER
    // ========================================================================

    /** Enabled: the minimum of three 301-round paired runs cleared every 1.5x gate (common mean 10.55x, p99 8.76x; uncommon mean 6.83x, p99 5.53x); see RustPredbFeedEntryImporterSourceFactoryIrcNetworksParserW61ParityTest. */
    public const bool W61_RUST_FFI_DEFAULT = true;

    public const string W61_PREDB_FEED_SOURCE_FACTORY_FFI_CDEF = <<<'C'
        ssize_t nntmux_w613_predb_feed_source_factory_batch(const char *blob, size_t blob_len, const int64_t *lens, size_t count, int64_t *out, size_t out_cap);
        C;

    /** Trailer words after the per-item words of the Wave 61.3 batch. */
    public const int W61_PREDB_FEED_SOURCE_FACTORY_TRAILER = 31;

    /** Normalized aliases (host names and short forms) for make() keys. */
    public const array W61_SOURCE_ALIASES = ['predb' => 'predb_club', 'predb.club' => 'predb_club', 'predb.net' => 'predb_net', 'predb.me' => 'predb_me',
        'predb.org' => 'predb_org', 'predb.ovh' => 'predb_ovh', 'srrdb.com' => 'srrdb', 'xrel.to' => 'xrel', 'xrel.to_p2p' => 'xrel_p2p', 'eztv.re' => 'eztv',
        'apibay.org' => 'apibay', 'knaben.eu' => 'knaben', 'crowdnfo.net' => 'crowdnfo', 'nima4k.org' => 'nima4k', '1techno' => 'one_techno',
        '1gabba' => 'one_gabba', 'threedsdb' => '3dsdb', 'scnsrc' => 'scnsrc_me', 'hd_encode' => 'hdencode'];

    /** Minimum request spacing per W48_SOURCES rate tier (local, RSS, standard, API), in milliseconds. */
    public const array W61_TIER_INTERVAL_MS = [0, 2000, 1000, 3000];

    /** Most items an RSS source returns per page. */
    public const int W61_RSS_PAGE_CAP = 50;

    public static function usesW61RustFfi(): bool
    {
        $override = self::w61RustFfiState();

        return \is_bool($override) ? $override : self::W61_RUST_FFI_DEFAULT;
    }

    /**
     * Runs $callback with the PredbFeedSourceFactory Wave 61 Rust path forced on or off, then restores the previous setting.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW61RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w61RustFfiState('set', $enabled);
        try {
            return $callback();
        } finally {
            self::w61RustFfiState('set', $previous);
        }
    }

    /**
     * Wave 61.3 source policy plans. Each item is `key|pageSize|timeout|maxBodyBytes`, every field trimmed. Per item
     * `status | (index + 1) << 3 | kind << 9 | flags << 11 | tier << 18 | pageState << 20 | timeoutState << 22 |
     * bodyState << 24 | page << 26 | timeout << 36 | duplicate << 43 | min(keyLength, 63) << 44`: the status is 0
     * blank, 1 an exact W48_SOURCES key, 2 a normalized key (lower-cased, dashes and spaces as underscores), 3 a
     * normalized W61_SOURCE_ALIASES key, 4 unknown and 5 an unmatched key over 32 bytes; a resolved source has
     * its W48_SOURCES index, kind, flags and rate tier (zero otherwise) and is a duplicate when an earlier item
     * resolved to it. Page size (default 100, 1-1000), timeout (15, 1-120 s) and body cap (1048576, 1024-67108864)
     * are 0 empty (default), 1 in range, 2 clamped and 3 malformed (default); a resolved RSS source caps the page
     * at W61_RSS_PAGE_CAP. Then the status, kind and tier counts (resolved items), the page, timeout and body state
     * counts, the duplicates, the resolved source mask, the page sum, largest timeout and body sum of resolved items
     * and the summed W61_TIER_INTERVAL_MS of the distinct sources.
     *
     * @param  array<array-key, string>  $items
     * @return list<int>
     */
    public static function w61SourcePlanBatch(array $items): array
    {
        return self::usesW61RustFfi() ? self::w61SourcePlanBatchNative($items) : self::w61SourcePlanBatchPhp($items);
    }

    /**
     * @param  array<array-key, string>  $items
     * @return list<int>
     */
    public static function w61SourcePlanBatchNative(array $items): array
    {
        return self::w61Invoke($items, \count($items) + self::W61_PREDB_FEED_SOURCE_FACTORY_TRAILER) ?? self::w61SourcePlanBatchPhp($items);
    }

    /**
     * @param  array<array-key, string>  $items
     * @return list<int>
     */
    public static function w61SourcePlanBatchPhp(array $items): array
    {
        $out = [];
        $statuses = [0, 0, 0, 0, 0, 0];
        $kinds = [0, 0, 0];
        $tiers = $pageStates = $timeoutStates = $bodyStates = [0, 0, 0, 0];
        $duplicates = $mask = $pageSum = $maxTimeout = $bodySum = $intervals = 0;
        foreach ($items as $item) {
            [$rawKey, $rawPage, $rawTimeout, $rawBody] = explode('|', (string) $item, 4) + ['', '', '', ''];
            $key = trim($rawKey);
            $length = \strlen($key);
            $entry = null;
            if ($length === 0) {
                $status = 0;
            } elseif (($entry = self::W48_SOURCES[$key] ?? null) !== null) {
                $status = 1;
            } elseif ($length > 32) {
                $status = 5;
            } else {
                $normal = strtolower(strtr($key, '- ', '__'));
                if (($entry = self::W48_SOURCES[$normal] ?? null) !== null) {
                    $status = 2;
                } elseif (isset(self::W61_SOURCE_ALIASES[$normal])) {
                    $entry = self::W48_SOURCES[self::W61_SOURCE_ALIASES[$normal]];
                    $status = 3;
                } else {
                    $status = 4;
                }
            }
            [$pageState, $page] = self::w61Bounded($rawPage, 100, 1, 1000);
            [$timeoutState, $timeout] = self::w61Bounded($rawTimeout, 15, 1, 120);
            [$bodyState, $body] = self::w61Bounded($rawBody, 1048576, 1024, 67108864);
            $index = -1;
            $kind = $flags = $tier = $duplicate = 0;
            if ($entry !== null) {
                [$index, $kind, $flags, $tier] = $entry;
                if ($kind === 1) {
                    $page = min($page, self::W61_RSS_PAGE_CAP);
                }
                if (($mask >> $index & 1) === 1) {
                    $duplicate = 1;
                } else {
                    $mask |= 1 << $index;
                    $intervals += self::W61_TIER_INTERVAL_MS[$tier];
                }
                $kinds[$kind]++;
                $tiers[$tier]++;
                $pageSum += $page;
                $maxTimeout = max($maxTimeout, $timeout);
                $bodySum += $body;
            }
            $statuses[$status]++;
            $pageStates[$pageState]++;
            $timeoutStates[$timeoutState]++;
            $bodyStates[$bodyState]++;
            $duplicates += $duplicate;
            $out[] = $status | ($index + 1) << 3 | $kind << 9 | $flags << 11 | $tier << 18 | $pageState << 20 | $timeoutState << 22
                | $bodyState << 24 | $page << 26 | $timeout << 36 | $duplicate << 43 | min($length, 63) << 44;
        }
        array_push($out, ...$statuses, ...$kinds, ...$tiers, ...$pageStates, ...$timeoutStates, ...$bodyStates);
        array_push($out, $duplicates, $mask, $pageSum, $maxTimeout, $bodySum, $intervals);

        return $out;
    }

    /**
     * Trimmed setting of up to 9 digits: [0, default] empty, [1, value] in range, [2, clamped] or [3, default] malformed.
     *
     * @return array{int, int}
     */
    private static function w61Bounded(string $raw, int $default, int $min, int $max): array
    {
        $value = trim($raw);
        $length = \strlen($value);
        if ($length === 0) {
            return [0, $default];
        }
        if ($length > 9 || strspn($value, '0123456789') !== $length) {
            return [3, $default];
        }
        $number = (int) $value;

        return $number >= $min && $number <= $max ? [1, $number] : [2, max($min, min($max, $number))];
    }

    /**
     * Wave 61 state: no $op reads the toggle override, 'set' stores $val (non-bool clears it) and
     * returns the previous override, 'ffi' resolves the library (null when unavailable) and 'out' and
     * 'lens' return `int64_t` output and length-column buffers of at least $val words.
     */
    private static function w61RustFfiState(?string $op = null, mixed $val = null): mixed
    {
        /** @var bool|null $override */
        static $override = null;
        /** @var FFI|false|null $ffi */
        static $ffi = null;
        /** @var CData|null $out */
        static $out = null;
        /** @var int $outCap */
        static $outCap = 0;
        /** @var CData|null $lens */
        static $lens = null;
        /** @var int $lensCap */
        static $lensCap = 0;

        if ($op === 'set') {
            $previous = $override;
            $override = \is_bool($val) ? $val : null;

            return $previous;
        }
        if ($op === 'ffi') {
            $ffi ??= RustPipelineLibrary::resolveDomain('predb_w613', self::W61_PREDB_FEED_SOURCE_FACTORY_FFI_CDEF) ?? false;

            return $ffi === false ? null : $ffi;
        }
        if ($op === 'out') {
            $words = \is_int($val) ? $val : 0;
            if ($out === null || $outCap < $words) {
                $handle = self::w61RustFfiState('ffi');
                if (! $handle instanceof FFI) {
                    return null;
                }
                $outCap = max(1024, $words * 2);
                $out = $handle->new('int64_t['.$outCap.']');
            }

            return $out;
        }
        if ($op === 'lens') {
            $words = \is_int($val) ? $val : 0;
            if ($lens === null || $lensCap < $words) {
                $handle = self::w61RustFfiState('ffi');
                if (! $handle instanceof FFI) {
                    return null;
                }
                $lensCap = max(256, $words * 2);
                $lens = $handle->new('int64_t['.$lensCap.']');
            }

            return $lens;
        }

        return $override;
    }

    /**
     * Calls the Wave 61.3 PredbFeedSourceFactory export with the NUL-joined $items, or with the items concatenated plus an
     * `int64_t` length column when an item holds a NUL byte. Null when the library is missing, the call
     * declines or it writes other than $words words, so the caller falls back to PHP.
     *
     * @param  array<array-key, mixed>  $items
     * @return list<int>|null
     */
    private static function w61Invoke(array $items, int $words): ?array
    {
        try {
            $ffi = self::w61RustFfiState('ffi');
            $out = self::w61RustFfiState('out', $words);
            if (! $ffi instanceof FFI || ! $out instanceof CData) {
                return null;
            }
            $count = \count($items);
            $blob = implode("\0", $items);
            $lens = null;
            if (substr_count($blob, "\0") !== max(0, $count - 1)) {
                $blob = implode('', $items);
                $column = self::w61RustFfiState('lens', $count);
                if (! $column instanceof CData) {
                    return null;
                }
                FFI::memcpy($column, pack('P*', ...array_map(strlen(...), array_values($items))), $count * 8);
                $lens = FFI::addr($column[0]);
            }
            // @phpstan-ignore method.notFound (Declared by W61_PREDB_FEED_SOURCE_FACTORY_FFI_CDEF.)
            $written = $ffi->nntmux_w613_predb_feed_source_factory_batch($blob, \strlen($blob), $lens, $count, $out, $words);
            if ($written !== $words) {
                return null;
            }
            $values = unpack('P*', FFI::string($out, $words * 8));

            /** @var list<int>|null */
            return $values === false ? null : array_values($values);
        } catch (Throwable) {
            return null;
        }
    }
}

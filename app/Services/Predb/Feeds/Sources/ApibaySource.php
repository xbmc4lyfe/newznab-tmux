<?php

declare(strict_types=1);

namespace App\Services\Predb\Feeds\Sources;

use App\Services\Predb\Feeds\PredbFeedEntry;
use App\Support\RustPipelineLibrary;
use Carbon\CarbonImmutable;
use FFI;
use RuntimeException;
use Throwable;

/**
 * apibay.org "top 100 recent" precompiled dump (The Pirate Bay).
 *
 * Row shape: {id, name, info_hash, leechers, seeders, num_files, size (bytes), username, added (unix), status, category, imdb}
 *
 * These are P2P names, not scene PREs. Only movie (201, 207) and TV (205, 208) categories are kept; every
 * other category, including adult, is dropped. The dump is a single file, so pages past the first are empty.
 * The added Unix timestamp is the catalogue upload time, not a verified PRE time.
 * Opt-in only.
 */
final class ApibaySource extends HttpFeedSource
{
    /**
     * Wave 44.3 production default is Rust: the paired benchmark in
     * tests/Unit/RustReleaseContextPredbFeedsApibayXmlCrowdnfoDatsW44ParityTest.php measures at
     * least 2.1x (mean and p99, common and uncommon batches). PHP stays the fallback when the
     * library is unavailable.
     * Named W443 so it stays distinct from the inherited feed toggles.
     */
    public const bool W443_RUST_FFI_DEFAULT = true;

    public const string W443_FFI_CDEF = <<<'C'
        ssize_t nntmux_w443_apibay_title_batch(
            const char *blob_ptr,
            size_t blob_len,
            const char *lens_ptr,
            size_t lens_len,
            char *out_buf,
            size_t out_cap
        );
        C;

    /**
     * P2P release group marker substrings, in match order. The first class with a substring of the
     * lowercased trimmed title wins.
     *
     * @var array<string, list<string>>
     */
    public const array W443_P2P_GROUP_MARKERS = [
        'yts' => ['yts', 'yify'],
        'rarbg' => ['rarbg'],
        'eztv' => ['eztv'],
        'galaxy' => ['tgx', 'torrentgalaxy', 'galaxyrg'],
    ];

    /** @var list<string> */
    public const array W443_CAM_MARKERS = ['hdcam', 'camrip', 'telesync', 'hdts'];

    /** Exactly 8 0x1F-separated decimals of 1-19 digits. */
    private const string W443_FIELDS_PATTERN = '/\A(?:\d{1,19}\x1F){7}\d{1,19}\z/';

    private static ?bool $w443RustFfiOverride = null;

    private static FFI|false|null $w443RustFfiHandle = null;

    /** @var array<int, string> */
    private const array CATEGORIES = [
        201 => 'MOVIE',
        207 => 'MOVIE-HD',
        205 => 'TV',
        208 => 'TV-HD',
    ];

    public function key(): string
    {
        return 'apibay';
    }

    public function fetch(int $page = 1): array
    {
        if ($page > 1) {
            return [];
        }

        $response = $this->http()->get($this->endpoint)->throw();
        $native = PredatabaseSource::nativeJsonEntries($response->body(), 9, 'apibay', listed: true, limit: $this->pageSize);
        if ($native !== null) {
            return $native[1];
        }

        $rows = $response->json();
        if (! is_array($rows) || ! array_is_list($rows)) {
            throw new RuntimeException('apibay returned an unexpected response (not a JSON list).');
        }

        return $this->parseRows($rows);
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

            $category = self::CATEGORIES[(int) ($row['category'] ?? 0)] ?? null;
            if ($category === null) {
                continue;
            }

            $entries[] = new PredbFeedEntry(
                title: trim($row['name']),
                source: 'apibay',
                category: $category,
                size: PredbFeedEntry::sizeFromMegabytes(is_numeric($row['size'] ?? null) ? (float) $row['size'] / 1048576 : null),
                files: PredbFeedEntry::filesFromCount(is_numeric($row['num_files'] ?? null) ? (int) $row['num_files'] : null),
                listedAt: is_numeric($row['added'] ?? null) ? CarbonImmutable::createFromTimestampUTC((int) $row['added']) : null,
            );

            if (count($entries) >= $this->pageSize) {
                break;
            }
        }

        return $entries;
    }

    /**
     * Enables (true) or disables (false) the Wave 44.3 Rust helper; null restores
     * W443_RUST_FFI_DEFAULT.
     */
    public static function useW443RustFfi(?bool $enable = true): void
    {
        self::$w443RustFfiOverride = $enable;
    }

    public static function usesW443RustFfi(): bool
    {
        return self::$w443RustFfiOverride ?? self::W443_RUST_FFI_DEFAULT;
    }

    public static function resetW443RustFfi(): void
    {
        self::$w443RustFfiOverride = null;
        self::$w443RustFfiHandle = null;
    }

    /**
     * P2P title summary for a batch of apibay names. Titles that trim() to '' count as empty;
     * every other lowercased title adds to its first W443_P2P_GROUP_MARKERS class, else to other,
     * and separately to cam when it holds any W443_CAM_MARKERS entry. Non-strings count as empty.
     *
     * @param  array<array-key, mixed>  $titles
     * @return array{total: int, empty: int, yts: int, rarbg: int, eztv: int, galaxy: int, other: int, cam: int}
     */
    public static function summarizeApibayTitleBatch(array $titles): array
    {
        return (self::usesW443RustFfi() ? self::summarizeApibayTitleBatchNative($titles) : null)
            ?? self::summarizeApibayTitleBatchPhp($titles);
    }

    /**
     * @param  array<array-key, mixed>  $titles
     * @return array{total: int, empty: int, yts: int, rarbg: int, eztv: int, galaxy: int, other: int, cam: int}
     */
    public static function summarizeApibayTitleBatchPhp(array $titles): array
    {
        $counts = ['yts' => 0, 'rarbg' => 0, 'eztv' => 0, 'galaxy' => 0];
        $empty = 0;
        $other = 0;
        $cam = 0;
        foreach ($titles as $item) {
            $title = trim(\is_string($item) ? $item : '');
            if ($title === '') {
                $empty++;

                continue;
            }
            $title = strtolower($title);
            foreach (self::W443_CAM_MARKERS as $needle) {
                if (str_contains($title, $needle)) {
                    $cam++;

                    break;
                }
            }
            foreach (self::W443_P2P_GROUP_MARKERS as $class => $needles) {
                foreach ($needles as $needle) {
                    if (str_contains($title, $needle)) {
                        $counts[$class]++;

                        continue 3;
                    }
                }
            }
            $other++;
        }

        return [
            'total' => \count($titles),
            'empty' => $empty,
            'yts' => $counts['yts'],
            'rarbg' => $counts['rarbg'],
            'eztv' => $counts['eztv'],
            'galaxy' => $counts['galaxy'],
            'other' => $other,
            'cam' => $cam,
        ];
    }

    /**
     * Declines (null) for non-string items so PHP counts them.
     *
     * @param  array<array-key, mixed>  $titles
     * @return array{total: int, empty: int, yts: int, rarbg: int, eztv: int, galaxy: int, other: int, cam: int}|null
     */
    public static function summarizeApibayTitleBatchNative(array $titles): ?array
    {
        $lengths = [];
        foreach ($titles as $item) {
            if (! \is_string($item)) {
                return null;
            }
            $lengths[] = \strlen($item);
        }
        $fields = self::callW443Native(implode('', $titles), pack('V*', ...$lengths));
        if ($fields === null) {
            return null;
        }

        return [
            'total' => (int) $fields[0],
            'empty' => (int) $fields[1],
            'yts' => (int) $fields[2],
            'rarbg' => (int) $fields[3],
            'eztv' => (int) $fields[4],
            'galaxy' => (int) $fields[5],
            'other' => (int) $fields[6],
            'cam' => (int) $fields[7],
        ];
    }

    /**
     * Calls this class's Wave 44.3 export with the shared scratch buffer, retrying once at the size
     * it asks for. Returns its fields only when they match W443_FIELDS_PATTERN: exactly the expected
     * count of 1-19 digit decimals.
     *
     * @return list<string>|null
     */
    private static function callW443Native(string $blob, string $lens): ?array
    {
        $ffi = self::w443RustFfi();
        if ($ffi === null) {
            return null;
        }

        try {
            $capacity = 0;
            $buffer = RustPipelineLibrary::obtainScratch($ffi, 128, $capacity);
            if ($buffer === null) {
                return null;
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_w443_apibay_title_batch($blob, \strlen($blob), $lens, \strlen($lens), $buffer, $capacity);
            if (\is_int($written) && $written < -1) {
                $buffer = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($buffer === null) {
                    return null;
                }
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_w443_apibay_title_batch($blob, \strlen($blob), $lens, \strlen($lens), $buffer, $capacity);
            }
            if (! \is_int($written) || $written < 0 || $written > $capacity) {
                return null;
            }
            $raw = FFI::string($buffer, $written);

            return preg_match(self::W443_FIELDS_PATTERN, $raw) === 1 ? explode("\x1F", $raw) : null;
        } catch (Throwable) {
            return null;
        }
    }

    private static function w443RustFfi(): ?FFI
    {
        if (self::$w443RustFfiHandle === null) {
            self::$w443RustFfiHandle = RustPipelineLibrary::resolveDomain('categorize_w443', self::W443_FFI_CDEF) ?? false;
        }

        return self::$w443RustFfiHandle === false ? null : self::$w443RustFfiHandle;
    }
}

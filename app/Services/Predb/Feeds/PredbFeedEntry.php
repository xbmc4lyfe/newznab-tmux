<?php

declare(strict_types=1);

namespace App\Services\Predb\Feeds;

use App\Models\Predb;
use App\Support\RustPipelineLibrary;
use Carbon\CarbonImmutable;
use FFI;
use FFI\CData;
use Throwable;

/**
 * Release-name metadata from a remote PRE feed or catalogue, normalised to predb table semantics.
 */
final class PredbFeedEntry
{
    public const string FFI_CDEF = <<<'C'
        ssize_t nntmux_predb_feed_entry_format_size_mb(double megabytes, char *out_buf, size_t out_cap);
        ssize_t nntmux_predb_feed_entry_format_files(int64_t count, const char *text, size_t text_len, uint8_t is_string, char *out_buf, size_t out_cap);
        C;

    /**
     * Both helpers stay on PHP: single-call FFI overhead outweighs number_format()/(int) (see
     * RustPredbFeedsAndStreamParityTest for the paired benchmark evidence).
     */
    private static bool $useRustFfi = false;

    private static FFI|false|null $ffi = null;

    /**
     * Wave 48.2 production default for summarizeEntryBatch() is Rust: the paired benchmark in
     * tests/Unit/RustPredbEntryFactoryBoundedHttpUpdateLockW48ParityTest.php measures 2.21-2.31x mean and 1.70-2.17x p99 on common batches and 2.05-2.14x mean and 1.85-2.04x p99 on uncommon batches.
     * PHP stays the fallback when the library is unavailable or a text field holds a NUL byte.
     */
    public const bool W48_ENTRY_RUST_FFI_DEFAULT = true;

    /** Domain shared by the Wave 48.2 PreDB feed and update-lock helpers; each class binds only its own export. */
    public const string W48_FFI_DOMAIN = 'nfo_w48';

    public const string W48_FFI_CDEF = <<<'C'
        ssize_t nntmux_w48_predb_entry_batch(const char *blob, size_t blob_len, const char *aux, size_t aux_len, int64_t reserved_a, int64_t reserved_b, char *out_buf, size_t out_cap);
        C;

    /** Native cap for either Wave 48.2 input buffer. */
    public const int W48_MAX_INPUT = 16777216;

    /** Native cap for the items in one Wave 48.2 batch. */
    public const int W48_MAX_ITEMS = 65536;

    private const array W48_ENTRY_KEYS = ['total', 'titled', 'untitled', 'duplicate_title', 'ungrouped_title', 'spaced_title', 'title_bytes',
        'distinct_sources', 'missing_source', 'category_none', 'category_tv', 'category_movies', 'category_music', 'category_games', 'category_apps',
        'category_xxx', 'category_books', 'category_other', 'size_missing', 'size_invalid', 'size_kb', 'max_size_kb', 'files_missing', 'files_invalid',
        'files_total', 'nuke_none', 'nuke_active', 'nuke_cleared', 'nuke_unknown', 'digest'];

    /** Category prefix (text before the first -/ ._: separator, upper-cased) to the category_* class. */
    private const array W48_CATEGORY_PREFIXES = ['TV' => 1, 'MOVIE' => 2, 'MOVIES' => 2, 'X264' => 2, 'X265' => 2, 'BLURAY' => 2, 'DVDR' => 2,
        'XVID' => 2, 'UHD' => 2, 'BD' => 2, 'MP3' => 3, 'FLAC' => 3, 'MUSIC' => 3, 'MVID' => 3, 'AUDIO' => 3, 'GAMES' => 4, 'GAME' => 4, 'PS4' => 4,
        'PS5' => 4, 'NSW' => 4, 'XBOX' => 4, 'PC' => 4, 'WII' => 4, 'APPS' => 5, 'APP' => 5, '0DAY' => 5, 'ISO' => 5, 'XXX' => 6, 'EBOOK' => 7,
        'EBOOKS' => 7, 'BOOK' => 7, 'BOOKS' => 7, 'ABOOK' => 7];

    private const array W48_SIZE_UNITS = ['K' => 1, 'M' => 1024, 'G' => 1048576];

    private static ?bool $w48EntryRustFfiOverride = null;

    private static FFI|false|null $w48EntryRustFfiHandle = null;

    public function __construct(
        public readonly string $title,
        public readonly string $source,
        public readonly ?string $category = null,
        public readonly ?string $size = null,
        public readonly ?string $files = null,
        public readonly ?CarbonImmutable $predate = null,
        public readonly int $nuked = Predb::PRE_NONUKE,
        public readonly ?string $nukeReason = null,
        // When the feed listed the entry, if that is not the PRE time (e.g. srrDB's upload time).
        // Used only to page history imports; never stored as predate.
        public readonly ?CarbonImmutable $listedAt = null,
        // Details-only entry (e.g. an IRC INFO line): it may fill in an existing row but never creates one.
        public readonly bool $enrichOnly = false,
    ) {}

    public static function setUseRustFfi(bool $enabled): void
    {
        self::$useRustFfi = $enabled;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi;
    }

    private static function ffi(): ?FFI
    {
        self::$ffi ??= RustPipelineLibrary::resolveDomain('predb', self::FFI_CDEF) ?? false;

        return self::$ffi === false ? null : self::$ffi;
    }

    /**
     * Timestamp used to decide how far back a history import has paged.
     */
    public function pagingTime(): ?CarbonImmutable
    {
        return $this->predate ?? $this->listedAt;
    }

    /**
     * Format a size given in megabytes the way IRC pre bots announce it (KB below 1 MB), or null when unknown.
     */
    public static function sizeFromMegabytes(int|float|null $megabytes): ?string
    {
        if (self::$useRustFfi) {
            $supported = false;
            $native = self::sizeFromMegabytesNative($megabytes, $supported);
            if ($supported) {
                return $native;
            }
        }

        return self::sizeFromMegabytesPhp($megabytes);
    }

    /**
     * Rust sizeFromMegabytes(); $supported is false when PHP must format the value (near a
     * rounding tie, large, non-finite, or the library is unavailable).
     */
    public static function sizeFromMegabytesNative(int|float|null $megabytes, bool &$supported = false): ?string
    {
        $supported = false;
        if ($megabytes === null) {
            $supported = true;

            return null;
        }
        $ffi = self::ffi();
        if ($ffi === null) {
            return null;
        }
        $capacity = 0;
        $scratch = RustPipelineLibrary::obtainScratch($ffi, 64, $capacity);
        if ($scratch === null) {
            return null;
        }
        try {
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_predb_feed_entry_format_size_mb((float) $megabytes, $scratch, $capacity);
        } catch (Throwable) {
            return null;
        }
        if (! is_int($written) || $written < 0) {
            return null;
        }
        $supported = true;

        return $written === 0 ? null : FFI::string($scratch, $written);
    }

    public static function sizeFromMegabytesPhp(int|float|null $megabytes): ?string
    {
        if ($megabytes === null || $megabytes <= 0) {
            return null;
        }

        $format = static fn (float $value): string => rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');

        return $megabytes < 1 ? $format($megabytes * 1024).'KB' : $format((float) $megabytes).'MB';
    }

    /**
     * Normalise a file count, treating zero as unknown.
     */
    public static function filesFromCount(int|string|null $count): ?string
    {
        if (self::$useRustFfi) {
            $supported = false;
            $native = self::filesFromCountNative($count, $supported);
            if ($supported) {
                return $native;
            }
        }

        return self::filesFromCountPhp($count);
    }

    /**
     * Rust filesFromCount(); $supported is false for float-like or overlong numeric strings.
     */
    public static function filesFromCountNative(int|string|null $count, bool &$supported = false): ?string
    {
        $supported = false;
        if ($count === null) {
            $supported = true;

            return null;
        }
        $ffi = self::ffi();
        if ($ffi === null) {
            return null;
        }
        $capacity = 0;
        $scratch = RustPipelineLibrary::obtainScratch($ffi, 64, $capacity);
        if ($scratch === null) {
            return null;
        }
        $text = is_string($count) ? $count : null;
        try {
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_predb_feed_entry_format_files(is_int($count) ? $count : 0, $text, $text === null ? 0 : strlen($text), $text === null ? 0 : 1, $scratch, $capacity);
        } catch (Throwable) {
            return null;
        }
        if (! is_int($written) || $written < 0) {
            return null;
        }
        $supported = true;

        return $written === 0 ? null : FFI::string($scratch, $written);
    }

    public static function filesFromCountPhp(int|string|null $count): ?string
    {
        if ($count === null || (int) $count <= 0) {
            return null;
        }

        return (string) (int) $count;
    }

    /** Force the Wave 48.2 entry summary onto Rust (true) or PHP (false); null restores W48_ENTRY_RUST_FFI_DEFAULT. */
    public static function useW48EntryRustFfi(?bool $enable = true): void
    {
        self::$w48EntryRustFfiOverride = $enable;
    }

    public static function usesW48EntryRustFfi(): bool
    {
        return self::$w48EntryRustFfiOverride ?? self::W48_ENTRY_RUST_FFI_DEFAULT;
    }

    public static function resetW48EntryRustFfi(): void
    {
        self::$w48EntryRustFfiOverride = null;
        self::$w48EntryRustFfiHandle = null;
    }

    /**
     * Summarize a batch of normalised entries before they are stored: trimmed titles (duplicates after lower-casing
     * and mapping spaces and underscores to dots, missing group dash, embedded blanks), distinct sources, the category
     * family, sizes parsed as whole KB, file counts, nuke states, and an FNV-1a digest of every entry's flags, category,
     * size, file count and title key in order.
     *
     * @param  list<self>  $entries
     * @return array<string, int>
     */
    public static function summarizeEntryBatch(array $entries): array
    {
        return (self::usesW48EntryRustFfi() ? self::summarizeEntryBatchNative($entries) : null) ?? self::summarizeEntryBatchPhp($entries);
    }

    /**
     * @param  list<self>  $entries
     * @return array<string, int>
     */
    public static function summarizeEntryBatchPhp(array $entries): array
    {
        $c = array_fill(0, \count(self::W48_ENTRY_KEYS), 0);
        $titles = $sources = [];
        $stream = '';
        foreach ($entries as $entry) {
            $c[0]++;
            $title = trim($entry->title);
            $flags = 0;
            $key = '';
            if ($title === '') {
                $c[2]++;
            } else {
                $c[1]++;
                $c[6] += \strlen($title);
                $key = strtolower(strtr($title, ' _', '..'));
                if (isset($titles[$key])) {
                    $c[3]++;
                    $flags |= 1;
                } else {
                    $titles[$key] = true;
                }
                if (! str_contains($title, '-')) {
                    $c[4]++;
                    $flags |= 2;
                }
                if (strpbrk($title, " \t") !== false) {
                    $c[5]++;
                    $flags |= 4;
                }
            }
            $source = trim($entry->source);
            if ($source === '') {
                $c[8]++;
            } else {
                $sources[strtolower($source)] = true;
            }
            $category = trim((string) $entry->category);
            if ($category === '') {
                $class = 0;
            } else {
                $prefix = substr($category, 0, strcspn($category, '-/ ._:'));
                $class = \strlen($prefix) > 6 ? 8 : (self::W48_CATEGORY_PREFIXES[strtoupper($prefix)] ?? 8);
            }
            $c[9 + $class]++;
            $size = trim((string) $entry->size);
            if ($size === '') {
                $kb = -1;
                $c[18]++;
            } elseif (preg_match('/^([0-9]{1,6})(?:\.([0-9]{1,2}))?([KMG])B$/iD', $size, $match) === 1) {
                $hundredths = (int) $match[1] * 100 + (\strlen($match[2]) === 1 ? (int) $match[2] * 10 : (int) $match[2]);
                $kb = intdiv($hundredths * self::W48_SIZE_UNITS[strtoupper($match[3])], 100);
                $c[20] += $kb;
                $c[21] = max($c[21], $kb);
            } else {
                $kb = -2;
                $c[19]++;
            }
            $count = trim((string) $entry->files);
            $length = \strlen($count);
            if ($length > 0 && $length <= 9 && strspn($count, '0123456789') === $length) {
                $files = (int) $count;
                if ($files === 0) {
                    $files = -1;
                    $c[22]++;
                } else {
                    $c[24] += $files;
                }
            } elseif ($length === 0) {
                $files = -1;
                $c[22]++;
            } else {
                $files = -2;
                $c[23]++;
            }
            $nuke = match ($entry->nuked) {
                Predb::PRE_NONUKE => 0,
                Predb::PRE_NUKED, Predb::PRE_RENUKED, Predb::PRE_OLDNUKE => 1,
                Predb::PRE_UNNUKED, Predb::PRE_MODNUKE => 2,
                default => 3,
            };
            $c[25 + $nuke]++;
            $stream .= pack('CCCPP', $flags, $class, $nuke, $kb, $files).$key."\0";
        }
        $c[7] = \count($sources);
        $c[29] = self::w48Fnv($stream);

        return array_combine(self::W48_ENTRY_KEYS, $c);
    }

    /**
     * Null when the library is missing, a text field holds a NUL byte or the batch exceeds the native limits.
     *
     * @param  list<self>  $entries
     * @return array<string, int>|null
     */
    public static function summarizeEntryBatchNative(array $entries): ?array
    {
        $count = \count($entries);
        $ffi = self::w48EntryRustFfi();
        if ($ffi === null || $count > self::W48_MAX_ITEMS) {
            return null;
        }
        $blob = $count === 0 ? '' : implode("\0", [...array_column($entries, 'title'), ...array_column($entries, 'source'),
            ...array_column($entries, 'category'), ...array_column($entries, 'size'), ...array_column($entries, 'files')]);
        $values = self::callW48Native($ffi, 'nntmux_w48_predb_entry_batch', $blob, pack('q*', ...array_column($entries, 'nuked')), 0, 0,
            \count(self::W48_ENTRY_KEYS));
        if ($values === null || $values[0] !== $count || $count !== $values[1] + $values[2]) {
            return null;
        }

        return array_combine(self::W48_ENTRY_KEYS, $values);
    }

    /**
     * @internal Call a Wave 48.2 export through the shared scratch buffer. Returns exactly $slots counters unpacked
     * from little-endian int64 values, or null when the export declines the batch.
     *
     * @return list<int>|null
     */
    public static function callW48Native(FFI $ffi, string $symbol, string $blob, string $aux, int $paramA, int $paramB, int $slots): ?array
    {
        if (\strlen($blob) > self::W48_MAX_INPUT || \strlen($aux) > self::W48_MAX_INPUT || $slots < 1) {
            return null;
        }
        try {
            $capacity = 0;
            $buffer = RustPipelineLibrary::obtainScratch($ffi, $slots * 8, $capacity);
            if ($buffer === null) {
                return null;
            }
            $written = $ffi->{$symbol}($blob, \strlen($blob), $aux, \strlen($aux), $paramA, $paramB, $buffer, $capacity);
            if (! \is_int($written) || $written !== $slots * 8 || $written > $capacity) {
                return null;
            }
            /** @var array<int, int>|false $values */
            $values = unpack('P*', FFI::string($buffer, $written));

            return $values === false ? null : array_values($values);
        } catch (Throwable) {
            return null;
        }
    }

    /** @internal FNV-1a 64 of $bytes as the signed int with the same bits, as the Wave 48.2 exports report it. */
    public static function w48Fnv(string $bytes): int
    {
        /** @var array{1: int} $value */
        $value = unpack('J', hash('fnv1a64', $bytes, true));

        return $value[1];
    }

    private static function w48EntryRustFfi(): ?FFI
    {
        self::$w48EntryRustFfiHandle ??= RustPipelineLibrary::resolveDomain(self::W48_FFI_DOMAIN, self::W48_FFI_CDEF) ?? false;

        return self::$w48EntryRustFfiHandle === false ? null : self::$w48EntryRustFfiHandle;
    }

    // ========================================================================
    // WAVE 61.3 BATCH HELPER
    // ========================================================================

    /** Enabled: the minimum of three 301-round paired runs cleared every 1.5x gate (common mean 3.87x, p99 3.20x; uncommon mean 3.98x, p99 3.52x); see RustPredbFeedEntryImporterSourceFactoryIrcNetworksParserW61ParityTest. */
    public const bool W61_RUST_FFI_DEFAULT = true;

    public const string W61_PREDB_FEED_ENTRY_FFI_CDEF = <<<'C'
        ssize_t nntmux_w613_predb_feed_entry_batch(const char *blob, size_t blob_len, const int64_t *lens, size_t count, int64_t *out, size_t out_cap);
        C;

    /** Trailer words after the per-item words of w61FeedEntryBatch(). */
    public const int W61_PREDB_FEED_ENTRY_TRAILER = 44;

    /** Valid predates are Unix times from 2000-01-01 (inclusive) to 2100-01-01 (exclusive) UTC. */
    public const int W61_PREDATE_MIN = 946684800;

    public const int W61_PREDATE_MAX = 4102444800;

    /** Bytes a scene release name may hold (isReleaseName() over ASCII). */
    public const string W61_SCENE_CHARS = "ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789_.()&'+,-";

    private const string W61_WORD_CHARS = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789_';

    private const string W61_SOURCE_CHARS = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789._-';

    private const string W61_CONTROL_CHARS = "\x00\x01\x02\x03\x04\x05\x06\x07\x08\x09\x0A\x0B\x0C\x0D\x0E\x0F\x10\x11\x12\x13\x14\x15\x16\x17\x18\x19\x1A\x1B\x1C\x1D\x1E\x1F\x7F";

    public static function usesW61RustFfi(): bool
    {
        $override = self::w61RustFfiState();

        return \is_bool($override) ? $override : self::W61_RUST_FFI_DEFAULT;
    }

    /**
     * Runs $callback with the PredbFeedEntry Wave 61 Rust path forced on or off, then restores the previous setting.
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
     * Wave 61.3 feed entry validation plans. Each item is `title|source|category|size|files|nuked|predate`, every
     * field trimmed. Per item `titleState | shape << 2 | sourceState << 5 | categoryClass << 7 | sizeState << 11 |
     * filesState << 13 | nuke << 15 | predateState << 18 | duplicate << 20 | min(titleLength, 1023) << 21 |
     * min(files, 0xFFFFF) << 31`: the title is 0 empty, 1 over 255 bytes, 2 holding a control byte or 3 valid; a
     * valid title's shape is 1 a scene release name (W61_SCENE_CHARS ending in `-` and word bytes), 4 bracketed,
     * 2 spaced, 3 underscored and 0 other, and it is a duplicate of an earlier valid title under the
     * summarizeEntryBatch() key; the source is 0 empty, 1 up to 64 `[A-Za-z0-9._-]` bytes or 2 other; the category
     * class follows W48_CATEGORY_PREFIXES (0 empty, 8 other); size and files are 0 empty, 1 valid (sizes as
     * summarizeEntryBatch() parses them, 1-9 digit file counts) and 2 invalid, with 2 for a zero file count and 3
     * for invalid files; nuke is 0 none, 1 active, 2 cleared, 3 another 1-2 digit status and 4 malformed; the
     * predate is 0 empty, 1 a Unix time in [W61_PREDATE_MIN, W61_PREDATE_MAX), 2 out of range or 3 malformed. Then
     * the title, shape, source, category, size, files, nuke and predate counts, the duplicates, the distinct
     * lower-cased sources, the KB sum and maximum, the file sum and the earliest and latest valid predates.
     *
     * @param  array<array-key, string>  $items
     * @return list<int>
     */
    public static function w61FeedEntryBatch(array $items): array
    {
        return self::usesW61RustFfi() ? self::w61FeedEntryBatchNative($items) : self::w61FeedEntryBatchPhp($items);
    }

    /**
     * @param  array<array-key, string>  $items
     * @return list<int>
     */
    public static function w61FeedEntryBatchNative(array $items): array
    {
        return self::w61Invoke($items, \count($items) + self::W61_PREDB_FEED_ENTRY_TRAILER) ?? self::w61FeedEntryBatchPhp($items);
    }

    /**
     * @param  array<array-key, string>  $items
     * @return list<int>
     */
    public static function w61FeedEntryBatchPhp(array $items): array
    {
        $out = [];
        $titleStates = [0, 0, 0, 0];
        $shapes = [0, 0, 0, 0, 0];
        $sourceStates = [0, 0, 0];
        $classes = array_fill(0, 9, 0);
        $sizeStates = [0, 0, 0];
        $filesStates = [0, 0, 0, 0];
        $nukes = [0, 0, 0, 0, 0];
        $timeStates = [0, 0, 0, 0];
        $duplicates = $kbSum = $maxKb = $filesSum = $minTime = $maxTime = 0;
        $titles = $sources = [];
        foreach ($items as $item) {
            [$rawTitle, $rawSource, $rawCategory, $rawSize, $rawFiles, $rawNuked, $rawPredate] = explode('|', (string) $item, 7) + ['', '', '', '', '', '', ''];
            $title = trim($rawTitle);
            $length = \strlen($title);
            $titleState = match (true) {
                $length === 0 => 0,
                $length > 255 => 1,
                strcspn($title, self::W61_CONTROL_CHARS) !== $length => 2,
                default => 3,
            };
            $shape = $duplicate = 0;
            if ($titleState === 3) {
                $shape = self::w61TitleShape($title, $length);
                $key = strtolower(strtr($title, ' _', '..'));
                if (isset($titles[$key])) {
                    $duplicate = 1;
                } else {
                    $titles[$key] = true;
                }
            }
            $source = trim($rawSource);
            $sourceLength = \strlen($source);
            $sourceState = $sourceLength === 0 ? 0 : ($sourceLength <= 64 && strspn($source, self::W61_SOURCE_CHARS) === $sourceLength ? 1 : 2);
            if ($sourceLength > 0) {
                $sources[strtolower($source)] = true;
            }
            $category = trim($rawCategory);
            if ($category === '') {
                $class = 0;
            } else {
                $prefix = substr($category, 0, strcspn($category, '-/ ._:'));
                $class = \strlen($prefix) > 6 ? 8 : (self::W48_CATEGORY_PREFIXES[strtoupper($prefix)] ?? 8);
            }
            $size = trim($rawSize);
            $kb = 0;
            if ($size === '') {
                $sizeState = 0;
            } elseif (preg_match('/^([0-9]{1,6})(?:\.([0-9]{1,2}))?([KMG])B$/iD', $size, $match) === 1) {
                $hundredths = (int) $match[1] * 100 + (\strlen($match[2]) === 1 ? (int) $match[2] * 10 : (int) $match[2]);
                $kb = intdiv($hundredths * self::W48_SIZE_UNITS[strtoupper($match[3])], 100);
                $sizeState = 1;
            } else {
                $sizeState = 2;
            }
            $files = trim($rawFiles);
            $filesLength = \strlen($files);
            $count = 0;
            if ($filesLength === 0) {
                $filesState = 0;
            } elseif ($filesLength <= 9 && strspn($files, '0123456789') === $filesLength) {
                $count = (int) $files;
                $filesState = $count === 0 ? 2 : 1;
            } else {
                $filesState = 3;
            }
            $nuked = trim($rawNuked);
            $nukedLength = \strlen($nuked);
            if ($nukedLength === 0) {
                $nuke = 0;
            } elseif ($nukedLength <= 2 && strspn($nuked, '0123456789') === $nukedLength) {
                $nuke = match ((int) $nuked) {
                    Predb::PRE_NONUKE => 0,
                    Predb::PRE_NUKED, Predb::PRE_RENUKED, Predb::PRE_OLDNUKE => 1,
                    Predb::PRE_UNNUKED, Predb::PRE_MODNUKE => 2,
                    default => 3,
                };
            } else {
                $nuke = 4;
            }
            $predate = trim($rawPredate);
            $predateLength = \strlen($predate);
            if ($predateLength === 0) {
                $timeState = 0;
            } elseif ($predateLength <= 12 && strspn($predate, '0123456789') === $predateLength) {
                $time = (int) $predate;
                if ($time >= self::W61_PREDATE_MIN && $time < self::W61_PREDATE_MAX) {
                    $timeState = 1;
                    $minTime = $minTime === 0 ? $time : min($minTime, $time);
                    $maxTime = max($maxTime, $time);
                } else {
                    $timeState = 2;
                }
            } else {
                $timeState = 3;
            }
            $titleStates[$titleState]++;
            $shapes[$shape]++;
            $sourceStates[$sourceState]++;
            $classes[$class]++;
            $sizeStates[$sizeState]++;
            $filesStates[$filesState]++;
            $nukes[$nuke]++;
            $timeStates[$timeState]++;
            $duplicates += $duplicate;
            $kbSum += $kb;
            $maxKb = max($maxKb, $kb);
            $filesSum += $count;
            $out[] = $titleState | $shape << 2 | $sourceState << 5 | $class << 7 | $sizeState << 11 | $filesState << 13 | $nuke << 15
                | $timeState << 18 | $duplicate << 20 | min($length, 1023) << 21 | min($count, 0xFFFFF) << 31;
        }
        array_push($out, ...$titleStates, ...$shapes, ...$sourceStates, ...$classes, ...$sizeStates, ...$filesStates, ...$nukes, ...$timeStates);
        array_push($out, $duplicates, \count($sources), $kbSum, $maxKb, $filesSum, $minTime, $maxTime);

        return $out;
    }

    /**
     * Shape of a valid trimmed title: 1 scene release name, 4 bracketed, 2 spaced, 3 underscored, 0 other.
     */
    private static function w61TitleShape(string $title, int $length): int
    {
        if (strspn($title, self::W61_SCENE_CHARS) === $length) {
            $dash = strrpos($title, '-');
            if ($dash !== false && $dash >= 1 && $dash < $length - 1 && strspn($title, self::W61_WORD_CHARS, $dash + 1) === $length - $dash - 1) {
                return 1;
            }
        }

        return match (true) {
            $title[0] === '[' || $title[0] === '(' => 4,
            str_contains($title, ' ') => 2,
            str_contains($title, '_') => 3,
            default => 0,
        };
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
            $ffi ??= RustPipelineLibrary::resolveDomain('predb_w613', self::W61_PREDB_FEED_ENTRY_FFI_CDEF) ?? false;

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
     * Calls the Wave 61.3 PredbFeedEntry export with the NUL-joined $items, or with the items concatenated plus an
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
            // @phpstan-ignore method.notFound (Declared by W61_PREDB_FEED_ENTRY_FFI_CDEF.)
            $written = $ffi->nntmux_w613_predb_feed_entry_batch($blob, \strlen($blob), $lens, $count, $out, $words);
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

<?php

declare(strict_types=1);

namespace App\Services\Predb\Feeds;

use App\Models\Predb;
use App\Support\RustPipelineLibrary;
use Carbon\CarbonImmutable;
use FFI;
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
}

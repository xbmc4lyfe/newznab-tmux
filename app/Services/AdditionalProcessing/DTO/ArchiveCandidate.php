<?php

declare(strict_types=1);

namespace App\Services\AdditionalProcessing\DTO;

use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use Throwable;

final readonly class ArchiveCandidate
{
    public const bool W44_RUST_FFI_DEFAULT = false;

    public const string FFI_CDEF = <<<'C'
        ssize_t nntmux_w44_archive_candidate_file_name(const char *subject, size_t length, char *output, size_t capacity);
        ssize_t nntmux_w44_archive_candidate_volume_set_key(const char *subject, size_t length, char *output, size_t capacity);
        C;

    /** @return array{enabled: ?bool, ffi: FFI|false|null} */
    private static function &w44State(): array
    {
        static $state = ['enabled' => null, 'ffi' => null];

        return $state;
    }

    public static function useRustFfi(?bool $enable = true): void
    {
        $state = &self::w44State();
        $state['enabled'] = $enable;
    }

    public static function usesRustFfi(): bool
    {
        return self::w44State()['enabled'] ?? self::W44_RUST_FFI_DEFAULT;
    }

    private static function w44Ffi(?string $libraryPath): ?FFI
    {
        if ($libraryPath !== null) {
            return RustPipelineLibrary::resolveDomain('w44_archive_candidate', self::FFI_CDEF, $libraryPath);
        }
        $state = &self::w44State();
        $state['ffi'] ??= RustPipelineLibrary::resolveDomain('w44_archive_candidate', self::FFI_CDEF) ?? false;

        return $state['ffi'] === false ? null : $state['ffi'];
    }

    private static function w44Transform(string $subject, bool $volumeSet, ?string $libraryPath, bool &$supported): ?string
    {
        $supported = false;
        if (strlen($subject) > 16_777_216) {
            return null;
        }
        try {
            $ffi = self::w44Ffi($libraryPath);
            if ($ffi === null) {
                return null;
            }
            $capacity = max(8, strlen($subject) + 8);
            $output = $ffi->new('char['.$capacity.']');
            if ($volumeSet) {
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_w44_archive_candidate_volume_set_key($subject, strlen($subject), $output, $capacity);
            } else {
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_w44_archive_candidate_file_name($subject, strlen($subject), $output, $capacity);
            }
            if (! is_int($written) || $written < 0 || $written > $capacity) {
                return null;
            }
            $supported = true;

            return FFI::string($output, $written);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  list<string>  $messageIds
     * @param  list<string>  $tailMessageIds
     */
    public function __construct(
        public string $title,
        public array $messageIds,
        public bool $likelyFirstVolume,
        public int $sourceIndex,
        public array $tailMessageIds = [],
        public bool $hasFirstSegment = true,
        public string $setKey = '',
    ) {}

    /**
     * Key shared by every volume or piece of the file this candidate belongs to.
     */
    public function volumeSet(): string
    {
        return $this->setKey !== '' ? $this->setKey : self::volumeSetKey($this->title);
    }

    /**
     * Key of the volume set a subject belongs to: the archive family and the name without
     * its volume suffix, e.g. "rar:movie" for movie.part01.rar, movie.rar and movie.r00, or
     * "split:movie.mkv" for movie.mkv.001. Only genuine sibling volumes share a key; a
     * movie.zip or movie.7z next to movie.part01.rar is a different set.
     */
    public static function volumeSetKey(string $subject, ?string $libraryPath = null): string
    {
        return self::usesRustFfi()
            ? self::volumeSetKeyNative($subject, $libraryPath)
            : self::volumeSetKeyPhp($subject);
    }

    public static function volumeSetKeyNative(string $subject, ?string $libraryPath = null, bool &$supported = false): string
    {
        return self::w44Transform($subject, true, $libraryPath, $supported) ?? self::volumeSetKeyPhp($subject);
    }

    public static function volumeSetKeyPhp(string $subject): string
    {
        $name = self::fileNamePhp($subject);
        $families = [
            'rar' => '/^(.+?)\.(?:part\d+\.rar|rar|r\d{2,3})$/',
            '7z' => '/^(.+?)\.7z(?:\.\d{3})?$/',
            'zip' => '/^(.+?)\.(?:zipx?|zipr\d{2,3}|z\d{2,3})$/',
            'split' => '/^([a-f0-9]{32})\.[1-9]\d{1,2}$|^(.+?)\.\d{3}$/',
        ];
        foreach ($families as $family => $pattern) {
            if (preg_match($pattern, $name, $match) === 1) {
                return $family.':'.($match[1] !== '' ? $match[1] : $match[2]);
            }
        }

        return 'file:'.$name;
    }

    /**
     * File name in an NZB subject, quoted ("name.part01.rar" yEnc (1/50)) or not
     * (name.part01.rar yEnc (1/50), [01/10] - name.mkv.001 yEnc), lower-cased.
     *
     * Unquoted, only "yEnc" and what follows, part counters and "-" separators around the
     * name, and "[01/10] - " post counters inside it are dropped. Everything else stays,
     * spaces and any poster prefix included, as the name can't be told apart from it:
     * "First Movie.rar" and "Second Movie.rar" must never share a name.
     */
    public static function fileName(string $subject, ?string $libraryPath = null): string
    {
        return self::usesRustFfi()
            ? self::fileNameNative($subject, $libraryPath)
            : self::fileNamePhp($subject);
    }

    public static function fileNameNative(string $subject, ?string $libraryPath = null, bool &$supported = false): string
    {
        return self::w44Transform($subject, false, $libraryPath, $supported) ?? self::fileNamePhp($subject);
    }

    public static function fileNamePhp(string $subject): string
    {
        if (preg_match('/"([^"\r\n]+)"/', $subject, $quoted) === 1) {
            return strtolower(trim($quoted[1]));
        }

        $counter = '[\[(]\s*\d+\s*(?:\/|of)\s*\d+\s*[\])]';
        $name = (string) preg_replace('/(?:^|\s)yEnc\b.*$/is', '', $subject);
        $name = (string) preg_replace('/(?:\s*(?:-|'.$counter.'))+\s*$/i', '', $name);
        $name = (string) preg_replace('/^(?:\s*(?:-(?=\s)|'.$counter.'))+\s*/i', '', $name);
        $name = (string) preg_replace('/\s+'.$counter.'\s+-\s+/i', ' ', $name);

        return strtolower(trim($name));
    }

    // Rust is the default only while the paired W52.6 benchmark clears 1.5x on all four metrics.
    private const bool W52_RUST_FFI_DEFAULT = true;

    public const string W52_FFI_CDEF = <<<'C'
        int32_t nntmux_w526_archive_volume_set_batch(const char *subjects, size_t subjects_len, const char *subject_twin, size_t subject_twin_len, size_t count, int64_t *out, size_t out_count);
        C;

    public const int W52_ARCHIVE_HEADER = 8;

    private const int W52_MAX_ITEMS = 1_048_576;

    /** `volumeSetKeyPhp()` family prefix => family code. */
    private const array W52_FAMILIES = ['rar' => 0, '7z' => 1, 'zip' => 2, 'split' => 3, 'file' => 4];

    /**
     * Group subjects by `volumeSetKeyPhp()`.
     *
     * Layout: `[0]` count, `[1]` distinct volume sets, `[2..6]` subjects per family (rar, 7z,
     * zip, split, file), `[7]` FNV-1a digest; then per subject `family | leader << 3`, where
     * `leader` is the position of the first subject with the same key.
     *
     * @param  array<array-key, string>  $subjects
     * @return list<int>
     */
    public static function volumeSetGroupBatch(array $subjects): array
    {
        return (self::usesW52RustFfi() ? self::volumeSetGroupBatchNative($subjects) : null) ?? self::volumeSetGroupBatchPhp($subjects);
    }

    /**
     * @param  array<array-key, string>  $subjects
     * @return list<int>
     */
    public static function volumeSetGroupBatchPhp(array $subjects): array
    {
        $out = [\count($subjects), 0, 0, 0, 0, 0, 0, 0];
        $digest = 2_166_136_261;
        $leaders = $rows = [];
        $index = 0;
        foreach ($subjects as $subject) {
            $key = self::volumeSetKeyPhp($subject);
            $family = self::W52_FAMILIES[(string) strstr($key, ':', true)] ?? 4;
            $out[2 + $family]++;
            $leader = $leaders[$key] ??= $index;
            if ($leader === $index) {
                $out[1]++;
            }
            $row = $family | $leader << 3;
            $digest = (($digest ^ (($row ^ ($row >> 32)) & 0xFFFFFFFF)) * 16_777_619) & 0xFFFFFFFF;
            $rows[] = $row;
            $index++;
        }
        $out[7] = $digest;

        return [...$out, ...$rows];
    }

    /**
     * Rust grouping, or null when PHP must compute it (unavailable library, invalid input or a
     * subject holding a vertical tab, which PCRE's `\s` matches but the Rust name parser does not).
     *
     * @param  array<array-key, string>  $subjects
     * @return list<int>|null
     */
    public static function volumeSetGroupBatchNative(array $subjects): ?array
    {
        $count = \count($subjects);
        $fields = self::W52_ARCHIVE_HEADER + $count;
        $binding = $count <= self::W52_MAX_ITEMS ? self::w52RustFfiState('binding', $fields) : null;
        if (! \is_array($binding)) {
            return null;
        }
        [$ffi, $out] = $binding;
        $joined = implode("\0", $subjects);
        $twin = $count > 0 && substr_count($joined, "\0") !== $count - 1 ? implode("\x01", $subjects) : '';
        try {
            $status = $ffi->nntmux_w526_archive_volume_set_batch($joined, \strlen($joined), $twin, \strlen($twin), $count, $out, $fields);
            if ($status !== 1) {
                return null;
            }
            $values = unpack('P*', FFI::string($out, $fields * 8));

            return $values === false ? null : array_values($values);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Run `$callback` with the Wave 52.6 Rust batch path forced on or off, then restore the previous routing.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW52RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w52RustFfiState('set', $enabled);
        try {
            return $callback();
        } finally {
            self::w52RustFfiState('set', $previous);
        }
    }

    public static function usesW52RustFfi(): bool
    {
        $enabled = self::w52RustFfiState();

        return \is_bool($enabled) ? $enabled : self::W52_RUST_FFI_DEFAULT;
    }

    /**
     * Wave 52.6 state, kept in function statics because a readonly class cannot declare static
     * properties. No `$op` returns the routing override (null for the default); `set` replaces
     * it and returns the previous one; `binding` returns the cached binding and an output buffer
     * of at least `$val` values, or null.
     */
    private static function w52RustFfiState(?string $op = null, mixed $val = null): mixed
    {
        static $enabled = null;
        static $ffi = null;
        static $out = null;
        static $capacity = 0;

        if ($op === 'set') {
            $previous = $enabled;
            $enabled = \is_bool($val) ? $val : null;

            return $previous;
        }
        if ($op !== 'binding') {
            return $enabled;
        }
        try {
            $ffi ??= RustPipelineLibrary::resolveDomain('additional_w52', self::W52_FFI_CDEF) ?? false;
            if (! $ffi instanceof FFI) {
                return null;
            }
            $fields = \is_int($val) ? $val : 0;
            if (! $out instanceof CData || $capacity < $fields) {
                $capacity = max(256, $fields);
                $out = $ffi->new('int64_t['.$capacity.']');
            }
        } catch (Throwable) {
            return null;
        }

        return [$ffi, $out];
    }

    // ---- Wave 64.2 batch plan ----------------------------------------------------------------

    /** Enabled: the minimum of three paired runs cleared every 1.5x gate (common mean/p99 8.00x/6.89x, uncommon 7.38x/6.77x); see RustAdditionalConfigConsoleBatchResultWorkPlanArchiveCandidateW64ParityTest. */
    public const bool W64_RUST_FFI_DEFAULT = true;

    private const string W64_ARCHIVE_CANDIDATE_FFI_CDEF = <<<'C'
        intptr_t nntmux_w642_archive_candidate_plan_batch(const char *items, size_t items_len, size_t count, int64_t *out, size_t out_cap);
        C;

    /** Summary words after the per-row words, the digest included. */
    public const int W64_ARCHIVE_CANDIDATE_TAIL = 19;

    /**
     * Wave 64 routing, binding and output buffer, kept in function-local statics (no static or
     * instance properties). Operations: null reads the routing override, 'set' replaces it
     * (returning the previous one), 'reset' also drops the binding and buffer, 'ffi' resolves the
     * binding and 'out' returns `[binding, buffer, capacity]` with room for at least $val words
     * (null without the library).
     */
    private static function w64RustFfiState(?string $op = null, mixed $val = null): mixed
    {
        static $enabled = null;
        static $ffi = null;
        static $out = null;
        static $capacity = 0;

        switch ($op) {
            case 'set':
                $previous = $enabled;
                $enabled = \is_bool($val) ? $val : null;

                return $previous;
            case 'reset':
                $previous = $enabled;
                [$enabled, $ffi, $out, $capacity] = [null, null, null, 0];

                return $previous;
            case 'ffi':
                $ffi ??= RustPipelineLibrary::resolveDomain('nfo_w64', self::W64_ARCHIVE_CANDIDATE_FFI_CDEF) ?? false;

                return $ffi === false ? null : $ffi;
            case 'out':
                $words = \is_int($val) ? $val : 0;
                if (! $out instanceof CData || $capacity < $words) {
                    $resolved = self::w64RustFfiState('ffi');
                    $buffer = $resolved instanceof FFI ? $resolved->new('int64_t['.max(1024, $words).']') : null;
                    if (! $buffer instanceof CData) {
                        return null;
                    }
                    [$out, $capacity] = [$buffer, max(1024, $words)];
                }

                return [$ffi, $out, $capacity];
            default:
                return $enabled;
        }
    }

    /** Routes w64ArchiveCandidatePlanBatch() to Rust until reset. */
    public static function enableW64RustFfi(): void
    {
        self::w64RustFfiState('set', true);
    }

    /** Routes w64ArchiveCandidatePlanBatch() to PHP until reset. */
    public static function disableW64RustFfi(): void
    {
        self::w64RustFfiState('set', false);
    }

    /** Restores W64_RUST_FFI_DEFAULT and drops the cached binding and buffer; earlier toggles are untouched. */
    public static function resetW64RustFfi(): void
    {
        self::w64RustFfiState('reset');
    }

    public static function usesW64RustFfi(): bool
    {
        $enabled = self::w64RustFfiState();

        return \is_bool($enabled) ? $enabled : self::W64_RUST_FFI_DEFAULT;
    }

    /**
     * Runs $callback with w64ArchiveCandidatePlanBatch() routed to Rust ($enabled) or PHP, then restores the previous
     * routing.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW64RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w64RustFfiState('set', $enabled);
        try {
            return $callback();
        } finally {
            self::w64RustFfiState('set', $previous);
        }
    }

    /**
     * @param  array<array-key, string>  $rows
     * @return list<int>
     */
    public static function w64ArchiveCandidatePlanBatch(array $rows): array
    {
        return self::usesW64RustFfi() ? self::w64ArchiveCandidatePlanBatchNative($rows) : self::w64ArchiveCandidatePlanBatchPhp($rows);
    }

    /**
     * Rust batch; falls back to PHP when the library is missing, a row holds a NUL byte or the
     * column is not a list.
     *
     * @param  array<array-key, string>  $rows
     * @return list<int>
     */
    public static function w64ArchiveCandidatePlanBatchNative(array $rows): array
    {
        $count = \count($rows);
        $words = $count + self::W64_ARCHIVE_CANDIDATE_TAIL;
        /** @var array{FFI, CData, int}|null $state */
        $state = $count === 0 ? null : self::w64RustFfiState('out', $words);
        if ($state === null || ! array_is_list($rows)) {
            return self::w64ArchiveCandidatePlanBatchPhp($rows);
        }
        $blob = implode("\0", $rows);
        [$ffi, $out, $cap] = $state;
        try {
            // @phpstan-ignore method.notFound (Declared by W64_ARCHIVE_CANDIDATE_FFI_CDEF.)
            $written = $ffi->nntmux_w642_archive_candidate_plan_batch($blob, \strlen($blob), $count, $out, $cap);
        } catch (Throwable) {
            $written = -1;
        }
        /** @var array<int, int>|false $values */
        $values = $written === $words ? unpack('P*', FFI::string($out, $words * 8)) : false;

        return \is_array($values) ? array_values($values) : self::w64ArchiveCandidatePlanBatchPhp($rows);
    }

    /** @param  list<int>  $out */
    private static function w64Digest(array $out): int
    {
        /** @var array{1: int} $digest */
        $digest = unpack('J', hash('xxh64', pack('P*', ...$out), true));

        return $digest[1];
    }

    /** A trimmed field: null when empty, its value for 1 to $max ASCII digits, otherwise false. */
    private static function w64Int(string $value, int $max): int|false|null
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        $length = \strlen($value);

        return $length <= $max && strspn($value, '0123456789') === $length ? (int) $value : false;
    }

    /** Bytes of first volumes one batch may sample. */
    public const int W64_ARCHIVE_BUDGET = 67_108_864;

    /** Budget class upper bounds: under 1 MiB, up to 16 MiB, up to 4 GiB. */
    private const int W64_SMALL_BYTES = 1_048_576;

    private const int W64_SAMPLE_BYTES = 16_777_216;

    private const int W64_LARGE_BYTES = 4_294_967_296;

    private static function w64Digits(string $value): bool
    {
        return strspn($value, '0123456789') === \strlen($value);
    }

    /**
     * Family (0 rar, 1 7z, 2 zip, 3 split, 4 file), part order and set stem of a lowercased name:
     * `.partN.rar` (1-4 digits) is part N and `.rar` part 1 of a RAR set, `.rNN`/`.rNNN` part NN
     * + 2; `.7z` is part 1 and `.7z.NNN` part NNN of a 7-Zip set; `.zip`/`.zipx` part 1 and
     * `.zNN`/`.zNNN` part NN + 1 of a zip set; any other `.NNN` part NNN of a split file. A name
     * with no such suffix, or an empty stem, is a part-1 file keyed by the whole name.
     *
     * @return array{int, int, string}
     */
    private static function w64Volume(string $name): array
    {
        $dot = strrpos($name, '.');
        if ($dot === false) {
            return [4, 1, $name];
        }
        $base = substr($name, 0, $dot);
        $extension = substr($name, $dot + 1);
        $length = \strlen($extension);
        $numbered = static fn (string $lead): bool => ($length === 3 || $length === 4) && $extension[0] === $lead && self::w64Digits(substr($extension, 1));
        if ($extension === 'rar') {
            $part = strrpos($base, '.');
            $tail = $part === false ? '' : substr($base, $part + 1);
            if ($part !== false && \strlen($tail) >= 5 && \strlen($tail) <= 8 && str_starts_with($tail, 'part') && self::w64Digits(substr($tail, 4))) {
                [$family, $order, $base] = [0, (int) substr($tail, 4), substr($base, 0, $part)];
            } else {
                [$family, $order] = [0, 1];
            }
        } elseif ($numbered('r')) {
            [$family, $order] = [0, (int) substr($extension, 1) + 2];
        } elseif ($extension === '7z') {
            [$family, $order] = [1, 1];
        } elseif ($length === 3 && self::w64Digits($extension)) {
            [$family, $order] = str_ends_with($base, '.7z') ? [1, (int) $extension] : [3, (int) $extension];
            if ($family === 1) {
                $base = substr($base, 0, -3);
            }
        } elseif ($extension === 'zip' || $extension === 'zipx') {
            [$family, $order] = [2, 1];
        } elseif ($numbered('z')) {
            [$family, $order] = [2, (int) substr($extension, 1) + 1];
        } else {
            return [4, 1, $name];
        }

        return $base === '' ? [4, 1, $name] : [$family, $order, $base];
    }

    /**
     * Wave 64.2 archive candidate plan. Per `name\tsegments\tbytes\tsource_index` row `family |
     * first << 3 | min(order, 65535) << 4 | budget_class << 20 | included << 23 | duplicate_source
     * << 24 | malformed << 25 | min(leader, 2^20 - 1) << 26 | (crc32(stem) & 0xFFFF) << 46`. The
     * trimmed, lowercased name gives family, order and stem (see w64Volume()); first is order <= 1
     * and the leader is the position of the first row of the same family and stem. Segments, bytes
     * and source_index are w64Int() fields (6, 12 and 9 digits); an unparsable one sets malformed.
     * Budget classes: 0 unknown bytes, 1 under 1 MiB, 2 up to 16 MiB, 3 up to 4 GiB, 4 larger.
     * First archive volumes (not plain files) with known bytes are included while the running
     * total stays within W64_ARCHIVE_BUDGET; a source index seen earlier is a duplicate. Then five
     * family and five budget class counts, first volumes, distinct sets, sets without a first
     * volume, included and total known bytes, malformed rows, duplicate sources, the highest order
     * and the XXH64 digest of all earlier words.
     *
     * @param  array<array-key, string>  $rows
     * @return list<int>
     */
    public static function w64ArchiveCandidatePlanBatchPhp(array $rows): array
    {
        $out = $setOf = $leaders = $hasFirst = $sources = [];
        $families = $classes = array_fill(0, 5, 0);
        $firstRows = $includedBytes = $totalBytes = $malformedRows = $duplicateRows = $maxOrder = 0;
        $index = 0;
        foreach ($rows as $row) {
            [$name, $segments, $bytes, $source] = explode("\t", (string) $row, 4) + array_fill(0, 4, '');
            [$family, $order, $base] = self::w64Volume(strtolower(trim($name)));
            $segments = self::w64Int($segments, 6);
            $bytes = self::w64Int($bytes, 12);
            $source = self::w64Int($source, 9);
            $malformed = $segments === false || $bytes === false || $source === false;
            $bytes = \is_int($bytes) ? $bytes : null;
            $first = $order <= 1;
            $key = $family.$base;
            if (! isset($setOf[$key])) {
                $setOf[$key] = \count($leaders);
                $leaders[] = $index;
                $hasFirst[] = false;
            }
            $set = $setOf[$key];
            if ($first) {
                $hasFirst[$set] = true;
            }
            $class = match (true) {
                $bytes === null => 0,
                $bytes < self::W64_SMALL_BYTES => 1,
                $bytes <= self::W64_SAMPLE_BYTES => 2,
                $bytes <= self::W64_LARGE_BYTES => 3,
                default => 4,
            };
            $included = $bytes !== null && $first && $family !== 4 && $includedBytes + $bytes <= self::W64_ARCHIVE_BUDGET;
            if ($included) {
                $includedBytes += $bytes;
            }
            $duplicate = false;
            if (\is_int($source)) {
                $duplicate = isset($sources[$source]);
                $sources[$source] = true;
            }
            $families[$family]++;
            $classes[$class]++;
            $firstRows += (int) $first;
            $totalBytes += $bytes ?? 0;
            $malformedRows += (int) $malformed;
            $duplicateRows += (int) $duplicate;
            $maxOrder = max($maxOrder, $order);
            $out[] = $family | (int) $first << 3 | min($order, 0xFFFF) << 4 | $class << 20 | (int) $included << 23 | (int) $duplicate << 24 | (int) $malformed << 25
                | min($leaders[$set], 0xFFFFF) << 26 | (crc32($base) & 0xFFFF) << 46;
            $index++;
        }
        array_push($out, ...$families, ...$classes);
        array_push($out, $firstRows, \count($leaders), \count(array_filter($hasFirst, static fn (bool $seen): bool => ! $seen)), $includedBytes, $totalBytes,
            $malformedRows, $duplicateRows, $maxOrder);
        $out[] = self::w64Digest($out);

        return $out;
    }
}

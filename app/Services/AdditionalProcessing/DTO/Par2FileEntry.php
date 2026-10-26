<?php

declare(strict_types=1);

namespace App\Services\AdditionalProcessing\DTO;

use App\Services\AdditionalProcessing\Enums\VideoContainer;
use App\Services\Par2FileList;
use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use InvalidArgumentException;
use Throwable;

/**
 * A file as the release's PAR2 index describes it.
 */
final readonly class Par2FileEntry
{
    public const bool W44_RUST_FFI_DEFAULT = false;

    public const string FFI_CDEF = <<<'C'
        ssize_t nntmux_w44_par2_normalize_entries(const char *input, size_t length, size_t count, char *output, size_t capacity);
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

    /**
     * @param  string  $hash16k  MD5 of the file's first 16 KiB, lower-case hex
     */
    public function __construct(
        public string $name,
        public int $size,
        public string $hash16k = '',
    ) {}

    /**
     * @param  array<int|string, mixed>  $files  Par2Info::getFileList()
     * @return list<self>
     */
    public static function listFromPar2Info(array $files): array
    {
        return self::usesRustFfi() ? self::listFromPar2InfoNative($files) : self::listFromPar2InfoPhp($files);
    }

    /**
     * @param  array<int|string, mixed>  $files
     * @return list<self>
     */
    public static function listFromPar2InfoPhp(array $files): array
    {
        $entries = [];
        foreach ($files as $file) {
            if (! is_array($file) || ! is_string($file['name'] ?? null) || (int) ($file['size'] ?? 0) <= 0) {
                continue;
            }

            $entries[] = new self(
                name: $file['name'],
                size: (int) $file['size'],
                hash16k: strtolower((string) ($file['hash_16K'] ?? '')),
            );
        }

        return $entries;
    }

    /**
     * @param  array<int|string, mixed>  $files
     * @return list<self>
     */
    public static function listFromPar2InfoNative(array $files, bool &$supported = false): array
    {
        $supported = false;
        if (PHP_INT_SIZE !== 8) {
            return self::listFromPar2InfoPhp($files);
        }
        // PHP owns mixed-value coercion: its integer casts and string conversion behavior
        // must remain identical for unusual parser output as well as valid descriptors.
        $input = '';
        $count = 0;
        $coerced = [];
        $tooLarge = false;
        foreach ($files as $file) {
            if (! is_array($file) || ! is_string($file['name'] ?? null) || (int) ($file['size'] ?? 0) <= 0) {
                continue;
            }
            $name = $file['name'];
            $size = (int) $file['size'];
            $hash = (string) ($file['hash_16K'] ?? '');
            $coerced[] = ['name' => $name, 'size' => $size, 'hash_16K' => $hash];
            if ($tooLarge || strlen($name) > 16_777_216 || strlen($hash) > 16_777_216 || strlen($input) + strlen($name) + strlen($hash) + 16 > 16_777_216) {
                $tooLarge = true;

                continue;
            }
            $input .= pack('VqV', strlen($name), $size, strlen($hash)).$name.$hash;
            $count++;
        }
        if ($tooLarge) {
            return self::listFromPar2InfoPhp($coerced);
        }
        if ($count === 0) {
            $supported = true;

            return [];
        }
        try {
            $state = &self::w44State();
            $state['ffi'] ??= RustPipelineLibrary::resolveDomain('w44_par2_file_entry', self::FFI_CDEF) ?? false;
            if ($state['ffi'] === false) {
                return self::listFromPar2InfoPhp($coerced);
            }
            $capacity = strlen($input);
            $output = $state['ffi']->new('char['.$capacity.']');
            // @phpstan-ignore method.notFound
            $written = $state['ffi']->nntmux_w44_par2_normalize_entries($input, $capacity, $count, $output, $capacity);
            if ($written !== $capacity) {
                return self::listFromPar2InfoPhp($coerced);
            }
            $encoded = FFI::string($output, $written);
            $entries = [];
            $offset = 0;
            for ($index = 0; $index < $count; $index++) {
                $header = unpack('Vname/qsize/Vhash', $encoded, $offset);
                if ($header === false || ! is_int($header['name']) || ! is_int($header['size']) || ! is_int($header['hash']) || $header['size'] <= 0) {
                    return self::listFromPar2InfoPhp($coerced);
                }
                $offset += 16;
                $entries[] = new self(substr($encoded, $offset, $header['name']), $header['size'], substr($encoded, $offset + $header['name'], $header['hash']));
                $offset += $header['name'] + $header['hash'];
            }
            $supported = true;

            return $entries;
        } catch (Throwable) {
            return self::listFromPar2InfoPhp($coerced);
        }
    }

    /**
     * Entries of a raw PAR2 index, empty when Par2Info cannot parse it.
     *
     * Parses through Par2FileList::extractFilesNative(): 7.86x mean / 9.58x p99 on typical
     * index files and 10.55x / 12.48x on large sets in paired benchmarks.
     *
     * @return list<self>
     */
    public static function listFromPar2Data(string $data): array
    {
        return self::listFromPar2DataNative($data);
    }

    /**
     * @return list<self>
     */
    public static function listFromPar2DataPhp(string $data): array
    {
        $parsed = Par2FileList::extractFilesPhp($data);

        return $parsed === null ? [] : self::listFromPar2InfoPhp($parsed['files']);
    }

    /**
     * @return list<self>
     */
    public static function listFromPar2DataNative(string $data, bool &$supported = false): array
    {
        $parsed = Par2FileList::extractFilesNative($data, supported: $supported);

        return $parsed === null ? [] : self::listFromPar2Info($parsed['files']);
    }

    /**
     * Whether $data is the start of this file.
     */
    public function matchesStartOf(string $data): bool
    {
        $length = min(16384, $this->size);

        return $this->hash16k !== '' && strlen($data) >= $length && md5(substr($data, 0, $length)) === $this->hash16k;
    }

    public const string W52_FFI_CDEF = 'int64_t nntmux_w525_par2_entry_volume_plan(size_t count, int64_t flags, const char *data, size_t data_len, const char *ints, size_t ints_len, int64_t *out, size_t out_cap);';

    /** Enabled: paired common/uncommon mean and p99 gains clear 1.5x (see the W52.5 parity test's QUALIFICATION). */
    private const bool W52_RUST_FFI_DEFAULT = true;

    /**
     * Wave 52.5 routing and FFI cache, kept in function-local statics so readonly classes can use them too.
     * Without $op it reports the routing; 'set' swaps the override for $val and returns the previous one;
     * 'reset' replaces the cached handle with $val (null re-resolves, false disables); 'buffer' returns
     * `[FFI, int64_t[], capacity]` with room for at least $val words, or null without the library.
     */
    private static function w52RustFfiState(?string $op = null, mixed $val = null): mixed
    {
        static $enabled = null;
        static $ffi = null;
        static $out = null;
        static $outCap = 0;

        switch ($op) {
            case 'set':
                $previous = $enabled;
                $enabled = $val;

                return $previous;
            case 'reset':
                $ffi = $val;
                $out = null;
                $outCap = 0;

                return null;
            case 'buffer':
                if ($ffi === null) {
                    $path = getenv('NNTMUX_PIPELINE_FFI_LIB');
                    $ffi = RustPipelineLibrary::resolveDomain('w52_5', self::W52_FFI_CDEF, \is_string($path) && $path !== '' ? $path : null) ?? false;
                }
                if (! $ffi instanceof FFI || ! \is_int($val)) {
                    return null;
                }
                if ($out === null || $outCap < $val) {
                    $outCap = max(1024, $val);
                    $out = $ffi->new('int64_t['.$outCap.']');
                }

                return [$ffi, $out, $outCap];
            default:
                return $enabled ?? self::W52_RUST_FFI_DEFAULT;
        }
    }

    public static function usesW52RustFfi(): bool
    {
        return self::w52RustFfiState() === true;
    }

    /**
     * Runs $callback with volumePlanBatch() routed to Rust ($enabled) or PHP, then restores the previous routing.
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

    /**
     * Exactly $words output words from the Wave 52.5 Rust helper, or null to fall back to PHP.
     *
     * @return list<int>|null
     */
    private static function w52Words(int $count, string $data, string $ints, int $words): ?array
    {
        try {
            $handle = self::w52RustFfiState('buffer', $words);
            if (! \is_array($handle)) {
                return null;
            }
            [$ffi, $out, $cap] = $handle;
            $written = $ffi->nntmux_w525_par2_entry_volume_plan($count, 0, $data, \strlen($data), $ints, \strlen($ints), $out, $cap);
            if ($written !== $words) {
                return null;
            }
            /** @var array<int, int>|false $values */
            $values = unpack('P*', FFI::string($out, $written * 8));

            return \is_array($values) && \count($values) === $words ? array_values($values) : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Batch volume plan of PAR2-described names. Three words per entry: `[kind | hash valid << 8, number,
     * extra]`. Kinds: 1 `.partN.rar` (number N), 2 `.rar`, 3 `.rNN` (number NN), 4 `.NNN` (number NNN), 5 a
     * `.par2` index, 6 a `.volN+M.par2` volume (number N, extra M), 7 `.7z`, 8 `.zip`, else 0; number and
     * extra are -1 when absent. Hash valid is 1 for a 32-digit lower-case hex hash16k.
     *
     * @param  list<self>  $entries
     * @return list<int>
     */
    public static function volumePlanBatch(array $entries): array
    {
        return self::usesW52RustFfi() ? self::volumePlanBatchNative($entries) : self::volumePlanBatchPhp($entries);
    }

    /**
     * @param  list<self>  $entries
     * @return list<int>
     */
    public static function volumePlanBatchPhp(array $entries): array
    {
        $out = [];
        foreach ($entries as $entry) {
            $name = $entry->name;
            if (preg_match('/\.vol(\d{1,5})\+(\d{1,5})\.par2$/iD', $name, $match) === 1) {
                $plan = [6, (int) $match[1], (int) $match[2]];
            } elseif (preg_match('/\.par2$/iD', $name) === 1) {
                $plan = [5, -1, -1];
            } elseif (preg_match('/\.part(\d{1,4})\.rar$/iD', $name, $match) === 1) {
                $plan = [1, (int) $match[1], -1];
            } elseif (preg_match('/\.rar$/iD', $name) === 1) {
                $plan = [2, -1, -1];
            } elseif (preg_match('/\.r(\d{2,3})$/iD', $name, $match) === 1) {
                $plan = [3, (int) $match[1], -1];
            } elseif (preg_match('/\.(\d{3})$/D', $name, $match) === 1) {
                $plan = [4, (int) $match[1], -1];
            } elseif (preg_match('/\.7z$/iD', $name) === 1) {
                $plan = [7, -1, -1];
            } elseif (preg_match('/\.zip$/iD', $name) === 1) {
                $plan = [8, -1, -1];
            } else {
                $plan = [0, -1, -1];
            }
            array_push($out, $plan[0] | (preg_match('/^[0-9a-f]{32}$/D', $entry->hash16k) === 1 ? 256 : 0), $plan[1], $plan[2]);
        }

        return $out;
    }

    /**
     * Rust batch over the NUL-delimited names followed by the hashes; falls back to PHP when the library is
     * missing or a name or hash holds a NUL byte.
     *
     * @param  list<self>  $entries
     * @return list<int>
     */
    public static function volumePlanBatchNative(array $entries): array
    {
        $count = \count($entries);
        if ($count === 0) {
            return [];
        }
        $data = implode("\0", array_column($entries, 'name'))."\0".implode("\0", array_column($entries, 'hash16k'));

        return (substr_count($data, "\0") === 2 * $count - 1 ? self::w52Words($count, $data, '', 3 * $count) : null)
            ?? self::volumePlanBatchPhp($entries);
    }

    // ========================================================================
    // WAVE 64.4 BATCH HELPER
    // ========================================================================

    /** Enabled: the minimum of three 1000-round paired runs reached 3.74x common mean, 3.09x common p99, 3.24x uncommon mean and 3.11x uncommon p99; see RustAdditionalSourcePlanPar2EntryPersistenceSlicerContainerW64ParityTest. */
    public const bool W64_RUST_FFI_DEFAULT = true;

    private const string W64_FFI_CDEF = 'ssize_t nntmux_w644_par2_file_entry_plan_batch(const char *blob, size_t blob_len, const char *twin, size_t twin_len, const char *ints, size_t ints_len, size_t count, int64_t *out, size_t out_cap);';

    /** int64 words per w64Par2FileEntryPlanBatch() record. */
    public const int W64_ROW_WORDS = 5;

    /** int64 summary words after the w64Par2FileEntryPlanBatch() records (none for an empty batch). */
    public const int W64_TRAILER_WORDS = 10;

    private const array W64_CONTAINER_CODES = ['matroska' => 1, 'avi' => 2, 'mpeg' => 3, 'mpegts' => 4, 'mov' => 5, 'asf' => 6, 'ogg' => 7];

    private const string W64_SAMPLE = '/(?:^|[\\\\\/._ -])sample(?:[\\\\\/._ -]|$)/i';

    private const int W64_VALUE_CAP = (1 << 40) - 1;

    /**
     * PAR2 file entry plan rows for [name, hash16k, size] descriptors.
     *
     * Roles, first match wins: 6 `.volN+M.par2` (number `N | M << 20`), 5 `.par2`, 2
     * `.partN.rar` (number N) or `.rar`, 3 `.rNN`, 4 `.NNN`, 7 `.7z`, 8 `.zip`, 1 a
     * VideoContainer::fromFileName() video, 9 `.nfo`, 10 audio, 11 image, else 0 (suffixes are
     * case-insensitive; number -1 when absent). Hash classes: 0 empty, 1 32 hex digits with an
     * upper-case one, 2 32 lower-case hex digits, 3 other. Flags: 1 repeated name, 2 repeated
     * (lower-cased) valid hash, 4 sample name, 8 size < 1, 16 empty name. The 16k slice covers
     * min(size, 16384) bytes (0 for size < 1).
     *
     * Rows [role | hash class << 4 | flags << 8 | container << 16 | name length << 20 (clamped
     * to 2^16 - 1) | slice << 36, fnv1a32(name) (0 when empty), the first 15 hex digits of a
     * valid hash as an int (-1 otherwise), size (clamped to [0, 2^40)), number], then the trailer
     * [records, media, archive volumes (roles 2, 3, 4, 7, 8), PAR2 files, other, valid hashes,
     * repeated names, repeated hashes, total size, FNV-1a digest].
     *
     * @param  list<array{0: string, 1: string, 2: int}>  $items
     * @return list<int>
     */
    public static function w64Par2FileEntryPlanBatch(array $items): array
    {
        return self::usesW64RustFfi() ? self::w64Par2FileEntryPlanBatchNative($items) : self::w64Par2FileEntryPlanBatchPhp($items);
    }

    /**
     * @param  list<array{0: string, 1: string, 2: int}>  $items
     * @return list<int>
     */
    public static function w64Par2FileEntryPlanBatchPhp(array $items): array
    {
        self::w64Items($items);
        if ($items === []) {
            return [];
        }
        $out = $names = $hashes = [];
        $t = [\count($items), 0, 0, 0, 0, 0, 0, 0, 0, 0];
        $digest = 2_166_136_261;
        foreach ($items as [$name, $hash, $raw]) {
            $video = VideoContainer::fromFileName($name);
            $container = $video === null ? 0 : self::W64_CONTAINER_CODES[$video->value];
            [$role, $number] = self::w64Role($name, $container);
            $class = match (true) {
                $hash === '' => 0,
                \strlen($hash) !== 32 || strspn($hash, '0123456789abcdefABCDEF') !== 32 => 3,
                strspn($hash, '0123456789abcdef') === 32 => 2,
                default => 1,
            };
            $size = max(0, min($raw, self::W64_VALUE_CAP));
            $dupName = isset($names[$name]);
            $names[$name] = true;
            $dupHash = false;
            $prefix = -1;
            if ($class === 1 || $class === 2) {
                $lower = strtolower($hash);
                $dupHash = isset($hashes[$lower]);
                $hashes[$lower] = true;
                $prefix = (int) hexdec(substr($lower, 0, 15));
                $t[5]++;
            }
            $flags = ($dupName ? 1 : 0) | ($dupHash ? 2 : 0) | (preg_match(self::W64_SAMPLE, $name) === 1 ? 4 : 0) | ($raw <= 0 ? 8 : 0) | ($name === '' ? 16 : 0);
            $nameHash = self::w64Fnv($name);
            $word = $role | $class << 4 | $flags << 8 | $container << 16 | min(\strlen($name), 0xFFFF) << 20 | ($raw > 0 ? min($raw, 16_384) : 0) << 36;
            array_push($out, $word, $nameHash, $prefix, $size, $number);
            $t[match ($role) {
                1 => 1,
                2, 3, 4, 7, 8 => 2,
                5, 6 => 3,
                default => 4,
            }]++;
            $t[6] += $dupName ? 1 : 0;
            $t[7] += $dupHash ? 1 : 0;
            $t[8] += $size;
            $value = $word ^ $nameHash << 12 ^ $number;
            $digest = (($digest ^ (($value ^ ($value >> 32)) & 0xFFFFFFFF)) * 16_777_619) & 0xFFFFFFFF;
        }
        $t[9] = $digest;

        return [...$out, ...$t];
    }

    /**
     * @param  list<array{0: string, 1: string, 2: int}>  $items
     * @return list<int>
     */
    public static function w64Par2FileEntryPlanBatchNative(array $items): array
    {
        self::w64Items($items);
        $count = \count($items);
        if ($count === 0) {
            return [];
        }
        [$blob, $twin] = self::w64Join([array_column($items, 0), array_column($items, 1)]);
        $ints = pack('P*', ...array_column($items, 2));

        return self::w64Call($blob, $twin, $ints, $count, $count * self::W64_ROW_WORDS + self::W64_TRAILER_WORDS)
            ?? self::w64Par2FileEntryPlanBatchPhp($items);
    }

    /**
     * [role, number] of a PAR2-described name; see w64Par2FileEntryPlanBatch().
     *
     * @return array{0: int, 1: int}
     */
    private static function w64Role(string $name, int $container): array
    {
        if (preg_match('/\.vol(\d{1,5})\+(\d{1,5})\.par2$/iD', $name, $match) === 1) {
            return [6, (int) $match[1] | (int) $match[2] << 20];
        }
        if (preg_match('/\.par2$/iD', $name) === 1) {
            return [5, -1];
        }
        if (preg_match('/\.part(\d{1,4})\.rar$/iD', $name, $match) === 1) {
            return [2, (int) $match[1]];
        }
        if (preg_match('/\.rar$/iD', $name) === 1) {
            return [2, -1];
        }
        if (preg_match('/\.r(\d{2,3})$/iD', $name, $match) === 1) {
            return [3, (int) $match[1]];
        }
        if (preg_match('/\.(\d{3})$/D', $name, $match) === 1) {
            return [4, (int) $match[1]];
        }

        return match (true) {
            preg_match('/\.7z$/iD', $name) === 1 => [7, -1],
            preg_match('/\.zip$/iD', $name) === 1 => [8, -1],
            $container !== 0 => [1, -1],
            preg_match('/\.nfo$/iD', $name) === 1 => [9, -1],
            preg_match('/\.(?:mp3|flac|m4a|ogg|wav)$/iD', $name) === 1 => [10, -1],
            preg_match('/\.(?:jpe?g|png|gif)$/iD', $name) === 1 => [11, -1],
            default => [0, -1],
        };
    }

    /** @param array<array-key, mixed> $items */
    private static function w64Items(array $items): void
    {
        if (! array_is_list($items)) {
            throw new InvalidArgumentException('W64 PAR2 file entry plan batch items must be a list.');
        }
        foreach ($items as $item) {
            if (! \is_array($item) || \count($item) !== 3 || ! array_is_list($item) || ! \is_string($item[0]) || ! \is_string($item[1]) || ! \is_int($item[2])) {
                throw new InvalidArgumentException('W64 PAR2 file entry plan batch items must be [string name, string hash16k, int size] lists.');
            }
        }
    }

    public static function usesW64RustFfi(): bool
    {
        $enabled = self::w64RustFfiState('enabled');

        return \is_bool($enabled) ? $enabled : self::W64_RUST_FFI_DEFAULT;
    }

    public static function enableW64RustFfi(): void
    {
        self::w64RustFfiState('enabled', true);
    }

    public static function disableW64RustFfi(): void
    {
        self::w64RustFfiState('enabled', false);
    }

    /** Drop the W64 override, binding, output buffer and declined-call count; routing returns to W64_RUST_FFI_DEFAULT. */
    public static function resetW64RustFfi(): void
    {
        self::w64RustFfiState('reset');
    }

    /**
     * Run $callback with the W64 dispatch forced on or off, then restore the previous choice.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW64RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w64RustFfiState('enabled');
        self::w64RustFfiState('enabled', $enabled);
        try {
            return $callback();
        } finally {
            self::w64RustFfiState('enabled', $previous);
        }
    }

    /**
     * String columns NUL-joined field-major and, when a value holds a NUL, the same columns
     * joined by 0x01 so Rust finds the real separators.
     *
     * @param  list<list<string>>  $columns
     * @return array{0: string, 1: string}
     */
    private static function w64Join(array $columns): array
    {
        $values = array_merge(...$columns);
        $joined = implode("\0", $values);

        return substr_count($joined, "\0") === \count($values) - 1 ? [$joined, ''] : [$joined, implode("\x01", $values)];
    }

    /**
     * One FFI crossing for the batch: $need int64 words back. Null when the library is missing
     * or declines a malformed frame, so the caller falls back to PHP; a declined call with a
     * resolved library is counted in the 'misses' state.
     *
     * @return list<int>|null
     */
    private static function w64Call(string $blob, string $twin, string $ints, int $count, int $need): ?array
    {
        /** @var array{enabled: ?bool, ffi: FFI|false|null, out: ?CData, cap: int, misses: int} $state */
        $state = self::w64RustFfiState();
        $ffi = $state['ffi'];
        if ($ffi === null) {
            $ffi = RustPipelineLibrary::resolveDomain('metadata_w64', self::W64_FFI_CDEF) ?? false;
            self::w64RustFfiState('ffi', $ffi);
        }
        if (! $ffi instanceof FFI) {
            return null;
        }
        try {
            $out = $state['out'];
            $cap = $state['cap'];
            if (! $out instanceof CData || $cap < $need) {
                $cap = max(4096, $need);
                $out = $ffi->new('int64_t['.$cap.']');
                self::w64RustFfiState('out', $out);
                self::w64RustFfiState('cap', $cap);
            }
            // @phpstan-ignore method.notFound (Declared by W64_FFI_CDEF.)
            $written = $ffi->nntmux_w644_par2_file_entry_plan_batch($blob, \strlen($blob), $twin, \strlen($twin), $ints, \strlen($ints), $count, $out, $cap);
            $values = $written === $need ? unpack('P*', FFI::string($out, $need * 8)) : false;
            if ($values !== false) {
                return array_values($values);
            }
        } catch (Throwable) {
        }
        self::w64RustFfiState('misses', $state['misses'] + 1);

        return null;
    }

    /**
     * W64 routing override, cached binding, reusable output buffer and declined-call count, kept
     * in a function static so the class declares no new property: no arguments returns the state,
     * one argument reads a key ('reset' clears everything) and two arguments write a key.
     */
    private static function w64RustFfiState(?string $op = null, mixed $val = null): mixed
    {
        /** @var array{enabled: ?bool, ffi: FFI|false|null, out: ?CData, cap: int, misses: int} $state */
        static $state = ['enabled' => null, 'ffi' => null, 'out' => null, 'cap' => 0, 'misses' => 0];
        if ($op === null) {
            return $state;
        }
        if ($op === 'reset') {
            $state = ['enabled' => null, 'ffi' => null, 'out' => null, 'cap' => 0, 'misses' => 0];

            return null;
        }
        if (\func_num_args() > 1) {
            $state[$op] = $val;

            return null;
        }

        return $state[$op] ?? null;
    }

    /** FNV-1a 32 of $bytes as an unsigned int, 0 for an empty string. */
    private static function w64Fnv(string $bytes): int
    {
        if ($bytes === '') {
            return 0;
        }
        /** @var array{1: int} $hash */
        $hash = unpack('N', hash('fnv1a32', $bytes, true));

        return $hash[1];
    }
}

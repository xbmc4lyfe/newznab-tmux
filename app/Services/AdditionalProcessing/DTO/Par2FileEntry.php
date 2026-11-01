<?php

declare(strict_types=1);

namespace App\Services\AdditionalProcessing\DTO;

use App\Services\Par2FileList;
use App\Support\RustPipelineLibrary;
use FFI;
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
}

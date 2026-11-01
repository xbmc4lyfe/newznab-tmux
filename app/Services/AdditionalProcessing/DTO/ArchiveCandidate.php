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
}

<?php

declare(strict_types=1);

namespace App\Services\AdditionalProcessing\DTO;

use App\Support\RustPipelineLibrary;
use FFI;
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
}

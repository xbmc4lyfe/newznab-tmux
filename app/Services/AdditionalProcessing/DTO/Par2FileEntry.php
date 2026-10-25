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
}

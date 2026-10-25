<?php

declare(strict_types=1);

namespace App\Services\Predb\Feeds\Sources;

use App\Support\RustPipelineLibrary;
use FFI;
use Throwable;

final class ThreeDsDbSource extends CompleteXmlCatalogueSource
{
    /**
     * Production default stays false: the paired benchmark in
     * tests/Unit/RustPredbSourcesHttpNswdb3dsdbRateParityTest.php is below 1.5x.
     */
    public const bool THREE_DS_DB_RUST_FFI_DEFAULT = false;

    /** Distinct from the parent HTTP feed, bounded feed and catalogue FFI constants. */
    private const string THREE_DS_DB_FFI_CDEF = <<<'C'
        ssize_t nntmux_w384_catalogue_page_plan(
            uint32_t source,
            int64_t entry_count,
            int64_t page_size,
            int64_t page,
            char *out_buf,
            size_t out_cap
        );
        C;

    /** Selects the 3dsdb key/label in nntmux_w384_catalogue_page_plan(). */
    private const int THREE_DS_DB_NATIVE_SOURCE = 1;

    /** Mirrors the complete-export record cap enforced by the parent constructor. */
    private const int THREE_DS_DB_MAX_RECORDS = 5000;

    private const string THREE_DS_DB_FIELD_SEPARATOR = "\x1F";

    private static ?bool $threeDsDbUseRustFfi = null;

    private static FFI|false|null $threeDsDbFfi = null;

    public function key(): string
    {
        return '3dsdb';
    }

    protected function sourceLabel(): string
    {
        return '3dsdb.com';
    }

    public static function useThreeDsDbRustFfi(?bool $enabled = true): void
    {
        self::$threeDsDbUseRustFfi = $enabled;
    }

    public static function usesThreeDsDbRustFfi(): bool
    {
        return self::$threeDsDbUseRustFfi ?? self::THREE_DS_DB_RUST_FFI_DEFAULT;
    }

    public static function resetThreeDsDbRustFfi(): void
    {
        self::$threeDsDbUseRustFfi = null;
        self::$threeDsDbFfi = null;
    }

    /**
     * Mirrors fetch() paging over the parsed export: the entry count is clamped to the 5000-record
     * cap and the page size to at least one; pages start at one (an invalid page has no slice)
     * and a page past the last one is empty. Also reports the key, label and log prefix.
     *
     * @return array{key: string, label: string, log_prefix: string, valid_page: bool, page_count: int, past_end: bool, offset: int, length: int, entries: int}
     */
    public static function planThreeDsDbPage(int $entryCount, int $pageSize, int $page): array
    {
        return self::usesThreeDsDbRustFfi()
            ? self::planThreeDsDbPageNative($entryCount, $pageSize, $page)
            : self::planThreeDsDbPagePhp($entryCount, $pageSize, $page);
    }

    /**
     * @return array{key: string, label: string, log_prefix: string, valid_page: bool, page_count: int, past_end: bool, offset: int, length: int, entries: int}
     */
    private static function planThreeDsDbPageNative(int $entryCount, int $pageSize, int $page): array
    {
        $output = self::callThreeDsDbNative([self::THREE_DS_DB_NATIVE_SOURCE, $entryCount, $pageSize, $page], 128);
        $fields = $output === null ? [] : explode(self::THREE_DS_DB_FIELD_SEPARATOR, $output);
        if (count($fields) !== 9) {
            return self::planThreeDsDbPagePhp($entryCount, $pageSize, $page);
        }

        return [
            'key' => $fields[0],
            'label' => $fields[1],
            'log_prefix' => $fields[2],
            'valid_page' => $fields[3] === '1',
            'page_count' => (int) $fields[4],
            'past_end' => $fields[5] === '1',
            'offset' => (int) $fields[6],
            'length' => (int) $fields[7],
            'entries' => (int) $fields[8],
        ];
    }

    /**
     * @return array{key: string, label: string, log_prefix: string, valid_page: bool, page_count: int, past_end: bool, offset: int, length: int, entries: int}
     */
    private static function planThreeDsDbPagePhp(int $entryCount, int $pageSize, int $page): array
    {
        $entries = max(0, min($entryCount, self::THREE_DS_DB_MAX_RECORDS));
        $size = max(1, $pageSize);
        // Equivalent to ceil($entries / $size) without overflowing for huge page sizes.
        $pageCount = $entries === 0 ? 0 : intdiv($entries - 1, $size) + 1;
        $valid = $page >= 1;
        $pastEnd = $valid && $page > $pageCount;
        $offset = $valid && ! $pastEnd ? ($page - 1) * $size : 0;

        return [
            'key' => '3dsdb',
            'label' => '3dsdb.com',
            'log_prefix' => strtoupper('3dsdb'),
            'valid_page' => $valid,
            'page_count' => $pageCount,
            'past_end' => $pastEnd,
            'offset' => $offset,
            'length' => $valid && ! $pastEnd ? min($size, $entries - $offset) : 0,
            'entries' => $entries,
        ];
    }

    /**
     * @param  list<int>  $args
     */
    private static function callThreeDsDbNative(array $args, int $hint): ?string
    {
        self::$threeDsDbFfi ??= RustPipelineLibrary::resolveDomain('metadata_w384', self::THREE_DS_DB_FFI_CDEF) ?? false;
        if (self::$threeDsDbFfi === false) {
            return null;
        }
        $ffi = self::$threeDsDbFfi;

        try {
            $capacity = 0;
            $buffer = RustPipelineLibrary::obtainScratch($ffi, $hint, $capacity);
            if ($buffer === null) {
                return null;
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_w384_catalogue_page_plan(...[...$args, $buffer, $capacity]);
            if (is_int($written) && $written < -1) {
                $buffer = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($buffer === null) {
                    return null;
                }
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_w384_catalogue_page_plan(...[...$args, $buffer, $capacity]);
            }
            if (! is_int($written) || $written < 0 || $written > $capacity) {
                return null;
            }

            return FFI::string($buffer, $written);
        } catch (Throwable) {
            return null;
        }
    }
}

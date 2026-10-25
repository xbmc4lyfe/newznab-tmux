<?php

declare(strict_types=1);

namespace App\Services\IGDB\Models;

use App\Support\RustPipelineLibrary;
use FFI;
use Throwable;

class Game extends BaseModel
{
    protected const string ENDPOINT = 'games';

    public const string LOOKUP_FFI_CDEF = <<<'C'
        ssize_t nntmux_w35_igdb_game_lookup_spec(
            int64_t limit,
            uint32_t has_platform,
            int64_t platform_id,
            const char *title_ptr,
            size_t title_len,
            char *out_buf,
            size_t out_cap
        );
        C;

    /**
     * Production default stays false: the paired benchmark in
     * tests/Unit/RustIgdbBookTvProviderMetadataParityTest.php is below 1.5x.
     */
    private const bool RUST_FFI_DEFAULT = false;

    private const string WHITESPACE = " \t\n\r\0\x0B\x0C";

    private const int LIMIT_MIN = 1;

    private const int LIMIT_MAX = 500;

    private const string FIELD_SEPARATOR = "\x1F";

    private const string NULL_MARK = "\x1E";

    private static ?bool $useRustFfi = null;

    private static FFI|false|null $ffi = null;

    /**
     * Toggles only the helper below; BaseModel keeps its own endpoint/key toggle.
     */
    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi ?? self::RUST_FFI_DEFAULT;
    }

    public static function setUseRustFfi(?bool $use = true): void
    {
        self::$useRustFfi = $use;
    }

    /**
     * Builds an IGDB game search: the title is trimmed with whitespace runs collapsed, quotes and
     * backslashes are escaped for the Apicalypse `search` clause, the limit is clamped to 1-500 and
     * a non-positive platform id is dropped.
     *
     * @return array{search: string, escaped: string, limit: int, platform_id: ?int, query: string, is_valid: bool}
     */
    public static function normalizeGameLookupSpec(string $searchTitle, int $limit = 10, ?int $platformId = null): array
    {
        return self::usesRustFfi()
            ? self::normalizeGameLookupSpecNative($searchTitle, $limit, $platformId)
            : self::normalizeGameLookupSpecPhp($searchTitle, $limit, $platformId);
    }

    /**
     * @return array{search: string, escaped: string, limit: int, platform_id: ?int, query: string, is_valid: bool}
     */
    public static function normalizeGameLookupSpecNative(string $searchTitle, int $limit = 10, ?int $platformId = null): array
    {
        $fields = self::callNative('nntmux_w35_igdb_game_lookup_spec', [$limit, $platformId === null ? 0 : 1, $platformId ?? 0, $searchTitle, strlen($searchTitle)]);
        if ($fields === null || count($fields) !== 6 || $fields[0] === null || $fields[1] === null || $fields[2] === null || $fields[4] === null || $fields[5] === null) {
            return self::normalizeGameLookupSpecPhp($searchTitle, $limit, $platformId);
        }

        return [
            'search' => $fields[0],
            'escaped' => $fields[1],
            'limit' => (int) $fields[2],
            'platform_id' => $fields[3] === null ? null : (int) $fields[3],
            'query' => $fields[4],
            'is_valid' => $fields[5] === '1',
        ];
    }

    /**
     * @return array{search: string, escaped: string, limit: int, platform_id: ?int, query: string, is_valid: bool}
     */
    public static function normalizeGameLookupSpecPhp(string $searchTitle, int $limit = 10, ?int $platformId = null): array
    {
        $search = preg_replace('/[ \t\n\r\0\x0B\x0C]+/', ' ', trim($searchTitle, self::WHITESPACE)) ?? '';
        $escaped = addcslashes($search, '"\\');
        $limit = max(self::LIMIT_MIN, min(self::LIMIT_MAX, $limit));
        $platformId = $platformId !== null && $platformId > 0 ? $platformId : null;
        $where = $platformId === null ? '' : "where platforms = ({$platformId}); ";

        return [
            'search' => $search,
            'escaped' => $escaped,
            'limit' => $limit,
            'platform_id' => $platformId,
            'query' => "search \"{$escaped}\"; fields *; {$where}limit {$limit};",
            'is_valid' => $search !== '',
        ];
    }

    /**
     * @param  list<int|string>  $args
     * @return list<?string>|null
     */
    private static function callNative(string $symbol, array $args): ?array
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return null;
        }

        try {
            $capacity = 0;
            $buffer = RustPipelineLibrary::obtainScratch($ffi, 1024, $capacity);
            if ($buffer === null) {
                return null;
            }
            $written = $ffi->$symbol(...[...$args, $buffer, $capacity]);
            if (is_int($written) && $written < -1) {
                $buffer = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($buffer === null) {
                    return null;
                }
                $written = $ffi->$symbol(...[...$args, $buffer, $capacity]);
            }
            if (! is_int($written) || $written < 0 || $written > $capacity) {
                return null;
            }

            return array_map(
                static fn (string $field): ?string => $field === self::NULL_MARK ? null : $field,
                explode(self::FIELD_SEPARATOR, FFI::string($buffer, $written)),
            );
        } catch (Throwable) {
            return null;
        }
    }

    private static function ffi(): ?FFI
    {
        if (self::$ffi === null) {
            self::$ffi = RustPipelineLibrary::resolveDomain('metadata', self::LOOKUP_FFI_CDEF) ?? false;
        }

        return self::$ffi === false ? null : self::$ffi;
    }
}

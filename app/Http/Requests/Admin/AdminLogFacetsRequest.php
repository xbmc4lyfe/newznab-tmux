<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Providers\SearchServiceProvider;
use App\Services\LogViewer\LogEntryParser;

/**
 * Validates level / channel facet requests: the same term and filters as a search, none of them required.
 */
class AdminLogFacetsRequest extends AdminLogSearchRequest
{
    private const int CHANNEL_MAX_BYTES = 64;

    /**
     * Rust stays opt-in: the FFI round trip is slower than the PHP helper (< 1.5x); see
     * tests/Unit/RustSearchProviderFacadeFacetsParityTest.php. This toggle is separate from the
     * inherited boundary-shape toggle.
     */
    private const bool RUST_FFI_DEFAULT = false;

    private static ?bool $useRustFfi = null;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return $this->filterRules();
    }

    public static function setUseRustFfi(?bool $use): void
    {
        self::$useRustFfi = $use;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi ?? self::RUST_FFI_DEFAULT;
    }

    /**
     * One facet filter: a trimmed term (null when blank) and whether it is free of control bytes, a known
     * lowercase level, and a trimmed channel matching the channel rule (unknown values become null).
     *
     * @return array{term: ?string, term_valid: bool, level: ?string, channel: ?string}
     */
    public static function normalizeFacetFilterInput(?string $term, ?string $level, ?string $channel): array
    {
        return self::usesRustFfi() ? self::normalizeFacetFilterInputNative($term, $level, $channel) : self::normalizeFacetFilterInputPhp($term, $level, $channel);
    }

    /**
     * @return array{term: ?string, term_valid: bool, level: ?string, channel: ?string}
     */
    public static function normalizeFacetFilterInputPhp(?string $term, ?string $level, ?string $channel): array
    {
        $term = $term === null ? null : trim($term);
        if ($term === '') {
            $term = null;
        }
        $level = $level === null ? null : strtolower(trim($level));
        if ($level !== null && ! \in_array($level, LogEntryParser::LEVELS, true)) {
            $level = null;
        }
        $channel = $channel === null ? null : trim($channel);
        if ($channel !== null && (\strlen($channel) > self::CHANNEL_MAX_BYTES || preg_match('/^[\w.\-]+$/', $channel) !== 1)) {
            $channel = null;
        }

        return [
            'term' => $term,
            'term_valid' => $term === null || preg_match('/[\x00-\x1F\x7F]/', $term) !== 1,
            'level' => $level,
            'channel' => $channel,
        ];
    }

    /**
     * Nullable strings cross as a `0`/`1` presence flag followed by their bytes.
     *
     * @return array{term: ?string, term_valid: bool, level: ?string, channel: ?string}
     */
    public static function normalizeFacetFilterInputNative(?string $term, ?string $level, ?string $channel): array
    {
        $result = SearchServiceProvider::evaluateNative(5, [
            $term === null ? '0' : '1', $term ?? '',
            $level === null ? '0' : '1', $level ?? '',
            $channel === null ? '0' : '1', $channel ?? '',
        ]);
        if ($result !== null && \count($result) === 7 && \in_array($result[2], ['0', '1'], true)) {
            $nativeTerm = self::nativeNullable($result[0], $result[1]);
            $nativeLevel = self::nativeNullable($result[3], $result[4]);
            $nativeChannel = self::nativeNullable($result[5], $result[6]);
            if ($nativeTerm !== false && $nativeLevel !== false && $nativeChannel !== false
                && ($nativeLevel === null || \in_array($nativeLevel, LogEntryParser::LEVELS, true))) {
                return ['term' => $nativeTerm, 'term_valid' => $result[2] === '1', 'level' => $nativeLevel, 'channel' => $nativeChannel];
            }
        }

        return self::normalizeFacetFilterInputPhp($term, $level, $channel);
    }

    /** The decoded nullable string, or false when the flag/value pair is malformed. */
    private static function nativeNullable(string $flag, string $value): string|false|null
    {
        return match (true) {
            $flag === '1' && $value !== '' => $value,
            $flag === '0' && $value === '' => null,
            default => false,
        };
    }
}

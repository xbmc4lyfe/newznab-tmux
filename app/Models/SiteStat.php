<?php

declare(strict_types=1);

namespace App\Models;

use App\Providers\SearchServiceProvider;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SiteStat extends Model
{
    use HasFactory; // @phpstan-ignore missingType.generics

    private const int STAT_KEY_MAX_BYTES = 64;

    /**
     * Rust stays opt-in: the FFI round trip is slower than the PHP helper (< 1.5x); see
     * tests/Unit/RustSearchProviderFacadeFacetsParityTest.php.
     */
    private const bool RUST_FFI_DEFAULT = false;

    private static ?bool $useRustFfi = null;

    protected $guarded = [];

    public static function setUseRustFfi(?bool $use): void
    {
        self::$useRustFfi = $use;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi ?? self::RUST_FFI_DEFAULT;
    }

    /**
     * Snake-case stat key (at most 64 bytes, no edge underscores) and a finite value: non-finite floats
     * become 0.0 and -0.0 becomes 0.0; integers pass through.
     *
     * @return array{key: string, value: int|float, valid: bool}
     */
    public static function normalizeStatEntry(string $statKey, int|float $statValue): array
    {
        return self::usesRustFfi() ? self::normalizeStatEntryNative($statKey, $statValue) : self::normalizeStatEntryPhp($statKey, $statValue);
    }

    /**
     * @return array{key: string, value: int|float, valid: bool}
     */
    public static function normalizeStatEntryPhp(string $statKey, int|float $statValue): array
    {
        $key = preg_replace('/[^a-z0-9_]+/', '_', strtolower(trim($statKey))) ?? '';
        $key = trim(substr($key, 0, self::STAT_KEY_MAX_BYTES), '_');
        if (\is_float($statValue)) {
            $statValue = is_finite($statValue) ? $statValue + 0.0 : 0.0;
        }

        return ['key' => $key, 'value' => $statValue, 'valid' => $key !== ''];
    }

    /**
     * Integers cross as canonical decimals, floats as their exact little-endian IEEE 754 bytes.
     *
     * @return array{key: string, value: int|float, valid: bool}
     */
    public static function normalizeStatEntryNative(string $statKey, int|float $statValue): array
    {
        $tag = \is_float($statValue) ? 'f' : 'i';
        $result = SearchServiceProvider::evaluateNative(4, [$statKey, $tag, $tag === 'f' ? pack('e', $statValue) : (string) $statValue]);
        if ($result !== null && \count($result) === 4 && $result[1] === ($result[0] !== '' ? '1' : '0') && $result[2] === $tag) {
            if ($tag === 'i' && preg_match('/^-?(0|[1-9][0-9]*)$/', $result[3]) === 1 && $result[3] !== '-0') {
                return ['key' => $result[0], 'value' => (int) $result[3], 'valid' => $result[1] === '1'];
            }
            $float = $tag === 'f' && \strlen($result[3]) === 8 ? unpack('e', $result[3]) : false;
            if (\is_array($float) && \is_float($float[1]) && is_finite($float[1])) {
                return ['key' => $result[0], 'value' => $float[1], 'valid' => $result[1] === '1'];
            }
        }

        return self::normalizeStatEntryPhp($statKey, $statValue);
    }
}

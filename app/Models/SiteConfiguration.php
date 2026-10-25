<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\IsSingletonConfiguration;
use App\Services\Search\DTO\SearchCursor;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $title
 * @property string $home_link
 * @property string|null $site_logo
 * @property string $strapline
 * @property string $meta_title
 * @property string $meta_description
 * @property string $meta_keywords
 * @property string $footer
 * @property string $dereferrer_link
 * @property string $terms
 * @property bool $trailers_display
 * @property int $trailers_size_x
 * @property int $trailers_size_y
 */
final class SiteConfiguration extends Model
{
    use IsSingletonConfiguration;

    /** Column defaults for trailers_size_x and trailers_size_y. */
    public const int DEFAULT_TRAILER_WIDTH = 480;

    public const int DEFAULT_TRAILER_HEIGHT = 345;

    /** Upper bound of the unsigned small integer trailer columns. */
    public const int MAX_TRAILER_DIMENSION = 65535;

    public const string DEFAULT_HOME_LINK = '/';

    /**
     * Rust stays opt-in: the FFI round trip is slower than the PHP helpers (< 1.5x); see
     * tests/Unit/RustSearchCursorConfigStatsParityTest.php.
     */
    private const bool RUST_FFI_DEFAULT = false;

    private static ?bool $useRustFfi = null;

    protected $guarded = ['id'];

    public static function setUseRustFfi(?bool $use): void
    {
        self::$useRustFfi = $use;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi ?? self::RUST_FFI_DEFAULT;
    }

    /** @return array{width: int, height: int} */
    public function trailerDimensions(): array
    {
        return self::normalizeTrailerDimensions((int) $this->trailers_size_x, (int) $this->trailers_size_y);
    }

    /**
     * A non-positive dimension falls back to its column default; larger values clamp to the column range.
     *
     * @return array{width: int, height: int}
     */
    public static function normalizeTrailerDimensions(int $width, int $height): array
    {
        return self::usesRustFfi() ? self::normalizeTrailerDimensionsNative($width, $height) : self::normalizeTrailerDimensionsPhp($width, $height);
    }

    /** @return array{width: int, height: int} */
    public static function normalizeTrailerDimensionsPhp(int $width, int $height): array
    {
        return [
            'width' => $width <= 0 ? self::DEFAULT_TRAILER_WIDTH : min($width, self::MAX_TRAILER_DIMENSION),
            'height' => $height <= 0 ? self::DEFAULT_TRAILER_HEIGHT : min($height, self::MAX_TRAILER_DIMENSION),
        ];
    }

    /** @return array{width: int, height: int} */
    public static function normalizeTrailerDimensionsNative(int $width, int $height): array
    {
        $result = SearchCursor::evaluateNative(5, [(string) $width, (string) $height]);
        if ($result !== null && \count($result) === 2) {
            [$normalizedWidth, $normalizedHeight] = array_map(intval(...), $result);
            if ([(string) $normalizedWidth, (string) $normalizedHeight] === $result
                && $normalizedWidth >= 1 && $normalizedWidth <= self::MAX_TRAILER_DIMENSION
                && $normalizedHeight >= 1 && $normalizedHeight <= self::MAX_TRAILER_DIMENSION) {
                return ['width' => $normalizedWidth, 'height' => $normalizedHeight];
            }
        }

        return self::normalizeTrailerDimensionsPhp($width, $height);
    }

    /** The trimmed home link; a blank link falls back to the site root. */
    public static function normalizeHomeLink(string $link): string
    {
        return self::usesRustFfi() ? self::normalizeHomeLinkNative($link) : self::normalizeHomeLinkPhp($link);
    }

    public static function normalizeHomeLinkPhp(string $link): string
    {
        $trimmed = trim($link);

        return $trimmed === '' ? self::DEFAULT_HOME_LINK : $trimmed;
    }

    public static function normalizeHomeLinkNative(string $link): string
    {
        $result = SearchCursor::evaluateNative(6, [$link]);

        return $result !== null && \count($result) === 1 && $result[0] !== '' && \strlen($result[0]) <= \strlen($link) + 1
            ? $result[0]
            : self::normalizeHomeLinkPhp($link);
    }

    protected function casts(): array
    {
        return [
            'trailers_display' => 'boolean',
            'trailers_size_x' => 'integer',
            'trailers_size_y' => 'integer',
        ];
    }
}

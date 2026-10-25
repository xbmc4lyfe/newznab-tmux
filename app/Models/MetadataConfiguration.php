<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\LookupMode;
use App\Models\Concerns\IsSingletonConfiguration;
use App\Services\Search\DTO\SearchCursor;
use Illuminate\Database\Eloquent\Model;

/**
 * @property LookupMode $anime_lookup
 * @property LookupMode $book_lookup
 * @property LookupMode $game_lookup
 * @property LookupMode $movie_lookup
 * @property LookupMode $music_lookup
 * @property LookupMode $tv_lookup
 * @property string $movie_language
 * @property bool $imdb_alternate_url
 * @property int $max_anime_processed
 * @property int $max_books_processed
 * @property int $max_games_processed
 * @property int $max_movies_processed
 * @property int $max_music_processed
 * @property int $max_tv_processed
 * @property int $amazon_sleep_milliseconds
 */
final class MetadataConfiguration extends Model
{
    use IsSingletonConfiguration;

    public const string DEFAULT_MOVIE_LANGUAGE = 'en';

    /** Width of the movie_language column. */
    public const int MOVIE_LANGUAGE_MAX_BYTES = 8;

    /** The lookup mode columns, in the order summarizeLookupModes() takes them. */
    public const array LOOKUP_COLUMNS = ['anime_lookup', 'book_lookup', 'game_lookup', 'movie_lookup', 'music_lookup', 'tv_lookup'];

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

    /**
     * How many metadata lookups this configuration enables.
     *
     * @return array{enabled: int, renamed_only: int, disabled: int, invalid: int}
     */
    public function lookupSummary(): array
    {
        $modes = array_map(function (string $column): int {
            $mode = $this->getAttribute($column);

            return $mode instanceof LookupMode ? $mode->value : (int) $mode;
        }, self::LOOKUP_COLUMNS);

        return self::summarizeLookupModes(...$modes);
    }

    /**
     * Counts lookup modes: All and Renamed are enabled (Renamed is also renamed_only), Disabled is disabled
     * and any other value is invalid.
     *
     * @return array{enabled: int, renamed_only: int, disabled: int, invalid: int}
     */
    public static function summarizeLookupModes(int $anime, int $book, int $game, int $movie, int $music, int $tv): array
    {
        return self::usesRustFfi()
            ? self::summarizeLookupModesNative($anime, $book, $game, $movie, $music, $tv)
            : self::summarizeLookupModesPhp($anime, $book, $game, $movie, $music, $tv);
    }

    /** @return array{enabled: int, renamed_only: int, disabled: int, invalid: int} */
    public static function summarizeLookupModesPhp(int $anime, int $book, int $game, int $movie, int $music, int $tv): array
    {
        $summary = ['enabled' => 0, 'renamed_only' => 0, 'disabled' => 0, 'invalid' => 0];
        foreach ([$anime, $book, $game, $movie, $music, $tv] as $mode) {
            match (LookupMode::tryFrom($mode)) {
                LookupMode::Disabled => $summary['disabled']++,
                LookupMode::All => $summary['enabled']++,
                LookupMode::Renamed => [$summary['enabled']++, $summary['renamed_only']++],
                null => $summary['invalid']++,
            };
        }

        return $summary;
    }

    /** @return array{enabled: int, renamed_only: int, disabled: int, invalid: int} */
    public static function summarizeLookupModesNative(int $anime, int $book, int $game, int $movie, int $music, int $tv): array
    {
        $result = SearchCursor::evaluateNative(3, array_map(strval(...), [$anime, $book, $game, $movie, $music, $tv]));
        if ($result !== null && \count($result) === 4) {
            $counts = array_map(intval(...), $result);
            if (array_map(strval(...), $counts) === $result && min($counts) >= 0 && $counts[0] + $counts[2] + $counts[3] === 6 && $counts[1] <= $counts[0]) {
                return ['enabled' => $counts[0], 'renamed_only' => $counts[1], 'disabled' => $counts[2], 'invalid' => $counts[3]];
            }
        }

        return self::summarizeLookupModesPhp($anime, $book, $game, $movie, $music, $tv);
    }

    /**
     * Trimmed, lowercase (ASCII) language code; an empty, over-long or non [a-z0-9-] value becomes the
     * default language.
     */
    public static function normalizeMovieLanguage(string $language): string
    {
        return self::usesRustFfi() ? self::normalizeMovieLanguageNative($language) : self::normalizeMovieLanguagePhp($language);
    }

    public static function normalizeMovieLanguagePhp(string $language): string
    {
        $normalized = strtolower(trim($language));

        return $normalized !== '' && \strlen($normalized) <= self::MOVIE_LANGUAGE_MAX_BYTES && preg_match('/^[a-z0-9-]+$/D', $normalized) === 1
            ? $normalized
            : self::DEFAULT_MOVIE_LANGUAGE;
    }

    public static function normalizeMovieLanguageNative(string $language): string
    {
        $result = SearchCursor::evaluateNative(4, [$language]);

        return $result !== null && \count($result) === 1 && $result[0] !== '' && \strlen($result[0]) <= self::MOVIE_LANGUAGE_MAX_BYTES
            ? $result[0]
            : self::normalizeMovieLanguagePhp($language);
    }

    protected function casts(): array
    {
        return [
            'anime_lookup' => LookupMode::class,
            'book_lookup' => LookupMode::class,
            'game_lookup' => LookupMode::class,
            'movie_lookup' => LookupMode::class,
            'music_lookup' => LookupMode::class,
            'tv_lookup' => LookupMode::class,
            'imdb_alternate_url' => 'boolean',
        ];
    }
}

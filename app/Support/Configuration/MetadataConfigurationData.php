<?php

declare(strict_types=1);

namespace App\Support\Configuration;

use App\Enums\LookupMode;
use App\Models\MetadataConfiguration;
use App\Support\RustPipelineLibrary;
use FFI;
use Throwable;

final readonly class MetadataConfigurationData
{
    public const string FFI_CDEF = <<<'C'
        ssize_t nntmux_metadata_config_defaults(int64_t *out_ints, size_t ints_cap, char *out_lang, size_t lang_cap);
        ssize_t nntmux_metadata_config_to_array(int64_t anime_lookup, int64_t book_lookup, int64_t game_lookup, int64_t movie_lookup, int64_t music_lookup, int64_t tv_lookup, const char *lang_ptr, size_t lang_len, uint32_t imdb_alt_url, int64_t max_anime, int64_t max_books, int64_t max_games, int64_t max_movies, int64_t max_music, int64_t max_tv, int64_t amazon_sleep_ms, char *out_buf, size_t out_cap);
        C;

    public static function setUseRustFfi(?bool $use = true): void
    {
        self::toggleState($use, true);
        if ($use === null) {
            self::ffi(true);
        }
    }

    public static function usesRustFfi(): bool
    {
        return self::toggleState() ?? false;
    }

    public static function setFfiForTesting(FFI|false|null $override = null): void
    {
        self::ffi(true, $override);
    }

    private static function toggleState(?bool $next = null, bool $write = false): ?bool
    {
        static $useRustFfi = null;
        if ($write) {
            $useRustFfi = $next;
        }

        return $useRustFfi;
    }

    private static function ffi(bool $reset = false, FFI|false|null $override = null): ?FFI
    {
        static $ffi = null;
        if ($reset) {
            $ffi = $override;
            if ($override !== null) {
                return $ffi === false ? null : $ffi;
            }
        }
        if ($ffi === null) {
            $ffi = RustPipelineLibrary::resolveDomain('metadata', self::FFI_CDEF) ?? false;
        }

        return $ffi === false ? null : $ffi;
    }

    public function __construct(
        public LookupMode $animeLookup,
        public LookupMode $bookLookup,
        public LookupMode $gameLookup,
        public LookupMode $movieLookup,
        public LookupMode $musicLookup,
        public LookupMode $tvLookup,
        public string $movieLanguage,
        public bool $imdbAlternateUrl,
        public int $maxAnimeProcessed,
        public int $maxBooksProcessed,
        public int $maxGamesProcessed,
        public int $maxMoviesProcessed,
        public int $maxMusicProcessed,
        public int $maxTvProcessed,
        public int $amazonSleepMilliseconds,
    ) {}

    public static function defaults(): self
    {
        return self::usesRustFfi()
            ? self::defaultsNative()
            : self::defaultsPhp();
    }

    public static function defaultsNative(): self
    {
        if (($ffi = self::ffi()) === null) {
            return self::defaultsPhp();
        }
        $capacity = 0;
        $buffer = RustPipelineLibrary::obtainScratch($ffi, 32, $capacity);
        if ($buffer === null) {
            return self::defaultsPhp();
        }
        try {
            $outInts = $ffi->new('int64_t[14]');
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_metadata_config_defaults($outInts, 14, $buffer, $capacity);
            if (\is_int($written) && $written < -1) {
                $buffer = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($buffer === null) {
                    return self::defaultsPhp();
                }
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_metadata_config_defaults($outInts, 14, $buffer, $capacity);
            }
            if (! \is_int($written) || $written <= 0 || $written > $capacity) {
                return self::defaultsPhp();
            }
            $anime = LookupMode::tryFrom((int) $outInts[0]);
            $book = LookupMode::tryFrom((int) $outInts[1]);
            $game = LookupMode::tryFrom((int) $outInts[2]);
            $movie = LookupMode::tryFrom((int) $outInts[3]);
            $music = LookupMode::tryFrom((int) $outInts[4]);
            $tv = LookupMode::tryFrom((int) $outInts[5]);
            if ($anime === null || $book === null || $game === null || $movie === null || $music === null || $tv === null) {
                return self::defaultsPhp();
            }

            return new self(
                $anime,
                $book,
                $game,
                $movie,
                $music,
                $tv,
                FFI::string($buffer, $written),
                (int) $outInts[6] !== 0,
                (int) $outInts[7],
                (int) $outInts[8],
                (int) $outInts[9],
                (int) $outInts[10],
                (int) $outInts[11],
                (int) $outInts[12],
                (int) $outInts[13],
            );
        } catch (Throwable) {
            return self::defaultsPhp();
        }
    }

    public static function defaultsPhp(): self
    {
        return new self(LookupMode::Disabled, LookupMode::All, LookupMode::All, LookupMode::All, LookupMode::All, LookupMode::All, 'en', false, 100, 300, 150, 100, 150, 75, 1000);
    }

    public static function fromModel(MetadataConfiguration $model): self
    {
        return new self(
            $model->anime_lookup,
            $model->book_lookup,
            $model->game_lookup,
            $model->movie_lookup,
            $model->music_lookup,
            $model->tv_lookup,
            (string) $model->movie_language,
            (bool) $model->imdb_alternate_url,
            (int) $model->max_anime_processed,
            (int) $model->max_books_processed,
            (int) $model->max_games_processed,
            (int) $model->max_movies_processed,
            (int) $model->max_music_processed,
            (int) $model->max_tv_processed,
            (int) $model->amazon_sleep_milliseconds,
        );
    }

    /** @return array<string, int|bool|string|null> */
    public function toArray(): array
    {
        return self::usesRustFfi()
            ? $this->toArrayNative()
            : $this->toArrayPhp();
    }

    /** @return array<string, int|bool|string|null> */
    public function toArrayNative(): array
    {
        if (($ffi = self::ffi()) === null) {
            return $this->toArrayPhp();
        }
        $langLen = \strlen($this->movieLanguage);
        $capacity = 0;
        $buffer = RustPipelineLibrary::obtainScratch($ffi, max(512, $langLen + 512), $capacity);
        if ($buffer === null) {
            return $this->toArrayPhp();
        }
        try {
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_metadata_config_to_array(
                $this->animeLookup->value,
                $this->bookLookup->value,
                $this->gameLookup->value,
                $this->movieLookup->value,
                $this->musicLookup->value,
                $this->tvLookup->value,
                $this->movieLanguage,
                $langLen,
                $this->imdbAlternateUrl ? 1 : 0,
                $this->maxAnimeProcessed,
                $this->maxBooksProcessed,
                $this->maxGamesProcessed,
                $this->maxMoviesProcessed,
                $this->maxMusicProcessed,
                $this->maxTvProcessed,
                $this->amazonSleepMilliseconds,
                $buffer,
                $capacity,
            );
            if (\is_int($written) && $written < -1) {
                $buffer = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($buffer === null) {
                    return $this->toArrayPhp();
                }
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_metadata_config_to_array(
                    $this->animeLookup->value,
                    $this->bookLookup->value,
                    $this->gameLookup->value,
                    $this->movieLookup->value,
                    $this->musicLookup->value,
                    $this->tvLookup->value,
                    $this->movieLanguage,
                    $langLen,
                    $this->imdbAlternateUrl ? 1 : 0,
                    $this->maxAnimeProcessed,
                    $this->maxBooksProcessed,
                    $this->maxGamesProcessed,
                    $this->maxMoviesProcessed,
                    $this->maxMusicProcessed,
                    $this->maxTvProcessed,
                    $this->amazonSleepMilliseconds,
                    $buffer,
                    $capacity,
                );
            }
            if (! \is_int($written) || $written <= 0 || $written > $capacity) {
                return $this->toArrayPhp();
            }
            $decoded = unserialize(FFI::string($buffer, $written), ['allowed_classes' => false]);

            /** @var array<string, int|bool|string|null> $result */
            $result = \is_array($decoded) ? $decoded : $this->toArrayPhp();

            return $result;
        } catch (Throwable) {
            return $this->toArrayPhp();
        }
    }

    /** @return array<string, int|bool|string|null> */
    public function toArrayPhp(): array
    {
        $values = get_object_vars($this);
        foreach (['animeLookup', 'bookLookup', 'gameLookup', 'movieLookup', 'musicLookup', 'tvLookup'] as $key) {
            $values[$key] = $values[$key]->value;
        }

        return $values;
    }
}

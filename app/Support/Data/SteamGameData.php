<?php

declare(strict_types=1);

namespace App\Support\Data;

use App\Support\Data\Factories\SteamGameDataFactory;
use App\Support\RustPipelineLibrary;
use FFI;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Throwable;

/**
 * Data Transfer Object for Steam Game data.
 *
 * Provides a type-safe representation of game data retrieved from the Steam API.
 * Reshaping/parsing logic lives in {@see SteamGameDataFactory}.
 */
#[TypeScript]
final class SteamGameData extends Data
{
    public const string FFI_CDEF = <<<'C'
        ssize_t nntmux_steam_game_data_transform_json(int32_t mode, const char *json, size_t len, char *out, size_t cap);
        C;

    private const int JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR;

    private const array MULTIPLAYER_CATEGORIES = ['Multi-player', 'Online Multi-Player', 'Online Co-op', 'Local Multi-Player', 'Local Co-op'];

    /** Null selects the benchmark-qualified production default (PHP; see RustTvShowAdderAndMetadataParityTest). */
    private static ?bool $useRustFfi = null;

    private static FFI|false|null $ffi = null;

    public static function setUseRustFfi(?bool $use = true): void
    {
        self::$useRustFfi = $use;
        if ($use === null) {
            self::$ffi = null;
        }
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi ?? false;
    }

    private static function ffi(): ?FFI
    {
        if (self::$ffi === null) {
            self::$ffi = RustPipelineLibrary::resolveDomain('metadata', self::FFI_CDEF) ?? false;
        }

        return self::$ffi === false ? null : self::$ffi;
    }

    /**
     * @param  array<int, array{thumbnail: ?string, full: ?string}>  $screenshots
     * @param  array<int, array{id: ?int, name: ?string, thumbnail: ?string, webm: ?string, mp4: ?string}>  $movies
     * @param  array<int, string>  $developers
     * @param  array<int, string>  $genres
     * @param  array<int, string>  $categories
     * @param  array<int, string>  $platforms
     * @param  array<string, array{minimum: ?string, recommended: ?string}>  $requirements
     * @param  array<int, int>  $dlcIds
     */
    public function __construct(
        public int $steamId,
        public string $title,
        public string $type = 'game',
        public ?string $description = null,
        public ?string $detailedDescription = null,
        public ?string $about = null,
        public ?string $coverUrl = null,
        public ?string $backdropUrl = null,
        public array $screenshots = [],
        public array $movies = [],
        public ?string $trailerUrl = null,
        public ?string $publisher = null,
        public array $developers = [],
        public ?string $releaseDate = null,
        public array $genres = [],
        public array $categories = [],
        public ?int $metacriticScore = null,
        public ?string $metacriticUrl = null,
        public ?SteamPriceData $price = null,
        public array $platforms = [],
        public array $requirements = [],
        public array $dlcIds = [],
        public ?int $achievementCount = null,
        public ?int $recommendationCount = null,
        public ?string $website = null,
        public ?string $supportUrl = null,
        public string $storeUrl = '',
    ) {}

    /**
     * Create from Steam API response array.
     *
     * Thin facade kept for backwards compatibility; delegates to
     * {@see SteamGameDataFactory::make()}.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromApiResponse(array $data, int $appId): self
    {
        return (new SteamGameDataFactory)->make($data, $appId);
    }

    /**
     * Convert to array format compatible with existing GamesInfo model.
     *
     * @return array<string, mixed>
     */
    public function toGamesInfoArray(): array
    {
        return self::usesRustFfi() ? $this->toGamesInfoArrayNative() : $this->toGamesInfoArrayPhp();
    }

    /**
     * @return array<string, mixed>
     */
    public function toGamesInfoArrayNative(): array
    {
        try {
            $payload = json_encode([
                'title' => $this->title,
                'steamId' => $this->steamId,
                'storeUrl' => $this->storeUrl,
                'publisher' => $this->publisher,
                'releaseDate' => $this->releaseDate,
                'description' => $this->description,
                'coverUrl' => $this->coverUrl,
                'backdropUrl' => $this->backdropUrl,
                'trailerUrl' => $this->trailerUrl,
                'metacriticScore' => $this->metacriticScore,
                'genres' => $this->genres,
            ], self::JSON_FLAGS);
            $json = self::callNative(0, $payload);
            $decoded = $json !== null ? json_decode($json, true, 4, JSON_THROW_ON_ERROR) : null;
        } catch (Throwable) {
            $decoded = null;
        }

        return \is_array($decoded) ? $decoded : $this->toGamesInfoArrayPhp();
    }

    /**
     * @return array<string, mixed>
     */
    public function toGamesInfoArrayPhp(): array
    {
        return [
            'title' => $this->title,
            'asin' => (string) $this->steamId,
            'url' => $this->storeUrl,
            'publisher' => $this->publisher ?? 'Unknown',
            'releasedate' => $this->releaseDate,
            'review' => $this->description ?? 'No description available',
            'cover' => ! empty($this->coverUrl) ? 1 : 0,
            'backdrop' => ! empty($this->backdropUrl) ? 1 : 0,
            'trailer' => $this->trailerUrl ?? '',
            'classused' => 'Steam',
            'esrb' => $this->metacriticScore !== null ? (string) $this->metacriticScore : 'Not Rated',
            'coverurl' => $this->coverUrl,
            'backdropurl' => $this->backdropUrl,
            'genres' => implode(',', $this->genres),
        ];
    }

    /**
     * Check if this is a game (not DLC, video, etc.).
     */
    public function isGame(): bool
    {
        return in_array($this->type, ['game', 'demo'], true);
    }

    /**
     * Check if game is free.
     */
    public function isFree(): bool
    {
        return $this->price !== null && $this->price->final <= 0;
    }

    /**
     * Get primary genre.
     */
    public function getPrimaryGenre(): ?string
    {
        return $this->genres[0] ?? null;
    }

    /**
     * Check if game supports a platform.
     */
    public function supportsPlatform(string $platform): bool
    {
        return in_array($platform, $this->platforms, true);
    }

    /**
     * Check if game has multiplayer.
     */
    public function hasMultiplayer(): bool
    {
        return self::usesRustFfi() ? $this->hasMultiplayerNative() : $this->hasMultiplayerPhp();
    }

    public function hasMultiplayerNative(): bool
    {
        if (($ffi = self::ffi()) === null) {
            return $this->hasMultiplayerPhp();
        }
        try {
            $payload = json_encode($this->categories, self::JSON_FLAGS);
            // @phpstan-ignore method.notFound
            $result = $ffi->nntmux_steam_game_data_transform_json(1, $payload, \strlen($payload), null, 0);
        } catch (Throwable) {
            return $this->hasMultiplayerPhp();
        }

        return match ($result) {
            1 => true,
            0 => false,
            default => $this->hasMultiplayerPhp(),
        };
    }

    public function hasMultiplayerPhp(): bool
    {
        return ! empty(array_intersect(self::MULTIPLAYER_CATEGORIES, $this->categories));
    }

    /**
     * Check if game supports Steam Workshop.
     */
    public function hasSteamWorkshop(): bool
    {
        return in_array('Steam Workshop', $this->categories, true);
    }

    /**
     * Get formatted genres string.
     */
    public function getGenresString(): string
    {
        return self::usesRustFfi() ? $this->getGenresStringNative() : $this->getGenresStringPhp();
    }

    public function getGenresStringNative(): string
    {
        try {
            $joined = self::callNative(2, json_encode($this->genres, self::JSON_FLAGS));
        } catch (Throwable) {
            $joined = null;
        }

        return $joined ?? $this->getGenresStringPhp();
    }

    public function getGenresStringPhp(): string
    {
        return implode(', ', $this->genres);
    }

    private static function callNative(int $mode, string $payload): ?string
    {
        if (($ffi = self::ffi()) === null) {
            return null;
        }
        $len = \strlen($payload);
        $capacity = 0;
        $buffer = RustPipelineLibrary::obtainScratch($ffi, max(256, $len + 128), $capacity);
        if ($buffer === null) {
            return null;
        }
        try {
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_steam_game_data_transform_json($mode, $payload, $len, $buffer, $capacity);
            if (\is_int($written) && $written < -1) {
                $buffer = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($buffer === null) {
                    return null;
                }
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_steam_game_data_transform_json($mode, $payload, $len, $buffer, $capacity);
            }
            if (! \is_int($written) || $written < 0 || $written > $capacity) {
                return null;
            }

            return $written === 0 ? '' : FFI::string($buffer, $written);
        } catch (Throwable) {
            return null;
        }
    }
}

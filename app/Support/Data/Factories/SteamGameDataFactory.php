<?php

declare(strict_types=1);

namespace App\Support\Data\Factories;

use App\Support\Data\SteamGameData;
use App\Support\Data\SteamPriceData;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Factory for building {@see SteamGameData} from a raw Steam Store API payload.
 *
 * Extracted from the previous static `SteamGameData::fromApiResponse` so the
 * Data object stays a pure structure and reshaping logic lives separately.
 */
final class SteamGameDataFactory
{
    public const NORMALIZER_FFI_CDEF = <<<'C'
        ssize_t nntmux_steam_list_fields(uint32_t op, const char *input, size_t len, char *out, size_t cap);
        C;

    /** Null selects the benchmark-qualified production default. */
    public ?bool $useRustFfi = null;

    private static FFI|false|null $normalizerFfi = null;

    /**
     * Build a SteamGameData from a raw Steam API response array.
     *
     * @param  array<string, mixed>  $data
     */
    public function make(array $data, int $appId): SteamGameData
    {
        return new SteamGameData(
            steamId: $appId,
            title: (string) ($data['name'] ?? ''),
            type: (string) ($data['type'] ?? 'game'),
            description: $data['short_description'] ?? null,
            detailedDescription: $data['detailed_description'] ?? null,
            about: $data['about_the_game'] ?? null,
            coverUrl: $data['header_image'] ?? null,
            backdropUrl: $data['background'] ?? ($data['background_raw'] ?? null),
            screenshots: $this->parseScreenshots($data),
            movies: $this->parseMovies($data, $trailerUrl),
            trailerUrl: $trailerUrl,
            publisher: $this->parsePublisher($data),
            developers: $this->parseDevelopers($data),
            releaseDate: $this->parseReleaseDate($data),
            genres: $this->parseGenres($data),
            categories: $this->parseCategories($data),
            metacriticScore: $data['metacritic']['score'] ?? null,
            metacriticUrl: $data['metacritic']['url'] ?? null,
            price: $this->parsePrice($data),
            platforms: $this->parsePlatforms($data),
            requirements: $this->parseRequirements($data),
            dlcIds: $data['dlc'] ?? [],
            achievementCount: $data['achievements']['total'] ?? null,
            recommendationCount: $data['recommendations']['total'] ?? null,
            website: $data['website'] ?? null,
            supportUrl: $data['support_info']['url'] ?? null,
            storeUrl: 'https://store.steampowered.com/app/'.$appId,
        );
    }

    public static function normalizerFfi(): ?FFI
    {
        if (self::$normalizerFfi === null) {
            self::$normalizerFfi = RustPipelineLibrary::resolveDomain('metadata', self::NORMALIZER_FFI_CDEF) ?? false;
        }

        return self::$normalizerFfi === false ? null : self::$normalizerFfi;
    }

    /**
     * Rust reshaping of one Steam list; null when Rust declines or is unavailable.
     *
     * @param  array<mixed>  $list
     */
    public static function nativeListString(int $operation, array $list): ?string
    {
        if (($ffi = self::normalizerFfi()) === null) {
            return null;
        }
        try {
            $input = serialize($list);
            $capacity = 0;
            $buffer = RustPipelineLibrary::obtainScratch($ffi, strlen($input) + 64, $capacity);
            if ($buffer === null) {
                return null;
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_steam_list_fields($operation, $input, strlen($input), $buffer, $capacity);
            if (is_int($written) && $written < -1) {
                $buffer = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($buffer === null) {
                    return null;
                }
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_steam_list_fields($operation, $input, strlen($input), $buffer, $capacity);
            }

            return is_int($written) && $written >= 0 && $written <= $capacity ? FFI::string($buffer, $written) : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  array<mixed>  $list
     * @return list<string>|null
     */
    private static function nativeList(int $operation, array $list): ?array
    {
        $output = self::nativeListString($operation, $list);
        if ($output === null) {
            return null;
        }
        if ($output === '') {
            return [];
        }
        $parts = explode("\0", $output);

        return array_pop($parts) === '' ? $parts : null;
    }

    /** Decode a Rust `N`/`S`/`I`/`B` tagged scalar. */
    private static function typedValue(string $field): mixed
    {
        return match ($field[0] ?? '') {
            'S' => substr($field, 1),
            'I' => (int) substr($field, 1),
            'B' => $field === 'B1',
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<int, array{thumbnail: ?string, full: ?string}>
     */
    private function parseScreenshots(array $data): array
    {
        return ($this->useRustFfi ?? false) ? $this->parseScreenshotsNative($data) : $this->parseScreenshotsPhp($data);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<int, array{thumbnail: ?string, full: ?string}>
     */
    public function parseScreenshotsNative(array $data): array
    {
        if (empty($data['screenshots']) || ! is_array($data['screenshots'])) {
            return [];
        }
        $parts = self::nativeList(0, $data['screenshots']);
        if ($parts === null || count($parts) % 2 !== 0) {
            return $this->parseScreenshotsPhp($data);
        }
        $screenshots = [];
        foreach (array_chunk($parts, 2) as [$thumbnail, $full]) {
            $screenshots[] = ['thumbnail' => self::typedValue($thumbnail), 'full' => self::typedValue($full)];
        }

        return $screenshots;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<int, array{id: ?int, name: ?string, thumbnail: ?string, webm: ?string, mp4: ?string}>
     */
    private function parseMovies(array $data, ?string &$trailerUrl): array
    {
        return ($this->useRustFfi ?? false) ? $this->parseMoviesNative($data, $trailerUrl) : $this->parseMoviesPhp($data, $trailerUrl);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<int, array{id: ?int, name: ?string, thumbnail: ?string, webm: ?string, mp4: ?string}>
     */
    public function parseMoviesNative(array $data, ?string &$trailerUrl): array
    {
        $trailerUrl = null;
        if (empty($data['movies']) || ! is_array($data['movies'])) {
            return [];
        }
        $parts = self::nativeList(1, $data['movies']);
        if ($parts === null || count($parts) % 5 !== 1) {
            return $this->parseMoviesPhp($data, $trailerUrl);
        }
        $trailerUrl = self::typedValue(array_shift($parts));
        $movies = [];
        foreach (array_chunk($parts, 5) as [$id, $name, $thumbnail, $webm, $mp4]) {
            $movies[] = [
                'id' => self::typedValue($id),
                'name' => self::typedValue($name),
                'thumbnail' => self::typedValue($thumbnail),
                'webm' => self::typedValue($webm),
                'mp4' => self::typedValue($mp4),
            ];
        }

        return $movies;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<int, string>
     */
    private function parseGenres(array $data): array
    {
        return ($this->useRustFfi ?? false) ? $this->parseGenresNative($data) : $this->parseGenresPhp($data);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<int, string>
     */
    public function parseGenresNative(array $data): array
    {
        if (empty($data['genres']) || ! is_array($data['genres'])) {
            return [];
        }

        return self::nativeList(2, $data['genres']) ?? $this->parseGenresPhp($data);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<int, string>
     */
    private function parseCategories(array $data): array
    {
        return ($this->useRustFfi ?? false) ? $this->parseCategoriesNative($data) : $this->parseCategoriesPhp($data);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<int, string>
     */
    public function parseCategoriesNative(array $data): array
    {
        if (empty($data['categories']) || ! is_array($data['categories'])) {
            return [];
        }

        return self::nativeList(2, $data['categories']) ?? $this->parseCategoriesPhp($data);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<int, string>
     */
    private function parsePlatforms(array $data): array
    {
        return ($this->useRustFfi ?? false) ? $this->parsePlatformsNative($data) : $this->parsePlatformsPhp($data);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<int, string>
     */
    public function parsePlatformsNative(array $data): array
    {
        if (empty($data['platforms']) || ! is_array($data['platforms'])) {
            return [];
        }

        return self::nativeList(3, $data['platforms']) ?? $this->parsePlatformsPhp($data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function parsePublisher(array $data): ?string
    {
        return ($this->useRustFfi ?? false) ? $this->parsePublisherNative($data) : $this->parsePublisherPhp($data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function parsePublisherNative(array $data): ?string
    {
        if (empty($data['publishers']) || ! is_array($data['publishers'])) {
            return null;
        }

        return self::nativeListString(4, $data['publishers']) ?? $this->parsePublisherPhp($data);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<int, string>
     */
    private function parseDevelopers(array $data): array
    {
        return ($this->useRustFfi ?? false) ? $this->parseDevelopersNative($data) : $this->parseDevelopersPhp($data);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<int, string>
     */
    public function parseDevelopersNative(array $data): array
    {
        if (empty($data['developers']) || ! is_array($data['developers'])) {
            return [];
        }

        return self::nativeList(5, $data['developers']) ?? $this->parseDevelopersPhp($data);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<int, array{thumbnail: ?string, full: ?string}>
     */
    public function parseScreenshotsPhp(array $data): array
    {
        $screenshots = [];
        if (! empty($data['screenshots']) && is_array($data['screenshots'])) {
            foreach ($data['screenshots'] as $ss) {
                $screenshots[] = [
                    'thumbnail' => $ss['path_thumbnail'] ?? null,
                    'full' => $ss['path_full'] ?? null,
                ];
            }
        }

        return $screenshots;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<int, array{id: ?int, name: ?string, thumbnail: ?string, webm: ?string, mp4: ?string}>
     */
    public function parseMoviesPhp(array $data, ?string &$trailerUrl): array
    {
        $movies = [];
        $trailerUrl = null;
        if (! empty($data['movies']) && is_array($data['movies'])) {
            foreach ($data['movies'] as $movie) {
                $mp4Url = $movie['mp4']['max'] ?? ($movie['mp4']['480'] ?? null);
                $movies[] = [
                    'id' => $movie['id'] ?? null,
                    'name' => $movie['name'] ?? null,
                    'thumbnail' => $movie['thumbnail'] ?? null,
                    'webm' => $movie['webm']['max'] ?? ($movie['webm']['480'] ?? null),
                    'mp4' => $mp4Url,
                ];
                if ($trailerUrl === null && ! empty($mp4Url)) {
                    $trailerUrl = $mp4Url;
                }
            }
        }

        return $movies;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<int, string>
     */
    public function parseGenresPhp(array $data): array
    {
        $genres = [];
        if (! empty($data['genres']) && is_array($data['genres'])) {
            foreach ($data['genres'] as $genre) {
                if (! empty($genre['description'])) {
                    $genres[] = (string) $genre['description'];
                }
            }
        }

        return $genres;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<int, string>
     */
    public function parseCategoriesPhp(array $data): array
    {
        $categories = [];
        if (! empty($data['categories']) && is_array($data['categories'])) {
            foreach ($data['categories'] as $cat) {
                if (! empty($cat['description'])) {
                    $categories[] = (string) $cat['description'];
                }
            }
        }

        return $categories;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<int, string>
     */
    public function parsePlatformsPhp(array $data): array
    {
        $platforms = [];
        if (! empty($data['platforms']) && is_array($data['platforms'])) {
            if ($data['platforms']['windows'] ?? false) {
                $platforms[] = 'Windows';
            }
            if ($data['platforms']['mac'] ?? false) {
                $platforms[] = 'Mac';
            }
            if ($data['platforms']['linux'] ?? false) {
                $platforms[] = 'Linux';
            }
        }

        return $platforms;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, array{minimum: ?string, recommended: ?string}>
     */
    private function parseRequirements(array $data): array
    {
        $requirements = [];
        foreach (['pc' => 'pc_requirements', 'mac' => 'mac_requirements', 'linux' => 'linux_requirements'] as $key => $field) {
            if (! empty($data[$field]) && is_array($data[$field])) {
                $requirements[$key] = [
                    'minimum' => $data[$field]['minimum'] ?? null,
                    'recommended' => $data[$field]['recommended'] ?? null,
                ];
            }
        }

        return $requirements;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function parsePrice(array $data): ?SteamPriceData
    {
        if (isset($data['price_overview']) && is_array($data['price_overview'])) {
            return SteamPriceData::fromApiResponse($data['price_overview']);
        }

        if ($data['is_free'] ?? false) {
            return SteamPriceData::free();
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function parseReleaseDate(array $data): ?string
    {
        if (empty($data['release_date']['date'])) {
            return null;
        }

        try {
            return Carbon::parse((string) $data['release_date']['date'])->format('Y-m-d');
        } catch (\Exception) {
            return (string) $data['release_date']['date'];
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function parsePublisherPhp(array $data): ?string
    {
        if (empty($data['publishers']) || ! is_array($data['publishers'])) {
            return null;
        }

        return implode(', ', array_filter(array_map('strval', $data['publishers'])));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<int, string>
     */
    public function parseDevelopersPhp(array $data): array
    {
        if (empty($data['developers']) || ! is_array($data['developers'])) {
            return [];
        }

        return array_values(array_filter(array_map('strval', $data['developers'])));
    }
}

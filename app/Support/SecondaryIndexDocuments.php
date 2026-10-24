<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\AnidbInfo;
use App\Models\AnidbTitle;
use App\Models\BookInfo;
use App\Models\ConsoleInfo;
use App\Models\GamesInfo;
use App\Models\MusicInfo;
use App\Models\SteamApp;
use Carbon\Carbon;

/**
 * Map Eloquent models / rows to search-engine documents for secondary indexes.
 */
final class SecondaryIndexDocuments
{
    private const string FFI_CDEF = <<<'C'
        int64_t nntmux_secondary_index_releasedate_to_ts(const char *input_ptr, size_t input_len, uint32_t tz_is_utc);
        C;

    private static ?bool $useRustFfi = null;

    private static \FFI|false|null $ffi = null;

    public static function setUseRustFfi(?bool $useRust): void
    {
        self::$useRustFfi = $useRust;
    }

    public static function usesRustFfi(): bool
    {
        if (self::$useRustFfi !== null) {
            return self::$useRustFfi;
        }

        return self::ffi() !== null;
    }

    public static function ffi(): ?\FFI
    {
        if (self::$ffi === null) {
            self::$ffi = RustPipelineLibrary::resolveDomain('secondary_index_documents', self::FFI_CDEF) ?? false;
        }

        return self::$ffi === false ? null : self::$ffi;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    public static function musicFromArray(array $row): array
    {
        return [
            'title' => (string) ($row['title'] ?? ''),
            'artist' => (string) ($row['artist'] ?? ''),
            'year' => (string) ($row['year'] ?? ''),
            'genres_id' => (int) ($row['genres_id'] ?? 0),
            'cover' => (int) ($row['cover'] ?? 0),
        ];
    }

    public static function music(MusicInfo $m): array
    {
        return self::musicFromArray($m->getAttributes());
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    public static function bookFromArray(array $row): array
    {
        return [
            'title' => (string) ($row['title'] ?? ''),
            'author' => (string) ($row['author'] ?? ''),
            'publishdate' => (string) ($row['publishdate'] ?? ''),
            'cover' => (int) ($row['cover'] ?? 0),
        ];
    }

    public static function book(BookInfo $b): array
    {
        return self::bookFromArray($b->getAttributes());
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    public static function gamesFromArray(array $row): array
    {
        if (self::$useRustFfi ?? true) {
            $native = self::gamesFromArrayNative($row);
            if ($native !== null) {
                return $native;
            }
        }

        return self::gamesFromArrayPhp($row);
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>|null
     */
    public static function gamesFromArrayNative(array $row): ?array
    {
        $ts = self::releasedateToTimestampNative($row['releasedate'] ?? null);
        if ($ts === null) {
            return null;
        }

        return [
            'title' => (string) ($row['title'] ?? ''),
            'genres_id' => (int) ($row['genres_id'] ?? 0),
            'releasedate_ts' => $ts,
            'cover' => (int) ($row['cover'] ?? 0),
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    public static function gamesFromArrayPhp(array $row): array
    {
        return [
            'title' => (string) ($row['title'] ?? ''),
            'genres_id' => (int) ($row['genres_id'] ?? 0),
            'releasedate_ts' => self::releasedateToTimestampPhp($row['releasedate'] ?? null),
            'cover' => (int) ($row['cover'] ?? 0),
        ];
    }

    public static function games(GamesInfo $g): array
    {
        return self::gamesFromArray($g->getAttributes());
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    public static function consoleFromArray(array $row): array
    {
        if (self::$useRustFfi ?? true) {
            $native = self::consoleFromArrayNative($row);
            if ($native !== null) {
                return $native;
            }
        }

        return self::consoleFromArrayPhp($row);
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>|null
     */
    public static function consoleFromArrayNative(array $row): ?array
    {
        $ts = self::releasedateToTimestampNative($row['releasedate'] ?? null);
        if ($ts === null) {
            return null;
        }

        return [
            'title' => (string) ($row['title'] ?? ''),
            'platform' => (string) ($row['platform'] ?? ''),
            'genres_id' => (int) ($row['genres_id'] ?? 0),
            'releasedate_ts' => $ts,
            'cover' => (int) ($row['cover'] ?? 0),
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    public static function consoleFromArrayPhp(array $row): array
    {
        return [
            'title' => (string) ($row['title'] ?? ''),
            'platform' => (string) ($row['platform'] ?? ''),
            'genres_id' => (int) ($row['genres_id'] ?? 0),
            'releasedate_ts' => self::releasedateToTimestampPhp($row['releasedate'] ?? null),
            'cover' => (int) ($row['cover'] ?? 0),
        ];
    }

    public static function console(ConsoleInfo $c): array
    {
        return self::consoleFromArray($c->getAttributes());
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    public static function steamFromArray(array $row): array
    {
        return [
            'name' => (string) ($row['name'] ?? ''),
            'appid' => (int) ($row['appid'] ?? 0),
        ];
    }

    public static function steam(SteamApp $s): array
    {
        return self::steamFromArray($s->getAttributes());
    }

    /**
     * @return array<string, mixed>
     */
    public static function animeTitle(AnidbTitle $t, ?AnidbInfo $info = null): array
    {
        $info ??= AnidbInfo::query()->where('anidbid', $t->anidbid)->first();

        return [
            'title' => (string) $t->title,
            'anidbid' => (int) $t->anidbid,
            'anilist_id' => (int) ($info?->anilist_id ?? 0),
            'mal_id' => (int) ($info?->mal_id ?? 0),
            'lang' => (string) $t->lang,
            'title_type' => (string) $t->type,
            'media_type' => (string) ($info?->media_type ?? ''),
            'status' => (string) ($info?->status ?? ''),
        ];
    }

    public static function releasedateToTimestamp(mixed $rd): int
    {
        if ($rd === null || $rd === '') {
            return 0;
        }
        if ($rd instanceof Carbon) {
            return $rd->getTimestamp();
        }
        if (self::$useRustFfi ?? true) {
            $native = self::releasedateToTimestampNative($rd);
            if ($native !== null) {
                return $native;
            }
        }

        return self::releasedateToTimestampPhp($rd);
    }

    public static function releasedateToTimestampNative(mixed $rd): ?int
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return null;
        }
        if ($rd === null || $rd === '') {
            return 0;
        }
        if ($rd instanceof Carbon) {
            return $rd->getTimestamp();
        }
        if (! is_string($rd) && ! is_int($rd) && ! is_float($rd) && ! is_bool($rd) && ! ($rd instanceof \Stringable)) {
            return self::releasedateToTimestampPhp($rd);
        }

        $s = (string) $rd;
        if ($s === '') {
            return 0;
        }

        $tz = date_default_timezone_get();
        $tzIsUtc = ($tz === 'UTC' || $tz === '+00:00' || $tz === 'Z') ? 1 : 0;

        // @phpstan-ignore method.notFound
        $ts = $ffi->nntmux_secondary_index_releasedate_to_ts($s, strlen($s), $tzIsUtc);
        if ($ts !== PHP_INT_MIN) {
            return $ts;
        }

        return self::releasedateToTimestampPhp($rd);
    }

    public static function releasedateToTimestampPhp(mixed $rd): int
    {
        if ($rd instanceof Carbon) {
            return $rd->getTimestamp();
        }
        if ($rd !== null && $rd !== '') {
            try {
                return Carbon::parse((string) $rd)->getTimestamp();
            } catch (\Throwable) {
                return 0;
            }
        }

        return 0;
    }
}

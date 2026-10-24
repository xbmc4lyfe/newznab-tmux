<?php

declare(strict_types=1);

namespace App\Services\Nzb;

use App\Models\Release;
use App\Models\ReleaseNfo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\LazyCollection;
use PDO;
use WeakMap;

/**
 * Persists and looks up releases.article_fingerprint (see {@see NzbArticleFingerprint}).
 * Column support is cached per PDO connection, so a schema change on a new connection is seen.
 */
final class ReleaseArticleFingerprintStore
{
    // Paired common/uncommon mean and p99 must all reach 1.5x before adoption.
    private static bool $useRustFfi = false;

    public static function enableRustFfi(): void
    {
        self::$useRustFfi = true;
    }

    public static function disableRustFfi(): void
    {
        self::$useRustFfi = false;
    }

    public static function resetRustFfi(): void
    {
        self::$useRustFfi = false;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi;
    }

    public const string COLUMN = 'article_fingerprint';

    /** @var WeakMap<PDO, bool>|null */
    private static ?WeakMap $columnSupport = null;

    public static function columnExists(): bool
    {
        $pdo = DB::connection()->getPdo();
        self::$columnSupport ??= new WeakMap;

        return self::$columnSupport[$pdo] ??= Schema::hasColumn('releases', self::COLUMN);
    }

    public static function store(int $releaseId, ?string $fingerprint): void
    {
        $values = self::updatePayload($fingerprint);
        if ($values !== [] && self::columnExists()) {
            Release::query()->where('id', $releaseId)->update($values);
        }
    }

    /**
     * Releases recorded with this fingerprint, oldest first.
     *
     * @param  list<string>  $columns
     * @return LazyCollection<int, Release>
     */
    public static function candidates(string $fingerprint, array $columns = ['id', 'guid', 'name', 'searchname', 'fromname', 'size']): LazyCollection
    {
        if (! self::columnExists()) {
            return LazyCollection::empty();
        }

        return Release::query()->where(self::COLUMN, $fingerprint)->select($columns)->lazyById(100);
    }

    /** @return array<string, string> */
    public static function updatePayload(?string $fingerprint): array
    {
        return (self::$useRustFfi ? self::updatePayloadNative($fingerprint) : null) ?? self::updatePayloadPhp($fingerprint);
    }

    /** @return array<string, string> */
    public static function updatePayloadPhp(?string $fingerprint): array
    {
        // Preserve exact bytes, including empty/non-hex values accepted by store().
        return $fingerprint === null ? [] : [self::COLUMN => $fingerprint];
    }

    /** @return array<string, string>|null */
    public static function updatePayloadNative(?string $fingerprint): ?array
    {
        $result = ReleaseNfo::computeNative(3, [$fingerprint]);

        return is_array($result) ? $result : null;
    }
}

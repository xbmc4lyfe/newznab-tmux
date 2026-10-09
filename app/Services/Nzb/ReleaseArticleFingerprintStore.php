<?php

declare(strict_types=1);

namespace App\Services\Nzb;

use App\Models\Release;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PDO;
use WeakMap;

/**
 * Persists and looks up releases.article_fingerprint (see {@see NzbArticleFingerprint}).
 * Column support is cached per PDO connection, so a schema change on a new connection is seen.
 */
final class ReleaseArticleFingerprintStore
{
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
        if ($fingerprint !== null && self::columnExists()) {
            Release::query()->where('id', $releaseId)->update([self::COLUMN => $fingerprint]);
        }
    }

    /**
     * @param  list<string>  $columns
     */
    public static function find(string $fingerprint, array $columns = ['id', 'guid', 'name', 'searchname', 'fromname', 'size']): ?Release
    {
        if (! self::columnExists()) {
            return null;
        }

        return Release::query()->where(self::COLUMN, $fingerprint)->first($columns);
    }
}

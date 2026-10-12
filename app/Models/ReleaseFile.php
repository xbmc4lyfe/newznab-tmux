<?php

declare(strict_types=1);

namespace App\Models;

use App\Facades\Search;
use App\Models\Concerns\HasCompositePrimaryKey;
use App\Support\Par2FileHashEvidence;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * App\Models\ReleaseFile.
 *
 * @property int $releases_id FK to releases.id
 * @property string $name
 * @property int $size
 * @property \Carbon\Carbon|null $created_at
 * @property \Carbon\Carbon|null $updated_at
 * @property bool $passworded
 * @property-read Release $release
 *
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\ReleaseFile whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\ReleaseFile whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\ReleaseFile wherePassworded($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\ReleaseFile whereReleasesId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\ReleaseFile whereSize($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\ReleaseFile whereUpdatedAt($value)
 *
 * @mixin \Eloquent
 *
 * @property string $crc32
 *
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\ReleaseFile newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\ReleaseFile newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\ReleaseFile query()
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\ReleaseFile whereCrc32($value)
 */
class ReleaseFile extends Model
{
    use HasCompositePrimaryKey;

    protected $dateFormat = false;

    /**
     * @var array<string>
     */
    protected $guarded = [];

    /**
     * @var string
     */
    protected $primaryKey = 'releases_id';

    /**
     * @return non-empty-list<string>
     */
    public function compositeKeyColumns(): array
    {
        return ['releases_id', 'name'];
    }

    /**
     * @return BelongsTo<Release, $this>
     */
    public function release(): BelongsTo
    {
        return $this->belongsTo(Release::class, 'releases_id');
    }

    /**
     * Scope to filter release files that look like NFO files.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeNfoFiles(Builder $query): Builder
    {
        return $query->where(function (Builder $q) {
            $q->where('name', 'like', '%.nfo')
                ->orWhere('name', 'like', '%.diz')
                ->orWhere('name', 'like', '%.inf')
                ->orWhere('name', 'like', '%file\_id.diz')
                ->orWhere('name', 'like', '%info.txt');
        });
    }

    /**
     * Scope to filter NFO files that have non-zero size (likely contain actual content).
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeNfoFilesWithContent(Builder $query): Builder
    {
        return $query->nfoFiles()->where('size', '>', 0);
    }

    /**
     * Get releasefiles row by id.
     */
    public static function getReleaseFiles(mixed $id): mixed
    {
        return self::query()->where('releases_id', $id)->orderBy('name')->get();
    }

    public static function getByGuid(mixed $guid): mixed
    {
        return self::query()
            ->join('releases', 'releases.id', '=', 'release_files.releases_id')
            ->where('releases.guid', $guid)
            ->orderBy('release_files.name')->get();
    }

    /**
     * Add new files for a release ID.
     *
     *
     *
     * @param  array<string, mixed>|null  $expectedSnapshot
     * @param  null|callable(): bool  $guard
     *
     * @throws \Exception
     */
    public static function addReleaseFiles(mixed $id, mixed $name, mixed $size, mixed $createdTime, mixed $hasPassword, string $hash = '', string $crc = '', string $fullMd5 = '', mixed $exactSize = null, ?array $expectedSnapshot = null, ?callable $guard = null): int
    {
        if ($fullMd5 !== '') {
            if ($expectedSnapshot === null) {
                return 0;
            }
            $aborted = new \RuntimeException('PAR2 file metadata ownership changed.');
            try {
                return DB::transaction(function () use ($id, $name, $size, $createdTime, $hasPassword, $hash, $crc, $fullMd5, $exactSize, $expectedSnapshot, $guard, $aborted): int {
                    $current = DB::table('releases')->where('id', $id)->lockForUpdate()->first();
                    if (! Par2FileHashEvidence::snapshotMatches($current, $expectedSnapshot)) {
                        throw $aborted;
                    }
                    if (! Par2FileHashEvidence::remember((int) $id, $fullMd5, $hash, $exactSize ?? $size, $expectedSnapshot, $guard)) {
                        throw $aborted;
                    }
                    $afterSnapshot = $expectedSnapshot;
                    if (array_key_exists('proc_hash16k', $afterSnapshot) && (int) ($current->predb_id ?? 0) === 0 && in_array((int) ($current->isrenamed ?? 0), [0, 1], true)) {
                        $afterSnapshot['proc_hash16k'] = (int) DB::table('releases')->where('id', $id)->value('proc_hash16k');
                    }
                    $inserted = self::addReleaseFiles($id, $name, $size, $createdTime, $hasPassword, $hash, $crc);
                    if (($guard !== null && ! $guard())
                        || ! Par2FileHashEvidence::snapshotMatches(DB::table('releases')->where('id', $id)->lockForUpdate()->first(), $afterSnapshot)) {
                        throw $aborted;
                    }

                    return $inserted;
                });
            } catch (\RuntimeException $exception) {
                if ($exception === $aborted) {
                    return 0;
                }
                throw $exception;
            }
        }
        // Check if we already have this data in table
        $duplicateCheck = self::query()->where('releases_id', $id)->where('name', $name)->first();

        // Check if the release exists in releases table to prevent foreign key error
        $releaseCheck = Release::query()->where('id', $id)->first();

        if (is_int($createdTime)) {
            if ($createdTime === 0) {
                $adjustedCreatedTime = now()->format('Y-m-d H:i:s');
            } else {
                $adjustedCreatedTime = Carbon::createFromTimestamp($createdTime, date_default_timezone_get())->format('Y-m-d H:i:s');
            }
        } else {
            $adjustedCreatedTime = $createdTime;
        }

        if ($duplicateCheck === null && $releaseCheck !== null) {
            try {
                $insert = self::insertOrIgnore([
                    'releases_id' => $id,
                    'name' => $name,
                    'size' => $size,
                    'created_at' => $adjustedCreatedTime,
                    'updated_at' => now()->timestamp,
                    'passworded' => $hasPassword,
                    'crc32' => $crc,
                ]);
            } catch (\PDOException $e) {
                Log::alert($e->getMessage());
            }

            DB::afterCommit(function () use ($id): void {
                Search::updateRelease($id);
            });
        }

        if ($releaseCheck !== null && strlen($hash) === 32 && ctype_xdigit($hash)
            && ($duplicateCheck !== null || ($insert ?? 0) > 0 || self::query()->where('releases_id', $id)->where('name', $name)->exists())
        ) {
            ParHash::insertOrIgnore(['releases_id' => $id, 'hash' => strtolower($hash)]);
        }

        return $insert ?? 0;
    }
}

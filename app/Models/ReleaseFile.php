<?php

declare(strict_types=1);

namespace App\Models;

use App\Facades\Search;
use App\Models\Concerns\HasCompositePrimaryKey;
use App\Support\Par2FileHashEvidence;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

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
                    if (self::shouldRefreshHashSnapshot($afterSnapshot, (int) ($current->predb_id ?? 0), (int) ($current->isrenamed ?? 0))) {
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

        $normalizedHash = self::normalizeParHash($hash);
        if ($releaseCheck !== null && $normalizedHash !== null
            && ($duplicateCheck !== null || ($insert ?? 0) > 0 || self::query()->where('releases_id', $id)->where('name', $name)->exists())
        ) {
            ParHash::insertOrIgnore(['releases_id' => $id, 'hash' => $normalizedHash]);
        }

        return $insert ?? 0;
    }

    public static function normalizeParHash(string $hash): ?string
    {
        if (self::$useRustFfi && ($native = self::normalizeParHashNative($hash)) !== null) {
            return $native === '' ? null : $native;
        }

        return strlen($hash) === 32 && ctype_xdigit($hash) ? strtolower($hash) : null;
    }

    /** Empty means invalid; null means the ABI declined. */
    public static function normalizeParHashNative(string $hash): ?string
    {
        return self::nativeCall(0, $hash);
    }

    /** @param array<string, mixed> $snapshot */
    public static function shouldRefreshHashSnapshot(array $snapshot, int $predbId, int $isRenamed): bool
    {
        return (self::$useRustFfi ? self::shouldRefreshHashSnapshotNative($snapshot, $predbId, $isRenamed) : null)
            ?? (array_key_exists('proc_hash16k', $snapshot) && $predbId === 0 && in_array($isRenamed, [0, 1], true));
    }

    /** @param array<string, mixed> $snapshot */
    public static function shouldRefreshHashSnapshotNative(array $snapshot, int $predbId, int $isRenamed): ?bool
    {
        $input = self::packFrames([array_key_exists('proc_hash16k', $snapshot) ? '1' : '0', (string) $predbId, (string) $isRenamed]);
        $result = $input === null ? null : self::nativeCall(1, $input);

        return $result === null ? null : $result === '1';
    }

    private static function nativeCall(int $mode, string $input): ?string
    {
        $ffi = RustPipelineLibrary::resolveDomain('wave20-file', 'intptr_t nntmux_wave20_file(uint32_t mode, const char *input, size_t input_len, char *out, size_t out_cap);');
        if ($ffi === null) {
            return null;
        }
        $capacity = 0;
        $buffer = RustPipelineLibrary::obtainScratch($ffi, max(256, strlen($input) * 2 + 256), $capacity);
        if ($buffer === null) {
            return null;
        }
        try {
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_wave20_file($mode, $input, strlen($input), $buffer, $capacity);

            return is_int($written) && $written >= 0 && $written <= $capacity
                ? ($written === 0 ? '' : FFI::string($buffer, $written)) : null;
        } catch (Throwable) {
            return null;
        }
    }

    /** @param list<string> $values */
    private static function packFrames(array $values): ?string
    {
        $input = '';
        foreach ($values as $value) {
            if (strlen($value) > 0xFFFFFFFF) {
                return null;
            }
            $input .= pack('V', strlen($value)).$value;
        }

        return $input;
    }

    // Single-call FFI must qualify on all four benchmark metrics before adoption.
    private static bool $useRustFfi = false;

    public static function useRustFfi(bool $enabled): void
    {
        self::$useRustFfi = $enabled;
    }
}

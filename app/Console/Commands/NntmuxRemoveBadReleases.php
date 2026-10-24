<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Facades\Search;
use App\Models\Release;
use App\Models\ReleaseFile;
use App\Services\Nzb\NzbService;
use App\Services\ReleaseImageService;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Console\Command;
use Throwable;

class NntmuxRemoveBadReleases extends Command
{
    private const FFI_CDEF = <<<'CDEF'
int32_t nntmux_w30_nzb_release_cleanup_commands(
    const char *input,
    size_t len,
    uint8_t *out,
    size_t out_cap
);
CDEF;

    /** Rust stays opt-in: the JSON FFI round trip is slower than these PHP helpers (< 1.5x). */
    private const RUST_FFI_DEFAULT = false;

    private static bool $useRustFfi = self::RUST_FFI_DEFAULT;

    private static FFI|false|null $ffi = null;

    public static function useRustFfi(bool $enabled = true): void
    {
        self::$useRustFfi = $enabled;
    }

    public static function setUseRustFfi(?bool $useRustFfi): void
    {
        self::$useRustFfi = $useRustFfi ?? self::RUST_FFI_DEFAULT;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi;
    }

    public static function resetRustFfi(): void
    {
        self::$useRustFfi = self::RUST_FFI_DEFAULT;
        self::$ffi = null;
    }

    public static function setFfiForTesting(FFI|false|null $ffi = null): void
    {
        self::$ffi = $ffi;
    }

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'nntmux:remove-bad';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Update releases that have passworded files inside archives and remove releases that cannot be PPA\'d properly';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    public static function isBadPasswordStatus(int $passwordStatus): bool
    {
        return self::$useRustFfi ? self::isBadPasswordStatusNative($passwordStatus) : self::isBadPasswordStatusPhp($passwordStatus);
    }

    public static function isBadPasswordStatusPhp(int $passwordStatus): bool
    {
        return $passwordStatus <= -2;
    }

    public static function isBadPasswordStatusNative(int $passwordStatus): bool
    {
        if (self::callNative(['remove_bad.is_bad_password_status', $passwordStatus], $result) && is_bool($result)) {
            return $result;
        }

        return self::isBadPasswordStatusPhp($passwordStatus);
    }

    /**
     * Unique positive release ids, in first-seen order, from ids or rows carrying `releases_id`.
     *
     * @param  array<array-key, mixed>  $rows
     * @return list<int>
     */
    public static function normalizeReleaseIdList(array $rows): array
    {
        return self::$useRustFfi ? self::normalizeReleaseIdListNative($rows) : self::normalizeReleaseIdListPhp($rows);
    }

    /**
     * @param  array<array-key, mixed>  $rows
     * @return list<int>
     */
    public static function normalizeReleaseIdListPhp(array $rows): array
    {
        $ids = [];
        foreach ($rows as $row) {
            $value = self::releaseIdValue($row);
            $id = $value === null || is_scalar($value) ? (int) $value : 0;
            if ($id > 0) {
                $ids[$id] ??= $id;
            }
        }

        return array_values($ids);
    }

    /**
     * @param  array<array-key, mixed>  $rows
     * @return list<int>
     */
    public static function normalizeReleaseIdListNative(array $rows): array
    {
        $values = [];
        foreach ($rows as $row) {
            $value = self::releaseIdValue($row);
            $values[] = $value === null || is_scalar($value) ? $value : null;
        }
        if (self::callNative(['remove_bad.normalize_release_id_list', $values], $result) && is_array($result) && array_is_list($result)) {
            $ids = [];
            foreach ($result as $id) {
                if (! is_int($id)) {
                    return self::normalizeReleaseIdListPhp($rows);
                }
                $ids[] = $id;
            }

            return $ids;
        }

        return self::normalizeReleaseIdListPhp($rows);
    }

    public static function formatUpdatedBadReleasesMessage(int $count): string
    {
        return self::$useRustFfi ? self::formatUpdatedBadReleasesMessageNative($count) : self::formatUpdatedBadReleasesMessagePhp($count);
    }

    public static function formatUpdatedBadReleasesMessagePhp(int $count): string
    {
        return "Updated {$count} bad releases";
    }

    public static function formatUpdatedBadReleasesMessageNative(int $count): string
    {
        if (self::callNative(['remove_bad.format_updated_bad_releases_message', $count], $result) && is_string($result)) {
            return $result;
        }

        return self::formatUpdatedBadReleasesMessagePhp($count);
    }

    private static function releaseIdValue(mixed $row): mixed
    {
        if (is_array($row)) {
            return $row['releases_id'] ?? null;
        }
        if (is_object($row)) {
            return $row->releases_id ?? null;
        }

        return $row;
    }

    /**
     * Execute the console command.
     *
     *
     * @throws \Exception
     */
    public function handle(): void
    {
        // Select releases with password status -2 and smaller and delete them. Also delete the files from the filesystem.
        $badReleases = Release::query()->where('passwordstatus', '<=', -2)->get();
        foreach ($badReleases as $badRelease) {
            if (! self::isBadPasswordStatus((int) $badRelease->passwordstatus)) {
                continue;
            }
            app(NzbService::class)->deleteNzb($badRelease->guid);
            (new ReleaseImageService)->delete($badRelease->guid);
            // Delete from search index
            Search::deleteRelease($badRelease->id);
            $badRelease->delete();
        }
        Release::query()->where('passwordstatus', '=', -2)->delete();

        $passReleases = ReleaseFile::query()->where('passworded', '=', 1)->groupBy('releases_id')->get();

        $count = 0;
        foreach (self::normalizeReleaseIdList($passReleases->pluck('releases_id')->all()) as $releasesId) {
            Release::whereId($releasesId)->update(['passwordstatus' => 1]);
            Search::updateRelease($releasesId);
            $count++;
        }

        $this->info(self::formatUpdatedBadReleasesMessage($count));
    }

    /**
     * Evaluates `[operation, ...arguments]` in Rust; false means the caller keeps its PHP result.
     *
     * @param  list<mixed>  $request
     *
     * @param-out mixed $result
     */
    private static function callNative(array $request, mixed &$result = null): bool
    {
        $ffi = self::nativeFfi();
        if (! $ffi instanceof FFI) {
            return false;
        }

        try {
            $json = json_encode($request, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
            $capacity = max(256, strlen($json) * 2 + 256);
            for ($attempt = 0; $attempt < 2; $attempt++) {
                $buffer = $ffi->new("uint8_t[{$capacity}]");
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_w30_nzb_release_cleanup_commands($json, strlen($json), $buffer, $capacity);
                if (! is_int($written) || $written === -1 || $written === 0) {
                    return false;
                }
                if ($written < 0) {
                    $capacity = -$written;

                    continue;
                }
                $decoded = json_decode(FFI::string($buffer, $written), true, 8, JSON_THROW_ON_ERROR);
                if (! is_array($decoded) || count($decoded) !== 1 || ! array_key_exists(0, $decoded)) {
                    return false;
                }
                $result = $decoded[0];

                return true;
            }
        } catch (Throwable) {
            return false;
        }

        return false;
    }

    private static function nativeFfi(): ?FFI
    {
        if (self::$ffi === false) {
            return null;
        }
        if (self::$ffi instanceof FFI) {
            return self::$ffi;
        }
        $ffi = RustPipelineLibrary::resolveDomain('cleaning', self::FFI_CDEF);
        self::$ffi = $ffi ?? false;

        return $ffi;
    }
}

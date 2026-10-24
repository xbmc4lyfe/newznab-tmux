<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\ReleaseNameFixed;
use App\Models\Release;
use App\Services\Categorization\CategorizationService;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use Throwable;

class RecategorizeReleaseAfterNameFix
{
    public function __construct(private readonly CategorizationService $categorization) {}

    public function handle(ReleaseNameFixed $event): void
    {
        $snapshot = $event->expectedSnapshot;
        if (! self::expectedSnapshotMatches($snapshot, $event->releaseId, $event->newName)) {
            return;
        }
        $query = $snapshot === null
            ? Release::query()->where('id', $event->releaseId)->whereRaw('HEX(searchname) = ?', [self::uppercaseHex($event->newName)])
            : $this->snapshotQuery($snapshot);
        $release = $query->first([
            'id',
            'guid',
            'name',
            'groups_id',
            'fromname',
            'categories_id',
            'iscategorized',
            'searchname',
        ]);

        if ($release === null) {
            return;
        }

        $snapshot = ['id' => (int) $release->id, 'guid' => (string) $release->guid, 'name' => $release->name, 'searchname' => $release->searchname];
        $result = $this->categorization->determineCategory(
            $release->groups_id,
            $event->newName,
            (string) ($release->fromname ?? $event->poster)
        );

        $newCategoryId = (int) ($result['categories_id'] ?? $release->categories_id);

        if (self::categoryAlreadyCurrent((int) $release->categories_id, $newCategoryId, (int) $release->iscategorized)) {
            return;
        }

        $affected = $this->snapshotQuery($snapshot)->update([
            'categories_id' => $newCategoryId,
            'iscategorized' => 1,
        ]);
        if ($affected !== 1) {
            return;
        }

        if (config('nntmux.categorization.log', false)) {
            Log::info('categorization.rename_recategorized', [
                'release_id' => $release->id,
                'old_name' => $event->oldName,
                'new_name' => $event->newName,
                'old_category_id' => $event->oldCategoryId,
                'new_category_id' => $newCategoryId,
            ]);
        }
    }

    /**
     * @param  array{id:int,guid:string,name:?string,searchname:?string}  $snapshot
     * @return Builder<Release>
     */
    private function snapshotQuery(array $snapshot): Builder
    {
        $query = Release::query()->where('id', $snapshot['id']);
        foreach (['guid', 'name', 'searchname'] as $column) {
            if ($snapshot[$column] === null) {
                $query->whereNull($column);
            } else {
                $query->whereRaw('HEX('.$column.') = ?', [self::uppercaseHex($snapshot[$column])]);
            }
        }

        return $query;
    }

    /** @param array{id:int,guid:string,name:?string,searchname:?string}|null $snapshot */
    public static function expectedSnapshotMatches(?array $snapshot, int $id, string $name): bool
    {
        return (self::$useRustFfi ? self::expectedSnapshotMatchesNative($snapshot, $id, $name) : null)
            ?? ($snapshot === null || ($snapshot['id'] === $id && $snapshot['searchname'] === $name));
    }

    /** @param array{id:int,guid:string,name:?string,searchname:?string}|null $snapshot */
    public static function expectedSnapshotMatchesNative(?array $snapshot, int $id, string $name): ?bool
    {
        if ($snapshot !== null && (! is_int($snapshot['id']) || ! is_string($snapshot['searchname']))) {
            return false;
        }
        $input = $snapshot === null ? '' : self::packFrames([(string) $snapshot['id'], $snapshot['searchname'], (string) $id, $name]);
        $result = $input === null ? null : self::nativeCall(0, $input);

        return $result === null ? null : $result === '1';
    }

    public static function uppercaseHex(string $value): string
    {
        return (self::$useRustFfi ? self::uppercaseHexNative($value) : null) ?? strtoupper(bin2hex($value));
    }

    public static function uppercaseHexNative(string $value): ?string
    {
        return self::nativeCall(1, $value);
    }

    public static function categoryAlreadyCurrent(int $current, int $new, int $categorized): bool
    {
        return (self::$useRustFfi ? self::categoryAlreadyCurrentNative($current, $new, $categorized) : null)
            ?? ($current === $new && $categorized === 1);
    }

    public static function categoryAlreadyCurrentNative(int $current, int $new, int $categorized): ?bool
    {
        $input = self::packFrames([(string) $current, (string) $new, (string) $categorized]);
        $result = $input === null ? null : self::nativeCall(2, $input);

        return $result === null ? null : $result === '1';
    }

    private static function nativeCall(int $mode, string $input): ?string
    {
        $ffi = RustPipelineLibrary::resolveDomain('wave20-recat', 'intptr_t nntmux_wave20_recat(uint32_t mode, const char *input, size_t input_len, char *out, size_t out_cap);');
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
            $written = $ffi->nntmux_wave20_recat($mode, $input, strlen($input), $buffer, $capacity);

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

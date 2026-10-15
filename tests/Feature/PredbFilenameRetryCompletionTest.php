<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Facades\Search;
use App\Services\NameFixing\NameFixingService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\Par2RenameTestDatabase;
use Tests\TestCase;

final class PredbFilenameRetryCompletionTest extends TestCase
{
    use Par2RenameTestDatabase;

    public static function callers(): array
    {
        return [['files'], ['standard'], ['check'], ['prefile']];
    }

    #[DataProvider('callers')]
    public function test_unknown_filename_lookup_remains_pending_and_next_public_pass_can_attach(string $caller): void
    {
        $this->createPar2RenameSchema();
        config(['tmux.fix_names.cache_store' => 'array']);
        Cache::store('array')->flush();
        DB::statement('ALTER TABLE predb ADD COLUMN filename TEXT');
        $title = 'Synthetic.Canonical.Release.2026-GRP';
        $alias = 'Synthetic.Observed.Alias-GRP';
        $filename = $alias.'.mkv';
        $release = $this->pendingPar2Release([
            'searchname' => $title, 'leftguid' => 'a', 'predb_id' => 0,
            'proc_files' => 0, 'proc_uid' => 1, 'proc_crc32' => 1,
            'proc_hash16k' => 1, 'proc_nfo' => 1, 'proc_srr' => 1, 'proc_par2' => 1,
        ]);
        DB::table('predb')->insert(['id' => 7, 'title' => $title, 'filename' => $alias]);
        DB::table('release_files')->insert(['releases_id' => $release->id, 'name' => $filename]);
        $calls = 0;
        Search::shouldReceive('supportsReleaseCandidatePaging')->andReturnFalse();
        Search::shouldReceive('searchPredbFilenameCandidates')->andReturnUsing(static function () use (&$calls, $title, $alias): ?array {
            $calls++;

            return $calls === 1 ? null : [['id' => 7, 'title' => $title, 'filename' => $alias]];
        });
        $service = new NameFixingService;
        $run = static function () use ($service, $release, $caller, $filename): void {
            if ($caller === 'files') {
                $service->fixNamesWithFiles(0, true, 0, true, false);
            } elseif ($caller === 'standard') {
                $service->processStandardBatch('a', 1, false);
            } elseif ($caller === 'prefile') {
                $service->getPreFileNames(['prefile', '1']);
            } else {
                $entry = $release->fresh();
                $entry->releases_id = $entry->id;
                $entry->textstring = $filename;
                $service->reset();
                $service->checkName($entry, true, 'Filenames, ', true, false);
            }
        };
        $run();
        self::assertSame(1, $calls);
        self::assertSame(0, $release->fresh()->proc_files, 'Unknown nominations must not complete filename inspection');
        self::assertSame(0, $release->fresh()->predb_id);
        self::assertSame(0, $release->fresh()->isrenamed);
        $run();
        self::assertSame(2, $calls, 'The same unchanged release must be retried on the next public pass');
        self::assertSame(7, $release->fresh()->predb_id);
        self::assertSame([$filename], DB::table('release_files')->where('releases_id', $release->id)->pluck('name')->all());
    }

    public static function completionCases(): array
    {
        return [
            ['files', false], ['standard', false], ['check', false],
            ['files', true], ['standard', true],
        ];
    }

    #[DataProvider('completionCases')]
    public function test_only_complete_observations_can_complete_an_unmatched_filename_pass(string $caller, bool $mixedUnknown): void
    {
        $this->createPar2RenameSchema();
        config(['tmux.fix_names.cache_store' => 'array']);
        Cache::store('array')->flush();
        $release = $this->pendingPar2Release([
            'searchname' => 'Unresolved.Synthetic.2026-GRP', 'leftguid' => 'a',
            'proc_files' => 0, 'proc_uid' => 1, 'proc_crc32' => 1,
            'proc_hash16k' => 1, 'proc_nfo' => 1, 'proc_srr' => 1, 'proc_par2' => 1,
        ]);
        $names = ['opaque1234567890.mkv'];
        if ($mixedUnknown) {
            $names[] = 'opaque0987654321.mkv';
        }
        foreach ($names as $name) {
            DB::table('release_files')->insert(['releases_id' => $release->id, 'name' => $name]);
        }
        Search::shouldReceive('supportsReleaseCandidatePaging')->andReturnFalse();
        $calls = 0;
        Search::shouldReceive('searchPredbFilenameCandidates')->andReturnUsing(static function () use (&$calls, $mixedUnknown): ?array {
            $calls++;

            return $mixedUnknown && $calls === 1 ? null : [];
        });
        $service = new NameFixingService;
        if ($caller === 'files') {
            $service->fixNamesWithFiles(0, true, 0, true, false);
        } elseif ($caller === 'standard') {
            $service->processStandardBatch('a', 1, false);
        } else {
            $release->releases_id = $release->id;
            $release->textstring = $names[0];
            $service->checkName($release, true, 'Filenames, ', true, false);
        }
        self::assertSame(count($names), $calls);
        self::assertSame($mixedUnknown ? 0 : 1, $release->fresh()->proc_files);
        self::assertSame(0, $release->fresh()->predb_id);
        self::assertSame(0, $release->fresh()->isrenamed);
    }
}

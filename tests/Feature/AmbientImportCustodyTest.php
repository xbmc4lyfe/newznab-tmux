<?php

namespace Tests\Feature;

use App\Enums\NzbImportStatus;
use App\Services\BlacklistService;
use App\Services\Nzb\NzbImportService;
use App\Services\Releases\Verification\ManifestStore;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;

class AmbientImportCustodyTest extends ReleaseVerificationCandidatesTest
{
    public static function scenarios(): array
    {
        $cases = [];
        foreach (['exact', 'invalid', 'non-nzb', 'legacy-invalid'] as $mode) {
            foreach ([false, true] as $delete) {
                foreach ([false, true] as $deleteFailed) {
                    foreach (['commit', 'rollback'] as $finish) {
                        foreach ([1, 2] as $depth) {
                            $cases[] = [$mode, $delete, $deleteFailed, $finish, $depth];
                        }
                    }
                }
            }
        }

        return $cases;
    }

    #[DataProvider('scenarios')]
    public function test_actual_public_import_preserves_assertion_on_same_identity(string $mode, bool $delete, bool $deleteFailed, string $finish, int $depth): void
    {
        config(['logging.default' => 'stderr']);
        $blacklist = $this->createStub(BlacklistService::class);
        $blacklist->method('isBlackListed')->willReturn(false);
        $blacklist->method('getAndClearIdsToUpdate')->willReturn([]);
        $service = new class($blacklist) extends NzbImportService
        {
            public function __construct($blacklist)
            {
                parent::__construct(['Browser' => true]);
                $this->blacklistService = $blacklist;
            }

            protected function getAllGroups(): bool
            {
                $this->allGroups = ['alt.test' => 1];

                return true;
            }
        };
        $path = config('release_verification.storage_path').'/incoming.nzb';
        app('files')->ensureDirectoryExists(dirname($path));
        $xml = '<nzb><head>%s</head><file poster="poster" date="1" subject="fixture.bin (1/1)"><groups><group>alt.test</group></groups><segments><segment bytes="5" number="1">fixture@example.test</segment></segments></file></nzb>';
        $head = $mode === 'exact-spot' ? '<meta type="nntmux:verification-mode">spot-check</meta>' : '';
        file_put_contents($path, sprintf($xml, $head));
        $statuses = [];
        $callback = static function (array $r) use (&$statuses) {
            $statuses[] = $r['status'];
        };
        $service->beginImport([$path], resultCallback: $callback);
        $first = DB::table('release_verification_candidates')->first();
        self::assertNotNull($first);
        $store = app(ManifestStore::class);
        $original = $store->read($first->manifest_path);
        $stored = $original;
        if ($mode === 'password-order') {
            $stored['passwords'] = ['second assertion', 'synthetic assertion'];
        }
        if ($mode === 'changed-date') {
            $stored['date'] = '2001-01-01 00:00:00';
        }
        if ($mode === 'changed-mode') {
            $stored['verification_mode'] = 'spot_check';
        }
        if ($mode === 'changed-subject') {
            $stored['subject'] = 'different fixture';
        }
        if ($mode === 'changed-groups') {
            $stored['groups'] = ['alt.other'];
        }
        if ($mode === 'changed-bytes') {
            $stored['files'][0]['segments'][0]['bytes'] = 6;
        }
        if ($mode === 'changed-file-key') {
            $stored['files'][0]['key'] = 'different';
        }
        if ($mode === 'stored-conflict') {
            $stored['conflict'] = true;
        }
        $storedPath = $store->write($stored);
        $storedBytes = $store->size($storedPath);
        DB::table('release_verification_candidates')->where('id', $first->id)->update(['manifest_path' => $storedPath, 'manifest_hash' => substr($storedPath, 0, 64), 'manifest_bytes' => $storedBytes, 'lease_token' => 'fixture-lease', 'lease_until' => now()->addHour()]);
        DB::table('release_verification_state')->update(['pending_bytes' => $storedBytes]);
        if ($mode === 'corrupt') {
            file_put_contents($store->path($storedPath), 'broken');
        }
        if ($mode === 'capacity') {
            config(['release_verification.pending_bytes' => $storedBytes]);
        }
        if ($mode === 'conflict') {
            $xml = str_replace('</segments>', '<segment bytes="5" number="1">fixture@example.test</segment></segments>', $xml);
        }
        file_put_contents($path, sprintf($xml, $head.'<meta type="password">synthetic assertion</meta>'));
        if (in_array($mode, ['invalid', 'legacy-invalid'], true)) {
            file_put_contents($path, 'invalid fixture XML');
        } elseif ($mode === 'non-nzb') {
            $other = $path.'.txt';
            rename($path, $other);
            $path = $other;
        }
        $sourceHash = hash_file('sha256', $path);
        $archivePaths = glob(config('release_verification.storage_path').'/*');
        $before = (array) DB::table('release_verification_candidates')->first();
        if ($mode === 'legacy-invalid') {
            config(['release_verification.required' => false]);
        }
        $statusCount = count($statuses);
        for ($i = 0; $i < $depth; $i++) {
            DB::beginTransaction();
        }
        $service->beginImport([$path], delete: $delete, deleteFailed: $deleteFailed, resultCallback: $callback);
        if ($mode === 'legacy-invalid') {
            self::assertSame(NzbImportStatus::Failed, end($statuses));
            self::assertSame(! $deleteFailed, is_file($path));
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }

            return;
        }
        if ($mode === 'non-nzb') {
            self::assertCount($statusCount, $statuses);
        } else {
            self::assertSame(NzbImportStatus::MetadataDeferred, end($statuses));
        }
        self::assertSame($depth, DB::transactionLevel());
        self::assertSame($sourceHash, hash_file('sha256', $path));
        self::assertSame($archivePaths, glob(config('release_verification.storage_path').'/*'));
        self::assertFileExists($path);
        self::assertSame($before, (array) DB::table('release_verification_candidates')->first());
        for ($i = 0; $i < $depth; $i++) {
            if ($finish === 'commit') {
                DB::commit();
            } else {
                DB::rollBack();
            }
        }
        self::assertSame($before, (array) DB::table('release_verification_candidates')->first());
        self::assertSame([], $store->read($storedPath)['passwords'] ?? []);
        self::assertFileExists($path);
        $service->beginImport([$path], delete: $delete, deleteFailed: $deleteFailed, resultCallback: $callback);
        if ($mode === 'exact') {
            self::assertSame(NzbImportStatus::Queued, end($statuses));
            self::assertSame(['synthetic assertion'], $store->read(DB::table('release_verification_candidates')->first()->manifest_path)['passwords']);
            self::assertSame($sourceHash, hash_file('sha256', $path), 'Queued metadata retains its original source until publication.');
        } elseif (in_array($mode, ['invalid', 'legacy-invalid'], true)) {
            self::assertSame(NzbImportStatus::Failed, end($statuses));
            self::assertSame(! $deleteFailed, is_file($path));
        } else {
            self::assertCount($statusCount, $statuses);
            self::assertFileExists($path);
        }
    }
}

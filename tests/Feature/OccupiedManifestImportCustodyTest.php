<?php

namespace Tests\Feature;

use App\Enums\NzbImportStatus;
use App\Services\BlacklistService;
use App\Services\Nzb\NzbImportService;
use App\Services\Releases\Verification\ManifestStore;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\StoredManifests;

class OccupiedManifestImportCustodyTest extends ReleaseVerificationCandidatesTest
{
    use StoredManifests;

    public static function modes(): array
    {
        $rows = [];
        foreach ([false, true] as $enrich) {
            foreach ([false, true] as $delete) {
                foreach ([false, true] as $failed) {
                    $rows[] = [$enrich, $delete, $failed];
                }
            }
        }

        return $rows;
    }

    #[DataProvider('modes')]
    public function test_public_import_retains_custody_for_corrupt_occupied_target(bool $enrich, bool $delete, bool $failed): void
    {
        $service = $this->offlineImportService();
        $store = app(ManifestStore::class);
        $path = config('release_verification.storage_path').'/incoming.nzb';
        app('files')->ensureDirectoryExists(dirname($path));
        file_put_contents($path, $this->passwordFixture($enrich ? [] : ['synthetic assertion']));
        $service->beginImport([$path]);
        $initial = DB::table('release_verification_candidates')->first();
        self::assertNotNull($initial);
        $manifest = $store->read($initial->manifest_path);
        $oldBytes = $this->storedManifestBody($initial->manifest_path);
        if ($enrich) {
            $incoming = [];
            foreach ($manifest as $key => $value) {
                $incoming[$key] = $value;
                if ($key === 'metadata_source') {
                    $incoming['passwords'] = ['synthetic assertion'];
                }
            } $manifest = $incoming;
            DB::table('release_verification_candidates')->update(['lease_token' => 'custody-lease', 'lease_until' => now()->addHour()]);
        } else {
            DB::table('release_verification_candidates')->delete();
            DB::table('release_verification_state')->update(['pending_bytes' => 0]);
            $this->removeStoredManifest($initial->manifest_path);
        }
        $prepared = $store->prepare($manifest);
        $corrupt = gzencode('{"files":[]}');
        $this->occupyManifest($prepared->name, $corrupt);
        $before = DB::table('release_verification_candidates')->get()->map(fn ($r) => (array) $r)->all();
        $state = (array) DB::table('release_verification_state')->first();
        $source = $this->passwordFixture(['synthetic assertion']);
        file_put_contents($path, $source);
        $statuses = [];
        Log::partialMock()->shouldReceive('warning')->once()->with('NZB verification admission deferred.', [
            'category' => 'unexpected_admission_failure',
        ]);
        $service->beginImport([$path], delete: $delete, deleteFailed: $failed, resultCallback: static function (array $r) use (&$statuses) {
            $statuses[] = $r['status'];
        });
        self::assertSame([NzbImportStatus::MetadataDeferred], $statuses);
        self::assertSame($source, file_get_contents($path));
        self::assertSame($before, DB::table('release_verification_candidates')->get()->map(fn ($r) => (array) $r)->all());
        self::assertSame($state, (array) DB::table('release_verification_state')->first());
        self::assertSame($corrupt, $this->storedManifestBody($prepared->name));
        if ($enrich) {
            self::assertSame($oldBytes, $this->storedManifestBody($initial->manifest_path));
        }
    }

    #[DataProvider('modes')]
    public function test_missing_capacity_state_is_visible_without_relinquishing_source(bool $loggerThrows, bool $delete, bool $failed): void
    {
        $service = $this->offlineImportService();
        $path = config('release_verification.storage_path').'/missing-state.nzb';
        app('files')->ensureDirectoryExists(dirname($path));
        $source = $this->passwordFixture(['synthetic assertion']);
        file_put_contents($path, $source);
        DB::table('release_verification_state')->delete();
        $logger = Log::partialMock()->shouldReceive('warning')->once()->with('NZB verification admission deferred.', [
            'category' => 'unexpected_admission_failure',
        ]);
        if ($loggerThrows) {
            $logger->andThrow(new \RuntimeException('Synthetic diagnostic failure.'));
        }
        $statuses = [];
        $service->beginImport([$path], delete: $delete, deleteFailed: $failed, resultCallback: static function (array $result) use (&$statuses): void {
            $statuses[] = $result['status'];
            self::assertNull($result['candidate_identity']);
        });
        self::assertSame([NzbImportStatus::MetadataDeferred], $statuses);
        self::assertSame($source, file_get_contents($path));
        self::assertSame(0, DB::table('release_verification_candidates')->count());
    }

    /** @return iterable<array{string, bool, bool}> */
    public static function heldReplays(): iterable
    {
        foreach (['metadata_review', 'metadata_deferred'] as $status) {
            foreach ([false, true] as $delete) {
                foreach ([false, true] as $failed) {
                    yield [$status, $delete, $failed];
                }
            }
        }
    }

    #[DataProvider('heldReplays')]
    public function test_passwordless_hold_replay_preserves_custody_and_locked_state(string $status, bool $delete, bool $failed): void
    {
        $service = $this->offlineImportService();
        $path = config('release_verification.storage_path').'/held.nzb';
        app('files')->ensureDirectoryExists(dirname($path));
        $source = $this->passwordFixture([]);
        file_put_contents($path, $source);
        $service->beginImport([$path]);
        DB::table('release_verification_candidates')->update([
            'status' => $status, 'lease_token' => null, 'lease_until' => null,
        ]);
        $before = (array) DB::table('release_verification_candidates')->sole();
        $state = (array) DB::table('release_verification_state')->sole();
        $store = app(ManifestStore::class);
        $stored = $this->storedManifestBody($before['manifest_path']);
        $statuses = [];
        $service->beginImport([$path], delete: $delete, deleteFailed: $failed, resultCallback: static function (array $result) use (&$statuses): void {
            $statuses[] = $result['status'];
            self::assertNull($result['candidate_identity']);
        });
        self::assertSame([$status === 'metadata_review' ? NzbImportStatus::MetadataIgnored : NzbImportStatus::MetadataDeferred], $statuses);
        self::assertSame($source, file_get_contents($path));
        self::assertSame($before, (array) DB::table('release_verification_candidates')->sole());
        self::assertSame($state, (array) DB::table('release_verification_state')->sole());
        self::assertSame($stored, $this->storedManifestBody($before['manifest_path']));
    }

    public function test_existing_valid_alternative_gzip_is_reusable(): void
    {
        $store = app(ManifestStore::class);
        $prepared = $store->prepare(['files' => []]);
        app('files')->ensureDirectoryExists(dirname($store->path($prepared->name)));
        $alternative = gzencode(gzdecode($prepared->compressed), 0);
        self::assertNotSame($prepared->compressed, $alternative);
        $this->occupyManifest($prepared->name, $alternative);
        self::assertSame($prepared->name, $store->writePrepared($prepared));
        self::assertSame(['files' => []], $store->read($prepared->name));
        self::assertSame($alternative, $this->storedManifestBody($prepared->name));
    }

    private function offlineImportService(): NzbImportService
    {
        $blacklist = $this->createStub(BlacklistService::class);
        $blacklist->method('isBlackListed')->willReturn(false);
        $blacklist->method('getAndClearIdsToUpdate')->willReturn([]);

        return new class($blacklist) extends NzbImportService
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
    }

    /** @param list<string> $passwords */
    private function passwordFixture(array $passwords): string
    {
        $head = implode('', array_map(static fn (string $password): string => '<meta type="password">'.$password.'</meta>', $passwords));

        return '<nzb><head>'.$head.'</head><file poster="poster" date="1" subject="fixture.bin (1/1)"><groups><group>alt.test</group></groups><segments><segment bytes="5" number="1">fixture@example.test</segment></segments></file></nzb>';
    }
}

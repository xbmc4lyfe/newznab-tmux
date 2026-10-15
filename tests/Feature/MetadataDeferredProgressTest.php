<?php

namespace Tests\Feature;

use App\Enums\NzbImportStatus;
use App\Services\BlacklistService;
use App\Services\NfoService;
use App\Services\Nzb\NfoImportService;
use App\Services\Nzb\NzbImportService;
use App\Services\Nzb\NzbUploadManifestService;
use App\Services\Releases\Verification\CandidateCapacityException;
use App\Services\Releases\Verification\CandidateRepository;
use App\Services\Releases\Verification\ManifestStore;
use App\Services\Releases\Verification\NzbManifestReader;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class MetadataDeferredProgressTest extends ReleaseVerificationCandidatesTest
{
    private function manifest(string $key, array $passwords = []): array
    {
        $manifest = (new NzbManifestReader)->read('<nzb><file poster="poster" date="1" subject="fixture.bin (1/1)"><groups><group>alt.test</group></groups><segments><segment bytes="5" number="1">'.$key.'@example.test</segment></segments></file></nzb>', (object) ['groups_id' => 1, 'searchname' => 'fixture', 'fromname' => 'poster', 'postdate' => '1970-01-01 00:00:01', 'metadata_source' => 'import']);
        $manifest['identity'] = sha1('import:'.$key);
        $manifest['passwords'] = $passwords;

        return $manifest;
    }

    private function defer(array $plain): object
    {
        $repo = app(CandidateRepository::class);
        self::assertTrue($repo->enqueue($plain));
        $row = DB::table('release_verification_candidates')->where('identity', $plain['identity'])->first();
        config(['release_verification.pending_bytes' => DB::table('release_verification_state')->value('pending_bytes')]);
        try {
            $repo->enqueue(array_replace($plain, ['passwords' => [str_repeat('synthetic assertion', 100)]]));
            self::fail('Expected capacity deferral');
        } catch (CandidateCapacityException) {
        }

        return DB::table('release_verification_candidates')->where('id', $row->id)->first();
    }

    public function test_all_held_reservations_expire_and_new_work_can_progress(): void
    {
        $repo = app(CandidateRepository::class);
        $plain = $this->manifest('held');
        $held = $this->defer($plain);
        self::assertSame('metadata_deferred', $held->status);
        $old = now()->subHours((int) config('release_verification.expiry_hours') + 1)->format('Y-m-d H:i:s');
        DB::table('release_verification_candidates')->where('id', $held->id)->update(['last_unique_at' => $old]);
        try {
            $repo->enqueue(array_replace($plain, ['passwords' => [str_repeat('synthetic assertion', 100)]]));
        } catch (CandidateCapacityException) {
        }
        self::assertSame($old, DB::table('release_verification_candidates')->value('last_unique_at'));
        self::assertNull($repo->claim());
        $repo->expire();
        $expired = DB::table('release_verification_candidates')->first();
        self::assertSame('metadata_expired', $expired->status);
        self::assertSame('', $expired->manifest_path);
        self::assertSame(0, $expired->manifest_bytes);
        self::assertSame(0, DB::table('release_verification_state')->value('pending_bytes'));
        self::assertSame($old, $expired->last_unique_at);
        self::assertGreaterThan($held->version, $expired->version);
        self::assertFalse($repo->enqueue($plain));
        self::assertSame('metadata_expired', DB::table('release_verification_candidates')->value('status'));
        self::assertTrue($repo->enqueue($this->manifest('next')));
        self::assertNotNull($repo->claim());
        config(['release_verification.pending_bytes' => 1048576]);
        self::assertTrue($repo->enqueue(array_replace($plain, ['passwords' => ['synthetic assertion']])));
        $restored = DB::table('release_verification_candidates')->where('id', $held->id)->first();
        self::assertNotSame('metadata_expired', $restored->status);
        self::assertGreaterThan($expired->version, $restored->version);
        self::assertSame(['synthetic assertion'], app(ManifestStore::class)->read($restored->manifest_path)['passwords']);
    }

    public function test_expiry_protects_current_leases_and_rechecks_latest_candidate(): void
    {
        $repo = app(CandidateRepository::class);
        $held = $this->defer($this->manifest('lease'));
        DB::table('release_verification_candidates')->where('id', $held->id)->update(['last_unique_at' => now()->subDays(100), 'lease_token' => 'owned', 'lease_until' => now()->addHour()]);
        $repo->expire();
        self::assertSame('metadata_deferred', DB::table('release_verification_candidates')->value('status'));
        DB::table('release_verification_candidates')->where('id', $held->id)->update(['lease_until' => null]);
        $calls = 0;
        $repo->expire(function () use (&$calls): bool {
            if (++$calls === 2) {
                DB::table('release_verification_candidates')->update(['status' => 'ready', 'last_unique_at' => now()]);
            }

            return true;
        });
        self::assertSame('ready', DB::table('release_verification_candidates')->value('status'));
        self::assertGreaterThan(0, DB::table('release_verification_state')->value('pending_bytes'));
    }

    public function test_expiry_and_fresh_readmission_obey_outer_rollback(): void
    {
        $repo = app(CandidateRepository::class);
        $plain = $this->manifest('rollback');
        $held = $this->defer($plain);
        DB::table('release_verification_candidates')->update(['last_unique_at' => now()->subDays(100)]);
        DB::beginTransaction();
        $repo->expire();
        self::assertSame('metadata_expired', DB::table('release_verification_candidates')->value('status'));
        DB::rollBack();
        self::assertSame('metadata_deferred', DB::table('release_verification_candidates')->value('status'));
        self::assertFileExists(app(ManifestStore::class)->path($held->manifest_path));
        self::assertSame($held->manifest_bytes, DB::table('release_verification_state')->value('pending_bytes'));
        $repo->expire();
        config(['release_verification.pending_bytes' => 1048576]);
        DB::beginTransaction();
        self::assertTrue($repo->enqueue(array_replace($plain, ['passwords' => ['synthetic assertion']])));
        $fresh = DB::table('release_verification_candidates')->first();
        DB::rollBack();
        self::assertSame('metadata_expired', DB::table('release_verification_candidates')->value('status'));
        self::assertSame(0, DB::table('release_verification_state')->value('pending_bytes'));
        self::assertFileDoesNotExist(app(ManifestStore::class)->path($fresh->manifest_path));
    }

    public function test_expired_metadata_hold_retains_source_pair_even_with_delete_failed(): void
    {
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
        $directory = config('release_verification.storage_path').'/upload';
        app('files')->ensureDirectoryExists($directory);
        $path = $directory.'/incoming.nzb';
        $plain = '<nzb><head>%s</head><file poster="poster" date="1" subject="fixture.bin (1/1)"><groups><group>alt.test</group></groups><segments><segment bytes="5" number="1">fixture@example.test</segment></segments></file></nzb>';
        file_put_contents($path, sprintf($plain, ''));
        file_put_contents($directory.'/info.nfo', 'synthetic companion');
        $manifests = app(NzbUploadManifestService::class);
        $manifests->create($directory, 'synthetic-upload', 'incoming.nzb', 'info.nfo', 'synthetic companion');
        $statuses = [];
        $callback = static function (array $r) use ($manifests, &$statuses): void {
            $statuses[] = $r['status'];
            $manifests->recordNzbOutcome($r['path'], $r['status'], $r['release_id'], $r['release_guid'], candidateIdentity: $r['candidate_identity'], inputCapture: $r['input_capture']);
        };
        $service->beginImport([$path], resultCallback: $callback);
        $candidate = DB::table('release_verification_candidates')->first();
        self::assertNotNull($candidate);
        config(['release_verification.pending_bytes' => $candidate->manifest_bytes]);
        file_put_contents($path, sprintf($plain, '<meta type="password">'.str_repeat('synthetic assertion', 100).'</meta>'));
        $assertionSource = file_get_contents($path);
        $service->beginImport([$path], delete: true, deleteFailed: true, resultCallback: $callback);
        self::assertSame(NzbImportStatus::MetadataDeferred, end($statuses));
        DB::table('release_verification_candidates')->update(['last_unique_at' => now()->subDays(100)]);
        app(CandidateRepository::class)->expire();
        self::assertSame('metadata_expired', DB::table('release_verification_candidates')->value('status'));
        Schema::create('releases', static function (Blueprint $t): void {
            $t->id();
            $t->string('guid');
        });
        self::assertTrue($manifests->shouldImportNzb($path));
        $manifestPath = $directory.'/'.NzbUploadManifestService::FILENAME;
        self::assertSame('metadata_deferred', $manifests->read($manifestPath)['state']);
        $service->beginImport([$path], delete: true, deleteFailed: true, resultCallback: $callback);
        self::assertSame(NzbImportStatus::MetadataDeferred, end($statuses));
        self::assertSame($assertionSource, file_get_contents($path));
        $nfo = (new NfoImportService(new Filesystem, $manifests, new NfoService))->importManifest($manifestPath, true, true);
        self::assertSame('skipped', $nfo['status']);
        self::assertSame('synthetic companion', file_get_contents($directory.'/info.nfo'));
        self::assertSame('metadata_expired', DB::table('release_verification_candidates')->value('status'));
        self::assertSame(0, DB::table('release_verification_state')->value('pending_bytes'));
        file_put_contents($path, sprintf($plain, ''));
        $service->beginImport([$path], delete: true, deleteFailed: true, resultCallback: $callback);
        self::assertSame(NzbImportStatus::MetadataIgnored, end($statuses));
        self::assertFileExists($path);
        self::assertFileExists($directory.'/info.nfo');
        self::assertSame('metadata_expired', DB::table('release_verification_candidates')->value('status'));
        file_put_contents($path, $assertionSource);
        config(['release_verification.pending_bytes' => 1048576]);
        self::assertTrue($manifests->shouldImportNzb($path));
        $service->beginImport([$path], resultCallback: $callback);
        self::assertSame(NzbImportStatus::Queued, end($statuses));
        self::assertSame($candidate->id, DB::table('release_verification_candidates')->value('id'));
        self::assertSame(0, DB::table('releases')->count());
    }

    public function test_metadata_review_reservation_expires_but_invalid_or_passwordless_replay_stays_fenced(): void
    {
        $repo = app(CandidateRepository::class);
        $plain = $this->manifest('review');
        self::assertTrue($repo->enqueue($plain));
        DB::table('release_verification_candidates')->update(['status' => 'metadata_review', 'last_unique_at' => now()->subDays(100)]);
        $repo->expire();
        self::assertSame('metadata_expired', DB::table('release_verification_candidates')->value('status'));
        foreach ([[], ['passwords' => ['assertion'], 'metadata_source' => 'headers'], ['passwords' => ['assertion'], 'conflict' => true], ['passwords' => [42]]] as $changes) {
            self::assertFalse($repo->enqueue(array_replace($plain, $changes)));
            self::assertSame('metadata_expired', DB::table('release_verification_candidates')->value('status'));
            self::assertSame(0, DB::table('release_verification_state')->value('pending_bytes'));
            self::assertNull($repo->claim());
        }
    }
}

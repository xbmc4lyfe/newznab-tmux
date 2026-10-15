<?php

namespace Tests\Feature;

use App\Enums\NzbImportStatus;
use App\Services\BlacklistService;
use App\Services\Nzb\NzbImportService;
use App\Services\Nzb\NzbService;
use App\Services\Releases\Verification\CandidateCapacityException;
use App\Services\Releases\Verification\CandidateRepository;
use App\Services\Releases\Verification\ManifestStore;
use App\Services\Releases\Verification\VerifiedNzbWriter;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;

class NzbPublishedPasswordReplayTest extends ReleaseVerificationCandidatesTest
{
    public static function replayCases(): array
    {
        $cases = [];
        foreach (['ordered', 'repeated', 'empty', 'empty-string', 'missing-artifact', 'swapped', 'changed-subject', 'changed-size', 'missing-release', 'wrong-guid', 'unpublished', 'corrupt', 'wrong-articles', 'stored-swapped', 'dropped-repeat', 'changed-hash', 'candidate-not-published', 'wrong-release', 'blank-guid', 'decoded-bound', 'compressed-bound', 'nested-password', 'retained-superset', 'retained-different-size', 'missing-verified', 'passwordless-missing-artifact', 'passwordless-changed-hash', 'passwordless-metadata-review', 'passwordless-metadata-deferred'] as $mode) {
            $cases[$mode] = [$mode];
        }

        return $cases;
    }

    #[DataProvider('replayCases')]
    public function test_actual_enqueue_published_replay(string $mode): void
    {
        $this->runReplay($mode, false);
    }

    #[DataProvider('replayCases')]
    public function test_actual_import_published_replay(string $mode): void
    {
        $this->runReplay($mode, true);
    }

    private function runReplay(string $mode, bool $useImport): void
    {
        Schema::create('releases', function (Blueprint $table): void {
            $table->id();
            $table->string('guid');
            $table->integer('nzbstatus');
        });
        $passwords = match ($mode) {
            'empty', 'passwordless-missing-artifact', 'passwordless-changed-hash', 'passwordless-metadata-review', 'passwordless-metadata-deferred' => [], 'empty-string' => [''],
            'repeated', 'dropped-repeat' => ['first assertion', 'first assertion', 'second assertion'],
            default => ['first assertion', 'second assertion'],
        };
        $manifest = ['identity' => hash('sha256', 'private-fixture'), 'subject' => 'fixture.bin',
            'fromname' => 'synthetic poster', 'date' => '2026-01-01 00:00:00', 'groups_id' => 1,
            'regex_id' => 0, 'expected_files' => 1, 'groups' => ['alt.test'], 'metadata_source' => 'import',
            'files' => [['key' => 'synthetic-key', 'subject' => 'fixture.bin', 'totalparts' => 1,
                'segments' => [['number' => 1, 'bytes' => 5, 'messageid' => 'fixture@example.test']]]]];
        if ($passwords !== []) {
            $manifest['passwords'] = $passwords;
        }
        $repository = app(CandidateRepository::class);
        $statuses = [];
        $sourcePath = config('release_verification.storage_path').'/source.nzb';
        if ($useImport) {
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
            app('files')->ensureDirectoryExists(dirname($sourcePath));
            file_put_contents($sourcePath, app(VerifiedNzbWriter::class)->document($manifest));
            $callback = static function (array $result) use (&$statuses): void {
                $statuses[] = $result['status'];
            };
            $service->beginImport([$sourcePath], resultCallback: $callback);
            self::assertSame([NzbImportStatus::Queued], $statuses);
            $queued = DB::table('release_verification_candidates')->first();
            $manifest = app(ManifestStore::class)->read($queued->manifest_path);
        } else {
            self::assertTrue($repository->enqueue($manifest));
        }
        $first = DB::table('release_verification_candidates')->first();
        $guid = '11111111-2222-3333-4444-555555555555';
        DB::table('releases')->insert(['id' => 1, 'guid' => $guid, 'nzbstatus' => NzbService::NZB_ADDED]);
        DB::table('release_verification_candidates')->update(['release_id' => 1, 'status' => 'complete',
            'manifest_path' => '', 'manifest_bytes' => 0, 'verified_at' => now()]);
        DB::table('release_verification_state')->update(['pending_bytes' => 0]);
        $nzb = app(NzbService::class);
        // Point actual bounded disk reads at a fixture-owned path.
        $disk = $this->createPartialMock(NzbService::class, ['getNzbPath', 'nzbPath']);
        $artifact = config('release_verification.storage_path').'/published.nzb.gz';
        $disk->method('getNzbPath')->willReturn($artifact);
        $disk->method('nzbPath')->willReturnCallback(static fn (string $requested): string|false => $requested === $guid && is_file($artifact) ? $artifact : false);
        app()->instance(NzbService::class, $disk);
        $stored = $manifest;
        if ($mode === 'stored-swapped') {
            $stored['passwords'] = array_reverse($passwords);
        }
        if ($mode === 'wrong-articles') {
            $stored['files'][0]['segments'][0]['messageid'] = 'other@example.test';
        }
        if ($mode === 'retained-superset') {
            $stored['files'][0]['totalparts'] = 2;
            $stored['files'][0]['segments'][] = ['number' => 2, 'bytes' => 9, 'messageid' => 'extra@example.test'];
        }
        if ($mode === 'retained-different-size') {
            $stored['files'][0]['segments'][0]['bytes'] = 99;
        }
        file_put_contents($artifact, gzencode((new VerifiedNzbWriter($disk))->document($stored)));
        if (in_array($mode, ['missing-artifact', 'passwordless-missing-artifact'], true)) {
            unlink($artifact);
        }
        if ($mode === 'decoded-bound') {
            file_put_contents($artifact, gzencode(str_repeat('x', 16_777_217)));
        }
        if ($mode === 'compressed-bound') {
            file_put_contents($artifact, str_repeat('x', 4_194_305));
        }
        if ($mode === 'nested-password') {
            $nested = str_replace('<meta type="password">first assertion</meta>', '<meta type="password"><value>first assertion</value></meta>', (new VerifiedNzbWriter($disk))->document($stored));
            file_put_contents($artifact, gzencode($nested));
        }
        if ($mode === 'corrupt') {
            file_put_contents($artifact, 'not gzip');
        }
        if ($mode === 'swapped') {
            $manifest['passwords'] = array_reverse($passwords);
        }
        if ($mode === 'dropped-repeat') {
            $manifest['passwords'] = ['first assertion', 'second assertion'];
        }
        if ($mode === 'changed-subject') {
            $manifest['subject'] = 'changed fixture';
            $manifest['files'][0]['subject'] = 'changed fixture';
        }
        if ($mode === 'changed-size') {
            $manifest['files'][0]['segments'][0]['bytes']++;
        }
        if ($mode === 'missing-release') {
            DB::table('releases')->delete();
        }
        if ($mode === 'wrong-guid') {
            DB::table('releases')->update(['guid' => 'different-guid']);
        }
        if ($mode === 'blank-guid') {
            DB::table('releases')->update(['guid' => '']);
        }
        if ($mode === 'wrong-release') {
            DB::table('releases')->insert(['id' => 2, 'guid' => 'different-guid', 'nzbstatus' => NzbService::NZB_ADDED]);
            DB::table('release_verification_candidates')->update(['release_id' => 2]);
        }
        if ($mode === 'unpublished') {
            DB::table('releases')->update(['nzbstatus' => 0]);
        }
        if (in_array($mode, ['changed-hash', 'passwordless-changed-hash'], true)) {
            DB::table('release_verification_candidates')->update(['manifest_hash' => str_repeat('0', 64)]);
        }
        if ($mode === 'candidate-not-published') {
            DB::table('release_verification_candidates')->update(['status' => 'ready']);
        }
        if ($mode === 'missing-verified') {
            DB::table('release_verification_candidates')->update(['verified_at' => null]);
        }
        if (in_array($mode, ['passwordless-metadata-review', 'passwordless-metadata-deferred'], true)) {
            DB::table('release_verification_candidates')->update(['status' => str_replace('-', '_', substr($mode, 13))]);
        }
        $before = (array) DB::table('release_verification_candidates')->first();
        $capacity = (array) DB::table('release_verification_state')->first();
        $artifactBefore = is_file($artifact) ? file_get_contents($artifact) : false;
        $accepted = in_array($mode, ['ordered', 'repeated', 'empty', 'empty-string', 'retained-superset', 'retained-different-size', 'missing-release', 'passwordless-missing-artifact', 'passwordless-changed-hash'], true);
        if ($useImport) {
            $incoming = (new VerifiedNzbWriter($disk))->document($manifest);
            file_put_contents($sourcePath, $incoming);
            $service->beginImport([$sourcePath], delete: true, deleteFailed: true, resultCallback: $callback);
            self::assertSame($accepted ? NzbImportStatus::Queued : ($mode === 'passwordless-metadata-deferred' ? NzbImportStatus::MetadataDeferred : NzbImportStatus::MetadataIgnored), end($statuses));
            if ($accepted) {
                self::assertFileDoesNotExist($sourcePath);
            } else {
                self::assertSame($incoming, file_get_contents($sourcePath));
            }
        } else {
            if ($mode === 'passwordless-metadata-deferred') {
                try {
                    $repository->enqueue($manifest);
                    self::fail('Expected deferred capacity signal.');
                } catch (CandidateCapacityException) {
                    self::assertTrue(true);
                }
            } else {
                self::assertSame($accepted, $repository->enqueue($manifest));
            }
        }
        if ($mode === 'missing-release') {
            $reconstructed = DB::table('release_verification_candidates')->first();
            self::assertNull($reconstructed->release_id);
            self::assertSame((int) $before['version'] + 1, (int) $reconstructed->version);
            self::assertNotSame('', $reconstructed->manifest_path);
            self::assertNull($reconstructed->verified_at);
            self::assertSame(app(ManifestStore::class)->read($reconstructed->manifest_path), $manifest);
            self::assertSame((int) $reconstructed->manifest_bytes, (int) DB::table('release_verification_state')->value('pending_bytes'));
        } else {
            self::assertSame($before, (array) DB::table('release_verification_candidates')->first());
            self::assertSame($capacity, (array) DB::table('release_verification_state')->first());
        }
        self::assertSame($artifactBefore, is_file($artifact) ? file_get_contents($artifact) : false);
        self::assertSame(1, DB::table('release_verification_candidates')->count());
    }
}

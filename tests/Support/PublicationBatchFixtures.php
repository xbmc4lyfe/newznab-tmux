<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Facades\Search;
use App\Services\ReleaseCleaningService;
use App\Services\Releases\Verification\CandidateRepository;
use App\Services\Releases\Verification\ManifestStore;
use App\Services\Releases\Verification\ReleaseMetadataResolver;
use App\Services\Releases\Verification\SpotCheckVerifier;
use App\Services\Releases\Verification\VerificationResult;
use App\Support\Configuration\IngestionConfigurationData;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;

/** Real archived candidates, claimed and ready to publish through the remote publication endpoints. */
trait PublicationBatchFixtures
{
    private const array PEER = ['REMOTE_ADDR' => '192.168.1.113'];

    /** Group 2 refuses every candidate below ten million declared bytes, so its candidates are filtered by policy. */
    private function batchSchema(int $releases = 0): void
    {
        config(['release_verification.publish_endpoint' => true, 'nntmux.release_dedupe_enabled' => false,
            'nntmux_settings.path_to_nzbs' => config('release_verification.storage_path').'/nzbs']);
        app('files')->ensureDirectoryExists(config('nntmux_settings.path_to_nzbs'));
        Schema::table('usenet_groups', function (Blueprint $table): void {
            $table->integer('minsizetoformrelease')->default(0);
            $table->integer('minfilestoformrelease')->default(0);
        });
        Schema::create('ingestion_configurations', function (Blueprint $table): void {
            $table->id();
        });
        DB::table('usenet_groups')->insert(['id' => 2, 'name' => 'alt.strict', 'minsizetoformrelease' => 10000000]);
        Cache::put('configuration:ingestion', new IngestionConfigurationData(...IngestionConfigurationData::defaults()->toArray()), 300);
        Schema::create('releases', function (Blueprint $table): void {
            $table->id();
            foreach (['name', 'searchname', 'guid', 'leftguid', 'fromname', 'postdate', 'adddate'] as $column) {
                $table->string($column);
            }
            foreach (['totalpart', 'groups_id', 'size', 'passwordstatus', 'haspreview', 'categories_id', 'nfostatus', 'nzbstatus', 'isrenamed', 'iscategorized', 'predb_id'] as $column) {
                $table->integer($column);
            }
        });
        Schema::create('release_regexes', function (Blueprint $table): void {
            $table->integer('releases_id');
            $table->integer('collection_regex_id');
            $table->integer('naming_regex_id');
        });
        Schema::create('releases_groups', function (Blueprint $table): void {
            $table->integer('releases_id');
            $table->integer('groups_id');
        });
        (require database_path('migrations/2026_10_17_210000_create_release_verification_pauses_table.php'))->up();
        Search::shouldReceive('updateRelease')->times($releases)->andReturnNull();
        $this->app->instance(ReleaseMetadataResolver::class, new class(app(ReleaseCleaningService::class)) extends ReleaseMetadataResolver
        {
            public function resolve(array $manifest): array
            {
                return ['name' => 'Movie', 'searchname' => 'Movie', 'fromname' => 'poster', 'predb_id' => 0, 'isrenamed' => 1, 'categories_id' => 2000, 'naming_regex_id' => 0];
            }
        });
    }

    /**
     * Archive a one-article collection and claim its candidate. Candidates sharing a message id cover each other's articles.
     *
     * @param  array<string, mixed>  $manifest  Entries added to the archived manifest.
     */
    private function claimedCandidate(string $messageId, int $groupId = 1, array $manifest = []): object
    {
        $collection = DB::table('collections')->insertGetId(['collectionhash' => sha1(random_bytes(8), true), 'subject' => 'Movie '.$messageId,
            'fromname' => 'poster', 'totalfiles' => 1, 'groups_id' => $groupId, 'date' => '2026-10-11 12:00:00', 'filecheck' => 3, 'filesize' => 100000]);
        $binary = DB::table('binaries')->insertGetId(['collections_id' => $collection, 'binaryhash' => md5($messageId, true), 'name' => 'movie.mkv', 'totalparts' => 1]);
        DB::table('parts')->insert(['binaries_id' => $binary, 'partnumber' => 1, 'messageid' => $messageId, 'size' => 100000]);
        $repository = app(CandidateRepository::class);
        $repository->archive($collection);
        $claim = $repository->claim();
        if ($manifest !== []) {
            $store = app(ManifestStore::class);
            $path = $store->write($store->read($claim->manifest_path) + $manifest);
            $old = (int) DB::table('release_verification_candidates')->where('id', $claim->id)->value('manifest_bytes');
            DB::table('release_verification_candidates')->where('id', $claim->id)->update(['manifest_path' => $path,
                'manifest_hash' => substr($path, 0, 64), 'manifest_bytes' => $store->size($path)]);
            DB::table('release_verification_state')->increment('pending_bytes', $store->size($path) - $old);
            $claim = DB::table('release_verification_candidates')->where('id', $claim->id)->first();
        }

        return $claim;
    }

    /** @return array{articles: int, bytes: int, sampled_files: int, verified_file_names: list<string>, identity_hints: list<string>} */
    private function evidence(): array
    {
        return ['articles' => 1, 'bytes' => 256, 'sampled_files' => 1, 'verified_file_names' => ['movie.mkv'], 'identity_hints' => ['movie.mkv']];
    }

    /** @return array<string, mixed> One request item (also the single endpoint's whole body). */
    private function item(object $claim, ?string $leaseToken = null): array
    {
        return ['claim' => ['id' => (int) $claim->id, 'version' => (int) $claim->version, 'manifest_hash' => (string) $claim->manifest_hash,
            'lease_token' => $leaseToken ?? (string) $claim->lease_token], 'reason' => 'Sampled.', 'evidence' => $this->evidence()];
    }

    /** What the worker holds after spot-checking the claim: the stored manifest with its sampled evidence. */
    private function sampled(object $claim): VerificationResult
    {
        return new VerificationResult('sampled', 'Sampled.', SpotCheckVerifier::withEvidence(app(ManifestStore::class)->read($claim->manifest_path), $this->evidence()));
    }

    /** @param list<array<string, mixed>> $items */
    private function postBatch(array $items, array $headers = []): TestResponse
    {
        return $this->withServerVariables(self::PEER)->postJson('/internal/release-verification/publish-batch', ['protocol' => 1, 'items' => $items], $headers);
    }

    private function candidateRow(object $claim): object
    {
        return DB::table('release_verification_candidates')->where('id', $claim->id)->first();
    }

    /** NZB documents installed under the configured path. */
    private function installedNzbs(): int
    {
        $count = 0;
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(config('nntmux_settings.path_to_nzbs'), \FilesystemIterator::SKIP_DOTS)) as $file) {
            $count += $file->isFile() && str_ends_with($file->getFilename(), '.nzb.gz') ? 1 : 0;
        }

        return $count;
    }

    /** The ledger holds exactly the bytes of the manifests the candidate rows still own. */
    private function assertLedgerMatchesCandidates(): void
    {
        $this->assertSame((int) DB::table('release_verification_candidates')->sum('manifest_bytes'), (int) DB::table('release_verification_state')->value('pending_bytes'));
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Releases\Verification\CandidateRepository;
use App\Services\Releases\Verification\ManifestStore;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\ManifestCompressionProbe;

class ReleaseVerificationArchiveLockTest extends ReleaseVerificationCandidatesTest
{
    public function test_new_admissions_compress_before_shared_capacity_and_publish_after_it(): void
    {
        require_once __DIR__.'/../Fixtures/ManifestCompressionProbe.php';
        $capacityReads = 0;
        $compressions = 0;
        DB::listen(static function (QueryExecuted $query) use (&$capacityReads): void {
            if (str_starts_with($query->sql, 'select') && str_contains($query->sql, 'release_verification_state')) {
                $capacityReads++;
            }
        });
        $repository = app(CandidateRepository::class);
        $id = $this->preparationCollection('first@article');
        ManifestCompressionProbe::$onCompress = function () use (&$capacityReads, &$compressions): void {
            $compressions++;
            $this->assertSame(0, $capacityReads, 'Compression must precede shared capacity acquisition.');
            $this->assertSame([], glob(config('release_verification.storage_path').'/manifests/*.json.gz'));
        };
        try {
            $repository->archive($id);
        } finally {
            ManifestCompressionProbe::$onCompress = null;
        }
        $this->assertSame(1, $compressions);
        $candidate = DB::table('release_verification_candidates')->sole();
        $manifest = app(ManifestStore::class)->read($candidate->manifest_path);
        $this->assertSame('first@article', $manifest['files'][0]['segments'][0]['messageid']);
        $this->assertSame((int) $candidate->manifest_bytes, (int) DB::table('release_verification_state')->value('pending_bytes'));
        $this->assertSame(0, DB::table('collections')->count());
    }

    public function test_new_enqueue_prepares_without_creating_a_manifest_before_capacity(): void
    {
        require_once __DIR__.'/../Fixtures/ManifestCompressionProbe.php';
        $capacityReads = 0;
        $compressions = 0;
        DB::listen(static function (QueryExecuted $query) use (&$capacityReads): void {
            if (str_starts_with($query->sql, 'select') && str_contains($query->sql, 'release_verification_state')) {
                $capacityReads++;
            }
        });
        ManifestCompressionProbe::$onCompress = function () use (&$capacityReads, &$compressions): void {
            $compressions++;
            $this->assertSame(0, $capacityReads);
            $this->assertSame([], glob(config('release_verification.storage_path').'/manifests/*.json.gz'));
        };
        $manifest = ['identity' => sha1('import-preparation'), 'groups_id' => 1, 'files' => []];
        try {
            app(CandidateRepository::class)->enqueue($manifest);
        } finally {
            ManifestCompressionProbe::$onCompress = null;
        }
        $this->assertSame(1, $compressions);
        $candidate = DB::table('release_verification_candidates')->sole();
        $this->assertSame($manifest, app(ManifestStore::class)->read($candidate->manifest_path));
        $this->assertSame((int) $candidate->manifest_bytes, (int) DB::table('release_verification_state')->value('pending_bytes'));
    }

    public function test_existing_manifest_merge_compresses_once_under_locked_reconciliation(): void
    {
        require_once __DIR__.'/../Fixtures/ManifestCompressionProbe.php';
        $repository = app(CandidateRepository::class);
        $repository->archive($this->preparationCollection('first@article'));
        $id = $this->preparationCollection('second@article', 2);
        $capacityReads = 0;
        $compressions = 0;
        DB::listen(static function (QueryExecuted $query) use (&$capacityReads): void {
            if (str_starts_with($query->sql, 'select') && str_contains($query->sql, 'release_verification_state')) {
                $capacityReads++;
            }
        });
        ManifestCompressionProbe::$onCompress = function () use (&$capacityReads, &$compressions): void {
            $compressions++;
            $this->assertSame(1, $capacityReads);
        };
        try {
            $repository->archive($id);
        } finally {
            ManifestCompressionProbe::$onCompress = null;
        }
        $this->assertSame(1, $compressions, 'A known merge must not also prepare an unused snapshot.');
        $candidate = DB::table('release_verification_candidates')->sole();
        $manifest = app(ManifestStore::class)->read($candidate->manifest_path);
        $this->assertSame(['first@article', 'second@article'], array_column($manifest['files'][0]['segments'], 'messageid'));
        $this->assertSame((int) $candidate->manifest_bytes, (int) DB::table('release_verification_state')->value('pending_bytes'));
    }

    public function test_known_enqueue_replay_does_not_prepare_unused_bytes(): void
    {
        require_once __DIR__.'/../Fixtures/ManifestCompressionProbe.php';
        $repository = app(CandidateRepository::class);
        $manifest = ['identity' => sha1('replay'), 'groups_id' => 1, 'files' => []];
        $repository->enqueue($manifest);
        $old = DB::table('release_verification_candidates')->sole();
        $compressions = 0;
        ManifestCompressionProbe::$onCompress = static function () use (&$compressions): void {
            $compressions++;
        };
        try {
            $repository->enqueue($manifest);
        } finally {
            ManifestCompressionProbe::$onCompress = null;
        }
        $this->assertSame(0, $compressions);
        $candidate = DB::table('release_verification_candidates')->sole();
        $this->assertSame($old->version, $candidate->version);
        $this->assertSame($old->manifest_path, $candidate->manifest_path);
    }

    private function preparationCollection(string $messageId, int $part = 1): int
    {
        $id = DB::table('collections')->insertGetId(['collectionhash' => sha1('preparation', true),
            'subject' => 'Movie', 'fromname' => 'poster', 'totalfiles' => 1, 'groups_id' => 1,
            'date' => now()->format('Y-m-d H:i:s')]);
        $binary = DB::table('binaries')->insertGetId(['collections_id' => $id,
            'binaryhash' => md5('file', true), 'name' => 'file.rar', 'totalparts' => 2]);
        DB::table('parts')->insert(['binaries_id' => $binary, 'partnumber' => $part, 'messageid' => $messageId, 'size' => 5]);

        return $id;
    }

    public function test_missing_collection_does_not_request_shared_capacity(): void
    {
        $capacityReads = 0;
        DB::listen(static function (QueryExecuted $query) use (&$capacityReads): void {
            if (str_starts_with($query->sql, 'select') && str_contains($query->sql, 'release_verification_state')) {
                $capacityReads++;
            }
        });

        app(CandidateRepository::class)->archive(123);

        $this->assertSame(0, $capacityReads);
        $this->assertSame(0, DB::table('release_verification_candidates')->count());
    }

    public function test_changed_hash_between_lookup_and_lock_retains_headers(): void
    {
        $id = DB::table('collections')->insertGetId(['collectionhash' => sha1('original', true),
            'subject' => 'Movie', 'fromname' => 'poster', 'totalfiles' => 1, 'groups_id' => 1,
            'date' => now()->format('Y-m-d H:i:s')]);
        $changedHash = sha1('replacement', true);
        $changed = false;
        DB::listen(static function (QueryExecuted $query) use ($id, $changedHash, &$changed): void {
            if (! $changed && str_starts_with($query->sql, 'select') && str_contains($query->sql, 'collectionhash')
                && str_contains($query->sql, 'collections') && $query->bindings === [$id]) {
                $changed = true;
                DB::table('collections')->where('id', $id)->update(['collectionhash' => $changedHash]);
            }
        });

        app(CandidateRepository::class)->archive($id);

        $this->assertTrue($changed);
        $this->assertSame($changedHash, DB::table('collections')->where('id', $id)->value('collectionhash'));
        $this->assertSame(0, DB::table('release_verification_candidates')->count());
        $this->assertSame(0, (int) DB::table('release_verification_state')->value('pending_bytes'));
    }
}

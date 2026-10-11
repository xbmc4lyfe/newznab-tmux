<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Releases\Verification\ArticleSource;
use App\Services\Releases\Verification\CandidateRepository;
use App\Services\Releases\Verification\ManifestStore;
use App\Services\Releases\Verification\PayloadVerifier;
use App\Services\YencService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;

class LegacyVerificationInventoryTest extends ReleaseVerificationCandidatesTest
{
    public function test_legacy_observed_count_cannot_authorize_complete_publication(): void
    {
        Schema::table('collections', static function (Blueprint $table): void {
            $table->integer('releases_id')->nullable();
        });
        Schema::create('releases', static function (Blueprint $table): void {
            $table->id();
            $table->integer('nzbstatus');
        });
        DB::table('releases')->insert(['id' => 91, 'nzbstatus' => 0]);
        $id = $this->inventoryCollection(1, 'legacy@fixture', 1);
        DB::table('collections')->where('id', $id)->update(['filecheck' => 4, 'releases_id' => 91]);
        app(CandidateRepository::class)->archive($id);
        $candidate = DB::table('release_verification_candidates')->first();
        $this->assertNotNull($candidate);
        $manifest = app(ManifestStore::class)->read($candidate->manifest_path);
        $this->assertSame(0, $manifest['expected_files']);
        $source = new class implements ArticleSource
        {
            public function fetch(string $messageId): string
            {
                return (new YencService)->encode('hello', 'movie.mkv');
            }
        };
        config(['release_verification.free_bytes' => 0, 'release_verification.bytes_per_second' => 0]);
        $result = (new PayloadVerifier($source, (array) config('release_verification')))->verify(
            $manifest, config('release_verification.storage_path').'/scratch/legacy-inventory', static function (): void {},
        );
        $this->assertSame('unknown', $result->verdict);
        $this->assertStringContainsString('inventory', $result->reason);
        $this->assertSame(1, DB::table('releases')->count());
        $this->assertSame(0, (int) DB::table('releases')->value('nzbstatus'));
    }

    #[DataProvider('inventoryTransitions')]
    public function test_later_inventory_evidence_preserves_known_bounds(int $first, int $second, bool $conflict, int $expected): void
    {
        $repository = app(CandidateRepository::class);
        $repository->archive($this->inventoryCollection($first, 'first@fixture', 1, 2));
        $before = DB::table('release_verification_candidates')->first();
        $repository->archive($this->inventoryCollection($second, 'second@fixture', 2, 2));
        $after = DB::table('release_verification_candidates')->first();
        $manifest = app(ManifestStore::class)->read($after->manifest_path);
        $this->assertSame($conflict, $manifest['conflict']);
        $this->assertSame($expected, $manifest['expected_files']);
        $this->assertCount(2, $manifest['files'][0]['segments']);
        $this->assertGreaterThan($before->version, $after->version);
    }

    /** @return array<string, array{int, int, bool, int}> */
    public static function inventoryTransitions(): array
    {
        return ['unknown becomes known' => [0, 2, false, 2],
            'unknown cannot erase known' => [2, 0, false, 2],
            'contradictory known counts stay blocked' => [2, 1, true, 2]];
    }

    private function inventoryCollection(int $expected, string $messageId, int $part, int $parts = 1): int
    {
        $id = DB::table('collections')->insertGetId(['collectionhash' => sha1('legacy-inventory', true), 'subject' => 'Movie',
            'fromname' => 'poster', 'totalfiles' => $expected, 'groups_id' => 1, 'date' => now()->format('Y-m-d H:i:s')]);
        $binary = DB::table('binaries')->insertGetId(['collections_id' => $id, 'binaryhash' => md5('movie', true),
            'name' => 'movie.mkv', 'totalparts' => $parts]);
        DB::table('parts')->insert(['binaries_id' => $binary, 'partnumber' => $part, 'messageid' => $messageId, 'size' => 5]);

        return $id;
    }
}

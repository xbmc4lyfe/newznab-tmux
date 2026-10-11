<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Monitoring\Collectors\ReleaseVerificationCollector;
use App\Services\Monitoring\Prometheus\TextFormatter;
use App\Services\NNTP\NNTPService;
use App\Services\Nzb\NzbService;
use App\Services\Releases\ReleaseManagementService;
use App\Services\Releases\Verification\CandidateRepository;
use App\Services\Releases\Verification\ManifestStore;
use App\Services\Releases\Verification\NzbManifestReader;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ReleaseVerificationCommandsTest extends ReleaseVerificationCandidatesTest
{
    public function test_worker_is_discovered_and_does_not_publish_when_queue_is_empty(): void
    {
        $this->artisan('releases:verify-candidates', ['--limit' => 1])->assertSuccessful();
    }

    public function test_expiry_releases_manifest_capacity_but_never_expires_active_lease(): void
    {
        $now = now();
        $store = app(ManifestStore::class);
        $name = $store->write(['files' => [], 'expected_files' => 0]);
        $bytes = filesize($store->path($name));
        DB::table('release_verification_state')->update(['pending_bytes' => $bytes]);
        $id = DB::table('release_verification_candidates')->insertGetId(['identity' => sha1('expired'), 'groups_id' => 1,
            'manifest_path' => $name, 'manifest_bytes' => $bytes, 'manifest_hash' => substr($name, 0, 64),
            'last_unique_at' => $now->copy()->subHours(49), 'next_attempt_at' => $now, 'lease_until' => $now->copy()->addMinute()]);
        app(CandidateRepository::class)->expire();
        $this->assertSame('pending', DB::table('release_verification_candidates')->where('id', $id)->value('status'));
        $this->travel(2)->minutes();
        app(CandidateRepository::class)->expire();
        $this->assertSame('expired', DB::table('release_verification_candidates')->where('id', $id)->value('status'));
        $this->assertSame(0, (int) DB::table('release_verification_state')->value('pending_bytes'));
        $this->assertFileDoesNotExist($store->path($name));
    }

    public function test_trailing_article_count_is_not_a_declared_file_inventory(): void
    {
        $xml = '<nzb><file subject="movie.mkv (1/10)"><groups><group>alt.test</group></groups><segments><segment bytes="5" number="1">a@b</segment></segments></file></nzb>';
        $manifest = app(NzbManifestReader::class)->read($xml, (object) ['groups_id' => 1, 'searchname' => 'Movie', 'fromname' => 'poster', 'postdate' => '2026-10-11 12:00:00']);
        $this->assertSame(0, $manifest['expected_files']);
        $this->assertSame(10, $manifest['files'][0]['totalparts']);
    }

    public function test_monitoring_exports_verifier_capacity_and_throughput(): void
    {
        $root = (string) config('release_verification.storage_path');
        mkdir($root, 0700, true);
        file_put_contents($root.'/status.json', '{"scratch_bytes":1234,"verified_bytes_per_second":5678}');
        $families = [...app(ReleaseVerificationCollector::class)->collect()];
        $text = (new TextFormatter)->format($families);
        $this->assertStringContainsString('nntmux_release_verification_scratch_bytes 1234', $text);
        $this->assertStringContainsString('nntmux_release_verification_verified_bytes_per_second 5678', $text);
    }

    public function test_audit_dry_run_keeps_unknown_release_even_with_delete_option(): void
    {
        Schema::create('releases', function (Blueprint $table): void {
            $table->id();
            $table->string('guid');
            $table->string('searchname');
            $table->string('fromname');
            $table->integer('groups_id');
            $table->string('postdate');
        });
        DB::table('releases')->insert(['id' => 1, 'guid' => 'audit-guid', 'searchname' => 'Movie', 'fromname' => 'poster', 'groups_id' => 1, 'postdate' => '2026-10-11 12:00:00']);
        $xml = '<nzb><file subject="Release [1/1] &quot;movie.mkv&quot; (1/1)"><groups><group>alt.test</group></groups><segments><segment bytes="5" number="1">a@b</segment></segments></file></nzb>';
        $this->mock(NzbService::class, function ($mock) use ($xml): void {
            $mock->shouldReceive('readNzbContents')->once()->with('audit-guid')->andReturn($xml);
        });
        $this->mock(NNTPService::class, function ($mock): void {
            $mock->shouldReceive('doConnect')->once()->with(false)->andReturn(true);
            $mock->shouldReceive('verificationArticle')->once()->andReturn('unsupported plain text');
            $mock->shouldReceive('doQuit')->andReturn(null);
        });
        $this->mock(ReleaseManagementService::class, function ($mock): void {
            $mock->shouldNotReceive('deleteSingle');
        });
        $this->artisan('releases:verify-repairable', ['--dry-run' => true, '--delete' => true, '--limit' => 1])->assertSuccessful();
        $this->assertSame(1, DB::table('releases')->count());
        $this->assertSame(1, (int) DB::table('release_verification_state')->value('audit_cursor'));
        $report = file_get_contents((string) config('release_verification.storage_path').'/audit.jsonl');
        $this->assertStringContainsString('"verdict":"unknown"', $report);
    }

    public function test_existing_nzb_keeps_declared_part_and_file_counts(): void
    {
        $xml = '<nzb><file poster="poster" date="1791720000" subject="Release [1/2] &quot;movie.mkv&quot; (1/10)"><groups><group>alt.test</group></groups><segments><segment bytes="5" number="1">a@b</segment></segments></file></nzb>';
        $manifest = app(NzbManifestReader::class)->read($xml, (object) ['groups_id' => 1, 'searchname' => 'Movie', 'fromname' => 'poster', 'postdate' => '2026-10-11 12:00:00']);
        $this->assertSame(2, $manifest['expected_files']);
        $this->assertSame(10, $manifest['files'][0]['totalparts']);
        $this->assertCount(1, $manifest['files'][0]['segments']);
    }
}

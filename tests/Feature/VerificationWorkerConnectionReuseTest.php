<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Facades\Search;
use App\Services\NNTP\NNTPService;
use App\Services\Nzb\NzbService;
use App\Services\ReleaseCleaningService;
use App\Services\Releases\Verification\CandidateRepository;
use App\Services\Releases\Verification\ReleaseMetadataResolver;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\Fixtures\YencArticles;

class VerificationWorkerConnectionReuseTest extends ReleaseVerificationCandidatesTest
{
    public function test_worker_reuses_one_authenticated_connection_for_separate_candidates(): void
    {
        $articles = $this->publicationFixture(2, 2);
        $connections = 0;
        $requests = [];
        $this->mock(NNTPService::class, static function ($mock) use ($articles, &$connections, &$requests): void {
            $mock->shouldReceive('doConnect')->with(false)->andReturnUsing(static function () use (&$connections): bool {
                $connections++;

                return true;
            });
            $mock->shouldReceive('verificationArticle')->andReturnUsing(static function (string $id, int $limit) use ($articles, &$requests): string {
                $requests[] = $id;

                return $articles[$id];
            });
            $mock->shouldReceive('doQuit')->andReturn(null);
        });

        $this->artisan('releases:verify-candidates', ['--limit' => 2])->assertSuccessful();

        $this->assertSame(2, DB::table('release_verification_candidates')->where('status', 'sampled')->count());
        $this->assertSame(2, DB::table('releases')->count());
        $this->assertCount(2, $requests);
        $this->assertSame(1, $connections, 'A worker must retain its authenticated session between candidates.');
        foreach (DB::table('releases')->get(['guid']) as $index => $release) {
            $xml = app(NzbService::class)->readNzbContents($release->guid);
            $this->assertStringContainsString('candidate'.($index + 1).'-1@example', $xml);
            $this->assertStringContainsString('candidate'.($index + 1).'-2@example', $xml);
        }
    }

    public function test_disconnected_transport_reconnects_for_the_next_candidate(): void
    {
        $articles = $this->publicationFixture(3, 2);
        $connections = 0;
        $requests = 0;
        $connected = false;
        $this->mock(NNTPService::class, static function ($mock) use ($articles, &$connections, &$requests, &$connected): void {
            $mock->shouldReceive('doConnect')->with(false)->andReturnUsing(static function () use (&$connections, &$connected): bool {
                $connections++;
                $connected = true;

                return true;
            });
            $mock->shouldReceive('verificationArticle')->andReturnUsing(static function (string $id, int $limit) use ($articles, &$requests, &$connected): string {
                if (++$requests === 2) {
                    $connected = false;
                    throw new RuntimeException('Fixture transport disconnected.');
                }

                return $articles[$id];
            });
            $mock->shouldReceive('isConnected')->andReturnUsing(static function () use (&$connected): bool {
                return $connected;
            });
            $mock->shouldReceive('doQuit')->andReturn(null);
        });

        $this->artisan('releases:verify-candidates', ['--limit' => 3])->assertSuccessful();

        $this->assertSame(2, DB::table('release_verification_candidates')->where('status', 'sampled')->count());
        $failed = DB::table('release_verification_candidates')->where('status', 'unknown')->sole();
        $this->assertNull($failed->release_id);
        $this->assertGreaterThan(now()->timestamp, strtotime($failed->next_attempt_at));
        $this->assertSame(2, $connections, 'Only a disconnected transport requires a new authenticated session.');
    }

    public function test_transfer_budget_is_fresh_for_each_candidate_on_a_reused_connection(): void
    {
        $articles = $this->publicationFixture(2, 2);
        $budget = max(array_map(strlen(...), $articles));
        config(['release_verification.spot_check_bytes' => $budget, 'release_verification.spot_check_article_bytes' => $budget]);
        $limits = [];
        $this->mock(NNTPService::class, static function ($mock) use ($articles, &$limits): void {
            $mock->shouldReceive('doConnect')->with(false)->andReturn(true);
            $mock->shouldReceive('verificationArticle')->andReturnUsing(static function (string $id, int $limit) use ($articles, &$limits): string {
                $limits[] = $limit;

                return $articles[$id];
            });
            $mock->shouldReceive('doQuit')->andReturn(null);
        });

        $this->artisan('releases:verify-candidates', ['--limit' => 2])->assertSuccessful();

        $this->assertSame([$budget, $budget], $limits);
        $this->assertSame(2, DB::table('release_verification_candidates')->where('status', 'sampled')->count());
    }

    /** @return array<string, string> */
    private function publicationFixture(int $candidates, int $published): array
    {
        config(['nntmux.release_dedupe_enabled' => false, 'release_verification.spot_check_articles' => 1,
            'release_verification.free_bytes' => 0, 'nntmux_settings.path_to_nzbs' => config('release_verification.storage_path').'/nzbs']);
        DB::table('release_verification_scratch_budget')->where('id', 1)->update(['initialized' => true]);
        File::ensureDirectoryExists(config('nntmux_settings.path_to_nzbs'));
        Schema::create('releases', static function (Blueprint $table): void {
            $table->id();
            foreach (['name', 'searchname', 'guid', 'leftguid', 'fromname', 'postdate', 'adddate'] as $column) {
                $table->string($column);
            }
            foreach (['totalpart', 'groups_id', 'size', 'passwordstatus', 'haspreview', 'categories_id', 'nfostatus', 'nzbstatus', 'isrenamed', 'iscategorized', 'predb_id'] as $column) {
                $table->integer($column);
            }
        });
        Schema::create('release_regexes', static function (Blueprint $table): void {
            $table->integer('releases_id');
            $table->integer('collection_regex_id');
            $table->integer('naming_regex_id');
        });
        Schema::create('releases_groups', static function (Blueprint $table): void {
            $table->integer('releases_id');
            $table->integer('groups_id');
        });
        $this->app->instance(ReleaseMetadataResolver::class, new class(app(ReleaseCleaningService::class)) extends ReleaseMetadataResolver
        {
            public function resolve(array $manifest): array
            {
                return ['name' => $manifest['subject'], 'searchname' => $manifest['subject'], 'fromname' => 'poster',
                    'predb_id' => 0, 'isrenamed' => 1, 'categories_id' => 2000, 'naming_regex_id' => 0];
            }
        });
        Search::shouldReceive('updateRelease')->times($published);
        $articles = [];
        foreach (range(1, $candidates) as $candidate) {
            $segments = [];
            foreach ([1 => 'hello', 2 => 'world'] as $part => $payload) {
                $id = 'candidate'.$candidate.'-'.$part.'@example';
                $articles[$id] = YencArticles::part($payload, 'movie'.$candidate.'.mkv', 10, $part, ($part - 1) * 5 + 1, 2);
                $segments[] = ['number' => $part, 'messageid' => $id, 'bytes' => 5];
            }
            app(CandidateRepository::class)->enqueue(['identity' => sha1('candidate'.$candidate), 'subject' => 'Release '.$candidate,
                'fromname' => 'poster', 'date' => '2026-10-13 12:00:00', 'groups_id' => 1, 'groups' => ['alt.test'],
                'expected_files' => 1, 'regex_id' => 0, 'files' => [['key' => 'file', 'subject' => 'movie'.$candidate.'.mkv',
                    'totalparts' => 2, 'segments' => $segments]]]);
        }

        return $articles;
    }
}

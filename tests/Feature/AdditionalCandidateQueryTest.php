<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\AdditionalProcessing\AdditionalCandidateQuery;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PDO;
use Tests\TestCase;

class AdditionalCandidateQueryTest extends TestCase
{
    private string $databasePath;

    /**
     * @var array<string, string|false>
     */
    private array $originalEnvironment = [];

    public function createApplication()
    {
        $this->databasePath = sys_get_temp_dir().'/nntmux-additional-candidate-query-test.sqlite';

        $this->originalEnvironment = [
            'APP_ENV' => getenv('APP_ENV'),
            'DB_CONNECTION' => getenv('DB_CONNECTION'),
            'DB_DATABASE' => getenv('DB_DATABASE'),
        ];

        if (file_exists($this->databasePath)) {
            unlink($this->databasePath);
        }

        $pdo = new PDO('sqlite:'.$this->databasePath);

        $this->setEnvironmentValue('APP_ENV', 'testing');
        $this->setEnvironmentValue('DB_CONNECTION', 'sqlite');
        $this->setEnvironmentValue('DB_DATABASE', $this->databasePath);

        $app = require __DIR__.'/../../bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => $this->databasePath,
        ]);

        DB::purge();
        DB::reconnect();

        $this->createSchema();
    }

    protected function tearDown(): void
    {
        if ($this->databasePath !== '' && file_exists($this->databasePath)) {
            unlink($this->databasePath);
        }

        parent::tearDown();

        foreach ($this->originalEnvironment as $key => $value) {
            $this->setEnvironmentValue($key, $value === false ? null : $value);
        }
    }

    public function test_bucket_chars_preserve_alphabetic_guid_buckets(): void
    {
        DB::table('categories')->insert([
            ['id' => 1, 'disablepreview' => 0],
            ['id' => 2, 'disablepreview' => 1],
        ]);

        DB::table('releases')->insert([
            $this->releaseRow(1, '0'),
            $this->releaseRow(2, '9'),
            $this->releaseRow(3, 'a'),
            $this->releaseRow(4, 'a'),
            $this->releaseRow(5, 'f'),
            $this->releaseRow(6, 'b', categoriesId: 2),
        ]);

        $chars = AdditionalCandidateQuery::bucketChars();
        sort($chars);

        $this->assertSame(['0', '9', 'a', 'f'], $chars);
    }

    public function test_bucket_chars_skip_active_claims_but_include_stale_claims(): void
    {
        DB::table('categories')->insert([
            ['id' => 1, 'disablepreview' => 0],
        ]);

        DB::table('releases')->insert([
            $this->releaseRow(1, 'a', claimedAt: now()),
            $this->releaseRow(2, 'b', claimedAt: now()->subSeconds(301)),
            $this->releaseRow(3, 'c'),
        ]);

        $chars = AdditionalCandidateQuery::bucketChars();
        sort($chars);

        $this->assertSame(['b', 'c'], $chars);
    }

    public function test_monitor_builder_can_include_claimed_releases_while_available_builder_excludes_them(): void
    {
        DB::table('categories')->insert([
            ['id' => 1, 'disablepreview' => 0],
        ]);

        DB::table('releases')->insert([
            $this->releaseRow(1, 'a', claimedAt: now()),
            $this->releaseRow(2, 'b'),
        ]);

        $this->assertSame(1, AdditionalCandidateQuery::baseBuilder()->count());
        $this->assertSame(2, AdditionalCandidateQuery::baseBuilder(includeClaimed: true)->count());
    }

    public function test_backlog_counts_are_aggregated_by_bucket_in_one_shape(): void
    {
        DB::table('categories')->insert(['id' => 1, 'disablepreview' => 0]);
        DB::table('releases')->insert([
            $this->releaseRow(1, 'a'),
            $this->releaseRow(2, 'a', claimedAt: now()),
            $this->releaseRow(3, 'b', claimedAt: now()->subSeconds(301)),
        ]);

        $this->assertSame([
            ['bucket' => 'a', 'total' => 2, 'available' => 1],
            ['bucket' => 'b', 'total' => 1, 'available' => 1],
        ], AdditionalCandidateQuery::bucketBacklog());
        $this->assertSame(['total' => 3, 'available' => 2], AdditionalCandidateQuery::backlogCounts());
        $this->assertSame([
            ['bucket' => 'a', 'count' => 1],
            ['bucket' => 'b', 'count' => 1],
        ], AdditionalCandidateQuery::availableBucketCounts());
    }

    public function test_claim_batch_excludes_active_claims_and_recovers_stale_claims(): void
    {
        DB::table('categories')->insert([
            ['id' => 1, 'disablepreview' => 0],
        ]);

        DB::table('releases')->insert([
            $this->releaseRow(1, 'a', postdate: '2026-07-12 10:00:00'),
            $this->releaseRow(2, 'a', postdate: '2026-07-12 09:00:00'),
        ]);

        $first = AdditionalCandidateQuery::claimBatch('a', 1, 'token-one', columns: ['id']);
        $this->assertSame([1], $first->pluck('id')->all());

        $second = AdditionalCandidateQuery::claimBatch('a', 10, 'token-two', columns: ['id']);
        $this->assertSame([2], $second->pluck('id')->all());

        DB::table('releases')
            ->where('id', 1)
            ->update(['additional_pp_claimed_at' => now()->subSeconds(301)]);

        $third = AdditionalCandidateQuery::claimBatch('a', 10, 'token-three', columns: ['id']);
        $this->assertSame([1], $third->pluck('id')->all());
    }

    public function test_a_partial_awaiting_late_articles_is_not_claimed_until_settled(): void
    {
        DB::table('categories')->insert(['id' => 1, 'disablepreview' => 0]);
        DB::table('releases')->insert([$this->releaseRow(1, 'a', postdate: '2026-07-12 10:00:00')]);
        DB::table('releases')->where('id', 1)->update(['late_article_candidate_id' => 77]);

        $this->assertSame([], AdditionalCandidateQuery::claimBatch('a', 10, 'token-one', columns: ['id'])->pluck('id')->all(),
            'Late articles may still replace its NZB.');

        DB::table('releases')->where('id', 1)->update(['late_article_candidate_id' => null]);
        $this->assertSame([1], AdditionalCandidateQuery::claimBatch('a', 10, 'token-two', columns: ['id'])->pluck('id')->all());
    }

    public function test_a_queued_archive_retry_is_not_a_candidate_until_it_is_due(): void
    {
        DB::table('categories')->insert(['id' => 1, 'disablepreview' => 0]);
        DB::table('releases')->insert([
            $this->releaseRow(1, 'a', postdate: '2026-07-12 10:00:00', archiveRetryAt: now()->addMinute()),
            $this->releaseRow(2, 'a', postdate: '2026-07-12 09:00:00'),
            $this->releaseRow(3, 'a', postdate: '2026-07-12 08:00:00', archiveRetryAt: now()->subMinute()),
        ]);

        $this->assertSame(['total' => 2, 'available' => 2], AdditionalCandidateQuery::backlogCounts());
        $this->assertSame([2, 3], AdditionalCandidateQuery::claimBatch('a', 10, 'token-one', columns: ['id'])->pluck('id')->all());

        $this->travel(61)->seconds();

        $this->assertSame([1], AdditionalCandidateQuery::claimBatch('a', 10, 'token-two', columns: ['id'])->pluck('id')->all());
    }

    public function test_disabling_password_checks_keeps_pending_media_in_selection_and_claims(): void
    {
        config(['nntmux_settings.check_passworded_rars' => false]);
        DB::table('categories')->insert(['id' => 1, 'disablepreview' => 0]);
        DB::table('releases')->insert([
            $this->releaseRow(1, 'a'),
            array_replace($this->releaseRow(2, 'a'), ['passwordstatus' => 0]),
            array_replace($this->releaseRow(3, 'b'), ['passwordstatus' => 1]),
            array_replace($this->releaseRow(4, 'b'), ['passwordstatus' => 0, 'haspreview' => 0]),
        ]);

        $this->assertSame([1, 2], AdditionalCandidateQuery::baseBuilder()->orderBy('r.id')->pluck('r.id')->all());
        $this->assertSame(['total' => 2, 'available' => 2], AdditionalCandidateQuery::backlogCounts());
        $this->assertSame(['a'], AdditionalCandidateQuery::bucketChars());
        $this->assertSame([1, 2], AdditionalCandidateQuery::claimBatch('a', 10, 'disabled-password-claim', columns: ['id'])->pluck('id')->all());
        $this->assertSame(['total' => 2, 'available' => 0], AdditionalCandidateQuery::backlogCounts());
    }

    public function test_enabled_password_checks_keep_the_existing_unchecked_only_gate(): void
    {
        config(['nntmux_settings.check_passworded_rars' => true]);
        DB::table('categories')->insert(['id' => 1, 'disablepreview' => 0]);
        DB::table('releases')->insert([
            $this->releaseRow(1, 'a'),
            array_replace($this->releaseRow(2, 'a'), ['passwordstatus' => 0]),
            array_replace($this->releaseRow(3, 'b'), ['passwordstatus' => 1]),
        ]);

        $this->assertSame([1], AdditionalCandidateQuery::baseBuilder()->pluck('r.id')->all());
        $this->assertSame([1], AdditionalCandidateQuery::claimBatch('a', 10, 'enabled-password-claim', columns: ['id'])->pluck('id')->all());
    }

    public function test_password_disabled_candidates_still_obey_other_admission_gates(): void
    {
        config(['nntmux_settings.check_passworded_rars' => false]);
        DB::table('categories')->insert([
            ['id' => 1, 'disablepreview' => 0],
            ['id' => 2, 'disablepreview' => 1],
        ]);
        DB::table('releases')->insert([
            array_replace($this->releaseRow(1, 'a'), ['passwordstatus' => 0]),
            array_replace($this->releaseRow(2, 'a', categoriesId: 2), ['passwordstatus' => 0]),
            array_replace($this->releaseRow(3, 'a'), ['passwordstatus' => 0, 'nzbstatus' => 0]),
            array_replace($this->releaseRow(4, 'a'), ['passwordstatus' => 0, 'size' => 1048576]),
            array_replace($this->releaseRow(5, 'a'), ['passwordstatus' => 0, 'size' => AdditionalCandidateQuery::DEFAULT_MAX_SIZE_BYTES]),
            array_replace($this->releaseRow(6, 'a', claimedAt: now()), ['passwordstatus' => 0]),
            array_replace($this->releaseRow(7, 'a', archiveRetryAt: now()->addMinute()), ['passwordstatus' => 0]),
            array_replace($this->releaseRow(8, 'a', claimedAt: now()->subSeconds(301)), ['passwordstatus' => 0]),
            array_replace($this->releaseRow(9, 'a', categoriesId: 999), ['passwordstatus' => 0]),
        ]);

        $this->assertSame([1, 8], AdditionalCandidateQuery::baseBuilder()->orderBy('r.id')->pluck('r.id')->all());
        $this->assertSame([1, 8], AdditionalCandidateQuery::claimBatch('a', 10, 'guarded-password-claim', columns: ['id'])->pluck('id')->all());
    }

    public function test_disabled_mode_merges_bounded_status_prefixes_in_global_order(): void
    {
        config(['nntmux_settings.check_passworded_rars' => false]);
        DB::table('categories')->insert(['id' => 1, 'disablepreview' => 0]);
        $rows = [];
        for ($id = 1; $id <= 24; $id++) {
            $rows[] = array_replace($this->releaseRow($id, 'a'), [
                'passwordstatus' => $id % 2 === 0 ? 0 : -1,
                'postdate' => $id <= 2 ? null : ($id <= 6 ? '2026-07-13 00:00:00' : '2026-07-12 00:00:00'),
            ]);
        }
        DB::table('releases')->insert($rows);

        $this->assertSame([3, 5, 6], AdditionalCandidateQuery::claimBatch('a', 3, 'first', columns: ['id'], excludedReleaseIds: [4])->pluck('id')->all());
        $this->assertSame(range(7, 24), AdditionalCandidateQuery::claimBatch('a', 18, 'next', columns: ['id'], excludedReleaseIds: [4])->pluck('id')->all());
        $this->assertSame([1, 2], AdditionalCandidateQuery::claimBatch('a', 10, 'null-dates', columns: ['id'], excludedReleaseIds: [4])->pluck('id')->all());
    }

    public function test_disabled_mode_completed_media_stays_out_and_deferred_media_returns_once_due(): void
    {
        config(['nntmux_settings.check_passworded_rars' => false]);
        DB::table('categories')->insert(['id' => 1, 'disablepreview' => 0]);
        DB::table('releases')->insert(array_replace($this->releaseRow(1, 'a'), ['passwordstatus' => 0]));
        $this->assertSame([1], AdditionalCandidateQuery::claimBatch('a', 1, 'first', columns: ['id'])->pluck('id')->all());
        DB::table('releases')->where('id', 1)->update(['archive_retry_at' => now()->addMinute()]);
        AdditionalCandidateQuery::clearClaim(1, 'first');
        $this->assertSame([], AdditionalCandidateQuery::claimBatch('a', 1, 'early', columns: ['id'])->pluck('id')->all());
        $this->travel(61)->seconds();
        $this->assertSame([1], AdditionalCandidateQuery::claimBatch('a', 1, 'retry', columns: ['id'])->pluck('id')->all());
        DB::table('releases')->where('id', 1)->update(['haspreview' => 0]);
        AdditionalCandidateQuery::clearClaim(1, 'retry');
        $this->assertFalse(AdditionalCandidateQuery::hasAnyCandidate());
    }

    /**
     * @return array<string, mixed>
     */
    private function releaseRow(
        int $id,
        string $leftguid,
        int $categoriesId = 1,
        ?\DateTimeInterface $claimedAt = null,
        string $postdate = '2026-07-12 00:00:00',
        ?\DateTimeInterface $archiveRetryAt = null,
    ): array {
        return [
            'id' => $id,
            'guid' => $leftguid.'-guid-'.$id,
            'leftguid' => $leftguid,
            'passwordstatus' => -1,
            'haspreview' => -1,
            'nzbstatus' => 1,
            'categories_id' => $categoriesId,
            'size' => 2 * 1048576,
            'postdate' => $postdate,
            'additional_pp_claimed_at' => $claimedAt?->format('Y-m-d H:i:s'),
            'additional_pp_claim_token' => $claimedAt === null ? null : 'claimed',
            'archive_retry_at' => $archiveRetryAt?->format('Y-m-d H:i:s'),
        ];
    }

    private function setEnvironmentValue(string $key, ?string $value): void
    {
        if ($value === null) {
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);

            return;
        }

        putenv($key.'='.$value);
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }

    private function createSchema(): void
    {

        Schema::dropIfExists('releases');
        Schema::dropIfExists('categories');

        Schema::create('categories', function (Blueprint $table): void {
            $table->unsignedInteger('id')->primary();
            $table->boolean('disablepreview')->default(false);
        });

        Schema::create('releases', function (Blueprint $table): void {
            $table->unsignedInteger('id')->primary();
            $table->string('guid');
            $table->char('leftguid', 1);
            $table->integer('passwordstatus');
            $table->integer('haspreview');
            $table->integer('nzbstatus');
            $table->unsignedInteger('categories_id');
            $table->unsignedBigInteger('size');
            $table->dateTime('postdate')->nullable();
            $table->timestamp('additional_pp_claimed_at')->nullable();
            $table->string('additional_pp_claim_token', 64)->nullable();
            $table->timestamp('archive_retry_at')->nullable();
            $table->unsignedBigInteger('late_article_candidate_id')->nullable();
        });
    }
}

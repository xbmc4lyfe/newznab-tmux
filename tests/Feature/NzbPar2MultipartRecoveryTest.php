<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Par2RenameOutcome;
use App\Events\ReleaseNameFixed;
use App\Facades\Search;
use App\Models\Category;
use App\Models\Predb;
use App\Models\Release;
use App\Services\AdditionalProcessing\ReleaseSearchSyncCoordinator;
use App\Services\AdditionalProcessing\State\PersistenceMetricsCollector;
use App\Services\Categorization\CategorizationService;
use App\Services\NameFixing\NameFixingService;
use App\Services\NameFixing\ReleaseUpdateService;
use App\Services\NNTP\BoundedArticleResult;
use App\Services\NNTP\NNTPService;
use App\Services\Nzb\NzbContentsService;
use App\Services\Nzb\NzbParserService;
use App\Services\Par2Processor;
use App\Services\PostProcessService;
use App\Services\Yenc\NativePayloadDecoder;
use App\Services\Yenc\YencPart;
use App\Services\YencService;
use App\Support\Par2RenameProgress;
use dariusiii\rarinfo\Par2Info;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use SimpleXMLElement;
use Tests\Fixtures\YencArticles;
use Tests\Support\Par2RenameTestDatabase;
use Tests\TestCase;

final class NzbPar2MultipartRecoveryTest extends TestCase
{
    use Par2RenameTestDatabase;

    private const TITLE = 'Little.Fockers.2010.UHD.BluRay.1080p.DDP.5.1.Atmos.DV.HDR.x265-faBR';

    /** @var list<string> */
    private array $fetched = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->createPar2RenameSchema();
        config(['tmux.fix_names.cache_store' => 'array', 'tmux.fix_names.par2_max_probes' => 1, 'tmux.fix_names.par2_max_probe_bytes' => 2097152, 'tmux.fix_names.par2_max_state_bytes' => 8388608]);
        Cache::store('array')->flush();
        Schema::table('release_files', static function (Blueprint $table): void {
            $table->bigInteger('size');
            $table->string('created_at')->nullable();
            $table->bigInteger('updated_at')->nullable();
            $table->boolean('passworded')->default(false);
            $table->unique(['releases_id', 'name']);
        });
        Schema::table('par_hashes', static fn (Blueprint $table) => $table->unique(['releases_id', 'hash']));
        Search::shouldReceive('updateRelease')->andReturnNull();
    }

    public function test_full_controller_resumes_main_first_fixture_across_objects_and_recovers_exact_large_title(): void
    {
        $release = $this->release();
        [$main, $descriptors] = $this->metadata();
        $xml = $this->nzb([$main, $descriptors]);
        $first = $this->service($xml, ['one' => $this->part($main, 1, 1, strlen($main.$descriptors))]);

        self::assertSame(Par2RenameOutcome::Deferred, $first->checkPar2Result($release->guid, $release->id, 1, 1, 0));
        self::assertSame(['candidates_probed' => 1, 'articles_received' => 1], $first->par2Stats());
        self::assertSame(1, DB::table('par2_rename_candidate_progress')->value('next_segment'));
        self::assertSame(0, $release->fresh()->proc_par2);
        $second = $this->service($xml, ['two' => $this->part($descriptors, 2, strlen($main) + 1, strlen($main.$descriptors))]);

        self::assertSame(Par2RenameOutcome::Renamed, $second->checkPar2Result($release->guid, $release->id, 1, 1, 0));
        self::assertSame(['one', 'two'], $this->fetched);
        self::assertSame(self::TITLE, $release->fresh()->searchname);
        self::assertSame(1, $release->fresh()->proc_par2);
        self::assertSame(12884902011, DB::table('release_files')->where('name', self::TITLE.'.mkv')->value('size'));
        self::assertSame(10, $release->fresh()->rarinnerfilecount);
        self::assertSame(0, DB::table('par2_rename_candidate_progress')->count());
        DB::table('predb')->insert(['id' => 9, 'title' => self::TITLE]);
        ob_start();
        try {
            Predb::checkPre();
        } finally {
            ob_end_clean();
        }
        self::assertSame(9, $release->fresh()->predb_id);
    }

    #[DataProvider('packetSplits')]
    public function test_full_controller_retains_split_headers_and_bodies(int $split): void
    {
        $release = $this->release();
        $bytes = implode('', $this->metadata());
        $parts = [substr($bytes, 0, $split), substr($bytes, $split)];
        $xml = $this->nzb($parts);
        $first = $this->service($xml, ['one' => $this->part($parts[0], 1, 1, strlen($bytes))]);

        self::assertSame(Par2RenameOutcome::Deferred, $first->checkPar2Result($release->guid, $release->id, 1, 1, 0));
        $second = $this->service($xml, ['two' => $this->part($parts[1], 2, $split + 1, strlen($bytes))]);

        self::assertSame(Par2RenameOutcome::Renamed, $second->checkPar2Result($release->guid, $release->id, 1, 1, 0));
        self::assertSame(['one', 'two'], $this->fetched);
        self::assertSame(self::TITLE, $release->fresh()->searchname);
    }

    /** @return array<string, array{int}> */
    public static function packetSplits(): array
    {
        return ['marker' => [3], 'length' => [12], 'packet type' => [52], 'Main body' => [80], 'descriptor body' => [360]];
    }

    public function test_later_failure_preserves_committed_prefix_and_metrics_for_a_fresh_process_retry(): void
    {
        $release = $this->release();
        [$main, $rest] = $this->metadata();
        $xml = $this->nzb([$main, $rest]);
        $this->service($xml, ['one' => BoundedArticleResult::success($main)])->checkPar2Result($release->guid, $release->id, 1, 1, 0);
        $saved = DB::table('par2_rename_candidate_progress')->value('accumulator_state');
        $failed = $this->service($xml, ['two' => BoundedArticleResult::failure('temporary provider failure')]);

        self::assertSame(Par2RenameOutcome::Retryable, $failed->checkPar2Result($release->guid, $release->id, 1, 1, 0));
        self::assertSame(['candidates_probed' => 1, 'articles_received' => 0], $failed->par2Stats());
        self::assertSame($saved, DB::table('par2_rename_candidate_progress')->value('accumulator_state'));
        self::assertSame(Par2RenameOutcome::Renamed, $this->service($xml, ['two' => BoundedArticleResult::success($rest)])->checkPar2Result($release->guid, $release->id, 1, 1, 0));
        self::assertSame(['one', 'two', 'two'], $this->fetched);
    }

    #[DataProvider('manifestChanges')]
    public function test_future_segment_or_configuration_changes_invalidate_prefix_state(string $change): void
    {
        $release = $this->release();
        [$main, $rest] = $this->metadata();
        $xml = $this->nzb([$main, $rest]);
        $this->service($xml, ['one' => BoundedArticleResult::success($main)])->checkPar2Result($release->guid, $release->id, 1, 1, 0);
        $before = DB::table('par2_rename_progress')->value('signature');
        if ($change === 'future ID') {
            $xml->file[0]->segments->segment[1] = 'new-future-id';
        } else {
            config(['tmux.fix_names.par2_max_state_bytes' => 8388609]);
        }

        self::assertSame(Par2RenameOutcome::Deferred, $this->service($xml, ['one' => BoundedArticleResult::success($main)])->checkPar2Result($release->guid, $release->id, 1, 1, 0));

        self::assertSame(['one', 'one'], $this->fetched);
        self::assertNotSame($before, DB::table('par2_rename_progress')->value('signature'));
        self::assertSame(1, DB::table('par2_rename_candidate_progress')->count());
    }

    /** @return array<string, array{string}> */
    public static function manifestChanges(): array
    {
        return ['future ID' => ['future ID'], 'state cap' => ['state cap']];
    }

    public function test_supplied_guid_is_bound_byte_exactly_before_loading_the_nzb(): void
    {
        $release = $this->release(['guid' => 'CaseSensitiveGuid']);
        Par2RenameProgress::save($release->id, str_repeat('a', 64), 1, [0]);
        $service = $this->service($this->nzb($this->metadata()), [], failLoad: true);

        self::assertSame(Par2RenameOutcome::Deferred, $service->checkPar2Result('casesensitiveguid', $release->id, 1, 1, 0));

        self::assertSame(str_repeat('a', 64), Par2RenameProgress::get($release->id)['signature']);
        self::assertSame([], $this->fetched);
    }

    #[DataProvider('releaseRaces')]
    public function test_concurrent_guid_name_or_status_changes_cannot_apply_metadata_or_clear_checkpoints(string $column, int|string $value): void
    {
        $release = $this->release();
        [$main, $rest] = $this->metadata();
        $xml = $this->nzb([$main, $rest]);
        $this->service($xml, ['one' => BoundedArticleResult::success($main)])->checkPar2Result($release->guid, $release->id, 1, 1, 0);
        $saved = DB::table('par2_rename_candidate_progress')->value('accumulator_state');
        $response = static function () use ($release, $column, $value, $rest): BoundedArticleResult {
            DB::table('releases')->where('id', $release->id)->update([$column => $value]);

            return BoundedArticleResult::success($rest);
        };

        self::assertSame(Par2RenameOutcome::Deferred, $this->service($xml, ['two' => $response])->checkPar2Result($release->guid, $release->id, 1, 1, 0));

        self::assertSame($saved, DB::table('par2_rename_candidate_progress')->value('accumulator_state'));
        self::assertSame(0, DB::table('release_files')->count());
        self::assertSame($value, DB::table('releases')->where('id', $release->id)->value($column));
    }

    /** @return array<string, array{string,int|string}> */
    public static function releaseRaces(): array
    {
        return ['GUID' => ['guid', 'replacement-guid'], 'name' => ['name', 'replacement-name'], 'searchname' => ['searchname', 'replacement-title'], 'linked' => ['predb_id', 9], 'renamed' => ['isrenamed', 1], 'processed' => ['proc_par2', 1]];
    }

    public function test_partial_index_rotates_so_a_hidden_index_can_win_the_next_article_budget(): void
    {
        $release = $this->release();
        [$main, $rest] = $this->metadata();
        $xml = $this->nzb([$main, $rest]);
        $hidden = $xml->addChild('file');
        $hidden->addAttribute('subject', '"hidden-index" yEnc');
        $segment = $hidden->addChild('segments')->addChild('segment', 'hidden');
        $segment->addAttribute('number', '1');
        $segment->addAttribute('bytes', (string) strlen($main.$rest));

        self::assertSame(Par2RenameOutcome::Deferred, $this->service($xml, ['one' => BoundedArticleResult::success($main)])->checkPar2Result($release->guid, $release->id, 1, 1, 0));
        self::assertSame(Par2RenameOutcome::Renamed, $this->service($xml, ['hidden' => BoundedArticleResult::success($main.$rest)])->checkPar2Result($release->guid, $release->id, 1, 1, 0));
        self::assertSame(['one', 'hidden'], $this->fetched);
    }

    #[DataProvider('malformedSegments')]
    public function test_malformed_advertised_bytes_or_number_sequences_remain_pending_without_fetch(string $number, string $bytes): void
    {
        $release = $this->release();
        $xml = new SimpleXMLElement('<nzb><file subject="index.par2"><segments><segment number="'.$number.'" bytes="'.$bytes.'">one</segment></segments></file></nzb>');

        self::assertSame(Par2RenameOutcome::Retryable, $this->service($xml, [])->checkPar2Result($release->guid, $release->id, 1, 1, 0));
        self::assertSame([], $this->fetched);
        self::assertSame(0, $release->fresh()->proc_par2);
    }

    /** @return array<string, array{string,string}> */
    public static function malformedSegments(): array
    {
        return ['missing number' => ['', '100'], 'zero number' => ['0', '100'], 'missing first part' => ['2', '100'], 'overflow number' => ['999999999999999999999', '100'], 'zero bytes' => ['1', '0'], 'nonnumeric bytes' => ['1', 'nope'], 'negative bytes' => ['1', '-1']];
    }

    public function test_oversized_explicit_index_blocks_without_repeated_first_fetch(): void
    {
        $release = $this->release();
        $xml = $this->nzb($this->metadata());
        $xml->file[0]->segments->segment[0]->attributes()->bytes = '2097153';
        $first = $this->service($xml, []);
        $second = $this->service($xml, []);

        self::assertSame(Par2RenameOutcome::Retryable, $first->checkPar2Result($release->guid, $release->id, 1, 1, 0));
        self::assertSame(Par2RenameOutcome::Retryable, $second->checkPar2Result($release->guid, $release->id, 1, 1, 0));
        self::assertSame([], $this->fetched);
        self::assertSame('article_limit', DB::table('par2_rename_candidate_progress')->value('blocked_reason'));
        self::assertSame(0, $release->fresh()->proc_par2);
    }

    #[DataProvider('corruptParentRetries')]
    public function test_corrupt_parent_retry_types_cannot_complete_a_blocked_candidate(string $json): void
    {
        $release = $this->release();
        $xml = $this->nzb(['malformed PAR2']);
        self::assertSame(Par2RenameOutcome::Retryable, $this->service($xml, ['one' => BoundedArticleResult::success('malformed PAR2')])->checkPar2Result($release->guid, $release->id, 1, 1, 0));
        DB::table('par2_rename_progress')->where('releases_id', $release->id)->update(['retry_indexes' => $json]);

        self::assertSame(Par2RenameOutcome::Retryable, $this->service($xml, [])->checkPar2Result($release->guid, $release->id, 1, 1, 0));

        self::assertSame(0, $release->fresh()->proc_par2);
        self::assertSame('malformed_article', DB::table('par2_rename_candidate_progress')->value('blocked_reason'));
    }

    /** @return array<string,array{string}> */
    public static function corruptParentRetries(): array
    {
        return ['string' => ['["0"]'], 'boolean' => ['[true]'], 'float' => ['[0.5]'], 'object' => ['{"0":0}'], 'duplicate' => ['[0,0]'], 'negative' => ['[-1]'], 'out of range' => ['[1]'], 'null' => ['null'], 'invalid JSON' => ['{']];
    }

    public function test_pending_child_checkpoint_cannot_be_erased_by_parent_cursor_exhaustion(): void
    {
        $release = $this->release();
        $xml = $this->nzb(['malformed PAR2']);
        $this->service($xml, ['one' => BoundedArticleResult::success('malformed PAR2')])->checkPar2Result($release->guid, $release->id, 1, 1, 0);
        DB::table('par2_rename_progress')->where('releases_id', $release->id)->update(['retry_indexes' => '[]']);

        self::assertSame(Par2RenameOutcome::Retryable, $this->service($xml, [])->checkPar2Result($release->guid, $release->id, 1, 1, 0));

        self::assertSame(0, $release->fresh()->proc_par2);
        self::assertSame(1, DB::table('par2_rename_candidate_progress')->count());
    }

    public function test_claim_loss_during_metadata_collection_rolls_back_and_resumes_without_refetch(): void
    {
        $release = $this->release();
        $bytes = implode('', $this->metadata());
        $xml = $this->nzb([$bytes]);
        Event::fake([ReleaseNameFixed::class]);
        $lost = false;
        DB::listen(static function (QueryExecuted $query) use ($release, &$lost): void {
            if (! $lost && str_starts_with($query->sql, 'insert or ignore into "release_files"')) {
                $lost = true;
                Cache::store('array')->lock('rename:par2:'.$release->id)->forceRelease();
            }
        });
        $first = $this->service($xml, ['one' => BoundedArticleResult::success($bytes)]);

        self::assertSame(Par2RenameOutcome::Deferred, $first->checkPar2Result($release->guid, $release->id, 1, 1, 0));

        self::assertTrue($lost);
        self::assertSame(0, DB::table('release_files')->count());
        self::assertSame(0, DB::table('par_hashes')->count());
        self::assertSame(0, $release->fresh()->rarinnerfilecount);
        self::assertSame(0, $release->fresh()->isrenamed);
        self::assertSame(0, $release->fresh()->proc_par2);
        Event::assertNotDispatched(ReleaseNameFixed::class);
        $second = $this->service($xml, []);

        self::assertSame(Par2RenameOutcome::Renamed, $second->checkPar2Result($release->guid, $release->id, 1, 1, 0));
        self::assertSame(['one'], $this->fetched);
        self::assertSame(['candidates_probed' => 0, 'articles_received' => 0], $second->par2Stats());
        self::assertSame(self::TITLE, $release->fresh()->searchname);
        Event::assertDispatchedTimes(ReleaseNameFixed::class, 1);
    }

    public function test_claim_loss_during_terminal_completion_restores_pending_status_and_parent_cursor(): void
    {
        $release = $this->release();
        $xml = $this->nzb(['ordinary payload']);
        $xml->file[0]->attributes()->subject = 'hidden unknown file';
        DB::listen(static function (QueryExecuted $query) use ($release): void {
            if (str_starts_with($query->sql, 'update "releases" set "proc_par2"')) {
                Cache::store('array')->lock('rename:par2:'.$release->id)->forceRelease();
            }
        });

        self::assertSame(Par2RenameOutcome::Deferred, $this->service($xml, ['one' => BoundedArticleResult::success('ordinary payload')])->checkPar2Result($release->guid, $release->id, 1, 1, 0));

        self::assertSame(0, $release->fresh()->proc_par2);
        self::assertNotNull(Par2RenameProgress::get($release->id));
        self::assertSame(1, Par2RenameProgress::get($release->id)['offset']);
        self::assertSame(0, DB::table('release_files')->count());
    }

    #[DataProvider('changedYencNames')]
    public function test_actual_bounded_transport_rejects_byte_changed_yenc_name_after_restart(string $changedName): void
    {
        $release = $this->release();
        [$main, $rest] = $this->metadata();
        $xml = $this->nzb([$main, $rest]);
        $total = strlen($main.$rest);
        $firstArticle = $this->decodeWirePart($main, 'index.par2', $total, 1, 1);
        $secondArticle = $this->decodeWirePart($rest, $changedName, $total, 2, strlen($main) + 1);
        self::assertSame($main, $firstArticle->body);
        self::assertSame($rest, $secondArticle->body);
        self::assertSame(Par2RenameOutcome::Deferred, $this->service($xml, ['one' => $firstArticle])->checkPar2Result($release->guid, $release->id, 1, 1, 0));
        $before = (array) DB::table('par2_rename_candidate_progress')->first();

        self::assertSame(Par2RenameOutcome::Retryable, $this->service($xml, ['two' => $secondArticle])->checkPar2Result($release->guid, $release->id, 1, 1, 0));

        $after = (array) DB::table('par2_rename_candidate_progress')->first();
        foreach (['next_segment', 'decoded_offset', 'article_identity', 'accumulator_state', 'stored_bytes'] as $column) {
            self::assertSame($before[$column], $after[$column]);
        }
        self::assertSame('malformed_article', $after['blocked_reason']);
        self::assertSame(0, $release->fresh()->isrenamed);
        self::assertSame(0, $release->fresh()->proc_par2);
        self::assertSame(0, DB::table('release_files')->count());
        self::assertSame(Par2RenameOutcome::Retryable, $this->service($xml, [])->checkPar2Result($release->guid, $release->id, 1, 1, 0));
        self::assertSame(['one', 'two'], $this->fetched);
    }

    /** @return array<string,array{string}> */
    public static function changedYencNames(): array
    {
        return ['space' => ['index.par2 '], 'tab' => ["index.par2\t"], 'leading space' => [' index.par2']];
    }

    public function test_actual_bounded_transport_preserves_equal_literal_names_for_successful_early_recovery(): void
    {
        $release = $this->release();
        [$main, $rest] = $this->metadata();
        $xml = $this->nzb([$main, $rest]);
        $total = strlen($main.$rest);
        $name = " index-\xff.par2 \t";
        $first = $this->decodeWirePart($main, $name, $total, 1, 1);
        $second = $this->decodeWirePart($rest, $name, $total, 2, strlen($main) + 1);

        self::assertSame(Par2RenameOutcome::Deferred, $this->service($xml, ['one' => $first])->checkPar2Result($release->guid, $release->id, 1, 1, 0));
        self::assertSame(base64_encode($name), json_decode(DB::table('par2_rename_candidate_progress')->value('article_identity'), true, flags: JSON_THROW_ON_ERROR)['name_base64']);
        self::assertSame(Par2RenameOutcome::Renamed, $this->service($xml, ['two' => $second])->checkPar2Result($release->guid, $release->id, 1, 1, 0));
        self::assertSame(self::TITLE, $release->fresh()->searchname);
        self::assertSame(['one', 'two'], $this->fetched);
    }

    private function decodeWirePart(string $bytes, string $name, int $total, int $part, int $begin): BoundedArticleResult
    {
        $library = getenv('YENC_NATIVE_LIBRARY');
        $decoder = $library ? new YencService(new NativePayloadDecoder($library)) : new YencService;
        $article = YencArticles::part($bytes, $name, $total, $part, $begin, 2);
        $wire = preg_replace('/^\./m', '..', $article)."\r\n.\r\n";
        $client = new class($wire, $decoder) extends NNTPService
        {
            public function __construct(string $wire, YencService $decoder)
            {
                $this->_yencService = $decoder;
                $this->_socket = fopen('php://temp', 'w+');
                fwrite($this->_socket, $wire);
                rewind($this->_socket);
            }

            protected function _checkConnection(bool $reSelectGroup = true): mixed
            {
                return true;
            }

            public function selectGroup(string $group, mixed $articles = false, bool $force = false): mixed
            {
                return [];
            }

            protected function _sendCommand(string $cmd): mixed
            {
                return 222;
            }
        };

        return $client->getMessageBounded('alt.binaries.test', 'offline@example.test', 2097152);
    }

    private function release(array $attributes = []): Release
    {
        return $this->pendingPar2Release(array_merge(['postdate' => now()->toDateTimeString(), 'name' => '7ae60ee3d802bfd4388b0f2c6e982e7a', 'searchname' => '7ae60ee3d802bfd4388b0f2c6e982e7a', 'categories_id' => Category::OTHER_MISC], $attributes));
    }

    /** @param list<string> $parts */
    private function nzb(array $parts): SimpleXMLElement
    {
        $xml = new SimpleXMLElement('<nzb/>');
        $file = $xml->addChild('file');
        $file->addAttribute('subject', '"index.par2" yEnc');
        $segments = $file->addChild('segments');
        foreach ($parts as $index => $part) {
            $segment = $segments->addChild('segment', $index === 0 ? 'one' : 'two');
            $segment->addAttribute('number', (string) ($index + 1));
            $segment->addAttribute('bytes', (string) strlen($part));
        }

        return $xml;
    }

    private function part(string $bytes, int $part, int $begin, int $size): BoundedArticleResult
    {
        return BoundedArticleResult::success($bytes, new YencPart('index.par2', $size, $part, $begin, $begin + strlen($bytes) - 1));
    }

    /** @param array<string,BoundedArticleResult|\Closure> $responses */
    private function service(SimpleXMLElement $xml, array $responses, bool $failLoad = false): NzbContentsService
    {
        $nntp = Mockery::mock(NNTPService::class);
        $nntp->shouldNotReceive('getMessages');
        foreach ($responses as $id => $response) {
            $nntp->shouldReceive('getMessageBounded')->once()->with('alt.binaries.test', $id, 2097152, false)->andReturnUsing(function () use ($id, $response): BoundedArticleResult {
                $this->fetched[] = $id;

                return $response instanceof \Closure ? $response() : $response;
            });
        }
        if ($responses === []) {
            $nntp->shouldNotReceive('getMessageBounded');
        }
        $category = Mockery::mock(CategorizationService::class);
        $updates = new ReleaseUpdateService($category, searchSyncCoordinator: new ReleaseSearchSyncCoordinator(new PersistenceMetricsCollector, static function (int $id): void {}));
        $processor = new Par2Processor(new NameFixingService($updates), new Par2Info, true, false);
        $post = new PostProcessService(par2Processor: $processor);

        return new class($xml, $nntp, $post, $failLoad) extends NzbContentsService
        {
            public function __construct(private SimpleXMLElement $xml, NNTPService $nntp, PostProcessService $post, private bool $failLoad)
            {
                $this->nntp = $nntp;
                $this->postProcessService = $post;
                $this->parserService = new NzbParserService;
                $this->alternateNntp = false;
            }

            public function loadNzb(string $guid): SimpleXMLElement
            {
                if ($this->failLoad) {
                    throw new \RuntimeException('A mismatched GUID must never select an NZB');
                }

                return $this->xml;
            }
        };
    }

    /**
     * Preserve actual fixture packets while deriving a coherent metadata-only large-file
     * descriptor and Main/IFSC IDs; no large media payload is allocated or fetched.
     *
     * @return array{string,string}
     */
    private function metadata(): array
    {
        $bytes = (string) file_get_contents(base_path('tests/Fixtures/par2/rename-index.par2'));
        $oldId = substr($bytes, 64, 16);
        $hash16 = substr($bytes, 96, 16);
        $length = pack('V2', 123, 3);
        $newId = md5($hash16.$length.self::TITLE.'.mkv', true);
        $packets = [];
        $mainBody = '';
        for ($offset = 0; $offset < strlen($bytes);) {
            $packetLength = unpack('V', substr($bytes, $offset + 8, 4))[1];
            $type = substr($bytes, $offset + 48, 16);
            $body = substr($bytes, $offset + 64, $packetLength - 64);
            if ($type === Par2Info::PACKET_MAIN) {
                $mainBody = str_replace($oldId, $newId, $body);
            } else {
                if (substr($body, 0, 16) === $oldId && $type === Par2Info::PACKET_FILEDESC) {
                    $body = $newId.substr($body, 16, 32).$length.self::TITLE.'.mkv';
                } elseif (substr($body, 0, 16) === $oldId && $type === Par2Info::PACKET_FILEVER) {
                    $body = $newId.substr($body, 16);
                }
                $packets[] = [$type, $body];
            }
            $offset += $packetLength;
        }
        $set = md5($mainBody, true);
        $encode = static function (string $type, string $body) use ($set): string {
            $body .= str_repeat("\0", (4 - strlen($body) % 4) % 4);
            $hashed = $set.$type.$body;

            return Par2Info::PACKET_MARKER.pack('V2', strlen($body) + 64, 0).md5($hashed, true).$hashed;
        };

        return [$encode(Par2Info::PACKET_MAIN, $mainBody), implode('', array_map(static fn (array $packet): string => $encode($packet[0], $packet[1]), $packets))];
    }
}

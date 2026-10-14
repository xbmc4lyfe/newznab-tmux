<?php

declare(strict_types=1);

namespace Tests\Unit\AdditionalProcessing;

use App\Models\Release;
use App\Services\AdditionalProcessing\AdditionalWorkPlanner;
use App\Services\AdditionalProcessing\DTO\Par2FileEntry;
use App\Services\AdditionalProcessing\Enums\DownloadKind;
use App\Services\AdditionalProcessing\Media\MediaArticleFetcher;
use App\Services\AdditionalProcessing\Media\MediaSourceResolver;
use App\Services\AdditionalProcessing\State\MediaByteBudget;
use App\Services\AdditionalProcessing\State\ReleaseProcessingContext;
use App\Services\AdditionalProcessing\UsenetDownloadService;
use App\Services\Yenc\YencPart;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Fixtures\MappingArticleNntpHarness;
use Tests\Fixtures\YencArticles;

class MediaArticleFetcherTest extends TestCase
{
    use BuildsArchiveFixtures;
    use CreatesProcessingConfiguration;

    private string $path;

    protected function setUp(): void
    {
        parent::setUp();
        $this->path = sys_get_temp_dir().'/fetcher-'.uniqid('', true).'.bin';
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
        Mockery::close();
        parent::tearDown();
    }

    #[Test]
    #[DataProvider('cachedVideoBudgetCases')]
    public function cached_video_still_obeys_offered_byte_budget_prefix_and_deadline(int $maximum, int $budgetLimit, bool $expired, string $expected, int $spent, int $reused, array $commands): void
    {
        $nntp = new MappingArticleNntpHarness('', [
            'BODY <a>' => MappingArticleNntpHarness::rawBody(YencArticles::part('AAAA', 'Movie.mkv', 8, 1, 1, 2)),
            'BODY <b>' => MappingArticleNntpHarness::rawBody(YencArticles::part('BBBB', 'Movie.mkv', 8, 2, 5, 2)),
        ]);
        $download = new UsenetDownloadService($this->makeConfig(['processThumbnails' => true, 'mediaMainVideoFallback' => true, 'mediaMainVideoMinBytes' => 1]), $nntp);
        $download->beginReleaseScope();
        $download->downloadMappingArticle('a');
        $download->retainMappedSourceArticle(new Par2FileEntry('Movie.mkv', 8));
        $budget = new MediaByteBudget($budgetLimit);
        $context = new ReleaseProcessingContext(new Release(['id' => 1, 'guid' => 'guid-1']));
        $written = (new MediaArticleFetcher($download))->append($context, ['a', 'b'], 0, 0, $maximum, $this->path, $budget, hrtime(true) / 1e9 + ($expired ? -1 : 60), 4);
        $this->assertSame(strlen($expected), $written);
        $this->assertSame($expected, is_file($this->path) ? file_get_contents($this->path) : '');
        $this->assertSame($budgetLimit - $spent, $budget->remaining());
        $this->assertSame($commands, $nntp->sentCommands());
        $this->assertSame($reused, $download->finishReleaseScope()->bytesReused);
    }

    public static function cachedVideoBudgetCases(): array
    {
        return [
            'cached prefix spends full offered article' => [2, 100, false, 'AA', 4, 4, ['BODY <a>']],
            'growing into next article spends both full offered lengths' => [6, 100, false, 'AAAABB', 8, 4, ['BODY <a>', 'BODY <b>']],
            'exhausted budget stops before next article' => [8, 4, false, 'AAAA', 4, 4, ['BODY <a>']],
            'estimate prevents even cached first article' => [8, 3, false, '', 0, 0, ['BODY <a>']],
            'deadline prevents even cached first article' => [8, 100, true, '', 0, 0, ['BODY <a>']],
        ];
    }

    #[Test]
    #[DataProvider('cachedVideoFailureCases')]
    public function cached_video_does_not_bridge_a_failure_or_offset_gap(array $ids, string $expected, int $spent, int $reused, array $commands): void
    {
        $nntp = new MappingArticleNntpHarness('', [
            'BODY <a>' => MappingArticleNntpHarness::rawBody(YencArticles::part('AAAA', 'Movie.mkv', 12, 1, 1, 3)),
            'BODY <wrong>' => MappingArticleNntpHarness::rawBody(YencArticles::part('BBBB', 'Movie.mkv', 12, 3, 9, 3)),
        ]);
        $download = new UsenetDownloadService($this->makeConfig(['processThumbnails' => true, 'mediaMainVideoFallback' => true, 'mediaMainVideoMinBytes' => 1]), $nntp);
        $download->beginReleaseScope();
        $download->downloadMappingArticle('a');
        $download->retainMappedSourceArticle(new Par2FileEntry('Movie.mkv', 12));
        $budget = new MediaByteBudget(100);
        $written = (new MediaArticleFetcher($download))->append(new ReleaseProcessingContext(new Release(['id' => 1, 'guid' => 'guid-1'])), $ids, 0, 0, 12, $this->path, $budget, hrtime(true) / 1e9 + 60, 4);
        $this->assertSame(strlen($expected), $written);
        $this->assertSame($expected, is_file($this->path) ? file_get_contents($this->path) : '');
        $this->assertSame(100 - $spent, $budget->remaining());
        $this->assertSame($commands, $nntp->sentCommands());
        $this->assertSame($reused, $download->finishReleaseScope()->bytesReused);
    }

    public static function cachedVideoFailureCases(): array
    {
        return [
            'missing before cache hit' => [['missing', 'a'], '', 0, 0, ['BODY <a>', 'BODY <missing>']],
            'missing after cache hit' => [['a', 'missing', 'wrong'], 'AAAA', 4, 4, ['BODY <a>', 'BODY <missing>']],
            'offset gap after cache hit' => [['a', 'wrong', 'missing'], 'AAAA', 8, 4, ['BODY <a>', 'BODY <wrong>']],
        ];
    }

    #[Test]
    public function par2_mapping_then_video_fetch_reuses_the_valid_first_article_without_changing_offsets(): void
    {
        $observations = [];
        foreach ([false, true] as $withChecksum) {
            @unlink($this->path);
            $config = $this->makeConfig(['processThumbnails' => true, 'mediaMainVideoFallback' => true, 'mediaMainVideoMinBytes' => 1, 'par2MediaMapping' => true, 'par2VerifyFiles' => 1]);
            $index = $this->par2Index([['Movie.mkv', 8, 'AAAABBBB'], ['Other.mkv', 8, 'CCCCDDDD']]);
            $a = YencArticles::part('AAAA', 'Movie.mkv', 8, 1, 1, 2);
            if (! $withChecksum) {
                $a = preg_replace('/ pcrc32=[0-9a-f]+/', '', $a);
            }
            $nntp = new MappingArticleNntpHarness('', [
                'BODY <index>' => MappingArticleNntpHarness::body($index, 'index.par2'),
                'BODY <a>' => MappingArticleNntpHarness::rawBody($a),
                'BODY <b>' => MappingArticleNntpHarness::rawBody(YencArticles::part('BBBB', 'Movie.mkv', 8, 2, 5, 2)),
            ]);
            $download = new UsenetDownloadService($config, $nntp);
            $context = new ReleaseProcessingContext(new Release(['id' => 1, 'guid' => 'guid-1']));
            $context->nzbContents = [
                ['title' => 'index.par2', 'size' => strlen($index), 'segments' => ['index'], 'filecount' => 1],
                ['title' => 'opaque1', 'size' => 8, 'segments' => ['a', 'b'], 'filecount' => 1],
                ['title' => 'opaque2', 'size' => 8, 'segments' => ['c', 'd'], 'filecount' => 1],
            ];
            $context->workPlan = (new AdditionalWorkPlanner($config))->plan($context->nzbContents, '');
            $download->beginReleaseScope();
            $context->mediaPlan = (new MediaSourceResolver($config, $download))->resolve($context);
            $this->assertSame('Movie.mkv', $context->mediaPlan?->mappedFiles[1]->file->name);
            $budget = new MediaByteBudget(1000);
            $written = (new MediaArticleFetcher($download))->append($context, ['a', 'b'], 0, 0, 8, $this->path, $budget, hrtime(true) / 1e9 + 60, 4);
            $this->assertSame(8, $written);
            $this->assertSame('AAAABBBB', file_get_contents($this->path));
            $this->assertSame(992, $budget->remaining());
            $this->assertSame($withChecksum ? ['BODY <index>', 'BODY <a>', 'BODY <b>'] : ['BODY <index>', 'BODY <a>', 'BODY <a>', 'BODY <b>'], $nntp->sentCommands());
            $metrics = $download->finishReleaseScope();
            $this->assertSame($withChecksum ? 3 : 4, $metrics->networkRequests);
            $this->assertSame($withChecksum ? 4 : 0, $metrics->bytesReused);
            $observations[] = [file_get_contents($this->path), $budget->remaining(), $context->mediaPlan];
        }
        $this->assertEquals($observations[0], $observations[1]);
    }

    #[Test]
    public function it_appends_consecutive_articles_up_to_the_byte_limit(): void
    {
        $download = $this->articles([
            '<2>' => ['BBBB', 4],
            '<3>' => ['CCCC', 8],
        ]);

        $written = $this->fetch($download, fromSegment: 1, expectedOffset: 4, maxBytes: 6);

        $this->assertSame(6, $written);
        $this->assertSame('BBBBCC', file_get_contents($this->path));
    }

    #[Test]
    public function it_stops_where_an_article_does_not_continue_the_previous_one(): void
    {
        $download = $this->articles([
            '<2>' => ['BBBB', 4],
            '<3>' => ['DDDD', 12],
        ]);

        $this->assertSame(4, $this->fetch($download, fromSegment: 1, expectedOffset: 4, maxBytes: 100));
        $this->assertSame('BBBB', file_get_contents($this->path));
    }

    #[Test]
    public function it_fetches_nothing_the_budget_cannot_cover(): void
    {
        $download = Mockery::mock(UsenetDownloadService::class);
        $download->shouldNotReceive('downloadArticle');

        $this->assertSame(0, $this->fetch($download, fromSegment: 0, expectedOffset: 0, maxBytes: 100, budget: new MediaByteBudget(3)));
    }

    #[Test]
    public function exhausted_image_budget_blocks_video_articles_even_without_a_size_estimate(): void
    {
        $download = Mockery::mock(UsenetDownloadService::class);
        $download->shouldNotReceive('downloadArticle');
        $budget = new MediaByteBudget(8);
        $budget->spend(8);

        $written = (new MediaArticleFetcher($download))->append(
            new ReleaseProcessingContext(new Release(['id' => 1, 'guid' => 'guid-1'])),
            ['video@test'], 0, 0, 100, $this->path, $budget, hrtime(true) / 1e9 + 60, 0,
        );

        $this->assertSame(0, $written);
        $this->assertFileDoesNotExist($this->path);
    }

    #[Test]
    public function it_places_a_cached_first_article_at_the_start_of_the_file(): void
    {
        $download = Mockery::mock(UsenetDownloadService::class);
        $download->shouldReceive('downloadArticle')->with(DownloadKind::MediaSource, '<1>', '', 1)
            ->andReturn(['success' => true, 'data' => 'AAAA', 'part' => null, 'groupUnavailable' => false, 'error' => null]);
        $download->shouldReceive('downloadArticle')->with(DownloadKind::MediaSource, '<2>', '', 1)
            ->andReturn(['success' => true, 'data' => 'BBBB', 'part' => new YencPart('f', 16, 2, 5, 8), 'groupUnavailable' => false, 'error' => null]);

        $this->assertSame(6, $this->fetch($download, fromSegment: 0, expectedOffset: 0, maxBytes: 6));
        $this->assertSame('AAAABB', file_get_contents($this->path));
    }

    /**
     * @param  array<string, array{0: string, 1: int}>  $articles  message ID => [data, 0-based offset]
     */
    private function articles(array $articles): UsenetDownloadService
    {
        $download = Mockery::mock(UsenetDownloadService::class);
        foreach ($articles as $messageId => [$data, $offset]) {
            $download->shouldReceive('downloadArticle')->with(DownloadKind::MediaSource, $messageId, '', 1)->andReturn([
                'success' => true,
                'data' => $data,
                'part' => new YencPart('movie.mkv', 16, null, $offset + 1, $offset + strlen($data)),
                'groupUnavailable' => false,
                'error' => null,
            ]);
        }

        return $download;
    }

    private function fetch(
        UsenetDownloadService $download,
        int $fromSegment,
        int $expectedOffset,
        int $maxBytes,
        ?MediaByteBudget $budget = null,
    ): int {
        return (new MediaArticleFetcher($download))->append(
            new ReleaseProcessingContext(new Release(['id' => 1, 'guid' => 'guid-1'])),
            ['<1>', '<2>', '<3>', '<4>'],
            $fromSegment,
            $expectedOffset,
            $maxBytes,
            $this->path,
            $budget ?? new MediaByteBudget(1000),
            hrtime(true) / 1e9 + 60,
            4,
        );
    }
}

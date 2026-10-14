<?php

namespace Tests\Unit\AdditionalProcessing;

use App\Services\AdditionalProcessing\DTO\Par2FileEntry;
use App\Services\AdditionalProcessing\Enums\DownloadKind;
use App\Services\AdditionalProcessing\UsenetDownloadService;
use App\Services\NNTP\NNTPService;
use App\Services\Yenc\YencPart;
use App\Services\YencService;
use DariusIII\NetNntp\Error as NntpError;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Fixtures\MappingArticleNntpHarness;

class UsenetDownloadServiceTest extends TestCase
{
    use CreatesProcessingConfiguration;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    #[Test]
    public function pending_receipts_share_the_byte_budget_and_yield_to_aggregate_downloads(): void
    {
        $articleData = str_repeat('A', 8_388_608);
        $nntp = Mockery::mock(NNTPService::class);
        $nntp->shouldReceive('getArticleForArchiveMapping')->twice()->andReturnUsing(static function (string $id, bool $alternate, callable $onValidated) use ($articleData): array {
            $part = new YencPart('opaque', 8_388_608);
            $onValidated($articleData, $part);

            return ['data' => $articleData, 'part' => $part];
        });
        foreach (['full1' => 'X', 'full2' => 'Y'] as $id => $byte) {
            $nntp->shouldReceive('getMessagesByMessageID')->once()->with([$id], false)->andReturn(str_repeat($byte, 8_388_608));
        }
        $service = new UsenetDownloadService($this->makeConfig(), $nntp);
        $service->beginReleaseScope();
        $this->assertSame($articleData, $service->downloadMappingArticle('unmatched')['data']);
        $this->assertSame(['entries' => 1, 'bytes' => 8_388_608, 'accounted' => 8_388_608], $this->retainedCacheState($service));
        $service->download(DownloadKind::Sample, ['full1']);
        $this->assertSame(['entries' => 2, 'bytes' => 16_777_216, 'accounted' => 16_777_216], $this->retainedCacheState($service));
        $service->download(DownloadKind::Sample, ['full2']);
        $this->assertSame(['entries' => 2, 'bytes' => 16_777_216, 'accounted' => 16_777_216], $this->retainedCacheState($service));
        $this->assertSame($articleData, $service->downloadMappingArticle('pressured')['data']);
        $this->assertSame(['entries' => 2, 'bytes' => 16_777_216, 'accounted' => 16_777_216], $this->retainedCacheState($service));
        $this->assertSame(str_repeat('X', 8_388_608), $service->download(DownloadKind::Sample, ['full1'])['data']);
        $this->assertSame(str_repeat('Y', 8_388_608), $service->download(DownloadKind::Sample, ['full2'])['data']);
    }

    #[Test]
    public function pending_receipts_share_the_entry_limit_without_evicting_aggregate_cache(): void
    {
        $nntp = Mockery::mock(NNTPService::class);
        foreach (['full1', 'full2', 'full3', 'full4'] as $id) {
            $nntp->shouldReceive('getMessagesByMessageID')->once()->with([$id], false)->andReturn('FULL');
        }
        $nntp->shouldReceive('getArticleForArchiveMapping')->twice()->andReturnUsing(static function (string $id, bool $alternate, callable $onValidated): array {
            $part = new YencPart('opaque', 7);
            $onValidated('ARTICLE', $part);

            return ['data' => 'ARTICLE', 'part' => $part];
        });
        $service = new UsenetDownloadService($this->makeConfig(), $nntp);
        $service->beginReleaseScope();
        foreach (['full1', 'full2', 'full3'] as $id) {
            $service->download(DownloadKind::Sample, [$id]);
        }
        $this->assertSame('ARTICLE', $service->downloadMappingArticle('pending')['data']);
        $this->assertSame(['entries' => 4, 'bytes' => 19, 'accounted' => 19], $this->retainedCacheState($service));
        $service->download(DownloadKind::Sample, ['full4']);
        $this->assertSame(['entries' => 4, 'bytes' => 16, 'accounted' => 16], $this->retainedCacheState($service));
        $this->assertSame('ARTICLE', $service->downloadMappingArticle('pressured')['data']);
        $this->assertSame(['entries' => 4, 'bytes' => 16, 'accounted' => 16], $this->retainedCacheState($service));
        foreach (['full1', 'full2', 'full3', 'full4'] as $id) {
            $this->assertSame('FULL', $service->download(DownloadKind::Sample, [$id])['data']);
        }
    }

    /** @return array{entries: int, bytes: int, accounted: int} */
    private function retainedCacheState(UsenetDownloadService $service): array
    {
        $aggregate = (new \ReflectionProperty($service, 'releaseCache'))->getValue($service);
        $mapped = (new \ReflectionProperty($service, 'mappedArchiveArticles'))->getValue($service);
        $pending = (new \ReflectionProperty($service, 'pendingMappingArticle'))->getValue($service);
        $entries = [...array_values($aggregate), ...array_values($mapped), ...($pending === null ? [] : [$pending])];

        return [
            'entries' => count($entries),
            'bytes' => array_sum(array_map(static fn (array $entry): int => strlen($entry['data']), $entries)),
            'accounted' => (new \ReflectionProperty($service, 'cachedBytes'))->getValue($service),
        ];
    }

    #[Test]
    #[DataProvider('alternateFailureCases')]
    public function alternate_failure_does_not_bridge_a_missing_article_with_a_later_cached_piece(bool $connectionFailure): void
    {
        $nntp = new MappingArticleNntpHarness(MappingArticleNntpHarness::body('A').MappingArticleNntpHarness::body('C')."430 missing\r\n");
        $alternate = new MappingArticleNntpHarness("430 still missing\r\n");
        $alternate->connectionFailure = $connectionFailure;
        $nntp->alternate = $alternate;
        $service = new UsenetDownloadService($this->makeConfig(['alternateNNTP' => true]), $nntp);
        $service->beginReleaseScope();
        foreach (['a' => 'A', 'c' => 'C'] as $id => $data) {
            $service->downloadMappingArticle($id);
            $service->retainMappedArchiveArticle(new Par2FileEntry('archive.rar', 1, md5($data)));
        }
        $this->assertSame('A', $service->download(DownloadKind::Compressed, ['a', 'b', 'c'])['data']);
        $this->assertSame(['BODY <a>', 'BODY <c>', 'BODY <b>'], $nntp->sentCommands());
        $this->assertSame($connectionFailure ? [] : ['BODY <b>'], $alternate->sentCommands());
        $this->assertSame($connectionFailure ? 0 : 1, $alternate->quits);
        $this->assertSame(1, $service->finishReleaseScope()->bytesReused);
    }

    public static function alternateFailureCases(): array
    {
        return ['alternate BODY missing' => [false], 'alternate connection failed' => [true]];
    }

    #[Test]
    public function empty_requests_keep_the_legacy_validation_with_provisional_articles_present(): void
    {
        $nntp = new MappingArticleNntpHarness(MappingArticleNntpHarness::body('A'));
        $service = new UsenetDownloadService($this->makeConfig(), $nntp);
        $service->beginReleaseScope();
        $service->downloadMappingArticle('a');
        $service->retainMappedArchiveArticle(new Par2FileEntry('archive.rar', 1, md5('A')));
        $nntp->doQuit();
        $nntp->connectionFailure = true;
        foreach ([[], ''] as $ids) {
            $result = $service->download(DownloadKind::Compressed, $ids);
            $this->assertSame('No message IDs provided', $result['error']);
        }
        $this->assertSame(1, $service->finishReleaseScope()->networkRequests);
    }

    #[Test]
    public function alternate_success_does_not_initialize_the_primary_message_size_guard(): void
    {
        $nntp = Mockery::mock(NNTPService::class)->makePartial()->shouldAllowMockingProtectedMethods();
        $alternate = Mockery::mock(NNTPService::class)->makePartial()->shouldAllowMockingProtectedMethods();
        foreach (['_currentServer' => 'fixture', '_configServer' => 'fixture', '_configCompressedHeaders' => false] as $property => $value) {
            (new \ReflectionProperty($nntp, $property))->setValue($nntp, $value);
        }
        $nntp->shouldReceive('_checkConnection')->once()->with(false)->andReturnTrue();
        $nntp->shouldReceive('previewAlternateConnection')->once()->andReturn($alternate);
        $nntp->shouldReceive('_getMessageByMessageID')->once()->with('large')->andReturn(new NntpError('missing'));
        $alternate->shouldReceive('doConnect')->once()->andReturnTrue();
        $alternate->shouldReceive('_getMessageByMessageID')->once()->with('large')->andReturn(str_repeat('X', 17_000_000));
        $alternate->shouldReceive('doQuit')->once()->andReturnTrue();
        $reused = 0;
        $result = $nntp->getMessagesByMessageIDWithArticleReuse(['large', ...array_fill(0, 100, 'cached')], true,
            static fn (mixed $id): ?string => $id === 'cached' ? 'A' : null,
            static function (int $bytes) use (&$reused): void {
                $reused += $bytes;
            },
        );
        $this->assertSame(17_000_100, strlen($result));
        $this->assertSame(100, $reused);
    }

    #[Test]
    #[DataProvider('articleEntrySizes')]
    public function provisional_retention_shares_the_existing_per_entry_byte_ceiling(int $size, bool $retained): void
    {
        $data = str_repeat('A', $size);
        $nntp = Mockery::mock(NNTPService::class)->makePartial()->shouldAllowMockingProtectedMethods();
        $nntp->shouldReceive('getArticleForArchiveMapping')->once()->andReturnUsing(static function (string $id, bool $alternate, callable $onValidated) use ($data, $size): array {
            $part = new YencPart('archive.rar', $size);
            $onValidated($data, $part);

            return ['data' => $data, 'part' => $part];
        });
        if ($retained) {
            $nntp->shouldReceive('_checkConnection')->once()->with(false)->andReturnTrue();
            $nntp->shouldNotReceive('_getMessageByMessageID');
        } else {
            $nntp->shouldReceive('getMessagesByMessageID')->once()->with(['a'], false)->andReturn('NETWORK');
        }
        $service = new UsenetDownloadService($this->makeConfig(), $nntp);
        $service->beginReleaseScope();
        $service->downloadMappingArticle('a');
        $service->retainMappedArchiveArticle(new Par2FileEntry('archive.rar', $size, md5(substr($data, 0, 16384))));
        $result = $service->download(DownloadKind::Compressed, ['a'], cache: false);
        $this->assertSame($retained ? $data : 'NETWORK', $result['data']);
        $this->assertSame($retained ? $size : 0, $service->finishReleaseScope()->bytesReused);
    }

    public static function articleEntrySizes(): array
    {
        return ['at existing limit' => [8_388_608, true], 'over existing limit' => [8_388_609, false]];
    }

    #[Test]
    public function provisional_retention_never_exceeds_four_entries(): void
    {
        $nntp = new PreviewNntpHarness(implode('', array_map(static fn (string $data): string => (new YencService)->encode($data, 'archive.rar')."\r\n.\r\n", ['A', 'B', 'C', 'D', 'E', 'NETWORK'])));
        $service = new UsenetDownloadService($this->makeConfig(), $nntp);
        $service->beginReleaseScope();
        foreach (['a' => 'A', 'b' => 'B', 'c' => 'C', 'd' => 'D', 'e' => 'E'] as $id => $data) {
            $service->downloadMappingArticle($id);
            $service->retainMappedArchiveArticle(new Par2FileEntry('archive.rar', 1, md5($data)));
        }
        $this->assertSame('ABCDNETWORK', $service->download(DownloadKind::Compressed, ['a', 'b', 'c', 'd', 'e'], cache: false)['data']);
        $this->assertSame(4, $service->finishReleaseScope()->bytesReused);
    }

    #[Test]
    public function ordered_reuse_retains_the_huge_string_guard_before_future_cache_hits(): void
    {
        $nntp = Mockery::mock(NNTPService::class)->makePartial()->shouldAllowMockingProtectedMethods();
        $nntp->shouldReceive('_checkConnection')->once()->with(false)->andReturnTrue();
        $nntp->shouldReceive('_getMessageByMessageID')->once()->with('large')->andReturn(str_repeat('X', 17_000_000));
        $nntp->shouldReceive('_getMessageByMessageID')->times(97)->with('empty')->andReturn('');
        $nntp->shouldNotReceive('_getMessageByMessageID')->with('future');
        $reused = 0;
        $result = $nntp->getMessagesByMessageIDWithArticleReuse(['large', 'cached', ...array_fill(0, 97, 'empty'), 'future'], false,
            static fn (mixed $id): ?string => in_array($id, ['cached', 'future'], true) ? 'A' : null,
            static function (int $bytes) use (&$reused): void {
                $reused += $bytes;
            },
        );
        $this->assertSame(17_000_001, strlen($result));
        $this->assertSame('A', substr($result, -1));
        $this->assertSame(1, $reused);
    }

    #[Test]
    public function beginning_another_scope_drops_both_pending_and_retained_mapping_articles(): void
    {
        $nntp = new MappingArticleNntpHarness(MappingArticleNntpHarness::body('A').MappingArticleNntpHarness::body('NETWORK'));
        $service = new UsenetDownloadService($this->makeConfig(), $nntp);
        $service->beginReleaseScope();
        $service->downloadMappingArticle('a');
        $service->retainMappedArchiveArticle(new Par2FileEntry('archive.rar', 1, md5('A')));
        $service->beginReleaseScope();
        $this->assertSame('NETWORK', $service->download(DownloadKind::Compressed, ['a'])['data']);
        $this->assertSame(0, $service->finishReleaseScope()->cacheHits);
    }

    #[Test]
    public function a_mapping_exception_clears_previously_retained_articles(): void
    {
        $nntp = Mockery::mock(NNTPService::class)->makePartial();
        $nntp->shouldReceive('getArticleForArchiveMapping')->once()->with('a', false, Mockery::any())
            ->andReturnUsing(static function (string $id, bool $alternate, callable $onValidated): array {
                $part = new YencPart('archive.rar', 1);
                $onValidated('A', $part);

                return ['data' => 'A', 'part' => $part];
            });
        $nntp->shouldReceive('getArticleForArchiveMapping')->once()->with('broken', false, Mockery::any())->andThrow(new \RuntimeException('fixture exception'));
        $nntp->shouldReceive('getMessagesByMessageID')->once()->with(['a'], false)->andReturn('NETWORK');
        $service = new UsenetDownloadService($this->makeConfig(), $nntp);
        $service->beginReleaseScope();
        $service->downloadMappingArticle('a');
        $service->retainMappedArchiveArticle(new Par2FileEntry('archive.rar', 1, md5('A')));
        try {
            $service->downloadMappingArticle('broken');
            $this->fail('The existing download exception must propagate.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('fixture exception', $exception->getMessage());
        }
        $this->assertSame('NETWORK', $service->download(DownloadKind::Compressed, ['a'])['data']);
        $this->assertSame(0, $service->finishReleaseScope()->cacheHits);
    }

    #[Test]
    public function an_alternate_socket_on_the_main_client_is_not_primary_admission(): void
    {
        $nntp = new MappingArticleNntpHarness(MappingArticleNntpHarness::body('A').MappingArticleNntpHarness::body('NETWORK'));
        $nntp->useAlternateAsCurrentConnection();
        $service = new UsenetDownloadService($this->makeConfig(), $nntp);
        $service->beginReleaseScope();
        $service->downloadMappingArticle('a');
        $service->retainMappedArchiveArticle(new Par2FileEntry('archive.rar', 1, md5('A')));
        $this->assertSame('NETWORK', $service->download(DownloadKind::Compressed, ['a'])['data']);
        $this->assertSame(0, $service->finishReleaseScope()->cacheHits);
    }

    #[Test]
    public function aggregate_cache_precedes_articles_and_evicts_provisional_entries_first(): void
    {
        $nntp = new PreviewNntpHarness(implode('', array_map(static fn (string $data): string => (new YencService)->encode($data, 'archive.rar')."\r\n.\r\n", ['A', 'B', 'C', 'D', 'WHO', 'LE', 'NEW-A'])));
        $service = new UsenetDownloadService($this->makeConfig(), $nntp);
        $service->beginReleaseScope();
        foreach (['a' => 'A', 'b' => 'B', 'c' => 'C', 'd' => 'D'] as $id => $data) {
            $service->downloadMappingArticle($id, 'group');
            $service->retainMappedArchiveArticle(new Par2FileEntry('archive.rar', 1, md5($data)));
        }
        $this->assertSame('WHOLE', $service->download(DownloadKind::Sample, ['a', 'b'], 'group')['data']);
        $this->assertSame('NEW-A', $service->download(DownloadKind::Compressed, ['a'], 'group', cache: false)['data']);
        $this->assertSame('B', $service->download(DownloadKind::Compressed, ['b'], 'group', cache: false)['data']);
        $nntp->doQuit();
        $this->assertSame('WHOLE', $service->download(DownloadKind::Compressed, ['a', 'b'], 'group')['data']);
        $this->assertSame(['BODY <a>', 'BODY <b>', 'BODY <c>', 'BODY <d>', 'BODY <a>', 'BODY <b>', 'BODY <a>'], $nntp->commands);
    }

    #[Test]
    public function aggregate_bytes_budget_prevents_provisional_retention(): void
    {
        $nntp = Mockery::mock(NNTPService::class)->makePartial();
        $nntp->shouldReceive('getMessagesByMessageID')->with(['full1'], false)->once()->andReturn(str_repeat('X', 8_388_608));
        $nntp->shouldReceive('getMessagesByMessageID')->with(['full2'], false)->once()->andReturn(str_repeat('Y', 8_388_608));
        $nntp->shouldReceive('getArticleForArchiveMapping')->once()->andReturnUsing(static function (string $id, bool $alternate, callable $onValidated): array {
            $part = new YencPart('archive.rar', 1);
            $onValidated('A', $part);

            return ['data' => 'A', 'part' => $part];
        });
        $nntp->shouldReceive('getMessagesByMessageID')->with(['a', 'b'], false)->once()->andReturn('NETWORK');
        $service = new UsenetDownloadService($this->makeConfig(), $nntp);
        $service->beginReleaseScope();
        $service->download(DownloadKind::Sample, ['full1']);
        $service->download(DownloadKind::Sample, ['full2']);
        $service->downloadMappingArticle('a');
        $service->retainMappedArchiveArticle(new Par2FileEntry('archive.rar', 1, md5('A')));
        $this->assertSame('NETWORK', $service->download(DownloadKind::Compressed, ['a', 'b'])['data']);
        $this->assertSame(0, $service->finishReleaseScope()->cacheHits);
    }

    #[Test]
    public function exact_id_group_and_kind_isolate_provisional_articles(): void
    {
        $nntp = new PreviewNntpHarness(implode('', array_map(static fn (string $data): string => (new YencService)->encode($data, 'archive.rar')."\r\n.\r\n", ['A', 'OTHER-GROUP', 'OTHER-ID', 'MEDIA'])));
        $nntp->alternate = new PreviewNntpHarness('');
        $config = $this->makeConfig();
        $service = new UsenetDownloadService($config, $nntp);
        $service->beginReleaseScope();
        $service->downloadMappingArticle('a', 'group');
        $service->retainMappedArchiveArticle(new Par2FileEntry('archive.rar', 1, md5('A')));
        $this->assertSame('OTHER-GROUP', $service->download(DownloadKind::Compressed, ['a'], 'other-group', cache: false)['data']);
        $this->assertSame('OTHER-ID', $service->download(DownloadKind::Compressed, ['<a>'], 'group', cache: false)['data']);
        $this->assertSame('MEDIA', $service->download(DownloadKind::Sample, ['a'], 'group', cache: false)['data']);
        $this->assertSame(0, $service->finishReleaseScope()->cacheHits);
    }

    #[Test]
    public function missing_dot_terminator_and_nonarchive_mapping_cannot_be_retained(): void
    {
        $nntp = new MappingArticleNntpHarness("222 body follows\r\n".(new YencService)->encode('A', 'archive.rar')."\r\n");
        $service = new UsenetDownloadService($this->makeConfig(), $nntp);
        $service->beginReleaseScope();
        $this->assertFalse($service->downloadMappingArticle('a')['success']);
        $service->retainMappedArchiveArticle(new Par2FileEntry('archive.rar', 1, md5('A')));
        $this->assertFalse($service->download(DownloadKind::Compressed, ['a'])['success']);
        $this->assertSame(0, $service->finishReleaseScope()->cacheHits);

        $nntp = new MappingArticleNntpHarness(MappingArticleNntpHarness::body('A').MappingArticleNntpHarness::body('NETWORK'));
        $service = new UsenetDownloadService($this->makeConfig(), $nntp);
        $service->beginReleaseScope();
        $service->downloadMappingArticle('a');
        $service->retainMappedArchiveArticle(new Par2FileEntry('sample.mkv', 1, md5('A')));
        $this->assertSame('NETWORK', $service->download(DownloadKind::Compressed, ['a'])['data']);
        $this->assertSame(0, $service->finishReleaseScope()->cacheHits);
    }

    #[Test]
    #[DataProvider('orderedReuseCases')]
    public function article_reuse_preserves_socket_order_and_first_failure(array $ids, string $wire, string $expected, array $commands, int $reused): void
    {
        $nntp = new MappingArticleNntpHarness(MappingArticleNntpHarness::body('A').MappingArticleNntpHarness::body('C').$wire);
        $service = new UsenetDownloadService($this->makeConfig(), $nntp);
        $service->beginReleaseScope();
        foreach (['a' => 'A', 'c' => 'C'] as $id => $data) {
            $service->downloadMappingArticle($id, 'group');
            $service->retainMappedArchiveArticle(new Par2FileEntry('archive.rar', 1, md5($data)));
        }
        $result = $service->download(DownloadKind::Compressed, $ids, 'group', cache: false);
        $metrics = $service->finishReleaseScope();
        $this->assertSame($expected === '' ? null : $expected, $result['data']);
        $this->assertSame($expected !== '', $result['success']);
        $this->assertSame(array_merge(['BODY <a>', 'BODY <c>'], $commands), $nntp->sentCommands());
        $this->assertSame($reused, $metrics->bytesReused);
        $this->assertSame(2 + ($wire === '' ? 0 : 1), $metrics->networkRequests);
        $this->assertSame(2 + strlen($expected) - $reused, $metrics->bytesDownloaded);
    }

    public static function orderedReuseCases(): array
    {
        return [
            'all cached' => [['a', 'c'], '', 'AC', [], 2],
            'duplicate cached ID' => [['a', 'a', 'b'], MappingArticleNntpHarness::body('B'), 'AAB', ['BODY <b>'], 2],
            'missing first does not bridge cached C' => [['missing', 'c'], "430 missing\r\n", '', ['BODY <missing>'], 0],
            'missing middle does not append cached C' => [['a', 'missing', 'c'], "430 missing\r\n", 'A', ['BODY <missing>'], 1],
            'missing after cached C returns full prefix' => [['a', 'c', 'missing'], "430 missing\r\n", 'AC', ['BODY <missing>'], 2],
            'network then cache then failure' => [['b', 'c', 'missing'], MappingArticleNntpHarness::body('B')."430 missing\r\n", 'BC', ['BODY <b>', 'BODY <missing>'], 1],
        ];
    }

    #[Test]
    public function reused_articles_keep_initial_connection_failure_and_scope_boundaries(): void
    {
        $nntp = new MappingArticleNntpHarness(MappingArticleNntpHarness::body('A'));
        $service = new UsenetDownloadService($this->makeConfig(), $nntp);
        $service->beginReleaseScope();
        $service->downloadMappingArticle('a', 'group');
        $service->retainMappedArchiveArticle(new Par2FileEntry('archive.rar', 1, md5('A')));
        $nntp->doQuit();
        $nntp->connectionFailure = true;
        $result = $service->download(DownloadKind::Compressed, ['a'], 'group', cache: false);
        $this->assertFalse($result['success']);
        $this->assertSame(0, $service->finishReleaseScope()->bytesReused);
        $service->beginReleaseScope();
        $result = $service->download(DownloadKind::Compressed, ['a'], 'group');
        $this->assertFalse($result['success']);
        $this->assertSame(0, $service->finishReleaseScope()->cacheHits);
    }

    #[Test]
    public function primary_failure_uses_alternate_at_the_same_position_before_cached_later_articles(): void
    {
        $nntp = new MappingArticleNntpHarness(MappingArticleNntpHarness::body('A')."430 missing\r\n");
        $alternate = new MappingArticleNntpHarness(MappingArticleNntpHarness::body('B'));
        $nntp->alternate = $alternate;
        $service = new UsenetDownloadService($this->makeConfig(['alternateNNTP' => true]), $nntp);
        $service->beginReleaseScope();
        $service->downloadMappingArticle('a', 'group');
        $service->retainMappedArchiveArticle(new Par2FileEntry('archive.rar', 1, md5('A')));
        $result = $service->download(DownloadKind::Compressed, ['a', 'b', 'a'], 'group');
        $this->assertSame('ABA', $result['data']);
        $this->assertSame(['BODY <a>', 'BODY <b>'], $nntp->sentCommands());
        $this->assertSame(['BODY <b>'], $alternate->sentCommands());
        $this->assertSame(1, $alternate->quits);
        $this->assertSame(2, $service->finishReleaseScope()->bytesReused);
    }

    #[Test]
    public function alternate_mapping_article_never_enters_the_primary_reuse_cache(): void
    {
        $nntp = new MappingArticleNntpHarness("430 missing\r\n".MappingArticleNntpHarness::body('NETWORK'));
        $nntp->alternate = new MappingArticleNntpHarness(MappingArticleNntpHarness::body('A'));
        $service = new UsenetDownloadService($this->makeConfig(['alternateNNTP' => true]), $nntp);
        $service->beginReleaseScope();
        $this->assertSame('A', $service->downloadMappingArticle('a', 'group')['data']);
        $service->retainMappedArchiveArticle(new Par2FileEntry('archive.rar', 1, md5('A')));
        $this->assertSame('NETWORK', $service->download(DownloadKind::Compressed, ['a', 'b'], 'group')['data']);
        $this->assertSame(0, $service->finishReleaseScope()->cacheHits);
    }

    #[Test]
    #[DataProvider('ineligibleReuseBodies')]
    public function invalid_integrity_is_nonfatal_for_mapping_but_declines_article_reuse(string $raw): void
    {
        $nntp = new MappingArticleNntpHarness(MappingArticleNntpHarness::rawBody($raw).MappingArticleNntpHarness::body('NETWORK'));
        $service = new UsenetDownloadService($this->makeConfig(), $nntp);
        $service->beginReleaseScope();
        $result = $service->downloadMappingArticle('a', 'group');
        [$expected, $part] = (new YencService)->decodeWithFrame($raw."\r\n");
        $this->assertSame($expected, $result['data']);
        $this->assertEquals($part, $result['part']);
        $service->retainMappedArchiveArticle(new Par2FileEntry('archive.rar', strlen($expected), md5(substr($expected, 0, 16384))));
        $this->assertSame('NETWORK', $service->download(DownloadKind::Compressed, ['a'], 'group')['data']);
        $this->assertSame(['BODY <a>', 'BODY <a>'], $nntp->sentCommands());
        $this->assertSame(0, $service->finishReleaseScope()->cacheHits);
    }

    public static function ineligibleReuseBodies(): array
    {
        $yenc = new YencService;
        $valid = $yenc->encode('FIRST', 'archive.rar');
        $tail = $yenc->encode(str_repeat('A', 16384).'TAIL', 'archive.rar');
        $tail[strpos($tail, "\r\n=yend") - 1] = 'X';

        return [
            'bad checksum' => [preg_replace('/crc32=[0-9a-f]+/', 'crc32=00000000', $valid)],
            'missing checksum' => [$yenc->encode('FIRST', 'archive.rar', includeCrc32: false)],
            'bad size' => [str_replace('size=5', 'size=6', $valid)],
            'unframed' => ['FIRST'],
            'first 16KiB matches but tail corrupt' => [$tail],
            'bad multipart range' => [str_replace('=ybegin ', '=ybegin part=1 total=2 ', $valid)],
        ];
    }

    #[Test]
    public function verified_mapped_archive_article_is_reused_in_the_ordered_bulk_loop(): void
    {
        $yenc = new YencService;
        $wire = $yenc->encode('FIRST', 'archive.rar')."\r\n.\r\n"
            .$yenc->encode('SECOND', 'archive.rar')."\r\n.\r\n"
            .$yenc->encode('THIRD', 'archive.rar')."\r\n.\r\n";
        $nntp = new PreviewNntpHarness($wire);
        $service = new UsenetDownloadService($this->makeConfig(), $nntp);
        $service->beginReleaseScope();
        $article = $service->downloadMappingArticle('<a>', 'alt.test', 1);
        $service->retainMappedArchiveArticle(new Par2FileEntry('archive.rar', 5, md5('FIRST')));
        $result = $service->download(DownloadKind::Compressed, ['<a>', '<b>', '<c>'], 'alt.test', 1);
        $metrics = $service->finishReleaseScope();

        $this->assertSame('FIRST', $article['data']);
        $this->assertSame('FIRSTSECONDTHIRD', $result['data']);
        $this->assertSame(['BODY <a>', 'BODY <b>', 'BODY <c>'], $nntp->commands);
        $this->assertSame(2, $metrics->logicalRequests);
        $this->assertSame(2, $metrics->networkRequests);
        $this->assertSame(1, $metrics->cacheHits);
        $this->assertSame(16, $metrics->bytesDownloaded);
        $this->assertSame(5, $metrics->bytesReused);
    }

    #[Test]
    public function bounded_preview_reader_counts_wire_bytes_and_never_reads_past_its_allowance(): void
    {
        $nntp = new PreviewNntpHarness("..abc\r\n.\r\nNEXT\r\n");
        $result = $nntp->getBoundedArticleByMessageID('image@test', 10);
        $this->assertSame(".abc\r\n", $result['data']);
        $this->assertSame(10, $result['bytesDownloaded']);
        $this->assertSame("NEXT\r\n", $nntp->nextLine());

        $limited = new PreviewNntpHarness("..abc\r\n.\r\nNEXT\r\n");
        $result = $limited->getBoundedArticleByMessageID('image@test', 9);
        $this->assertNull($result['data']);
        $this->assertSame(9, $result['bytesDownloaded']);
        $this->assertSame(1, $limited->quits);
    }

    #[Test]
    public function failed_bounded_preview_keeps_partial_bytes_in_download_and_media_metrics(): void
    {
        $nntp = new PreviewNntpHarness("partial\r\n");
        $service = new UsenetDownloadService($this->makeConfig(), $nntp);
        $service->beginReleaseScope();

        $result = $service->downloadBounded(DownloadKind::Jpg, ['image@test'], 100);
        $metrics = $service->finishReleaseScope();

        $this->assertFalse($result['success']);
        $this->assertNull($result['data']);
        $this->assertSame(9, $result['bytesDownloaded']);
        $this->assertSame(9, $metrics->bytesDownloaded);
        $this->assertSame(9, $metrics->mediaBytesDownloaded);
    }

    #[Test]
    public function missing_later_image_article_does_not_discard_consumed_byte_accounting(): void
    {
        $nntp = new PreviewNntpHarness("part\r\n.\r\n", [222, 430]);
        $service = new UsenetDownloadService($this->makeConfig(), $nntp);
        $service->beginReleaseScope();
        $result = $service->downloadBounded(DownloadKind::Jpg, ['first@test', 'missing@test'], 20);
        $metrics = $service->finishReleaseScope();
        $this->assertFalse($result['success']);
        $this->assertNull($result['data']);
        $this->assertSame(9, $result['bytesDownloaded']);
        $this->assertSame(9, $metrics->mediaBytesDownloaded);
        $this->assertSame(2, $metrics->networkRequests);
    }

    #[Test]
    public function multipart_preview_applies_only_the_remaining_allowance_to_the_next_article(): void
    {
        $nntp = new PreviewNntpHarness("part\r\n.\r\nrest\r\n.\r\n");
        $service = new UsenetDownloadService($this->makeConfig(), $nntp);
        $result = $service->downloadBounded(DownloadKind::Jpg, ['first@test', 'second@test', 'third@test'], 12);
        $this->assertFalse($result['success']);
        $this->assertNull($result['data']);
        $this->assertSame(12, $result['bytesDownloaded']);
        $this->assertSame(['BODY <first@test>', 'BODY <second@test>'], $nntp->commands);
    }

    #[Test]
    public function bounded_preview_fallback_charges_primary_and_alternate_bytes_to_one_allowance(): void
    {
        $primary = new PreviewNntpHarness("partial\r\n");
        $alternate = new PreviewNntpHarness("ok\r\n.\r\n");
        $primary->alternate = $alternate;
        $result = $primary->getBoundedArticleByMessageID('image@test', 14, true);
        $this->assertNull($result['data']);
        $this->assertSame(14, $result['bytesDownloaded']);
        $this->assertSame(['BODY <image@test>'], $alternate->commands);
    }

    #[Test]
    public function it_downloads_binary_payloads_through_the_enum_driven_api(): void
    {
        $nntp = Mockery::mock(NNTPService::class);
        $nntp->shouldReceive('getMessagesByMessageID')
            ->once()
            ->with(['<abc>'], false)
            ->andReturn('BINARY-DATA');

        $service = new UsenetDownloadService($this->makeConfig(), $nntp);

        $result = $service->download(DownloadKind::Compressed, ['<abc>'], 'alt.binaries', 55, 'archive.rar');

        $this->assertTrue($result['success']);
        $this->assertSame('BINARY-DATA', $result['data']);
    }

    #[Test]
    public function it_marks_group_unavailable_errors_explicitly(): void
    {
        $error = new class
        {
            public function getMessage(): string
            {
                return 'No such news group';
            }
        };

        $nntp = Mockery::mock(NNTPService::class);
        $nntp->shouldReceive('getMessagesByMessageID')
            ->once()
            ->with(['<missing>'], false)
            ->andReturn($error);

        $service = new UsenetDownloadService($this->makeConfig(), $nntp);

        $result = $service->download(DownloadKind::Sample, ['<missing>']);

        $this->assertFalse($result['success']);
        $this->assertTrue($result['groupUnavailable']);
        $this->assertStringContainsString('Group unavailable', (string) $result['error']);
    }

    #[Test]
    public function it_reuses_exact_successful_requests_only_within_the_current_release(): void
    {
        $nntp = Mockery::mock(NNTPService::class);
        $nntp->shouldReceive('getMessagesByMessageID')
            ->twice()
            ->with(['<shared>'], false)
            ->andReturn('SHARED-DATA');

        $service = new UsenetDownloadService($this->makeConfig(), $nntp);
        $service->beginReleaseScope();

        $first = $service->download(DownloadKind::Compressed, ['<shared>'], 'alt.binaries.test', 10);
        $second = $service->download(DownloadKind::Jpg, ['<shared>'], 'alt.binaries.test', 10);
        $firstReleaseMetrics = $service->finishReleaseScope();

        $this->assertSame($first, $second);
        $this->assertSame(2, $firstReleaseMetrics->logicalRequests);
        $this->assertSame(1, $firstReleaseMetrics->networkRequests);
        $this->assertSame(1, $firstReleaseMetrics->cacheHits);
        $this->assertSame(strlen('SHARED-DATA'), $firstReleaseMetrics->bytesDownloaded);
        $this->assertSame(strlen('SHARED-DATA'), $firstReleaseMetrics->bytesReused);

        $service->beginReleaseScope();
        $service->download(DownloadKind::Compressed, ['<shared>'], 'alt.binaries.test', 11);
        $secondReleaseMetrics = $service->finishReleaseScope();

        $this->assertSame(1, $secondReleaseMetrics->logicalRequests);
        $this->assertSame(1, $secondReleaseMetrics->networkRequests);
        $this->assertSame(0, $secondReleaseMetrics->cacheHits);
    }

    #[Test]
    public function it_does_not_cache_failed_downloads(): void
    {
        $nntp = Mockery::mock(NNTPService::class);
        $nntp->shouldReceive('getMessagesByMessageID')
            ->twice()
            ->with(['<missing>'], false)
            ->andReturn('');

        $service = new UsenetDownloadService($this->makeConfig(), $nntp);
        $service->beginReleaseScope();

        $service->download(DownloadKind::Compressed, ['<missing>']);
        $service->download(DownloadKind::Compressed, ['<missing>']);
        $metrics = $service->finishReleaseScope();

        $this->assertSame(2, $metrics->networkRequests);
        $this->assertSame(0, $metrics->cacheHits);
    }

    #[Test]
    public function it_does_not_reuse_successful_downloads_outside_a_release_scope(): void
    {
        $nntp = Mockery::mock(NNTPService::class);
        $nntp->shouldReceive('getMessagesByMessageID')
            ->twice()
            ->with(['<shared>'], false)
            ->andReturn('SHARED-DATA');

        $service = new UsenetDownloadService($this->makeConfig(), $nntp);

        $first = $service->download(DownloadKind::Compressed, ['<shared>'], 'alt.binaries.test', 10);
        $second = $service->download(DownloadKind::Jpg, ['<shared>'], 'alt.binaries.test', 10);

        $this->assertSame('SHARED-DATA', $first['data']);
        $this->assertSame('SHARED-DATA', $second['data']);
    }

    #[Test]
    public function it_retries_an_exact_request_after_a_partial_provider_failure(): void
    {
        $nntp = Mockery::mock(NNTPService::class);
        $nntp->shouldReceive('getMessagesByMessageID')
            ->twice()
            ->with(['<retry>'], false)
            ->andReturn('', 'RECOVERED-DATA');

        $service = new UsenetDownloadService($this->makeConfig(), $nntp);
        $service->beginReleaseScope();

        $failed = $service->download(DownloadKind::Compressed, ['<retry>']);
        $recovered = $service->download(DownloadKind::Compressed, ['<retry>']);
        $metrics = $service->finishReleaseScope();

        $this->assertFalse($failed['success']);
        $this->assertTrue($recovered['success']);
        $this->assertSame('RECOVERED-DATA', $recovered['data']);
        $this->assertSame(2, $metrics->networkRequests);
        $this->assertSame(0, $metrics->cacheHits);
    }

    #[Test]
    public function it_checks_minimum_download_sizes(): void
    {
        $service = new UsenetDownloadService($this->makeConfig(), Mockery::mock(NNTPService::class));

        $this->assertFalse($service->meetsMinimumSize(str_repeat('a', 40)));
        $this->assertTrue($service->meetsMinimumSize(str_repeat('a', 41)));
    }

    #[Test]
    public function it_downloads_one_article_with_its_yenc_part_and_counts_it_as_preview_traffic(): void
    {
        $part = new YencPart('Movie.mkv', 5000, 2, 1001, 2000);
        $nntp = Mockery::mock(NNTPService::class);
        $nntp->shouldReceive('getArticleByMessageID')
            ->twice()
            ->with('<article>', false)
            ->andReturn(['data' => 'ARTICLE', 'part' => $part]);

        $service = new UsenetDownloadService($this->makeConfig(), $nntp);
        $service->beginReleaseScope();

        $result = $service->downloadArticle(DownloadKind::MediaSource, '<article>', 'alt.binaries.test', 10);
        $again = $service->downloadArticle(DownloadKind::MediaSource, '<article>', 'alt.binaries.test', 10);
        $metrics = $service->finishReleaseScope();

        $this->assertTrue($result['success']);
        $this->assertSame('ARTICLE', $result['data']);
        $this->assertSame($part, $result['part']);
        $this->assertSame('ARTICLE', $again['data'], 'articles are not cached, so the second call goes to the server');
        $this->assertSame(2, $metrics->networkRequests);
        $this->assertSame(14, $metrics->mediaBytesDownloaded);
    }

    #[Test]
    public function it_reuses_a_cached_download_of_the_same_single_article(): void
    {
        $nntp = Mockery::mock(NNTPService::class);
        $nntp->shouldReceive('getMessagesByMessageID')->once()->with(['<probe>'], false)->andReturn('PROBE-DATA');
        $nntp->shouldNotReceive('getArticleByMessageID');

        $service = new UsenetDownloadService($this->makeConfig(), $nntp);
        $service->beginReleaseScope();
        $service->download(DownloadKind::Compressed, ['<probe>'], 'alt.binaries.test', 10);

        $result = $service->downloadArticle(DownloadKind::MediaSource, '<probe>', 'alt.binaries.test', 10);

        $this->assertSame('PROBE-DATA', $result['data']);
        $this->assertNull($result['part']);
        $this->assertSame(1, $service->finishReleaseScope()->cacheHits);
    }

    #[Test]
    public function it_reports_an_unavailable_group_while_downloading_an_article(): void
    {
        $nntp = Mockery::mock(NNTPService::class);
        $nntp->shouldReceive('getArticleByMessageID')->once()->andReturn(new NntpError('411 No such news group'));

        $result = (new UsenetDownloadService($this->makeConfig(), $nntp))->downloadArticle(DownloadKind::Par2Index, '<par2>');

        $this->assertFalse($result['success']);
        $this->assertTrue($result['groupUnavailable']);
    }

    #[Test]
    public function it_keeps_uncached_downloads_out_of_the_release_cache(): void
    {
        $nntp = Mockery::mock(NNTPService::class);
        $nntp->shouldReceive('getMessagesByMessageID')->twice()->with(['<mid>'], false)->andReturn('MID-DATA');

        $service = new UsenetDownloadService($this->makeConfig(), $nntp);
        $service->beginReleaseScope();
        $service->download(DownloadKind::MediaSource, ['<mid>'], cache: false);
        $service->download(DownloadKind::MediaSource, ['<mid>'], cache: false);

        $this->assertSame(0, $service->finishReleaseScope()->cacheHits);
    }
}

final class PreviewNntpHarness extends NNTPService
{
    public int $quits = 0;

    /** @var list<string> */
    public array $commands = [];

    public ?self $alternate = null;

    /** @param list<int> $responses */
    public function __construct(string $wire, private array $responses = [222])
    {
        $this->_socket = fopen('php://memory', 'w+');
        fwrite($this->_socket, $wire);
        rewind($this->_socket);
        $this->_yencService = new YencService;
        $this->_configServer = $this->_currentServer = 'fixture';
        $this->_configCompressedHeaders = false;
    }

    protected function _checkConnection(bool $reSelectGroup = true): mixed
    {
        return true;
    }

    protected function _sendCommand(string $cmd): mixed
    {
        $this->commands[] = $cmd;

        return array_shift($this->responses) ?? 222;
    }

    protected function previewAlternateConnection(): NNTPService
    {
        return $this->alternate ?? throw new \RuntimeException('No fixture alternate.');
    }

    public function doConnect(bool $compression = true, bool $alternate = false): mixed
    {
        return true;
    }

    public function doQuit(bool $force = false): mixed
    {
        $this->quits++;
        if (is_resource($this->_socket)) {
            fclose($this->_socket);
        }
        $this->_socket = null;

        return true;
    }

    public function nextLine(): string|false
    {
        return fgets($this->_socket);
    }
}

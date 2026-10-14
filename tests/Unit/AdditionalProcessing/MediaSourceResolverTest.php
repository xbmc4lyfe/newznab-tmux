<?php

declare(strict_types=1);

namespace Tests\Unit\AdditionalProcessing;

use App\Models\Release;
use App\Services\AdditionalProcessing\AdditionalWorkPlanner;
use App\Services\AdditionalProcessing\Enums\DownloadKind;
use App\Services\AdditionalProcessing\Enums\MappingConfidence;
use App\Services\AdditionalProcessing\Media\MediaSourceResolver;
use App\Services\AdditionalProcessing\State\MediaByteBudget;
use App\Services\AdditionalProcessing\State\ReleaseProcessingContext;
use App\Services\AdditionalProcessing\UsenetDownloadService;
use App\Services\Yenc\YencPart;
use dariusiii\rarinfo\Par2Info;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Fixtures\MappingArticleNntpHarness;

class MediaSourceResolverTest extends TestCase
{
    use BuildsArchiveFixtures;
    use CreatesProcessingConfiguration;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    #[Test]
    public function unmatched_integrity_valid_mapping_article_is_discarded_at_the_attempt_boundary(): void
    {
        $config = $this->makeConfig(['par2MediaMapping' => true, 'par2VerifyFiles' => 1]);
        $context = $this->contextFor([
            $this->entry('<index>', 600),
            $this->entry('<a>', 20000),
            $this->entry('<b>', 20000),
        ], ['par2VerifyFiles' => 1]);
        $index = $this->par2Index([
            ['Movie.rar', 20000, str_repeat('EXPECTED-A', 2000)],
            ['Other.bin', 20000, str_repeat('EXPECTED-B', 2000)],
        ]);
        $nntp = new MappingArticleNntpHarness(MappingArticleNntpHarness::body($index, 'index.par2').MappingArticleNntpHarness::body('UNMATCHED', 'opaque'));
        $download = new UsenetDownloadService($config, $nntp);
        $download->beginReleaseScope();
        $resolver = new MediaSourceResolver($config, $download);
        $this->assertNull($resolver->resolve($context));
        $this->assertSame(['BODY <index>', 'BODY <a>'], $nntp->sentCommands());
        $this->assertNull((new \ReflectionProperty($download, 'pendingMappingArticle'))->getValue($download));
        $this->assertSame(0, (new \ReflectionProperty($download, 'cachedBytes'))->getValue($download));
    }

    #[Test]
    public function it_maps_real_media_names_when_verification_packets_precede_descriptions(): void
    {
        $name = 'Example.Movie.2020.1080p.WEB-DL.x264-GRP.mkv';
        $data = str_repeat('fixture payload ', 1200);
        $context = $this->contextFor([
            array_replace($this->entry('<index>', 600), ['title' => '"Movie.par2" yEnc (1/1)']),
            $this->entry('<movie>', 20000),
        ], ['par2VerifyFiles' => 0]);
        $download = Mockery::mock(UsenetDownloadService::class);
        $download->shouldReceive('download')->once()
            ->with(DownloadKind::Par2Index, ['<index>'], 'alt.binaries.test', 1, Mockery::any(), false)
            ->andReturn($this->ok($this->indexWithVerificationBeforeDescription($name, $data)));
        $download->shouldNotReceive('downloadMappingArticle');

        $plan = $this->resolver($context, $download, ['par2VerifyFiles' => 0])->resolve($context);

        $this->assertNotNull($plan);
        $this->assertSame([1], array_keys($plan->mappedFiles));
        $this->assertSame($name, $plan->mappedFiles[1]->file->name);
        $this->assertSame(19200, $plan->mappedFiles[1]->file->size);
        $this->assertSame('54ab0d46c0318ac3e0687e39973c5c2c', $plan->mappedFiles[1]->file->hash16k);
        $this->assertTrue($plan->mappedFiles[1]->file->matchesStartOf($data));
        $this->assertSame(MappingConfidence::UniqueSize, $plan->mappedFiles[1]->confidence);
    }

    #[Test]
    public function it_rejects_a_corrupt_descriptor_after_verification_packets(): void
    {
        $bytes = $this->indexWithVerificationBeforeDescription('Movie.mkv', str_repeat('fixture payload ', 1200));
        $bytes[strlen($bytes) - 1] = chr(ord($bytes[strlen($bytes) - 1]) ^ 1);
        $context = $this->contextFor([
            array_replace($this->entry('<index>', 600), ['title' => '"Movie.par2" yEnc (1/1)']),
            $this->entry('<movie>', 20000),
        ]);
        $download = Mockery::mock(UsenetDownloadService::class);
        $download->shouldReceive('download')->once()->andReturn($this->ok($bytes));
        $download->shouldNotReceive('downloadMappingArticle');

        $this->assertNull($this->resolver($context, $download)->resolve($context));
    }

    private function indexWithVerificationBeforeDescription(string $name, string $data): string
    {
        $index = $this->par2Index([[$name, strlen($data), $data]]);
        $mainLength = unpack('Vlength', substr($index, 8, 4))['length'];
        $description = substr($index, $mainLength);
        $fileId = substr($description, 64, 16);
        $slice = str_pad($data, 768000, "\0");
        $body = $fileId.md5($slice, true).pack('V', crc32($slice));
        $hashed = str_repeat("\x5A", 16).Par2Info::PACKET_FILEVER.$body;
        $verification = Par2Info::PACKET_MARKER.pack('V2', 64 + strlen($body), 0).md5($hashed, true).$hashed;

        return substr($index, 0, $mainLength).$verification.$description;
    }

    #[Test]
    public function it_tells_equal_sized_volumes_apart_by_the_hash_of_their_first_article(): void
    {
        $part01 = 'Rar!'.str_repeat('1', 20000);
        $part02 = 'Rar!'.str_repeat('2', 20000);
        $context = $this->contextFor([
            $this->entry('<index>', 600),
            $this->entry('<vol-a>', 52000, 2),
            $this->entry('<vol-b>', 52000, 2),
            $this->entry('<other>', 90000, 4),
        ], ['par2VerifyFiles' => 2]);

        $download = Mockery::mock(UsenetDownloadService::class);
        $download->shouldReceive('download')
            ->once()
            ->with(DownloadKind::Par2Index, ['<index>'], 'alt.binaries.test', 1, Mockery::any(), false)
            ->andReturn($this->ok($this->par2Index([
                ['Movie.part01.rar', 50000, $part01],
                ['Movie.part02.rar', 50000, $part02],
            ])));
        $download->shouldReceive('downloadMappingArticle')
            ->once()
            ->with('<vol-a>.1', 'alt.binaries.test', 1)
            ->andReturn($this->ok($part02, new YencPart('a1b2', 50000, 1, 1, 25000)));
        $download->shouldReceive('downloadMappingArticle')
            ->once()
            ->with('<vol-b>.1', 'alt.binaries.test', 1)
            ->andReturn($this->ok($part01, new YencPart('c3d4', 50000, 1, 1, 25000)));

        $plan = $this->resolver($context, $download, ['par2VerifyFiles' => 2])->resolve($context);

        $this->assertNotNull($plan);
        $this->assertSame('Movie.part02.rar', $plan->mappedFiles[1]->file->name);
        $this->assertSame('Movie.part01.rar', $plan->mappedFiles[2]->file->name);
        $this->assertSame(MappingConfidence::Verified, $plan->mappedFiles[2]->confidence);
        $this->assertSame(25000, $plan->articlePartSize);
    }

    #[Test]
    public function it_stops_verifying_after_the_configured_number_of_articles(): void
    {
        $context = $this->contextFor([
            $this->entry('<index>', 600),
            $this->entry('<vol-a>', 52000, 2),
            $this->entry('<vol-b>', 52000, 2),
            $this->entry('<vol-c>', 52000, 2),
        ], ['par2VerifyFiles' => 1]);

        $download = Mockery::mock(UsenetDownloadService::class);
        $download->shouldReceive('download')->once()->andReturn($this->ok($this->par2Index([
            ['Movie.part01.rar', 50000, 'FIRST'],
            ['Movie.part02.rar', 50000, 'SECOND'],
            ['Movie.part03.rar', 50000, 'THIRD'],
        ])));
        $download->shouldReceive('downloadMappingArticle')->once()->andReturn($this->ok('UNRELATED'));

        $this->assertNull($this->resolver($context, $download, ['par2VerifyFiles' => 1])->resolve($context));
    }

    #[Test]
    public function it_does_not_download_an_index_larger_than_the_byte_budget(): void
    {
        $context = $this->contextFor([
            $this->entry('<index>', 600),
            $this->entry('<a>', 5000),
            $this->entry('<b>', 9000),
        ], ['mediaMaxBytes' => 100]);

        $download = Mockery::mock(UsenetDownloadService::class);
        $download->shouldNotReceive('download');

        $this->assertNull($this->resolver($context, $download, ['mediaMaxBytes' => 100])->resolve($context));
    }

    #[Test]
    public function exhausted_image_budget_blocks_par2_even_without_a_size_estimate(): void
    {
        $context = $this->contextFor([$this->entry('<index>', 0), $this->entry('<a>', 5000)]);
        $context->mediaBudget = new MediaByteBudget(8);
        $context->mediaBudget->spend(8);
        $download = Mockery::mock(UsenetDownloadService::class);
        $download->shouldNotReceive('download');
        $download->shouldNotReceive('downloadMappingArticle');

        $this->assertNull($this->resolver($context, $download)->resolve($context));
    }

    #[Test]
    public function it_marks_the_group_unavailable_when_the_index_cannot_be_fetched(): void
    {
        $context = $this->contextFor([
            $this->entry('<index>', 600),
            $this->entry('<a>', 5000),
            $this->entry('<b>', 9000),
        ]);

        $download = Mockery::mock(UsenetDownloadService::class);
        $download->shouldReceive('download')->once()->andReturn(
            ['success' => false, 'data' => null, 'groupUnavailable' => true, 'error' => 'Group unavailable'],
        );

        $this->assertNull($this->resolver($context, $download)->resolve($context));
        $this->assertTrue($context->groupUnavailable);
    }

    /**
     * @param  list<array<string, mixed>>  $nzb
     * @param  array<string, mixed>  $config
     */
    private function contextFor(array $nzb, array $config = []): ReleaseProcessingContext
    {
        $context = new ReleaseProcessingContext(new Release(['id' => 1, 'guid' => 'guid-1']));
        $context->releaseGroupName = 'alt.binaries.test';
        $context->nzbContents = $nzb;
        $context->workPlan = (new AdditionalWorkPlanner($this->makeConfig(['par2MediaMapping' => true, ...$config])))
            ->plan($nzb, 'alt.binaries.test');

        return $context;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function resolver(ReleaseProcessingContext $context, UsenetDownloadService $download, array $config = []): MediaSourceResolver
    {
        $download->shouldReceive('retainMappedArchiveArticle')->zeroOrMoreTimes()->andReturnNull();
        $download->shouldReceive('discardPendingMappingArticle')->zeroOrMoreTimes()->andReturnNull();

        return new MediaSourceResolver($this->makeConfig(['par2MediaMapping' => true, ...$config]), $download);
    }

    /**
     * @return array{title: string, size: int, filecount: int, segments: list<string>}
     */
    private function entry(string $id, int $size, int $segments = 1): array
    {
        return [
            'title' => '"'.md5($id).'" yEnc (1/'.$segments.')',
            'size' => $size,
            'filecount' => 1,
            'segments' => $segments === 1
                ? [$id]
                : array_map(static fn (int $part): string => $id.'.'.$part, range(1, $segments)),
        ];
    }

    /**
     * @return array{success: true, data: string, part: YencPart|null, groupUnavailable: false, error: null}
     */
    private function ok(string $data, ?YencPart $part = null): array
    {
        return ['success' => true, 'data' => $data, 'part' => $part, 'groupUnavailable' => false, 'error' => null];
    }
}

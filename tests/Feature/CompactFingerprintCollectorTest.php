<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\CollectReleaseFingerprintMetadata;
use App\Services\NNTP\NntpArticleMissingException;
use App\Services\NNTP\NntpProviderUnavailableException;
use App\Services\NNTP\NNTPService;
use App\Services\Nzb\NzbService;
use App\Services\Releases\Fingerprint\CompactFingerprintCollector;
use App\Services\Releases\Fingerprint\NzbFingerprintAnalyzer;
use App\Services\Releases\Fingerprint\Par2MetadataParser;
use App\Services\Releases\Fingerprint\ReleaseFingerprintCollector;
use App\Services\YencService;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Fixtures\ArchiveParsingBenchmarkFixtures;
use Tests\Fixtures\YencArticles;
use Tests\TestCase;

final class CompactFingerprintCollectorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'cache.default' => 'array',
            'nntmux_nntp.server' => 'news.primary.invalid',
            'nntmux_nntp.alternate_server' => 'news.alternate.invalid',
            'nntmux_nntp.alternate_server_port' => '',
            'nntmux_nntp.alternate_server_ssl' => false,
            'nntmux.fingerprint_alternate_percent' => 0,
        ]);
    }

    #[Test]
    public function it_samples_the_par2_index_then_the_largest_files_within_the_limit(): void
    {
        $manifest = (new NzbFingerprintAnalyzer(new YencService))->analyzeManifest(self::nzb(8, 5));
        $omitted = 0;

        $samples = CompactFingerprintCollector::selectSamples($manifest['files'], $omitted);

        $this->assertCount(CompactFingerprintCollector::SAMPLE_LIMIT, $samples);
        $this->assertSame(['par2', 1], [$samples[0]['role'], $samples[0]['segment_number']]);
        $this->assertSame('movie.par2', self::subjectName($manifest['files'], $samples[0]['file_index']));
        // The three largest RAR volumes (part06, part05, part04 in this fixture) get first/middle/last, largest first.
        $this->assertSame(
            [['movie.part06.rar', 'first'], ['movie.part06.rar', 'middle'], ['movie.part06.rar', 'last'],
                ['movie.part05.rar', 'first'], ['movie.part05.rar', 'middle'], ['movie.part05.rar', 'last']],
            array_map(fn (array $sample): array => [self::subjectName($manifest['files'], $sample['file_index']), $sample['role']], array_slice($samples, 1, 6)),
        );
        $this->assertSame(['first'], array_values(array_unique(array_column(array_slice($samples, 10), 'role'))));
        $this->assertGreaterThan(0, $omitted);
        $this->assertSame(count($samples), count(array_unique(array_column($samples, 'messageid'))));
    }

    #[Test]
    public function it_writes_a_small_complete_record_from_the_primary_only(): void
    {
        $nntp = $this->mock(NNTPService::class);
        $nntp->shouldReceive('getFingerprintServerProfile')->once()->with(false)->andReturn(self::profile() + ['implementation' => 'IMPLEMENTATION Fixture']);
        $nntp->shouldNotReceive('getFingerprintServerProfile')->with(true);
        $nntp->shouldReceive('getFingerprintArticleByMessageID')->times(CompactFingerprintCollector::SAMPLE_LIMIT)
            ->withArgs(fn (string $id, int $body, int $header, bool $alternate): bool => $alternate === false)
            // f0/f1 are the single-segment PAR2 files; RAR volumes have five segments.
            ->andReturnUsing(fn (string $id): array => self::segmentArticle($id, preg_match('/\Af[01]-/', $id) === 1 ? 1 : 5));

        $result = $this->collector()->collectFromNzb(self::nzb(120, 5), 7);
        $data = $result['data'];

        $this->assertSame('complete', $result['status']);
        $this->assertSame([CompactFingerprintCollector::FORMAT, CompactFingerprintCollector::SCHEMA_VERSION], [$data['format'], $data['schema_version']]);
        $this->assertSame(['primary'], array_column($data['providers'], 'account'));
        $this->assertSame(['requested' => 12, 'found' => 12, 'missing' => 0, 'errors' => 0], $data['providers'][0]['articles']);
        $this->assertSame('IMPLEMENTATION Fixture', $data['providers'][0]['implementation']);
        $this->assertCount(12, $data['articles']);
        $this->assertSame(['name' => 'segment.bin', 'size' => 80, 'part' => 1, 'total' => 5, 'begin' => 1, 'end' => 16, 'crc_ok' => true, 'size_ok' => true], $data['articles'][1]['yenc']);
        // Single-part yEnc (the PAR2 index) carries no part, total or range fields.
        $this->assertSame([null, null], [$data['articles'][0]['yenc']['part'], $data['articles'][0]['yenc']['total']]);
        $this->assertArrayNotHasKey('date_utc', $data['articles'][1]);
        $this->assertSame(['ngPost'], $data['uploader_software_candidates']);
        // Two single-segment PAR2 files plus 120 five-segment RAR volumes.
        $this->assertSame(['file_count' => 122, 'segment_count' => 2 + 120 * 5], array_intersect_key($data['nzb'], ['file_count' => 1, 'segment_count' => 1]));
        $this->assertSame(['alt.binaries.test'], $data['nzb']['groups']);
        $this->assertSame(['first_utc' => '2026-10-01T00:00:00Z', 'last_utc' => '2026-10-01T00:02:01Z', 'span_s' => 121], $data['posting']);
        $this->assertSame([], $data['errors']);
        // A 122-file release stays far below the 20 KB target.
        $this->assertLessThan(20_480, strlen(json_encode($data, JSON_THROW_ON_ERROR)));
    }

    #[Test]
    public function missing_articles_make_the_record_partial(): void
    {
        $nntp = $this->mock(NNTPService::class);
        $nntp->shouldReceive('getFingerprintServerProfile')->once()->with(false)->andReturn(self::profile());
        $nntp->shouldReceive('getFingerprintArticleByMessageID')->times(3)
            ->andReturnUsing(function (string $id): array {
                if (str_starts_with($id, 'f1-')) {
                    throw new NntpArticleMissingException('Article headers are unavailable.');
                }

                return self::article($id);
            });

        $result = $this->collector()->collectFromNzb(self::nzb(3, 1, false), 7);

        $this->assertSame('partial', $result['status']);
        $this->assertSame(['requested' => 3, 'found' => 2, 'missing' => 1, 'errors' => 0], $result['data']['providers'][0]['articles']);
        $this->assertSame([['account' => 'primary', 'error_type' => 'NntpArticleMissingException', 'count' => 1]], $result['data']['errors']);
    }

    #[Test]
    public function a_sampled_release_also_queries_the_alternate_and_honours_the_breaker(): void
    {
        config(['nntmux.fingerprint_alternate_percent' => 100]);
        $nntp = $this->mock(NNTPService::class);
        $nntp->shouldReceive('getFingerprintServerProfile')->twice()->with(false)->andReturn(self::profile());
        $nntp->shouldReceive('getFingerprintServerProfile')->once()->with(true)
            ->andThrow(new NntpProviderUnavailableException('Unable to connect to the requested NNTP provider.'));
        $nntp->shouldReceive('getFingerprintArticleByMessageID')->times(6)
            ->withArgs(fn (string $id, int $body, int $header, bool $alternate): bool => $alternate === false)
            ->andReturnUsing(fn (string $id): array => self::article($id));

        $first = $this->collector()->collectFromNzb(self::nzb(3, 1, false), 7)['data'];
        $second = $this->collector()->collectFromNzb(self::nzb(3, 1, false), 8)['data'];

        $this->assertSame(['primary', 'alternate'], array_column($first['providers'], 'account'));
        $this->assertSame('NntpProviderUnavailableException', $first['providers'][1]['profile']);
        $this->assertSame('recent_provider_failure', $second['providers'][1]['skipped_reason']);
        $this->assertSame([['account' => 'alternate', 'error_type' => 'NntpProviderUnavailableException', 'count' => 4]], $first['errors']);
        $this->assertSame([['account' => 'alternate', 'error_type' => 'NntpProviderUnavailableException', 'count' => 3]], $second['errors']);
    }

    #[Test]
    public function it_summarises_the_par2_index_and_matches_its_files_to_the_nzb(): void
    {
        $par2 = ArchiveParsingBenchmarkFixtures::par2(2);
        $data = str_repeat('fixture payload ', 1200);
        $nntp = $this->mock(NNTPService::class);
        $nntp->shouldReceive('getFingerprintServerProfile')->once()->with(false)->andReturn(self::profile());
        $nntp->shouldReceive('getFingerprintArticleByMessageID')->times(3)
            ->andReturnUsing(fn (string $id): array => ['body' => YencArticles::article($id === 'par2-index@fixture' ? $par2 : $data)] + self::article($id));
        $xml = '<?xml version="1.0" encoding="UTF-8"?><nzb xmlns="http://www.newzbin.com/DTD/2003/nzb">';
        foreach (['par2-index' => 'movie.par2', 'f0' => 'offline-0000.bin', 'f1' => 'offline-0001.bin'] as $id => $name) {
            $xml .= '<file poster="p@example" date="1790812800" subject="&quot;'.$name.'&quot; yEnc (1/1)"><groups><group>a.b.test</group></groups>'
                .'<segments><segment bytes="20000" number="1">'.$id.'@fixture</segment></segments></file>';
        }

        $summary = $this->collector()->collectFromNzb($xml.'</nzb>', 7)['data']['par2'];

        $this->assertSame(16384, $summary['block_size']);
        $this->assertSame(2, $summary['nzb_matches']);
        $this->assertMatchesRegularExpression('/\\A[0-9a-f]{32}\\z/', (string) $summary['set_id']);
        $this->assertSame(2, $summary['file_count']);
        $this->assertSame([
            ['name' => 'offline-0000.bin', 'size' => 19_200, 'md5' => md5($data), 'md5_16k' => md5(substr($data, 0, 16384))],
            ['name' => 'offline-0001.bin', 'size' => 19_200, 'md5' => md5($data), 'md5_16k' => md5(substr($data, 0, 16384))],
        ], $summary['files']);
    }

    #[Test]
    public function long_names_and_huge_releases_stay_within_the_record_budget(): void
    {
        $nntp = $this->mock(NNTPService::class);
        $nntp->shouldReceive('getFingerprintServerProfile')->once()->with(false)->andReturn(self::profile());
        $nntp->shouldReceive('getFingerprintArticleByMessageID')->andReturnUsing(fn (string $id): array => self::article($id));
        $xml = '<?xml version="1.0" encoding="UTF-8"?><nzb xmlns="http://www.newzbin.com/DTD/2003/nzb">';
        for ($index = 0; $index < 400; $index++) {
            $xml .= '<file poster="p@example" date="1790812800" subject="&quot;'.str_repeat('n', 300).$index.'.rar&quot; yEnc (1/1)">'
                .'<groups><group>'.str_repeat('g', 5_000).'</group><group>alt.binaries.'.$index.'</group></groups>'
                .'<segments><segment bytes="1000" number="1">f'.$index.'@fixture</segment></segments></file>';
        }

        $data = $this->collector()->collectFromNzb($xml.'</nzb>', 7)['data'];

        $this->assertLessThanOrEqual(CompactFingerprintCollector::MAX_RECORD_BYTES, strlen(json_encode($data, JSON_THROW_ON_ERROR)));
        $this->assertTrue($data['truncated']);
        $this->assertNotEmpty($data['files']);
        $this->assertLessThanOrEqual(120, max(array_map(mb_strlen(...), $data['nzb']['groups'])));
    }

    #[Test]
    public function multibyte_names_cannot_push_a_record_past_the_budget(): void
    {
        $name = str_repeat('名', 2_000);
        $nntp = $this->mock(NNTPService::class);
        $nntp->shouldReceive('getFingerprintServerProfile')->once()->with(false)->andReturn(self::profile() + ['implementation' => $name]);
        $nntp->shouldReceive('getFingerprintArticleByMessageID')->times(12)->andReturnUsing(fn (string $id): array => [
            'body' => str_replace('name=fixture file.bin', 'name='.$name, YencArticles::article('0123456789abcdef')),
        ] + self::article($id));
        $xml = '<?xml version="1.0" encoding="UTF-8"?><nzb xmlns="http://www.newzbin.com/DTD/2003/nzb">';
        for ($index = 0; $index < 12; $index++) {
            $xml .= '<file poster="'.$name.'" date="1790812800" subject="&quot;'.$name.$index.'.rar&quot; yEnc (1/1)"><groups>';
            for ($group = 0; $group < 30; $group++) {
                $xml .= '<group>'.$name.$group.'</group>';
            }
            $xml .= '</groups><segments><segment bytes="1000" number="1">f'.$index.'@fixture</segment></segments></file>';
        }

        $data = $this->collector()->collectFromNzb($xml.'</nzb>', 7)['data'];

        // Names are cut by UTF-8 bytes, so even escaped multibyte text stays far inside the budget.
        $this->assertLessThanOrEqual(CompactFingerprintCollector::MAX_RECORD_BYTES, strlen(json_encode($data, JSON_THROW_ON_ERROR)));
        $this->assertLessThanOrEqual(120, strlen((string) $data['providers'][0]['implementation']));
        $this->assertLessThanOrEqual(120, strlen((string) $data['articles'][0]['yenc']['name']));
        $this->assertTrue(mb_check_encoding((string) $data['articles'][0]['yenc']['name'], 'UTF-8'));
    }

    #[Test]
    public function an_unusable_par2_index_does_not_block_a_later_volume(): void
    {
        $par2 = ArchiveParsingBenchmarkFixtures::par2(1);
        $nntp = $this->mock(NNTPService::class);
        $nntp->shouldReceive('getFingerprintServerProfile')->once()->with(false)->andReturn(self::profile());
        $nntp->shouldReceive('getFingerprintArticleByMessageID')->times(3)->andReturnUsing(fn (string $id): array => [
            'body' => YencArticles::article($id === 'volume@fixture' ? $par2 : 'not a par2 packet stream'),
        ] + self::article($id));
        $xml = '<?xml version="1.0" encoding="UTF-8"?><nzb xmlns="http://www.newzbin.com/DTD/2003/nzb">';
        foreach (['index' => ['movie.par2', 100], 'volume' => ['movie.vol00+01.par2', 900], 'data' => ['offline-0000.bin', 5000]] as $id => [$fileName, $bytes]) {
            $xml .= '<file poster="p@example" date="1790812800" subject="&quot;'.$fileName.'&quot; yEnc (1/1)"><groups><group>a.b.test</group></groups>'
                .'<segments><segment bytes="'.$bytes.'" number="1">'.$id.'@fixture</segment></segments></file>';
        }

        $summary = $this->collector()->collectFromNzb($xml.'</nzb>', 7)['data']['par2'];

        $this->assertSame(['offline-0000.bin'], array_column($summary['files'], 'name'));
    }

    #[Test]
    public function a_creator_only_par2_volume_does_not_block_a_later_volume(): void
    {
        $setId = md5('creator-only', true);
        $body = str_pad('Creator fixture', 16, "\0");
        $hashed = $setId."PAR 2.0\0Creator\0".$body;
        $creatorOnly = "PAR2\0PKT".pack('V2', strlen($body) + 64, 0).md5($hashed, true).$hashed;
        $par2 = ArchiveParsingBenchmarkFixtures::par2(1);
        $nntp = $this->mock(NNTPService::class);
        $nntp->shouldReceive('getFingerprintServerProfile')->once()->with(false)->andReturn(self::profile());
        $nntp->shouldReceive('getFingerprintArticleByMessageID')->times(3)->andReturnUsing(fn (string $id): array => [
            'body' => YencArticles::article(match ($id) {
                'index@fixture' => $creatorOnly,
                'volume@fixture' => $par2,
                default => 'payload',
            }),
        ] + self::article($id));

        $summary = $this->collector()->collectFromNzb(self::par2Nzb(['offline-0000.bin']), 7)['data']['par2'];

        $this->assertSame(['offline-0000.bin'], array_column($summary['files'], 'name'));
    }

    #[Test]
    public function par2_names_match_nzb_files_exactly_and_before_shortening(): void
    {
        $nntp = $this->mock(NNTPService::class);
        $nntp->shouldReceive('getFingerprintServerProfile')->once()->with(false)->andReturn(self::profile());
        $nntp->shouldReceive('getFingerprintArticleByMessageID')->times(4)->andReturnUsing(fn (string $id): array => [
            'body' => YencArticles::article($id === 'index@fixture' ? ArchiveParsingBenchmarkFixtures::par2(1) : 'payload'),
        ] + self::article($id));

        // The PAR2 describes offline-0000.bin; the NZB only has names sharing a prefix or suffix with it.
        $summary = $this->collector()->collectFromNzb(self::par2Nzb(['offline-0000.bin.nfo', 'xoffline-0000.bin']), 7)['data']['par2'];

        $this->assertSame(0, $summary['nzb_matches']);
    }

    #[Test]
    public function a_crc_mismatch_is_reported_and_keeps_the_record_partial(): void
    {
        $nntp = $this->mock(NNTPService::class);
        $nntp->shouldReceive('getFingerprintServerProfile')->once()->with(false)->andReturn(self::profile());
        $nntp->shouldReceive('getFingerprintArticleByMessageID')->times(3)->andReturnUsing(function (string $id): array {
            $article = self::article($id);
            if (str_starts_with($id, 'f1-')) {
                $article['body'] = (string) preg_replace('/crc32=[0-9a-f]{8}/', 'crc32=00000000', $article['body']);
            }

            return $article;
        });

        $result = $this->collector()->collectFromNzb(self::nzb(3, 1, false), 7);

        $this->assertSame('partial', $result['status']);
        $this->assertSame([['account' => 'primary', 'error_type' => 'YencCrcMismatch', 'count' => 1]], $result['data']['errors']);
        $this->assertFalse($result['data']['articles'][array_search(1, array_column($result['data']['articles'], 'file_index'), true)]['yenc']['crc_ok']);
    }

    #[Test]
    public function a_yenc_size_mismatch_is_reported_even_with_a_valid_crc(): void
    {
        $nntp = $this->mock(NNTPService::class);
        $nntp->shouldReceive('getFingerprintServerProfile')->once()->with(false)->andReturn(self::profile());
        $nntp->shouldReceive('getFingerprintArticleByMessageID')->times(3)->andReturnUsing(function (string $id): array {
            $article = self::article($id);
            if (str_starts_with($id, 'f2-')) {
                $article['body'] = (string) preg_replace('/=yend size=16/', '=yend size=999', $article['body']);
            }

            return $article;
        });

        $result = $this->collector()->collectFromNzb(self::nzb(3, 1, false), 7);

        $this->assertSame('partial', $result['status']);
        $this->assertSame([['account' => 'primary', 'error_type' => 'YencSizeMismatch', 'count' => 1]], $result['data']['errors']);
        $article = $result['data']['articles'][array_search(2, array_column($result['data']['articles'], 'file_index'), true)];
        $this->assertSame([true, false], [$article['yenc']['crc_ok'], $article['yenc']['size_ok']]);
    }

    /** @return array<string, array{callable(string): string}> */
    public static function invalidMultipartBodies(): array
    {
        return [
            'range outside the file' => [static fn (string $body): string => str_replace('=ypart begin=1 end=16', '=ypart begin=90 end=105', $body)],
            'trailer part disagrees' => [static fn (string $body): string => str_replace('=yend size=16 part=1', '=yend size=16 part=2', $body)],
            'non-numeric header size' => [static fn (string $body): string => str_replace('size=32 name=', 'size=abc name=', $body)],
            'malformed part CRC' => [static fn (string $body): string => (string) preg_replace('/pcrc32=[0-9a-f]{8}/', 'pcrc32=XYZ', $body)],
            'missing trailer' => [static fn (string $body): string => (string) preg_replace('/\r\n=yend[^\r\n]*/', '', $body)],
        ];
    }

    /** @return array<string, array{callable(string): string}> */
    public static function unverifiableSinglePartBodies(): array
    {
        return [
            'malformed CRC' => [static fn (string $body): string => (string) preg_replace('/crc32=[0-9a-f]{8}/', 'crc32=XYZ', $body)],
            'missing CRC' => [static fn (string $body): string => (string) preg_replace('/ crc32=[0-9a-f]{8}/', '', $body)],
            'missing trailer' => [static fn (string $body): string => (string) preg_replace('/\r\n=yend[^\r\n]*/', '', $body)],
            'trailer without size' => [static fn (string $body): string => str_replace('=yend size=16 ', '=yend ', $body)],
            'not yEnc at all' => [static fn (string $body): string => "plain text body\r\n"],
            'malformed secondary CRC' => [static fn (string $body): string => $body.' pcrc32=XYZ'],
            'contradictory secondary CRC' => [static fn (string $body): string => $body.' pcrc32=00000000'],
        ];
    }

    /** @param callable(string): string $corrupt */
    #[Test]
    #[DataProvider('unverifiableSinglePartBodies')]
    public function an_unverifiable_single_part_article_keeps_the_record_partial(callable $corrupt): void
    {
        $nntp = $this->mock(NNTPService::class);
        $nntp->shouldReceive('getFingerprintServerProfile')->once()->with(false)->andReturn(self::profile());
        $nntp->shouldReceive('getFingerprintArticleByMessageID')->times(3)->andReturnUsing(function (string $id) use ($corrupt): array {
            $article = self::article($id);
            if (str_starts_with($id, 'f1-')) {
                $article['body'] = $corrupt($article['body']);
            }

            return $article;
        });

        $result = $this->collector()->collectFromNzb(self::nzb(3, 1, false), 7);

        $this->assertSame('partial', $result['status']);
        $this->assertNotSame([], $result['data']['errors']);
    }

    /** @param callable(string): string $corrupt */
    #[Test]
    #[DataProvider('invalidMultipartBodies')]
    public function invalid_yenc_structure_keeps_the_record_partial(callable $corrupt): void
    {
        $nntp = $this->mock(NNTPService::class);
        $nntp->shouldReceive('getFingerprintServerProfile')->once()->with(false)->andReturn(self::profile());
        $nntp->shouldReceive('getFingerprintArticleByMessageID')->times(6)->andReturnUsing(function (string $id) use ($corrupt): array {
            $article = self::segmentArticle($id, 2);

            return $id === 'f1-s1@fixture' ? ['body' => $corrupt($article['body'])] + $article : $article;
        });

        $result = $this->collector()->collectFromNzb(self::nzb(3, 2, false), 7);

        $this->assertSame('partial', $result['status']);
        $this->assertNotSame([], $result['data']['errors']);
    }

    #[Test]
    public function a_valid_multipart_article_is_not_reported(): void
    {
        $nntp = $this->mock(NNTPService::class);
        $nntp->shouldReceive('getFingerprintServerProfile')->once()->with(false)->andReturn(self::profile());
        $nntp->shouldReceive('getFingerprintArticleByMessageID')->times(6)->andReturnUsing(fn (string $id): array => self::segmentArticle($id, 2));

        $result = $this->collector()->collectFromNzb(self::nzb(3, 2, false), 7);

        $this->assertSame([], $result['data']['errors']);
        $this->assertSame('complete', $result['status']);
    }

    #[Test]
    public function an_empty_single_part_file_is_complete(): void
    {
        $nntp = $this->mock(NNTPService::class);
        $nntp->shouldReceive('getFingerprintServerProfile')->once()->with(false)->andReturn(self::profile());
        $nntp->shouldReceive('getFingerprintArticleByMessageID')->times(3)
            ->andReturnUsing(fn (string $id): array => ['body' => YencArticles::article('')] + self::article($id));

        $result = $this->collector()->collectFromNzb(self::nzb(3, 1, false), 7);

        $this->assertSame([], $result['data']['errors']);
        $this->assertSame('complete', $result['status']);
    }

    /** @return array<string, array{string}> */
    public static function invalidEmptyFileHeaders(): array
    {
        return ['zero line length' => ['line=0'], 'non-numeric line length' => ['line=abc']];
    }

    #[Test]
    #[DataProvider('invalidEmptyFileHeaders')]
    public function an_empty_file_with_another_invalid_numeric_field_is_partial(string $line): void
    {
        $nntp = $this->mock(NNTPService::class);
        $nntp->shouldReceive('getFingerprintServerProfile')->once()->with(false)->andReturn(self::profile());
        $nntp->shouldReceive('getFingerprintArticleByMessageID')->times(3)
            ->andReturnUsing(fn (string $id): array => ['body' => str_replace('line=128', $line, YencArticles::article(''))] + self::article($id));

        $result = $this->collector()->collectFromNzb(self::nzb(3, 1, false), 7);

        $this->assertSame('partial', $result['status']);
        $this->assertNotSame([], $result['data']['errors']);
    }

    #[Test]
    public function size_ok_requires_affirmative_size_agreement(): void
    {
        $nntp = $this->mock(NNTPService::class);
        $nntp->shouldReceive('getFingerprintServerProfile')->once()->with(false)->andReturn(self::profile());
        $nntp->shouldReceive('getFingerprintArticleByMessageID')->times(3)->andReturnUsing(function (string $id): array {
            $article = self::article($id);
            if (str_starts_with($id, 'f1-')) {
                $article['body'] = str_replace('=yend size=16 ', '=yend ', $article['body']);
            }

            return $article;
        });

        $articles = $this->collector()->collectFromNzb(self::nzb(3, 1, false), 7)['data']['articles'];
        $byFile = array_column($articles, 'yenc', 'file_index');

        $this->assertTrue($byFile[0]['size_ok']);
        $this->assertNull($byFile[1]['size_ok']);
    }

    #[Test]
    public function retrieval_timings_do_not_change_what_a_record_keeps(): void
    {
        $budget = new \ReflectionMethod(CompactFingerprintCollector::class, 'withinBudget');
        $record = static function (int $ms, int $files): array {
            return [
                'providers' => [['account' => 'primary', 'retrieval_ms' => ['p50' => $ms, 'max' => $ms, 'total' => $ms * 12]]],
                'articles' => array_fill(0, 12, ['provider_results' => ['primary' => ['code' => 222, 'bytes' => 1, 'ms' => $ms]], 'yenc' => null]),
                'files' => array_map(static fn (int $index): array => ['file_index' => $index, 'name' => str_repeat('x', 60)], range(1, $files)),
                'par2' => null,
                'nzb' => ['groups' => []],
                'truncated' => false,
            ];
        };
        // Grow the file list until a fast run (0 ms) only just fits the budget.
        $files = 1;
        while (strlen(json_encode($record(0, $files + 1), JSON_THROW_ON_ERROR)) <= CompactFingerprintCollector::MAX_RECORD_BYTES) {
            $files++;
        }

        $fast = $budget->invoke(null, $record(0, $files));
        $slow = $budget->invoke(null, $record(987_654, $files));

        $this->assertSame(count($fast['files']), count($slow['files']));
        $this->assertSame($fast['truncated'], $slow['truncated']);
    }

    #[Test]
    public function capped_file_lists_set_the_truncated_flag(): void
    {
        $nntp = $this->mock(NNTPService::class);
        $nntp->shouldReceive('getFingerprintServerProfile')->twice()->with(false)->andReturn(self::profile());
        $nntp->shouldReceive('getFingerprintArticleByMessageID')->andReturnUsing(fn (string $id): array => self::article($id));

        $data = $this->collector()->collectFromNzb(self::nzb(201, 1, false), 7)['data'];

        $this->assertLessThan(201, count($data['files']));
        $this->assertTrue($data['truncated']);
        $this->assertFalse($this->collector()->collectFromNzb(self::nzb(3, 1, false), 8)['data']['truncated']);
    }

    #[Test]
    public function a_failed_provider_profile_keeps_the_record_partial(): void
    {
        $nntp = $this->mock(NNTPService::class);
        $nntp->shouldReceive('getFingerprintServerProfile')->once()->with(false)->andThrow(new \RuntimeException('CAPABILITIES failed.'));
        $nntp->shouldReceive('getFingerprintArticleByMessageID')->times(3)->andReturnUsing(fn (string $id): array => self::article($id));

        $result = $this->collector()->collectFromNzb(self::nzb(3, 1, false), 7);

        $this->assertSame('partial', $result['status']);
        $this->assertSame('RuntimeException', $result['data']['providers'][0]['profile']);
        $this->assertSame([['account' => 'primary', 'error_type' => 'RuntimeException', 'count' => 1]], $result['data']['errors']);
    }

    #[Test]
    public function a_later_par2_volume_supplies_the_summary_when_the_index_is_missing(): void
    {
        $par2 = ArchiveParsingBenchmarkFixtures::par2(1);
        $nntp = $this->mock(NNTPService::class);
        $nntp->shouldReceive('getFingerprintServerProfile')->once()->with(false)->andReturn(self::profile());
        $nntp->shouldReceive('getFingerprintArticleByMessageID')->times(3)->andReturnUsing(function (string $id) use ($par2): array {
            if ($id === 'index@fixture') {
                throw new NntpArticleMissingException('Article headers are unavailable.');
            }

            return ['body' => YencArticles::article($id === 'volume@fixture' ? $par2 : 'payload')] + self::article($id);
        });
        $xml = '<?xml version="1.0" encoding="UTF-8"?><nzb xmlns="http://www.newzbin.com/DTD/2003/nzb">';
        foreach (['index' => ['movie.par2', 100], 'volume' => ['movie.vol00+01.par2', 900], 'data' => ['offline-0000.bin', 5000]] as $id => [$name, $bytes]) {
            $xml .= '<file poster="p@example" date="1790812800" subject="&quot;'.$name.'&quot; yEnc (1/1)"><groups><group>a.b.test</group></groups>'
                .'<segments><segment bytes="'.$bytes.'" number="1">'.$id.'@fixture</segment></segments></file>';
        }

        $summary = $this->collector()->collectFromNzb($xml.'</nzb>', 7)['data']['par2'];

        $this->assertSame(['offline-0000.bin'], array_column($summary['files'], 'name'));
        $this->assertSame(1, $summary['nzb_matches']);
    }

    #[Test]
    public function the_alternate_percentage_selects_a_stable_subset_of_releases(): void
    {
        config(['nntmux.fingerprint_alternate_percent' => 10]);
        $selected = array_filter(range(1, 10_000), CompactFingerprintCollector::comparesAlternate(...));

        $this->assertEqualsWithDelta(1_000, count($selected), 150);
        $this->assertSame(CompactFingerprintCollector::comparesAlternate(4242), CompactFingerprintCollector::comparesAlternate(4242));
        config(['nntmux.fingerprint_alternate_percent' => 0]);
        $this->assertSame([], array_filter(range(1, 1_000), CompactFingerprintCollector::comparesAlternate(...)));
    }

    #[Test]
    public function only_records_of_the_configured_format_and_version_are_current(): void
    {
        $compact = ['format' => CompactFingerprintCollector::FORMAT, 'schema_version' => CompactFingerprintCollector::SCHEMA_VERSION];
        $full = ['schema_version' => ReleaseFingerprintCollector::SCHEMA_VERSION];

        config(['nntmux.fingerprint_format' => 'compact']);
        $this->assertTrue(CollectReleaseFingerprintMetadata::isCurrent($compact));
        $this->assertFalse(CollectReleaseFingerprintMetadata::isCurrent($full));
        $this->assertFalse(CollectReleaseFingerprintMetadata::isCurrent(['format' => CompactFingerprintCollector::FORMAT, 'schema_version' => 0]));
        $this->assertFalse(CollectReleaseFingerprintMetadata::isCurrent(null));
        foreach ([1.9, true, '1', '1garbage'] as $loose) {
            $this->assertFalse(CollectReleaseFingerprintMetadata::isCurrent(['format' => CompactFingerprintCollector::FORMAT, 'schema_version' => $loose]));
        }
        // A newer version left behind by a rollback is collected again too.
        $this->assertFalse(CollectReleaseFingerprintMetadata::isCurrent(['format' => CompactFingerprintCollector::FORMAT, 'schema_version' => CompactFingerprintCollector::SCHEMA_VERSION + 1]));

        config(['nntmux.fingerprint_format' => 'full']);
        $this->assertTrue(CollectReleaseFingerprintMetadata::isCurrent($full));
        $this->assertFalse(CollectReleaseFingerprintMetadata::isCurrent($compact));
    }

    #[Test]
    public function a_profile_that_failed_without_throwing_keeps_the_record_partial(): void
    {
        $nntp = $this->mock(NNTPService::class);
        $nntp->shouldReceive('getFingerprintServerProfile')->once()->with(false)
            ->andReturn(['capabilities_response_code' => null, 'capabilities_error_type' => 'Error', 'capabilities_raw' => []]);
        $nntp->shouldReceive('getFingerprintArticleByMessageID')->times(3)->andReturnUsing(fn (string $id): array => self::article($id));

        $result = $this->collector()->collectFromNzb(self::nzb(3, 1, false), 7);

        $this->assertSame('partial', $result['status']);
        $this->assertSame('ProfileResponseInvalid', $result['data']['providers'][0]['profile']);
        $this->assertSame([['account' => 'primary', 'error_type' => 'ProfileResponseInvalid', 'count' => 1]], $result['data']['errors']);
    }

    #[Test]
    public function protocol_failures_are_errors_not_missing_articles(): void
    {
        $nntp = $this->mock(NNTPService::class);
        $nntp->shouldReceive('getFingerprintServerProfile')->once()->with(false)->andReturn(self::profile());
        $nntp->shouldReceive('getFingerprintArticleByMessageID')->times(3)->andThrow(new \RuntimeException('Article headers are unavailable.'));

        $articles = $this->collector()->collectFromNzb(self::nzb(3, 1, false), 7)['data']['providers'][0]['articles'];

        $this->assertSame(['requested' => 3, 'found' => 0, 'missing' => 0, 'errors' => 3], $articles);
    }

    #[Test]
    public function unknown_zero_file_dates_are_not_posting_evidence(): void
    {
        $nntp = $this->mock(NNTPService::class);
        $nntp->shouldReceive('getFingerprintServerProfile')->twice()->with(false)->andReturn(self::profile());
        $nntp->shouldReceive('getFingerprintArticleByMessageID')->andReturnUsing(fn (string $id): array => self::article($id));
        $mixed = str_replace('date="1790812801"', 'date="0"', self::nzb(2, 1, false));
        $allZero = (string) preg_replace('/date="\d+"/', 'date="0"', self::nzb(2, 1, false));

        $this->assertSame(['first_utc' => '2026-10-01T00:00:00Z', 'last_utc' => '2026-10-01T00:00:00Z', 'span_s' => 0],
            $this->collector()->collectFromNzb($mixed, 7)['data']['posting']);
        $this->assertNull($this->collector()->collectFromNzb($allZero, 8)['data']['posting']);
    }

    #[Test]
    public function a_corrupt_nzb_manifest_keeps_the_record_partial(): void
    {
        $nntp = $this->mock(NNTPService::class);
        $nntp->shouldReceive('getFingerprintServerProfile')->once()->with(false)->andReturn(self::profile());
        $nntp->shouldReceive('getFingerprintArticleByMessageID')->andReturnUsing(fn (string $id): array => self::article($id));
        // Two segments of file 0 claim the same number.
        $xml = str_replace('number="2">f0-s2@fixture', 'number="1">f0-s2@fixture', self::nzb(2, 3, false));

        $result = $this->collector()->collectFromNzb($xml, 7);

        $this->assertSame('partial', $result['status']);
        $this->assertContains(['account' => 'nzb', 'error_type' => 'NzbManifestInvalid', 'count' => 1], $result['data']['errors']);
    }

    #[Test]
    public function the_alternate_copy_supplies_par2_metadata_when_the_primary_copy_has_none(): void
    {
        config(['nntmux.fingerprint_alternate_percent' => 100]);
        $nntp = $this->mock(NNTPService::class);
        $nntp->shouldReceive('getFingerprintServerProfile')->twice()->andReturn(self::profile());
        $nntp->shouldReceive('getFingerprintArticleByMessageID')
            ->andReturnUsing(fn (string $id, int $body, int $header, bool $alternate): array => [
                'body' => YencArticles::article($id === 'index@fixture' && $alternate ? ArchiveParsingBenchmarkFixtures::par2(1) : 'not par2'),
            ] + self::article($id));

        $summary = $this->collector()->collectFromNzb(self::par2Nzb(['offline-0000.bin']), 7)['data']['par2'];

        $this->assertSame(['offline-0000.bin'], array_column($summary['files'], 'name'));
    }

    #[Test]
    public function a_copy_that_identifies_as_another_article_is_rejected(): void
    {
        $nntp = $this->mock(NNTPService::class);
        $nntp->shouldReceive('getFingerprintServerProfile')->once()->with(false)->andReturn(self::profile());
        $nntp->shouldReceive('getFingerprintArticleByMessageID')->times(3)->andReturnUsing(fn (string $id): array => str_starts_with($id, 'f1-')
            ? self::article('wrong@fixture') : self::article($id));

        $result = $this->collector()->collectFromNzb(self::nzb(3, 1, false), 7);
        $foreign = $result['data']['articles'][array_search(1, array_column($result['data']['articles'], 'file_index'), true)];

        $this->assertSame('partial', $result['status']);
        $this->assertSame([['account' => 'primary', 'error_type' => 'ArticleIdentityMismatch', 'count' => 1]], $result['data']['errors']);
        $this->assertNull($foreign['yenc']);
    }

    #[Test]
    public function par2_data_from_several_recovery_sets_is_not_merged_into_one_summary(): void
    {
        $nntp = $this->mock(NNTPService::class);
        $nntp->shouldReceive('getFingerprintServerProfile')->once()->with(false)->andReturn(self::profile());
        $nntp->shouldReceive('getFingerprintArticleByMessageID')->times(3)->andReturnUsing(fn (string $id): array => [
            'body' => YencArticles::article($id === 'index@fixture'
                ? ArchiveParsingBenchmarkFixtures::par2(1).str_replace('offline-', 'other-', ArchiveParsingBenchmarkFixtures::par2(2))
                : 'payload'),
        ] + self::article($id));

        $summary = $this->collector()->collectFromNzb(self::par2Nzb(['offline-0000.bin']), 7)['data']['par2'];

        $this->assertTrue($summary === null || $summary['files'] === []);
    }

    #[Test]
    public function an_unrepresentable_nzb_byte_total_is_unknown_and_invalid(): void
    {
        $nntp = $this->mock(NNTPService::class);
        $nntp->shouldReceive('getFingerprintServerProfile')->once()->with(false)->andReturn(self::profile());
        $nntp->shouldReceive('getFingerprintArticleByMessageID')->andReturnUsing(fn (string $id): array => self::article($id));
        $xml = '<?xml version="1.0" encoding="UTF-8"?><nzb xmlns="http://www.newzbin.com/DTD/2003/nzb">';
        foreach (['a.rar', 'b.nfo'] as $position => $name) {
            $xml .= '<file poster="p@example" date="1790812800" subject="&quot;'.$name.'&quot; yEnc (1/1)"><groups><group>a.b.test</group></groups>'
                .'<segments><segment bytes="'.PHP_INT_MAX.'" number="1">f'.$position.'@fixture</segment></segments></file>';
        }

        $data = $this->collector()->collectFromNzb($xml.'</nzb>', 7)['data'];

        $this->assertNull($data['nzb']['total_bytes']);
        $this->assertContains('NzbManifestInvalid', array_column($data['errors'], 'error_type'));
    }

    /**
     * A fixture article whose yEnc framing matches its NZB segment: single-part for one-segment files,
     * otherwise part N of $total for Message-ID f<file>-s<N>@fixture, 16 bytes per part.
     *
     * @return array<string, mixed>
     */
    private static function segmentArticle(string $messageId, int $total): array
    {
        if ($total === 1) {
            return self::article($messageId);
        }
        $part = preg_match('/-s(\d+)@/', $messageId, $match) === 1 ? (int) $match[1] : 1;

        return ['body' => YencArticles::part('0123456789abcdef', 'segment.bin', 16 * $total, $part, 16 * ($part - 1) + 1, $total)]
            + self::article($messageId);
    }

    #[Test]
    public function a_part_whose_trailer_omits_the_part_number_is_verified(): void
    {
        // Real posting tools end parts with "=yend size=16 pcrc32=..." and no part= field.
        $nntp = $this->mock(NNTPService::class);
        $nntp->shouldReceive('getFingerprintServerProfile')->once()->with(false)->andReturn(self::profile());
        $nntp->shouldReceive('getFingerprintArticleByMessageID')->times(6)->andReturnUsing(function (string $id): array {
            $article = self::segmentArticle($id, 2);
            $article['body'] = (string) preg_replace('/(=yend size=16) part=\d+/', '$1', $article['body']);

            return $article;
        });

        $result = $this->collector()->collectFromNzb(self::nzb(3, 2, false), 7);

        $this->assertSame([], $result['data']['errors']);
        $this->assertSame('complete', $result['status']);
        $this->assertSame([true], array_values(array_unique(array_map(static fn (array $article): mixed => $article['yenc']['crc_ok'], $result['data']['articles']))));
        // A 16-byte first part cannot cover the file's prefix, so no prefix hash is claimed.
        $this->assertNull($result['data']['files'][0]['first16k_md5']);
    }

    #[Test]
    public function a_proven_trailerless_first_part_supplies_the_prefix_hash(): void
    {
        $data = str_repeat('y', 20_000);
        $nntp = $this->mock(NNTPService::class);
        $nntp->shouldReceive('getFingerprintServerProfile')->once()->with(false)->andReturn(self::profile());
        $nntp->shouldReceive('getFingerprintArticleByMessageID')->times(6)->andReturnUsing(function (string $id) use ($data): array {
            $part = preg_match('/-s(\d+)@/', $id, $match) === 1 ? (int) $match[1] : 1;
            $body = YencArticles::part($data, 'big.bin', 40_000, $part, 20_000 * ($part - 1) + 1, 2);

            return ['body' => (string) preg_replace('/(=yend size=20000) part=\d+/', '$1', $body)] + self::article($id);
        });

        $result = $this->collector()->collectFromNzb(self::nzb(3, 2, false), 7);

        $this->assertSame('complete', $result['status']);
        $this->assertSame(md5(substr($data, 0, 16_384)), $result['data']['files'][0]['first16k_md5']);
    }

    /**
     * Trailerless parts of a 16-byte payload whose CRC (000da322) is written without its leading zeros,
     * as posting tools do: "=yend size=16 pcrc32=da322".
     *
     * @param  callable(string): string|null  $corrupt  applied to f1-s1 only
     * @return array{status: string, data: array<string, mixed>}
     */
    private function collectUnpaddedPcrc32Parts(?callable $corrupt = null): array
    {
        $data = 'short-crc-000119';
        $this->assertSame('000da322', hash('crc32b', $data));
        $nntp = $this->mock(NNTPService::class);
        $nntp->shouldReceive('getFingerprintServerProfile')->once()->with(false)->andReturn(self::profile());
        $nntp->shouldReceive('getFingerprintArticleByMessageID')->times(6)->andReturnUsing(function (string $id) use ($data, $corrupt): array {
            $part = preg_match('/-s(\d+)@/', $id, $match) === 1 ? (int) $match[1] : 1;
            $body = YencArticles::part($data, 'segment.bin', 32, $part, 16 * ($part - 1) + 1, 2);
            $body = (string) preg_replace('/=yend size=16 part=\d+ pcrc32=000da322/', '=yend size=16 pcrc32=da322', $body);
            if ($corrupt !== null && $id === 'f1-s1@fixture') {
                $body = $corrupt($body);
            }

            return ['body' => $body] + self::article($id);
        });

        return $this->collector()->collectFromNzb(self::nzb(3, 2, false), 7);
    }

    #[Test]
    public function a_trailerless_part_whose_pcrc32_omits_leading_zeros_is_verified(): void
    {
        $result = $this->collectUnpaddedPcrc32Parts();

        $this->assertSame([], $result['data']['errors']);
        $this->assertSame('complete', $result['status']);
        $this->assertSame([true], array_values(array_unique(array_map(static fn (array $article): mixed => $article['yenc']['crc_ok'], $result['data']['articles']))));
    }

    /** @return array<string, array{callable(string): string}> */
    public static function unprovenUnpaddedPcrc32Parts(): array
    {
        return [
            'wrong short pcrc32' => [static fn (string $body): string => str_replace('pcrc32=da322', 'pcrc32=da323', $body)],
            'non-hex pcrc32' => [static fn (string $body): string => str_replace('pcrc32=da322', 'pcrc32=da32g', $body)],
            'size mismatch' => [static fn (string $body): string => str_replace('=yend size=16 ', '=yend size=17 ', $body)],
            'trailer part present' => [static fn (string $body): string => str_replace('=yend size=16 ', '=yend size=16 part=1 ', $body)],
        ];
    }

    /** @param callable(string): string $corrupt */
    #[Test]
    #[DataProvider('unprovenUnpaddedPcrc32Parts')]
    public function a_trailerless_part_with_an_unpadded_pcrc32_but_no_full_proof_stays_partial(callable $corrupt): void
    {
        $result = $this->collectUnpaddedPcrc32Parts($corrupt);

        $this->assertSame('partial', $result['status']);
        $this->assertNotSame([], $result['data']['errors']);
    }

    #[Test]
    public function a_trailerless_part_with_a_matching_crc_but_a_size_mismatch_does_not_report_crc_ok(): void
    {
        // The pcrc32 still matches the decoded bytes, but the trailer size disagrees with them.
        $nntp = $this->mock(NNTPService::class);
        $nntp->shouldReceive('getFingerprintServerProfile')->once()->with(false)->andReturn(self::profile());
        $nntp->shouldReceive('getFingerprintArticleByMessageID')->times(6)->andReturnUsing(function (string $id): array {
            $article = self::segmentArticle($id, 2);
            $article['body'] = (string) preg_replace('/=yend size=16 part=\d+/', '=yend size=17', $article['body']);

            return $article;
        });

        $result = $this->collector()->collectFromNzb(self::nzb(3, 2, false), 7);

        $this->assertSame('partial', $result['status']);
        $this->assertSame([null], array_values(array_unique(array_map(static fn (array $article): mixed => $article['yenc']['crc_ok'], $result['data']['articles']))));
    }

    /** @return array<string, array{callable(string): string}> */
    public static function unprovenTrailerlessParts(): array
    {
        return [
            'wrong pcrc32' => [static fn (string $body): string => (string) preg_replace('/pcrc32=[0-9a-f]{8}/', 'pcrc32=00000001', $body)],
            'no pcrc32' => [static fn (string $body): string => (string) preg_replace('/ pcrc32=[0-9a-f]{8}/', '', $body)],
            'bad range' => [static fn (string $body): string => str_replace('=ypart begin=1 end=16', '=ypart begin=5 end=16', $body)],
            'no trailer size' => [static fn (string $body): string => str_replace('=yend size=16 ', '=yend ', $body)],
        ];
    }

    /** @param callable(string): string $corrupt */
    #[Test]
    #[DataProvider('unprovenTrailerlessParts')]
    public function a_trailerless_part_without_full_proof_stays_partial(callable $corrupt): void
    {
        $nntp = $this->mock(NNTPService::class);
        $nntp->shouldReceive('getFingerprintServerProfile')->once()->with(false)->andReturn(self::profile());
        $nntp->shouldReceive('getFingerprintArticleByMessageID')->times(6)->andReturnUsing(function (string $id) use ($corrupt): array {
            $article = self::segmentArticle($id, 2);
            $article['body'] = (string) preg_replace('/(=yend size=16) part=\d+/', '$1', $article['body']);
            if ($id === 'f1-s1@fixture') {
                $article['body'] = $corrupt($article['body']);
            }

            return $article;
        });

        $result = $this->collector()->collectFromNzb(self::nzb(3, 2, false), 7);

        $this->assertSame('partial', $result['status']);
        $this->assertNotSame([], $result['data']['errors']);
    }

    #[Test]
    public function a_part_from_another_segment_is_rejected(): void
    {
        $nntp = $this->mock(NNTPService::class);
        $nntp->shouldReceive('getFingerprintServerProfile')->once()->with(false)->andReturn(self::profile());
        $nntp->shouldReceive('getFingerprintArticleByMessageID')->times(6)->andReturnUsing(fn (string $id): array => $id === 'f1-s2@fixture'
            ? ['headers' => self::article($id)['headers']] + self::segmentArticle('f1-s1@fixture', 2)
            : self::segmentArticle($id, 2));

        $result = $this->collector()->collectFromNzb(self::nzb(3, 2, false), 7);

        $this->assertSame('partial', $result['status']);
        $this->assertSame([['account' => 'primary', 'error_type' => 'YencPartMismatch', 'count' => 1]], $result['data']['errors']);
    }

    #[Test]
    public function article_identity_must_be_exact_and_present(): void
    {
        $nntp = $this->mock(NNTPService::class);
        $nntp->shouldReceive('getFingerprintServerProfile')->once()->with(false)->andReturn(self::profile());
        $nntp->shouldReceive('getFingerprintArticleByMessageID')->times(3)->andReturnUsing(function (string $id): array {
            $article = self::article($id);
            if ($id === 'f1-s1@fixture') {
                $article['headers'][0] = 'Message-ID: <F1-S1@fixture>';
            } elseif ($id === 'f2-s1@fixture') {
                unset($article['headers'][0]);
                $article['headers'] = array_values($article['headers']);
            }

            return $article;
        });

        $errors = $this->collector()->collectFromNzb(self::nzb(3, 1, false), 7)['data']['errors'];

        $this->assertSame([
            ['account' => 'primary', 'error_type' => 'ArticleIdentityMismatch', 'count' => 1],
            ['account' => 'primary', 'error_type' => 'ArticleIdentityUnconfirmed', 'count' => 1],
        ], $errors);
    }

    #[Test]
    public function a_later_par2_volume_is_sampled_before_remaining_data_files(): void
    {
        $manifest = (new NzbFingerprintAnalyzer(new YencService))->analyzeManifest(self::nzb(8, 5));

        $samples = CompactFingerprintCollector::selectSamples($manifest['files']);

        $this->assertSame('movie.vol00+01.par2', self::subjectName($manifest['files'], $samples[10]['file_index']));
    }

    #[Test]
    public function prefix_hashes_come_only_from_verified_copies(): void
    {
        $data = str_repeat('x', 20_000);
        $nntp = $this->mock(NNTPService::class);
        $nntp->shouldReceive('getFingerprintServerProfile')->once()->with(false)->andReturn(self::profile());
        $nntp->shouldReceive('getFingerprintArticleByMessageID')->times(3)->andReturnUsing(function (string $id) use ($data): array {
            $body = YencArticles::article($data);
            if ($id === 'f1-s1@fixture') {
                $body = (string) preg_replace('/crc32=[0-9a-f]{8}/', 'crc32=00000000', $body);
            }

            return ['body' => $body] + self::article($id);
        });

        $files = array_column($this->collector()->collectFromNzb(self::nzb(3, 1, false), 7)['data']['files'], 'first16k_md5', 'file_index');

        $this->assertSame(md5(substr($data, 0, 16_384)), $files[0]);
        $this->assertNull($files[1]);
    }

    #[Test]
    public function an_invalid_alternate_profile_does_not_close_the_breaker(): void
    {
        config(['nntmux.fingerprint_alternate_percent' => 100]);
        $nntp = $this->mock(NNTPService::class);
        $nntp->shouldReceive('getFingerprintServerProfile')->with(false)->andReturn(self::profile());
        $nntp->shouldReceive('getFingerprintServerProfile')->once()->with(true)
            ->andThrow(new NntpProviderUnavailableException('Unable to connect to the requested NNTP provider.'));
        $nntp->shouldReceive('getFingerprintServerProfile')->once()->with(true)
            ->andReturn(['capabilities_response_code' => null, 'capabilities_error_type' => 'Error']);
        $nntp->shouldReceive('getFingerprintArticleByMessageID')->andReturnUsing(fn (string $id): array => self::article($id));

        $this->collector()->collectFromNzb(self::nzb(3, 1, false), 7);
        $this->travel(901)->seconds();
        $this->collector()->collectFromNzb(self::nzb(3, 1, false), 8);
        // Another worker now holds the re-probe claim. Had the rejected profile marked the provider
        // recovered, this job would probe freely; instead it must wait for the claimant.
        $base = 'release-fingerprint:provider-breaker:'.sha1('news.alternate.invalid||plain');
        Cache::put($base.':probe:'.Cache::get($base.':generation'), true, 240);
        $third = $this->collector()->collectFromNzb(self::nzb(3, 1, false), 9)['data']['providers'][1];

        $this->assertSame('provider_probe_in_progress', $third['skipped_reason']);
    }

    #[Test]
    public function an_unknown_file_size_makes_the_nzb_total_unknown(): void
    {
        $nntp = $this->mock(NNTPService::class);
        $nntp->shouldReceive('getFingerprintServerProfile')->once()->with(false)->andReturn(self::profile());
        $nntp->shouldReceive('getFingerprintArticleByMessageID')->andReturnUsing(fn (string $id): array => self::article($id));
        // One file's segment bytes overflow, so the analyzer reports its size as unknown.
        $xml = '<?xml version="1.0" encoding="UTF-8"?><nzb xmlns="http://www.newzbin.com/DTD/2003/nzb">'
            .'<file poster="p@example" date="1790812800" subject="&quot;a.rar&quot; yEnc (1/2)"><groups><group>a.b.test</group></groups><segments>'
            .'<segment bytes="'.PHP_INT_MAX.'" number="1">f0-s1@fixture</segment><segment bytes="'.PHP_INT_MAX.'" number="2">f0-s2@fixture</segment>'
            .'</segments></file></nzb>';

        $data = $this->collector()->collectFromNzb($xml, 7)['data'];

        $this->assertNull($data['nzb']['total_bytes']);
        $this->assertContains('NzbManifestInvalid', array_column($data['errors'], 'error_type'));
    }

    #[Test]
    public function scalar_metadata_is_never_current(): void
    {
        foreach ([1, true, 'bad', null] as $metadata) {
            $this->assertFalse(CollectReleaseFingerprintMetadata::isCurrent($metadata));
        }
    }

    /** @return array<string, mixed> */
    private static function profile(): array
    {
        return ['capabilities_response_code' => 101, 'capabilities_raw' => [], 'overview_format_response_code' => null];
    }

    private function collector(): CompactFingerprintCollector
    {
        return new CompactFingerprintCollector($this->mock(NzbService::class), new NzbFingerprintAnalyzer(new YencService), new Par2MetadataParser);
    }

    /** @return array<string, mixed> */
    private static function article(string $messageId): array
    {
        return [
            'headers' => [
                'Message-ID: <'.$messageId.'>',
                'Date: Wed, 01 Oct 2026 12:00:00 +0000',
                'Xref: news.example alt.binaries.test:1 alt.binaries.other:2',
                'User-Agent: ngPost v4.16',
            ],
            'body' => YencArticles::article('0123456789abcdef'),
            'head_response' => ['response_code' => 221, 'response_text' => '221 ok'],
            'body_response' => ['response_code' => 222, 'response_text' => '222 ok'],
        ];
    }

    /**
     * NZB with one PAR2 index, $volumes PAR2 volumes when $par2 is set, and $rarFiles RAR volumes of
     * growing size, each with $segments segments.
     */
    private static function nzb(int $rarFiles, int $segments, bool $par2 = true): string
    {
        $files = [];
        if ($par2) {
            $files[] = ['movie.par2', 1, 1_000];
            $files[] = ['movie.vol00+01.par2', 1, 50_000];
        }
        for ($index = 1; $index <= $rarFiles; $index++) {
            $files[] = [sprintf('movie.part%02d.rar', $index), $segments, 100_000 + ($index % 7) * 10_000 + $index];
        }
        $xml = '<?xml version="1.0" encoding="UTF-8"?><nzb xmlns="http://www.newzbin.com/DTD/2003/nzb">';
        foreach ($files as $position => [$name, $count, $bytes]) {
            $xml .= '<file poster="poster@example" date="'.(1_790_812_800 + $position).'" subject="&quot;'.$name.'&quot; yEnc (1/'.$count.')">'
                .'<groups><group>alt.binaries.test</group></groups><segments>';
            for ($segment = 1; $segment <= $count; $segment++) {
                $xml .= '<segment bytes="'.intdiv($bytes, $count).'" number="'.$segment.'">f'.$position.'-s'.$segment.'@fixture</segment>';
            }
            $xml .= '</segments></file>';
        }

        return $xml.'</nzb>';
    }

    /**
     * NZB with a PAR2 index (index@fixture), a PAR2 volume (volume@fixture) and the given data files.
     *
     * @param  list<string>  $dataFiles
     */
    private static function par2Nzb(array $dataFiles): string
    {
        $files = ['index' => ['movie.par2', 100], 'volume' => ['movie.vol00+01.par2', 900]];
        foreach ($dataFiles as $position => $name) {
            $files['data'.$position] = [$name, 5_000 + $position];
        }
        $xml = '<?xml version="1.0" encoding="UTF-8"?><nzb xmlns="http://www.newzbin.com/DTD/2003/nzb">';
        foreach ($files as $id => [$name, $bytes]) {
            $xml .= '<file poster="p@example" date="1790812800" subject="&quot;'.$name.'&quot; yEnc (1/1)"><groups><group>a.b.test</group></groups>'
                .'<segments><segment bytes="'.$bytes.'" number="1">'.$id.'@fixture</segment></segments></file>';
        }

        return $xml.'</nzb>';
    }

    /** @param list<array<string, mixed>> $files */
    private static function subjectName(array $files, int $index): string
    {
        foreach ($files as $file) {
            if ((int) $file['index'] === $index) {
                return preg_match('/"([^"]+)"/', (string) $file['subject'], $match) === 1 ? $match[1] : '';
            }
        }

        return '';
    }
}

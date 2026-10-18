<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\CollectReleaseFingerprintMetadata;
use App\Services\NNTP\NntpProviderUnavailableException;
use App\Services\NNTP\NNTPService;
use App\Services\Nzb\NzbService;
use App\Services\Releases\Fingerprint\CompactFingerprintCollector;
use App\Services\Releases\Fingerprint\NzbFingerprintAnalyzer;
use App\Services\Releases\Fingerprint\Par2MetadataParser;
use App\Services\Releases\Fingerprint\ReleaseFingerprintCollector;
use App\Services\YencService;
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
        $nntp->shouldReceive('getFingerprintServerProfile')->once()->with(false)->andReturn(['capabilities_raw' => [], 'implementation' => 'IMPLEMENTATION Fixture']);
        $nntp->shouldNotReceive('getFingerprintServerProfile')->with(true);
        $nntp->shouldReceive('getFingerprintArticleByMessageID')->times(CompactFingerprintCollector::SAMPLE_LIMIT)
            ->withArgs(fn (string $id, int $body, int $header, bool $alternate): bool => $alternate === false)
            ->andReturnUsing(fn (string $id): array => self::article($id));

        $result = $this->collector()->collectFromNzb(self::nzb(120, 5), 7);
        $data = $result['data'];

        $this->assertSame('complete', $result['status']);
        $this->assertSame([CompactFingerprintCollector::FORMAT, CompactFingerprintCollector::SCHEMA_VERSION], [$data['format'], $data['schema_version']]);
        $this->assertSame(['primary'], array_column($data['providers'], 'account'));
        $this->assertSame(['requested' => 12, 'found' => 12, 'missing' => 0, 'errors' => 0], $data['providers'][0]['articles']);
        $this->assertSame('IMPLEMENTATION Fixture', $data['providers'][0]['implementation']);
        $this->assertCount(12, $data['articles']);
        // Single-part yEnc carries no part, total or range fields.
        $this->assertSame(['name' => 'fixture file.bin', 'size' => 16, 'part' => null, 'total' => null, 'begin' => null, 'end' => null, 'crc_ok' => true], $data['articles'][1]['yenc']);
        $this->assertSame('2026-10-01T12:00:00Z', $data['articles'][1]['date_utc']);
        $this->assertSame(2, $data['articles'][1]['xref_group_count']);
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
        $nntp->shouldReceive('getFingerprintServerProfile')->once()->with(false)->andReturn(['capabilities_raw' => []]);
        $nntp->shouldReceive('getFingerprintArticleByMessageID')->times(3)
            ->andReturnUsing(function (string $id): array {
                if (str_starts_with($id, 'f1-')) {
                    throw new \RuntimeException('Article headers are unavailable.');
                }

                return self::article($id);
            });

        $result = $this->collector()->collectFromNzb(self::nzb(3, 1, false), 7);

        $this->assertSame('partial', $result['status']);
        $this->assertSame(['requested' => 3, 'found' => 2, 'missing' => 1, 'errors' => 0], $result['data']['providers'][0]['articles']);
        $this->assertSame([['account' => 'primary', 'error_type' => 'RuntimeException', 'count' => 1]], $result['data']['errors']);
    }

    #[Test]
    public function a_sampled_release_also_queries_the_alternate_and_honours_the_breaker(): void
    {
        config(['nntmux.fingerprint_alternate_percent' => 100]);
        $nntp = $this->mock(NNTPService::class);
        $nntp->shouldReceive('getFingerprintServerProfile')->twice()->with(false)->andReturn(['capabilities_raw' => []]);
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
        $nntp->shouldReceive('getFingerprintServerProfile')->once()->with(false)->andReturn(['capabilities_raw' => []]);
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
        $this->assertSame([
            ['name' => 'offline-0000.bin', 'size' => 19_200, 'md5' => md5($data), 'md5_16k' => md5(substr($data, 0, 16384))],
            ['name' => 'offline-0001.bin', 'size' => 19_200, 'md5' => md5($data), 'md5_16k' => md5(substr($data, 0, 16384))],
        ], $summary['files']);
    }

    #[Test]
    public function long_names_and_huge_releases_stay_within_the_record_budget(): void
    {
        $nntp = $this->mock(NNTPService::class);
        $nntp->shouldReceive('getFingerprintServerProfile')->once()->with(false)->andReturn(['capabilities_raw' => []]);
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
        $nntp->shouldReceive('getFingerprintServerProfile')->once()->with(false)->andReturn(['capabilities_raw' => [], 'implementation' => $name]);
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
        $nntp->shouldReceive('getFingerprintServerProfile')->once()->with(false)->andReturn(['capabilities_raw' => []]);
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
    public function a_crc_mismatch_is_reported_and_keeps_the_record_partial(): void
    {
        $nntp = $this->mock(NNTPService::class);
        $nntp->shouldReceive('getFingerprintServerProfile')->once()->with(false)->andReturn(['capabilities_raw' => []]);
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
        $nntp->shouldReceive('getFingerprintServerProfile')->once()->with(false)->andReturn(['capabilities_raw' => []]);
        $nntp->shouldReceive('getFingerprintArticleByMessageID')->times(3)->andReturnUsing(function (string $id) use ($par2): array {
            if ($id === 'index@fixture') {
                throw new \RuntimeException('Article headers are unavailable.');
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
        // A newer version left behind by a rollback is collected again too.
        $this->assertFalse(CollectReleaseFingerprintMetadata::isCurrent(['format' => CompactFingerprintCollector::FORMAT, 'schema_version' => CompactFingerprintCollector::SCHEMA_VERSION + 1]));

        config(['nntmux.fingerprint_format' => 'full']);
        $this->assertTrue(CollectReleaseFingerprintMetadata::isCurrent($full));
        $this->assertFalse(CollectReleaseFingerprintMetadata::isCurrent($compact));
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

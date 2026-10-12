<?php

declare(strict_types=1);

namespace Tests\Unit\Predb;

use App\Services\Predb\Feeds\Sources\NzedbArchiveSource;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Contracts\Foundation\Application as ApplicationContract;
use Illuminate\Foundation\Application;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

final class NzedbArchiveSourceTest extends TestCase
{
    private const ENDPOINT = 'https://raw.githubusercontent.com/nZEDb/nZEDbPre_Dumps/ae51410b04bb3c59749b68582fbc4cc1e1f02d68/dumps/1689156393/1714642832_predb_dump.csv.gz';

    private Container $previousContainer;

    private ?ApplicationContract $previousFacadeApplication;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousContainer = Container::getInstance();
        $this->previousFacadeApplication = Facade::getFacadeApplication();
        $app = new Application(dirname(__DIR__, 3));
        $app->instance('config', new Repository);
        $app->instance('log', new NullLogger);
        Facade::setFacadeApplication($app);
        Facade::clearResolvedInstances();
        Http::swap((new Factory)->preventStrayRequests());
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->previousFacadeApplication);
        Container::setInstance($this->previousContainer);
        parent::tearDown();
    }

    #[Test]
    public function factual_archive_rows_keep_titles_categories_and_unknown_pre_metadata(): void
    {
        $source = new NzedbArchiveSource(self::ENDPOINT);

        $entries = $source->parseGzip(gzencode($this->fixture()));

        $this->assertSame('nzedb_archive', $source->key());
        $this->assertSame([
            'FC.Bayern.Generation.Wembley.S01E05.720p.BluRay.x264-GUACAMOLE',
            'Ueberall.nur.nicht.hier.1999.German.DL.1080p.DSNP.H264-Oergel',
            'The.Little.Mermaid.1989.FiNNiSH.HDR.2160p.WEB.H265-TOOSA',
            'Puss.In.Boots.2011.DANiSH.1080p.BluRay.x264-WAR',
            "Girl.You.Know.It's.True.Die.unglaublich.wahre.Geschichte.von.Milli.Vanilli.2023.Dual.Complete.BluRay-MAMA",
        ], array_column($entries, 'title'));
        $this->assertSame(['X264', 'Movie HD-1080p', 'PRE', null, null], array_column($entries, 'category'));
        $this->assertSame(5, $source->acceptedCount());
        $this->assertSame([], $source->rejectionCounts());
        foreach ($entries as $entry) {
            $this->assertSame('nzedb-pre-dumps', $entry->source);
            $this->assertNull($entry->predate);
            $this->assertNull($entry->listedAt);
            $this->assertNull($entry->size);
            $this->assertNull($entry->files);
        }
    }

    #[Test]
    public function logical_pages_share_one_complete_verified_download(): void
    {
        Http::fake([self::ENDPOINT => Http::response(gzencode($this->fixture()))]);
        $source = new NzedbArchiveSource(self::ENDPOINT, pageSize: 2);

        $first = $source->fetch(1);
        $second = $source->fetch(2);
        $third = $source->fetch(3);

        $this->assertCount(2, $first);
        $this->assertCount(2, $second);
        $this->assertCount(1, $third);
        $this->assertSame('The.Little.Mermaid.1989.FiNNiSH.HDR.2160p.WEB.H265-TOOSA', $second[0]->title);
        $this->assertSame(5, $source->acceptedCount());
        $this->assertSame([], $source->fetch(4));
        Http::assertSentCount(1);
    }

    #[Test]
    #[DataProvider('malformedCsv')]
    public function ambiguous_or_truncated_csv_is_rejected(string $csv): void
    {
        $this->expectException(RuntimeException::class);
        (new NzedbArchiveSource(self::ENDPOINT))->parseGzip(gzencode($csv));
    }

    /** @return array<string, array{string}> */
    public static function malformedCsv(): array
    {
        $row = self::row('Exact.Release.2024-GRP');

        return [
            'empty export' => [''],
            'eleven fields' => [implode("\t\t", array_slice(explode("\t\t", substr($row, 0, -2)), 0, 11))."\r\n"],
            'thirteen fields' => [substr($row, 0, -2)."\t\t'unsupported'\r\n"],
            'missing final terminator' => [substr($row, 0, -2)],
            'LF instead of producer CRLF' => [str_replace("\r\n", "\n", $row)],
            'unterminated title field' => [str_replace("Exact.Release.2024-GRP'", 'Exact.Release.2024-GRP', $row)],
            'unsupported enclosure' => [str_replace("'", '"', $row)],
            'terminal backslash' => ["'Exact\\"],
            'unknown escape' => [str_replace('Exact.Release', 'Exact\\q.Release', $row)],
            'unescaped inner quote' => [str_replace('Exact.Release', "Exact.O'Neil.Release", $row)],
            'malformed NULL token' => [str_replace('\\N', 'NULL', $row)],
            'extra trailing record' => [$row.'garbage'],
            'invalid UTF8' => [str_replace('Exact', "\xff", $row)],
        ];
    }

    #[Test]
    #[DataProvider('invalidGzip')]
    public function one_complete_gzip_member_is_required(string $gzip): void
    {
        $this->expectException(RuntimeException::class);
        (new NzedbArchiveSource(self::ENDPOINT))->parseGzip($gzip);
    }

    /** @return array<string, array{string}> */
    public static function invalidGzip(): array
    {
        $gzip = gzencode(self::row('Exact.Release.2024-GRP'));
        $crc = $gzip;
        $crc[strlen($crc) - 8] = chr(ord($crc[strlen($crc) - 8]) ^ 255);

        return [
            'not gzip' => [self::row('Exact.Release.2024-GRP')],
            'truncated trailer' => [substr($gzip, 0, -4)],
            'corrupt CRC' => [$crc],
            'trailing junk' => [$gzip.'junk'],
            'multiple members' => [$gzip.$gzip],
        ];
    }

    #[Test]
    public function compressed_and_decoded_byte_limits_are_independent(): void
    {
        $gzip = gzencode(self::row(str_repeat('a', 200).'-GRP'));
        $source = new NzedbArchiveSource(self::ENDPOINT, maximumBodyBytes: strlen($gzip));
        $this->assertCount(1, $source->parseGzip($gzip));

        $this->expectException(RuntimeException::class);
        (new NzedbArchiveSource(self::ENDPOINT, maximumBodyBytes: strlen($gzip) - 1))->parseGzip($gzip);
    }

    #[Test]
    public function highly_compressible_csv_cannot_exceed_the_decoded_limit(): void
    {
        $gzip = gzencode(self::row('Exact.Release.2024-GRP', nfo: str_repeat('a', 10000)));

        $this->expectException(RuntimeException::class);
        (new NzedbArchiveSource(self::ENDPOINT, maximumCsvBytes: 1000))->parseGzip($gzip);
    }

    #[Test]
    public function every_raw_row_counts_toward_the_record_bound_before_local_paging(): void
    {
        $this->expectException(RuntimeException::class);
        (new NzedbArchiveSource(self::ENDPOINT, pageSize: 1, maximumRecords: 2))->parseGzip(gzencode($this->fixture()));
    }

    #[Test]
    public function invalid_titles_are_counted_and_never_reconstructed_or_truncated(): void
    {
        $valid = str_repeat('é', 249).'-Group';
        $csv = self::row($valid).self::row(str_repeat('é', 250).'-Group').self::row("Control.\0.Release-GRP").self::row('');
        $source = new NzedbArchiveSource(self::ENDPOINT);

        $entries = $source->parseGzip(gzencode($csv));

        $this->assertSame([$valid], array_column($entries, 'title'));
        $this->assertSame(1, $source->acceptedCount());
        $this->assertSame(['overlength_title' => 1, 'control_title' => 1, 'empty_title' => 1], $source->rejectionCounts());
    }

    #[Test]
    public function duplicate_case_insensitive_titles_do_not_inflate_catalogue_coverage(): void
    {
        $source = new NzedbArchiveSource(self::ENDPOINT);

        $entries = $source->parseGzip(gzencode(self::row('Exact.Release.2024-GRP').self::row('exact.release.2024-grp')));

        $this->assertSame(['Exact.Release.2024-GRP'], array_column($entries, 'title'));
        $this->assertSame(1, $source->acceptedCount());
        $this->assertSame(['duplicate_title' => 1], $source->rejectionCounts());
    }

    #[Test]
    public function literal_zero_category_is_preserved_and_invalid_category_stays_unknown(): void
    {
        $source = new NzedbArchiveSource(self::ENDPOINT);
        $entries = $source->parseGzip(gzencode(self::row('First.Release-GRP', '0').self::row('Second.Release-GRP', "bad\0category")));

        $this->assertSame(['0', null], array_column($entries, 'category'));
    }

    #[Test]
    public function invalid_page_numbers_do_not_start_a_download(): void
    {
        $this->expectException(RuntimeException::class);
        (new NzedbArchiveSource(self::ENDPOINT))->fetch(0);
    }

    private function fixture(): string
    {
        return (string) file_get_contents(__DIR__.'/../../Fixtures/predb/nzedb-2024-05-02-subset.csv');
    }

    private static function row(string $title, ?string $category = null, ?string $nfo = null): string
    {
        $field = static fn (?string $value): string => $value === null ? '\\N' : "'".str_replace(['\\', "'", "\0", "\r", "\n", "\t"], ['\\\\', "\\'", '\\0', '\\r', '\\n', '\\t'], $value)."'";

        return implode("\t\t", array_map($field, [$title, $nfo, '999MB', '14', 'ignored.alias', '0', null, $category, '2024-05-02 11:40:08', 'upstream-provider', '0', null]))."\r\n";
    }
}

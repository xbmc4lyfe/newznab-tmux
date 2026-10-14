<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Predb\Feeds\Contracts\CompleteCatalogueSource;
use App\Services\Predb\Feeds\PredbFeedEntry;
use App\Services\Predb\Feeds\Sources\NswdbSource;
use App\Services\Predb\Feeds\Sources\ThreeDsDbSource;
use Closure;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use RuntimeException;
use Stringable;

final class ThreeDsDbSourceTest extends TestCase
{
    private mixed $previousFacadeApplication;

    /** @var list<string> */
    private array $messages = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousFacadeApplication = Facade::getFacadeApplication();
        Facade::setFacadeApplication(null);
        Http::clearResolvedInstance('http');
        Log::clearResolvedInstance('log');
        Http::swap((new Factory)->preventStrayRequests());
        Log::swap(new class(function (string $message): void {
            $this->messages[] = $message;
        }) extends AbstractLogger
        {

            public function __construct(private readonly Closure $record) {}

            /** @param array<string, mixed> $context */
            public function log(mixed $level, string|Stringable $message, array $context = []): void
            {
                ($this->record)((string) $message);
            }
        });
    }

    protected function tearDown(): void
    {
        Http::clearResolvedInstance('http');
        Log::clearResolvedInstance('log');
        Facade::setFacadeApplication($this->previousFacadeApplication);
        parent::tearDown();
    }

    #[Test]
    public function factual_titles_preserve_canonical_group_tokens_and_distinct_source_identity(): void
    {
        $source = new ThreeDsDbSource('https://3dsdb.com/xml.php');
        $entries = $source->parseXml($this->fixture());

        $this->assertInstanceOf(CompleteCatalogueSource::class, $source);
        $this->assertSame('3dsdb', $source->key());
        $this->assertSame([
            'Atooi.Collection_3DS-JRP',
            'Persona.Q2.New.Cinema.Labyrinth.3DS-TiNGO',
            'Mario_and_Luigi-Super_Star_Saga_and_Bowsers_Minions_EUR_MULTI6_3DS-LiGHTFORCE',
            'Fire_Emblem_Musou_JPN_New3DS-HR',
            'Simple_Series_for_Nintendo_3DS_Vol_2_The_Misshitsu_kara_no_Dasshutsu_Archives_1_JPN_3DS-HR',
            'Super_Monkey_Ball_3D_EUR_3DS-LGC',
            'Tom_Clancys_Ghost_Recon_Shadow_Wars_EUR_3DS-LGC',
        ], $this->titles($entries));
        $this->assertSame(7, $source->acceptedCount());
        $this->assertSame(2, $source->boundaryNormalizedCount());
        $this->assertSame(['duplicate_title' => 1], $source->rejectionCounts());
        foreach ($entries as $entry) {
            $this->assertSame('3dsdb.com', $entry->source);
            $this->assertNull($entry->predate);
            $this->assertNull($entry->listedAt);
            $this->assertNull($entry->pagingTime());
            $this->assertNull($entry->category);
            $this->assertNull($entry->size);
            $this->assertNull($entry->files);
        }
        $this->assertCount(2, $this->messages);
        foreach ($this->messages as $message) {
            $this->assertStringStartsWith('3DSDB ', $message);
        }
    }

    #[Test]
    #[DataProvider('encodings')]
    public function complete_snapshot_is_downloaded_once_and_pages_are_cached_locally(string $encoding): void
    {
        $body = $encoding === 'gzip' ? gzencode($this->fixture()) : $this->fixture();
        Http::fake(['https://3dsdb.com/xml.php' => Http::response($body, 200, ['Content-Encoding' => $encoding])]);
        $source = new ThreeDsDbSource('https://3dsdb.com/xml.php', pageSize: 3);

        $this->assertCount(3, $source->fetch());
        $this->assertCount(3, $source->fetch(2));
        $this->assertSame(['Tom_Clancys_Ghost_Recon_Shadow_Wars_EUR_3DS-LGC'], $this->titles($source->fetch(3)));
        $this->assertSame([], $source->fetch(4));
        $this->assertSame([], $source->fetch(PHP_INT_MAX));
        $this->assertSame(7, $source->acceptedCount());
        Http::assertSentCount(1);
    }

    /** @return array<string, array{string}> */
    public static function encodings(): array
    {
        return ['identity' => ['identity'], 'gzip' => ['gzip']];
    }

    #[Test]
    public function default_complete_export_body_budget_exceeds_the_latest_listing_budget(): void
    {
        $source = new ThreeDsDbSource('https://3dsdb.com/xml.php');

        $this->assertCount(7, $source->parseXml($this->fixture().str_repeat(' ', 1048576)));
    }

    #[Test]
    public function constructor_record_limit_counts_raw_records_before_deduplication(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('3DSDB export has no records or exceeds the record limit.');
        (new ThreeDsDbSource('https://3dsdb.com/xml.php', maximumRecords: 7))->parseXml($this->fixture());
    }

    #[Test]
    public function error_identity_does_not_leak_the_nswdb_adapter_label(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('3DSDB returned an invalid complete XML export.');
        (new ThreeDsDbSource('https://3dsdb.com/xml.php'))->parseXml('<error>Unavailable</error>');
    }

    #[Test]
    public function nswdb_keeps_its_identity_while_sharing_the_complete_catalogue_contract(): void
    {
        $source = new NswdbSource('https://nswdb.com/xml.php');
        $entries = $source->parseXml((string) file_get_contents(__DIR__.'/../Fixtures/predb/nswdb.xml'));

        $this->assertInstanceOf(CompleteCatalogueSource::class, $source);
        $this->assertSame('nswdb', $source->key());
        $this->assertSame('nswdb.com', $entries[0]->source);
        $this->assertSame(6, $source->acceptedCount());
    }

    private function fixture(): string
    {
        return (string) file_get_contents(__DIR__.'/../Fixtures/predb/3dsdb.xml');
    }

    /**
     * @param  list<PredbFeedEntry>  $entries
     * @return list<string>
     */
    private function titles(array $entries): array
    {
        return array_map(static fn (PredbFeedEntry $entry): string => $entry->title, $entries);
    }
}

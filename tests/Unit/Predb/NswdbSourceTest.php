<?php

declare(strict_types=1);

namespace Tests\Unit\Predb;

use App\Services\Predb\Feeds\PredbFeedEntry;
use App\Services\Predb\Feeds\Sources\NswdbSource;
use Closure;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Foundation\Application;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use RuntimeException;
use Stringable;

final class NswdbSourceTest extends TestCase
{
    private Container $previousContainer;

    private mixed $previousFacadeApplication;

    /** @var list<array{message: string, context: array<string, mixed>}> */
    private array $warnings = [];

    /** @var list<array{message: string, context: array<string, mixed>}> */
    private array $normalizations = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousContainer = Container::getInstance();
        $this->previousFacadeApplication = Facade::getFacadeApplication();
        $container = new Application(dirname(__DIR__, 3));
        $container->instance('config', new Repository);
        $logger = new class(function (string $level, string $message, array $context): void {
            if ($level === 'warning') {
                $this->warnings[] = ['message' => $message, 'context' => $context];
            } elseif ($level === 'notice') {
                $this->normalizations[] = ['message' => $message, 'context' => $context];
            }
        }) extends AbstractLogger
        {

            /** @param Closure(string, string, array<string, mixed>): void $record */
            public function __construct(private readonly Closure $record) {}

            /** @param array<string, mixed> $context */
            public function log(mixed $level, string|Stringable $message, array $context = []): void
            {
                if (is_string($level)) {
                    ($this->record)($level, (string) $message, $context);
                }
            }
        };
        $container->instance('log', $logger);
        Container::setInstance($container);
        Facade::setFacadeApplication($container);
        Http::clearResolvedInstance('http');
        Log::clearResolvedInstance('log');
        Http::swap((new Factory)->preventStrayRequests());
    }

    protected function tearDown(): void
    {
        Http::clearResolvedInstance('http');
        Log::clearResolvedInstance('log');
        Facade::setFacadeApplication($this->previousFacadeApplication);
        Container::setInstance($this->previousContainer);
        parent::tearDown();
    }

    public function test_factual_complete_export_preserves_titles_and_reports_boundary_formatting_separately(): void
    {
        $source = new NswdbSource('https://nswdb.com/xml.php');
        $entries = $source->parseXml($this->fixture());

        $this->assertSame([
            'Ayakashi_Gohan_Okawari_for_S_JPN_NSW-HR',
            'MARIO_Plus_RABBIDS_SPARKS_OF_HOPE_NSW',
            'Mega_Man_11_NSW-VENOM',
            'ARMS.PROPER.NSW-BigBlueBox',
            'Mario.Kart.8.Deluxe.PROPER.NSW-BigBlueBox',
            'The.Legend.of.Zelda.Breath.of.the.Wild.PROPER.NSW-BigBlueBox',
        ], $this->titles($entries));
        $this->assertSame([], $source->rejectionCounts());
        $this->assertSame(1, $source->boundaryNormalizedCount());
        foreach ($entries as $entry) {
            $this->assertSame('nswdb.com', $entry->source);
            $this->assertNull($entry->predate);
            $this->assertNull($entry->listedAt);
            $this->assertNull($entry->pagingTime());
            $this->assertNull($entry->category);
            $this->assertNull($entry->size);
            $this->assertNull($entry->files);
        }
        $this->assertCount(0, $this->warnings);
        $this->assertCount(1, $this->normalizations);
        $this->assertSame(1, $this->normalizations[0]['context']['boundary_normalized_records']);
        $this->assertSame(6, $this->normalizations[0]['context']['total_records']);
        $this->assertArrayNotHasKey('rejected_records', $this->normalizations[0]['context']);
    }

    #[DataProvider('encodings')]
    public function test_complete_export_is_fetched_once_and_paged_locally_by_catalogue_id(string $encoding): void
    {
        $body = $encoding === 'gzip' ? gzencode($this->fixture()) : $this->fixture();
        Http::fake(['https://nswdb.com/xml.php' => Http::response($body, 200, ['Content-Encoding' => $encoding])]);
        $source = new NswdbSource('https://nswdb.com/xml.php', pageSize: 2);
        $this->assertSame(0, $source->acceptedCount());

        $this->assertSame(['Ayakashi_Gohan_Okawari_for_S_JPN_NSW-HR', 'MARIO_Plus_RABBIDS_SPARKS_OF_HOPE_NSW'], $this->titles($source->fetch()));
        $this->assertSame(['Mega_Man_11_NSW-VENOM', 'ARMS.PROPER.NSW-BigBlueBox'], $this->titles($source->fetch(2)));
        $this->assertSame(['Mario.Kart.8.Deluxe.PROPER.NSW-BigBlueBox', 'The.Legend.of.Zelda.Breath.of.the.Wild.PROPER.NSW-BigBlueBox'], $this->titles($source->fetch(3)));
        $this->assertSame([], $source->fetch(4));
        $this->assertSame([], $source->fetch(PHP_INT_MAX));
        $this->assertCount(2, $source->fetch(1));
        $this->assertSame(6, $source->acceptedCount());
        Http::assertSentCount(1);
        $this->assertCount(0, $this->warnings);
        $this->assertCount(1, $this->normalizations);
    }

    /** @return array<string, array{string}> */
    public static function encodings(): array
    {
        return ['identity' => ['identity'], 'gzip' => ['gzip']];
    }

    public function test_failed_export_is_not_cached_as_a_complete_prefix(): void
    {
        Http::fake(['https://nswdb.com/xml.php' => Http::sequence()
            ->push(substr($this->fixture(), 0, -12))
            ->push($this->fixture())]);
        $source = new NswdbSource('https://nswdb.com/xml.php');
        try {
            $source->fetch();
            $this->fail('A truncated export must not produce accepted cached entries.');
        } catch (RuntimeException) {
            $this->assertSame([], $source->rejectionCounts());
        }

        $this->assertCount(6, $source->fetch());
        Http::assertSentCount(2);
    }

    public function test_literal_unicode_and_technical_tokens_are_not_rewritten(): void
    {
        $title = 'Léon_天空.1994.DD+5.1.H.264-MixedGroup';
        $source = new NswdbSource('https://nswdb.com/xml.php');

        $this->assertSame([$title], $this->titles($source->parseXml('<releases>'.$this->record(1, $title).'</releases>')));
        $this->assertSame([], $source->rejectionCounts());
    }

    public function test_publisher_literals_without_a_terminal_group_are_preserved(): void
    {
        $titles = [
            'MARIO_Plus_RABBIDS_SPARKS_OF_HOPE_NSW',
            'Asterix_And_Obelix_XXXL_The_Ram_From_Hibernia_NSW-NiiNTENDO[0001]',
            'Otome_Game_no_Hametsu_Flag_Shikanai_Akuyaku_Reijo_ni_Tensei_Shite_Shimatta_Haran_o_Yobu_Kaizoku_JPN_',
        ];
        $source = new NswdbSource('https://nswdb.com/xml.php');
        $xml = '<releases>'.$this->record(2622, $titles[0]).$this->record(2635, $titles[1]).$this->record(3110, $titles[2]).'</releases>';

        $this->assertSame(array_reverse($titles), $this->titles($source->parseXml($xml)));
        $this->assertSame([], $source->rejectionCounts());
    }

    #[DataProvider('observedOuterFormatting')]
    public function test_observed_outer_formatting_uses_the_importer_boundary_trim_only(int $id, string $raw, string $title): void
    {
        $source = new NswdbSource('https://nswdb.com/xml.php');

        $this->assertSame([$title], $this->titles($source->parseXml('<releases>'.$this->record($id, $raw).'</releases>')));
        $this->assertSame([], $source->rejectionCounts());
        $this->assertSame(1, $source->boundaryNormalizedCount());
        $this->assertSame(1, $source->acceptedCount());
        $this->assertCount(0, $this->warnings);
    }

    /**
     * Literal field values from the complete public XML export observed 2026-10-11.
     *
     * @return array<string, array{int, string, string}>
     */
    public static function observedOuterFormatting(): array
    {
        return [
            '1038 trailing tab' => [1038, "Mega_Man_11_NSW-VENOM\t", 'Mega_Man_11_NSW-VENOM'],
            '1896 leading space' => [1896, ' Moai.VI.Unexpected.Guests.NSW-DELiGHT', 'Moai.VI.Unexpected.Guests.NSW-DELiGHT'],
            '2370 leading space' => [2370, ' Instant_Sports_Winter_Games_NSW-SUXXORS', 'Instant_Sports_Winter_Games_NSW-SUXXORS'],
            '2583 leading space' => [2583, ' Made_In_Abyss_Binary_Star_Falling_Into_Darkness_NSW-NiiNTENDO', 'Made_In_Abyss_Binary_Star_Falling_Into_Darkness_NSW-NiiNTENDO'],
            '2704 leading space' => [2704, ' TAITO_MILESTONES_MULTI_NSW-HR', 'TAITO_MILESTONES_MULTI_NSW-HR'],
            '2826 leading space' => [2826, ' FINAL_FANTASY_I-VI_Pixel_Remaster_Collection_NSW-HR', 'FINAL_FANTASY_I-VI_Pixel_Remaster_Collection_NSW-HR'],
            '3221 leading space' => [3221, ' Of_the_Red_the_Light_and_the_Ayakashi_Tsuzuri_NSW-HR', 'Of_the_Red_the_Light_and_the_Ayakashi_Tsuzuri_NSW-HR'],
            '3383 leading space' => [3383, ' Getsuei_no_Kusari_Sakuran_Paranoia_JPN_NSW-HR', 'Getsuei_no_Kusari_Sakuran_Paranoia_JPN_NSW-HR'],
            '3536 leading space' => [3536, ' ACA_NEOGEO_SELECTION_Vol.2_NSW-HR', 'ACA_NEOGEO_SELECTION_Vol.2_NSW-HR'],
            '3570 leading space' => [3570, ' Guilty_Gear_Strive_Nintendo_Switch_Edition_MULTI5_NSW-SUXXORS', 'Guilty_Gear_Strive_Nintendo_Switch_Edition_MULTI5_NSW-SUXXORS'],
            '3587 leading space' => [3587, ' Marble_It_Up_Ultra_NSW-SUXXORS', 'Marble_It_Up_Ultra_NSW-SUXXORS'],
            '3644 leading space' => [3644, ' Care_Bears_Unlock_The_Magic_NSW-SUXXORS', 'Care_Bears_Unlock_The_Magic_NSW-SUXXORS'],
            '3666 leading space' => [3666, ' Yasha_Legends_of_the_Demon_Blade_NSW-HR', 'Yasha_Legends_of_the_Demon_Blade_NSW-HR'],
            '3933 leading space' => [3933, ' Yggdra_Kingdom_JPN_NSW-HR', 'Yggdra_Kingdom_JPN_NSW-HR'],
            '3994 leading space' => [3994, ' Maho_Shisho_Ariana_Nana_Eiketsu_no_Sho_MULTI_NSW-HR', 'Maho_Shisho_Ariana_Nana_Eiketsu_no_Sho_MULTI_NSW-HR'],
        ];
    }

    #[DataProvider('internalWhitespace')]
    public function test_boundary_trim_does_not_rewrite_internal_text_or_unicode_separators(string $title): void
    {
        $source = new NswdbSource('https://nswdb.com/xml.php');

        $this->assertSame([], $source->parseXml('<releases>'.$this->record(1, $title).'</releases>'));
        $this->assertSame(['nonliteral_title' => 1], $source->rejectionCounts());
        $this->assertSame(0, $source->boundaryNormalizedCount());
        $this->assertCount(0, $this->normalizations);
    }

    /** @return array<string, array{string}> */
    public static function internalWhitespace(): array
    {
        return [
            'embedded space' => ['Exact Release-GRP'],
            'embedded tab' => ["Exact\tRelease-GRP"],
            'embedded linefeed' => ["Exact\nRelease-GRP"],
            'embedded DEL control' => ["Exact\x7fRelease-GRP"],
            'outer spaces cannot repair internal words' => ['  Exact Release-GRP  '],
            'nonbreaking boundary separator is not PHP trim' => ["\u{00a0}Exact.Release-GRP"],
        ];
    }

    public function test_boundary_normalized_duplicates_keep_the_highest_id_canonical_literal(): void
    {
        $source = new NswdbSource('https://nswdb.com/xml.php');
        $xml = '<releases>'.$this->record(1, 'exact.title-grp').$this->record(9, " Exact.Title-GRP\t").'</releases>';

        $this->assertSame(['Exact.Title-GRP'], $this->titles($source->parseXml($xml)));
        $this->assertSame(['duplicate_title' => 1], $source->rejectionCounts());
        $this->assertSame(1, $source->boundaryNormalizedCount());
        $this->assertSame(1, $source->acceptedCount());
        $this->assertSame(1, $this->warnings[0]['context']['rejected_records']);
        $this->assertSame(1, $this->normalizations[0]['context']['boundary_normalized_records']);
    }

    public function test_duplicate_exact_and_case_insensitive_titles_keep_the_highest_id_literal(): void
    {
        $source = new NswdbSource('https://nswdb.com/xml.php');
        $xml = '<releases>'.$this->record(1, 'Exact.Title-GRP').$this->record(9, 'exact.title-grp').$this->record(5, 'Exact.Title-GRP').$this->record(2, 'Other.Title-GRP').'</releases>';

        $this->assertSame(['exact.title-grp', 'Other.Title-GRP'], $this->titles($source->parseXml($xml)));
        $this->assertSame(['duplicate_title' => 2], $source->rejectionCounts());
        $this->assertSame(2, $this->warnings[0]['context']['rejected_records']);
    }

    public function test_duplicate_title_check_keeps_accent_differences_distinct(): void
    {
        $source = new NswdbSource('https://nswdb.com/xml.php');
        $xml = '<releases>'.$this->record(1, 'Léon.1994-GRP').$this->record(2, 'Leon.1994-GRP').'</releases>';

        $this->assertSame(['Leon.1994-GRP', 'Léon.1994-GRP'], $this->titles($source->parseXml($xml)));
        $this->assertSame([], $source->rejectionCounts());
    }

    public function test_accepted_count_is_reset_when_a_new_complete_parse_fails(): void
    {
        $source = new NswdbSource('https://nswdb.com/xml.php');
        $source->parseXml($this->fixture());
        $this->assertSame(6, $source->acceptedCount());
        $this->assertSame(1, $source->boundaryNormalizedCount());
        try {
            $source->parseXml('<releases>');
            $this->fail('Incomplete XML must fail before publishing an accepted count.');
        } catch (RuntimeException) {
            $this->assertSame(0, $source->acceptedCount());
            $this->assertSame(0, $source->boundaryNormalizedCount());
        }
    }

    public function test_accepted_count_is_reset_when_transport_fails_after_a_successful_parse(): void
    {
        $source = new NswdbSource('https://nswdb.com/xml.php');
        $source->parseXml($this->fixture());
        $this->assertSame(6, $source->acceptedCount());
        $this->assertSame(1, $source->boundaryNormalizedCount());
        Http::fake(['https://nswdb.com/xml.php' => Http::response('Unavailable', 404)]);
        try {
            $source->fetch();
            $this->fail('A failed export fetch must clear the prior parse count.');
        } catch (RequestException) {
            $this->assertSame(0, $source->acceptedCount());
            $this->assertSame(0, $source->boundaryNormalizedCount());
        }
    }

    #[DataProvider('invalidStructures')]
    public function test_incomplete_or_structurally_ambiguous_exports_fail_without_prefix_results(string $xml): void
    {
        $this->expectException(RuntimeException::class);
        (new NswdbSource('https://nswdb.com/xml.php'))->parseXml($xml);
    }

    /** @return array<string, array{string}> */
    public static function invalidStructures(): array
    {
        $record = '<release><id>1</id><releasename>Exact.Release-GRP</releasename></release>';

        return [
            'truncated closing root' => ['<releases>'.$record],
            'wrong root' => ['<rss>'.$record.'</rss>'],
            'HTML error response' => ['<html><body>Unavailable</body></html>'],
            'empty export' => ['<releases/>'],
            'duplicate IDs' => ['<releases>'.$record.$record.'</releases>'],
            'missing ID' => ['<releases>'.str_replace('<id>1</id>', '', $record).'</releases>'],
            'ambiguous ID' => ['<releases>'.str_replace('<id>1</id>', '<id>1</id><id>2</id>', $record).'</releases>'],
            'mixed-content ID cannot be truncated to its direct text' => ['<releases>'.str_replace('<id>1</id>', '<id>1<other>2</other></id>', $record).'</releases>'],
            'noninteger ID' => ['<releases>'.str_replace('<id>1</id>', '<id>1.5</id>', $record).'</releases>'],
            'unexpected root child' => ['<releases>'.$record.'<message>Incomplete</message></releases>'],
            'unrecognized namespace' => ['<releases xmlns="https://example.test">'.$record.'</releases>'],
            'external entity' => ['<!DOCTYPE releases [<!ENTITY external SYSTEM "file:///etc/passwd">]><releases><release><id>1</id><releasename>&external;</releasename></release></releases>'],
            'UTF-16 entity declaration cannot bypass the declaration guard' => [mb_convert_encoding('<?xml version="1.0" encoding="UTF-16"?><!DOCTYPE releases [<!ENTITY external SYSTEM "file:///etc/passwd">]><releases><release><id>1</id><releasename>&external;</releasename></release></releases>', 'UTF-16LE', 'UTF-8')],
        ];
    }

    public function test_all_records_count_against_the_limit_including_rejected_titles(): void
    {
        $this->expectException(RuntimeException::class);
        (new NswdbSource('https://nswdb.com/xml.php', maximumRecords: 1))->parseXml('<releases>'.$this->record(1, 'Exact.Release-GRP').$this->record(2, 'Editorial title').'</releases>');
    }

    public function test_default_record_limit_rejects_a_5001_record_complete_export(): void
    {
        $records = '';
        for ($id = 1; $id <= 5001; $id++) {
            $records .= $this->record($id, 'Exact.Release-GRP');
        }
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('record limit');
        (new NswdbSource('https://nswdb.com/xml.php'))->parseXml('<releases>'.$records.'</releases>');
    }

    public function test_overlong_identity_is_rejected_instead_of_silently_truncated_by_the_importer(): void
    {
        $source = new NswdbSource('https://nswdb.com/xml.php');

        $this->assertSame([], $source->parseXml('<releases>'.$this->record(1, str_repeat('a', 256).'-GRP').'</releases>'));
        $this->assertSame(['overlength_title' => 1], $source->rejectionCounts());
    }

    public function test_missing_multiple_or_nested_release_title_fields_are_counted_without_guessing(): void
    {
        $source = new NswdbSource('https://nswdb.com/xml.php');
        $xml = '<releases><release><id>1</id></release><release><id>2</id><releasename>A-GRP</releasename><releasename>B-GRP</releasename></release><release><id>3</id><releasename><b>Exact.Release-GRP</b></releasename></release></releases>';

        $this->assertSame([], $source->parseXml($xml));
        $this->assertSame(['missing_or_ambiguous_title' => 3], $source->rejectionCounts());
    }

    public function test_direct_parser_and_decoded_gzip_body_enforce_the_same_complete_export_cap(): void
    {
        $source = new NswdbSource('https://nswdb.com/xml.php', maximumBodyBytes: strlen($this->fixture()) - 1);
        try {
            $source->parseXml($this->fixture());
            $this->fail('Oversized direct input must fail.');
        } catch (RuntimeException) {
            $this->assertSame([], $source->rejectionCounts());
        }
        Http::fake(['https://nswdb.com/xml.php' => Http::response(gzencode($this->fixture()), 200, ['Content-Encoding' => 'gzip'])]);
        $this->expectException(RuntimeException::class);
        $source->fetch();
    }

    public function test_truncated_gzip_transport_does_not_yield_a_complete_export(): void
    {
        Http::fake(['https://nswdb.com/xml.php' => Http::response(substr(gzencode($this->fixture()), 0, -8), 200, ['Content-Encoding' => 'gzip'])]);
        $this->expectException(RuntimeException::class);
        (new NswdbSource('https://nswdb.com/xml.php'))->fetch();
    }

    private function fixture(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2).'/Fixtures/predb/nswdb.xml');
    }

    private function record(int $id, string $title): string
    {
        return '<release><id>'.$id.'</id><releasename>'.htmlspecialchars($title, ENT_XML1, 'UTF-8').'</releasename></release>';
    }

    /** @param list<PredbFeedEntry> $entries
     * @return list<string>
     */
    private function titles(array $entries): array
    {
        return array_map(static fn (PredbFeedEntry $entry): string => $entry->title, $entries);
    }
}

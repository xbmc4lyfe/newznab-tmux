<?php

declare(strict_types=1);

namespace Tests\Unit\Predb;

use App\Services\Predb\Feeds\Contracts\CompleteCatalogueSource;
use App\Services\Predb\Feeds\Sources\DatsPcIsoSource;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class DatsPcIsoSourceTest extends TestCase
{
    private const ENDPOINT = 'https://dats.site/exports/dat-sets/267';

    public function test_independent_literal_mia_names_only_are_dictionary_entries(): void
    {
        $source = new DatsPcIsoSource(self::ENDPOINT);
        self::assertInstanceOf(CompleteCatalogueSource::class, $source);
        $entries = $source->parseXml($this->fixture());
        self::assertSame(['F22.Air.Dominance.Fighter-GOBBLE', 'Alien.Earth-RAZORISO', 'Bundesliga_Manager_98_GERMAN_ISO_FF'], array_column($entries, 'title'));
        self::assertSame(3, $source->acceptedCount());
        self::assertSame(['archive_file_metadata' => 1], $source->rejectionCounts());
        foreach ($entries as $entry) {
            self::assertSame('dats.site', $entry->source);
            self::assertNull($entry->predate);
            self::assertNull($entry->listedAt);
            self::assertNull($entry->category);
            self::assertNull($entry->size);
            self::assertNull($entry->files);
        }
    }

    public function test_observed_optional_catalogue_annotation_does_not_supply_identity_or_dates(): void
    {
        $xml = str_replace('</datafile>', <<<'XML'
<game name="1998-12-11-Abes_Exodus-FLT">
    <description>1998-12-11-Abes_Exodus-FLT</description>
    <nukedreason>get_CD2.repack</nukedreason>
    <rom name="Abes_Exodus-FLT" mia="yes" />
  </game>
</datafile>
XML, $this->fixture());
        $source = new DatsPcIsoSource(self::ENDPOINT);
        $entries = $source->parseXml($xml);
        self::assertSame('Abes_Exodus-FLT', $entries[3]->title);
        self::assertSame(4, $source->acceptedCount());
        self::assertNull($entries[3]->predate);
        self::assertNull($entries[3]->nukeReason);
    }

    public function test_one_complete_download_is_cached_and_locally_paged(): void
    {
        Http::swap((new Factory)->preventStrayRequests());
        Http::fake([self::ENDPOINT => Http::response($this->fixture())]);
        $source = new DatsPcIsoSource(self::ENDPOINT, pageSize: 2);
        self::assertSame('dats_pciso_1998', $source->key());
        self::assertCount(2, $source->fetch());
        self::assertSame(3, $source->acceptedCount());
        self::assertSame('Bundesliga_Manager_98_GERMAN_ISO_FF', $source->fetch(2)[0]->title);
        self::assertSame([], $source->fetch(3));
        self::assertSame([], $source->fetch(PHP_INT_MAX));
        self::assertCount(2, $source->fetch(1));
        Http::assertSentCount(1);
        Http::assertSent(static fn ($request) => $request->url() === self::ENDPOINT);
    }

    public function test_malformed_later_record_cannot_leave_partial_catalogue_or_counts(): void
    {
        $source = new DatsPcIsoSource(self::ENDPOINT, pageSize: 1);
        $source->parseXml($this->fixture());
        try {
            $source->parseXml(str_replace('crc="2a869538"', 'crc="invalid"', $this->fixture()));
            self::fail('Expected malformed archive record rejection.');
        } catch (RuntimeException) {
            self::assertSame(0, $source->acceptedCount());
            self::assertSame([], $source->rejectionCounts());
        }
    }

    #[DataProvider('invalidChanges')]
    public function test_malformed_ambiguous_or_unsupported_schema_rejects_complete_export(string $from, string $to): void
    {
        $this->expectException(RuntimeException::class);
        (new DatsPcIsoSource(self::ENDPOINT))->parseXml(str_replace($from, $to, $this->fixture()));
    }

    /** @return array<string, array{string, string}> */
    public static function invalidChanges(): array
    {
        return [
            'wrong root' => ['datafile', 'catalogue'],
            'namespace' => ['<datafile>', '<datafile xmlns="https://example.org">'],
            'DTD' => ['<datafile>', '<!DOCTYPE datafile [<!ENTITY name "Other-GRP">]><datafile>'],
            'external entity' => ['<datafile>', '<!DOCTYPE datafile SYSTEM "https://example.org/catalogue.dtd"><datafile>'],
            'unexpected record' => ['</datafile>', '<release /></datafile>'],
            'wrong dataset' => ['PC iSO Games Scene 1998', 'PC iSO Games Scene 2026'],
            'duplicate annotation' => ['<rom name="Alien.Earth-RAZORISO"', '<nukedreason>one</nukedreason><nukedreason>two</nukedreason><rom name="Alien.Earth-RAZORISO"'],
            'nested annotation' => ['<rom name="Alien.Earth-RAZORISO"', '<nukedreason><title>Other-GRP</title></nukedreason><rom name="Alien.Earth-RAZORISO"'],
            'attributed annotation' => ['<rom name="Alien.Earth-RAZORISO"', '<nukedreason source="other">one</nukedreason><rom name="Alien.Earth-RAZORISO"'],
            'unknown game field' => ['</description>', '</description><releasename>Other-GRP</releasename>'],
            'different description association' => ['<description>1998-01-01-F22.Air.Dominance.Fighter-GOBBLE</description>', '<description>1998-01-01-Other-GRP</description>'],
            'wrong name association' => ['name="F22.Air.Dominance.Fighter-GOBBLE"', 'name="Other-GRP"'],
            'duplicate game' => ['1998-02-04-Alien.Earth-RAZORISO', '1998-01-01-F22.Air.Dominance.Fighter-GOBBLE'],
            'duplicate literal title' => ['Alien.Earth-RAZORISO', 'F22.Air.Dominance.Fighter-GOBBLE'],
            'case duplicate title' => ['Alien.Earth-RAZORISO', 'f22.air.dominance.fighter-gobble'],
            'extra MIA attribute' => ['mia="yes"', 'mia="yes" size="1"'],
            'ambiguous MIA flag' => ['mia="yes"', 'mia="no"'],
            'nested ROM element' => ['mia="yes" />', 'mia="yes"><name>Other-GRP</name></rom>'],
            'multiple MIA ROMs' => ['<rom name="Alien.Earth-RAZORISO" mia="yes" />', '<rom name="Alien.Earth-RAZORISO" mia="yes" /><rom name="Other-GRP" mia="yes" />'],
            'mixed MIA and file records' => ['<rom name="Alien.Earth-RAZORISO" mia="yes" />', '<rom name="Alien.Earth-RAZORISO" mia="yes" /><rom name="file.nfo" size="1" crc="12345678" md5="'.str_repeat('a', 32).'" sha1="'.str_repeat('b', 40).'" />'],
            'invalid hash' => ['crc="2a869538"', 'crc="INVALID"'],
            'missing ROM name' => ['name="F22.Air.Dominance.Fighter-GOBBLE"', 'name=""'],
            'oversized name' => ['F22.Air.Dominance.Fighter-GOBBLE', str_repeat('A', 256)],
            'spaced name' => ['F22.Air.Dominance.Fighter-GOBBLE', 'F22 Air Dominance Fighter-GOBBLE'],
            'nonprinting name' => ['F22.Air.Dominance.Fighter-GOBBLE', 'F22.&#x200b;Air-GOBBLE'],
            'invalid UTF8' => ['F22.Air.Dominance.Fighter-GOBBLE', "F22.\xff-GOBBLE"],
            'path name' => ['F22.Air.Dominance.Fighter-GOBBLE', '../F22-GOBBLE'],
            'truncated XML' => ['</datafile>', ''],
            'duplicate attribute' => ['mia="yes"', 'mia="yes" mia="yes"'],
        ];
    }

    public function test_literal_unicode_and_nonstandard_group_separator_remain_unchanged(): void
    {
        $title = 'Léon_天空_German_Ind';
        $entries = (new DatsPcIsoSource(self::ENDPOINT))->parseXml(str_replace('F22.Air.Dominance.Fighter-GOBBLE', $title, $this->fixture()));
        self::assertSame($title, $entries[0]->title);
    }

    public function test_whole_export_record_limit_applies_even_with_small_page_cap(): void
    {
        $this->expectException(RuntimeException::class);
        (new DatsPcIsoSource(self::ENDPOINT, pageSize: 1, maximumRecords: 3))->parseXml($this->fixture());
    }

    public function test_wire_body_limit_applies_to_direct_parser(): void
    {
        $this->expectException(RuntimeException::class);
        (new DatsPcIsoSource(self::ENDPOINT, maximumBodyBytes: 10))->parseXml($this->fixture());
    }

    public function test_invalid_page_makes_no_network_request(): void
    {
        Http::swap((new Factory)->preventStrayRequests());
        try {
            (new DatsPcIsoSource(self::ENDPOINT))->fetch(0);
            self::fail('Expected invalid page rejection.');
        } catch (RuntimeException) {
            Http::assertNothingSent();
        }
    }

    private function fixture(): string
    {
        return file_get_contents(dirname(__DIR__, 2).'/Fixtures/predb/dats-pciso-1998-subset.xml');
    }
}

<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Predb\Feeds\PredbFeedEntry;
use App\Services\Predb\Feeds\Sources\CompleteXmlCatalogueSource;
use App\Services\Predb\Feeds\Sources\DatsPcIsoSource;
use App\Services\Predb\Feeds\Sources\NswdbSource;
use App\Services\Predb\Feeds\Sources\RssFeedSource;
use App\Services\Predb\Feeds\Sources\ThreeDsDbSource;
use App\Support\RustPipelineLibrary;
use Closure;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use ReflectionMethod;
use ReflectionProperty;
use Stringable;
use Throwable;

/**
 * Differential parity between the Rust XML subset parsers and the exact simplexml_load_string
 * paths: every input must produce identical entries, counters, logs and exception messages,
 * whether Rust parses it or declines to PHP.
 */
final class RustPredbXmlCataloguesParityTest extends TestCase
{
    private const CDEF = <<<'C'
        ssize_t nntmux_predb_xml_complete_catalogue(const char *xml, size_t len, size_t max_records, char *out_buf, size_t out_cap);
        ssize_t nntmux_predb_xml_dats_pciso(const char *xml, size_t len, size_t max_records, char *out_buf, size_t out_cap);
        ssize_t nntmux_predb_xml_rss(const char *xml, size_t len, uint32_t flags, size_t page_size, char *out_buf, size_t out_cap);
        C;

    private const RSS_FIXTURES = [
        'predb_me', 'nfodb', 'one_techno', 'hdencode', 'hdencode_tv', 'mp3scene', 'ddlvalley',
        'one_gabba', 'scnsrc_me', 'eztv', 'gload', 'hdworld', 'movieblog', 'scnlog',
    ];

    private mixed $previousFacadeApplication;

    /** @var list<array{string, string, array<string, mixed>}> */
    private array $logs = [];

    protected function setUp(): void
    {
        parent::setUp();
        if (RustPipelineLibrary::resolveDomain('predb_xml_catalogues', self::CDEF) === null) {
            self::markTestSkipped('libnntmux_pipeline with XML catalogue exports is not built.');
        }
        $this->previousFacadeApplication = Facade::getFacadeApplication();
        Facade::setFacadeApplication(null);
        Log::clearResolvedInstance('log');
        Log::swap(new class(function (string $level, string $message, array $context): void {
            $this->logs[] = [$level, $message, $context];
        }) extends AbstractLogger
        {

            public function __construct(private readonly Closure $record) {}

            /** @param array<string, mixed> $context */
            public function log(mixed $level, string|Stringable $message, array $context = []): void
            {
                ($this->record)((string) $level, (string) $message, $context);
            }
        });
    }

    protected function tearDown(): void
    {
        foreach ([CompleteXmlCatalogueSource::class, DatsPcIsoSource::class, RssFeedSource::class] as $class) {
            (new ReflectionProperty($class, 'native'))->setValue(null, null);
        }
        Log::clearResolvedInstance('log');
        Facade::setFacadeApplication($this->previousFacadeApplication);
        parent::tearDown();
    }

    public function test_common_fixtures_are_parsed_natively(): void
    {
        $this->assertNativeEngaged(new NswdbSource('https://nswdb.com/xml.php'), 'nativeCatalogue', $this->fixture('nswdb.xml'));
        $this->assertNativeEngaged(new ThreeDsDbSource('https://3dsdb.com/xml.php'), 'nativeCatalogue', $this->fixture('3dsdb.xml'));
        $this->assertNativeEngaged(new DatsPcIsoSource('https://dats.site/x'), 'nativeExport', $this->fixture('dats-pciso-1998-subset.xml'));
        foreach (self::RSS_FIXTURES as $name) {
            $this->assertNativeEngaged(new RssFeedSource($name, $name, 'https://example.test/'), 'nativeItems', $this->fixture($name.'.xml'));
        }
    }

    /** @param list<string> $xmls */
    #[DataProvider('catalogueDocuments')]
    public function test_complete_catalogue_matches_php(array $xmls): void
    {
        foreach ($xmls as $xml) {
            $this->assertParity(static fn () => new NswdbSource('https://nswdb.com/xml.php'), $xml);
            $this->assertParity(static fn () => new ThreeDsDbSource('https://3dsdb.com/xml.php', maximumRecords: 6), $xml);
        }
    }

    /** @return array<string, array{list<string>}> */
    public static function catalogueDocuments(): array
    {
        $record = static fn (int|string $id, string $title): string => '<release><id>'.$id.'</id><releasename>'.htmlspecialchars($title, ENT_XML1, 'UTF-8').'</releasename></release>';
        $rec = $record(1, 'Exact.Release-GRP');

        return [
            'fixtures' => [[self::fixtureStatic('nswdb.xml'), self::fixtureStatic('3dsdb.xml'), self::fixtureStatic('3dsdb.xml').str_repeat(' ', 4096)]],
            'titles' => [array_map(static fn (string $t): string => '<releases>'.$record(4, $t).$record(2, 'Other-GRP').'</releases>', [
                'Léon_天空.1994.DD+5.1.H.264-MixedGroup', "Mega_Man_11_NSW-VENOM\t", ' Lead-GRP', 'Exact Release-GRP', "Exact\x7fRelease-GRP",
                "\u{00a0}Exact.Release-GRP", "A\u{200b}B-GRP", 'Ünïcödé-GRP', 'İstanbul-GRP', 'ǅ-GRP', 'Ωmega-GRP', 'Τest-GRP', str_repeat('a', 255),
                str_repeat('a', 256), str_repeat('é', 255), str_repeat('é', 256), '', '   ', 'other-grp', 'OTHER-GRP', "x\u{e000}y", "\u{1F600}-GRP", "a\u{2028}b",
            ])],
            'duplicates' => [[
                '<releases>'.$record(1, 'exact.title-grp').$record(9, " Exact.Title-GRP\t").'</releases>',
                '<releases>'.$record(1, 'Exact.Title-GRP').$record(9, 'exact.title-grp').$record(5, 'Exact.Title-GRP').$record(2, 'Other.Title-GRP').'</releases>',
                '<releases>'.$record(1, 'Léon.1994-GRP').$record(2, 'Leon.1994-GRP').$record(3, 'LÉON.1994-GRP').'</releases>',
                '<releases>'.$record(1, 'a b').$record(2, '').$record(3, str_repeat('x', 300)).$record(4, 'a b').$record(5, 'A-GRP').$record(6, 'a-grp').'</releases>',
            ]],
            'structures' => [[
                '<releases>'.$rec, '<rss>'.$rec.'</rss>', '<html><body>Unavailable</body></html>', '<releases/>', '<releases>'.$rec.$rec.'</releases>',
                '<releases>'.str_replace('<id>1</id>', '', $rec).'</releases>', '<releases>'.str_replace('<id>1</id>', '<id>1</id><id>2</id>', $rec).'</releases>',
                '<releases>'.str_replace('<id>1</id>', '<id>1<other>2</other></id>', $rec).'</releases>', '<releases>'.str_replace('<id>1</id>', '<id>1.5</id>', $rec).'</releases>',
                '<releases>'.$rec.'<message>Incomplete</message></releases>', '<releases xmlns="https://example.test">'.$rec.'</releases>',
                '<releases xmlns:a="https://example.test">'.$rec.'</releases>', '<releases><a:b xmlns:a="u"/>'.$rec.'</releases>',
                '<!DOCTYPE releases [<!ENTITY external SYSTEM "file:///etc/passwd">]><releases>'.$rec.'</releases>',
                '<releases>'.$record('9223372036854775807', 'A-GRP').$record('9223372036854775808', 'B-GRP').'</releases>',
                '<releases>'.$record('0', 'A-GRP').'</releases>', '<releases>'.$record('01', 'A-GRP').'</releases>', '<releases>'.$record(' 1', 'A-GRP').'</releases>',
                '<releases>text'.$rec.'</releases>', '<releases>'.$rec.'</releases>trailing', '<releases>'.$rec.'</releases><releases/>',
                "<releases>\r\n".str_replace('Exact', "Ex\r\nact", $rec)."\r\n</releases>", '<releases><release><id>1</id><releasename><![CDATA[Cdata-GRP]]></releasename></release></releases>',
                '<releases><release><id>1<!-- x -->2</id><releasename>A<!-- c -->B-GRP</releasename></release></releases>',
                '<releases><release a="1" b=\'2\'><id x="y">1</id><releasename>&#x41;&#66;&amp;&lt;&gt;&quot;&apos;-GRP</releasename></release></releases>',
                '<releases><release><id>1</id><releasename>&nbsp;A</releasename></release></releases>', '<releases><release><id>1</id><releasename>&#0;A</releasename></release></releases>',
                '<releases><release><id>1</id><releasename>A]]>B</releasename></release></releases>', '<?xml version="1.0" encoding="ISO-8859-1"?><releases>'.$rec.'</releases>',
                '<?xml version="1.1"?><releases>'.$rec.'</releases>', "\u{feff}<releases>".$rec.'</releases>', "  \n<releases>".$rec.'</releases>',
                '  <?xml version="1.0"?><releases>'.$rec.'</releases>', '<?xml version="1.0" standalone="yes"?><?pi data?><!-- c --><releases>'.$rec.'</releases><!-- end --><?pi?>',
                '<releases><release><id>1</id><releasename a="1" a="2">A-GRP</releasename></release></releases>', '<releases><release><id>1</id><releasename a="x\ty">A-GRP</releasename></release></releases>',
                '<releases>'.$record(1, 'A').$record(2, 'B').$record(3, 'C').$record(4, 'D').$record(5, 'E').$record(6, 'F').$record(7, 'G').'</releases>',
                '<releases><release><id>1</id><releasename>A<b>x</b>-GRP</releasename></release></releases>', '<releases><release><id>1</id></release></releases>',
                "<releases><release><id>1</id><releasename>A\x01</releasename></release></releases>", "<releases>\xff".$rec.'</releases>', "<releases>\0".$rec.'</releases>',
            ]],
        ];
    }

    /** @param list<string> $xmls */
    #[DataProvider('datsDocuments')]
    public function test_dats_pciso_matches_php(array $xmls): void
    {
        foreach ($xmls as $xml) {
            $this->assertParity(static fn () => new DatsPcIsoSource('https://dats.site/x'), $xml);
            $this->assertParity(static fn () => new DatsPcIsoSource('https://dats.site/x', maximumRecords: 3), $xml);
        }
    }

    /** @return array<string, array{list<string>}> */
    public static function datsDocuments(): array
    {
        $fixture = self::fixtureStatic('dats-pciso-1998-subset.xml');
        $changes = [
            ['datafile', 'catalogue'], ['<datafile>', '<datafile xmlns="https://example.org">'], ['<datafile>', '<!DOCTYPE datafile [<!ENTITY name "Other-GRP">]><datafile>'],
            ['</datafile>', '<release /></datafile>'], ['PC iSO Games Scene 1998', 'PC iSO Games Scene 2026'],
            ['<rom name="Alien.Earth-RAZORISO"', '<nukedreason>one</nukedreason><nukedreason>two</nukedreason><rom name="Alien.Earth-RAZORISO"'],
            ['<rom name="Alien.Earth-RAZORISO"', '<nukedreason><title>Other-GRP</title></nukedreason><rom name="Alien.Earth-RAZORISO"'],
            ['<rom name="Alien.Earth-RAZORISO"', '<nukedreason source="other">one</nukedreason><rom name="Alien.Earth-RAZORISO"'],
            ['<rom name="Alien.Earth-RAZORISO"', '<nukedreason>get_CD2.repack</nukedreason><rom name="Alien.Earth-RAZORISO"'],
            ['<rom name="Alien.Earth-RAZORISO"', '<nukedreason></nukedreason><rom name="Alien.Earth-RAZORISO"'],
            ['</description>', '</description><releasename>Other-GRP</releasename>'], ['name="F22.Air.Dominance.Fighter-GOBBLE"', 'name="Other-GRP"'],
            ['1998-02-04-Alien.Earth-RAZORISO', '1998-01-01-F22.Air.Dominance.Fighter-GOBBLE'], ['Alien.Earth-RAZORISO', 'F22.Air.Dominance.Fighter-GOBBLE'],
            ['Alien.Earth-RAZORISO', 'f22.air.dominance.fighter-gobble'], ['mia="yes"', 'mia="yes" size="1"'], ['mia="yes"', 'mia="no"'], ['mia="yes"', 'mia=""'],
            ['mia="yes" />', 'mia="yes"><name>Other-GRP</name></rom>'], ['mia="yes" />', 'mia="yes"> </rom>'], ['mia="yes" />', 'mia="yes"><!-- c --></rom>'],
            ['crc="2a869538"', 'crc="INVALID"'], ['crc="2a869538"', 'crc="2A869538"'], ['name="F22.Air.Dominance.Fighter-GOBBLE"', 'name=""'],
            ['F22.Air.Dominance.Fighter-GOBBLE', str_repeat('A', 256)], ['F22.Air.Dominance.Fighter-GOBBLE', str_repeat('é', 120)],
            ['F22.Air.Dominance.Fighter-GOBBLE', 'F22 Air Dominance Fighter-GOBBLE'], ['F22.Air.Dominance.Fighter-GOBBLE', 'F22.&#x200b;Air-GOBBLE'],
            ['F22.Air.Dominance.Fighter-GOBBLE', "F22.\xff-GOBBLE"], ['F22.Air.Dominance.Fighter-GOBBLE', '../F22-GOBBLE'], ['F22.Air.Dominance.Fighter-GOBBLE', 'Léon_天空_German_Ind'],
            ['F22.Air.Dominance.Fighter-GOBBLE', 'İ-GOBBLE'], ['F22.Air.Dominance.Fighter-GOBBLE', 'Ω-GOBBLE'], ['F22.Air.Dominance.Fighter-GOBBLE', 'F22&amp;Co-GOBBLE'],
            ['</datafile>', ''], ['mia="yes"', 'mia="yes" mia="yes"'], ['size="', 'size="0'], ['size="', 'size=" '], ['<header>', '<header note="x">'],
            ['forcepacking="unzip"', 'forcepacking="zip"'], ['<clrmamepro', '<extra/><clrmamepro'], ['</datafile>', '<header/></datafile>'],
            ["\n", "\r\n"], ['<datafile>', "<datafile>\n<!-- comment -->\n<?pi x?>"], ['<datafile>', '<datafile>text'],
        ];

        return [
            'fixture' => [[$fixture]],
            'changes' => [array_map(static fn (array $change): string => str_replace($change[0], $change[1], $fixture), $changes)],
        ];
    }

    #[DataProvider('rssFlagSets')]
    public function test_rss_fixtures_match_php(bool $spaced, bool $category, bool $publication, bool $strip, int $pageSize): void
    {
        $make = static fn () => new RssFeedSource('k', 'label', 'https://example.test/', $pageSize, spacedTitles: $spaced, mapCategory: $category, publicationDatesOnly: $publication, stripDisplaySizeSuffix: $strip);
        foreach (self::RSS_FIXTURES as $name) {
            $this->assertParity($make, $this->fixture($name.'.xml'));
        }
        foreach (self::rssDocuments() as $xml) {
            $this->assertParity($make, $xml);
        }
    }

    /** @return array<string, array{bool, bool, bool, bool, int}> */
    public static function rssFlagSets(): array
    {
        $sets = [];
        for ($mask = 0; $mask < 16; $mask++) {
            $sets['flags '.$mask] = [(bool) ($mask & 1), (bool) ($mask & 2), (bool) ($mask & 4), (bool) ($mask & 8), 100];
        }
        $sets['page size 1'] = [false, true, true, true, 1];
        $sets['page size 3 spaced'] = [true, true, false, false, 3];

        return $sets;
    }

    /** @return list<string> */
    private static function rssDocuments(): array
    {
        $item = static fn (string $title, string $extra = ''): string => '<item><title>'.htmlspecialchars($title, ENT_XML1, 'UTF-8').'</title>'.$extra.'</item>';

        return [
            '<rss><channel>'.$item('Example.Film-GRP – 3.1 GB').$item('Example.Film-GRP – 3. GB').$item('Example.Film-GRP – .5 MB').$item('X – 1.2.3 GB').$item('Unchanged_Release-GRP').'</channel></rss>',
            '<rss><channel>'.$item('Show S01E01 720p WEB h264-GROUP EZTV').$item(" Show\tS01E02\n EZTV ").$item('EZTV').$item("A\x0bB").$item("A\fB\f").$item('a  eztv').'</channel></rss>',
            '<rss><channel>'.$item('Example.Release-GRP', '<pubDate>Sun, 11 Oct 2026 14:56:46 +0000</pubDate><category> Music </category><category>Other</category>').'</channel></rss>',
            '<rss><channel>'.$item('Example.Release-GRP', '<pubDate>Mon, 11 Oct 2026 14:56:46 +0000</pubDate>').$item('B-GRP', '<pubDate>2026-10-11 04:14:20</pubDate>').$item('C-GRP', '<pubDate>garbage</pubDate>').$item('D-GRP', '<pubDate>  </pubDate>').'</channel></rss>',
            '<rss xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:atom="http://www.w3.org/2005/Atom"><channel><atom:link href="x" rel="self"/><item><dc:title>Wrong-GRP</dc:title><title>Right-GRP</title><dc:creator>x</dc:creator></item></channel></rss>',
            '<rss xmlns="http://example.test/default"><channel><item><title>Default.Ns-GRP</title></item></channel></rss>',
            '<rss><channel><item><x:title>Undeclared-GRP</x:title></item></channel></rss>', '<rss><channel><item><title xml:lang="en">Lang-GRP</title></item></channel></rss>',
            '<rss xmlns:a="u" xmlns:b="u"><channel><item a:x="1" b:x="2"><title>Dup-GRP</title></item></channel></rss>',
            '<RSS><channel><item><title>Upper-GRP</title></item></channel></RSS>', '<feed><channel/></feed>', '<rss/>', '<rss><channel/></rss>',
            '<rss><channel><item><title>First-GRP</title></item></channel><channel><item><title>Second-GRP</title></item></channel></rss>',
            '<rss><channel><item><title>A<b>x</b>B-GRP</title><title>Second-GRP</title></item><item><title/></item><item><title><![CDATA[Cdata & Co-GRP]]></title></item></channel></rss>',
            "\n\t <?xml version=\"1.0\" encoding=\"UTF-8\"?><rss><channel>".$item('Lead-GRP').'</channel></rss>', "\n<rss><channel>".$item('Lead-GRP').'</channel></rss>',
            '<?xml version="1.0" encoding="windows-1252"?><rss><channel>'.$item('Encoding-GRP').'</channel></rss>', "<rss><channel><item><title>\xe9-GRP</title></item></channel></rss>",
            '<rss><channel><item><title>Unclosed-GRP</title></channel></rss>', '<html>not rss', '<!DOCTYPE rss><rss><channel/></rss>',
            '<rss><channel>'.str_repeat($item('Same.Release-GRP'), 5001).'</channel></rss>', "<rss>\r\n<channel>\r\n".$item("Win\r\nLine-GRP").'</channel></rss>',
            '<rss><channel><item><title>&#x1F600;-GRP</title><category>&amp;Cat</category></item><item><title>&bogus;</title></item></channel></rss>',
        ];
    }

    public function test_rss_publication_dates_match_php(): void
    {
        $previousZone = date_default_timezone_get();
        date_default_timezone_set('America/New_York');
        try {
            mt_srand(20261024);
            $dates = ['Sun, 11 Oct 2026 14:56:46 GMT', 'Sun, 11 Oct 2026 14:56:46 UTC', 'Sun, 11 Oct 2026 14:56:46 +1400', 'Sun, 11 Oct 2026 14:56:46 +1500',
                'Sun, 11 Oct 2026 14:56:46 -0000', 'Sun, 1 Feb 2026 00:00:00 +0000', 'Sun, 01 Feb 2026 00:00:00 +0000', 'Mon, 1 Feb 2026 00:00:00 +0000',
                'Sat, 31 Feb 2026 10:00:00 +0000', 'Sun, 29 Feb 2032 10:00:00 +0000', 'Tue, 29 Feb 2028 23:59:59 -1159', 'Sun, 11 oct 2026 14:56:46 +0000',
                'sun, 11 Oct 2026 14:56:46 +0000', 'Sun, 11 Oct 2026 14:56:60 +0000', 'Sun, 11 Oct 2026 24:00:00 +0000', 'Sun, 11 Oct 2026 14:56 +0000',
                'Sun, 11 Oct 2026 14:56:46 +000', ' Sun, 11 Oct 2026 14:56:46 +0000', 'Sun, 11 Oct 2026 14:56:46 +0000 ', 'Thu, 01 Jan 1970 00:00:00 +0000',
                'Fri, 31 Dec 1971 23:30:00 -0130', 'Fri, 31 Dec 9998 23:59:59 -1400', '2026-10-11T04:14:20+00:00', '2026-10-11 04:14:20'];
            for ($index = 0; $index < 400; $index++) {
                $offset = mt_rand(-14 * 60, 14 * 60);
                $zone = sprintf('%s%02d%02d', $offset < 0 ? '-' : '+', intdiv(abs($offset), 60), abs($offset) % 60);
                $local = (new \DateTimeImmutable('@'.mt_rand(0, 4102444800)))->setTimezone(new \DateTimeZone($zone));
                $weekday = mt_rand(0, 9) === 0 ? ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'][mt_rand(0, 6)] : $local->format('D');
                $dates[] = $weekday.$local->format(mt_rand(0, 1) ? ', j M Y H:i:s ' : ', d M Y H:i:s ').(mt_rand(0, 7) === 0 ? 'GMT' : $zone);
            }
            foreach (array_chunk($dates, 50) as $chunk) {
                $xml = '<rss><channel>';
                foreach ($chunk as $index => $date) {
                    $xml .= '<item><title>Date'.$index.'-GRP</title><pubDate>'.htmlspecialchars($date, ENT_XML1).'</pubDate></item>';
                }
                $xml .= '</channel></rss>';
                foreach ([true, false] as $publicationDatesOnly) {
                    $this->assertParity(static fn () => new RssFeedSource('k', 'l', 'https://example.test/', publicationDatesOnly: $publicationDatesOnly), $xml);
                }
            }
        } finally {
            date_default_timezone_set($previousZone);
        }
    }

    #[DataProvider('fuzzTargets')]
    public function test_random_mutations_match_php(string $kind, string $fixture): void
    {
        $make = match ($kind) {
            'complete' => static fn () => new NswdbSource('https://nswdb.com/xml.php'),
            'dats' => static fn () => new DatsPcIsoSource('https://dats.site/x'),
            default => static fn () => new RssFeedSource('k', 'label', 'https://example.test/', 100, spacedTitles: true, mapCategory: true, publicationDatesOnly: true, stripDisplaySizeSuffix: true),
        };
        $xml = $this->fixture($fixture);
        $tokens = ['<', '>', '&', '&amp;', '&#x41;', '&#0;', ']]>', '<![CDATA[x]]>', '<!-- c -->', '<?pi x?>', ' ', "\r\n", "\t", ' xmlns:a="u"', '<a:b/>',
            '"', "'", '=', '/', 'é', "\u{00a0}", '–', '</item>', '<item>', '<title>', '</title>', '</release>', '<release>', '<id>7</id>', '<rom name="x" mia="yes"/>', "\x01", "\xff"];
        mt_srand(crc32($kind.$fixture));
        for ($iteration = 0; $iteration < 250; $iteration++) {
            $mutated = $xml;
            for ($edit = mt_rand(1, 3); $edit > 0; $edit--) {
                $at = mt_rand(0, strlen($mutated));
                $mutated = match (mt_rand(0, 2)) {
                    0 => substr($mutated, 0, $at).substr($mutated, $at + mt_rand(1, 8)),
                    1 => substr($mutated, 0, $at).$tokens[mt_rand(0, count($tokens) - 1)].substr($mutated, $at),
                    default => substr($mutated, 0, $at).substr($mutated, $at, mt_rand(1, 40)).substr($mutated, $at),
                };
            }
            $this->assertParity($make, $mutated);
        }
    }

    /** @return array<string, array{string, string}> */
    public static function fuzzTargets(): array
    {
        return [
            'nswdb' => ['complete', 'nswdb.xml'], '3dsdb' => ['complete', '3dsdb.xml'], 'dats' => ['dats', 'dats-pciso-1998-subset.xml'],
            'scnsrc' => ['rss', 'scnsrc_me.xml'], 'hdencode' => ['rss', 'hdencode.xml'], 'nfodb' => ['rss', 'nfodb.xml'], 'eztv' => ['rss', 'eztv.xml'],
        ];
    }

    /** @param Closure(): (CompleteXmlCatalogueSource|DatsPcIsoSource|RssFeedSource) $make */
    private function assertParity(Closure $make, string $xml): void
    {
        $class = match (true) {
            $make() instanceof CompleteXmlCatalogueSource => CompleteXmlCatalogueSource::class,
            $make() instanceof DatsPcIsoSource => DatsPcIsoSource::class,
            default => RssFeedSource::class,
        };
        $native = new ReflectionProperty($class, 'native');
        $native->setValue(null, null);
        $rust = $this->outcome($make(), $xml);
        $native->setValue(null, false);
        $php = $this->outcome($make(), $xml);
        $native->setValue(null, null);

        self::assertSame($php, $rust, 'Native/PHP divergence for input: '.json_encode(mb_substr(mb_scrub($xml, 'UTF-8'), 0, 600)));
    }

    /** @return array<string, mixed> */
    private function outcome(CompleteXmlCatalogueSource|DatsPcIsoSource|RssFeedSource $source, string $xml): array
    {
        $this->logs = [];
        try {
            $entries = array_map(static fn (PredbFeedEntry $entry): array => [
                $entry->title, $entry->source, $entry->category, $entry->size, $entry->files,
                $entry->predate?->format('U.u e'), $entry->listedAt?->format('U.u e'),
            ], $source->parseXml($xml));
            $result = ['entries' => $entries];
        } catch (Throwable $exception) {
            $result = ['exception' => $exception::class.': '.$exception->getMessage()];
        }
        if (! $source instanceof RssFeedSource) {
            $result['accepted'] = $source->acceptedCount();
            $result['rejections'] = $source->rejectionCounts();
        }
        if ($source instanceof CompleteXmlCatalogueSource) {
            $result['normalized'] = $source->boundaryNormalizedCount();
        }
        $result['logs'] = $this->logs;

        return $result;
    }

    private function assertNativeEngaged(object $source, string $method, string $xml): void
    {
        $output = (new ReflectionMethod($source, $method))->invoke($source, $xml);
        self::assertIsString($output, $source::class.' declined a common fixture to PHP.');
    }

    private function fixture(string $name): string
    {
        return self::fixtureStatic($name);
    }

    private static function fixtureStatic(string $name): string
    {
        return (string) file_get_contents(__DIR__.'/../Fixtures/predb/'.$name);
    }
}

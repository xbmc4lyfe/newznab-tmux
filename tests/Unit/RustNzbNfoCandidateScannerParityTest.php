<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\NNTP\NNTPService;
use App\Services\Nzb\NzbContentsService;
use App\Services\Nzb\NzbParserService;
use App\Services\Nzb\NzbService;
use App\Services\Par2Processor;
use App\Services\PostProcessService;
use App\Support\RustPipelineLibrary;
use Closure;
use ErrorException;
use FFI;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;
use SimpleXMLElement;
use Tests\TestCase;

final class RustNzbNfoCandidateScannerParityTest extends TestCase
{
    private static function invokeFfi(FFI $ffi, string $symbol, mixed ...$args): mixed
    {
        return $ffi->$symbol(...$args);
    }

    private static function ffi(): FFI
    {
        $ffi = RustPipelineLibrary::resolveDomain('nzb_nfo_candidates_test', NzbParserService::NFO_FFI_CDEF);
        if ($ffi === null) {
            throw new RuntimeException('Build the release dylib before running parity tests.');
        }

        return $ffi;
    }

    private static function contents(?NzbParserService $parser = null): NzbContentsService
    {
        // The scanner needs no app, settings, NNTP or database.
        $service = (new ReflectionClass(NzbContentsService::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty(NzbContentsService::class, 'parserService'))->setValue($service, $parser ?? new NzbParserService);
        (new ReflectionProperty(NzbContentsService::class, 'echoOutput'))->setValue($service, false);

        return $service;
    }

    /**
     * Production path of {@see NzbContentsService::parseNzb()} ({@see NzbContentsService::scanNzb()})
     * with the NZB store returning $source.
     */
    private static function router(NzbContentsService $service, string $source): Closure
    {
        $store = new class($source) extends NzbService
        {
            public function __construct(private readonly string $source) {}

            public function readNzbContents(string $releaseGuid): string
            {
                return $this->source;
            }
        };
        (new ReflectionProperty(NzbContentsService::class, 'nzbService'))->setValue($service, $store);
        $method = new ReflectionMethod(NzbContentsService::class, 'scanNzb');

        return fn (bool $nfo, bool $par2): ?array => $method->invoke($service, 'guid', $nfo, $par2);
    }

    private static function load(string $xml): SimpleXMLElement|false
    {
        $previous = libxml_use_internal_errors(true);
        try {
            return simplexml_load_string($xml);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    /** @return list<string> */
    private static function subjects(): array
    {
        $cases = [
            '[01/50] - "Some.Movie.2026.1080p.BluRay.x264-GRP.part01.rar" yEnc (1/120)', '"Some.Movie.2026.nfo" yEnc (1/1)',
            '"Some.Movie.2026.par2" yEnc (1/1)', 'Some.Movie.2026.vol000+01.par2', 'hashed.par2', 'HASHED.PAR2', "hashed.par2\n",
            "hashed.par2\n\n", 'hashed.par2 ', 'a.par2"', 'readme.txt', 'file_id.diz', '00-group-release.nfo', '"info.txt" (1/1)', 'abc-def (1/1)',
            'random yEnc (123) [1/1]', 'x.inf', 'x.info-', 'x.nfo.', 'x.NFO', 'x.infoo', 'x._nfo', '.nfo', 'nfo', 'release notes.txt',
            'Release "notes.TXT"', "notes\n.txt", "readme\nx.txt", 'read.me.txt', 'readme.nfo"x', 'information.nfo', 'desc.diz"', '_-_a.nfo ',
            '0-x.txt', '000-x.txt', '00x.txt', '"00-x.diz"', 'xreadme.txt', 'a readme b.txt c', 'a readme b.txtc', "readme.txt\n", "readme.txt\nx",
            'A (15/20)', 'A [15/20]', 'A 15/20', 'A (1/0)', 'A 5)', "A (3/4)\n", "A (3/4)\n\n", 'A (99999999999999999999/5)',
            'A (5/99999999999999999999)', 'A (5/3000000000)', 'A (5/0003)', 'A 0)', 'A (0/0)', 'A 1]2]', 'A 1)2)', 'A 12)', 'A ]5', 'A /5', '1/5',
            '(1/5)', '', ' ', 'x.r01 (1/1)', 'x.s12', 'x.t1', 'x.par (1/1)', 'x.sha256', 'x.c', 'x.cpp (1/1)', 'x.C_ (1/1)', 'x.mp4b (1/1)',
            "\xc3\xa9.nfo", "\xe9.nfo", "a\xa0nfo\xa0", "a\x85info", "\vreadme\v", "\fnfo", "\0nfo\0", 'yEnc   (1)   [1/1]', 'YENC(12)[1/1]',
            'yenc ( 1) [1/1]', 'yEnc (1) [1/1] x.exe', 'scene-rel.(1/1)', 'scene-rel "" (1/1)', 'a.b"" (1/1)', '-ab (1/1)', 'ab- (1/1)', 'a-b(1/1)',
            'a- (1/1)', 'ab (1/1)', 'a.b (1/1)x', 'a.b (1/1)', 'release', 'release ', '"release"', 'x release', 'file_id', 'fileid "', 'file_i',
            'about', 'xabout', "nfo\n", "\nnfo", 'Info', 'notes.nfo', 'note.diz', 'description.txt', 'desc.txt', "a\tdesc.txt\t", 'a"info".nfo.nfo',
        ];
        $random = new Randomizer(new Mt19937(9401));
        $chunks = ['.', 'nfo', 'NFO', 'diz', 'info', 'inf', 'txt', 'par2', 'PAR2', '"', ' ', "\t", "\n", "\r", "\v", "\f", '(1/1)', '[1/1]',
            'yEnc', '(', ')', '[', ']', '/', '1', '0', '15', '-', '_', 'readme', 'release', 'file_id', 'about', 'notes', 'read.me', '00-', '_-_',
            'rar', 'r01', 'exe', "\xc3\xa9", "\xa0", 'x', 'abc', 'vol01+02', 'sha1', '12)', '(3/40)', 'part01'];
        for ($i = 0; $i < 4000; $i++) {
            $subject = '';
            for ($j = $random->getInt(1, 14); $j > 0; $j--) {
                $subject .= $chunks[$random->getInt(0, count($chunks) - 1)];
            }
            $cases[] = $subject;
        }
        // Structured cases around each pattern's anchors, keywords, extensions and terminators.
        $lead = ['', ' ', '"', "\n", "\t", 'x', '[01/10] - "', '-', '.'];
        $words = ['file', 'FILE_ID', 'fileid', 'readme', 'READ.ME', 'read.me', 'release', 'info', 'information', 'about', 'desc',
            'description', 'note', 'notes', '00-', '000-', '0-', '_-_', 'nfo', 'abc', 'a1-b2', 'scene.rel-GRP', 'yEnc', ''];
        $middle = ['', ' ', '.x', ' stuff ', "\n", '"', '-', '_', '.nfo', '.txt'];
        $ext = ['.nfo', '.NFO', '.diz', '.txt', '.info', '.inf', '.par2', '.PAR2', '.vol01+02.par2', '.rar', '.r01', '.s99', '.exe', '.par',
            '.sha512', '.nfox', '.nf', '', '.c', '.jpeg'];
        $tail = ['', ' ', '"', "\n", '.', '-', '_', 'x', ' (1/1)', '(1/1)', "(1/1)\n", ' yEnc (1/1)', ' yEnc (123) [1/1]', ' yEnc(7)[1/1]',
            ' [3/40]', ' (3/40)', "(3/40)\n", ' 7)', '" yEnc (1/1)', '" (1/1)', ')', ']', ' (0/0)', ' (12/0)'];
        $pick = static fn (array $list): string => $list[$random->getInt(0, count($list) - 1)];
        for ($i = 0; $i < 6000; $i++) {
            $cases[] = $pick($lead).$pick($words).$pick($middle).$pick($ext).$pick($tail).($random->getInt(0, 3) === 0 ? $pick($ext).$pick($tail) : '');
        }

        return $cases;
    }

    private static function xmlSafe(string $subject): bool
    {
        return mb_check_encoding($subject, 'UTF-8') && preg_match('/[\x00-\x1f\x7f]/', $subject) !== 1;
    }

    private static function attr(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES);
    }

    /**
     * @param  list<array{0:string,1:list<string>|null}>  $files  subject and segment texts (null: no segments element)
     */
    private static function nzb(array $files, string $prolog = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n", string $head = ''): string
    {
        $xml = $prolog.'<nzb xmlns="http://www.newzbin.com/DTD/2003/nzb">'.$head;
        foreach ($files as $index => [$subject, $segments]) {
            $xml .= "\n <file poster=\"p@example\" date=\"1700000000\" subject=\"".self::attr($subject).'">';
            $groups = '<groups><group>alt.binaries.test</group></groups>';
            $body = '';
            if ($segments === [] && $index % 2 === 1) {
                $body = '<segments/>';
            } elseif ($segments !== null) {
                $body = '<segments>';
                foreach ($segments as $number => $id) {
                    $body .= '<segment bytes="'.(700000 + $number).'" number="'.($number + 1).'">'.htmlspecialchars($id, ENT_XML1).'</segment>';
                }
                $body .= '</segments>';
            }
            $xml .= $index % 3 === 0 ? $groups.$body : $body.'<!-- c -->'.$groups;
            $xml .= '</file>';
        }

        return $xml."\n</nzb>\n";
    }

    /**
     * @param  list<string>  $subjects
     * @param  list<int>  $counts  segments per file, cycled (-1: no segments element)
     * @param  list<string>  $ids
     * @return list<array{0:string,1:list<string>|null}>
     */
    private static function files(array $subjects, array $counts, array $ids): array
    {
        $files = [];
        foreach ($subjects as $index => $subject) {
            $count = $counts[$index % count($counts)];
            $segments = $count < 0 ? null : [];
            for ($s = 0; $s < $count; $s++) {
                $segments[] = $ids[($index + $s) % count($ids)].($s > 0 ? '.'.$s : '');
            }
            $files[] = [$subject, $segments];
        }

        return $files;
    }

    /** @return array<string, string> */
    private static function documents(): array
    {
        $ids = ['part1of5.abc@news.example', 'x', '', " spaced id \n", "\n\tid@x\n", 'a&b<c>@example', str_repeat('m', 300).'@long'];
        $docs = [
            'release' => self::nzb(self::files(['"Movie.2026.nfo" yEnc (1/1)', '"Movie.2026.part01.rar" yEnc (1/40)', '"Movie.2026.par2" yEnc (1/1)',
                'Movie.2026.par2', 'Movie.2026.vol00+01.par2'], [1, 3, 2, 1, 2], $ids)),
            'par2_only' => self::nzb(self::files(['a.par2', 'a.vol01+02.par2', '"a.par2"'], [1], $ids)),
            'hidden_then_explicit' => self::nzb(self::files(['abc-def (1/1)', 'other (1/1)', 'release.nfo', 'second.nfo'], [1], $ids)),
            'short_ids' => self::nzb(self::files(['x.nfo', 'abc-def (1/1)', 'z.par2', 'data.rar (1/3)'], [1], ['x', ''])),
            'empty_segments' => self::nzb(self::files(['x.nfo', 'abc-def (1/1)', 'z.par2', 'y.rar (2/2)'], [0, 0, 1, 0], $ids)),
            'head_doctype_bom' => "\xEF\xBB\xBF<?xml version=\"1.0\" encoding=\"utf-8\"?>\n<!DOCTYPE nzb PUBLIC \"-//newzBin//DTD NZB 1.1//EN\" \"http://www.newzbin.com/DTD/nzb/nzb-1.1.dtd\">\n<!-- x -->"
                .'<nzb xmlns="http://www.newzbin.com/DTD/2003/nzb"><head><meta type="title">T &amp; U</meta><meta type="x"/></head>'
                .'<file subject="&quot;a&amp;b.nfo&quot; yEnc (1/1)"><segments><segment number="1" bytes="5">a&#64;b&#x40;c</segment></segments></file></nzb>',
            'empty_root' => '<nzb></nzb>',
            'completion_exact' => self::nzb(self::files(['a (1/2)', 'b (1/2)'], [2], $ids)),
            'completion_over' => self::nzb(self::files(['a (1/1)', 'b (1/1)'], [3], $ids)),
            'completion_zero' => self::nzb(self::files(['a (1/0)', 'b'], [3], $ids)),
            'no_root_namespace' => str_replace(' xmlns="http://www.newzbin.com/DTD/2003/nzb"', '', self::nzb(self::files(['a.nfo', 'b.par2', 'c'], [1], $ids))),
        ];
        $random = new Randomizer(new Mt19937(9402));
        $subjects = array_values(array_filter(self::subjects(), self::xmlSafe(...)));
        for ($i = 0; $i < 400; $i++) {
            $picked = [];
            for ($j = $random->getInt(1, 9); $j > 0; $j--) {
                $picked[] = $subjects[$random->getInt(0, count($subjects) - 1)];
            }
            $counts = [$random->getInt(0, 3), $random->getInt(1, 3), 1, $random->getInt(0, 2)];
            $docs['random_'.$i] = self::nzb(self::files($picked, $counts, $ids), $i % 2 === 0 ? '' : "<?xml version='1.0'?>\n");
        }

        return $docs;
    }

    public function test_subject_detectors_match_pcre(): void
    {
        $ffi = self::ffi();
        $parser = new NzbParserService;
        $buffer = $ffi->new('char[4]');
        $supported = false;
        foreach (self::subjects() as $index => $subject) {
            $case = "case $index ".bin2hex($subject);
            $expected = $parser->detectNfoFile($subject);
            self::assertSame($expected, $parser->detectNfoFileNative($subject, $supported), $case);
            self::assertTrue($supported);
            $written = self::invokeFfi($ffi, 'nntmux_nzb_detect_nfo_file', $subject, strlen($subject), $buffer, 4);
            self::assertSame($expected === false ? 0 : 1, $written, $case);
            for ($segments = 0; $segments <= 3; $segments++) {
                self::assertSame($parser->detectHiddenNfoFile($subject, $segments), $parser->detectHiddenNfoFileNative($subject, $segments, $supported), "$case segments $segments");
                self::assertTrue($supported);
            }
            self::assertSame($parser->detectPar2IndexFile($subject), $parser->detectPar2IndexFileNative($subject, $supported), $case);
            self::assertTrue($supported);
            $total = $parser->extractPartsTotal($subject);
            $native = $parser->extractPartsTotalNative($subject, $supported);
            if ($total > 2147483647) {
                self::assertFalse($supported, $case);
                self::assertSame(-2, self::invokeFfi($ffi, 'nntmux_nzb_extract_parts_total', $subject, strlen($subject)));
            } else {
                self::assertTrue($supported, $case);
                self::assertSame($total, $native, $case);
            }
        }
    }

    public function test_scanner_matches_php_for_generated_documents(): void
    {
        $service = self::contents();
        foreach (self::documents() as $name => $source) {
            $xml = self::load($source);
            self::assertInstanceOf(SimpleXMLElement::class, $xml, $name);
            $route = self::router($service, $source);
            foreach ([[true, true], [true, false], [false, true], [false, false]] as [$nfo, $par2]) {
                $expected = $service->scanNzbFilesPhp($xml, $nfo, $par2);
                self::assertSame($expected, $service->scanNzbFilesNative($source, $nfo, $par2), "$name packed ".(int) $nfo.(int) $par2);
                self::assertSame($expected, $service->scanNzbFilesBatchNative($xml, $nfo, $par2), "$name batch ".(int) $nfo.(int) $par2);
                self::assertSame($expected, $route($nfo, $par2), "$name router");
            }
        }
    }

    public function test_scanner_declines_documents_outside_the_reproduced_subset(): void
    {
        $file = static fn (string $inner, string $subject = 'a.nfo (1/1)'): string => '<nzb><file subject="'.$subject.'">'.$inner.'</file><file subject="b.par2"><segments><segment>p@x</segment></segments></file></nzb>';
        $declined = [
            'cdata' => $file('<segments><segment><![CDATA[id@x]]></segment></segments>'),
            'prefixed_file' => '<n:nzb xmlns:n="urn:x"><n:file subject="a.nfo"/></n:nzb>',
            'prefixed_child' => '<nzb xmlns:n="urn:x"><n:file subject="a.nfo"/><file subject="b.nfo"><segments><segment>i@x</segment></segments></file></nzb>',
            'two_segment_lists' => $file('<segments><segment>a@x</segment></segments><segments><segment>b@x</segment></segments>'),
            'carriage_return_text' => $file("<segments><segment>id@x\r</segment></segments>"),
            'literal_tab_subject' => $file('<segments><segment>id@x</segment></segments>', "a\t.nfo"),
            'newline_reference_subject' => $file('<segments><segment>id@x</segment></segments>', 'a&#10;.nfo'),
            'internal_subset' => '<!DOCTYPE nzb [ <!ENTITY e "x.nfo"> ]>'.$file('<segments><segment>id@x</segment></segments>', '&e;'),
            'unknown_child' => $file('<extra/><segments><segment>id@x</segment></segments>'),
            'processing_instruction' => $file('<?pi x?><segments><segment>id@x</segment></segments>'),
            'latin1' => '<?xml version="1.0" encoding="ISO-8859-1"?>'.$file('<segments><segment>id@x</segment></segments>'),
            'other_root' => '<root><file subject="a.nfo"><segments><segment>id@x</segment></segments></file></root>',
            'nested_segment' => $file('<segments><segment>id<b/>x</segment></segments>'),
            'single_overflow' => $file('<segments><segment>id@x</segment></segments>', 'a (1/99999999999999999999)'),
            'no_segments_element' => self::nzb(self::files(['x.nfo', 'abc-def (1/1)', 'z.par2', 'y.rar (2/2)'], [-1, 1], ['i@x'])),
            'self_closing_file' => "<nzb>\n<file subject=\"x.nfo (1/2)\"/><file subject=\"y.rar\"><segments><segment>id@1</segment></segments></file></nzb>",
            'child_namespace' => '<nzb><file xmlns="urn:other" subject="a.nfo"><segments><segment>i@x</segment></segments></file></nzb>',
            'uppercase_hex_char_ref_attr' => $file('<segments><segment>id@x</segment></segments>', 'a&#X41;.nfo (1/1)'),
            'uppercase_hex_char_ref_text' => $file('<segments><segment>id&#X41;@x</segment></segments>'),
            'lowercase_doctype_keyword' => '<!doctype nzb PUBLIC "-//newzBin//DTD NZB 1.1//EN" "http://www.newzbin.com/DTD/nzb/nzb-1.1.dtd">'.$file('<segments><segment>id@x</segment></segments>'),
            'uppercase_doctype_root' => '<!DOCTYPE NZB PUBLIC "-//newzBin//DTD NZB 1.1//EN" "http://www.newzbin.com/DTD/nzb/nzb-1.1.dtd">'.$file('<segments><segment>id@x</segment></segments>'),
            'lowercase_doctype_public' => '<!DOCTYPE nzb public "-//newzBin//DTD NZB 1.1//EN" "http://www.newzbin.com/DTD/nzb/nzb-1.1.dtd">'.$file('<segments><segment>id@x</segment></segments>'),
            'reserved_xml_namespace' => '<nzb xmlns="http://www.w3.org/XML/1998/namespace"><file subject="a.nfo (1/1)"><segments><segment>id@x</segment></segments></file></nzb>',
            'reserved_xmlns_namespace' => '<nzb xmlns="http://www.w3.org/2000/xmlns/"><file subject="a.nfo (1/1)"><segments><segment>id@x</segment></segments></file></nzb>',
            'entity_in_xmlns' => '<nzb xmlns="http://www.w3.org/XML/1998/&#110;amespace"><file subject="a.nfo (1/1)"><segments><segment>id@x</segment></segments></file></nzb>',
        ];
        $service = self::contents();
        foreach ($declined as $name => $source) {
            self::assertNull($service->scanNzbFilesNative($source, true, true), $name);
            $xml = self::load($source);
            if ($xml === false) {
                self::assertNull(self::router($service, $source)(true, true), $name);

                continue;
            }
            try {
                $expected = $service->scanNzbFilesPhp($xml, true, true);
            } catch (ErrorException $exception) {
                // A file without <segments> makes PHP iterate null; the router must raise the same warning.
                try {
                    self::router($service, $source)(true, true);
                    self::fail("$name should raise the PHP warning");
                } catch (ErrorException $routed) {
                    self::assertSame($exception->getMessage(), $routed->getMessage(), $name);
                }

                continue;
            }
            self::assertSame($expected, self::router($service, $source)(true, true), $name);
        }
    }

    public function test_accepted_mutations_parse_identically_in_libxml(): void
    {
        $service = self::contents();
        $random = new Randomizer(new Mt19937(9404));
        $seeds = array_values(array_filter(self::documents(), fn (string $doc): bool => strlen($doc) < 4000));
        $pieces = ['<', '>', '&', ';', '"', "'", '/', '=', ' ', "\t", "\n", "\r", "\0", "\x7f", "\xEF\xBF\xBE", "\xEF\xBF\xBD", "\xC3", '&amp;',
            '&#65;', '&#xFFFF;', '&#xD800;', '&#1114112;', '&lt;', '&bogus;', '<!--', '-->', '--', '<![CDATA[', ']]>', '<?x?>', '<!DOCTYPE nzb>',
            '<segment>', '</segment>', '<segments>', '</segments>', '<file subject="m.nfo">', '</file>', '<x/>', 'xmlns="u"', 'xmlns:a="u"',
            'a:b="1"', ' subject="s"', '<group>g</group>', '</nzb>', '<nzb>', "\xEF\xBB\xBF", '<?xml version="1.0"?>', 'number="1"'];
        $accepted = 0;
        for ($i = 0; $i < 20000; $i++) {
            $doc = $seeds[$random->getInt(0, count($seeds) - 1)];
            for ($m = $random->getInt(1, 3); $m > 0; $m--) {
                $at = $random->getInt(0, strlen($doc));
                $doc = match ($random->getInt(0, 2)) {
                    0 => substr($doc, 0, $at).$pieces[$random->getInt(0, count($pieces) - 1)].substr($doc, $at),
                    1 => substr($doc, 0, $at).substr($doc, min(strlen($doc), $at + $random->getInt(1, 8))),
                    default => substr($doc, 0, $at).$pieces[$random->getInt(0, count($pieces) - 1)].substr($doc, min(strlen($doc), $at + $random->getInt(1, 4))),
                };
            }
            $native = $service->scanNzbFilesNative($doc, true, true);
            if ($native === null) {
                continue;
            }
            $accepted++;
            $xml = self::load($doc);
            self::assertInstanceOf(SimpleXMLElement::class, $xml, 'Rust accepted a document libxml rejects: '.bin2hex($doc));
            self::assertSame($service->scanNzbFilesPhp($xml, true, true), $native, bin2hex($doc));
        }
        self::assertGreaterThan(500, $accepted);
    }

    public function test_router_keeps_php_for_overrides_disabled_ffi_and_other_locales(): void
    {
        $source = self::documents()['release'];
        $xml = self::load($source);
        self::assertInstanceOf(SimpleXMLElement::class, $xml);
        $parser = new class extends NzbParserService
        {
            public function detectNfoFile(string $subject): array
            {
                return ['hidden' => false, 'priority' => 7];
            }
        };
        $custom = self::contents($parser);
        self::assertSame(7, self::router($custom, $source)(true, true)['nfo']['priority'] ?? null);
        $subclass = (new ReflectionClass(new class extends NzbContentsService
        {
            public function __construct() {}

            protected function calculateCompletion(int $actualParts, int $artificialParts): float
            {
                return 42.0;
            }
        }))->newInstanceWithoutConstructor();
        (new ReflectionProperty(NzbContentsService::class, 'parserService'))->setValue($subclass, new NzbParserService);
        (new ReflectionProperty(NzbContentsService::class, 'echoOutput'))->setValue($subclass, false);
        self::assertSame(42.0, self::router($subclass, $source)(true, true)['completion'] ?? null);

        $service = self::contents();
        NzbContentsService::setUseRustFfi(false);
        try {
            self::assertSame($service->scanNzbFilesPhp($xml, true, true), self::router($service, $source)(true, true));
        } finally {
            NzbContentsService::setUseRustFfi(true);
        }

        $locale = setlocale(LC_CTYPE, '0');
        try {
            if (setlocale(LC_CTYPE, 'en_US.UTF-8', 'en_US.utf8', 'de_DE.UTF-8') !== false) {
                self::assertNull(NzbParserService::nfoCandidateFfi());
                self::assertNull($service->scanNzbFilesNative($source, true, true));
                $supported = true;
                (new NzbParserService)->detectNfoFileNative('x.nfo', $supported);
                self::assertFalse($supported);
            }
        } finally {
            setlocale(LC_CTYPE, $locale === false ? 'C' : $locale);
        }
        self::assertNotNull(NzbParserService::nfoCandidateFfi());
    }

    public function test_abi_contracts_and_capacity_retry(): void
    {
        $ffi = self::ffi();
        $small = $ffi->new('char[1]');
        self::assertSame(-1, self::invokeFfi($ffi, 'nntmux_nzb_detect_nfo_file', null, 1, $small, 1));
        self::assertSame(-1, self::invokeFfi($ffi, 'nntmux_nzb_detect_nfo_file', 'x.nfo', 5, null, 1));
        self::assertSame(-1, self::invokeFfi($ffi, 'nntmux_nzb_detect_nfo_file', 'x.nfo', 5, $small, 0));
        self::assertSame(0, self::invokeFfi($ffi, 'nntmux_nzb_detect_nfo_file', null, 0, $small, 1));
        self::assertSame(-1, self::invokeFfi($ffi, 'nntmux_nzb_detect_hidden_nfo_file', null, 1));
        self::assertSame(-1, self::invokeFfi($ffi, 'nntmux_nzb_detect_par2_index_file', null, 1));
        self::assertSame(-1, self::invokeFfi($ffi, 'nntmux_nzb_extract_parts_total', null, 1));
        self::assertSame(-1, self::invokeFfi($ffi, 'nntmux_nzb_classify_nfo_par2_packed', null, 1, 3, $small, 1));
        self::assertSame(-1, self::invokeFfi($ffi, 'nntmux_nzb_classify_nfo_par2_packed', '<nzb></nzb>', 11, 3, null, 1));
        self::assertSame(-1, self::invokeFfi($ffi, 'nntmux_nzb_classify_nfo_par2_batch_nul', "a\0x\0", 4, 3, $small, 1));
        self::assertSame(-1, self::invokeFfi($ffi, 'nntmux_nzb_classify_nfo_par2_batch_nul', "a\0", 2, 3, $small, 1));

        $id = str_repeat('i', 9000).'@long';
        $files = [['x.nfo', [$id]], ['data.rar (1/1)', [$id]]];
        for ($i = 0; $i < 20; $i++) {
            $files[] = ["p$i.par2", [$id.$i]];
        }
        $source = self::nzb($files);
        $required = self::invokeFfi($ffi, 'nntmux_nzb_classify_nfo_par2_packed', $source, strlen($source), 3, $small, 1);
        self::assertLessThan(-1, $required);
        $buffer = $ffi->new('char['.(-$required).']');
        self::assertSame(-$required, self::invokeFfi($ffi, 'nntmux_nzb_classify_nfo_par2_packed', $source, strlen($source), 3, $buffer, -$required));
        $xml = self::load($source);
        self::assertInstanceOf(SimpleXMLElement::class, $xml);
        $service = self::contents();
        self::assertSame($service->scanNzbFilesPhp($xml, true, true), $service->scanNzbFilesNative($source, true, true));
    }

    public function test_parse_nzb_routes_nfo_par2_and_completion_like_php(): void
    {
        Schema::create('releases', function (Blueprint $table): void {
            $table->id();
            $table->float('completion')->default(0);
            $table->integer('proc_par2')->default(0);
            $table->integer('nfostatus')->default(0);
        });
        $documents = [
            'release' => self::documents()['release'],
            'short_ids' => self::documents()['short_ids'],
            'hidden_then_explicit' => self::documents()['hidden_then_explicit'],
            'no_nfo' => self::nzb(self::files(['data.rar (1/3)', 'x.par2', 'y.par2'], [2], ['first@x', 'second@x', 'third@x'])),
            'declined' => '<nzb><file subject="a.nfo (1/2)"><segments><segment><![CDATA[c@x]]></segment></segments></file><file subject="b.par2"><segments><segment>p@x</segment></segments></file></nzb>',
        ];
        foreach ($documents as $name => $source) {
            $outcomes = [];
            foreach ([true, false] as $rust) {
                DB::table('releases')->delete();
                DB::table('releases')->insert(['id' => 5]);
                NzbContentsService::setUseRustFfi($rust);
                try {
                    [$service, $calls] = $this->parseNzbService($source);
                    $result = $service->parseNzb('guid', 5, 1, true);
                    $outcomes[] = [$result, $calls->ids, (array) DB::table('releases')->first()];
                } finally {
                    NzbContentsService::setUseRustFfi(true);
                }
            }
            self::assertSame($outcomes[1], $outcomes[0], $name);
        }
    }

    /** @return array{0:NzbContentsService,1:object{ids:list<string>}} */
    private function parseNzbService(string $source): array
    {
        $calls = new class
        {
            /** @var list<string> */
            public array $ids = [];
        };
        $nzb = $this->createStub(NzbService::class);
        $nzb->method('readNzbContents')->willReturn($source);
        $processor = $this->createStub(Par2Processor::class);
        $processor->method('parseFromMessage')->willReturnCallback(function (string $id) use ($calls): bool {
            $calls->ids[] = $id;

            return str_starts_with($id, 'second') || str_starts_with($id, 'Movie') || $id === 'p@x';
        });
        $postProcess = (new ReflectionClass(PostProcessService::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty(PostProcessService::class, 'par2Processor'))->setValue($postProcess, $processor);
        $service = self::contents();
        foreach (['nzbService' => $nzb, 'postProcessService' => $postProcess, 'nntp' => $this->createStub(NNTPService::class),
            'lookupPar2' => true, 'echoOutput' => false] as $property => $value) {
            (new ReflectionProperty(NzbContentsService::class, $property))->setValue($service, $value);
        }

        return [$service, $calls];
    }

    /** @return array<string, string> */
    public static function subjectWorkloads(): array
    {
        return [
            'common_rar' => '[01/50] - "Some.Movie.2026.1080p.BluRay.x264-GRP.part01.rar" yEnc (1/120)',
            'common_nfo' => '[02/50] - "Some.Movie.2026.1080p.BluRay.x264-GRP.nfo" yEnc (1/1)',
            'common_par2' => '[03/50] - "Some.Movie.2026.1080p.BluRay.x264-GRP.vol003+04.par2" yEnc (1/12)',
            'uncommon_hashed' => 'a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6 (1/1)',
            'uncommon_long' => str_repeat('Some.Long.Dotted.Name.', 90).'part001.rar" yEnc (1/9999)',
            'uncommon_named' => '"readme release notes about.txt" yEnc (1/1)',
        ];
    }

    /** @return array<string, array{0:string,1:bool,2:bool}> */
    public static function documentWorkloads(): array
    {
        $release = static function (string $name, int $rars, int $segments, bool $nfo = true): array {
            $files = $nfo ? [["[01/$rars] - \"$name.nfo\" yEnc (1/1)", ['nfo-'.$name.'@news.example.com']]] : [];
            $files[] = ["[02/$rars] - \"$name.sfv\" yEnc (1/1)", ['sfv@news.example.com']];
            for ($i = 1; $i <= $rars; $i++) {
                $ids = [];
                for ($s = 1; $s <= $segments; $s++) {
                    $ids[] = sprintf('part%dof%d.%s@news.example.com', $s, $segments, md5("$name$i"));
                }
                $files[] = [sprintf('[%02d/%d] - "%s.part%02d.rar" yEnc (1/%d)', $i + 2, $rars, $name, $i, $segments), $ids];
            }
            $files[] = ["\"$name.par2\" yEnc (1/1)", ['par2@news.example.com']];
            for ($v = 0; $v < 6; $v++) {
                $files[] = [sprintf('"%s.vol%03d+%02d.par2" yEnc (1/%d)', $name, $v, $v + 1, $v + 2), array_fill(0, $v + 2, "vol$v@news.example.com")];
            }

            return $files;
        };
        $hashed = [];
        for ($i = 0; $i < 60; $i++) {
            $hashed[] = [md5((string) $i).' (1/'.($i % 7 + 1).')', array_fill(0, $i % 7 + 1, md5("id$i").'@x')];
        }
        $hashed[] = ['abc-def (1/1)', ['hidden@x']];
        $hashed[] = [md5('p').'.par2', ['index@x']];
        $entities = [];
        for ($i = 0; $i < 40; $i++) {
            $entities[] = ["\"Tom & Jerry's <Show> S01E$i.mkv\" yEnc (1/30)", array_fill(0, 30, str_repeat('&', 20).md5("$i").'@x')];
        }
        $vols = [['data.rar (1/1)', ['d@x']]];
        for ($i = 0; $i < 200; $i++) {
            $vols[] = [sprintf('set.vol%03d+%03d.par2', $i, $i + 1), ['v'.$i.'@x', 'w'.$i.'@x']];
        }

        return [
            'common_tv' => [self::nzb($release('Show.S01E02.1080p.WEB.h264-GRP', 40, 50)), true, true],
            'common_movie' => [self::nzb($release('Some.Movie.2026.2160p.UHD.BluRay.x265-GRP', 120, 150)), true, true],
            'common_small' => [self::nzb($release('Small.Release-GRP', 2, 3)), true, true],
            'common_completion_only' => [self::nzb($release('Show.S01E03.720p.HDTV.x264-GRP', 40, 50)), false, false],
            'uncommon_obfuscated' => [self::nzb($hashed), true, true],
            'uncommon_entities' => [self::nzb($entities), true, true],
            'uncommon_par2_heavy' => [self::nzb($vols), true, true],
            'uncommon_single' => [self::nzb([['single.bin (1/1)', ['only@x']]]), true, true],
            'uncommon_no_nfo' => [self::nzb($release('NoNfo.Release-GRP', 30, 40, false)), true, true],
            'uncommon_large' => [self::nzb($release('Huge.Remux.2026-GRP', 400, 250)), true, true],
        ];
    }

    /**
     * Admission record: 2026-10-24, Apple M5 (10 CPUs), macOS 27.2, PHP 8.5.12 CLI (default ini),
     * Rust 1.99.1 release (opt=3, LTO, one codegen unit). Two independent runs (seeds 9421/9422),
     * 64 warmups, 4096 randomized pairs per method/workload (1024 for uncommon_large).
     * Values are the minimum mean/p99 speedups across both runs; p99lo is the lower bound of the
     * p99 rank interval. Raw pairs, inputs hashes and protocol: /tmp/wave9-nzb-nfo-final-{1,2}.json
     * (incremental .json.jsonl beside each); frozen sources /tmp/wave9-nzb-nfo-measured-source;
     * environment /tmp/wave9-nzb-nfo-environment.txt. Earlier runs measured a shared NZB store and
     * are kept only as invalid (/tmp/wave9-nzb-nfo-invalid-shared-store-1.*, exploratory-1.*).
     *
     * Adopted: parseNzb() via scanNzb() (Rust source scan versus SimpleXML parse + PHP scan),
     * min 2.39x mean / 2.42x p99 (uncommon_entities); common paths 3.46x-4.36x mean, 3.35x-3.55x p99.
     *   common_tv 3.53/3.43, common_movie 3.60/3.35, common_small 4.36/3.55, common_completion_only
     *   3.46/3.38, uncommon_obfuscated 4.70/3.84, uncommon_entities 2.39/2.42, uncommon_par2_heavy
     *   4.64/4.20, uncommon_single 3.02/3.06 (p99lo 1.80), uncommon_no_nfo 3.45/3.36, uncommon_large 3.69/3.77.
     * Retained in PHP (minimum mean / p99 across workloads):
     *   detectNfoFile 0.29x/0.25x, detectHiddenNfoFile 0.29x/0.30x, detectPar2IndexFile 0.37x/0.34x,
     *   extractPartsTotal 0.84x/1.00x (5.05x only on uncommon_long), scan-only packed over an already
     *   parsed document 0.15x/0.17x, NUL batch from SimpleXML 0.86x/0.85x.
     */
    /**
     * Reproduce outside PHPUnit (no database/app boot):
     * php -r 'require "vendor/autoload.php"; require "tests/Unit/RustNzbNfoCandidateScannerParityTest.php";
     * Tests\Unit\RustNzbNfoCandidateScannerParityTest::benchmark("/tmp/wave9-nzb-nfo-benchmark.json");'
     *
     * Each observation times one actual PHP caller, including FFI, scratch and decoding.
     * Randomized pair order, 64 warmups; 4096 pairs per subject workload and per document
     * (1024 for uncommon_large). parseNzbScan times NzbContentsService::scanNzb(), the section
     * parseNzb() replaced, with the library disabled (SimpleXML parse + PHP scan) and enabled.
     */
    public static function benchmark(string $destination, int $samples = 4096, int $seed = 9403): void
    {
        $parser = new NzbParserService;
        $service = self::contents();
        $random = new Randomizer(new Mt19937($seed));
        $results = [];
        file_put_contents($destination.'.jsonl', json_encode([
            'event' => 'protocol', 'samples' => $samples, 'warmups' => 64, 'seed' => $seed,
            'clock' => 'hrtime nanoseconds', 'order' => 'randomized paired single caller invocations',
        ], JSON_THROW_ON_ERROR)."\n");
        $cases = [];
        foreach (self::subjectWorkloads() as $workload => $subject) {
            $cases[] = [$workload, 'detectNfoFile', $samples, fn () => $parser->detectNfoFile($subject), fn () => $parser->detectNfoFileNative($subject)];
            $cases[] = [$workload, 'detectHiddenNfoFile', $samples, fn () => $parser->detectHiddenNfoFile($subject, 1), fn () => $parser->detectHiddenNfoFileNative($subject, 1)];
            $cases[] = [$workload, 'detectPar2IndexFile', $samples, fn () => $parser->detectPar2IndexFile($subject), fn () => $parser->detectPar2IndexFileNative($subject)];
            $cases[] = [$workload, 'extractPartsTotal', $samples, fn () => $parser->extractPartsTotal($subject), fn () => $parser->extractPartsTotalNative($subject)];
        }
        foreach (self::documentWorkloads() as $workload => [$source, $nfo, $par2]) {
            $xml = self::load($source);
            if ($xml === false) {
                throw new RuntimeException("Invalid workload $workload");
            }
            // Each workload owns its service: router() installs that workload's NZB store.
            $route = self::router(self::contents(), $source);
            $count = $workload === 'uncommon_large' ? min($samples, 1024) : $samples;
            // The replaced section of parseNzb(): parse + PHP scan versus the Rust source scan.
            $cases[] = [$workload, 'parseNzbScan', $count,
                function () use ($route, $nfo, $par2): ?array {
                    NzbContentsService::setUseRustFfi(false);

                    return $route($nfo, $par2);
                },
                function () use ($route, $nfo, $par2): ?array {
                    NzbContentsService::setUseRustFfi(true);

                    return $route($nfo, $par2);
                }];
            // Scan-only candidates against iterating an already parsed document.
            $cases[] = [$workload, 'scanNzbFilesPacked', $count, fn () => $service->scanNzbFilesPhp($xml, $nfo, $par2), fn () => $service->scanNzbFilesNative($source, $nfo, $par2)];
            $cases[] = [$workload, 'scanNzbFilesBatch', $count, fn () => $service->scanNzbFilesPhp($xml, $nfo, $par2), fn () => $service->scanNzbFilesBatchNative($xml, $nfo, $par2)];
        }
        foreach ($cases as [$workload, $method, $count, $php, $rust]) {
            $start = hrtime(true);
            $firstNative = $php();
            $firstNativeNs = hrtime(true) - $start;
            $start = hrtime(true);
            $firstRust = $rust();
            $firstRustNs = hrtime(true) - $start;
            if (serialize($firstNative) !== serialize($firstRust)) {
                file_put_contents($destination.'.jsonl', json_encode(['event' => 'invalid', 'method' => $method, 'workload' => $workload,
                    'reason' => 'parity failure'], JSON_THROW_ON_ERROR)."\n", FILE_APPEND);
                throw new RuntimeException("Parity failed: $method / $workload");
            }
            for ($i = 0; $i < 64; $i++) {
                $php();
                $rust();
            }
            $raw = [];
            for ($i = 0; $i < $count; $i++) {
                $order = $random->getInt(0, 1);
                $durations = [];
                foreach ($order === 0 ? [0, 1] : [1, 0] as $which) {
                    $call = $which === 0 ? $php : $rust;
                    $start = hrtime(true);
                    $call();
                    $durations[$which] = hrtime(true) - $start;
                }
                $raw[] = [$order, $durations[0], $durations[1]];
            }
            $base = array_column($raw, 1);
            $new = array_column($raw, 2);
            sort($base);
            sort($new);
            $meanBase = array_sum($base) / $count;
            $meanNew = array_sum($new) / $count;
            $p99Index = (int) ceil(0.99 * $count) - 1;
            $rankRadius = (int) ceil(1.96 * sqrt($count * 0.99 * 0.01));
            $lower = max(0, $p99Index - $rankRadius);
            $upper = min($count - 1, $p99Index + $rankRadius);
            $results[$method][$workload] = [
                'samples' => $count, 'first_native_ns' => $firstNativeNs, 'first_rust_ns' => $firstRustNs,
                'native_mean_ns' => $meanBase, 'rust_mean_ns' => $meanNew, 'mean_speedup' => $meanBase / $meanNew,
                'native_p99_ns' => $base[$p99Index], 'rust_p99_ns' => $new[$p99Index], 'p99_speedup' => $base[$p99Index] / $new[$p99Index],
                'p99_speedup_rank_interval' => [$base[$lower] / $new[$upper], $base[$upper] / $new[$lower]],
                'raw_pairs_order_native_rust_ns' => $raw,
            ];
            file_put_contents($destination.'.jsonl', json_encode(['event' => 'measurement', 'method' => $method, 'workload' => $workload,
                'result' => $results[$method][$workload]], JSON_THROW_ON_ERROR)."\n", FILE_APPEND);
            fwrite(STDOUT, sprintf("%-24s %-22s mean %6.2fx p99 %6.2fx  (php %9.0f ns, rust %9.0f ns)\n", $workload, $method,
                $meanBase / $meanNew, $base[$p99Index] / $new[$p99Index], $meanBase, $meanNew));
        }
        $root = dirname(__DIR__, 2);
        file_put_contents($destination, json_encode([
            'utc' => gmdate(DATE_ATOM), 'php' => PHP_VERSION, 'os' => php_uname(), 'opcache_cli' => ini_get('opcache.enable_cli'),
            'php_jit' => ini_get('opcache.jit'), 'pcre' => PCRE_VERSION, 'pcre_jit' => ini_get('pcre.jit'), 'warmups' => 64, 'seed' => $seed,
            'source_sha256' => array_map(fn (string $path): string|false => hash_file('sha256', $root.'/'.$path), [
                'app/Services/Nzb/NzbParserService.php', 'app/Services/Nzb/NzbContentsService.php', 'rust/pipeline-ffi/src/nfo.rs',
                'rust/pipeline-ffi/Cargo.lock', 'composer.lock', 'tests/Unit/RustNzbNfoCandidateScannerParityTest.php',
            ]),
            'library_sha256' => hash_file('sha256', $root.'/storage/app/nntmux-native/libnntmux_pipeline.'.(PHP_OS_FAMILY === 'Darwin' ? 'dylib' : 'so')),
            'inputs_sha256' => array_map(fn (array $w): string => hash('sha256', $w[0]), self::documentWorkloads()) + array_map(fn (string $s): string => hash('sha256', $s), self::subjectWorkloads()),
            'results' => $results,
        ], JSON_THROW_ON_ERROR));
    }
}

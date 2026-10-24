<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Nzb\NzbContentsService;
use App\Services\Nzb\NzbParserService;
use App\Services\Nzb\NzbService;
use App\Support\RustPipelineLibrary;
use PHPUnit\Framework\Attributes\Test;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use SimpleXMLElement;
use Tests\TestCase;
use Throwable;

/**
 * Parity and paired benchmarks for `NzbContentsService::par2Candidates()` / `par2FamilyKey()` against the Rust
 * exports `nntmux_nzb_par2_candidates_batch` / `nntmux_par2_family_key` (`rust/pipeline-ffi/src/predb_feeds.rs`).
 *
 * Paired randomized benchmark (`NZB_PAR2_BENCHMARK_PAIRS=200` interleaved pairs in random order, warmups untimed,
 * cycle collection before each untimed sample start,
 * release dylib `opt-level=3, lto=true, codegen-units=1`, PHP 8.5.12 NTS, Apple M5 macOS 27.2; nearest-rank p99;
 * `NZB_PAR2_BENCHMARK_REPORT=1` prints raw results with source hashes). Candidate rows time what `checkPar2Result`
 * runs per release: `par2Candidates()` on the loaded document plus the manifest/candidate signature hash; the Rust
 * rows include `asXML()`, the FFI call, output copy and `json_decode` of the candidates.
 *
 * | Path / Workload | PHP Mean / p99 (ms) | Rust FFI Mean / p99 (ms) | Mean / p99 Speedup | Production Path |
 * | --- | --- | --- | --- | --- |
 * | `par2Candidates()` + signature, release 12 rar x 20 seg + 8 par2 | 0.382 / 0.682 | 0.108 / 0.201 | 3.53x / 3.39x | Adopted Rust FFI |
 * | `par2Candidates()` + signature, release 60 rar x 140 seg + 8 par2 | 12.327 / 28.090 | 2.456 / 4.538 | 5.02x / 6.19x | Adopted Rust FFI |
 * | `par2Candidates()` + signature, obfuscated 60 files x 140 seg (all hidden) | 17.165 / 20.921 | 6.732 / 8.539 | 2.55x / 2.45x | Adopted Rust FFI |
 * | `par2Candidates()` + signature, large 200 rar x 400 seg + 8 par2 | 117.075 / 142.620 | 20.915 / 28.634 | 5.60x / 4.98x | Adopted Rust FFI |
 * | `par2Candidates()` + signature, many small 1000 files x 3 seg | 4.434 / 5.381 | 1.372 / 1.632 | 3.23x / 3.30x | Adopted Rust FFI |
 * | `par2Candidates()` + signature, Latin-1 family 60 rar x 140 seg | 10.562 / 12.592 | 2.071 / 2.450 | 5.10x / 5.14x | Adopted Rust FFI |
 * | `par2Candidates()` + signature, Greek family (`mb_strtolower` pass) 60 x 140 | 10.565 / 13.585 | 3.864 / 4.899 | 2.73x / 2.77x | Adopted Rust FFI |
 * | `par2Candidates()` + signature, serialized (no loadNzb source) 60 x 140 | 11.791 / 13.805 | 4.065 / 5.035 | 2.90x / 2.74x | Adopted Rust FFI |
 * | `par2Candidates()` + signature, literal tabs (source declined) 60 x 140 | 12.446 / 17.032 | 4.232 / 5.126 | 2.94x / 3.32x | Adopted Rust FFI |
 * | `par2FamilyKey` x2000, quoted volume subject | 0.494 / 0.833 | 0.435 / 0.638 | 1.14x / 1.31x | Retained PHP (`< 1.5x`) |
 * | `par2FamilyKey` x2000, bare non-par2 subject | 0.517 / 0.919 | 0.387 / 0.636 | 1.34x / 1.45x | Retained PHP (`< 1.5x`) |
 *
 * NZBs use the layout `NzbService` writes. The per-subject family key stays in PHP (one FFI call per subject misses
 * the gate) as the parity-tested `par2FamilyKeyNative()`; the batch export computes family keys internally. The
 * Rust SHA-256 uses the ARMv8 SHA2 instructions when present (scalar elsewhere).
 */
final class RustNzbPar2CandidatesParityTest extends TestCase
{
    private const string CDEF = <<<'C'
        ssize_t nntmux_nzb_par2_candidates_batch(const char *xml, size_t xml_len, uint32_t raw, int64_t max_bytes, const char *families, size_t families_len, char *out_buf, size_t out_cap);
        ssize_t nntmux_par2_family_key(const char *subject, size_t subject_len, char *out_buf, size_t out_cap);
        ssize_t nntmux_par2_signature_sha256(const char *head, size_t head_len, const char *manifest, size_t manifest_len, const char *tail, size_t tail_len, char *out_hex);
        C;

    private const array SUBJECT_TOKENS = [
        '[01/12] - ', '[3/3]', ' [1/2] ', '"', '"', 'Some.Release.Name', 'x', 'abc', '.par2', '.PAR2', '.par2', '.vol00+01.par2',
        '.vol1+2.PaR2', '.VOL007+08.par2', '.vol+1.par2', '.vol1+.par2', '.part01.rar', '.rar', '.r00', '.r1234', '.r12345', '.R7',
        '.001', '.0001', '.mkv', '.M2TS', '.ts', '.flac', '.nfo', '.7z', '.Mp3', '.srr', ' yEnc ', '(1/1)', '(1/5)', '[1/1]',
        '(01/10)', '1/0)', '(0/0)', '(00/001)', '[2/1]', '(12)', '(1)', '(99999999999999999999)', ' ', ' ', "\t", "\n", "\r", "'",
        'é', '日本', 'Σ', 'ß', '😀', 'Ā', '.par"', '.par ', '.12"', '.123"', '.1234"', '/', '\\', '&', '<', '>', '+', '0', '1',
        '[', ']', '(', ')', '.', '..', 'file.par2.nfo', '.par2"', '"name.vol3+4.par2"', '.par2x', 'vol', '-', '_',
    ];

    private const array NUMBERS = ['1', '2', '3', ' 4', '05', '+6', '', 'abc', '9223372036854775807', '9223372036854775808', '0', '-1', '7 ', "\t8\n", '1.0', '1e1'];

    private const array BYTES = ['4096', '700000', '2097152', '2097153', ' 12 ', '0', '', 'x', '-5', '1', '768000', '+9', '012'];

    private const array IDS = ['abc@x', ' abc@y ', '', "\n\t", '&lt;id&gt;@z', '<![CDATA[cd@x]]>', 'a<!-- c -->b', 'a<b>x</b>c', 'pa&amp;rt@h', '&#233;@u', 'id/\\"q', ' '];

    private Randomizer $random;

    protected function setUp(): void
    {
        parent::setUp();

        if (RustPipelineLibrary::resolveDomain('nzb_par2_candidates_parity', self::CDEF) === null) {
            self::markTestSkipped('libnntmux_pipeline with the NZB PAR2 candidate exports is not built.');
        }

        $this->random = new Randomizer(new Mt19937(20261024));
    }

    protected function tearDown(): void
    {
        NzbContentsService::setUseRustFfi(true);

        parent::tearDown();
    }

    #[Test]
    public function the_rust_path_is_taken_for_typical_documents(): void
    {
        $xml = $this->releaseNzb(12, 20, false);
        $service = $this->service($xml);
        $nzb = $service->loadNzb('guid');
        $this->assertInstanceOf(SimpleXMLElement::class, $nzb);
        $sources = (new ReflectionProperty(NzbContentsService::class, 'nzbSources'))->getValue($service);
        $this->assertSame($xml, $sources[$nzb], 'loadNzb keeps the parsed source for the Rust scanner');

        $php = $this->php($service, $nzb, 2097152);
        $native = $this->native($service, $nzb, 2097152, $xml);
        $this->assertNotNull($native);
        $this->assertSame($php, $native);
        $this->assertSame($php, $this->native($service, $nzb, 2097152));
        $this->assertSame('index', $native['candidates'][0]['classification']);
        $this->assertSame(json_encode($native['candidates']), (new ReflectionProperty(NzbContentsService::class, 'par2CandidatesJson'))->getValue($service));
        $ffi = RustPipelineLibrary::resolveDomain('nzb_par2_candidates', (new \ReflectionClassConstant(NzbContentsService::class, 'FFI_CDEF'))->getValue());
        $this->assertNotNull($ffi);
        $this->assertNotNull((new ReflectionMethod(NzbContentsService::class, 'par2CandidatesCall'))->invoke($service, $ffi, $xml, true, 2097152),
            'the NzbService layout is scanned from source without serializing');
    }

    #[Test]
    public function fixed_documents_match_php(): void
    {
        $service = $this->service();
        $documents = [
            '<nzb/>',
            '<nzb xmlns="http://www.newzbin.com/DTD/2003/nzb"><file subject="a.par2"><segments/></file></nzb>',
            "<!DOCTYPE nzb PUBLIC \"-//newzBin//DTD NZB 1.1//EN\" \"http://www.newzbin.com/DTD/nzb/nzb-1.1.dtd\">\n<nzb><head><meta type=\"title\">t</meta></head><file subject=\"&quot;r.par2&quot; yEnc (1/1)\"><groups><group>a.b</group></groups><segments><segment bytes=\"10\" number=\"1\">id@x</segment></segments></file></nzb>",
            '<nzb><file subject="r.vol0+1.par2"><segments><segment number="2" bytes="5">b@x</segment><segment number="1" bytes="5">a@x</segment></segments></file><file subject="r.par2"><segments><segment number="1" bytes="5">c@x</segment></segments></file></nzb>',
            '<nzb><file subject="r.vol0+1.par2"><segments><segment number="1" bytes="5">a@x</segment></segments><segments><segment number="1" bytes="5">z@x</segment></segments></file></nzb>',
            '<nzb xmlns:x="urn:x"><x:file subject="a.par2"><segments><segment number="1" bytes="5">a@x</segment></segments></x:file><file x:subject="b.par2" subject="c.par2"><x:segments/><segments><x:segment number="1" bytes="1">q</x:segment><segment number="1" bytes="1" x:number="9">r</segment></segments></file></nzb>',
            '<nzb><file subject="obfuscated"><segments><segment number="1" bytes="5">a@x</segment></segments></file><file subject="obfuscated"><segments><segment number="1" bytes="5">a@x</segment></segments></file></nzb>',
            '<nzb><file subject="A &quot;b.12&quot; yEnc (1/1)"><segments><segment number="1" bytes="3">a@x</segment></segments></file><file subject="c.par &quot;x (1/1)"><segments><segment number="1" bytes="2">b@x</segment></segments></file></nzb>',
            '<nzb><file subject="multi&#10;line.par2&#10;"><segments><segment number="1" bytes="3">a@x</segment></segments></file><file subject="&#9;tab.vol1+2.par2&#13;"><segments><segment number="1" bytes="3">a@x</segment></segments></file></nzb>',
            '<nzb><file subject="Ünïcödé.Release.par2"><segments><segment number="1" bytes="3">a@x</segment></segments></file><file subject="Ünïcödé.Release.vol0+1.PAR2"><segments><segment number="1" bytes="3">b@x</segment></segments></file></nzb>',
            '<nzb><file subject="ΣΑΣ.par2"><segments><segment number="1" bytes="3">a@x</segment></segments></file></nzb>',
            '<nzb><file><segments><segment>x</segment><segment number="1"/></segments></file></nzb>',
        ];

        $supported = 0;
        foreach ($documents as $document) {
            $source = '<?xml version="1.0" encoding="UTF-8"?>'."\n".$document;
            $nzb = simplexml_load_string($source);
            $this->assertInstanceOf(SimpleXMLElement::class, $nzb, $document);
            $php = $this->php($service, $nzb, 2097152);
            $native = $this->native($service, $nzb, 2097152);
            $this->assertSame($native, $this->native($service, $nzb, 2097152, $source), $document);
            if ($native !== null) {
                $supported++;
                $this->assertSame($php, $native, $document);
            }
            $this->assertSame($php, $this->viaPar2Candidates($service, $nzb), $document);
        }

        $this->assertSame(count($documents) - 1, $supported, 'only the same-local-name attributes decline');
    }

    #[Test]
    public function documents_php_rejects_fail_the_same_way(): void
    {
        $service = $this->service();
        foreach ([
            '<nzb><file subject="a.par2"/></nzb>' => 'count()',
            '<nzb><file subject="big.bin"><segments><segment number="1" bytes="9223372036854775807">a@x</segment><segment number="2" bytes="1">b@x</segment></segments></file></nzb>' => 'float',
        ] as $source => $message) {
            $nzb = simplexml_load_string($source);
            $this->assertInstanceOf(SimpleXMLElement::class, $nzb);
            $this->assertNull($this->native($service, $nzb, 2097152));
            $this->assertNull($this->native($service, $nzb, 2097152, $source));

            foreach ([false, true] as $rust) {
                NzbContentsService::setUseRustFfi($rust);
                try {
                    (new ReflectionMethod(NzbContentsService::class, 'par2Candidates'))->invoke($service, $nzb);
                    $this->fail('expected TypeError');
                } catch (\TypeError $error) {
                    $this->assertStringContainsString($message, $error->getMessage());
                }
            }
        }
    }

    #[Test]
    public function the_signature_matches_the_original_encoding(): void
    {
        $service = $this->service();
        $nzb = simplexml_load_string($this->releaseNzb(6, 4, true));
        $this->assertInstanceOf(SimpleXMLElement::class, $nzb);

        $signatures = [];
        foreach ([false, true] as $rust) {
            NzbContentsService::setUseRustFfi($rust);
            $candidates = (new ReflectionMethod(NzbContentsService::class, 'par2Candidates'))->invoke($service, $nzb);
            $manifest = (new ReflectionProperty(NzbContentsService::class, 'par2Manifest'))->getValue($service);
            $original = hash('sha256', json_encode(['guid' => 'g/é', 'group' => 7, 'article_limit' => 1, 'state_limit' => 2,
                'alternate' => false, 'manifest' => json_decode($manifest, true), 'candidates' => $candidates], JSON_THROW_ON_ERROR));
            $signatures[] = $signature = $this->signature($service, $candidates);
            $this->assertSame($original, $signature);
        }
        $this->assertSame($signatures[0], $signatures[1]);
        $this->assertNotSame([], json_decode($manifest, true));
    }

    #[Test]
    public function randomized_documents_match_php(): void
    {
        $service = $this->service();
        $supported = $serializedSupported = $sourceScans = $total = 0;
        $call = new ReflectionMethod(NzbContentsService::class, 'par2CandidatesCall');
        $ffi = RustPipelineLibrary::resolveDomain('nzb_par2_candidates', (new \ReflectionClassConstant(NzbContentsService::class, 'FFI_CDEF'))->getValue());
        $this->assertNotNull($ffi);

        for ($round = 0; $round < 1500; $round++) {
            $xml = $this->randomDocument();
            $previous = libxml_use_internal_errors(true);
            $nzb = simplexml_load_string($xml);
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
            if (! $nzb instanceof SimpleXMLElement) {
                continue;
            }
            $total++;
            $maxBytes = [1, 4096, 700000, 2097152, PHP_INT_MAX][$this->random->getInt(0, 4)];
            try {
                $php = $this->php($service, $nzb, $maxBytes);
            } catch (Throwable $error) {
                $this->assertNull($this->native($service, $nzb, $maxBytes), get_class($error).' '.$xml);
                $this->assertNull($this->native($service, $nzb, $maxBytes, $xml), get_class($error).' '.$xml);

                continue;
            }
            $native = $this->native($service, $nzb, $maxBytes, $xml);
            if ($native !== null) {
                $supported++;
                $this->assertSame($php, $native, $xml);
            }
            $serialized = $this->native($service, $nzb, $maxBytes);
            if ($serialized !== null) {
                $serializedSupported++;
                $this->assertSame($php, $serialized, $xml);
            }
            // The source scan alone (no serialization fallback) agrees byte-for-byte with the serialized scan.
            $fromSource = $call->invoke($service, $ffi, $xml, true, $maxBytes);
            if ($fromSource !== null) {
                $sourceScans++;
                $this->assertSame($call->invoke($service, $ffi, (string) $nzb->asXML(), false, $maxBytes), $fromSource, $xml);
            }
        }

        $this->assertGreaterThan(600, $total);
        $this->assertGreaterThan((int) ($total * 0.9), $supported, 'most generated documents stay on the Rust path');
        $this->assertGreaterThan((int) ($total * 0.9), $serializedSupported, 'most serialized documents stay on the Rust path');
        // Literal tabs and newlines in attribute values (normalized by libxml) decline the source scan only.
        $this->assertGreaterThan((int) ($total * 0.5), $sourceScans, 'most sources are scanned directly');
    }

    #[Test]
    public function family_keys_match_php(): void
    {
        $service = $this->service();
        $php = new ReflectionMethod(NzbContentsService::class, 'par2FamilyKey');
        $subjects = ['', ' ', '"', '""', 'a.par2', '"a.par2"', '[1/2] "x.vol1+2.par2" yEnc', "x.par2\n", "x.vol1+2.par2\n", "\"x\ny.par2\"", 'a"b.par2 c',
            '"q" b.PAR2', 'b.par2"', ' [01/10] - r.vol001+002.PAR2', "'r.par2'", '"\'r.par2\'"', 'r.vol.par2', 'x.vol1+2.par2.vol3+4.par2', "\v\f[1/1]\fa.par2"];
        for ($round = 0; $round < 4000; $round++) {
            $subjects[] = $this->randomSubject();
        }

        foreach ($subjects as $subject) {
            $supported = false;
            $result = $service->par2FamilyKeyNative($subject, $supported);
            $this->assertTrue($supported);
            $this->assertSame($php->invoke($service, $subject), $result, json_encode($subject, JSON_INVALID_UTF8_SUBSTITUTE) ?: '');
        }
    }

    #[Test]
    public function adopted_paths_meet_the_speedup_gate(): void
    {
        $pairs = $this->pairs();
        $results = [];
        $ffi = RustPipelineLibrary::resolveDomain('nzb_par2_candidates', (new \ReflectionClassConstant(NzbContentsService::class, 'FFI_CDEF'))->getValue());
        $this->assertNotNull($ffi);

        $release = $this->releaseNzb(60, 140, false);
        foreach ([
            'release 12 rar x 20 seg + 8 par2' => [$this->releaseNzb(12, 20, false), 5, true],
            'release 60 rar x 140 seg + 8 par2' => [$release, 3, true],
            'obfuscated 60 files x 140 seg' => [$this->releaseNzb(60, 140, true), 3, true],
            'large 200 rar x 400 seg + 8 par2' => [$this->releaseNzb(200, 400, false), 2, true],
            'many small 1000 files x 3 seg' => [$this->releaseNzb(1000, 3, false), 2, true],
            'Latin-1 family 60 rar x 140 seg' => [$this->releaseNzb(60, 140, false, 'Ünïcödé.Réléäse'), 3, true],
            'Greek family (mb_strtolower pass) 60 rar x 140 seg' => [$this->releaseNzb(60, 140, false, 'Σειρά.Τίτλος.2024'), 3, true],
            'serialized (no loadNzb source) 60 rar x 140 seg' => [$release, 3, false],
            'literal tabs (source declined) 60 rar x 140 seg' => [str_replace('&quot; yEnc', "&quot;\tyEnc", $release), 3, true],
        ] as $workload => [$xml, $warmups, $fromSource]) {
            $service = $this->service($xml);
            $nzb = $service->loadNzb('guid');
            $this->assertInstanceOf(SimpleXMLElement::class, $nzb);
            $this->assertNotNull($this->native($service, $nzb, 2097152, $fromSource ? $xml : null), $workload.' stays on the Rust path');
            $this->setSource($service, $nzb, $fromSource ? $xml : null);
            if (str_contains($workload, 'declined')) {
                $this->assertNull((new ReflectionMethod(NzbContentsService::class, 'par2CandidatesCall'))->invoke($service, $ffi, $xml, true, 2097152));
            }
            $expected = $this->timedCandidates($service, $nzb, false);
            $results[$workload] = $this->paired($pairs, $warmups, fn (bool $rust): string => $this->timedCandidates($service, $nzb, $rust), $expected);
        }

        $this->report($results, 'adopted');

        foreach (getenv('NZB_PAR2_BENCHMARK_REPORT') === false ? $results : [] as $workload => $result) {
            $this->assertGreaterThanOrEqual(1.5, $result['mean_speedup'], $workload.' mean');
            $this->assertGreaterThanOrEqual(1.5, $result['p99_speedup'], $workload.' p99');
        }
    }

    #[Test]
    public function retained_family_key_helper_is_measured_against_php(): void
    {
        $pairs = $this->pairs();
        $service = $this->service();
        $php = new ReflectionMethod(NzbContentsService::class, 'par2FamilyKey');
        $results = [];

        foreach ([
            'par2FamilyKey x2000 quoted volume subject' => '[01/12] - "Some.Release.Name.2024.1080p.WEB-DL-GRP.vol015+16.par2" yEnc (1/3)',
            'par2FamilyKey x2000 bare non-par2 subject' => 'Some.Release.Name.2024.1080p.WEB-DL-GRP.part01.rar yEnc (1/140)',
        ] as $workload => $subject) {
            $expected = $php->invoke($service, $subject);
            $call = static function (bool $rust) use ($service, $php, $subject): ?string {
                $result = null;
                for ($i = 0; $i < 2000; $i++) {
                    $result = $rust ? $service->par2FamilyKeyNative($subject) : $php->invoke($service, $subject);
                }

                return $result;
            };
            $results[$workload] = $this->paired($pairs, 3, $call, $expected);
        }

        $this->report($results, 'retained');
        $this->assertCount(2, $results);
    }

    private function service(string $source = ''): NzbContentsService
    {
        $service = (new ReflectionClass(NzbContentsService::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty(NzbContentsService::class, 'parserService'))->setValue($service, new NzbParserService);
        (new ReflectionProperty(NzbContentsService::class, 'echoOutput'))->setValue($service, false);
        $nzbService = $this->createStub(NzbService::class);
        $nzbService->method('readNzbContents')->willReturn($source);
        (new ReflectionProperty(NzbContentsService::class, 'nzbService'))->setValue($service, $nzbService);

        return $service;
    }

    private function setSource(NzbContentsService $service, SimpleXMLElement $nzb, ?string $source): void
    {
        $sources = null;
        if ($source !== null) {
            $sources = new \WeakMap;
            $sources[$nzb] = $source;
        }
        (new ReflectionProperty(NzbContentsService::class, 'nzbSources'))->setValue($service, $sources);
    }

    /**
     * @return array{candidates: mixed, manifest: mixed, valid: mixed, bytes: mixed}
     */
    private function php(NzbContentsService $service, SimpleXMLElement $nzb, int $maxBytes): array
    {
        $candidates = (new ReflectionMethod(NzbContentsService::class, 'par2CandidatesPhp'))->invoke($service, $nzb, $maxBytes);

        return $this->state($service, $candidates);
    }

    /**
     * @return array{candidates: mixed, manifest: mixed, valid: mixed, bytes: mixed}|null
     */
    private function native(NzbContentsService $service, SimpleXMLElement $nzb, int $maxBytes, ?string $source = null): ?array
    {
        $this->setSource($service, $nzb, $source);
        (new ReflectionProperty(NzbContentsService::class, 'par2Manifest'))->setValue($service, 'stale');
        (new ReflectionProperty(NzbContentsService::class, 'par2StructureValid'))->setValue($service, false);
        (new ReflectionProperty(NzbContentsService::class, 'par2NonExplicitBytes'))->setValue($service, -1);
        $candidates = (new ReflectionMethod(NzbContentsService::class, 'par2CandidatesNative'))->invoke($service, $nzb, $maxBytes);

        return $candidates === null ? null : $this->state($service, $candidates);
    }

    /**
     * @return array{candidates: mixed, manifest: mixed, valid: mixed, bytes: mixed}
     */
    private function viaPar2Candidates(NzbContentsService $service, SimpleXMLElement $nzb): array
    {
        $this->setSource($service, $nzb, null);
        NzbContentsService::setUseRustFfi(true);
        $candidates = (new ReflectionMethod(NzbContentsService::class, 'par2Candidates'))->invoke($service, $nzb);

        return $this->state($service, $candidates);
    }

    /**
     * @return array{candidates: mixed, manifest: mixed, valid: mixed, bytes: mixed}
     */
    private function state(NzbContentsService $service, mixed $candidates): array
    {
        return [
            'candidates' => $candidates,
            'manifest' => (new ReflectionProperty(NzbContentsService::class, 'par2Manifest'))->getValue($service),
            'valid' => (new ReflectionProperty(NzbContentsService::class, 'par2StructureValid'))->getValue($service),
            'bytes' => (new ReflectionProperty(NzbContentsService::class, 'par2NonExplicitBytes'))->getValue($service),
        ];
    }

    /**
     * The signature `checkPar2Result()` computes after par2Candidates().
     *
     * @param  array<mixed>  $candidates
     */
    private function signature(NzbContentsService $service, array $candidates): string
    {
        return (new ReflectionMethod(NzbContentsService::class, 'par2Signature'))->invoke($service,
            ['guid' => 'g/é', 'group' => 7, 'article_limit' => 1, 'state_limit' => 2, 'alternate' => false], $candidates);
    }

    /** What `checkPar2Result()` runs per loaded NZB: par2Candidates() and the progress signature. */
    private function timedCandidates(NzbContentsService $service, SimpleXMLElement $nzb, bool $rust): string
    {
        NzbContentsService::setUseRustFfi($rust);
        $candidates = (new ReflectionMethod(NzbContentsService::class, 'par2Candidates'))->invoke($service, $nzb);

        return $this->signature($service, $candidates);
    }

    /** An NZB in the layout `NzbService` writes (XMLWriter, DOCTYPE, generator comment, two-space indent). */
    private function releaseNzb(int $archives, int $segments, bool $obfuscated, string $name = 'Some.Release.Name.2024.1080p.WEB-DL-GRP'): string
    {
        $files = [];
        for ($i = 1; $i <= $archives; $i++) {
            $files[] = [$obfuscated ? bin2hex($this->random->getBytes(16)) : sprintf('%s.part%03d.rar', $name, $i), $segments];
        }
        if (! $obfuscated) {
            $files[] = [$name.'.par2', 1];
            for ($i = 0; $i < 7; $i++) {
                $files[] = [sprintf('%s.vol%03d+%02d.par2', $name, 2 ** $i - 1, 2 ** $i), 1 + $i];
            }
            $files[] = [$name.'.nfo', 1];
            $files[] = [$name.'.sfv', 1];
        }

        $writer = new \XMLWriter;
        $writer->openMemory();
        $writer->setIndent(true);
        $writer->setIndentString('  ');
        $writer->startDocument('1.0', 'UTF-8');
        $writer->startDtd('nzb', '-//newzBin//DTD NZB 1.1//EN', 'http://www.newzbin.com/DTD/nzb/nzb-1.1.dtd');
        $writer->endDtd();
        $writer->writeComment(' NZB Generated by: NNTmux 2.0 2026-10-24 00:00:00 ');
        $writer->startElement('nzb');
        $writer->writeAttribute('xmlns', 'http://www.newzbin.com/DTD/2003/nzb');
        $writer->startElement('head');
        $writer->startElement('meta');
        $writer->writeAttribute('type', 'category');
        $writer->text('TV > HD');
        $writer->endElement();
        $writer->startElement('meta');
        $writer->writeAttribute('type', 'name');
        $writer->text($name);
        $writer->endElement();
        $writer->endElement();
        $count = count($files);
        foreach ($files as $index => [$file, $parts]) {
            $writer->startElement('file');
            $writer->writeAttribute('poster', 'poster@example.com (Poster)');
            $writer->writeAttribute('date', '1729700000');
            $writer->writeAttribute('subject', sprintf('[%02d/%02d] - "%s" yEnc (1/%d)', $index + 1, $count, $file, $parts));
            $writer->startElement('groups');
            $writer->writeElement('group', 'alt.binaries.example');
            $writer->endElement();
            $writer->startElement('segments');
            for ($segment = 1; $segment <= $parts; $segment++) {
                $writer->startElement('segment');
                $writer->writeAttribute('bytes', (string) (739000 + $this->random->getInt(0, 2000)));
                $writer->writeAttribute('number', (string) $segment);
                $writer->text(bin2hex($this->random->getBytes(12)).'@news.example.com');
                $writer->endElement();
            }
            $writer->endElement();
            $writer->endElement();
        }
        $writer->endElement();
        $writer->endDocument();

        return $writer->outputMemory();
    }

    private function randomSubject(): string
    {
        $subject = '';
        for ($i = 0, $n = $this->random->getInt(0, 9); $i < $n; $i++) {
            $subject .= self::SUBJECT_TOKENS[$this->random->getInt(0, count(self::SUBJECT_TOKENS) - 1)];
        }

        return $subject;
    }

    private function attribute(string $value): string
    {
        $escaped = htmlspecialchars($value, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return $this->random->getInt(0, 1) === 0 ? $escaped : strtr($escaped, ["\t" => '&#9;', "\n" => '&#10;', "\r" => '&#13;']);
    }

    /** @param  list<string>  $values */
    private function pick(array $values): string
    {
        return $values[$this->random->getInt(0, count($values) - 1)];
    }

    private function randomDocument(): string
    {
        $variant = $this->random->getInt(0, 9);
        $xml = $variant === 0 ? '' : '<?xml version="1.0"'.($variant > 3 ? ' encoding="UTF-8"' : '').'?>';
        if ($variant % 3 === 1) {
            $xml .= '<!DOCTYPE nzb PUBLIC "-//newzBin//DTD NZB 1.1//EN" "http://www.newzbin.com/DTD/nzb/nzb-1.1.dtd">';
        }
        $xml .= $variant % 2 === 0 ? '<nzb xmlns="http://www.newzbin.com/DTD/2003/nzb" xmlns:p="urn:p">' : '<nzb xmlns:p="urn:p">';
        if ($this->random->getInt(0, 3) === 0) {
            $xml .= '<head><meta type="title">t</meta></head><!-- c -->';
        }
        for ($file = 0, $files = $this->random->getInt(0, 9); $file < $files; $file++) {
            $element = $this->random->getInt(0, 30) === 0 ? 'p:file' : 'file';
            $xml .= '<'.$element.($this->random->getInt(0, 25) === 0 ? '' : ' subject="'.$this->attribute($this->randomSubject()).'"').' poster="a@b">';
            if ($this->random->getInt(0, 3) === 0) {
                $xml .= '<groups><group>a.b.c</group></groups>';
            }
            $segmentsTags = $this->random->getInt(0, 20) === 0 ? 2 : 1;
            for ($tag = 0; $tag < $segmentsTags; $tag++) {
                $xml .= '<segments>';
                $count = $this->random->getInt(0, 6);
                $order = range(1, max(1, $count));
                $this->random->shuffleArray($order);
                for ($segment = 0; $segment < $count; $segment++) {
                    $number = $this->random->getInt(0, 3) === 0 ? $this->pick(self::NUMBERS) : (string) $order[$segment];
                    $bytes = $this->random->getInt(0, 3) === 0 ? $this->pick(self::BYTES) : (string) $this->random->getInt(1, 3000000);
                    $name = $this->random->getInt(0, 40) === 0 ? 'p:segment' : 'segment';
                    $attributes = ($this->random->getInt(0, 20) === 0 ? '' : ' number="'.$this->attribute($number).'"')
                        .($this->random->getInt(0, 20) === 0 ? '' : ' bytes="'.$this->attribute($bytes).'"');
                    $id = $this->random->getInt(0, 2) === 0 ? $this->pick(self::IDS) : bin2hex($this->random->getBytes(6)).'@example.com';
                    $xml .= $this->random->getInt(0, 30) === 0 ? '<'.$name.$attributes.'/>' : '<'.$name.$attributes.'>'.$id.'</'.$name.'>';
                }
                $xml .= '</segments>';
            }
            $xml .= '</'.$element.'>';
        }

        return $xml.'</nzb>';
    }

    private function pairs(): int
    {
        return max(40, min(2000, (int) (getenv('NZB_PAR2_BENCHMARK_PAIRS') ?: 40)));
    }

    /**
     * @param  callable(bool): mixed  $call
     * @return array{pairs: int, php_mean_ms: float, rust_mean_ms: float, php_p99_ms: float, rust_p99_ms: float, mean_speedup: float, p99_speedup: float}
     */
    private function paired(int $pairs, int $warmups, callable $call, mixed $expected): array
    {
        for ($i = 0; $i < $warmups; $i++) {
            $this->assertSame($expected, $call(false));
            $this->assertSame($expected, $call(true));
        }

        $times = ['php' => [], 'rust' => []];
        for ($pair = 0; $pair < $pairs; $pair++) {
            $order = $this->random->getInt(0, 1) === 0 ? [false, true] : [true, false];
            foreach ($order as $rust) {
                gc_collect_cycles();
                $start = hrtime(true);
                $result = $call($rust);
                $times[$rust ? 'rust' : 'php'][] = (hrtime(true) - $start) / 1e6;
                $this->assertSame($expected, $result);
                unset($result);
            }
        }

        NzbContentsService::setUseRustFfi(true);
        sort($times['php']);
        sort($times['rust']);
        $p99 = (int) ceil($pairs * 0.99) - 1;
        $phpMean = array_sum($times['php']) / $pairs;
        $rustMean = array_sum($times['rust']) / $pairs;

        return [
            'pairs' => $pairs,
            'php_mean_ms' => round($phpMean, 3),
            'rust_mean_ms' => round($rustMean, 3),
            'php_p99_ms' => round($times['php'][$p99], 3),
            'rust_p99_ms' => round($times['rust'][$p99], 3),
            'mean_speedup' => round($phpMean / $rustMean, 2),
            'p99_speedup' => round($times['php'][$p99] / $times['rust'][$p99], 2),
        ];
    }

    /**
     * @param  array<string, array<string, float|int>>  $results
     */
    private function report(array $results, string $group): void
    {
        if (getenv('NZB_PAR2_BENCHMARK_REPORT') === false) {
            return;
        }

        fwrite(STDOUT, json_encode([
            'group' => $group,
            'php_version' => PHP_VERSION,
            'host' => php_uname(),
            'source_sha256' => [
                'nzb_contents_service' => hash_file('sha256', base_path('app/Services/Nzb/NzbContentsService.php')),
                'predb_feeds_rs' => hash_file('sha256', base_path('rust/pipeline-ffi/src/predb_feeds.rs')),
                'harness' => hash_file('sha256', __FILE__),
            ],
            'results' => $results,
        ], JSON_PRETTY_PRINT)."\n");
    }
}

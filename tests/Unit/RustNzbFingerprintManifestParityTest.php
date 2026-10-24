<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Releases\Fingerprint\NzbFingerprintAnalyzer;
use App\Services\YencService;
use App\Support\RustPipelineLibrary;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;

final class RustNzbFingerprintManifestParityTest extends TestCase
{
    private const string FFI_CDEF = <<<'C'
        int32_t nntmux_nzb_file_kind(const char *subject, size_t subject_len);
        ssize_t nntmux_nzb_format_hint(const char *subject, size_t subject_len, char *out_buf, size_t out_cap);
        int32_t nntmux_nzb_detect_format_signatures(const char *bytes, size_t len);
        ssize_t nntmux_nzb_analyze_manifest(const char *xml, size_t xml_len, char *out_buf, size_t out_cap);
        ssize_t nntmux_nzb_unfold_headers(const char *input, size_t input_len, size_t count, char *out_buf, size_t out_cap);
        ssize_t nntmux_nzb_analyze_article_body(const char *body, size_t body_len, uint32_t flags, char *out_buf, size_t out_cap);
        ssize_t nntmux_nzb_select_article_samples(const char *input, size_t input_len, size_t file_count, size_t max_samples, char *out_buf, size_t out_cap);
        C;

    protected function setUp(): void
    {
        parent::setUp();
        NzbFingerprintAnalyzer::setUseRustFfi(true);
        if (RustPipelineLibrary::resolveDomain('nzb_fingerprint_manifest', self::FFI_CDEF) === null) {
            self::markTestSkipped('Rust pipeline FFI library is not available.');
        }
    }

    protected function tearDown(): void
    {
        NzbFingerprintAnalyzer::setUseRustFfi(true);
        parent::tearDown();
    }

    public function test_file_kind_and_format_hint_parity(): void
    {
        $subjects = [
            '"Example.Release.vol00+01.PAR2" yEnc (1/1)',
            '"Example.Release.par2" yEnc (1/1)',
            '"Example.Release.part001.rar" yEnc (1/25)',
            '"Example.Release.rar" yEnc (1/10)',
            '"Example.Release.r00" yEnc (1/10)',
            '"Example.Release.R99" yEnc (1/10)',
            '"Example.Release.s01" yEnc (1/10)',
            '"Example.Release.7z.001" yEnc (1/10)',
            '"Example.Release.nfo" yEnc (1/1)',
            'NoExtensionSubject line without dots',
            'Subject.too_long_extension_token.MKV yEnc',
            'Subject..double..dots.sfv',
            '',
        ];

        foreach ($subjects as $subject) {
            self::assertSame(
                NzbFingerprintAnalyzer::fileKind($subject),
                NzbFingerprintAnalyzer::fileKindNative($subject),
                "fileKind mismatch for subject: {$subject}",
            );
            self::assertSame(
                NzbFingerprintAnalyzer::formatHint($subject),
                NzbFingerprintAnalyzer::formatHintNative($subject),
                "formatHint mismatch for subject: {$subject}",
            );
        }
    }

    public function test_analyze_manifest_parity_across_valid_and_edge_case_nzbs(): void
    {
        $analyzer = new NzbFingerprintAnalyzer(new YencService);

        $standardNzb = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE nzb PUBLIC "-//newzBin//DTD NZB 1.1//EN" "http://www.newzbin.com/DTD/nzb/nzb-1.1.dtd">
<!-- Top-level comment -->
<nzb xmlns="http://www.newzbin.com/DTD/2003/nzb">
  <head>
    <meta type="title">Example &amp; Show S01E01 &lt;1080p&gt;</meta>
    <meta type="tag">HDTV</meta>
    <meta type="tag">x265 &#x26; AAC</meta>
    <meta type="   ">ignored empty type</meta>
  </head>
  <file poster="poster &quot;one&quot; &lt;p@example.com&gt;" date="1700000123" subject="&quot;Example.Show.S01E01.part01.rar&quot; yEnc (1/3)">
    <groups>
      <group>alt.binaries.teevee</group>
      <group>alt.binaries.mom</group>
    </groups>
    <segments>
      <segment bytes="3000" number="3">&lt;seg3@example.com&gt;</segment>
      <segment bytes="1000" number="1">seg1@example.com</segment>
      <segment bytes="2000" number="2">seg2@example.com</segment>
    </segments>
  </file>
  <file poster="poster@example.com" date="0001700000999" subject="&quot;Example.Show.S01E01.vol0+1.par2&quot; yEnc (1/2)">
    <groups>
      <group>alt.binaries.teevee</group>
    </groups>
    <segments>
      <segment bytes="500" number="1">par2-seg1@example.com</segment>
      <segment bytes="500" number="1">par2-seg1@example.com</segment>
      <segment bytes="invalid" number="0">par2-segbad@example.com</segment>
      <segment bytes="400" number="2">bad whitespace id</segment>
      <segment bytes="400" number="2"></segment>
    </segments>
  </file>
  <file poster="poster@example.com" date="not-a-date" subject="&quot;Example.Show.S01E01.nfo&quot; yEnc (1/1)">
    <groups>
      <group>alt.binaries.teevee</group>
    </groups>
    <segments>
      <segment bytes="9223372036854775808" number="1">nfo-overflow@example.com</segment>
    </segments>
  </file>
</nzb>
XML;

        $cumulativeOverflowNzb = <<<'XML'
<?xml version="1.0" encoding="utf-8" standalone="no"?>
<nzb>
  <file poster="p" date="0" subject="a.r00">
    <groups><group>alt.binaries.test</group></groups>
    <segments>
      <segment bytes="9223372036854775800" number="1">m1@example.com</segment>
      <segment bytes="100" number="2">m2@example.com</segment>
    </segments>
  </file>
</nzb>
XML;

        foreach ([$standardNzb, $cumulativeOverflowNzb] as $idx => $xml) {
            NzbFingerprintAnalyzer::setUseRustFfi(false);
            $phpResult = $analyzer->analyzeManifest($xml);

            NzbFingerprintAnalyzer::setUseRustFfi(true);
            $rustResult = $analyzer->analyzeManifest($xml);

            self::assertSame($phpResult, $rustResult, "Manifest parity mismatch on fixture #{$idx}");
        }
    }

    public function test_analyze_manifest_exception_parity(): void
    {
        $analyzer = new NzbFingerprintAnalyzer(new YencService);
        $invalidFixtures = [
            '<!DOCTYPE nzb SYSTEM "http://example.com/evil.dtd"><nzb/>',
            '<!DOCTYPE nzb [<!ENTITY xxe "boom">]><nzb/>',
            '<nzb><head></head></nzb>',
            '<not-nzb><file/></not-nzb>',
            '<nzb><file><unclosed></nzb>',
        ];

        foreach ($invalidFixtures as $xml) {
            NzbFingerprintAnalyzer::setUseRustFfi(false);
            $phpException = null;
            try {
                $analyzer->analyzeManifest($xml);
            } catch (RuntimeException $e) {
                $phpException = $e->getMessage();
            }

            NzbFingerprintAnalyzer::setUseRustFfi(true);
            $rustException = null;
            try {
                $analyzer->analyzeManifest($xml);
            } catch (RuntimeException $e) {
                $rustException = $e->getMessage();
            }

            self::assertNotNull($phpException);
            self::assertSame($phpException, $rustException, "Exception message mismatch for XML: {$xml}");
        }
    }

    public function test_select_article_samples_parity(): void
    {
        $analyzer = new NzbFingerprintAnalyzer(new YencService);
        $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<nzb>
  <file poster="p" date="1700000000" subject="file1.rar">
    <groups><group>alt.binaries.test</group></groups>
    <segments>
      <segment bytes="100" number="1">rar1-1@example.com</segment>
      <segment bytes="100" number="2">rar1-2@example.com</segment>
      <segment bytes="100" number="3">rar1-3@example.com</segment>
      <segment bytes="100" number="4">rar1-4@example.com</segment>
      <segment bytes="100" number="5">rar1-5@example.com</segment>
    </segments>
  </file>
  <file poster="p" date="1700000000" subject="file2.par2">
    <groups><group>alt.binaries.test</group></groups>
    <segments>
      <segment bytes="100" number="1">par2-1@example.com</segment>
      <segment bytes="100" number="2">par2-1@example.com</segment>
      <segment bytes="100" number="3">par2-3@example.com</segment>
    </segments>
  </file>
  <file poster="p" date="1700000000" subject="file3.nfo">
    <groups><group>alt.binaries.test</group></groups>
    <segments>
      <segment bytes="50" number="1">nfo-1@example.com</segment>
    </segments>
  </file>
  <file poster="p" date="1700000000" subject="file4.sfv">
    <groups><group>alt.binaries.test</group></groups>
    <segments>
      <segment bytes="50" number="1">sfv-1@example.com</segment>
      <segment bytes="50" number="2">sfv-2@example.com</segment>
    </segments>
  </file>
</nzb>
XML;

        $manifest = $analyzer->analyzeManifest($xml);
        foreach ([128, 4, 2, 1] as $maxSamples) {
            $phpOmitted = null;
            $rustOmitted = null;
            $phpSamples = $analyzer->selectArticleSamples($manifest['files'], $maxSamples, $phpOmitted);
            $rustSamples = NzbFingerprintAnalyzer::selectArticleSamplesNative($manifest['files'], $maxSamples, $rustOmitted);

            self::assertSame($phpSamples, $rustSamples, "Samples mismatch for limit {$maxSamples}");
            self::assertSame($phpOmitted, $rustOmitted, "Omitted count mismatch for limit {$maxSamples}");
        }
    }

    public function test_unfold_headers_parity(): void
    {
        $rawHeaders = [
            "Subject: \"Example.Release.part01.rar\"\r\n",
            "\t yEnc (1/15) 15000000\r\n",
            "From: poster@example.com\r\n",
            "X-Newsreader: Nyuu/0.4.1\r\n",
            "User-Agent: Postie/1.0 and ngPost/4.3\r\n",
            "X-Custom: first\r\n",
            "x-custom: second\r\n",
            "   continuation of second\n",
            "Malformed line without colon\r\n",
        ];

        NzbFingerprintAnalyzer::setUseRustFfi(false);
        $phpAnalysis = (new NzbFingerprintAnalyzer(new YencService))->analyzeArticle($rawHeaders, '');

        NzbFingerprintAnalyzer::setUseRustFfi(true);
        $nativeUnfolded = NzbFingerprintAnalyzer::unfoldHeadersNative($rawHeaders);

        self::assertNotNull($nativeUnfolded);
        self::assertSame($phpAnalysis['headers'], $nativeUnfolded['headers']);
        self::assertSame($phpAnalysis['header_profile']['ordered_names'], $nativeUnfolded['ordered_names']);
        self::assertSame(
            $phpAnalysis['retrieval_measurements']['raw_header_bytes_including_crlf'],
            $nativeUnfolded['raw_header_bytes_including_crlf'],
        );
        self::assertSame(
            $phpAnalysis['uploader_software_candidates'],
            $nativeUnfolded['uploader_software_candidates'],
        );
    }

    public function test_analyze_article_and_decoded_bytes_parity(): void
    {
        $yenc = new YencService;
        $analyzer = new NzbFingerprintAnalyzer($yenc);

        $rar5Payload = "Rar!\x1A\x07\x01\x00".random_bytes(512)."\x00\x09\x0A\x0D\x20\x2E\x3D";
        $singlePartArticle = $yenc->encode($rar5Payload, 'Example.part01.rar', 128, true)."\r\n";

        $multipartArticle = "=ybegin part=1 total=3 line=128 size=300 name=Example.part01.rar\r\n"
            ."=ypart begin=1 end=8\r\n"
            ."Rar!\x1A\x07\x00\r\n"
            ."=yend size=8 part=1 total=3 pcrc32=12345678 crc32=87654321 extra=val\r\n";

        $danglingEscapeArticle = "=ybegin line=128 size=4 name=bad.bin\r\n*+,-=\r\n=yend size=4 crc32=9b0d08f1\r\n";
        $bareLfBody = "=ybegin line=128 size=4 name=lf.bin\n*+,-\n=yend size=4 crc32=9b0d08f1";
        $bareCrAndNulBody = "plain text\rwith bare cr and \x00 nul byte\r\n";

        $headers = [
            "Subject: \"Example.part01.rar\" yEnc (1/3)\r\n",
            "From: uploader@example.com\r\n",
            "X-Newsreader: ngPost v4.16\r\n",
            "Lines: 10\r\n",
        ];

        foreach ([
            'single_part' => $singlePartArticle,
            'multipart' => $multipartArticle,
            'dangling_escape' => $danglingEscapeArticle,
            'bare_lf' => $bareLfBody,
            'bare_cr_nul' => $bareCrAndNulBody,
            'empty' => '',
        ] as $name => $body) {
            NzbFingerprintAnalyzer::setUseRustFfi(false);
            $phpResult = $analyzer->analyzeArticleAndDecodedBytes($headers, $body);

            NzbFingerprintAnalyzer::setUseRustFfi(true);
            $rustResult = $analyzer->analyzeArticleAndDecodedBytes($headers, $body);

            self::assertSame($phpResult, $rustResult, "Article analysis mismatch on fixture: {$name}");
        }
    }

    public function test_private_fallback_helpers_parity_via_reflection(): void
    {
        $bodyMetaMethod = new ReflectionMethod(NzbFingerprintAnalyzer::class, 'fallbackNntpBodyMetadata');
        $framingMethod = new ReflectionMethod(NzbFingerprintAnalyzer::class, 'fallbackYencFramingEvidenceIncomplete');
        $sigMethod = new ReflectionMethod(NzbFingerprintAnalyzer::class, 'detectFormatSignatures');

        $bodies = [
            '',
            "=ybegin line=128 size=4 name=test.bin\r\n*+,-\r\n=yend size=4 crc32=9b0d08f1\r\n",
            "=ybegin line=128 size=4 size=5 name=conflict.bin\r\n*+,-\r\n=yend size=4\r\n",
            "=ypart begin=1 end=4\r\n=ybegin line=128 size=4 name=order.bin\r\n=yend size=4\r\n",
            "line1\nline2\rline3\x00unterminated",
        ];

        foreach ($bodies as $idx => $body) {
            NzbFingerprintAnalyzer::setUseRustFfi(false);
            $phpBodyMeta = $bodyMetaMethod->invoke(null, $body);
            $phpFraming = $framingMethod->invoke(null, $body);

            NzbFingerprintAnalyzer::setUseRustFfi(true);
            $rustBodyMeta = $bodyMetaMethod->invoke(null, $body);
            $rustFraming = $framingMethod->invoke(null, $body);

            self::assertSame($phpBodyMeta, $rustBodyMeta, "fallbackNntpBodyMetadata mismatch on #{$idx}");
            self::assertSame($phpFraming, $rustFraming, "fallbackYencFramingEvidenceIncomplete mismatch on #{$idx}");
        }

        $isoSample = str_repeat("\x00", 32_769).'CD001';
        $sigPayloads = [
            "Rar!\x1A\x07\x01\x00",
            "Rar!\x1A\x07\x00",
            "7z\xBC\xAF\x27\x1C",
            "PK\x03\x04",
            "\x1F\x8B\x08",
            "\xFD7zXZ\x00",
            $isoSample,
            "PK\x03\x04".str_repeat("\x00", 32_765).'CD001',
            'random non-matching bytes',
        ];

        foreach ($sigPayloads as $idx => $bytes) {
            NzbFingerprintAnalyzer::setUseRustFfi(false);
            $phpSigs = $sigMethod->invoke(null, $bytes);

            NzbFingerprintAnalyzer::setUseRustFfi(true);
            $rustSigs = $sigMethod->invoke(null, $bytes);

            self::assertSame($phpSigs, $rustSigs, "detectFormatSignatures mismatch on #{$idx}");
        }
    }
}

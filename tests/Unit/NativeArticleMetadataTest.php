<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Releases\Fingerprint\NativeMetadataParser;
use App\Services\Releases\Fingerprint\NzbFingerprintAnalyzer;
use App\Services\Yenc\PhpPayloadDecoder;
use App\Services\YencService;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;

final class NativeArticleMetadataTest extends TestCase
{
    private Container $previousContainer;

    /** @var list<string> */
    private array $temporaryPaths = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousContainer = Container::getInstance();
        $container = new Container;
        $container->instance('config', new Repository(['yenc' => ['native_library' => '']]));
        Container::setInstance($container);
    }

    protected function tearDown(): void
    {
        Container::setInstance($this->previousContainer);
        foreach (array_reverse($this->temporaryPaths) as $path) {
            if (file_exists($path)) {
                is_dir($path) ? rmdir($path) : unlink($path);
            }
        }
        parent::tearDown();
    }

    public function test_missing_library_and_over_budget_inputs_fall_back(): void
    {
        config(['yenc.native_library' => '/missing/article-metadata.so']);
        $parser = new NativeMetadataParser;
        self::assertNull($parser->parseArticleMetadata('', ''));
        self::assertNull($parser->parseArticleMetadata(str_repeat('x', 131073), ''));
        self::assertNull($parser->parseArticleMetadata('', str_repeat('x', 2000001)));
        $metadata = (new NzbFingerprintAnalyzer(new YencService(new PhpPayloadDecoder)))->analyzeArticle([], "x\0\xff\r\n");
        self::assertSame(1, $metadata['nntp_body_metadata']['nul_byte_count']);
        self::assertSame(5, $metadata['nntp_body_metadata']['body_octets_after_dot_unstuffing']);
    }

    public function test_bundle_validation_accepts_incomplete_header_statuses_and_rejects_incompatible_schemas(): void
    {
        $method = new ReflectionMethod(NativeMetadataParser::class, 'hasArticleMetadataSchema');
        $bundle = self::schemaBundle();
        self::assertTrue($method->invoke(null, $bundle));
        foreach (['line_budget_exceeded', 'field_budget_exceeded', 'budget_exceeded'] as $status) {
            $bundle['nntp_header_metadata']['status'] = $status;
            self::assertTrue($method->invoke(null, $bundle));
        }
        foreach (['yenc_framing_metadata', 'nntp_header_metadata', 'nntp_body_metadata', 'nntp_lines_body_relationship'] as $key) {
            $invalid = $bundle;
            unset($invalid[$key]);
            self::assertFalse($method->invoke(null, $invalid), $key);
        }
        foreach (['nntp_header_metadata', 'nntp_body_metadata', 'nntp_lines_body_relationship'] as $key) {
            $invalid = $bundle;
            $invalid[$key]['schema_version'] = 99;
            self::assertFalse($method->invoke(null, $invalid), $key);
            $invalid[$key]['schema_version'] = $bundle[$key]['schema_version'];
            $invalid[$key]['parser_engine'] = 'unexpected';
            self::assertFalse($method->invoke(null, $invalid), $key);
        }
        $bundle['yenc_framing_metadata']['parser_engine'] = 'unexpected';
        self::assertFalse($method->invoke(null, $bundle));
    }

    public function test_malformed_framing_completeness_and_nested_consumed_fields_are_rejected(): void
    {
        $method = new ReflectionMethod(NativeMetadataParser::class, 'hasArticleMetadataSchema');
        $bundle = self::schemaBundle();
        $malformed = [];
        foreach (['frames_truncated', 'control_lines_truncated'] as $key) {
            $invalid = $bundle;
            $invalid['yenc_framing_metadata'][$key] = 'true';
            $malformed[] = $invalid;
            unset($invalid['yenc_framing_metadata'][$key]);
            $malformed[] = $invalid;
        }
        $bundle['yenc_framing_metadata']['frames'] = [['frame_index' => 1, 'size_relationship_status' => 'complete']];
        $bundle['yenc_framing_metadata']['lines'] = [[
            'kind' => 'ybegin', 'frame_index' => 1, 'field_occurrences_truncated' => false,
            'conflicting_duplicate_field_names' => [],
        ]];
        $bundle['yenc_framing_metadata']['frame_count'] = 1;
        $bundle['yenc_framing_metadata']['control_line_count'] = 1;
        self::assertTrue($method->invoke(null, $bundle));
        foreach ([['frames', 'frame_index', '1'], ['frames', 'size_relationship_status', true],
            ['lines', 'kind', true], ['lines', 'frame_index', '1'],
            ['lines', 'field_occurrences_truncated', 'true'],
            ['lines', 'conflicting_duplicate_field_names', 'size'],
            ['lines', 'conflicting_duplicate_field_names', [1]]] as [$list, $key, $value]) {
            $invalid = $bundle;
            $invalid['yenc_framing_metadata'][$list][0][$key] = $value;
            $malformed[] = $invalid;
            unset($invalid['yenc_framing_metadata'][$list][0][$key]);
            $malformed[] = $invalid;
        }
        foreach ($malformed as $invalid) {
            self::assertFalse($method->invoke(null, $invalid));
        }
        $bundle['yenc_framing_metadata']['frames_truncated'] = true;
        $bundle['yenc_framing_metadata']['control_lines_truncated'] = true;
        $bundle['yenc_framing_metadata']['lines'][0]['frame_index'] = null;
        $bundle['yenc_framing_metadata']['lines'][0]['field_occurrences_truncated'] = true;
        self::assertTrue($method->invoke(null, $bundle));
        $incomplete = new ReflectionMethod(NzbFingerprintAnalyzer::class, 'yencFramingEvidenceIncomplete');
        self::assertTrue($incomplete->invoke(null, $bundle['yenc_framing_metadata']));
    }

    public function test_native_bundle_preserves_exact_legacy_subobjects_and_analyzer_output(): void
    {
        $path = $this->requireNativeLibrary();
        $parser = new NativeMetadataParser;
        $cache = new ReflectionProperty(NativeMetadataParser::class, 'articleMetadataLibraries');
        $key = getmypid().':'.realpath($path);
        $analyzer = new NzbFingerprintAnalyzer(new YencService(new PhpPayloadDecoder));
        foreach (self::fixtures() as [$headers, $body]) {
            $bundle = $parser->parseArticleMetadata(implode('', $headers), $body);
            self::assertNotNull($bundle, 'Combined parser is required in native qualification.');
            $expected = [
                'yenc_framing_metadata' => $parser->parseYencFraming($body),
                'nntp_header_metadata' => $parser->parseNntpHeaders(implode('', $headers)),
                'nntp_body_metadata' => $parser->parseNntpBody($body),
                'nntp_lines_body_relationship' => $parser->compareNntpLinesToBody(implode('', $headers), $body),
            ];
            foreach ($expected as $field => $value) {
                self::assertSame(json_encode($value), json_encode($bundle[$field]), $field);
            }
            $combined = $analyzer->analyzeArticleAndDecodedBytes($headers, $body);
            $libraries = $cache->getValue();
            $cache->setValue(null, [$key => null]);
            try {
                $legacy = $analyzer->analyzeArticleAndDecodedBytes($headers, $body);
                self::assertSame(serialize($legacy), serialize($combined));
            } finally {
                $cache->setValue(null, $libraries);
            }
        }
        self::assertNotNull($parser->parseArticleMetadata(str_repeat('x', 131072), str_repeat('x', 2000000)));
    }

    public function test_old_library_absent_symbol_is_cached_without_disabling_body_parser(): void
    {
        $this->requireNativePlatform();
        $path = $this->compileLibrary('char* nntmux_nntp_body_measurement_json(const unsigned char* data, size_t length) { return strdup("{\\"parser_engine\\":\\"legacy-body\\"}"); }');
        config(['yenc.native_library' => $path]);
        $parser = new NativeMetadataParser;
        self::assertNull($parser->parseArticleMetadata('', ''));
        self::assertNull($parser->parseArticleMetadata('', ''));
        $cache = (new ReflectionProperty(NativeMetadataParser::class, 'articleMetadataLibraries'))->getValue();
        self::assertArrayHasKey(getmypid().':'.realpath($path), $cache);
        self::assertNull($cache[getmypid().':'.realpath($path)]);
        self::assertSame(['parser_engine' => 'legacy-body'], $parser->parseNntpBody('x'));
    }

    public function test_bad_probe_and_null_pointer_are_rejected_and_freed(): void
    {
        $this->requireNativePlatform();
        foreach (['return strdup("{}");', 'return strdup("invalid json");', 'return NULL;'] as $result) {
            $path = $this->compileLibrary('char* nntmux_article_metadata_v1_json(const char* h, size_t hl, const char* b, size_t bl) { '.$result.' }');
            config(['yenc.native_library' => $path]);
            self::assertNull((new NativeMetadataParser)->parseArticleMetadata('Lines: 1\r\n', 'x'));
            $ffi = \FFI::cdef('int freed_count(void);', $path);
            self::assertSame(str_contains($result, 'NULL') ? 0 : 1, $ffi->freed_count());
        }
    }

    public function test_invalid_results_after_a_valid_probe_free_allocations_and_use_the_whole_legacy_path(): void
    {
        $this->requireNativePlatform();
        $probe = self::schemaBundle();
        $probeLiteral = json_encode(json_encode($probe, JSON_THROW_ON_ERROR), JSON_THROW_ON_ERROR);
        $invalidResults = ['{}', 'not json', 'null'];
        foreach (['frames_truncated', 'control_lines_truncated'] as $key) {
            $invalid = $probe;
            $invalid['yenc_framing_metadata'][$key] = 'true';
            $invalidResults[] = json_encode($invalid, JSON_THROW_ON_ERROR);
            unset($invalid['yenc_framing_metadata'][$key]);
            $invalidResults[] = json_encode($invalid, JSON_THROW_ON_ERROR);
        }
        $invalid = $probe;
        $invalid['yenc_framing_metadata']['frames'] = [['frame_index' => 1]];
        $invalidResults[] = json_encode($invalid, JSON_THROW_ON_ERROR);
        $invalid = $probe;
        $invalid['yenc_framing_metadata']['lines'] = [[
            'kind' => 'ybegin', 'frame_index' => 1, 'field_occurrences_truncated' => 'true',
            'conflicting_duplicate_field_names' => [],
        ]];
        $invalidResults[] = json_encode($invalid, JSON_THROW_ON_ERROR);
        foreach ($invalidResults as $invalid) {
            $literal = json_encode($invalid, JSON_THROW_ON_ERROR);
            $path = $this->compileLibrary('static int calls=0;
                char* nntmux_article_metadata_v1_json(const char* h, size_t hl, const char* b, size_t bl) {
                    return strdup(++calls == 1 ? '.$probeLiteral.' : '.$literal.');
                }
                char* nntmux_nntp_body_measurement_json(const unsigned char* data, size_t length) {
                    return strdup("{\\"parser_engine\\":\\"legacy-body\\"}");
                }');
            config(['yenc.native_library' => $path]);
            $parser = new NativeMetadataParser;
            self::assertNull($parser->parseArticleMetadata("Lines: 1\r\n", "x\r\n"));
            $analyzer = new NzbFingerprintAnalyzer(new YencService(new PhpPayloadDecoder));
            $actual = $analyzer->analyzeArticle(["Lines: 1\r\n"], "x\r\n");
            self::assertSame(['parser_engine' => 'legacy-body'], $actual['nntp_body_metadata']);
            $cache = new ReflectionProperty(NativeMetadataParser::class, 'articleMetadataLibraries');
            $libraries = $cache->getValue();
            $cache->setValue(null, [getmypid().':'.realpath($path) => null]);
            try {
                self::assertSame(serialize($analyzer->analyzeArticle(["Lines: 1\r\n"], "x\r\n")), serialize($actual));
            } finally {
                $cache->setValue(null, $libraries);
            }
            $ffi = \FFI::cdef('int freed_count(void);', $path);
            // Probe, rejected result, rejected analyzer result and two legacy body results.
            self::assertSame(5, $ffi->freed_count());
        }
    }

    public function test_a_body_above_combined_budget_keeps_legacy_framing_evidence(): void
    {
        $this->requireNativeLibrary();
        $body = "=ybegin line=128 size=1 name=x\r\n".str_repeat('k', 2000001)."\r\n=yend size=1\r\n";
        $parser = new NativeMetadataParser;
        self::assertNull($parser->parseArticleMetadata('', $body));
        $legacy = $parser->parseYencFraming($body);
        self::assertNotNull($legacy);
        $analysis = (new NzbFingerprintAnalyzer(new YencService(new PhpPayloadDecoder)))->analyzeArticle([], $body);
        self::assertSame(serialize($legacy), serialize($analysis['yenc_framing_metadata']));
        self::assertSame('php-fallback', $analysis['nntp_body_metadata']['parser_engine']);
    }

    /** @return array<string, mixed> */
    private static function schemaBundle(): array
    {
        // Captured from the v1 Rust ABI for Lines: 1 CRLF and x CRLF.
        return json_decode(<<<'JSON'
            {
                "yenc_framing_metadata": {
                    "parser_engine": "nntmux-rust-ffi",
                    "input_bytes": 3,
                    "control_line_count": 0,
                    "frame_count": 0,
                    "frames_truncated": false,
                    "control_lines_truncated": false,
                    "frames": [],
                    "lines": []
                },
                "nntp_header_metadata": {
                    "parser_engine": "nntmux-rust-nntp-headers",
                    "schema_version": 3,
                    "input_bytes": 10,
                    "status": "complete",
                    "line_count": 1,
                    "header_terminator_line_index": null,
                    "field_count": 1,
                    "malformed_line_count": 0,
                    "orphan_continuation_count": 0,
                    "field_occurrences_truncated": false,
                    "ordered_field_names_sha256": "7f9b04a05ea55904e110d65aedd3f6b64ad7b6b4be94f3e08ef3be3d2f878cfb",
                    "duplicate_field_names": [],
                    "conflicting_duplicate_field_names": [],
                    "fields": [
                        {
                            "field_index": 0,
                            "name": "Lines",
                            "name_byte_length": 5,
                            "name_hex": "4c696e6573",
                            "name_hex_truncated": false,
                            "name_syntax_valid": true,
                            "normalized_name": "lines",
                            "duplicate_occurrence_index": 0,
                            "duplicate_value_conflicts": false,
                            "source_line_indices": [
                                1
                            ],
                            "continuation_line_count": 0,
                            "final_line_terminated": true,
                            "final_line_crlf_terminated": true,
                            "lines": {
                                "status": "complete",
                                "required_separator_space_valid": true,
                                "continuation_present": false,
                                "digit_count": 1,
                                "leading_zero_count": 0,
                                "declared_body_line_count": 1,
                                "syntax_valid": true,
                                "obsolete_header": true
                            },
                            "raw_value_byte_length": 4,
                            "raw_value_sha256": "e8029382a2e408d2c7d9bbecfaee872752270b2010eb2c2c4f7f52fee2c24ed8",
                            "value_byte_length": 2,
                            "value_sha256": "e12a7e051731cf1dbeefa2142a8e1abb1eb5898e2cbe4aa522120829a5588dc7",
                            "value_utf8_valid": true,
                            "value_preview_utf8": " 1",
                            "value_preview_hex": "2031",
                            "value_preview_truncated": false,
                            "newsgroups": null,
                            "xref": null
                        }
                    ],
                    "newsgroups_xref_relationship": {
                        "status": "unavailable",
                        "comparison_basis": "exact_newsgroup_octets_case_sensitive",
                        "newsgroups_field_occurrence_count": 0,
                        "xref_field_occurrence_count": 0,
                        "field_occurrence_counts_complete": true,
                        "common_group_count": 0,
                        "newsgroups_only_count": 0,
                        "xref_only_count": 0,
                        "result_lists_truncated": false,
                        "common_groups": [],
                        "newsgroups_only_groups": [],
                        "xref_only_groups": []
                    }
                },
                "nntp_body_metadata": {
                    "parser_engine": "nntmux-rust-nntp-body",
                    "schema_version": 1,
                    "status": "complete_crlf",
                    "body_octets_after_dot_unstuffing": 3,
                    "crlf_line_break_count": 1,
                    "lf_line_break_count": 1,
                    "bare_lf_count": 0,
                    "bare_cr_count": 0,
                    "nul_byte_count": 0,
                    "body_line_count": 1,
                    "observed_lf_delimited_line_count": 1,
                    "unterminated_tail_byte_count": 0,
                    "final_line_terminated": true,
                    "measurement_basis": "Raw NNTP BODY octets after one dot-unstuffing pass; excludes the NNTP dot terminator."
                },
                "nntp_lines_body_relationship": {
                    "parser_engine": "nntmux-rust-nntp-body",
                    "schema_version": 1,
                    "status": "match",
                    "comparison_basis": "Unique RFC 5536 Lines value in the completed HEAD/article header field list versus complete BODY CRLF separator count after dot unstuffing",
                    "lines_field_occurrence_count": 1,
                    "field_occurrences_complete": true,
                    "declared_body_line_count": 1,
                    "observed_body_line_count": 1,
                    "matches": true,
                    "absolute_difference": 0
                }
            }
            JSON, true, 512, JSON_THROW_ON_ERROR);
    }

    /** @return list<array{list<string>, string}> */
    private static function fixtures(): array
    {
        return [
            [[], ''],
            [["Lines: 1\r\n"], "x\r\n"],
            [["Lines: 18446744073709551615\r\n", "Subject: x\0\xff\r\n"], "x\0\xff\r\n"],
            [["Lines: 1\r\n", "Lines: 2\r\n"], "bare\nline\rtrailer"],
            [["Subject: folded\r\n", " next\r\n"], "=ybegin line=128 size=1 name=x\r\nk\r\n=yend size=1 crc32=d3d99e8b\r\n"],
            [[str_repeat("X: a\r\n", 2050)], "=ybegin line=128 size=0 name=x\r\n=yend size=0\r\n"],
            [[str_repeat("?\r\n", 4100)], "unterminated\0\xff"],
        ];
    }

    private function requireNativePlatform(): void
    {
        if (PHP_OS_FAMILY !== 'Linux' || PHP_SAPI !== 'cli' || ! extension_loaded('FFI')) {
            self::markTestSkipped('Native metadata qualification requires Linux CLI with FFI.');
        }
    }

    private function requireNativeLibrary(): string
    {
        $this->requireNativePlatform();
        $path = getenv('YENC_NATIVE_LIBRARY');
        if (! is_string($path) || ! is_file($path)) {
            self::markTestSkipped('Set YENC_NATIVE_LIBRARY for native metadata qualification.');
        }
        config(['yenc.native_library' => $path]);

        return $path;
    }

    private function compileLibrary(string $functions): string
    {
        $directory = sys_get_temp_dir().'/nntmux-article-native-test-'.bin2hex(random_bytes(8));
        self::assertTrue(mkdir($directory));
        $source = $directory.'/fixture.c';
        $library = $directory.'/fixture.so';
        $this->temporaryPaths = array_merge($this->temporaryPaths, [$directory, $source, $library]);
        file_put_contents($source, "#include <stdlib.h>\n#include <string.h>\n#include <stddef.h>\nstatic int freed=0;\nvoid nntmux_metadata_string_free(char* value) { freed++; free(value); }\nint freed_count(void) { return freed; }\n".$functions);
        $process = proc_open(['cc', '-shared', '-fPIC', $source, '-o', $library], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $output = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $output);

        return $library;
    }
}

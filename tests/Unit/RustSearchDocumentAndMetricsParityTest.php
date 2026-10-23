<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Monitoring\Prometheus\MetricFamily;
use App\Services\Monitoring\Prometheus\TextFormatter;
use App\Support\PredbSearchDocument;
use App\Support\ReleaseSearchIndexDocument;
use App\Support\RustPipelineLibrary;
use FFI;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;

final class RustSearchDocumentAndMetricsParityTest extends TestCase
{
    private const string CDEF = <<<'C'
        int64_t nntmux_predb_validate_filename_candidate(const char *id_str, size_t id_len, int64_t id_int, uint32_t has_id_int, const char *title, size_t title_len, const char *filename, size_t filename_len, const char *source, size_t source_len);
        ssize_t nntmux_prometheus_format_text(const char *input, size_t input_len, char *out_buf, size_t out_cap);
        ssize_t nntmux_release_plain_search_name(const char *input, size_t input_len, char *out_buf, size_t out_cap);
        int64_t nntmux_datetime_to_unix_utc(const char *input, size_t input_len);
        C;

    public function test_domain_exports_are_available(): void
    {
        self::assertNotNull(RustPipelineLibrary::resolveDomain('search_doc_metrics', self::CDEF));
    }

    public function test_predb_filename_candidate_matches_php_across_boundaries_unicode_and_mutations(): void
    {
        $phpMethod = new ReflectionMethod(PredbSearchDocument::class, 'filenameCandidatePhp');
        $useNativeProp = new ReflectionProperty(PredbSearchDocument::class, 'useNative');

        $good = ['_id' => '42', '_source' => ['title' => 'Canonical.Release-GRP', 'filename' => 'Alias.Release-GRP.mkv', 'source' => 'srrdb']];
        $rows = [
            null,
            [],
            ['_id' => 7],
            $good,
            ['_id' => 42, '_source' => ['title' => 'Canonical.Release-GRP']],
            ['_id' => '000042', '_source' => ['title' => 'Canonical.Release-GRP', 'filename' => null, 'source' => null]],
            ['_id' => (string) PHP_INT_MAX, '_source' => ['title' => 'Max.Int-GRP']],
            ['_id' => '9223372036854775808', '_source' => ['title' => 'Overflow.Int-GRP']],
            ['_id' => '0', '_source' => ['title' => 'Zero.String-GRP']],
            ['_id' => '000', '_source' => ['title' => 'Zeros.String-GRP']],
            ['_id' => 0, '_source' => ['title' => 'Zero.Int-GRP']],
            ['_id' => -5, '_source' => ['title' => 'Negative.Int-GRP']],
            ['_id' => "42\n", '_source' => ['title' => 'Trailing.Newline-GRP']],
            ['_id' => '42abc', '_source' => ['title' => 'Alpha.Id-GRP']],
            ['_id' => 42.5, '_source' => ['title' => 'Float.Id-GRP']],
            ['_id' => true, '_source' => ['title' => 'Bool.Id-GRP']],
        ];

        foreach (['title' => 255, 'filename' => 255, 'source' => 50] as $field => $max) {
            foreach (['a', 'ö', "\u{1F3B5}", '映'] as $char) {
                foreach ([$max - 1, $max, $max + 1] as $len) {
                    $r = $good;
                    $r['_source'][$field] = str_repeat($char, $len);
                    $rows[] = $r;
                }
            }
        }

        foreach (['title', 'filename'] as $field) {
            foreach ([
                "bad\0char",
                "bad\nchar",
                "bad\rchar",
                "bad\tchar",
                "bad\x7fchar",
                "bad\u{0085}char",
                "bad\u{00AD}char",
                "bad\u{200B}char",
                "bad\u{202E}char",
                "bad\u{FEFF}char",
                "bad\u{E000}char",
                "bad\u{FDD0}char",
                "bad\u{FFFE}char",
                "bad\u{FFFF}char",
                "bad\u{0378}char",
                "bad\x80\xffchar",
                'Valid.MÖVIE-GRP',
                'Valid.Фильм-GRP',
                'Valid.映画-GRP',
            ] as $val) {
                $r = $good;
                $r['_source'][$field] = $val;
                $rows[] = $r;
            }
        }

        foreach (['bad/sep', 'bad\\sep', 'bad|sep'] as $sep) {
            $rTitle = $good;
            $rTitle['_source']['title'] = $sep;
            $rows[] = $rTitle;

            $rFile = $good;
            $rFile['_source']['filename'] = $sep;
            $rows[] = $rFile;

            $rSrc = $good;
            $rSrc['_source']['source'] = $sep;
            $rows[] = $rSrc;
        }

        mt_srand(20261024);
        $alphabet = "abc09/_|\\-\0\n\x7f\xc3\xb6\xe2\x80\x8b\xff";
        for ($i = 0; $i < 600; $i++) {
            $r = $good;
            $targetField = ['title', 'filename', 'source'][$i % 3];
            $base = (string) $r['_source'][$targetField];
            $at = mt_rand(0, strlen($base));
            $r['_source'][$targetField] = substr_replace(
                $base,
                $alphabet[mt_rand(0, strlen($alphabet) - 1)],
                $at,
                mt_rand(0, 1),
            );
            $rows[] = $r;
        }

        try {
            foreach ($rows as $idx => $row) {
                $useNativeProp->setValue(null, true);
                $native = PredbSearchDocument::filenameCandidate($row);
                $php = $phpMethod->invoke(null, $row);
                self::assertSame($php, $native, 'Mismatch at row index '.$idx);
            }
        } finally {
            $useNativeProp->setValue(null, true);
        }
    }

    public function test_prometheus_text_formatter_matches_php_and_supports_nul_packed_protocol(): void
    {
        $ffi = RustPipelineLibrary::resolveDomain('search_doc_metrics', self::CDEF);
        self::assertNotNull($ffi);

        // Verify direct C-ABI NUL-packed protocol and buffer resize reporting
        $nulInput = implode("\0", [
            'nntmux_jobs', "Jobs processed\\total\nin worker", 'counter', '2',
            '1', 'queue', "default\"1\"\\a\nb", '42',
            '1', 'queue', 'high', '0.250000',
            'nntmux_empty', 'Omitted', 'gauge', '0',
        ]);
        $tiny = $ffi->new('char[8]');
        // @phpstan-ignore method.notFound
        $needed = $ffi->nntmux_prometheus_format_text($nulInput, strlen($nulInput), $tiny, 8);
        self::assertLessThan(-8, $needed);
        $cap = 0;
        $scratch = RustPipelineLibrary::obtainScratch($ffi, -$needed, $cap);
        self::assertNotNull($scratch);
        // @phpstan-ignore method.notFound
        $written = $ffi->nntmux_prometheus_format_text($nulInput, strlen($nulInput), $scratch, $cap);
        self::assertSame(-$needed, $written);
        self::assertSame(
            "# HELP nntmux_jobs Jobs processed\\\\total\\nin worker\n"
            ."# TYPE nntmux_jobs counter\n"
            ."nntmux_jobs{queue=\"default\\\"1\\\"\\\\a\\nb\"} 42\n"
            ."nntmux_jobs{queue=\"high\"} 0.25\n",
            FFI::string($scratch, $written),
        );

        // Verify end-to-end parity between native and pure-PHP TextFormatter
        $nativeFormatter = new TextFormatter(useNative: true);
        $phpFormatter = new TextFormatter(useNative: false);

        self::assertSame('', $nativeFormatter->format([]));
        self::assertSame('', $nativeFormatter->format([MetricFamily::gauge('nntmux_none', 'None.')]));

        $f1 = MetricFamily::gauge('nntmux_special_floats', "Help with \\ and \n newline")
            ->add(NAN, ['case' => 'nan'])
            ->add(INF, ['case' => 'inf'])
            ->add(-INF, ['case' => 'neg_inf'])
            ->add(0.0, ['case' => 'zero'])
            ->add(-0.0, ['case' => 'neg_zero'])
            ->add(2.0, ['case' => 'whole'])
            ->add(0.25, ['case' => 'quarter'])
            ->add(0.0078125, ['case' => 'tie_even_down'])
            ->add(0.0234375, ['case' => 'tie_even_up'])
            ->add(0.1234567, ['case' => 'seven_decimals'])
            ->add(1.0e-5, ['case' => 'sci_keep'])
            ->add(1.0e-7, ['case' => 'sci_zero'])
            ->add(12345678901234.5678, ['case' => 'large_float'])
            ->add(-9876543210, ['case' => 'neg_int']);

        $f2 = (new MetricFamily('nntmux_unlabelled_total', 'Counter without labels.', MetricFamily::COUNTER))
            ->add(100);

        $fEmpty = MetricFamily::gauge('nntmux_skipped', 'Skipped.');

        $families = [$fEmpty, $f1, $f2];
        self::assertSame($phpFormatter->format($families), $nativeFormatter->format($families));

        mt_srand(9001);
        for ($round = 0; $round < 50; $round++) {
            $batch = [];
            for ($i = 0; $i < 8; $i++) {
                $mf = new MetricFamily(
                    'nntmux_fuzz_'.$round.'_'.$i,
                    "Help {$i} \\ line\nnext",
                    $i % 2 === 0 ? MetricFamily::GAUGE : MetricFamily::COUNTER,
                );
                $sc = mt_rand(0, 6);
                for ($j = 0; $j < $sc; $j++) {
                    $val = match (mt_rand(0, 5)) {
                        0 => mt_rand(-100000, 100000),
                        1 => mt_rand(-100000, 100000) / 8.0,
                        2 => mt_rand(-100000, 100000) / 3.0,
                        3 => -0.0,
                        4 => NAN,
                        default => INF,
                    };
                    $mf->add($val, [
                        'k1' => 'v_'.$j,
                        'k2' => "q\"{$j}\\x\ny",
                    ]);
                }
                $batch[] = $mf;
            }
            self::assertSame($phpFormatter->format($batch), $nativeFormatter->format($batch));
        }
    }

    public function test_release_search_index_document_datetime_and_plain_search_name_match_php(): void
    {
        $ffi = RustPipelineLibrary::resolveDomain('search_doc_metrics', self::CDEF);
        self::assertNotNull($ffi);

        $cap = 0;
        $scratch = RustPipelineLibrary::obtainScratch($ffi, 1024, $cap);
        self::assertNotNull($scratch);

        foreach ([
            '',
            'Release.Name-2026_1080p',
            '..__--Leading.And.Trailing--__..',
            'Multiple...___---Separators   With   Spaces',
            " \t\nTrimmed.Release-GRP\r\n ",
            'MÖVIE.Name.2026-GRP',
        ] as $name) {
            // @phpstan-ignore method.notFound
            $n = $ffi->nntmux_release_plain_search_name($name, strlen($name), $scratch, $cap);
            self::assertGreaterThanOrEqual(0, $n);
            $actual = $n === 0 ? '' : FFI::string($scratch, $n);
            $expected = trim((string) preg_replace('/[._-]+/', ' ', $name));
            self::assertSame($expected, $actual);
        }

        $useNativeProp = new ReflectionProperty(ReleaseSearchIndexDocument::class, 'useNative');
        $dates = [
            null,
            '',
            '0',
            0,
            1767323045,
            1767323045.9,
            '1970-01-01 00:00:00',
            '1969-12-31 23:59:59',
            '2000-02-29 12:34:56',
            '2024-02-29 23:59:59',
            '2025-02-29 12:00:00',
            '2026-01-02 03:04:05',
            '2026-01-02T03:04:05Z',
            '1999-03-31',
            'invalid-date',
        ];

        try {
            foreach ($dates as $dt) {
                $row = [
                    'id' => 7,
                    'searchname' => 'Show.Name.S01E02.1080p-GRP',
                    'postdate' => $dt,
                    'adddate' => $dt,
                    'firstaired' => $dt,
                ];
                $useNativeProp->setValue(null, false);
                $expected = ReleaseSearchIndexDocument::normalize($row);
                $useNativeProp->setValue(null, true);
                $actual = ReleaseSearchIndexDocument::normalize($row);
                self::assertSame($expected, $actual, 'Mismatch for date: '.json_encode($dt));
            }
        } finally {
            $useNativeProp->setValue(null, true);
        }
    }
}

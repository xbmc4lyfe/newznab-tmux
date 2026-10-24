<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\NameFixing\ConstrainedPredbMatcher;
use App\Support\RustPipelineLibrary;
use FFI;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;

final class RustConstrainedPredbMatcherParityTest extends TestCase
{
    private ConstrainedPredbMatcher $matcher;

    protected function setUp(): void
    {
        parent::setUp();
        ConstrainedPredbMatcher::setUseRustFfi(true);
        $this->matcher = new ConstrainedPredbMatcher(useNative: true);
    }

    protected function tearDown(): void
    {
        ConstrainedPredbMatcher::setUseRustFfi(true);
        parent::tearDown();
    }

    private function requireFfi(): FFI
    {
        $ffi = RustPipelineLibrary::resolveDomain('constrained_predb_matcher', ConstrainedPredbMatcher::FFI_CDEF);
        if ($ffi === null) {
            self::markTestSkipped('Rust pipeline FFI library is not available.');
        }

        return $ffi;
    }

    #[Test]
    public function c_abi_parse_and_resize_protocol_work(): void
    {
        $ffi = $this->requireFfi();
        $input = 'The.Kanata.Kara.S01E02E003.1080p.ENGLISH.GERMAN.REPACK.PROPER.AMZN.WEB-DL.DDP2.0.H.264-ToonsHub.mkv';
        $tiny = $ffi->new('char[8]');
        // @phpstan-ignore method.notFound
        $needed = $ffi->nntmux_constrained_predb_parse($input, strlen($input), $tiny, 8);
        self::assertLessThan(-8, $needed);

        $cap = 0;
        $scratch = RustPipelineLibrary::obtainScratch($ffi, -$needed, $cap);
        self::assertNotNull($scratch);
        // @phpstan-ignore method.notFound
        $written = $ffi->nntmux_constrained_predb_parse($input, strlen($input), $scratch, $cap);
        self::assertSame(-$needed, $written);
        self::assertSame(
            "kanata kara\ngroup:toonshub\tepisode:s1e2e3\tresolution:1080p\tlanguage:english german\tedition:repack proper\namzn web dl ddp2 0 h 264",
            FFI::string($scratch, $written),
        );
    }

    #[Test]
    public function parse_matches_php_across_boundaries_markers_and_unicode(): void
    {
        $this->requireFfi();

        $cases = [
            ['', true],
            ['   ', true],
            ["\t\n\r\0\x0B", true],
            ["\x0ca.b.c", true],
            ['...', true],
            ['---', true],
            ['-grp', true],
            ['-a', true],
            ['-abcdefghijklmnopqrst', true],
            ['-abcdefghijklmnopqrstu', true],
            ['a.b.c', true],
            ['the.a.an.and.of.2026', true],
            ['Known.Movie.1999.2160i.BLURAY.X265.HDR.DOVI.DTS-GRP.mkv', true],
            ['Known.Movie.2026.480p.576i.720p.1080i.BDRIP.WEBDL.WEBRIP.HDTV.X264.H264.H265.HEVC.AVC.DDP.AAC.MP4-G1.mp4', true],
            ['Show.Name.S00E000.S01E02E030.S05.EXTENDED.UNRATED.DIRECTORS.THEATRICAL.REMASTERED.REPACK.PROPER-GRP.avi', true],
            ['Foreign.Film.1984.FRENCH.FINNISH.JAPANESE.DUTCH.SPANISH.ITALIAN.MULTI.DUAL.XXX-GRP.rar', true],
            ['Archive.Release.2021.720p.WEB-GRP.nfo', true],
            ['Parity.Release.2021.720p.WEB-GRP.par2', true],
            ['Unstripped.Ext.2021.720p.WEB-GRP.zip', true],
            ["Internal\0Nul.Movie.2026.1080p-GRP", true],
            [str_repeat('a', 512), true],
            [str_repeat('a', 513), true],
            [implode('.', array_fill(0, 64, 'tok')), true],
            [implode('.', array_fill(0, 65, 'tok')), true],
            ["Invalid\xFF\xFEUtf8", true],
            // Non-ASCII valid UTF-8 declines native and falls back to PHP
            ['Kanata.Kara.MÖVIE.2026.1080p-GRP', false],
            ["Cafe\u{0301}.Movie.2026.1080p-GRP", false],
            ['Кино.Фильм.2026.1080p-GRP', false],
            ['東京.映画.2026.1080p-GRP', false],
        ];

        foreach ($cases as $idx => [$input, $expectSupported]) {
            $expected = $this->matcher->parsePhp($input);
            $supported = false;
            $native = $this->matcher->parseNative($input, $supported);
            self::assertSame($expectSupported, $supported, "Support flag mismatch at case #{$idx}: ".json_encode($input));
            if ($expectSupported) {
                self::assertSame($expected, $native, "Native parse mismatch at case #{$idx}: ".json_encode($input));
            } else {
                self::assertNull($native);
            }
            self::assertSame($expected, $this->matcher->parse($input), "Public parse mismatch at case #{$idx}");
        }
    }

    #[Test]
    public function suggest_and_complete_evidence_match_php_across_all_reasons_and_tie_breakers(): void
    {
        $this->requireFfi();

        $baseHit = ['id' => 1, 'title' => 'Kanata.Kara.S01E02.1080p.AMZN.WEB-DL.JPN.DDP2.0.H.264-ToonsHub'];

        $scenarios = [
            // Candidate overflow (> 50)
            ['Known.Movie.2026', array_fill(0, 51, ['id' => 1, 'title' => 'Known.Movie.2026']), true],
            // Exactly 50 candidates
            ['Known.Movie.2026', array_fill(0, 50, ['id' => 1, 'title' => 'Known.Movie.2026']), true],
            // Insufficient title
            ['', [$baseHit], true],
            ['SingleToken.2026', [$baseHit], true],
            ['ab.cd.ef.2026', [$baseHit], true],
            // Missing identity anchor (no year or episode; season alone does not anchor)
            ['Kanata.Kara.S01.1080p', [$baseHit], true],
            // Partial query -> suggest matches, completeEvidence returns unresolved_release_variant
            ['kazata.kara.s1e2', [$baseHit], true],
            // Full query -> both suggest and completeEvidence match
            ['Kazata.Kara.S01E02.1080p.AMZN.WEB-DL.JPN.DDP2.0.H.264-ToonsHub', [$baseHit], true],
            // Extra candidate marker in year/edition/language/adult rejected when missing on query
            ['Known.Movie.S01E02', [['id' => 1, 'title' => 'Known.Movie.2026.S01E02']], true],
            ['Known.Movie.2026', [['id' => 1, 'title' => 'Known.Movie.2026.REPACK']], true],
            ['Known.Movie.2026', [['id' => 1, 'title' => 'Known.Movie.2026.FRENCH']], true],
            ['Known.Movie.2026', [['id' => 1, 'title' => 'Known.Movie.2026.XXX']], true],
            // Suffix mismatch when query has suffix
            ['Known.Movie.2026.WEB.x264', [['id' => 1, 'title' => 'Known.Movie.2026.WEB.x265']], true],
            // Numeric sequel edit intolerance
            ['Known.Movie.2.2026', [['id' => 1, 'title' => 'Known.Movie.3.2026']], true],
            // Short word (< 5 chars) edit intolerance
            ['Dark.Star.2026', [['id' => 1, 'title' => 'Dork.Star.2026']], true],
            // Long word (>= 10 chars) allows distance 2, rejects distance 3
            ['Extraordinary.Adventure.2026', [['id' => 1, 'title' => 'Extraordanary.Adventure.2026']], true],
            ['Extraordinary.Adventure.2026', [['id' => 1, 'title' => 'Extroordanary.Adventure.2026']], true],
            ['Extraordinary.Adventure.2026', [['id' => 1, 'title' => 'Extroordanari.Adventure.2026']], true],
            // Ambiguous top-2 within < 8 points
            [
                'Known.Movie.2026',
                [
                    ['id' => 10, 'title' => 'Known.Movie.2026.1080p.WEB-DL-GRP'],
                    ['id' => 20, 'title' => 'Known.Movie.2026.720p.WEB-DL-GRP'],
                ],
                true,
            ],
            // Non-ambiguous when top-2 score gap >= 8 points (100.0 vs 90.0)
            [
                'Alpha.Bravo.2026',
                [
                    ['id' => 10, 'title' => 'Alpho.Bravo.2026'],
                    ['id' => 20, 'title' => 'Alpha.Bravo.2026'],
                ],
                true,
            ],
            // Duplicate hit ID overwrites in-place
            [
                'Alpha.Bravo.2026',
                [
                    ['id' => 10, 'title' => 'Alpha.Bravo.2026'],
                    ['id' => 20, 'title' => 'Alpho.Bravo.2026'],
                    ['id' => 10, 'title' => 'Alpho.Bravo.2026'],
                ],
                true,
            ],
            // Invalid hit entries (id <= 0 or non-string title) skipped
            [
                'Alpha.Bravo.2026',
                [
                    ['id' => 0, 'title' => 'Alpha.Bravo.2026'],
                    ['id' => -5, 'title' => 'Alpha.Bravo.2026'],
                    ['id' => 7, 'title' => 'Alpha.Bravo.2026'],
                ],
                true,
            ],
            // Embedded NUL in candidate title declines native and falls back to PHP
            [
                'Alpha.Bravo.2026',
                [
                    ['id' => 1, 'title' => "Alpha\0Bravo.2026"],
                    ['id' => 2, 'title' => 'Alpha.Bravo.2026'],
                ],
                false,
            ],
            // Non-ASCII in query or candidate declines native and falls back to PHP
            [
                'Münchner.Geschichten.2026',
                [['id' => 1, 'title' => 'Münchner.Geschichten.2026.1080p-GRP']],
                false,
            ],
            [
                'Alpha.Bravo.2026',
                [['id' => 1, 'title' => 'Alpha.Bravö.2026']],
                false,
            ],
        ];

        foreach ($scenarios as $idx => [$query, $hits, $expectNative]) {
            $expectedSuggest = $this->matcher->suggestPhp($query, $hits);
            $expectedComplete = $this->matcher->completeEvidencePhp($query, $hits);

            $nativeSuggest = $this->matcher->suggestNative($query, $hits);
            $nativeComplete = $this->matcher->completeEvidenceNative($query, $hits);

            if ($expectNative) {
                self::assertSame($expectedSuggest, $nativeSuggest, "suggestNative mismatch at scenario #{$idx}");
                self::assertSame($expectedComplete, $nativeComplete, "completeEvidenceNative mismatch at scenario #{$idx}");
            } else {
                self::assertNull($nativeSuggest, "Expected suggestNative decline at scenario #{$idx}");
                self::assertNull($nativeComplete, "Expected completeEvidenceNative decline at scenario #{$idx}");
            }

            self::assertSame($expectedSuggest, $this->matcher->suggest($query, $hits), "suggest mismatch at scenario #{$idx}");
            self::assertSame($expectedComplete, $this->matcher->completeEvidence($query, $hits), "completeEvidence mismatch at scenario #{$idx}");
        }
    }

    #[Test]
    public function randomized_batches_match_php_exactly(): void
    {
        $this->requireFfi();
        $random = new Randomizer(new Mt19937(20261024));

        $words = [
            'kanata', 'kazata', 'chronicles', 'chronikles', 'mysterious', 'galaxy',
            'galaxies', 'adventure', 'adventures', 'shadow', 'shadows', 'station',
            '2', '3', 'the', 'of', 'and', 'sample', 'cover',
        ];
        $anchors = ['1999', '2025', '2026', 'S01E02', 'S01E002', 'S02E05E06', 'S01'];
        $extras = [
            '1080p', '720p', '2160p', 'ENGLISH', 'GERMAN', 'MULTI',
            'REPACK', 'PROPER', 'EXTENDED', 'XXX', 'WEB-DL', 'BLURAY',
            'x264', 'H265', 'DDP', 'HDR',
        ];
        $groups = ['-GRP', '-ToonsHub', '-OTHER', ''];
        $exts = ['.mkv', '.mp4', '.par2', ''];

        $buildTitle = static function () use ($random, $words, $anchors, $extras, $groups, $exts): string {
            $parts = [];
            $wCount = $random->getInt(1, 5);
            for ($i = 0; $i < $wCount; $i++) {
                $parts[] = $words[$random->getInt(0, count($words) - 1)];
            }
            if ($random->getInt(0, 4) > 0) {
                $parts[] = $anchors[$random->getInt(0, count($anchors) - 1)];
            }
            $eCount = $random->getInt(0, 4);
            for ($i = 0; $i < $eCount; $i++) {
                $parts[] = $extras[$random->getInt(0, count($extras) - 1)];
            }

            return implode('.', $parts)
                .$groups[$random->getInt(0, count($groups) - 1)]
                .$exts[$random->getInt(0, count($exts) - 1)];
        };

        for ($round = 0; $round < 250; $round++) {
            $query = $buildTitle();
            self::assertSame($this->matcher->parsePhp($query), $this->matcher->parse($query), "parse mismatch at round #{$round}");

            $hitCount = $random->getInt(0, 20);
            $hits = [];
            for ($h = 0; $h < $hitCount; $h++) {
                $hits[] = [
                    'id' => $random->getInt(0, 15),
                    'title' => $h === 0 && $random->getInt(0, 1) === 1 ? $query : $buildTitle(),
                ];
            }

            self::assertSame(
                $this->matcher->suggestPhp($query, $hits),
                $this->matcher->suggest($query, $hits),
                "suggest mismatch at round #{$round}"
            );
            self::assertSame(
                $this->matcher->completeEvidencePhp($query, $hits),
                $this->matcher->completeEvidence($query, $hits),
                "completeEvidence mismatch at round #{$round}"
            );
        }
    }

    #[Test]
    public function disabled_ffi_uses_php_fallback(): void
    {
        $hits = [['id' => 1, 'title' => 'Kanata.Kara.S01E02.1080p.AMZN.WEB-DL.JPN.DDP2.0.H.264-ToonsHub']];
        $query = 'Kazata.Kara.S01E02.1080p.AMZN.WEB-DL.JPN.DDP2.0.H.264-ToonsHub';

        ConstrainedPredbMatcher::setUseRustFfi(false);
        $supported = true;
        self::assertNull($this->matcher->parseNative($query, $supported));
        self::assertFalse($supported);
        self::assertNull($this->matcher->suggestNative($query, $hits));
        self::assertNull($this->matcher->completeEvidenceNative($query, $hits));

        self::assertSame($this->matcher->parsePhp($query), $this->matcher->parse($query));
        self::assertSame($this->matcher->suggestPhp($query, $hits), $this->matcher->suggest($query, $hits));
        self::assertSame($this->matcher->completeEvidencePhp($query, $hits), $this->matcher->completeEvidence($query, $hits));
    }

    /**
     * Opt-in paired randomized benchmark of parse(), suggest(), and completeEvidence().
     * NNTMUX_CONSTRAINED_PREDB_BENCHMARK=1 php -d opcache.enable_cli=1 artisan test --compact
     * tests/Unit/RustConstrainedPredbMatcherParityTest.php --filter=paired_benchmark
     */
    #[Test]
    public function paired_benchmark(): void
    {
        if (getenv('NNTMUX_CONSTRAINED_PREDB_BENCHMARK') !== '1') {
            self::markTestSkipped('Opt in with NNTMUX_CONSTRAINED_PREDB_BENCHMARK=1 to run 40 paired blocks per workload.');
        }
        $this->requireFfi();
        $random = new Randomizer(new Mt19937(20261024));

        $commonQuery = 'Kazata.Kara.S01E02.1080p.AMZN.WEB-DL.JPN.DDP2.0.H.264-ToonsHub';
        $commonHits = [];
        for ($i = 1; $i <= 12; $i++) {
            $commonHits[] = [
                'id' => $i,
                'title' => $i === 7
                    ? 'Kanata.Kara.S01E02.1080p.AMZN.WEB-DL.JPN.DDP2.0.H.264-ToonsHub'
                    : "Other.Series.Name.Part.{$i}.S01E02.1080p.AMZN.WEB-DL.DDP2.0.H.264-GRP",
            ];
        }

        $uncommonQuery = 'The.Extraordinary.Chronicles.Of.Mysterious.Galaxies.S03E14E15.2160p.EXTENDED.REMASTERED.GERMAN.WEB-DL.DDP.DV.HDR.H265-SUPERGROUP';
        $uncommonHits = [];
        for ($i = 1; $i <= 50; $i++) {
            $uncommonHits[] = [
                'id' => $i,
                'title' => $i === 42
                    ? 'The.Extraordanary.Chronicles.Of.Mysterious.Galaxies.S03E14E15.2160p.EXTENDED.REMASTERED.GERMAN.WEB-DL.DDP.DV.HDR.H265-SUPERGROUP'
                    : "The.Extraordinary.Chronicles.Of.Different.Galaxies.{$i}.S03E14E15.2160p.EXTENDED.REMASTERED.GERMAN.WEB-DL.DDP.DV.HDR.H265-SUPERGROUP",
            ];
        }

        $p99 = static function (array $values): float {
            sort($values);

            return $values[(int) ceil(count($values) * 0.99) - 1];
        };

        $workloads = [
            'common' => [$commonQuery, $commonHits],
            'uncommon' => [$uncommonQuery, $uncommonHits],
        ];

        foreach (['parse', 'suggest', 'completeEvidence'] as $op) {
            foreach ($workloads as $wName => [$q, $hits]) {
                for ($w = 0; $w < 200; $w++) {
                    if ($op === 'parse') {
                        $this->matcher->parsePhp($q);
                        $this->matcher->parse($q);
                    } elseif ($op === 'suggest') {
                        $this->matcher->suggestPhp($q, $hits);
                        $this->matcher->suggest($q, $hits);
                    } else {
                        $this->matcher->completeEvidencePhp($q, $hits);
                        $this->matcher->completeEvidence($q, $hits);
                    }
                }
                $samples = ['php' => [], 'rust' => []];
                for ($pair = 0; $pair < 40; $pair++) {
                    $order = $random->getInt(0, 1) === 0 ? ['php', 'rust'] : ['rust', 'php'];
                    foreach ($order as $variant) {
                        for ($call = 0; $call < 25; $call++) {
                            $start = hrtime(true);
                            if ($op === 'parse') {
                                $variant === 'php' ? $this->matcher->parsePhp($q) : $this->matcher->parse($q);
                            } elseif ($op === 'suggest') {
                                $variant === 'php' ? $this->matcher->suggestPhp($q, $hits) : $this->matcher->suggest($q, $hits);
                            } else {
                                $variant === 'php' ? $this->matcher->completeEvidencePhp($q, $hits) : $this->matcher->completeEvidence($q, $hits);
                            }
                            $samples[$variant][] = hrtime(true) - $start;
                        }
                    }
                }
                $summary = [
                    'php' => [
                        'mean_ns' => array_sum($samples['php']) / count($samples['php']),
                        'p99_ns' => $p99($samples['php']),
                    ],
                    'rust' => [
                        'mean_ns' => array_sum($samples['rust']) / count($samples['rust']),
                        'p99_ns' => $p99($samples['rust']),
                    ],
                ];
                $summary['mean_speedup'] = $summary['php']['mean_ns'] / $summary['rust']['mean_ns'];
                $summary['p99_speedup'] = $summary['php']['p99_ns'] / $summary['rust']['p99_ns'];
                fwrite(STDERR, "{$op}[{$wName}] ".json_encode($summary, JSON_THROW_ON_ERROR)."\n");
                self::assertGreaterThanOrEqual(1.5, $summary['mean_speedup'], "{$op}[{$wName}] mean");
                self::assertGreaterThanOrEqual(1.5, $summary['p99_speedup'], "{$op}[{$wName}] p99");
            }
        }
    }
}

<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\LogViewer\LogEntry;
use App\Services\LogViewer\LogEntryParser;
use App\Services\NameFixing\DonorMatchSelector;
use App\Support\BookIsbn;
use App\Support\RustPipelineLibrary;
use FFI;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RustLogDonorIsbnParityTest extends TestCase
{
    private function requireFfi(): FFI
    {
        $ffi = RustPipelineLibrary::resolveDomain('log_donor_isbn', LogEntryParser::FFI_CDEF);
        if ($ffi === null) {
            self::markTestSkipped('Rust pipeline FFI library is not available.');
        }

        return $ffi;
    }

    /**
     * @param  list<LogEntry>  $entries
     * @return list<array<string, mixed>>
     */
    private function serializeEntries(array $entries): array
    {
        return array_map(static fn (LogEntry $e): array => $e->toArray(), $entries);
    }

    #[Test]
    public function domain_resolves_with_shared_cdef(): void
    {
        $ffi = $this->requireFfi();
        self::assertSame(LogEntryParser::FFI_CDEF, DonorMatchSelector::FFI_CDEF);
        self::assertSame(LogEntryParser::FFI_CDEF, BookIsbn::FFI_CDEF);
        self::assertSame($ffi, RustPipelineLibrary::resolveDomain('log_donor_isbn', DonorMatchSelector::FFI_CDEF));
        self::assertSame($ffi, RustPipelineLibrary::resolveDomain('log_donor_isbn', BookIsbn::FFI_CDEF));
    }

    #[Test]
    public function log_entry_parser_c_abi_header_and_batch_functions_match_php(): void
    {
        $ffi = $this->requireFfi();
        $phpParser = new LogEntryParser(useNative: false);

        $lines = [
            '[2026-10-06 18:11:29] NNTmux.ERROR: Something broke {"id":1}',
            '[2026-10-06T18:11:29.123456+02:00] local.DEBUG: tick',
            '[2026-10-06T18:11:29.5Z] app.jobs.WARNING: slow',
            '[2026-10-06T18:11:29-0500] worker-1.CRITICAL: disk full',
            '[2026-10-06 18:11:29] channel.VERBOSE: unknown level',
            '[2026-10-06 18:11:29] channel.info: lowercase level is not a header',
            '#0 /var/www/app/Jobs/Example.php(12): boom()',
            '[stacktrace]',
            '  2026-10-05 21:53:21 App\\Jobs\\Example ... DONE',
            '   [2026-10-05T21:53:21] leading space before bracket',
            '',
        ];

        foreach ($lines as $line) {
            // @phpstan-ignore method.notFound
            $spans = $ffi->nntmux_log_parse_header_spans($line, strlen($line));
            $expectedHeader = $phpParser->parseHeaderPhp($line);

            if ($expectedHeader === null) {
                self::assertSame(-1, $spans, "Expected non-header for: {$line}");
            } else {
                self::assertGreaterThan(0, $spans, "Expected header spans for: {$line}");
                $tsLen = $spans & 0xFF;
                $chanStart = ($spans >> 8) & 0xFFFF;
                $chanLen = ($spans >> 24) & 0xFFFF;
                $msgStart = ($spans >> 40) & 0xFFFF;
                $lvlCode = ($spans >> 56) & 0xFF;

                self::assertSame($expectedHeader['timestamp'], substr($line, 1, $tsLen));
                self::assertSame($expectedHeader['channel'], substr($line, $chanStart, $chanLen));
                self::assertSame($expectedHeader['level'], LogEntryParser::LEVELS[$lvlCode] ?? null);
                self::assertSame($expectedHeader['message'], substr($line, $msgStart));
            }

            // @phpstan-ignore method.notFound
            $tsOff = $ffi->nntmux_log_plain_timestamp_span($line, strlen($line));
            $expectedPlain = $phpParser->plainTimestampPhp($line);
            if ($expectedPlain === null) {
                self::assertSame(-1, $tsOff);
            } else {
                self::assertGreaterThanOrEqual(0, $tsOff);
                self::assertSame($expectedPlain, substr($line, $tsOff, 19));
            }
        }

        // Batch header check and batch header parse via C-ABI
        $joined = implode("\0", $lines);
        $count = count($lines);
        $cap = 0;
        $scratch = RustPipelineLibrary::obtainScratch($ffi, 4096, $cap);
        self::assertNotNull($scratch);

        // @phpstan-ignore method.notFound
        $writtenFlags = $ffi->nntmux_log_is_header_batch_nul($joined, strlen($joined), $count, $scratch, $cap);
        self::assertSame($count, $writtenFlags);
        $flagsStr = FFI::string($scratch, $writtenFlags);
        foreach ($lines as $i => $line) {
            self::assertSame($phpParser->isHeaderPhp($line), $flagsStr[$i] === '1');
        }

        // Verify small buffer reports required negative capacity
        // @phpstan-ignore method.notFound
        $tooSmall = $ffi->nntmux_log_parse_headers_batch_nul($joined, strlen($joined), $count, $scratch, 4);
        self::assertLessThan(-4, $tooSmall);

        // @phpstan-ignore method.notFound
        $writtenHeaders = $ffi->nntmux_log_parse_headers_batch_nul($joined, strlen($joined), $count, $scratch, $cap);
        self::assertSame(-$tooSmall, $writtenHeaders);
        $parts = explode("\0", FFI::string($scratch, $writtenHeaders));
        $idx = 0;
        foreach ($lines as $line) {
            $expected = $phpParser->parseHeaderPhp($line);
            $ts = $parts[$idx++];
            if ($expected === null) {
                self::assertSame('', $ts);
            } else {
                $chan = $parts[$idx++];
                $lvl = $parts[$idx++];
                $msg = $parts[$idx++];
                self::assertSame($expected, [
                    'timestamp' => $ts,
                    'channel' => $chan,
                    'level' => $lvl !== '' ? $lvl : null,
                    'message' => $msg,
                ]);
            }
        }
    }

    #[Test]
    public function log_entry_parser_block_parsing_matches_php_across_edge_cases(): void
    {
        $native = new LogEntryParser(useNative: true);
        $php = new LogEntryParser(useNative: false);

        $blocks = [
            '',
            "[2026-10-06 18:11:29] local.INFO: first entry\n[2026-10-06 18:11:30] local.ERROR: second entry\n#0 /app/Test.php(10): run()\n#1 /app/Main.php(20): boot()\n",
            "orphan line 1\norphan line 2\n[2026-10-06T18:11:29.123456+02:00] app.jobs.WARNING: after orphan {\"k\":\"v\"}\r\ncontinuation with crlf\r\n[2026-10-06 18:11:31] app.VERBOSE: unknown level\n",
            "2026-10-05 21:53:21 Plain timestamp orphan\nmore orphan details\n[2026-10-06 18:11:32] prod.ALERT: final line without trailing newline",
            "  2026-10-05 21:53:21 App\\Jobs\\One ... DONE\n\n  2026-10-05 21:53:22 App\\Jobs\\Two ... FAIL\n",
            "[2026-10-06 18:11:29] local.INFO: entry with embedded\0nul byte\n#0 trace\n",
        ];

        foreach ($blocks as $block) {
            foreach ([true, false] as $structured) {
                foreach ([0, 128] as $baseOffset) {
                    foreach ([1, 50, null] as $startLine) {
                        foreach ([32768, 48] as $maxBytes) {
                            $expected = $this->serializeEntries(
                                $php->parseEntriesBlockPhp($block, $structured, $baseOffset, $maxBytes, $startLine)
                            );
                            $actual = $this->serializeEntries(
                                $native->parseEntriesBlock($block, $structured, $baseOffset, $maxBytes, $startLine)
                            );
                            self::assertSame($expected, $actual);
                        }
                    }
                }
            }
        }
    }

    #[Test]
    public function donor_match_selector_canonical_matches_php(): void
    {
        $native = new DonorMatchSelector(useNative: true);
        $php = new DonorMatchSelector(useNative: false);

        $cases = [
            [],
            [
                (object) [
                    'predb_id' => 51,
                    'releases_id' => 99,
                    'canonical_title' => 'Some.Scene.Release.2026.1080p.WEB.H264-GROUP',
                    'searchname' => 'some.scene.release.2026.1080p.web.h264-group',
                ],
            ],
            [
                (object) [
                    'predb_id' => 51,
                    'releases_id' => 250,
                    'canonical_title' => 'Some.Scene.Release.2026.1080p.WEB.H264-GROUP',
                    'searchname' => 'some.scene.release.2026.1080p.web.h264-group',
                ],
                (object) [
                    'predb_id' => 51,
                    'releases_id' => 120,
                    'canonical_title' => 'Some.Scene.Release.2026.1080p.WEB.H264-GROUP',
                    'searchname' => 'SOME.SCENE.RELEASE.2026.1080P.WEB.H264-GROUP',
                ],
            ],
            // Non-ASCII valid UTF-8 (Latin, Cyrillic, CJK, Emoji)
            [
                (object) [
                    'predb_id' => 77,
                    'releases_id' => 10,
                    'canonical_title' => 'Café.Münchner.Кино.東京.🎵-GRP',
                    'searchname' => 'Café.Münchner.Кино.東京.🎵-GRP',
                ],
            ],
            // Non-ASCII case-folded match falling back to PHP
            [
                (object) [
                    'predb_id' => 78,
                    'releases_id' => 15,
                    'canonical_title' => 'Café.Münchner.2026-GRP',
                    'searchname' => 'café.münchner.2026-grp',
                ],
            ],
            // Multi-donor batch where donor 0 requires PHP Unicode case-folding and donor 1 is an exact match
            [
                (object) [
                    'predb_id' => 78,
                    'releases_id' => 25,
                    'canonical_title' => 'Café.Münchner.2026-GRP',
                    'searchname' => 'café.münchner.2026-grp',
                ],
                (object) [
                    'predb_id' => 78,
                    'releases_id' => 12,
                    'canonical_title' => 'Café.Münchner.2026-GRP',
                    'searchname' => 'Café.Münchner.2026-GRP',
                ],
            ],
            // Rejections: control / format / private-use / invalid UTF-8 / mismatch / >255 chars
            [
                (object) [
                    'predb_id' => 0,
                    'releases_id' => 10,
                    'canonical_title' => 'Valid.Title-GRP',
                    'searchname' => 'Valid.Title-GRP',
                ],
            ],
            [
                (object) [
                    'predb_id' => 10,
                    'releases_id' => 10,
                    'canonical_title' => "Invalid\x07Control-GRP",
                    'searchname' => "Invalid\x07Control-GRP",
                ],
            ],
            [
                (object) [
                    'predb_id' => 10,
                    'releases_id' => 10,
                    'canonical_title' => "ZeroWidth\u{200B}Space-GRP",
                    'searchname' => "ZeroWidth\u{200B}Space-GRP",
                ],
            ],
            [
                (object) [
                    'predb_id' => 10,
                    'releases_id' => 10,
                    'canonical_title' => "PrivateUse\u{E000}-GRP",
                    'searchname' => "PrivateUse\u{E000}-GRP",
                ],
            ],
            [
                (object) [
                    'predb_id' => 10,
                    'releases_id' => 10,
                    'canonical_title' => "Bad\xFFUtf8",
                    'searchname' => "Bad\xFFUtf8",
                ],
            ],
            [
                (object) [
                    'predb_id' => 10,
                    'releases_id' => 10,
                    'canonical_title' => str_repeat('A', 256),
                    'searchname' => str_repeat('a', 256),
                ],
            ],
            // Conflicting predb_id across donors
            [
                (object) [
                    'predb_id' => 10,
                    'releases_id' => 1,
                    'canonical_title' => 'Same.Title-GRP',
                    'searchname' => 'Same.Title-GRP',
                ],
                (object) [
                    'predb_id' => 11,
                    'releases_id' => 2,
                    'canonical_title' => 'Same.Title-GRP',
                    'searchname' => 'Same.Title-GRP',
                ],
            ],
            // Conflicting canonical_title across donors
            [
                (object) [
                    'predb_id' => 10,
                    'releases_id' => 1,
                    'canonical_title' => 'First.Title-GRP',
                    'searchname' => 'First.Title-GRP',
                ],
                (object) [
                    'predb_id' => 10,
                    'releases_id' => 2,
                    'canonical_title' => 'Second.Title-GRP',
                    'searchname' => 'Second.Title-GRP',
                ],
            ],
        ];

        foreach ($cases as $idx => $donors) {
            $expected = $php->selectCanonicalPhp($donors);
            $actual = $native->selectCanonical($donors);
            self::assertEquals($expected, $actual, "Mismatch on canonical donor case #{$idx}");
        }

        // > 256 donors returns null
        $tooMany = array_fill(0, 257, (object) [
            'predb_id' => 1,
            'releases_id' => 1,
            'canonical_title' => 'Valid.Title-GRP',
            'searchname' => 'Valid.Title-GRP',
        ]);
        self::assertNull($php->selectCanonicalPhp($tooMany));
        self::assertNull($native->selectCanonical($tooMany));
    }

    #[Test]
    public function donor_match_selector_size_selection_matches_php(): void
    {
        $ffi = $this->requireFfi();
        $native = new DonorMatchSelector(useNative: true);
        $php = new DonorMatchSelector(useNative: false);

        mt_srand(20261024);
        for ($trial = 0; $trial < 100; $trial++) {
            $count = mt_rand(0, 40);
            $targetSize = mt_rand(0, 1) === 1 ? mt_rand(500, 10_000_000) : mt_rand(-100, 0);
            $tolerance = mt_rand(-2, 20);

            $donors = [];
            $nulParts = [];
            for ($i = 0; $i < $count; $i++) {
                $size = mt_rand(-50, 1) === 0 ? 0 : $targetSize + mt_rand(-500_000, 500_000);
                $id = mt_rand(1, 50);
                $useAltKeys = ($i % 3 === 0);
                $donors[] = $useAltKeys
                    ? (object) ['size' => $size, 'id' => $id, 'tag' => "d{$i}"]
                    : (object) ['relsize' => $size, 'releases_id' => $id, 'tag' => "d{$i}"];
                $nulParts[] = (string) $size;
                $nulParts[] = (string) $id;
            }

            $expected = $php->selectPhp($donors, $targetSize, $tolerance);
            $actual = $native->select($donors, $targetSize, $tolerance);
            self::assertSame($expected, $actual, "Mismatch on trial #{$trial}");

            if ($count > 0 && $targetSize > 0 && $tolerance >= 0) {
                $joined = implode("\0", $nulParts);
                // @phpstan-ignore method.notFound
                $nulIdx = $ffi->nntmux_donor_select_best_nul($joined, strlen($joined), $count, $targetSize, $tolerance);
                if ($expected === null) {
                    self::assertSame(-1, $nulIdx);
                } else {
                    self::assertSame($expected, $donors[$nulIdx]);
                }
            }
        }
    }

    #[Test]
    public function book_isbn_methods_and_batch_match_php(): void
    {
        $samples = [
            null,
            '',
            '0-13-235088-2',
            '0132350882',
            '978-0-13-235088-4',
            '9780132350884',
            '0-8044-2957-X',
            '0-8044-2957-x',
            '978-0-8044-2957-3',
            '99921-58-10-7',
            '85-359-0277-5',
            '1-84356-028-3',
            '0-684-84328-5',
            '0-85131-041-9',
            '99999-99-99-X',
            '99715-0210-0',
            '979-10-90636-07-1',
            '9791090636071',
            '9771234567003',
            'ISBN-13: 978-0-321-12521-7',
            'ISBN-10: 0-321-12521-5',
            '0132350883',
            '9780132350885',
            '0X32350882',
            "978\00132350884",
            'not-an-isbn',
            '12345678901234567890',
        ];

        // Generate additional valid/invalid ISBN-10s ending in 'X' with high prefixes ('999...')
        for ($prefix = 990; $prefix <= 999; $prefix++) {
            $body = sprintf('%03d000001', $prefix);
            $sum = 0;
            for ($i = 0; $i < 9; $i++) {
                $sum += ((int) $body[$i]) * (10 - $i);
            }
            $check = (11 - ($sum % 11)) % 11;
            $isbn10 = $body.($check === 10 ? 'X' : (string) $check);
            $samples[] = $isbn10;
            $isbn13 = BookIsbn::toIsbn13Php($isbn10);
            if ($isbn13 !== null) {
                $samples[] = $isbn13;
            }
        }

        foreach ($samples as $idx => $sample) {
            self::assertSame(
                BookIsbn::normalizePhp($sample),
                BookIsbn::normalize($sample),
                "normalize mismatch at #{$idx}"
            );
            self::assertSame(
                BookIsbn::toIsbn13Php($sample),
                BookIsbn::toIsbn13($sample),
                "toIsbn13 mismatch at #{$idx}"
            );
            self::assertSame(
                BookIsbn::toIsbn10Php($sample),
                BookIsbn::toIsbn10($sample),
                "toIsbn10 mismatch at #{$idx}"
            );
            self::assertSame(
                BookIsbn::equivalentsPhp($sample),
                BookIsbn::equivalents($sample),
                "equivalents mismatch at #{$idx}"
            );
        }

        foreach ($samples as $i => $left) {
            foreach ($samples as $j => $right) {
                self::assertSame(
                    BookIsbn::equivalentPhp($left, $right),
                    BookIsbn::equivalent($left, $right),
                    "equivalent mismatch for pair ({$i}, {$j})"
                );
            }
        }

        // Batch normalization parity (both without NULs and with embedded NUL fallback)
        $cleanSamples = array_values(array_filter(
            $samples,
            static fn (?string $s): bool => $s === null || ! str_contains($s, "\0")
        ));
        foreach ([0, 1, 2, 99] as $mode) {
            self::assertSame(
                BookIsbn::normalizeBatchPhp($cleanSamples, $mode),
                BookIsbn::normalizeBatch($cleanSamples, $mode),
                "normalizeBatch clean mismatch for mode {$mode}"
            );
            self::assertSame(
                BookIsbn::normalizeBatchPhp($samples, $mode),
                BookIsbn::normalizeBatch($samples, $mode),
                "normalizeBatch with NUL fallback mismatch for mode {$mode}"
            );
        }
    }
}

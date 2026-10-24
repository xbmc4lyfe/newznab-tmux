<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Api\ApiInputCanonicalizer;
use App\Services\Api\V1\ReleaseItemPayloadBuilder;
use App\Services\Search\DTO\ReleaseSearchQuery;
use App\Support\RustPipelineLibrary;
use FFI;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * Parity, boundary, and benchmark suite for Wave 8 Slice 8.6 Rust FFI exports in `rust/pipeline-ffi/src/search.rs`:
 * - `nntmux_api_canonicalize_list_csv` (`ApiInputCanonicalizer`)
 * - `nntmux_search_normalize_media_unique_id` (`ReleaseSearchQuery`)
 * - `nntmux_search_normalize_range_bound` (`ReleaseSearchQuery`)
 * - `nntmux_rfc2822_from_unix_utc` (`ReleaseItemPayloadBuilder`)
 * - `nntmux_release_item_rss_dates` (`ReleaseItemPayloadBuilder`)
 *
 * Paired randomized benchmark summary (macOS Darwin arm64, `cargo build --release`, N=40 interleaved pairs):
 * - `ApiInputCanonicalizer::canonicalizeListCsv` (common 6-cat CSV): PHP 521.3 ns mean / 667.0 ns p99 vs Rust 190.4 ns mean / 250.0 ns p99 -> 2.74x mean, 2.67x p99 (adopted in production for comma-separated strings).
 * - `ApiInputCanonicalizer::canonicalizeListCsv` (uncommon p99 32-cat CSV): PHP 1939.3 ns mean / 2417.0 ns p99 vs Rust 331.9 ns mean / 417.0 ns p99 -> 5.84x mean, 5.80x p99 (adopted in production).
 * - `ReleaseItemPayloadBuilder::rssDates` (common same-date): PHP 960.8 ns mean / 1209.0 ns p99 vs Rust 248.6 ns mean / 334.0 ns p99 -> 3.86x mean, 3.62x p99 (adopted in production).
 * - `ReleaseItemPayloadBuilder::rssDates` (uncommon diff-date): PHP 1057.4 ns mean / 2459.0 ns p99 vs Rust 334.8 ns mean / 708.0 ns p99 -> 3.16x mean, 3.47x p99 (adopted in production).
 * - `ReleaseItemPayloadBuilder::build` (extended release payload): PHP 1607.9 ns mean / 1958.0 ns p99 vs Rust 962.4 ns mean / 1209.0 ns p99 -> 1.67x mean, 1.62x p99 (adopted in production).
 * - `ReleaseItemPayloadBuilder::rfc2822FromUnixUtc`: PHP 261.2 ns mean / 459.0 ns p99 vs Rust 161.4 ns mean / 375.0 ns p99 -> 1.62x mean, 1.22x p99 (retained native PHP in production; `rfc2822FromUnixUtcNative` tested).
 * - `ReleaseSearchQuery::normalizeMediaUniqueId`: PHP 38.7 ns mean / 83.0 ns p99 vs Rust 115.7 ns mean / 167.0 ns p99 -> 0.33x mean, 0.50x p99 (retained native PHP in production; `normalizeMediaUniqueIdNative` tested).
 * - `ReleaseSearchQuery::normalizeRangeBound`: PHP 39.8 ns mean / 84.0 ns p99 vs Rust 105.1 ns mean / 125.0 ns p99 -> 0.38x mean, 0.67x p99 (retained native PHP in production; `normalizeRangeBoundNative` / `normalizeRangesNative` tested).
 */
final class RustSearchApiCanonicalizerParityTest extends TestCase
{
    private const string CDEF = <<<'C'
        ssize_t nntmux_api_canonicalize_list_csv(const char *input_ptr, size_t input_len, char *out_buf, size_t out_cap);
        ssize_t nntmux_search_normalize_media_unique_id(const char *input_ptr, size_t input_len, char *out_buf, size_t out_cap);
        int32_t nntmux_search_normalize_range_bound(const char *input_ptr, size_t input_len, int64_t *out_val);
        ssize_t nntmux_rfc2822_from_unix_utc(int64_t ts, char *out_buf, size_t out_cap);
        ssize_t nntmux_release_item_rss_dates(const char *adddate_ptr, size_t adddate_len, const char *postdate_ptr, size_t postdate_len, char *out_buf, size_t out_cap);
        C;

    private string $previousTz = 'UTC';

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousTz = date_default_timezone_get();
        date_default_timezone_set('UTC');
        ApiInputCanonicalizer::setUseRustFfi(true);
        ReleaseSearchQuery::setUseRustFfi(true);
        ReleaseItemPayloadBuilder::setUseRustFfi(true);
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->previousTz);
        ApiInputCanonicalizer::setUseRustFfi(true);
        ReleaseSearchQuery::setUseRustFfi(true);
        ReleaseItemPayloadBuilder::setUseRustFfi(true);
        parent::tearDown();
    }

    private function requireFfi(): FFI
    {
        $ffi = RustPipelineLibrary::resolveDomain('search_api_canonicalizer_test', self::CDEF);
        if ($ffi === null) {
            self::markTestSkipped('Rust pipeline FFI library is not available.');
        }

        return $ffi;
    }

    #[Test]
    public function c_abi_resize_and_error_contracts_work(): void
    {
        $ffi = $this->requireFfi();
        $tiny = $ffi->new('char[4]');
        $outVal = $ffi->new('int64_t');

        $csv = ' 2000 , 5030 , 5040 ';
        // @phpstan-ignore method.notFound
        $neededCsv = $ffi->nntmux_api_canonicalize_list_csv($csv, strlen($csv), $tiny, 4);
        self::assertSame(-14, $neededCsv);
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_api_canonicalize_list_csv(null, 5, $tiny, 4));
        // @phpstan-ignore method.notFound
        self::assertSame(0, $ffi->nntmux_api_canonicalize_list_csv('', 0, $tiny, 4));

        $uid = '  123456789012345  ';
        // @phpstan-ignore method.notFound
        $neededUid = $ffi->nntmux_search_normalize_media_unique_id($uid, strlen($uid), $tiny, 4);
        self::assertSame(-15, $neededUid);
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_search_normalize_media_unique_id(null, 5, $tiny, 4));

        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_search_normalize_range_bound('42', 2, null));
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_search_normalize_range_bound(null, 2, FFI::addr($outVal)));

        // @phpstan-ignore method.notFound
        self::assertSame(-31, $ffi->nntmux_rfc2822_from_unix_utc(1767323045, $tiny, 4));
        // @phpstan-ignore method.notFound
        self::assertSame(-2, $ffi->nntmux_rfc2822_from_unix_utc(253402300800, $tiny, 4));

        $dt = '2026-01-02 03:04:05';
        // @phpstan-ignore method.notFound
        self::assertSame(-63, $ffi->nntmux_release_item_rss_dates($dt, strlen($dt), '', 0, $tiny, 4));
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_release_item_rss_dates(null, 5, '', 0, $tiny, 4));
    }

    #[Test]
    public function api_input_canonicalizer_csv_and_parameters_match_php(): void
    {
        $this->requireFfi();
        $canonicalizer = new ApiInputCanonicalizer(useNative: true);

        $csvCases = [
            '',
            '   ',
            ',,,',
            " \t\n\r\0\x0B , \t\n\r\0\x0B ",
            "\x0c",
            " \x0c , 2000 ",
            '0',
            '0,0,0',
            '2000',
            ' 2000 ',
            '2000,5030',
            ' 2000, ,5030 ',
            ",2000,,5030,\t5040\n,\r2040\x0B,\02045,",
            'movies_hd, tv_uhd , anime',
            'MÖVIE, Фильм , 映画',
            implode(' , ', array_map(static fn (int $i): string => (string) (1000 + $i), range(1, 120))),
        ];

        foreach ($csvCases as $idx => $csv) {
            $expected = $canonicalizer->canonicalizeListCsvPhp($csv);
            $supported = false;
            $native = $canonicalizer->canonicalizeListCsvNative($csv, $supported);
            self::assertTrue($supported, "Expected supported=true at CSV case #{$idx}");
            self::assertSame($expected, $native, "canonicalizeListCsvNative mismatch at case #{$idx}");
            self::assertSame($expected, $canonicalizer->canonicalizeListCsv($csv), "canonicalizeListCsv mismatch at case #{$idx}");
            self::assertSame(
                $canonicalizer->canonicalizePhp(['cat' => $csv, 'id' => ' test ', 'extended' => true]),
                $canonicalizer->canonicalize(['cat' => $csv, 'id' => ' test ', 'extended' => true]),
                "canonicalize mismatch at case #{$idx}",
            );
        }

        // Array, bool, int, float, null, and object inputs for 'cat'
        foreach ([
            ['2000', ' 5030 ', '', '0'],
            [2000, 5030],
            [],
            true,
            false,
            2000,
            null,
            (object) ['a' => 1],
        ] as $idx => $catInput) {
            self::assertSame(
                $canonicalizer->canonicalizePhp(['cat' => $catInput, 'o' => ['b' => ' 2 ', 'a' => 1]]),
                $canonicalizer->canonicalize(['cat' => $catInput, 'o' => ['b' => ' 2 ', 'a' => 1]]),
                "Non-CSV cat input mismatch at #{$idx}",
            );
        }

        $random = new Randomizer(new Mt19937(20261024));
        $alphabet = "0123456789, \t\n\r\0\x0B\x0cab-_";
        for ($round = 0; $round < 300; $round++) {
            $len = $random->getInt(0, 90);
            $s = '';
            for ($i = 0; $i < $len; $i++) {
                $s .= $alphabet[$random->getInt(0, strlen($alphabet) - 1)];
            }
            $supported = false;
            self::assertSame(
                $canonicalizer->canonicalizeListCsvPhp($s),
                $canonicalizer->canonicalizeListCsvNative($s, $supported),
                "Fuzz CSV mismatch at round #{$round}",
            );
            self::assertTrue($supported);
        }

        ApiInputCanonicalizer::setUseRustFfi(false);
        $supported = true;
        self::assertNull($canonicalizer->canonicalizeListCsvNative('2000,5030', $supported));
        self::assertFalse($supported);
        self::assertSame(['cat' => '2000,5030'], $canonicalizer->canonicalize(['cat' => ' 2000, ,5030 ']));
    }

    #[Test]
    public function release_search_query_range_bounds_and_media_unique_id_match_php(): void
    {
        $this->requireFfi();

        $boundCases = [
            0,
            1,
            42,
            PHP_INT_MAX,
            -1,
            PHP_INT_MIN,
            null,
            true,
            false,
            12.5,
            [],
            (object) [],
            '',
            ' ',
            '0',
            '+0',
            '-0',
            ' 0 ',
            " \t\n\r\x0B0 \t\n\r\x0B",
            '1',
            '+1',
            '-1',
            '00',
            '+00',
            '-00',
            '01',
            '+01',
            '-01',
            '007',
            '42',
            '+42',
            " \t\n\r\x0B42 \t\n\r\x0B",
            "\x0c42",
            "42\x0c",
            "\042",
            "42\0",
            '9223372036854775807',
            '+9223372036854775807',
            '9223372036854775808',
            '18446744073709551615',
            '-9223372036854775808',
            '+',
            '-',
            '+ 1',
            '- 0',
            '1_000',
            '0x10',
            '1e5',
            '1.0',
        ];

        $random = new Randomizer(new Mt19937(20261025));
        $alphabet = "0123456789+- \t\n\r\x0B\x0C\0a";
        for ($i = 0; $i < 500; $i++) {
            $len = $random->getInt(1, 22);
            $s = '';
            for ($j = 0; $j < $len; $j++) {
                $s .= $alphabet[$random->getInt(0, strlen($alphabet) - 1)];
            }
            $boundCases[] = $s;
        }

        foreach ($boundCases as $idx => $case) {
            self::assertSame(
                ReleaseSearchQuery::normalizeRangeBound($case),
                ReleaseSearchQuery::normalizeRangeBoundNative($case),
                'normalizeRangeBound mismatch at case #'.$idx.': '.json_encode($case),
            );
        }

        $rangeMaps = [
            null,
            'not-an-array',
            [],
            [
                'size' => ['min' => '100', 'max' => 5000],
                'totalpart' => ['min' => '-1', 'max' => '+25'],
                'grabs' => ['min' => '00', 'max' => '-0'],
                'comments' => ['min' => " 10\n", 'max' => "20\0"],
                'adddate_ts' => ['min' => 1700000000, 'max' => '9223372036854775808'],
                'unknown_field' => ['min' => 1, 'max' => 2],
            ],
        ];

        foreach ($rangeMaps as $idx => $ranges) {
            self::assertSame(
                ReleaseSearchQuery::normalizeRanges($ranges),
                ReleaseSearchQuery::normalizeRangesNative($ranges),
                "normalizeRanges mismatch at #{$idx}",
            );
        }

        $uidCases = [
            null,
            [],
            (object) [],
            '',
            '   ',
            "\t\n\r\0\x0B",
            "\x0c",
            '-1',
            ' -1 ',
            "\t-1\r\n",
            '-10',
            '0',
            0,
            -1,
            42,
            3.14,
            true,
            false,
            '  123456789012345678901234567890  ',
            '  '.str_repeat('abcdef0123456789', 10).'  ',
            " \tMÖVIE-Unique-ID-映画\r\n ",
        ];

        foreach ($uidCases as $idx => $uid) {
            self::assertSame(
                ReleaseSearchQuery::normalizeMediaUniqueId($uid),
                ReleaseSearchQuery::normalizeMediaUniqueIdNative($uid),
                'normalizeMediaUniqueId mismatch at case #'.$idx,
            );
        }

        ReleaseSearchQuery::setUseRustFfi(false);
        self::assertNull(ReleaseSearchQuery::normalizeRangeBoundNative('42'));
        self::assertNull(ReleaseSearchQuery::normalizeMediaUniqueIdNative('abc123'));
    }

    #[Test]
    public function release_item_payload_builder_dates_and_build_match_php(): void
    {
        $this->requireFfi();

        $timestamps = [
            -62167219200, // 0000-01-01 00:00:00 UTC
            -30610224000, // 1000-01-01 00:00:00 UTC
            -2208988800,  // 1900-01-01 00:00:00 UTC
            -2203977600,  // 1900-02-28 00:00:00 UTC
            -1,
            0,
            1,
            951782400,    // 2000-02-29 00:00:00 UTC
            1709251199,   // 2024-02-29 23:59:59 UTC
            1767323045,   // 2026-01-02 03:04:05 UTC
            4107456000,   // 2100-02-28 00:00:00 UTC
            253402300799, // 9999-12-31 23:59:59 UTC
        ];

        $random = new Randomizer(new Mt19937(20261026));
        for ($i = 0; $i < 300; $i++) {
            $timestamps[] = $random->getInt(-2208988800, 4102444800);
        }

        foreach ($timestamps as $idx => $ts) {
            $expected = ReleaseItemPayloadBuilder::rfc2822FromUnixUtcPhp($ts);
            $native = ReleaseItemPayloadBuilder::rfc2822FromUnixUtcNative($ts);
            self::assertSame($expected, $native, "rfc2822FromUnixUtcNative mismatch at #{$idx} (ts={$ts})");
            self::assertSame($expected, ReleaseItemPayloadBuilder::rfc2822FromUnixUtc($ts));
        }

        // Out-of-range years (< 0000 or > 9999) decline native and fall back to PHP
        foreach ([-62167219201, 253402300800] as $outTs) {
            self::assertNull(ReleaseItemPayloadBuilder::rfc2822FromUnixUtcNative($outTs));
            self::assertSame(
                ReleaseItemPayloadBuilder::rfc2822FromUnixUtcPhp($outTs),
                ReleaseItemPayloadBuilder::rfc2822FromUnixUtc($outTs),
            );
        }

        $datePairs = [
            ['2026-01-02 03:04:05', '2026-01-02 03:04:05', true],
            ['2026-01-02 03:04:05', '2024-02-29 23:59:59', true],
            ['2026-01-02 03:04:05', '', true],
            ['2026-01-02 03:04:05', " \t\n\r\x0B ", true],
            ['2026-01-02T03:04:05Z', '1999-12-31', true],
            ['2000-02-29 12:34:56', '2026-10-24T04:19:24', true],
            ['2026-01-02 03:04:05', "\x0c", false],
            ['2026-01-02 03:04:05', 'not-a-valid-date', false],
            ["2026-01-02 03:04:05\0", '2026-01-02 03:04:05', false],
            ['not-a-valid-date', '2026-01-02 03:04:05', false],
            ['', '2026-01-02 03:04:05', false],
        ];

        foreach ($datePairs as $idx => [$add, $post, $expectNative]) {
            $expected = ReleaseItemPayloadBuilder::rssDatesPhp($add, $post);
            $native = ReleaseItemPayloadBuilder::rssDatesNative($add, $post);
            if ($expectNative) {
                self::assertSame($expected, $native, "rssDatesNative mismatch at pair #{$idx}");
            } else {
                self::assertNull($native, "Expected rssDatesNative decline at pair #{$idx}");
            }
            self::assertSame($expected, ReleaseItemPayloadBuilder::rssDates($add, $post), "rssDates mismatch at pair #{$idx}");
        }

        $server = ['server' => ['url' => 'https://indexer.invalid']];
        $releases = [
            [
                'guid' => 'guid-1',
                'searchname' => 'Movie.Name.2026.1080p.WEB-DL-GRP',
                'adddate' => '2026-10-17 14:22:31',
                'postdate' => '2026-10-16 09:15:00',
                'categories_id' => 2040,
                'category_name' => 'Movies > HD',
                'size' => 4294967296,
                'totalpart' => 45,
                'grabs' => 12,
                'comments' => 2,
                'passwordstatus' => 0,
                'group_name' => 'alt.binaries.movies',
                'coverurl' => '12345-cover.jpg',
                'imdbid' => '0133093',
                'anidbid' => 42,
                'predb_id' => 99,
                'nfostatus' => 1,
            ],
            (object) [
                'guid' => 'guid-tv',
                'searchname' => 'Show.Name.S01E02.1080p.WEB-DL-GRP',
                'adddate' => '2026-10-17 00:00:00',
                'postdate' => '',
                'categories_id' => 5040,
                'category_name' => 'TV > HD',
                'size' => 1073741824,
                'totalpart' => 18,
                'articles_expected' => 500,
                'articles_found' => 480,
                'files_expected' => 18,
                'files_found' => 17,
                'videos_id' => 10,
                'tv_episodes_id' => 20,
                'title' => 'Show Name',
                'series' => 1,
                'episode' => (object) ['episode' => 2],
                'firstaired' => '2026-10-15',
                'tvdb' => 101,
                'trakt' => 202,
                'tvmaze' => 303,
                'tmdb' => 404,
                'tvrage' => 505,
                'imdb' => '0944947',
                'grabs' => 5,
                'comments' => 0,
                'passwordstatus' => 0,
            ],
            [
                'guid' => 'guid-int-postdate',
                'searchname' => 'Int.Postdate.Release-GRP',
                'adddate' => '2026-10-17 00:00:00',
                'postdate' => 1760572800,
                'categories_id' => 2000,
                'category_name' => 'Movies',
                'size' => 500000,
                'totalpart' => 3,
                'grabs' => 1,
                'comments' => 0,
                'passwordstatus' => 0,
            ],
            [
                'guid' => 'guid-invalid-postdate',
                'searchname' => 'Fallback.Postdate.Release-GRP',
                'adddate' => '2026-10-17 00:00:00',
                'postdate' => 'invalid-postdate',
                'categories_id' => 2000,
                'category_name' => 'Movies',
                'size' => 500000,
                'totalpart' => 3,
                'grabs' => 1,
                'comments' => 0,
                'passwordstatus' => 0,
            ],
        ];

        foreach ([
            ['extended' => '1', 'token' => 'tok', 'del' => '1', 'dl' => '1'],
            ['extended' => '0', 'token' => 'tok', 'del' => '0', 'dl' => '0'],
        ] as $params) {
            $builder = new ReleaseItemPayloadBuilder($params, $server);
            foreach ($releases as $idx => $rel) {
                self::assertSame(
                    $builder->buildPhp($rel),
                    $builder->build($rel),
                    "ReleaseItemPayloadBuilder::build mismatch at release #{$idx}",
                );
            }
        }

        // Non-UTC timezone transparently falls back to PHP
        date_default_timezone_set('Europe/Amsterdam');
        self::assertNull(ReleaseItemPayloadBuilder::rfc2822FromUnixUtcNative(1767323045));
        self::assertNull(ReleaseItemPayloadBuilder::rssDatesNative('2026-01-02 03:04:05', '2026-01-02 03:04:05'));
        $builderNonUtc = new ReleaseItemPayloadBuilder(['extended' => '1', 'token' => 'tok'], $server);
        self::assertSame($builderNonUtc->buildPhp($releases[0]), $builderNonUtc->build($releases[0]));
    }

    /**
     * Opt-in paired randomized benchmark of the adopted Search/API canonicalizer and payload methods.
     * NNTMUX_SEARCH_API_CANONICALIZER_BENCHMARK=1 php -d opcache.enable_cli=1 artisan test --compact
     * tests/Unit/RustSearchApiCanonicalizerParityTest.php --filter=paired_benchmark
     */
    #[Test]
    public function paired_benchmark(): void
    {
        if (getenv('NNTMUX_SEARCH_API_CANONICALIZER_BENCHMARK') !== '1') {
            self::markTestSkipped('Opt in with NNTMUX_SEARCH_API_CANONICALIZER_BENCHMARK=1 to run 40 paired blocks per workload.');
        }
        $this->requireFfi();
        $random = new Randomizer(new Mt19937(20261024));

        $canonicalizer = new ApiInputCanonicalizer(useNative: true);
        $csvCommon = ' 2000, , 5030, 5040, 2040, 2045 ';
        $csvUncommon = implode(' , ', array_map(static fn (int $i): string => (string) (2000 + $i), range(1, 32)));

        $builder = new ReleaseItemPayloadBuilder(['extended' => '1', 'token' => 'tok'], ['server' => ['url' => 'https://indexer.invalid']]);
        $releaseCommon = [
            'guid' => 'abc123def456',
            'searchname' => 'Some.Movie.2026.1080p.WEB-DL.H264-GRP',
            'adddate' => '2026-10-17 14:22:31',
            'postdate' => '2026-10-17 14:22:31',
            'categories_id' => 2040,
            'category_name' => 'Movies > HD',
            'size' => 4294967296,
            'totalpart' => 45,
            'grabs' => 12,
            'comments' => 2,
            'passwordstatus' => 0,
            'group_name' => 'alt.binaries.movies',
        ];
        $releaseUncommon = $releaseCommon + [
            'postdate' => '2026-10-16 09:15:00',
            'coverurl' => '12345-cover.jpg',
            'articles_expected' => 934,
            'articles_found' => 236,
            'files_expected' => 4,
            'files_found' => 3,
            'nfostatus' => 1,
            'predb_id' => 77,
        ];

        $p99 = static function (array $values): float {
            sort($values);

            return $values[(int) ceil(count($values) * 0.99) - 1];
        };

        $benchmarks = [
            'canonicalizeListCsv[common]' => [
                static fn (): ?string => $canonicalizer->canonicalizeListCsvPhp($csvCommon),
                static fn (): ?string => $canonicalizer->canonicalizeListCsv($csvCommon),
            ],
            'canonicalizeListCsv[uncommon]' => [
                static fn (): ?string => $canonicalizer->canonicalizeListCsvPhp($csvUncommon),
                static fn (): ?string => $canonicalizer->canonicalizeListCsv($csvUncommon),
            ],
            'rssDates[common]' => [
                static fn (): ?array => ReleaseItemPayloadBuilder::rssDatesPhp('2026-10-17 14:22:31', '2026-10-17 14:22:31'),
                static fn (): ?array => ReleaseItemPayloadBuilder::rssDates('2026-10-17 14:22:31', '2026-10-17 14:22:31'),
            ],
            'rssDates[uncommon]' => [
                static fn (): ?array => ReleaseItemPayloadBuilder::rssDatesPhp('2026-10-17 14:22:31', '2026-10-16 09:15:00'),
                static fn (): ?array => ReleaseItemPayloadBuilder::rssDates('2026-10-17 14:22:31', '2026-10-16 09:15:00'),
            ],
            'build[common]' => [
                static fn (): array => $builder->buildPhp($releaseCommon),
                static fn (): array => $builder->build($releaseCommon),
            ],
        ];

        foreach ($benchmarks as $label => [$phpFn, $rustFn]) {
            self::assertSame($phpFn(), $rustFn(), "Parity check failed for {$label}");
            for ($w = 0; $w < 200; $w++) {
                $phpFn();
                $rustFn();
            }
            $samples = ['php' => [], 'rust' => []];
            for ($pair = 0; $pair < 40; $pair++) {
                $order = $random->getInt(0, 1) === 0 ? ['php', 'rust'] : ['rust', 'php'];
                foreach ($order as $variant) {
                    $fn = $variant === 'php' ? $phpFn : $rustFn;
                    for ($call = 0; $call < 50; $call++) {
                        $start = hrtime(true);
                        $fn();
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
            fwrite(STDERR, "{$label} ".json_encode($summary, JSON_THROW_ON_ERROR)."\n");
            self::assertGreaterThanOrEqual(1.5, $summary['mean_speedup'], "{$label} mean speedup");
            self::assertGreaterThanOrEqual(1.5, $summary['p99_speedup'], "{$label} p99 speedup");
        }
    }
}

<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Data\Api\DetailsData;
use App\Data\Api\ReleaseData;
use App\Models\User;
use App\Services\Api\V2\ApiV2Presenter;
use App\Services\Search\Drivers\ElasticSearchDriver;
use App\Services\Search\Drivers\ManticoreSearchDriver;
use App\Support\SecondaryIndexDocuments;
use Carbon\Carbon as BaseCarbon;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class RustApiV2RssDatesSearchFuzzyParityTest extends TestCase
{
    protected function tearDown(): void
    {
        ReleaseData::setUseRustFfi(null);
        DetailsData::setUseRustFfi(null);
        ApiV2Presenter::setUseRustFfi(null);
        SecondaryIndexDocuments::setUseRustFfi(null);
        ManticoreSearchDriver::setUseRustFfi(null);
        ElasticSearchDriver::setUseRustFfi(null);
        date_default_timezone_set('UTC');

        parent::tearDown();
    }

    public function test_ffi_handles_are_available_and_toggles_work(): void
    {
        $this->assertNotNull(ReleaseData::ffi(), 'ReleaseData FFI should be available');
        $this->assertNotNull(SecondaryIndexDocuments::ffi(), 'SecondaryIndexDocuments FFI should be available');
        $this->assertNotNull(ManticoreSearchDriver::ffi(), 'ManticoreSearchDriver FFI should be available');
        $this->assertNotNull(ElasticSearchDriver::ffi(), 'ElasticSearchDriver FFI should be available');

        foreach ([
            ReleaseData::class,
            DetailsData::class,
            ApiV2Presenter::class,
            SecondaryIndexDocuments::class,
        ] as $class) {
            $this->assertTrue($class::usesRustFfi(), "{$class} should default to using Rust FFI");
            $class::setUseRustFfi(false);
            $this->assertFalse($class::usesRustFfi(), "{$class} should report false when disabled");
            $class::setUseRustFfi(true);
            $this->assertTrue($class::usesRustFfi(), "{$class} should report true when enabled");
            $class::setUseRustFfi(null);
        }

        // Single-call short-string helpers (< 1.5x on common short inputs) default to pure PHP
        // in production while retaining *Native + setUseRustFfi(true) for parity testing.
        foreach ([
            ManticoreSearchDriver::class,
            ElasticSearchDriver::class,
        ] as $class) {
            $this->assertFalse($class::usesRustFfi(), "{$class} should default to pure PHP");
            $class::setUseRustFfi(true);
            $this->assertTrue($class::usesRustFfi(), "{$class} should report true when explicitly enabled");
            $class::setUseRustFfi(false);
            $this->assertFalse($class::usesRustFfi(), "{$class} should report false when disabled");
            $class::setUseRustFfi(null);
        }
    }

    public function test_c_abi_error_decline_and_resize_contracts(): void
    {
        $rssFfi = ReleaseData::ffi();
        $secFfi = SecondaryIndexDocuments::ffi();
        $manFfi = ManticoreSearchDriver::ffi();
        $esFfi = ElasticSearchDriver::ffi();
        $this->assertNotNull($rssFfi);
        $this->assertNotNull($secFfi);
        $this->assertNotNull($manFfi);
        $this->assertNotNull($esFfi);

        $smallBuf = $rssFfi->new('uint8_t[4]');
        $fullBuf = $rssFfi->new('uint8_t[256]');

        // 1. nntmux_api_v2_rss_dates_batch
        $d1 = '2026-03-14 01:02:03';
        $d2 = '2026-03-14 04:05:06';
        $d3 = '2026-03-13 23:59:59';

        // Invalid count (0 or 4) -> -1
        // @phpstan-ignore method.notFound
        $this->assertSame(-1, $rssFfi->nntmux_api_v2_rss_dates_batch($d1, strlen($d1), null, 0, null, 0, 0, 1, $fullBuf, 256));
        // @phpstan-ignore method.notFound
        $this->assertSame(-1, $rssFfi->nntmux_api_v2_rss_dates_batch($d1, strlen($d1), null, 0, null, 0, 4, 1, $fullBuf, 256));
        // Null pointer with non-zero length -> -1
        // @phpstan-ignore method.notFound
        $this->assertSame(-1, $rssFfi->nntmux_api_v2_rss_dates_batch(null, 5, null, 0, null, 0, 1, 1, $fullBuf, 256));

        // Decline (-2) when tz_is_utc == 0, empty date, or invalid calendar date
        // @phpstan-ignore method.notFound
        $this->assertSame(-2, $rssFfi->nntmux_api_v2_rss_dates_batch($d1, strlen($d1), null, 0, null, 0, 1, 0, $fullBuf, 256));
        // @phpstan-ignore method.notFound
        $this->assertSame(-2, $rssFfi->nntmux_api_v2_rss_dates_batch('', 0, null, 0, null, 0, 1, 1, $fullBuf, 256));
        // @phpstan-ignore method.notFound
        $this->assertSame(-2, $rssFfi->nntmux_api_v2_rss_dates_batch('2025-02-29 00:00:00', 19, null, 0, null, 0, 1, 1, $fullBuf, 256));

        // Resize contract (-required) when out_cap is too small
        // @phpstan-ignore method.notFound
        $this->assertSame(-31, $rssFfi->nntmux_api_v2_rss_dates_batch($d1, strlen($d1), null, 0, null, 0, 1, 1, $smallBuf, 4));
        // @phpstan-ignore method.notFound
        $this->assertSame(-63, $rssFfi->nntmux_api_v2_rss_dates_batch($d1, strlen($d1), $d2, strlen($d2), null, 0, 2, 1, $smallBuf, 4));
        // @phpstan-ignore method.notFound
        $this->assertSame(-95, $rssFfi->nntmux_api_v2_rss_dates_batch($d1, strlen($d1), $d2, strlen($d2), $d3, strlen($d3), 3, 1, $smallBuf, 4));

        // 2. nntmux_secondary_index_releasedate_to_ts
        // @phpstan-ignore method.notFound
        $this->assertSame(0, $secFfi->nntmux_secondary_index_releasedate_to_ts(null, 0, 1));
        // @phpstan-ignore method.notFound
        $this->assertSame(PHP_INT_MIN, $secFfi->nntmux_secondary_index_releasedate_to_ts(null, 4, 1));
        // @phpstan-ignore method.notFound
        $this->assertSame(PHP_INT_MIN, $secFfi->nntmux_secondary_index_releasedate_to_ts('2026-03-14', 10, 0));
        // @phpstan-ignore method.notFound
        $this->assertSame(PHP_INT_MIN, $secFfi->nntmux_secondary_index_releasedate_to_ts('   ', 3, 1));

        // 3. nntmux_manticore_phrases_to_fuzzy
        // @phpstan-ignore method.notFound
        $this->assertSame(-1, $manFfi->nntmux_manticore_phrases_to_fuzzy(null, 5, 1, $fullBuf, 256));
        // @phpstan-ignore method.notFound
        $this->assertSame(-1, $manFfi->nntmux_manticore_phrases_to_fuzzy('abc', 3, 2, $fullBuf, 256));
        $longPhrase = 'Alpha.Beta.Gamma.Delta.Epsilon';
        // @phpstan-ignore method.notFound
        $reqFuzzy = $manFfi->nntmux_manticore_phrases_to_fuzzy($longPhrase, strlen($longPhrase), 1, $smallBuf, 4);
        $this->assertSame(-30, $reqFuzzy);

        // 4. nntmux_elastic_escape_string
        // @phpstan-ignore method.notFound
        $this->assertSame(-1, $esFfi->nntmux_elastic_escape_string(null, 5, $fullBuf, 256));
        // @phpstan-ignore method.notFound
        $reqEsc = $esFfi->nntmux_elastic_escape_string($longPhrase, strlen($longPhrase), $smallBuf, 4);
        $this->assertSame(-30, $reqEsc);
    }

    public function test_release_data_and_details_data_parity_across_categories_and_timezones(): void
    {
        $user = new User;
        $user->api_token = 'secret-api-token';
        $detailsUrl = 'https://indexer.invalid/details/';
        $nzbUrl = 'https://indexer.invalid/getnzb';

        $rows = [
            // TV category with partial publication counts
            [
                'searchname' => 'Show.Name.S01E02.1080p.WEB-DL',
                'guid' => 'guid-tv-1',
                'categories_id' => 5040,
                'category_name' => 'TV > HD',
                'adddate' => '2026-03-14 01:02:03',
                'postdate' => '2026-03-13 22:15:00',
                'size' => 1987654321,
                'totalpart' => 42,
                'grabs' => 0,
                'comments' => 3,
                'passwordstatus' => 0,
                'imdb' => '0944947',
                'tmdb' => 1399,
                'trakt' => 1390,
                'title' => 'The Kingsroad',
                'series' => '1',
                'episode' => '2',
                'firstaired' => '2011-04-24',
                'tvdb' => 121361,
                'tvrage' => 24493,
                'tvmaze' => 82,
                'articles_found' => 400,
                'articles_expected' => 420,
                'files_found' => 41,
                'files_expected' => 42,
            ],
            // Movie category with identical adddate and postdate + fractional seconds
            [
                'searchname' => 'Movie.Title.2025.2160p.UHD.BluRay',
                'guid' => 'guid-movie-2',
                'categories_id' => 2045,
                'category_name' => 'Movies > UHD',
                'adddate' => '2025-12-31T23:59:59.123456Z',
                'postdate' => '2025-12-31T23:59:59.123456Z',
                'size' => 45000000000,
                'totalpart' => 120,
                'grabs' => 12,
                'comments' => 0,
                'passwordstatus' => 0,
                'imdbid' => '1122334',
                'tmdbid' => '0',
                'traktid' => 998877,
            ],
            // Other category with date-only strings and leap-year Feb 29
            [
                'searchname' => 'Linux.ISO.2024.x86_64',
                'guid' => 'guid-other-3',
                'categories_id' => 4010,
                'category_name' => 'PC > 0day',
                'adddate' => '2024-02-29',
                'postdate' => '2024-02-28 12:34:56+00:00',
                'size' => 4096000,
                'totalpart' => 5,
                'grabs' => '0',
                'comments' => '',
                'passwordstatus' => 1,
            ],
            // Non-standard / rollover date strings that decline to Carbon::parse
            [
                'searchname' => 'Rollover.Date.Release',
                'guid' => 'guid-rollover-4',
                'categories_id' => 7020,
                'category_name' => 'Books > EBook',
                'adddate' => '2025-02-29 08:00:00',
                'postdate' => '2025-04-31 19:30:00',
                'size' => 12345,
                'totalpart' => 1,
                'grabs' => 1,
                'comments' => 1,
                'passwordstatus' => 0,
            ],
        ];

        foreach (['UTC', 'America/New_York', 'Europe/Berlin'] as $tz) {
            date_default_timezone_set($tz);

            foreach ($rows as $arrRow) {
                $objRow = (object) $arrRow;

                $relNativeObj = ReleaseData::toArrayFromReleaseNative($objRow, $user, $detailsUrl, $nzbUrl);
                $relPhpObj = ReleaseData::toArrayFromReleasePhp($objRow, $user, $detailsUrl, $nzbUrl);
                $this->assertSame($relPhpObj, $relNativeObj, "ReleaseData object parity failed in {$tz}");

                $relNativeArr = ReleaseData::toArrayFromReleaseNative($arrRow, $user, $detailsUrl, $nzbUrl);
                $relPhpArr = ReleaseData::toArrayFromReleasePhp($arrRow, $user, $detailsUrl, $nzbUrl);
                $this->assertSame($relPhpArr, $relNativeArr, "ReleaseData array parity failed in {$tz}");

                $this->assertSame(
                    ReleaseData::fromRelease($objRow, $user)->toArray(),
                    ReleaseData::fromArray($arrRow, $user)->toArray()
                );

                $detNativeObj = DetailsData::toArrayFromReleaseNative($objRow, $user, $detailsUrl, $nzbUrl);
                $detPhpObj = DetailsData::toArrayFromReleasePhp($objRow, $user, $detailsUrl, $nzbUrl);
                $this->assertSame($detPhpObj, $detNativeObj, "DetailsData object parity failed in {$tz}");

                $detNativeArr = DetailsData::toArrayFromReleaseNative($arrRow, $user, $detailsUrl, $nzbUrl);
                $detPhpArr = DetailsData::toArrayFromReleasePhp($arrRow, $user, $detailsUrl, $nzbUrl);
                $this->assertSame($detPhpArr, $detNativeArr, "DetailsData array parity failed in {$tz}");

                $this->assertSame(
                    DetailsData::fromRelease($objRow, $user)->toArray(),
                    DetailsData::fromArray($arrRow, $user)->toArray()
                );
            }
        }
    }

    public function test_api_v2_presenter_rss_dates_and_usage_parity(): void
    {
        $user = new User;
        $user->api_token = 'tok';
        $user->setRelation('role', (object) [
            'apirequests' => 5000,
            'downloadrequests' => 1000,
        ]);

        $dateTriples = [
            ['2026-03-14 01:02:03', '2026-03-14 04:05:06', '2026-03-13 23:59:59'],
            ['2026-01-01 00:00:00', '2026-01-01 00:00:00', '2026-01-01 00:00:00'],
            ['2024-02-29 12:00:00', '2024-02-29', '2024-03-01T00:00:00Z'],
            ['2025-02-29 10:00:00', '2026-05-10 11:22:33', '2026-05-10 11:22:33'],
        ];

        $statsCases = [
            (object) ['api_count' => 10, 'grab_count' => 2, 'api_time' => '2026-03-14 01:02:03', 'grab_time' => '2026-03-14 02:03:04'],
            (object) ['api_count' => 5, 'grab_count' => 0, 'api_time' => '2026-03-14 01:02:03', 'grab_time' => null],
            (object) ['api_count' => 0, 'grab_count' => 1, 'api_time' => '', 'grab_time' => '2026-03-14 02:03:04'],
            (object) ['api_count' => 0, 'grab_count' => 0, 'api_time' => null, 'grab_time' => null],
        ];

        foreach (['UTC', 'America/New_York'] as $tz) {
            date_default_timezone_set($tz);

            foreach ($dateTriples as [$d1, $d2, $d3]) {
                $this->assertSame(
                    ApiV2Presenter::formatRssDatesPhp($d1),
                    ApiV2Presenter::formatRssDatesNative($d1),
                    "1-date parity failed in {$tz}"
                );
                $this->assertSame(
                    ApiV2Presenter::formatRssDatesPhp($d1, $d2),
                    ApiV2Presenter::formatRssDatesNative($d1, $d2),
                    "2-date parity failed in {$tz}"
                );
                $this->assertSame(
                    ApiV2Presenter::formatRssDatesPhp($d1, $d2, $d3),
                    ApiV2Presenter::formatRssDatesNative($d1, $d2, $d3),
                    "3-date parity failed in {$tz}"
                );
            }

            foreach ($statsCases as $stats) {
                $this->assertSame(
                    ApiV2Presenter::usagePhp($user, $stats),
                    ApiV2Presenter::usageNative($user, $stats),
                    "ApiV2Presenter::usage parity failed in {$tz}"
                );
            }
        }
    }

    public function test_secondary_index_documents_parity(): void
    {
        $inputs = [
            null,
            '',
            '0',
            '   ',
            '2023-11-14',
            '2023-11-14 22:13:20',
            '2024-02-29 00:00:00',
            '2024-02-29T15:30:45.123456Z',
            '2025-02-29',
            'invalid-date-string',
            "2023-11-14\0extra",
            BaseCarbon::parse('2025-06-15 10:20:30'),
            Carbon::parse('2025-06-15 10:20:30'),
        ];

        foreach (['UTC', 'Europe/Berlin'] as $tz) {
            date_default_timezone_set($tz);

            foreach ($inputs as $idx => $rd) {
                $this->assertSame(
                    SecondaryIndexDocuments::releasedateToTimestampPhp($rd),
                    SecondaryIndexDocuments::releasedateToTimestampNative($rd),
                    "releasedateToTimestamp parity failed at index {$idx} in {$tz}"
                );

                $row = [
                    'title' => 'Cyberpunk 2077',
                    'platform' => 'PC',
                    'genres_id' => '7',
                    'releasedate' => $rd,
                    'cover' => '1',
                ];

                $this->assertSame(
                    SecondaryIndexDocuments::gamesFromArrayPhp($row),
                    SecondaryIndexDocuments::gamesFromArrayNative($row),
                    "gamesFromArray parity failed at index {$idx} in {$tz}"
                );
                $this->assertSame(
                    SecondaryIndexDocuments::consoleFromArrayPhp($row),
                    SecondaryIndexDocuments::consoleFromArrayNative($row),
                    "consoleFromArray parity failed at index {$idx} in {$tz}"
                );
            }
        }
    }

    public function test_manticore_phrases_to_fuzzy_string_parity(): void
    {
        $cases = [
            '',
            '0',
            '   ',
            '...0...',
            'Ubuntu.24.04.LTS-x86_64!',
            "\x0CFormFeedWord\x0C",
            "\x0BVerticalTabWord\x0B",
            "Null\x00Byte\x00In\x00String",
            'UTF8-Café-München-日本語',
            ['searchname' => 'Show.Name.S01E02', 'name' => '1080p.WEB-DL', 'fromname' => ''],
            ['searchname' => '0', 'name' => '...0...', 'filename' => '  Hello---World  '],
            ['searchname' => "With\x00Null", 'name' => "\x0CFF\x0C"],
            ['Indexed.One', '0', 'Indexed--Two', '   '],
            [],
        ];

        foreach ($cases as $idx => $phrases) {
            $this->assertSame(
                ManticoreSearchDriver::phrasesToFuzzyStringPhp($phrases),
                ManticoreSearchDriver::phrasesToFuzzyStringNative($phrases),
                "phrasesToFuzzyString parity failed at case #{$idx}"
            );
        }
    }

    public function test_elastic_escape_string_parity(): void
    {
        $cases = [
            '',
            '0',
            '*',
            '...',
            " . \t\n\r\x00\x0B .. ",
            "\x0CLeadingAndTrailingFF\x0C",
            "Internal\x00Null.And..Dots",
            'Show.Name.S01E02.1080p.WEB-DL.DDP5.1.H.264-GROUP',
            "Multi\t\tLine\r\nRelease...Name\x0B\x0C2026",
            'UTF8.Café.München.日本語.1080p',
        ];

        foreach ($cases as $idx => $input) {
            $this->assertSame(
                ElasticSearchDriver::escapeStringPhp($input),
                ElasticSearchDriver::escapeStringNative($input),
                "ElasticSearchDriver::escapeString parity failed at case #{$idx}"
            );
        }
    }

    public function test_randomized_fuzz_parity_across_all_helpers(): void
    {
        mt_srand(20261024);
        $alphabet = "abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789._-!@#$%^&*() \t\n\r\x0B\x0C";
        $alphaLen = strlen($alphabet);

        for ($i = 0; $i < 300; $i++) {
            $year = mt_rand(1975, 2035);
            $month = mt_rand(1, 12);
            $day = mt_rand(1, 28);
            $h = mt_rand(0, 23);
            $m = mt_rand(0, 59);
            $s = mt_rand(0, 59);

            $d1 = sprintf('%04d-%02d-%02d %02d:%02d:%02d', $year, $month, $day, $h, $m, $s);
            $d2 = sprintf('%04d-%02d-%02d %02d:%02d:%02d', $year, $month, max(1, $day - 1), $h, $m, $s);
            $d3 = sprintf('%04d-%02d-%02d', $year, $month, $day);

            $this->assertSame(
                ReleaseData::formatRssDatesPhp($d1, $d2),
                ReleaseData::formatRssDatesNative($d1, $d2)
            );
            $this->assertSame(
                ApiV2Presenter::formatRssDatesPhp($d1, $d2, $d3),
                ApiV2Presenter::formatRssDatesNative($d1, $d2, $d3)
            );
            $this->assertSame(
                SecondaryIndexDocuments::releasedateToTimestampPhp($d1),
                SecondaryIndexDocuments::releasedateToTimestampNative($d1)
            );

            $len = mt_rand(0, 80);
            $randStr = '';
            for ($j = 0; $j < $len; $j++) {
                $randStr .= $alphabet[mt_rand(0, $alphaLen - 1)];
            }

            $this->assertSame(
                ManticoreSearchDriver::phrasesToFuzzyStringPhp($randStr),
                ManticoreSearchDriver::phrasesToFuzzyStringNative($randStr)
            );
            $this->assertSame(
                ElasticSearchDriver::escapeStringPhp($randStr),
                ElasticSearchDriver::escapeStringNative($randStr)
            );

            $assoc = [
                'searchname' => $randStr,
                'name' => substr($randStr, 0, 25),
                'filename' => $i % 3 === 0 ? '0' : 'Release.File_Name-2026',
            ];
            $this->assertSame(
                ManticoreSearchDriver::phrasesToFuzzyStringPhp($assoc),
                ManticoreSearchDriver::phrasesToFuzzyStringNative($assoc)
            );
        }
    }

    public function test_rust_vs_php_microbenchmark(): void
    {
        if (getenv('RUN_RUST_FFI_BENCHMARKS') !== '1') {
            $this->markTestSkipped('Set RUN_RUST_FFI_BENCHMARKS=1 to run paired Rust vs PHP benchmarks.');
        }

        $user = new User;
        $user->api_token = 'bench-token-123456';
        $user->setRelation('role', (object) [
            'apirequests' => 5000,
            'downloadrequests' => 1000,
        ]);
        $detailsUrl = 'https://indexer.invalid/details/';
        $nzbUrl = 'https://indexer.invalid/getnzb';

        $commonRelease = (object) [
            'searchname' => 'Show.Name.S03E07.1080p.WEB.H264-GROUP',
            'guid' => 'a1b2c3d4e5f60718293a4b5c6d7e8f90',
            'categories_id' => 5040,
            'category_name' => 'TV > HD',
            'adddate' => '2026-03-14 01:02:03',
            'postdate' => '2026-03-13 21:45:10',
            'size' => 2147483648,
            'totalpart' => 55,
            'grabs' => 4,
            'comments' => 0,
            'passwordstatus' => 0,
            'imdb' => '1234567',
            'tmdb' => 4567,
            'trakt' => 8901,
            'title' => 'Episode Seven',
            'series' => '3',
            'episode' => '7',
            'firstaired' => '2026-03-13',
            'tvdb' => 55667,
            'tvrage' => 0,
            'tvmaze' => 7788,
        ];

        $uncommonRelease = (object) [
            'searchname' => 'Long.Movie.Release.2026.2160p.UHD.BluRay.REMUX.HDR10Plus.TrueHD.7.1.Atmos-GROUP',
            'guid' => 'f0e1d2c3b4a5968778695a4b3c2d1e0f',
            'categories_id' => 2045,
            'category_name' => 'Movies > UHD',
            'adddate' => '2026-03-14T08:15:22.654321+00:00',
            'postdate' => '2026-03-12T19:04:55.987654Z',
            'size' => 68719476736,
            'totalpart' => 240,
            'grabs' => 25,
            'comments' => 3,
            'passwordstatus' => 0,
            'imdbid' => '7654321',
            'tmdbid' => 98765,
            'traktid' => 54321,
            'articles_found' => 238,
            'articles_expected' => 240,
            'files_found' => 88,
            'files_expected' => 90,
        ];

        $commonStats = (object) [
            'api_count' => 42,
            'grab_count' => 7,
            'api_time' => '2026-03-14 01:02:03',
            'grab_time' => '2026-03-14 02:03:04',
        ];
        $uncommonStats = (object) [
            'api_count' => 4999,
            'grab_count' => 999,
            'api_time' => '2026-03-14T01:02:03.123456+00:00',
            'grab_time' => '2026-03-13T23:59:59.654321Z',
        ];

        $commonGameRow = [
            'title' => 'Baldur Gate 3',
            'platform' => 'PC',
            'genres_id' => 12,
            'releasedate' => '2023-08-03',
            'cover' => 1,
        ];
        $uncommonGameRow = [
            'title' => 'The Legend of Heroes: Trails through Daybreak II Deluxe Edition',
            'platform' => 'PlayStation 5',
            'genres_id' => 15,
            'releasedate' => '2025-02-14T00:00:00.000000+00:00',
            'cover' => 1,
        ];

        $commonFuzzy = ['searchname' => 'Show.Name.S01E02.1080p.WEB-DL'];
        $uncommonFuzzy = [
            'searchname' => 'Some.Very.Long.Release.Name.With-Special!@#Characters.S04E12.2160p.WEB-DL.DDP5.1.H.265',
            'name' => 'Some.Very.Long.Usenet.Subject.Header.[01/92] - "some.very.long.file.part01.rar" yEnc (1/150)',
            'filename' => 'some.very.long.file.name.with.dots.and-dashes.2026.mkv',
        ];

        $commonEscape = 'Show.Name.S01E02.1080p.WEB-DL.H264-GROUP';
        $uncommonEscape = str_repeat('Long.Release.Segment..With...Dots.And   Spaces.S01E02.2160p.', 6);

        $benchmarks = [
            'ReleaseData::formatRssDates (common)' => [
                fn () => ReleaseData::formatRssDatesNative('2026-03-14 01:02:03', '2026-03-13 21:45:10'),
                fn () => ReleaseData::formatRssDatesPhp('2026-03-14 01:02:03', '2026-03-13 21:45:10'),
            ],
            'ReleaseData::formatRssDates (uncommon)' => [
                fn () => ReleaseData::formatRssDatesNative('2026-03-14T08:15:22.654321+00:00', '2026-03-12T19:04:55.987654Z'),
                fn () => ReleaseData::formatRssDatesPhp('2026-03-14T08:15:22.654321+00:00', '2026-03-12T19:04:55.987654Z'),
            ],
            'ReleaseData::toArrayFromRelease (common)' => [
                fn () => ReleaseData::toArrayFromReleaseNative($commonRelease, $user, $detailsUrl, $nzbUrl),
                fn () => ReleaseData::toArrayFromReleasePhp($commonRelease, $user, $detailsUrl, $nzbUrl),
            ],
            'ReleaseData::toArrayFromRelease (uncommon)' => [
                fn () => ReleaseData::toArrayFromReleaseNative($uncommonRelease, $user, $detailsUrl, $nzbUrl),
                fn () => ReleaseData::toArrayFromReleasePhp($uncommonRelease, $user, $detailsUrl, $nzbUrl),
            ],
            'DetailsData::toArrayFromRelease (common)' => [
                fn () => DetailsData::toArrayFromReleaseNative($commonRelease, $user, $detailsUrl, $nzbUrl),
                fn () => DetailsData::toArrayFromReleasePhp($commonRelease, $user, $detailsUrl, $nzbUrl),
            ],
            'DetailsData::toArrayFromRelease (uncommon)' => [
                fn () => DetailsData::toArrayFromReleaseNative($uncommonRelease, $user, $detailsUrl, $nzbUrl),
                fn () => DetailsData::toArrayFromReleasePhp($uncommonRelease, $user, $detailsUrl, $nzbUrl),
            ],
            'ApiV2Presenter::formatRssDates 3x (common)' => [
                fn () => ApiV2Presenter::formatRssDatesNative('2026-03-14 01:02:03', '2026-03-13 21:45:10', '2026-03-14 01:02:03'),
                fn () => ApiV2Presenter::formatRssDatesPhp('2026-03-14 01:02:03', '2026-03-13 21:45:10', '2026-03-14 01:02:03'),
            ],
            'ApiV2Presenter::formatRssDates 3x (uncommon)' => [
                fn () => ApiV2Presenter::formatRssDatesNative('2026-03-14T08:15:22.654321+00:00', '2026-03-12T19:04:55.987654Z', '2024-02-29T23:59:59.999999+00:00'),
                fn () => ApiV2Presenter::formatRssDatesPhp('2026-03-14T08:15:22.654321+00:00', '2026-03-12T19:04:55.987654Z', '2024-02-29T23:59:59.999999+00:00'),
            ],
            'ApiV2Presenter::usage (common)' => [
                fn () => ApiV2Presenter::usageNative($user, $commonStats),
                fn () => ApiV2Presenter::usagePhp($user, $commonStats),
            ],
            'ApiV2Presenter::usage (uncommon)' => [
                fn () => ApiV2Presenter::usageNative($user, $uncommonStats),
                fn () => ApiV2Presenter::usagePhp($user, $uncommonStats),
            ],
            'SecondaryIndexDocuments::releasedateToTimestamp (common)' => [
                fn () => SecondaryIndexDocuments::releasedateToTimestampNative('2023-08-03'),
                fn () => SecondaryIndexDocuments::releasedateToTimestampPhp('2023-08-03'),
            ],
            'SecondaryIndexDocuments::releasedateToTimestamp (uncommon)' => [
                fn () => SecondaryIndexDocuments::releasedateToTimestampNative('2025-02-14T00:00:00.000000+00:00'),
                fn () => SecondaryIndexDocuments::releasedateToTimestampPhp('2025-02-14T00:00:00.000000+00:00'),
            ],
            'SecondaryIndexDocuments::gamesFromArray (common)' => [
                fn () => SecondaryIndexDocuments::gamesFromArrayNative($commonGameRow),
                fn () => SecondaryIndexDocuments::gamesFromArrayPhp($commonGameRow),
            ],
            'SecondaryIndexDocuments::gamesFromArray (uncommon)' => [
                fn () => SecondaryIndexDocuments::gamesFromArrayNative($uncommonGameRow),
                fn () => SecondaryIndexDocuments::gamesFromArrayPhp($uncommonGameRow),
            ],
            'SecondaryIndexDocuments::consoleFromArray (common)' => [
                fn () => SecondaryIndexDocuments::consoleFromArrayNative($commonGameRow),
                fn () => SecondaryIndexDocuments::consoleFromArrayPhp($commonGameRow),
            ],
            'SecondaryIndexDocuments::consoleFromArray (uncommon)' => [
                fn () => SecondaryIndexDocuments::consoleFromArrayNative($uncommonGameRow),
                fn () => SecondaryIndexDocuments::consoleFromArrayPhp($uncommonGameRow),
            ],
            'ManticoreSearchDriver::phrasesToFuzzyString (common)' => [
                fn () => ManticoreSearchDriver::phrasesToFuzzyStringNative($commonFuzzy),
                fn () => ManticoreSearchDriver::phrasesToFuzzyStringPhp($commonFuzzy),
            ],
            'ManticoreSearchDriver::phrasesToFuzzyString (uncommon)' => [
                fn () => ManticoreSearchDriver::phrasesToFuzzyStringNative($uncommonFuzzy),
                fn () => ManticoreSearchDriver::phrasesToFuzzyStringPhp($uncommonFuzzy),
            ],
            'ElasticSearchDriver::escapeString (common)' => [
                fn () => ElasticSearchDriver::escapeStringNative($commonEscape),
                fn () => ElasticSearchDriver::escapeStringPhp($commonEscape),
            ],
            'ElasticSearchDriver::escapeString (uncommon)' => [
                fn () => ElasticSearchDriver::escapeStringNative($uncommonEscape),
                fn () => ElasticSearchDriver::escapeStringPhp($uncommonEscape),
            ],
        ];

        $iterations = 1500;
        $warmup = 150;
        $report = "\n| Operation | Rust Mean (ns) | PHP Mean (ns) | Mean Speedup | Rust p99 (ns) | PHP p99 (ns) | p99 Speedup |\n";
        $report .= "|---|---:|---:|---:|---:|---:|---:|\n";

        foreach ($benchmarks as $label => [$rustFn, $phpFn]) {
            for ($w = 0; $w < $warmup; $w++) {
                $rustFn();
                $phpFn();
            }

            $rustTimes = [];
            $phpTimes = [];
            for ($i = 0; $i < $iterations; $i++) {
                if (($i & 1) === 0) {
                    $t0 = hrtime(true);
                    $rustFn();
                    $rustTimes[] = hrtime(true) - $t0;

                    $t1 = hrtime(true);
                    $phpFn();
                    $phpTimes[] = hrtime(true) - $t1;
                } else {
                    $t1 = hrtime(true);
                    $phpFn();
                    $phpTimes[] = hrtime(true) - $t1;

                    $t0 = hrtime(true);
                    $rustFn();
                    $rustTimes[] = hrtime(true) - $t0;
                }
            }

            sort($rustTimes);
            sort($phpTimes);
            $rMean = array_sum($rustTimes) / $iterations;
            $pMean = array_sum($phpTimes) / $iterations;
            $p99Idx = (int) floor($iterations * 0.99);
            $rP99 = $rustTimes[$p99Idx];
            $pP99 = $phpTimes[$p99Idx];
            $meanSpeedup = $pMean / max(1.0, $rMean);
            $p99Speedup = $pP99 / max(1.0, $rP99);

            $report .= sprintf(
                "| %s | %.0f | %.0f | %.2fx | %d | %d | %.2fx |\n",
                $label,
                $rMean,
                $pMean,
                $meanSpeedup,
                $rP99,
                $pP99,
                $p99Speedup
            );
        }

        fwrite(STDERR, $report);
        $this->assertNotSame('', $report);
    }
}

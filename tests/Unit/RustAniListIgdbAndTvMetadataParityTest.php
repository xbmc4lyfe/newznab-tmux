<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\IGDB\Client;
use App\Services\IGDB\Models\Game;
use App\Services\IGDB\QueryBuilder;
use App\Services\PopulateAniListService;
use App\Services\TvProcessing\ParsedTvRelease;
use App\Support\MetadataSearchLookup;
use FFI;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Stringable;

/**
 * Reproduce the paired single-call experiment with benchmark('/tmp/wave12_bench.json').
 * Baseline: 620892c2f97164aff021a46cbcf383bdbdf7da7c. Production adoption
 * requires >=1.5x mean AND p99 on common AND uncommon inputs for each method.
 * PHP 8.5.12, rustc 1.99.1 release, Darwin arm64 Apple M5, 10 CPUs/32 GiB;
 * OPcache CLI off; C/C.UTF-8; host process without imposed limits. 1,024
 * warmups, 20,480 randomized paired single calls per method/workload:
 *
 * Method (PHP latency / native latency)             common mean/p99  uncommon mean/p99
 * PopulateAniListService::normalizeAniListMedia        0.56 / 0.56      0.42 / 0.47
 * QueryBuilder::toQuery                                0.85 / 0.79      0.54 / 0.55
 * QueryBuilder::whereIn                                0.77 / 0.71      1.46 / 1.64
 * QueryBuilder::formatValue                            0.40 / 0.50      0.45 / 0.46
 * ParsedTvRelease::fromParseInfo                       0.34 / 0.35      0.29 / 0.29
 * ParsedTvRelease::titleWithoutYear                    0.33 / 0.40      0.41 / 0.45
 * ParsedTvRelease::countryCode                         0.17 / 0.25      0.16 / 0.14
 * MetadataSearchLookup::normalizeBooleanSearchWords    0.60 / 0.67      1.30 / 1.28
 *
 * None of the eight single-call helpers reach >=1.5x mean AND p99 on both
 * common and uncommon workloads because PHP already holds the decoded arrays
 * or short strings in memory and C-backed built-ins (addcslashes, str_replace,
 * preg_replace, strlen) complete below or near the ~215-300 ns FFI call floor.
 * All four classes therefore retain PHP by default ($useRustFfi = null -> false).
 */
class RustAniListIgdbAndTvMetadataParityTest extends TestCase
{
    protected function tearDown(): void
    {
        ParsedTvRelease::$useRustFfi = null;
        MetadataSearchLookup::$useRustFfi = null;
        $this->resetFfiCaches();
        parent::tearDown();
    }

    private function resetFfiCaches(): void
    {
        foreach ([
            [PopulateAniListService::class, 'normalizerFfi'],
            [QueryBuilder::class, 'queryFfi'],
            [ParsedTvRelease::class, 'tvFfi'],
            [MetadataSearchLookup::class, 'lookupFfi'],
        ] as [$class, $prop]) {
            $reflection = new ReflectionProperty($class, $prop);
            $reflection->setValue(null, null);
        }
    }

    private function newAniListService(): PopulateAniListService
    {
        $ref = new \ReflectionClass(PopulateAniListService::class);
        /** @var PopulateAniListService $service */
        $service = $ref->newInstanceWithoutConstructor();
        $service->echooutput = false;
        $service->imgSavePath = '/tmp/';

        return $service;
    }

    /**
     * @return QueryBuilder<Game>
     */
    private function newQueryBuilder(): QueryBuilder
    {
        $clientRef = new \ReflectionClass(Client::class);
        /** @var Client $client */
        $client = $clientRef->newInstanceWithoutConstructor();

        return new QueryBuilder(Game::class, $client);
    }

    public function test_rust_ffi_handles_are_available(): void
    {
        $this->assertInstanceOf(FFI::class, PopulateAniListService::normalizerFfi());
        $this->assertInstanceOf(FFI::class, QueryBuilder::queryFfi());
        $this->assertInstanceOf(FFI::class, ParsedTvRelease::tvFfi());
        $this->assertInstanceOf(FFI::class, MetadataSearchLookup::lookupFfi());
    }

    public function test_anilist_normalize_media_parity_across_payloads(): void
    {
        $service = $this->newAniListService();

        foreach ($this->aniListPayloads() as $name => $payload) {
            $php = $service->normalizeAniListMediaPhp($payload);
            $native = $service->normalizeAniListMediaNative($payload);
            $this->assertSame($php, $native, "AniList parity failed on fixture: {$name}");

            foreach ([false, true, null] as $flag) {
                $service->useRustFfi = $flag;
                $this->assertSame($php, $service->normalizeAniListMedia($payload));
            }
        }
    }

    public function test_igdb_format_value_parity(): void
    {
        $builder = $this->newQueryBuilder();
        $stringable = new class implements Stringable
        {
            public function __toString(): string
            {
                return 'Stringable "Game" \\ Deluxe';
            }
        };

        $values = [
            true,
            false,
            null,
            0,
            -42,
            1999999,
            3.14159,
            '',
            'Halo',
            'The Legend of Zelda: "Breath of the Wild"',
            'Path\\To\\Game\\"Quote"',
            "Line1\nLine2\t\"Quoted\"\\Backslash",
            "Null\0Byte\"Inside",
            'Pokémon Café Mix — 2024',
            $stringable,
        ];

        foreach ($values as $idx => $value) {
            $php = $builder->formatValuePhp($value);
            $native = $builder->formatValueNative($value);
            $this->assertSame($php, $native, "formatValue parity failed at index {$idx}");

            foreach ([false, true, null] as $flag) {
                $builder->useRustFfi = $flag;
                $this->assertSame($php, $builder->formatValue($value));
            }
        }
    }

    public function test_igdb_where_in_and_to_query_parity(): void
    {
        $cases = [
            'minimal' => static function (QueryBuilder $b): void {},
            'Typical game lookup' => static function (QueryBuilder $b): void {
                $b->search('Halo: "Combat Evolved" \\ Anniversary')
                    ->with(['cover' => ['url', 'image_id'], 'platforms' => 'name', 'genres'])
                    ->where('category', 0)
                    ->where('version_parent', '=', null)
                    ->where('rating', '>=', 75)
                    ->whereIn('platforms', [6, 14, 48, 49, 130])
                    ->orderByDesc('rating')
                    ->limit(25);
            },
            'duplicates and string whereIn' => static function (QueryBuilder $b): void {
                $b->with(['cover' => ['url', 'url'], 'cover'])
                    ->where('status', '=', true)
                    ->where('status', '=', true)
                    ->whereIn('slug', ['halo-1', 'halo "2" \\ edition', '', 'true'])
                    ->whereIn('empty_list', [])
                    ->orderBy('first_release_date', 'ASC')
                    ->limit(0);
            },
            'nul in fields and where clauses declines safely to php' => static function (QueryBuilder $b): void {
                $b->with(["bad\0field" => 'name'])
                    ->where("bad\0where", '=', "val\0ue");
            },
            'over 64 unique fields exercises spill deduplication' => static function (QueryBuilder $b): void {
                $fields = [];
                for ($i = 0; $i < 80; $i++) {
                    $fields[] = "field_{$i}";
                }
                for ($i = 0; $i < 20; $i++) {
                    $fields[] = "field_{$i}";
                }
                $b->with(['rel' => $fields]);
            },
            'float in whereIn declines safely to php' => static function (QueryBuilder $b): void {
                /** @var array<int, int|string> $mixed */
                $mixed = [1, 2.5, 'three', true, false, null];
                $b->whereIn('mixed_field', $mixed);
            },
        ];

        foreach ($cases as $label => $configure) {
            $bPhp = $this->newQueryBuilder();
            $bPhp->useRustFfi = false;
            $configure($bPhp);
            $expected = $bPhp->toQueryPhp();

            $bNative = $this->newQueryBuilder();
            $bNative->useRustFfi = true;
            $configure($bNative);
            $this->assertSame($expected, $bNative->toQueryNative(), "toQueryNative parity failed for: {$label}");
            $this->assertSame($expected, $bNative->toQuery(), "toQuery(useRustFfi=true) failed for: {$label}");
        }
    }

    public function test_parsed_tv_release_from_parse_info_parity(): void
    {
        foreach ($this->tvParseInfoFixtures() as $label => $input) {
            $php = ParsedTvRelease::fromParseInfoPhp($input);
            $native = ParsedTvRelease::fromParseInfoNative($input);

            if ($php === null) {
                $this->assertNull($native, "Expected null from fromParseInfoNative for: {$label}");
            } else {
                $this->assertNotNull($native, "Expected non-null from fromParseInfoNative for: {$label}");
                $this->assertSame($this->snapshotTvRelease($php), $this->snapshotTvRelease($native), "Mismatch on: {$label}");
            }

            ParsedTvRelease::$useRustFfi = false;
            $viaFalse = ParsedTvRelease::fromParseInfo($input);
            ParsedTvRelease::$useRustFfi = true;
            $viaTrue = ParsedTvRelease::fromParseInfo($input);
            ParsedTvRelease::$useRustFfi = null;
            $viaDefault = ParsedTvRelease::fromParseInfo($input);

            $this->assertSame(
                $php ? $this->snapshotTvRelease($php) : null,
                $viaFalse ? $this->snapshotTvRelease($viaFalse) : null
            );
            $this->assertSame(
                $php ? $this->snapshotTvRelease($php) : null,
                $viaTrue ? $this->snapshotTvRelease($viaTrue) : null
            );
            $this->assertSame(
                $php ? $this->snapshotTvRelease($php) : null,
                $viaDefault ? $this->snapshotTvRelease($viaDefault) : null
            );
        }
    }

    public function test_parsed_tv_release_title_without_year_and_country_code_parity(): void
    {
        $cleanNames = [
            'Doctor Who (2005)',
            'The Twilight Zone (1959)',
            'Battlestar Galactica   (2003)',
            "Show With Tab\t(2021)",
            "Show With FormFeed\x0C(2021)",
            'Show Without Year',
            'Show (1899)',
            'Show (2100)',
            'Show (202a)',
            '  Padded Show (2020)',
            'Padded Trailing (2020)  ',
            "Newline Before\n(2020)",
            "Trailing Newline (2020)\n",
            "\0Leading And Trailing Nul (2019)\0",
            '(1999)',
            '   (2024)',
            '',
        ];

        $countries = ['', 'U', 'US', 'UK', 'DE', 'JP', 'USA', '   ', "\0\0"];

        foreach ($cleanNames as $idx => $cleanName) {
            $country = $countries[$idx % \count($countries)];
            $tv = new ParsedTvRelease(
                name: $cleanName,
                cleanName: $cleanName,
                country: $country,
                season: 1,
                episode: 1,
                seasonPack: false,
                airdate: null,
                year: 2020,
            );

            $expectedTitle = $tv->titleWithoutYearPhp();
            $this->assertSame($expectedTitle, $tv->titleWithoutYearNative(), "titleWithoutYear mismatch for idx {$idx}");

            $expectedCountry = $tv->countryCodePhp();
            $this->assertSame($expectedCountry, $tv->countryCodeNative(), "countryCode mismatch for idx {$idx}");

            ParsedTvRelease::$useRustFfi = true;
            $this->assertSame($expectedTitle, $tv->titleWithoutYear());
            $this->assertSame($expectedCountry, $tv->countryCode());
            ParsedTvRelease::$useRustFfi = false;
            $this->assertSame($expectedTitle, $tv->titleWithoutYear());
            $this->assertSame($expectedCountry, $tv->countryCode());
        }
    }

    public function test_metadata_search_lookup_normalize_boolean_search_words_parity(): void
    {
        $inputs = [
            '',
            '   ',
            '+++',
            ' + + + ',
            '+word1 +word2',
            '+the +matrix +reloaded +2003',
            "  +star\t\t+wars\n+episode\r\n+iv  ",
            "\x0C\x0B+formfeed\x0C+and\x0B+vtab\x0C",
            "\0+leading_nul +middle\0nul +trailing_nul\0",
            "\0+\x0C  +edge_mix  \x0C+\0",
            'already normalized phrase',
            '+café +résumé +naïve +日本語',
            str_repeat('+token ', 250),
        ];

        foreach ($inputs as $idx => $input) {
            $php = MetadataSearchLookup::normalizeBooleanSearchWordsPhp($input);
            $native = MetadataSearchLookup::normalizeBooleanSearchWordsNative($input);
            $this->assertSame($php, $native, "normalizeBooleanSearchWords mismatch at index {$idx}");

            foreach ([false, true, null] as $flag) {
                MetadataSearchLookup::$useRustFfi = $flag;
                $this->assertSame($php, MetadataSearchLookup::normalizeBooleanSearchWords($input));
            }
        }
    }

    public function test_direct_ffi_abi_rejection_and_resize_contracts(): void
    {
        $aniFfi = PopulateAniListService::normalizerFfi();
        $igdbFfi = QueryBuilder::queryFfi();
        $tvFfi = ParsedTvRelease::tvFfi();
        $lookupFfi = MetadataSearchLookup::lookupFfi();
        $this->assertNotNull($aniFfi);
        $this->assertNotNull($igdbFfi);
        $this->assertNotNull($tvFfi);
        $this->assertNotNull($lookupFfi);

        $buf = $aniFfi->new('char[64]');

        // Null input with non-zero length -> -1
        // @phpstan-ignore method.notFound
        $this->assertSame(-1, $aniFfi->nntmux_anilist_normalize_media(null, 5, $buf, 64));
        // @phpstan-ignore method.notFound
        $this->assertSame(-1, $igdbFfi->nntmux_igdb_format_string(null, 5, $buf, 64));
        // @phpstan-ignore method.notFound
        $this->assertSame(-1, $tvFfi->nntmux_tv_from_parse_info(null, 5, $buf, 64));
        // @phpstan-ignore method.notFound
        $this->assertSame(-1, $tvFfi->nntmux_tv_title_without_year(null, 5, $buf, 64));
        // @phpstan-ignore method.notFound
        $this->assertSame(-1, $tvFfi->nntmux_tv_country_code(null, 5, $buf, 64));
        // @phpstan-ignore method.notFound
        $this->assertSame(-1, $lookupFfi->nntmux_metadata_normalize_boolean_search_words(null, 5, $buf, 64));

        // Malformed serialized payload -> -1
        // @phpstan-ignore method.notFound
        $this->assertSame(-1, $aniFfi->nntmux_anilist_normalize_media('not-serialized', 14, $buf, 64));
        // @phpstan-ignore method.notFound
        $this->assertSame(-1, $igdbFfi->nntmux_igdb_where_in('id', 2, 'bad', 3, $buf, 64));
        // @phpstan-ignore method.notFound
        $this->assertSame(-1, $tvFfi->nntmux_tv_from_parse_info('bad', 3, $buf, 64));

        // Undersized capacity signals negative required capacity `< -1`
        $tiny = $lookupFfi->new('char[2]');
        $query = '+alpha +beta +gamma';
        // @phpstan-ignore method.notFound
        $needed = $lookupFfi->nntmux_metadata_normalize_boolean_search_words($query, \strlen($query), $tiny, 2);
        $this->assertLessThan(-1, $needed);
        $exactCap = -$needed;
        $exactBuf = $lookupFfi->new("char[{$exactCap}]");
        // @phpstan-ignore method.notFound
        $written = $lookupFfi->nntmux_metadata_normalize_boolean_search_words($query, \strlen($query), $exactBuf, $exactCap);
        $this->assertSame($exactCap, $written);
        $this->assertSame('alpha beta gamma', FFI::string($exactBuf, $written));
    }

    public function test_unavailable_ffi_falls_back_cleanly_to_php(): void
    {
        foreach ([
            [PopulateAniListService::class, 'normalizerFfi'],
            [QueryBuilder::class, 'queryFfi'],
            [ParsedTvRelease::class, 'tvFfi'],
            [MetadataSearchLookup::class, 'lookupFfi'],
        ] as [$class, $prop]) {
            $reflection = new ReflectionProperty($class, $prop);
            $reflection->setValue(null, false);
        }

        $service = $this->newAniListService();
        $payload = $this->aniListPayloads()['common_full'];
        $this->assertSame($service->normalizeAniListMediaPhp($payload), $service->normalizeAniListMediaNative($payload));

        $qb = $this->newQueryBuilder();
        $qb->useRustFfi = true;
        $qb->search('Halo "1"')->whereIn('platforms', [6, 14])->limit(5);
        $this->assertSame($qb->toQueryPhp(), $qb->toQueryNative());
        $this->assertSame($qb->formatValuePhp('A "B"'), $qb->formatValueNative('A "B"'));

        $info = $this->tvParseInfoFixtures()['standard_episode'];
        $tvPhp = ParsedTvRelease::fromParseInfoPhp($info);
        $tvNative = ParsedTvRelease::fromParseInfoNative($info);
        $this->assertNotNull($tvPhp);
        $this->assertNotNull($tvNative);
        $this->assertSame($this->snapshotTvRelease($tvPhp), $this->snapshotTvRelease($tvNative));
        $this->assertSame($tvPhp->titleWithoutYearPhp(), $tvPhp->titleWithoutYearNative());
        $this->assertSame($tvPhp->countryCodePhp(), $tvPhp->countryCodeNative());

        $this->assertSame(
            MetadataSearchLookup::normalizeBooleanSearchWordsPhp('+a +b'),
            MetadataSearchLookup::normalizeBooleanSearchWordsNative('+a +b')
        );
    }

    public function test_non_c_locale_falls_back_cleanly_and_restores_pcre_tables(): void
    {
        $startupAsciiTables = preg_match('/^\s$/', "\xa0") === 0;
        $previous = setlocale(LC_CTYPE, '0');
        $switched = setlocale(LC_CTYPE, 'en_US.ISO8859-1', 'en_US.UTF-8', 'de_DE.UTF-8', 'fr_FR.UTF-8');
        try {
            if ($switched !== false && ! \in_array($switched, ['C', 'POSIX', 'C.UTF-8', 'C.utf8'], true)) {
                $this->assertFalse(PopulateAniListService::normalizerEnvironmentSupported());
                $this->assertFalse(ParsedTvRelease::tvEnvironmentSupported());
                $this->assertFalse(MetadataSearchLookup::lookupEnvironmentSupported());

                $tv = new ParsedTvRelease('Show (2020)', 'Show (2020)', 'US', 1, 2, false, null, 2020);
                $this->assertSame('Show', $tv->titleWithoutYearNative());
                $this->assertSame('word1 word2', MetadataSearchLookup::normalizeBooleanSearchWordsNative('+word1 +word2'));
            } else {
                $this->assertTrue(PopulateAniListService::normalizerEnvironmentSupported());
            }
        } finally {
            setlocale(LC_CTYPE, 'C');
            if (\is_string($previous) && $previous !== '') {
                setlocale(LC_CTYPE, $previous);
            }
            if ($startupAsciiTables) {
                setlocale(LC_CTYPE, 'C');
            }
        }
    }

    /**
     * Run paired single-call microbenchmarks (1,024 warmups + 20,480 pairs) on common & uncommon workloads.
     */
    public static function benchmark(string $outputPath): void
    {
        $self = new self('benchmark');
        $service = $self->newAniListService();
        $commonAniList = $self->aniListPayloads()['common_full'];
        $uncommonAniList = $self->aniListPayloads()['uncommon_large'];

        $qbCommonPhp = $self->newQueryBuilder();
        $qbCommonPhp->search('The Witcher 3: Wild Hunt')
            ->with(['cover' => ['url', 'image_id'], 'platforms' => ['name'], 'genres' => ['name']])
            ->where('category', 0)
            ->where('rating', '>=', 80)
            ->orderByDesc('rating')
            ->limit(10);
        $qbCommonNative = clone $qbCommonPhp;

        $qbUncommonPhp = $self->newQueryBuilder();
        $manyFields = [];
        for ($i = 0; $i < 24; $i++) {
            $manyFields[] = "field_{$i}";
        }
        $qbUncommonPhp->search('Cyberpunk 2077: "Phantom Liberty" \\ Ultimate Edition')
            ->with(['cover' => $manyFields, 'platforms' => $manyFields, 'involved_companies' => ['company.name']])
            ->where('category', 0)
            ->where('version_parent', '=', null)
            ->where('rating', '>=', 70)
            ->where('total_rating_count', '>=', 50)
            ->whereIn('platforms', [6, 14, 48, 49, 130, 167, 169])
            ->orderByDesc('first_release_date')
            ->limit(50);
        $qbUncommonNative = clone $qbUncommonPhp;

        $whereInCommon = [6, 14, 48, 49, 130];
        $whereInUncommon = [
            'action-adventure',
            'role-playing-"rpg"',
            'sci-fi\\cyberpunk',
            'open-world',
            'single-player',
            'multiplayer-coop',
            'steam-deck-verified',
            'ray-tracing-"rt"',
            'controller-support',
            'cloud-saves',
            'achievements',
            'hdr-"10bit"',
        ];

        $formatCommon = 'The Legend of Zelda: Tears of the Kingdom';
        $formatUncommon = 'Title with "quotes" and \\backslashes\\ — Special "Collector\'s" Edition (2025) \\ Deluxe "GOTY"';

        $tvInfoCommon = [
            'name' => 'The.Bear.S03E04.1080p.WEB.H264',
            'cleanname' => 'The Bear (2022)',
            'country' => 'US',
            'season' => '03',
            'episode' => '04',
            'airdate' => '',
            'year' => '2022',
        ];
        $tvInfoUncommon = [
            'name' => 'Doctor.Who.2005.S14.COMPLETE.2160p.WEB-DL',
            'cleanname' => 'Doctor Who (2005)',
            'country' => 'UK',
            'season' => 14,
            'episode' => ['all'],
            'airdate' => '2024-05-11',
            'year' => 2005,
        ];

        $tvCommonObj = new ParsedTvRelease('The Bear', 'The Bear (2022)', 'US', 3, 4, false, null, 2022);
        $tvUncommonObj = new ParsedTvRelease(
            'The Lord of the Rings: The Rings of Power',
            'The Lord of the Rings: The Rings of Power   (2022)',
            'UNITED_KINGDOM',
            2,
            8,
            false,
            '2024-10-03',
            2022
        );

        $boolCommon = '+the +bear +s03e04 +1080p';
        $boolUncommon = "  +the   +lord\t+of\n+the++rings  +the\r\n+rings\t+of++power   +season +02 +2160p +uhd +hdr10 +web +h265  ";

        $suites = [
            'PopulateAniListService::normalizeAniListMedia' => [
                'common' => [
                    'php' => static fn () => $service->normalizeAniListMediaPhp($commonAniList),
                    'rust' => static fn () => $service->normalizeAniListMediaNative($commonAniList),
                ],
                'uncommon' => [
                    'php' => static fn () => $service->normalizeAniListMediaPhp($uncommonAniList),
                    'rust' => static fn () => $service->normalizeAniListMediaNative($uncommonAniList),
                ],
            ],
            'QueryBuilder::toQuery' => [
                'common' => [
                    'php' => static fn () => $qbCommonPhp->toQueryPhp(),
                    'rust' => static fn () => $qbCommonNative->toQueryNative(),
                ],
                'uncommon' => [
                    'php' => static fn () => $qbUncommonPhp->toQueryPhp(),
                    'rust' => static fn () => $qbUncommonNative->toQueryNative(),
                ],
            ],
            'QueryBuilder::whereIn' => [
                'common' => [
                    'php' => static fn () => (clone $qbCommonPhp)->whereInPhp('platforms', $whereInCommon),
                    'rust' => static fn () => (clone $qbCommonNative)->whereInNative('platforms', $whereInCommon),
                ],
                'uncommon' => [
                    'php' => static fn () => (clone $qbCommonPhp)->whereInPhp('themes', $whereInUncommon),
                    'rust' => static fn () => (clone $qbCommonNative)->whereInNative('themes', $whereInUncommon),
                ],
            ],
            'QueryBuilder::formatValue' => [
                'common' => [
                    'php' => static fn () => $qbCommonPhp->formatValuePhp($formatCommon),
                    'rust' => static fn () => $qbCommonNative->formatValueNative($formatCommon),
                ],
                'uncommon' => [
                    'php' => static fn () => $qbCommonPhp->formatValuePhp($formatUncommon),
                    'rust' => static fn () => $qbCommonNative->formatValueNative($formatUncommon),
                ],
            ],
            'ParsedTvRelease::fromParseInfo' => [
                'common' => [
                    'php' => static fn () => ParsedTvRelease::fromParseInfoPhp($tvInfoCommon),
                    'rust' => static fn () => ParsedTvRelease::fromParseInfoNative($tvInfoCommon),
                ],
                'uncommon' => [
                    'php' => static fn () => ParsedTvRelease::fromParseInfoPhp($tvInfoUncommon),
                    'rust' => static fn () => ParsedTvRelease::fromParseInfoNative($tvInfoUncommon),
                ],
            ],
            'ParsedTvRelease::titleWithoutYear' => [
                'common' => [
                    'php' => static fn () => $tvCommonObj->titleWithoutYearPhp(),
                    'rust' => static fn () => $tvCommonObj->titleWithoutYearNative(),
                ],
                'uncommon' => [
                    'php' => static fn () => $tvUncommonObj->titleWithoutYearPhp(),
                    'rust' => static fn () => $tvUncommonObj->titleWithoutYearNative(),
                ],
            ],
            'ParsedTvRelease::countryCode' => [
                'common' => [
                    'php' => static fn () => $tvCommonObj->countryCodePhp(),
                    'rust' => static fn () => $tvCommonObj->countryCodeNative(),
                ],
                'uncommon' => [
                    'php' => static fn () => $tvUncommonObj->countryCodePhp(),
                    'rust' => static fn () => $tvUncommonObj->countryCodeNative(),
                ],
            ],
            'MetadataSearchLookup::normalizeBooleanSearchWords' => [
                'common' => [
                    'php' => static fn () => MetadataSearchLookup::normalizeBooleanSearchWordsPhp($boolCommon),
                    'rust' => static fn () => MetadataSearchLookup::normalizeBooleanSearchWordsNative($boolCommon),
                ],
                'uncommon' => [
                    'php' => static fn () => MetadataSearchLookup::normalizeBooleanSearchWordsPhp($boolUncommon),
                    'rust' => static fn () => MetadataSearchLookup::normalizeBooleanSearchWordsNative($boolUncommon),
                ],
            ],
        ];

        $results = [];
        foreach ($suites as $operation => $workloads) {
            foreach ($workloads as $workloadName => $callables) {
                $results[$operation][$workloadName] = self::runPairedBenchmark(
                    $callables['php'],
                    $callables['rust'],
                    1024,
                    20480,
                );
            }
        }

        file_put_contents($outputPath, json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /**
     * @param  callable(): mixed  $phpFn
     * @param  callable(): mixed  $rustFn
     * @return array<string, float|int>
     */
    private static function runPairedBenchmark(callable $phpFn, callable $rustFn, int $warmups, int $pairs): array
    {
        for ($i = 0; $i < $warmups; $i++) {
            $phpFn();
            $rustFn();
        }

        $phpTimes = [];
        $rustTimes = [];
        mt_srand(12062026);

        for ($i = 0; $i < $pairs; $i++) {
            if ((mt_rand() & 1) === 0) {
                $t0 = hrtime(true);
                $phpFn();
                $t1 = hrtime(true);
                $phpTimes[] = $t1 - $t0;

                $t2 = hrtime(true);
                $rustFn();
                $t3 = hrtime(true);
                $rustTimes[] = $t3 - $t2;
            } else {
                $t0 = hrtime(true);
                $rustFn();
                $t1 = hrtime(true);
                $rustTimes[] = $t1 - $t0;

                $t2 = hrtime(true);
                $phpFn();
                $t3 = hrtime(true);
                $phpTimes[] = $t3 - $t2;
            }
        }

        sort($phpTimes);
        sort($rustTimes);

        $phpMean = array_sum($phpTimes) / $pairs;
        $rustMean = array_sum($rustTimes) / $pairs;
        $p50Idx = (int) floor($pairs * 0.50);
        $p99Idx = (int) floor($pairs * 0.99);

        $phpP50 = $phpTimes[$p50Idx];
        $rustP50 = $rustTimes[$p50Idx];
        $phpP99 = $phpTimes[$p99Idx];
        $rustP99 = $rustTimes[$p99Idx];

        return [
            'pairs' => $pairs,
            'php_mean_ns' => round($phpMean, 1),
            'rust_mean_ns' => round($rustMean, 1),
            'mean_speedup' => round($phpMean / max(1.0, $rustMean), 2),
            'php_p50_ns' => $phpP50,
            'rust_p50_ns' => $rustP50,
            'p50_speedup' => round($phpP50 / max(1, $rustP50), 2),
            'php_p99_ns' => $phpP99,
            'rust_p99_ns' => $rustP99,
            'p99_speedup' => round($phpP99 / max(1, $rustP99), 2),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshotTvRelease(ParsedTvRelease $tv): array
    {
        return [
            'name' => $tv->name,
            'cleanName' => $tv->cleanName,
            'country' => $tv->country,
            'season' => $tv->season,
            'episode' => $tv->episode,
            'seasonPack' => $tv->seasonPack,
            'airdate' => $tv->airdate,
            'year' => $tv->year,
            'hasEpisodeNumbers' => $tv->hasEpisodeNumbers(),
            'hasAirdate' => $tv->hasAirdate(),
            'hasEpisodeReference' => $tv->hasEpisodeReference(),
            'titleWithoutYear' => $tv->titleWithoutYearPhp(),
            'countryCode' => $tv->countryCodePhp(),
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function aniListPayloads(): array
    {
        $characters25 = [];
        for ($i = 1; $i <= 15; $i++) {
            $characters25[] = ['name' => ['full' => $i === 3 ? '' : "Character {$i}"]];
        }

        $edges30 = [];
        $relTypes = ['SEQUEL', 'PREQUEL', 'SIDE_STORY', 'PARENT', 'SPIN_OFF', 'ALTERNATIVE', 'CHARACTER', 'ADAPTATION'];
        for ($i = 1; $i <= 50; $i++) {
            $edges30[] = [
                'relationType' => $relTypes[$i % \count($relTypes)],
                'node' => [
                    'id' => 1000 + $i,
                    'title' => [
                        'english' => $i % 3 === 0 ? null : "English Rel {$i}",
                        'romaji' => "Romaji Rel {$i}",
                    ],
                ],
            ];
        }

        return [
            'empty' => [],
            'common_full' => [
                'id' => 154587,
                'idMal' => 52991,
                'countryOfOrigin' => 'JP',
                'type' => 'ANIME',
                'format' => 'TV',
                'episodes' => 28,
                'duration' => 24,
                'status' => 'FINISHED',
                'source' => 'MANGA',
                'hashtag' => '#frieren',
                'startDate' => ['year' => 2023, 'month' => 9, 'day' => 29],
                'endDate' => ['year' => 2024, 'month' => 3, 'day' => 22],
                'description' => '<p>The adventure is over &amp; life goes on for <i>Frieren</i> &ndash; an elf mage.<br><br>(Source: Crunchyroll &quot;Official&quot; &#039;2023&#039;)</p>',
                'averageScore' => 91,
                'coverImage' => ['large' => 'https://s4.anilist.co/file/anilistcdn/media/anime/cover/large/bx154587-gHSraOSa0nBG.jpg'],
                'genres' => ['Adventure', 'Drama', 'Fantasy'],
                'studios' => [
                    'nodes' => [
                        ['name' => 'MADHOUSE'],
                        ['name' => ''],
                        ['name' => 'TOHO animation'],
                    ],
                ],
                'characters' => [
                    'nodes' => [
                        ['name' => ['full' => 'Frieren']],
                        ['name' => ['full' => 'Fern']],
                        ['name' => ['full' => 'Stark']],
                        ['name' => ['full' => 'Himmel']],
                    ],
                ],
                'relations' => [
                    'edges' => [
                        [
                            'relationType' => 'SEQUEL',
                            'node' => [
                                'id' => 180001,
                                'title' => ['english' => 'Frieren Season 2', 'romaji' => 'Sousou no Frieren 2'],
                            ],
                        ],
                        [
                            'relationType' => 'ALTERNATIVE',
                            'node' => [
                                'id' => 180002,
                                'title' => ['english' => null, 'romaji' => 'Sousou no Frieren Mini'],
                            ],
                        ],
                    ],
                ],
                'title' => [
                    'romaji' => 'Sousou no Frieren',
                    'english' => "Frieren: Beyond Journey's End",
                    'native' => '葬送のフリーレン',
                ],
            ],
            'uncommon_large' => [
                'id' => 21,
                'idMal' => 21,
                'countryOfOrigin' => 'JP',
                'type' => 'ANIME',
                'format' => 'TV',
                'episodes' => 1100,
                'duration' => 24,
                'status' => 'RELEASING',
                'source' => 'MANGA',
                'hashtag' => '#OnePiece',
                'startDate' => ['year' => 1999, 'month' => 10, 'day' => 20],
                'endDate' => ['year' => null, 'month' => null, 'day' => null],
                'description' => '<p>Gol D. Roger was known as the &ldquo;Pirate King&rdquo;&mdash;the strongest &amp; most infamous being to have sailed the Grand Line&hellip;</p><p>With <a href="https://anilist.co">links</a> and <b>bold</b> tags &rsquo;quoted&lsquo;.</p>',
                'averageScore' => 88,
                'coverImage' => ['large' => 'https://s4.anilist.co/file/anilistcdn/media/anime/cover/large/bx21-YCDoj1EkAxFn.jpg'],
                'genres' => ['Action', 'Adventure', '', 'Comedy', 'Drama', 'Fantasy'],
                'studios' => [
                    'nodes' => [
                        ['name' => 'Toei Animation'],
                        ['name' => '0'],
                        ['name' => 'Fuji TV'],
                    ],
                ],
                'characters' => ['nodes' => $characters25],
                'relations' => ['edges' => $edges30],
                'title' => [
                    'romaji' => 'ONE PIECE',
                    'english' => 'ONE PIECE',
                    'native' => 'ONE PIECE',
                ],
            ],
            'unknown_entity_and_query_url_decline_to_php' => [
                'id' => 999,
                'description' => 'Copyright &copy; 2024 with bare < angle bracket',
                'coverImage' => ['large' => 'https://example.com/covers/anime%20cover.jpg?size=large'],
                'startDate' => ['year' => -5, 'month' => 1, 'day' => 1],
                'title' => ['romaji' => '0', 'english' => '', 'native' => 'テスト'],
            ],
        ];
    }

    /**
     * @return array<string, array<string, mixed>|false>
     */
    private function tvParseInfoFixtures(): array
    {
        return [
            'false_input' => false,
            'empty_array' => [],
            'empty_name' => ['name' => '', 'season' => 1, 'episode' => 1],
            'zero_string_name' => ['name' => '0', 'season' => 1, 'episode' => 1],
            'missing_season' => ['name' => 'Lost', 'episode' => 1],
            'missing_episode' => ['name' => 'Lost', 'season' => 1],
            'standard_episode' => [
                'name' => 'Severance.S01E09.1080p',
                'cleanname' => 'Severance (2022)',
                'country' => 'US',
                'season' => '01',
                'episode' => '09',
                'airdate' => '',
                'year' => '2022',
            ],
            'season_pack_string' => [
                'name' => 'Andor.S01.2160p',
                'cleanname' => 'Andor',
                'country' => 'UK',
                'season' => 1,
                'episode' => 'all',
                'year' => 2022,
            ],
            'episode_array_first_element' => [
                'name' => 'Avatar.S01E01E02',
                'season' => '1',
                'episode' => ['01', '02'],
            ],
            'episode_array_empty_defaults_to_zero' => [
                'name' => 'Special.Show',
                'season' => 0,
                'episode' => [],
            ],
            'episode_array_all_season_pack' => [
                'name' => 'Band.of.Brothers.S01',
                'season' => 1,
                'episode' => ['all'],
            ],
            'airdate_release_with_non_numeric_season_episode' => [
                'name' => 'The.Daily.Show.2024.10.24',
                'cleanname' => 'The Daily Show',
                'country' => 'US',
                'season' => '2024',
                'episode' => '10/24',
                'airdate' => '2024-10-24',
                'year' => null,
            ],
            'negative_and_padded_numbers' => [
                'name' => 'Test.Show',
                'season' => '  -2',
                'episode' => '+007',
                'year' => ' 1999',
            ],
            'float_and_scientific_strings_decline_to_php' => [
                'name' => 'Float.Show',
                'season' => 1.5,
                'episode' => '2e1',
                'year' => '2020.0',
            ],
        ];
    }
}

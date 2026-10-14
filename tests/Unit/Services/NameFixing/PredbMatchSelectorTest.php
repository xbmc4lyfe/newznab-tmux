<?php

declare(strict_types=1);

namespace Tests\Unit\Services\NameFixing;

use App\Services\NameFixing\PredbMatchSelector;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PredbMatchSelectorTest extends TestCase
{
    #[DataProvider('similarityInputs')]
    public function test_similarity_preserves_native_percentages(string $left, string $right): void
    {
        $selector = new class extends PredbMatchSelector
        {
            public function compare(string $left, string $right): float
            {
                return $this->similarityPercent($left, $right);
            }
        };
        $expected = 0.0;
        similar_text($left, $right, $expected);
        $this->assertSame($expected, $selector->compare($left, $right));
    }

    /** @return array<string, array{string, string}> */
    public static function similarityInputs(): array
    {
        return [
            'empty' => ['', ''],
            'empty left' => ['', 'fixture'],
            'empty right' => ['fixture', ''],
            'zero' => ['0', '0'],
            'equal ASCII' => ['Fixture.Release-GRP', 'Fixture.Release-GRP'],
            'equal Unicode' => ['é界🚀', 'é界🚀'],
            'equal binary' => ["fixture\0\xFF", "fixture\0\xFF"],
            'long equality' => [str_repeat('x', 256), str_repeat('x', 256)],
            'case difference' => ['Fixture', 'fixture'],
            'unequal' => ['abcXYZ', 'XYZabc'],
            'numeric difference' => ['0', '00'],
        ];
    }

    public function test_it_prefers_the_best_matching_predb_hit_over_the_first_hit(): void
    {
        $selector = new PredbMatchSelector;

        $bestMatch = $selector->selectBestMatch('The.Fisher.King.1991.1080p.BluRay.x264-GRP', [
            [
                'id' => 1,
                'title' => '1.vs.1.Suzu.Matsuoka.JAPANESE.XXX.720p.WEBRiP.MP4-MFPF',
                'filename' => '1.vs.1.Suzu.Matsuoka.JAPANESE.XXX.720p.WEBRiP.MP4-MFPF.mp4',
            ],
            [
                'id' => 2,
                'title' => 'The.Fisher.King.1991.1080p.BluRay.x264-GRP',
                'filename' => 'The.Fisher.King.1991.1080p.BluRay.x264-GRP.mkv',
            ],
        ]);

        $this->assertNotNull($bestMatch);
        $this->assertSame(2, $bestMatch['id']);
    }

    public function test_it_rejects_numeric_only_queries(): void
    {
        $selector = new PredbMatchSelector;

        $bestMatch = $selector->selectBestMatch('1', [
            [
                'id' => 1,
                'title' => '1.vs.1.Suzu.Matsuoka.JAPANESE.XXX.720p.WEBRiP.MP4-MFPF',
                'filename' => '1.vs.1.Suzu.Matsuoka.JAPANESE.XXX.720p.WEBRiP.MP4-MFPF.mp4',
            ],
        ]);

        $this->assertNull($bestMatch);
    }

    public function test_it_rejects_generic_dvd_structure_queries(): void
    {
        $selector = new PredbMatchSelector;

        $bestMatch = $selector->selectBestMatch('VTS_01_1', [
            [
                'id' => 1,
                'title' => '1.vs.1.Suzu.Matsuoka.JAPANESE.XXX.720p.WEBRiP.MP4-MFPF',
                'filename' => '1.vs.1.Suzu.Matsuoka.JAPANESE.XXX.720p.WEBRiP.MP4-MFPF.mp4',
            ],
        ]);

        $this->assertNull($bestMatch);
    }

    public function test_it_rejects_cover_style_queries_missing_the_literal_release_identity(): void
    {
        $selector = new PredbMatchSelector;

        $bestMatch = $selector->selectBestMatch('cover the fisher king', [
            [
                'id' => 1,
                'title' => '1.vs.1.Suzu.Matsuoka.JAPANESE.XXX.720p.WEBRiP.MP4-MFPF',
                'filename' => '1.vs.1.Suzu.Matsuoka.JAPANESE.XXX.720p.WEBRiP.MP4-MFPF.mp4',
            ],
            [
                'id' => 2,
                'title' => 'The.Fisher.King.1991.1080p.BluRay.x264-GRP',
                'filename' => 'The.Fisher.King.1991.1080p.BluRay.x264-GRP.mkv',
            ],
        ]);

        $this->assertNull($bestMatch);
    }

    public function test_it_rejects_unrelated_release_sharing_only_date_and_resolution(): void
    {
        $selector = new PredbMatchSelector;

        $bestMatch = $selector->selectBestMatch(
            'NubileFilms.Molly.Little.My.Little.Seductress.S50E7.26.04.07.2160p',
            [
                [
                    'id' => 1,
                    'title' => 'Private.26.04.07.Alice.Ross.And.Nata.Gold.XXX.2160p.MP4-WRB',
                    'filename' => 'Private.26.04.07.Alice.Ross.And.Nata.Gold.XXX.2160p.MP4-WRB.mp4',
                    'source' => 'srrdb',
                ],
                [
                    'id' => 2,
                    'title' => 'SomeOther.26.04.07.Random.Title.XXX.2160p.MP4-GRP',
                    'filename' => 'SomeOther.26.04.07.Random.Title.XXX.2160p.MP4-GRP.mp4',
                    'source' => 'srrdb',
                ],
            ]
        );

        $this->assertNull($bestMatch, 'Should reject all results when none share meaningful tokens with the query');
    }

    public function test_it_selects_correct_match_when_date_overlaps_with_unrelated_results(): void
    {
        $selector = new PredbMatchSelector;

        $bestMatch = $selector->selectBestMatch(
            'NubileFilms.Molly.Little.My.Little.Seductress.S50E7.26.04.07.2160p',
            [
                [
                    'id' => 1,
                    'title' => 'Private.26.04.07.Alice.Ross.And.Nata.Gold.XXX.2160p.MP4-WRB',
                    'filename' => 'Private.26.04.07.Alice.Ross.And.Nata.Gold.XXX.2160p.MP4-WRB.mp4',
                    'source' => 'srrdb',
                ],
                [
                    'id' => 2,
                    'title' => 'NubileFilms.Molly.Little.My.Little.Seductress.S50E7.26.04.07.2160p-GRP',
                    'filename' => 'NubileFilms.Molly.Little.My.Little.Seductress.S50E7.26.04.07.2160p.mp4',
                    'source' => 'srrdb',
                ],
            ]
        );

        $this->assertNotNull($bestMatch);
        $this->assertSame(2, $bestMatch['id'], 'Should select the result that shares meaningful tokens with the query');
    }

    public function test_numbered_and_unnumbered_aliases_select_distinct_canonical_titles(): void
    {
        $selector = new PredbMatchSelector;
        $hits = [
            ['id' => 91, 'title' => 'Fixture.Canonical.Alpha.2026-GRP', 'filename' => '123.Fixture.Alias.2026-GRP.mkv'],
            ['id' => 92, 'title' => 'Fixture.Canonical.Beta.2026-GRP', 'filename' => 'Fixture.Alias.2026-GRP.mkv'],
        ];
        self::assertSame(91, $selector->selectBestMatch('123.Fixture.Alias.2026-GRP', $hits)['id'] ?? null);
        self::assertSame(92, $selector->selectBestMatch('Fixture.Alias.2026-GRP', $hits)['id'] ?? null);
    }

    public function test_same_numbered_alias_for_different_titles_remains_ambiguous(): void
    {
        self::assertNull((new PredbMatchSelector)->selectBestMatch('123.Fixture.Alias.2026-GRP', [
            ['id' => 91, 'title' => 'Fixture.Canonical.Alpha.2026-GRP', 'filename' => '123.Fixture.Alias.2026-GRP.mkv'],
            ['id' => 92, 'title' => 'Fixture.Canonical.Beta.2026-GRP', 'filename' => '123.Fixture.Alias.2026-GRP.mkv'],
        ]));
    }
}

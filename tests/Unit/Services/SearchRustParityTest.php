<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\Search\Drivers\ManticoreSearchDriver;
use App\Support\RustPipelineLibrary;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use Tests\TestCase;

class SearchRustParityTest extends TestCase
{
    #[Test]
    public function manticore_escape_string_and_prepare_query_match_php(): void
    {
        $this->assertTrue(RustPipelineLibrary::available());

        $escapeCases = [
            '',
            '*',
            '0',
            'simple query',
            'a--b-c!',
            'a---b',
            'a----b',
            'foo! ',
            'test (1) [2] @foo ~bar "baz" & / $ = \' ! -',
            'ubuntu.24.04.LTS-x86_64',
            'The.Matrix.1999.1080p.BluRay.x264-GROUP',
        ];

        foreach ($escapeCases as $input) {
            $php = ManticoreSearchDriver::escapeStringPhp($input);
            $rust = RustPipelineLibrary::manticoreEscapeString($input);
            $this->assertSame($php, $rust, "Mismatch for escapeString({$input})");
            $this->assertSame($php, ManticoreSearchDriver::escapeString($input));
        }

        $queryCases = [
            '',
            '*',
            '0',
            'matrix reloaded',
            '(matrix | "reloaded-2") -cam !ts spider-man *720p*',
            '!"exact - phrase!" ((grouped)) ***wild***',
            'unmatched "quote in token @danger $100',
            'from@user.name (poster) [group] | test* ? % ^ < >',
            'WEB-DL.x265.10bit-GRP',
        ];

        foreach ($queryCases as $query) {
            $phpUser = ManticoreSearchDriver::prepareUserSearchQueryPhp($query);
            $rustUser = RustPipelineLibrary::manticorePrepareQuery($query, 0);
            $this->assertSame($phpUser, $rustUser, "Mismatch for prepareUserSearchQuery({$query})");
            $this->assertSame($phpUser, ManticoreSearchDriver::prepareUserSearchQuery($query));

            $phpFrom = ManticoreSearchDriver::prepareReleaseFieldSearchQueryPhp('fromname', $query);
            $rustFrom = RustPipelineLibrary::manticorePrepareQuery($query, 1);
            $this->assertSame($phpFrom, $rustFrom, "Mismatch for prepareReleaseFieldSearchQuery(fromname, {$query})");
            $this->assertSame($phpFrom, ManticoreSearchDriver::prepareReleaseFieldSearchQuery('fromname', $query));
        }
    }

    #[Test]
    public function manticore_scope_and_build_field_search_expression_match_php(): void
    {
        $fields = ['', '@searchname', '@(name,searchname)', '@fromname', '@(title,filename)'];
        $queries = [
            'The.Matrix.1999.1080p.WEB-DL.x265',
            'spider-man -cam',
            '! unwanted_token.720p',
            'plain words without separators',
            '"quoted.phrase-test" 1080p',
            '0',
        ];

        foreach ($fields as $field) {
            foreach ($queries as $raw) {
                $prepared = ManticoreSearchDriver::prepareUserSearchQueryPhp($raw);
                $php = ManticoreSearchDriver::scopeReleaseSearchQueryPhp($raw, $prepared, $field);
                $rust = RustPipelineLibrary::manticoreScopeReleaseQuery($raw, $prepared, $field);
                $this->assertSame($php, $rust, "Mismatch for scopeReleaseSearchQuery({$raw}, {$field})");
                $this->assertSame($php, ManticoreSearchDriver::scopeReleaseSearchQuery($raw, $prepared, $field));
            }
        }

        $searchArrays = [
            [],
            ['searchname' => 'The.Expanse.S01E01.1080p.WEB-DL.x265'],
            ['name' => 'Ubuntu.24.04', 'searchname' => 'Ubuntu.24.04.LTS-x64', 'filename' => 'ubuntu-24.04.iso'],
            ['searchname' => '0', 'fromname' => ''],
            ['searchname' => '(matrix | reloaded) -cam', 'name' => 'matrix.reloaded.1999'],
        ];

        foreach ($searchArrays as $arr) {
            $php = ManticoreSearchDriver::buildFieldSearchExpressionPhp($arr);
            $this->assertSame($php, ManticoreSearchDriver::buildFieldSearchExpression($arr));
            $phpFiltered = ManticoreSearchDriver::buildFieldSearchExpressionPhp($arr, ['searchname']);
            $this->assertSame($phpFiltered, ManticoreSearchDriver::buildFieldSearchExpression($arr, ['searchname']));
        }
    }

    #[Test]
    public function autocomplete_and_suggest_fallback_ranking_match_php(): void
    {
        /** @var ManticoreSearchDriver $driver */
        $driver = (new ReflectionClass(ManticoreSearchDriver::class))->newInstanceWithoutConstructor();

        $searchnames = [
            'The.Matrix.1999.1080p.BluRay.x264-GROUP.mkv',
            'The.Matrix.1999.1080p.BluRay.x264-GROUP.nzb',
            'The.Matrix.Reloaded.2003.2160p.UHD.BluRay.x265-GRP.par2',
            'Matrix.Revolutions.2003.1080p.WEB-DL.DD5.1.H.264-GRP.r01',
            'Prefix.Padding.Words.Here.Before.The.Matrix.Resurrections.2021.1080p.AMZN.WEB-DL.DDP5.1.Atmos.H.264-GROUP.Extra.Trailing.Words.mkv',
            'Matriks.1999.720p.BRRip.x264',
            'Matrex.Reloaded.1080p.WEB-DL',
            'Matrx.1999.1080p.BluRay',
            'Matriks.2003.1080p.BluRay',
        ];

        foreach (['matrix', 'reloaded', '1080p'] as $query) {
            foreach ($searchnames as $sn) {
                $this->assertSame(
                    $driver->extractSuggestionPhp($sn, $query),
                    $driver->extractSuggestion($sn, $query),
                    "Mismatch for extractSuggestion({$sn}, {$query})"
                );
            }

            $this->assertSame(
                $driver->rankAutocompleteCandidatesPhp($searchnames, $query, 5),
                $driver->rankAutocompleteCandidates($searchnames, $query, 5),
                "Mismatch for rankAutocompleteCandidates({$query})"
            );

            $this->assertSame(
                $driver->rankSuggestFallbackCandidatesPhp($searchnames, $query, 5),
                $driver->rankSuggestFallbackCandidates($searchnames, $query, 5),
                "Mismatch for rankSuggestFallbackCandidates({$query})"
            );
        }
    }
}

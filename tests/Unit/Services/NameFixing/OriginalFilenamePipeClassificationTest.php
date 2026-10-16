<?php

declare(strict_types=1);

namespace Tests\Unit\Services\NameFixing;

use App\Services\NameFixing\OriginalFilenameEvidence;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OriginalFilenamePipeClassificationTest extends TestCase
{
    public static function classificationCases(): array
    {
        return [
            'literal double pipe' => ['Auxiliary.Asset||Exact.Release-GRP.mkv', true],
            'literal single pipe' => ['Exact|Release-GRP.rar', true],
            'pipe only stem' => ['|.mkv', true],
            'exact byte bound' => [str_repeat('x', 8191).'|', true],
            'byte overflow' => [str_repeat('x', 8192).'|', false],
            'exact segment bound' => ['Title|GRP'.str_repeat('.rar', 127), true],
            'segment overflow' => ['Title|GRP'.str_repeat('.rar', 128), false],
            'invalid UTF8' => ["Exact|\xFF.mkv", false],
            'control byte' => ["Exact|\x01.mkv", false],
            'directory pipe' => ['Dir|Aux/Exact.Release-GRP.mkv', false],
            'fullwidth vertical line' => ['Exact｜Release-GRP.mkv', false],
            'divides symbol' => ['Exact∣Release-GRP.mkv', false],
            'malformed wrapper' => ['__NZBSPLIT__bad__NZBSPLIT__Exact|Release-GRP.mkv', false],
            'wrapper path' => ['__NZBSPLIT__abcdef01__NZBSPLIT__dir/Exact|Release-GRP.mkv', false],
            'valid wrapper pipe' => ['__NZBSPLIT__abcdef01__NZBSPLIT__Exact|Release-GRP.mkv', true],
        ];
    }

    #[DataProvider('classificationCases')]
    public function test_only_bounded_proven_pipe_rejection_is_classified(string $raw, bool $expected): void
    {
        $e = new OriginalFilenameEvidence($raw);
        self::assertSame($expected, $e->isLocallyRejectedPipe());
        if ($expected) {
            self::assertNull($e->canonicalNominationKeys(), 'Nomination UNKNOWN API must remain unchanged.');
            foreach (['Exact.Release-GRP', 'Canonical.Title-GRP', 'Exact|Release-GRP'] as $title) {
                foreach (['', 'Exact.Release-GRP.rar', 'Auxiliary.Asset.rar'] as $alias) {
                    self::assertFalse($e->accepts($title, $alias));
                }
            }
        }
    }

    public static function acceptedNonPipeCases(): array
    {
        return [
            ['Dir|Aux/Exact.Release-GRP.mkv', 'Exact.Release-GRP'],
            ['Exact｜Release-GRP.mkv', 'Exact｜Release-GRP'],
            ['Exact∣Release-GRP.mkv', 'Exact∣Release-GRP'],
        ];
    }

    #[DataProvider('acceptedNonPipeCases')]
    public function test_effective_basename_and_unicode_lookalikes_remain_eligible(string $raw, string $title): void
    {
        $e = new OriginalFilenameEvidence($raw);
        self::assertFalse($e->isLocallyRejectedPipe());
        self::assertTrue($e->accepts($title));
        self::assertNotEmpty($e->canonicalNominationKeys());
    }
}

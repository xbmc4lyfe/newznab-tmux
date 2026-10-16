<?php

declare(strict_types=1);

namespace Tests\Unit\Services\NameFixing;

use App\Services\NameFixing\OriginalFilenameEvidence;
use App\Support\PredbSearchDocument;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OriginalFilenameNominationTest extends TestCase
{
    public function test_all_accepted_archive_boundaries_remain_available_for_ambiguity_checks(): void
    {
        $evidence = new OriginalFilenameEvidence('Exact.Release-GRP.rar.par2');
        self::assertTrue(method_exists($evidence, 'canonicalNominationKeys'), 'Bounded complete nomination API is not implemented.');
        self::assertSame(['exact.release-grp', 'exact.release-grp.rar', 'exact.release-grp.rar.par2'], $evidence->canonicalNominationKeys());
    }

    public function test_literal_codec_policy_is_shared_with_acceptance(): void
    {
        $evidence = new OriginalFilenameEvidence('Exact.Release-GRP.DTS.rar');
        self::assertTrue(method_exists($evidence, 'canonicalNominationKeys'));
        self::assertSame(['exact.release-grp.dts', 'exact.release-grp.dts.rar'], $evidence->canonicalNominationKeys());
    }

    public function test_encoded_wrapper_separators_are_title_bytes_not_suffix_boundaries(): void
    {
        $evidence = new OriginalFilenameEvidence('Outer__NZBSPLIT__abcdef01__NZBSPLIT__Exact_Release-GRP_rar.rar');
        self::assertTrue(method_exists($evidence, 'canonicalNominationKeys'));
        self::assertSame(['exact.release-grp.rar', 'exact.release-grp.rar.rar'], $evidence->canonicalNominationKeys());
    }

    public static function canonicalCases(): array
    {
        $cases = [];
        foreach (['İ.Title-GRP', 'i̇.Title-GRP', 'K.Title-GRP', 'ΟΣ.Title-GRP', 'A.Σ', 'Éditeur.Asset-GRP'] as $title) {
            foreach (['', '.mkv', '.rar.par2'] as $suffix) {
                $cases[$title.$suffix] = [$title, $suffix];
            }
        }

        return $cases;
    }

    #[DataProvider('canonicalCases')]
    public function test_isolated_unicode_prefixes_nominate_every_accepted_canonical_title(string $title, string $suffix): void
    {
        $evidence = new OriginalFilenameEvidence($title.$suffix);
        self::assertTrue($evidence->accepts($title));
        self::assertTrue(method_exists($evidence, 'canonicalNominationKeys'));
        self::assertContains(PredbSearchDocument::exactValue($title), $evidence->canonicalNominationKeys());
    }

    public static function aliasCases(): array
    {
        return [
            'archive and media' => ['Publisher.Asset-GRP.mkv', 'Publisher.Asset-GRP.rar'],
            'shared intermediate boundary' => ['Publisher.Asset-GRP.rar.rar', 'Publisher.Asset-GRP.rar.mkv'],
            'encoded parity wrapper' => ['Outer__NZBSPLIT__abcdef01__NZBSPLIT__Publisher_Asset-GRP.rar.par2', 'Publisher.Asset-GRP.mkv'],
            'unicode alias' => ['ÉDITEUR.Asset-GRP.mkv', 'éditeur.asset-grp.rar'],
        ];
    }

    #[DataProvider('aliasCases')]
    public function test_every_accepted_alias_has_a_shared_nomination_witness(string $input, string $alias): void
    {
        $evidence = new OriginalFilenameEvidence($input);
        self::assertTrue($evidence->accepts('Canonical.Title-GRP', $alias));
        self::assertTrue(method_exists($evidence, 'canonicalNominationKeys'));
        self::assertNotEmpty(array_intersect($evidence->canonicalNominationKeys(), (new OriginalFilenameEvidence($alias))->canonicalNominationKeys()));
    }

    public function test_work_and_input_overflow_are_unknown_instead_of_truncated_keys(): void
    {
        self::assertTrue(method_exists(OriginalFilenameEvidence::class, 'canonicalNominationKeys'));
        self::assertNull((new OriginalFilenameEvidence(str_repeat('x', 8193)))->canonicalNominationKeys());
        self::assertNull((new OriginalFilenameEvidence('Title-GRP'.str_repeat('.rar', 129)))->canonicalNominationKeys());
        self::assertNotEmpty((new OriginalFilenameEvidence('Title-GRP'.str_repeat('.rar', 64)))->canonicalNominationKeys());
    }

    public function test_numeric_identity_keys_remain_strings(): void
    {
        self::assertSame(['12345', '12345.rar'], (new OriginalFilenameEvidence('12345.rar'))->canonicalNominationKeys());
    }

    public function test_unknown_suffix_does_not_make_the_shorter_title_eligible(): void
    {
        $evidence = new OriginalFilenameEvidence('Exact.Release-GRP.unknown');
        self::assertTrue(method_exists($evidence, 'canonicalNominationKeys'));
        self::assertSame(['exact.release-grp.unknown'], $evidence->canonicalNominationKeys());
    }
}

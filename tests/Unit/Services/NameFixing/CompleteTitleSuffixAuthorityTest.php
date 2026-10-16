<?php

declare(strict_types=1);

namespace Tests\Unit\Services\NameFixing;

use App\Services\NameFixing\OriginalFilenameEvidence;
use PHPUnit\Framework\TestCase;

final class CompleteTitleSuffixAuthorityTest extends TestCase
{
    public function test_non_ascii_lowercase_cannot_create_a_raw_suffix_boundary(): void
    {
        self::assertFalse((new OriginalFilenameEvidence('İ.Exact.Release.rar'))->accepts('i'));
        self::assertTrue((new OriginalFilenameEvidence('İ.Exact.Release.rar'))->accepts('i̇.Exact.Release'));
        self::assertFalse((new OriginalFilenameEvidence('Exact.Release.K.rar'))->accepts('Exact.Release'));
    }

    public function test_raw_numeric_alias_does_not_become_meaningful_through_its_extension(): void
    {
        self::assertFalse((new OriginalFilenameEvidence('123456.mkv'))->accepts('Different.Canonical', '123456.mkv'));
    }

    public function test_high_dot_valid_invalid_payload_and_unicode_parity_authority(): void
    {
        foreach ([16, 64, 256, 1024] as $count) {
            $title = 'Exact.Release'.str_repeat('.x', $count);
            self::assertTrue((new OriginalFilenameEvidence($title.'.rar'))->accepts($title));
            self::assertFalse((new OriginalFilenameEvidence($title.'.unknown'))->accepts($title));
            self::assertTrue((new OriginalFilenameEvidence($title.'.flac.mkv'))->accepts($title.'.flac'));
            self::assertFalse((new OriginalFilenameEvidence($title.'.flac.mkv'))->accepts($title));
            $encoded = 'Outer__NZBSPLIT__abcdef01__NZBSPLIT__İ_'.str_replace('.', '_', $title).'.vol00+01.par2';
            self::assertTrue((new OriginalFilenameEvidence($encoded))->accepts('i̇.'.$title));
            $archives = new OriginalFilenameEvidence('Exact.Release'.str_repeat('.rar', $count));
            self::assertTrue($archives->accepts('Exact.Release'));
            self::assertTrue($archives->accepts('Exact.Release.rar'));
        }
    }
}

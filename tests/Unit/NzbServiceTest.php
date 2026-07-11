<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Nzb\NzbService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class NzbServiceTest extends TestCase
{
    public function test_build_binary_subject_preserves_separator_for_quoted_names(): void
    {
        $reflection = new ReflectionClass(NzbService::class);
        $service = $reflection->newInstanceWithoutConstructor();
        $method = $reflection->getMethod('buildBinarySubject');

        $subject = $method->invoke($service, '"Example.Release.part01.rar" yEnc', 12);

        $this->assertSame('"Example.Release.part01.rar" yEnc (1/12)', $subject);
        $this->assertStringNotContainsString('" yEnc(1/12)', $subject);
        $this->assertStringNotContainsString('"(1/12)', $subject);
    }

    public function test_build_binary_subject_trims_trailing_whitespace_before_part_suffix(): void
    {
        $reflection = new ReflectionClass(NzbService::class);
        $service = $reflection->newInstanceWithoutConstructor();
        $method = $reflection->getMethod('buildBinarySubject');

        $subject = $method->invoke($service, 'Example.Release.rar yEnc   ', 3);

        $this->assertSame('Example.Release.rar yEnc (1/3)', $subject);
    }

    public function test_normalize_segment_message_id_strips_outer_quotes_and_brackets(): void
    {
        $reflection = new ReflectionClass(NzbService::class);
        $service = $reflection->newInstanceWithoutConstructor();
        $method = $reflection->getMethod('normalizeSegmentMessageId');

        $messageId = $method->invoke($service, '"<part01.abcd@example.test>"');

        $this->assertSame('part01.abcd@example.test', $messageId);
    }

    public function test_normalize_segment_message_id_keeps_bare_message_id_as_is(): void
    {
        $reflection = new ReflectionClass(NzbService::class);
        $service = $reflection->newInstanceWithoutConstructor();
        $method = $reflection->getMethod('normalizeSegmentMessageId');

        $messageId = $method->invoke($service, 'part01.abcd@example.test');

        $this->assertSame('part01.abcd@example.test', $messageId);
    }
}

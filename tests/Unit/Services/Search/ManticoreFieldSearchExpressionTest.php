<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Search;

use App\Services\Search\Drivers\ManticoreSearchDriver;
use PHPUnit\Framework\TestCase;

final class ManticoreFieldSearchExpressionTest extends TestCase
{
    /**
     * `@@relaxed` is a query-wide modifier, valid once at the start. Repeating it before each field
     * (`@@relaxed @name (..) @@relaxed @searchname (..)`) made Manticore answer
     * "P08: syntax error, unexpected TOK_FIELDLIMIT", so every PreDB title match failed.
     */
    public function test_a_multi_field_search_has_a_single_leading_relaxed_modifier(): void
    {
        $expression = ManticoreSearchDriver::buildFieldSearchExpression([
            'name' => 'Some.Release.2026-GRP',
            'searchname' => 'Some.Release.2026-GRP',
        ]);

        $this->assertNotNull($expression);
        $this->assertSame(1, substr_count($expression, '@@relaxed'));
        $this->assertStringStartsWith('@@relaxed @name ', $expression);
        $this->assertStringContainsString(' @searchname ', $expression);
    }

    public function test_a_single_field_search_is_unchanged_and_empty_values_are_skipped(): void
    {
        $this->assertSame(
            '@@relaxed (@searchname (Some.Release.2026\\-GRP) | @searchname (Some Release 2026 GRP))',
            ManticoreSearchDriver::buildFieldSearchExpression(['name' => '', 'searchname' => 'Some.Release.2026-GRP', 'other' => null]),
        );
        $this->assertNull(ManticoreSearchDriver::buildFieldSearchExpression(['name' => '', 'searchname' => '']));
    }
}

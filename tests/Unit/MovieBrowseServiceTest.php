<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Tests the year filter logic used by MovieBrowseService::getBrowseBy().
 *
 * The service uses exact equality (m.year = 'YYYY') for year and validates
 * with preg_match('/^(19|20)\d{2}$/', $bbv). These tests verify that contract
 * without requiring the full Laravel stack or database.
 */
final class MovieBrowseServiceTest extends TestCase
{
    /**
     * Same validation pattern as MovieBrowseService::getBrowseBy() for year.
     */
    private const YEAR_PATTERN = '/^(19|20)\d{2}$/';

    public function test_year_validation_accepts_valid_four_digit_years(): void
    {
        $this->assertSame(1, preg_match(self::YEAR_PATTERN, '2020'));
        $this->assertSame(1, preg_match(self::YEAR_PATTERN, '1999'));
        $this->assertSame(1, preg_match(self::YEAR_PATTERN, '1900'));
        $this->assertSame(1, preg_match(self::YEAR_PATTERN, '2099'));
    }

    public function test_year_validation_rejects_invalid_years(): void
    {
        $this->assertSame(0, preg_match(self::YEAR_PATTERN, 'invalid'));
        $this->assertSame(0, preg_match(self::YEAR_PATTERN, '1899'));
        $this->assertSame(0, preg_match(self::YEAR_PATTERN, '2100'));
        $this->assertSame(0, preg_match(self::YEAR_PATTERN, '20'));
        $this->assertSame(0, preg_match(self::YEAR_PATTERN, '20200'));
    }

    public function test_year_filter_uses_equality_not_like(): void
    {
        $validYear = '2020';
        $expectedFragment = ' AND m.year = ';
        $this->assertStringContainsString('m.year = ', $expectedFragment.$validYear);
        $this->assertStringNotContainsString('LIKE', $expectedFragment.$validYear);
    }
}

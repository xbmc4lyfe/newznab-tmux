<?php

declare(strict_types=1);

namespace Tests\Unit\Predb;

use App\Services\IRCClient;
use App\Services\Predb\Irc\PreAnnounceParser;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class IrcFormattingParserTest extends TestCase
{
    /** @return array<string, array{string, string}> */
    public static function formattingCases(): array
    {
        return [
            'hex foreground' => ["PRE: [TV-HD] Example.\x04A1b2C3Subject-GRP", 'PRE: [TV-HD] Example.Subject-GRP'],
            'hex foreground and background' => ["PRE: [TV-HD] Example.Subject-\x04A1b2C3,0F1e2DGRP", 'PRE: [TV-HD] Example.Subject-GRP'],
            'hex reset' => ["PRE: [TV-HD] Example.Subject-\x04GRP", 'PRE: [TV-HD] Example.Subject-GRP'],
            'strikethrough keyword and group' => ["P\x1eRE: [TV-HD] Example.Subject-G\x1eRP", 'PRE: [TV-HD] Example.Subject-GRP'],
            'literal hexadecimal title' => ['PRE: [TV-HD] A1b2C3.Subject-GRP', 'PRE: [TV-HD] A1b2C3.Subject-GRP'],
            'literal numeric title' => ['PRE: [TV-HD] 123456.Subject-GRP', 'PRE: [TV-HD] 123456.Subject-GRP'],
            'decimal foreground consumes only two digits' => ["PRE: [TV-HD] \x03123456.Subject-GRP", 'PRE: [TV-HD] 3456.Subject-GRP'],
            'hex foreground consumes exactly six digits' => ["PRE: [TV-HD] \x0412345678.Subject-GRP", 'PRE: [TV-HD] 78.Subject-GRP'],
            'hex incomplete parameter preserves suffix' => ["PRE: [TV-HD] \x04ABC.Subject-GRP", 'PRE: [TV-HD] ABC.Subject-GRP'],
            'unsupported control preserved' => ["PRE: [TV-HD] Example.\x07Subject-GRP", "PRE: [TV-HD] Example.\x07Subject-GRP"],
        ];
    }

    #[DataProvider('formattingCases')]
    public function test_actual_sanitizer_then_actual_parser(string $raw, string $expected): void
    {
        $client = new class extends IRCClient
        {
            public function sanitize(string $raw): string
            {
                return $this->_stripControlCharacters($raw);
            }
        };
        $sanitized = $client->sanitize($raw);
        self::assertSame($expected, $sanitized);
        $receivedAt = CarbonImmutable::parse('2026-10-14 09:00:00', 'UTC');
        $entry = (new PreAnnounceParser('opentrackers', 'fixture'))->parse($sanitized, $receivedAt);
        if (str_contains($expected, "\x07")) {
            self::assertNull($entry);

            return;
        }
        self::assertNotNull($entry);
        self::assertSame(substr($expected, strlen('PRE: [TV-HD] ')), $entry->title);
        self::assertSame('TV-HD', $entry->category);
        self::assertNull($entry->predate);
        self::assertTrue($receivedAt->equalTo($entry->listedAt));
        self::assertSame('opentrackers-relay', $entry->source);
    }
}

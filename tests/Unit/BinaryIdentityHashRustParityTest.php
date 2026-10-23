<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Binaries\BinaryHandler;
use PHPUnit\Framework\TestCase;

final class BinaryIdentityHashRustParityTest extends TestCase
{
    public function test_binary_identity_hash_single_and_batch_match_php_reference(): void
    {
        $handler = new BinaryHandler;

        $records = [
            10 => [
                'header' => ['matches' => [1 => 'Show.S01E01.1080p [01/62] - "show.rar" yEnc'], 'From' => 'poster@example.com'],
                'file_number' => 1,
            ],
            25 => [
                'header' => ['matches' => [1 => 'Show.S01E01.1080p [01/62] - "show.rar" yEnc'], 'From' => 'poster@example.com'],
                'file_number' => 1,
            ],
            30 => [
                'header' => ['matches' => [1 => '   Unnumbered   Subject   With   Spaces   yEnc  '], 'From' => "  Poster@Usenet.ORG\t(Poster) "],
                'file_number' => 0,
            ],
            42 => [
                'header' => ['matches' => [1 => "Tab\tIn\tSubject yEnc"], 'From' => 'user@example.com'],
                'file_number' => 0,
            ],
            55 => [
                'header' => ['matches' => [1 => 'UTF8 Café Édition yEnc'], 'From' => 'Müllér <muller@example.com>'],
                'file_number' => 0,
            ],
            77 => [
                'header' => ['matches' => [1 => ''], 'From' => ''],
                'file_number' => 0,
            ],
            99 => [
                'header' => ['matches' => [1 => "Invalid UTF-8 \xFF\xFE byte sequence"], 'From' => 'poster@example.com'],
                'file_number' => 0,
            ],
        ];

        foreach ($records as $index => $record) {
            $this->assertSame(
                $handler->identityHashPhp($record['header'], $record['file_number']),
                $handler->identityHash($record['header'], $record['file_number'], true),
                "Single identityHash mismatch at index {$index}"
            );
        }

        $this->assertSame(
            $handler->identityHashesBatch($records, false),
            $handler->identityHashesBatch($records, true)
        );

        $asciiList = array_values(array_slice($records, 0, 4));
        $this->assertSame(
            $handler->identityHashesBatch($asciiList, false),
            $handler->identityHashesBatch($asciiList, true)
        );

        $withEmbeddedNul = [
            [
                'header' => ['matches' => [1 => "Embedded\0Nul yEnc"], 'From' => 'poster@example.com'],
                'file_number' => 0,
            ],
            [
                'header' => ['matches' => [1 => 'Normal Subject yEnc'], 'From' => 'poster@example.com'],
                'file_number' => 0,
            ],
        ];
        $this->assertSame(
            $handler->identityHashesBatch($withEmbeddedNul, false),
            $handler->identityHashesBatch($withEmbeddedNul, true)
        );
    }
}

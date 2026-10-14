<?php

declare(strict_types=1);

namespace Tests\Unit\Services\NameFixing;

use App\Services\NameFixing\FileNameCleaner;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class FileNameMatchingReuseTest extends TestCase
{
    public function test_music_and_predb_modes_remain_distinct_in_a_shared_lookup(): void
    {
        $cleaner = new FileNameCleaner;
        $cleaner->withMatchingReuse(function () use ($cleaner): void {
            for ($attempt = 0; $attempt < 2; $attempt++) {
                self::assertSame('Artist.Track', $cleaner->cleanForMatching('01.Artist.Track.flac'));
                self::assertSame('01.Artist.Track', $cleaner->cleanForMatching('01.Artist.Track.flac', true));
                self::assertFalse($cleaner->cleanForMatching('123.mkv', true));
                self::assertSame('Synthetic.Alias', $cleaner->cleanForMatching('Synthetic.Alias.mkv', true));
            }
        });
    }

    public function test_custom_stateful_cleaner_retains_each_call_in_nested_lookups(): void
    {
        $cleaner = new class extends FileNameCleaner
        {
            private int $calls = 0;

            public function cleanForMatching(string $fileName, bool $preserveLeadingNumber = false): string
            {
                return $fileName.'.'.++$this->calls;
            }
        };

        $cleaner->withMatchingReuse(function () use ($cleaner): void {
            self::assertSame('same.1', $cleaner->cleanForMatching('same'));
            $cleaner->withMatchingReuse(function () use ($cleaner): void {
                self::assertSame('same.2', $cleaner->cleanForMatching('same'));
                self::assertSame('same.3', $cleaner->cleanForMatching('same'));
            }, joinActiveLookup: true);
            self::assertSame('same.4', $cleaner->cleanForMatching('same'));
        });
        self::assertSame('same.5', $cleaner->cleanForMatching('same'));
    }

    public function test_nested_exception_propagates_and_parent_lookup_can_continue(): void
    {
        $cleaner = new FileNameCleaner;
        self::assertSame('outer result', $cleaner->withMatchingReuse(function () use ($cleaner): string {
            self::assertSame('Synthetic.Alias', $cleaner->cleanForMatching('Synthetic.Alias.mkv', true));
            try {
                $cleaner->withMatchingReuse(function () use ($cleaner): never {
                    self::assertFalse($cleaner->cleanForMatching('123.mkv', true));
                    throw new RuntimeException('synthetic lookup failure');
                });
            } catch (RuntimeException $exception) {
                self::assertSame('synthetic lookup failure', $exception->getMessage());
            }
            self::assertSame('Synthetic.Alias', $cleaner->cleanForMatching('Synthetic.Alias.mkv', true));

            return 'outer result';
        }));
        self::assertSame('Synthetic.Other', $cleaner->withMatchingReuse(fn (): string|false => $cleaner->cleanForMatching('Synthetic.Other.mkv', true)));
    }
}

<?php

declare(strict_types=1);

namespace Tests\Unit\Services\NameFixing;

use App\Services\NameFixing\Data\FilenamePass;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FilenamePassTest extends TestCase
{
    public static function malformedObservations(): array
    {
        $match = ['id' => 7, 'title' => 'Synthetic.Release-GRP'];
        $lookup = ['filename' => 'a.mkv', 'match' => $match, 'evidence' => true, 'complete' => true];

        return [
            'missing' => [['a.mkv', 'b.mkv'], [$lookup]],
            'undeclared' => [[], [$lookup]],
            'reordered' => [['b.mkv', 'a.mkv'], [$lookup, [...$lookup, 'filename' => 'b.mkv']]],
            'truthy complete' => [['a.mkv'], [[...$lookup, 'complete' => 'UNKNOWN']]],
            'truthy evidence' => [['a.mkv'], [[...$lookup, 'evidence' => 1]]],
            'nonarray match' => [['a.mkv'], [[...$lookup, 'match' => 'invalid']]],
            'invalid UTF-8 title' => [['a.mkv'], [[...$lookup, 'match' => ['id' => 7, 'title' => "\xff"]]]],
            'invalid optional filename' => [['a.mkv'], [[...$lookup, 'match' => [...$match, 'filename' => 1]]]],
            'invalid match' => [['a.mkv'], [[...$lookup, 'match' => ['id' => 0, 'title' => '']]]],
            'missing field' => [['a.mkv'], [['filename' => 'a.mkv', 'match' => null, 'complete' => true]]],
            'associative names' => [[1 => 'a.mkv'], [$lookup]],
            'associative lookups' => [['a.mkv'], [1 => $lookup]],
            'nonstring name' => [[7], [[...$lookup, 'filename' => 7]]],
        ];
    }

    #[DataProvider('malformedObservations')]
    public function test_malformed_observations_are_rejected(array $filenames, array $lookups): void
    {
        $this->expectException(InvalidArgumentException::class);
        new FilenamePass($filenames, $lookups);
    }

    public function test_empty_observations_remain_complete(): void
    {
        $pass = new FilenamePass([], []);
        self::assertTrue($pass->complete());
        self::assertFalse($pass->hasMatches());
        self::assertSame([], $pass->uniqueMatches());
    }

    public function test_order_and_duplicate_agreeing_witnesses_are_preserved(): void
    {
        $match = ['id' => 7, 'title' => 'Synthetic.Release-GRP'];
        $names = ['b.mkv', 'a.mkv', 'a.mkv'];
        $lookups = array_map(static fn (string $name): array => ['filename' => $name, 'match' => $match, 'evidence' => true, 'complete' => true], $names);
        $pass = new FilenamePass($names, $lookups);
        self::assertTrue($pass->complete());
        self::assertSame($names, array_column($pass->uniqueMatches(), 'filename'));
    }

    public function test_numeric_string_candidates_are_validated_without_rewriting_them(): void
    {
        $match = ['id' => '0007', 'title' => 'Synthetic.Release-GRP'];
        $pass = new FilenamePass(['a.mkv'], [['filename' => 'a.mkv', 'match' => $match, 'evidence' => true, 'complete' => true]]);
        self::assertSame($match, $pass->uniqueMatches()[0]['match']);
    }

    public function test_unknown_and_conflicting_observations_remain_unselectable(): void
    {
        $first = ['filename' => 'a.mkv', 'match' => ['id' => 7, 'title' => 'Synthetic.Release-GRP'], 'evidence' => true, 'complete' => true];
        $unknown = new FilenamePass(['a.mkv'], [[...$first, 'complete' => false]]);
        self::assertFalse($unknown->complete());
        self::assertSame([], $unknown->uniqueMatches());
        $conflict = new FilenamePass(['a.mkv', 'b.mkv'], [$first, [...$first, 'filename' => 'b.mkv', 'match' => ['id' => 8, 'title' => 'Synthetic.Other-GRP']]]);
        self::assertTrue($conflict->complete());
        self::assertSame([], $conflict->uniqueMatches());
    }
}

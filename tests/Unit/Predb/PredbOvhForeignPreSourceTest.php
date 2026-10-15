<?php

declare(strict_types=1);

namespace Tests\Unit\Predb;

use App\Services\Predb\Feeds\Sources\PredbOvhSource;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PredbOvhForeignPreSourceTest extends TestCase
{
    /** @param array<string, mixed> $ids */
    #[Test]
    #[DataProvider('identityCases')]
    public function explicit_valid_foreign_ids_suppress_only_the_event(array $ids, bool $foreign, int $type): void
    {
        $row = ['name' => ' Synthetic.Identity.Release-TEST ', 'cat' => 'SYNTHETIC', 'size' => 2048, 'files' => 7, 'preAt' => 1700000000,
            'nuke' => ['id' => 900, 'typeId' => $type, 'reason' => 'synthetic reason', 'nukeAt' => 1700000001]];
        if (array_key_exists('row', $ids)) {
            $row['id'] = $ids['row'];
        }
        if (array_key_exists('pre', $ids)) {
            $row['nuke']['preId'] = $ids['pre'];
        }
        $entries = (new PredbOvhSource('https://synthetic.invalid/api/v1/'))->parseRows([$row]);
        $this->assertCount(1, $entries);
        $entry = $entries[0];
        $this->assertSame('Synthetic.Identity.Release-TEST', $entry->title);
        $this->assertSame('predb.ovh', $entry->source);
        $this->assertSame('SYNTHETIC', $entry->category);
        $this->assertSame('2MB', $entry->size);
        $this->assertSame('7', $entry->files);
        $this->assertSame(1700000000, $entry->predate?->getTimestamp());
        $this->assertSame($foreign ? 0 : [1 => 2, 2 => 1, 3 => 3, 4 => 2, 5 => 1][$type], $entry->nuked);
        $this->assertSame($foreign ? null : 'synthetic reason', $entry->nukeReason);
    }

    /** @return iterable<string, array{array<string, mixed>, bool, int}> */
    public static function identityCases(): iterable
    {
        $cases = [
            'equal integers' => [['row' => 100, 'pre' => 100], false],
            'foreign integers' => [['row' => 100, 'pre' => 101], true],
            'equal strings' => [['row' => '100', 'pre' => '100'], false],
            'foreign strings' => [['row' => '100', 'pre' => '101'], true],
            'equal mixed' => [['row' => 100, 'pre' => '100'], false],
            'foreign mixed' => [['row' => '100', 'pre' => 101], true],
            'equal leading zeros' => [['row' => '000100', 'pre' => 100], false],
            'foreign leading zeros' => [['row' => '000100', 'pre' => '00101'], true],
            'equal signed maximum' => [['row' => PHP_INT_MAX, 'pre' => (string) PHP_INT_MAX], false],
            'foreign signed maximum' => [['row' => PHP_INT_MAX, 'pre' => '9223372036854775806'], true],
            'equal unsigned maximum' => [['row' => '18446744073709551615', 'pre' => '18446744073709551615'], false],
            'foreign unsigned maximum' => [['row' => '18446744073709551615', 'pre' => '18446744073709551614'], true],
            'foreign beyond signed range' => [['row' => '9223372036854775808', 'pre' => '9223372036854775809'], true],
            'missing row' => [['pre' => 101], false],
            'missing event pre' => [['row' => 100], false],
            'missing both' => [[], false],
        ];
        $invalid = ['null' => null, 'true' => true, 'false' => false, 'zero' => 0, 'negative' => -1,
            'integral float' => 100.0, 'fractional float' => 100.5, 'empty' => '', 'space' => ' ',
            'positive sign' => '+100', 'negative string' => '-100', 'exponent' => '1e2', 'decimal' => '100.0',
            'suffix' => '100x', 'overflow' => '18446744073709551616', 'array' => [], 'object-like array' => ['id' => 100]];
        foreach ($invalid as $label => $value) {
            $cases['invalid row '.$label] = [['row' => $value, 'pre' => 101], false];
            $cases['invalid event pre '.$label] = [['row' => 100, 'pre' => $value], false];
        }
        foreach ($cases as $label => [$ids, $foreign]) {
            foreach (range(1, 5) as $type) {
                yield $label.' type '.$type => [$ids, $foreign, $type];
            }
        }
    }

    #[Test]
    public function ordinary_json_decode_precision_loss_remains_a_compatibility_limit(): void
    {
        // Distinct raw uint64 numerals collapse to the same float before adapter parsing.
        $row = json_decode('{"id":18446744073709551615,"name":"Synthetic.Identity.Release-TEST","nuke":{"preId":18446744073709551614,"typeId":1,"reason":"synthetic reason"}}', true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsFloat($row['id']);
        $this->assertSame($row['id'], $row['nuke']['preId']);
        $entry = (new PredbOvhSource('https://synthetic.invalid/api/v1/'))->parseRows([$row])[0];
        $this->assertSame(2, $entry->nuked);
        $this->assertSame('synthetic reason', $entry->nukeReason);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Unit\Predb;

use App\Services\Predb\PredbIdentitySchemaAttestation;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PredbIdentitySqlDefaultTest extends TestCase
{
    public static function literals(): array
    {
        return [
            [null, 'absent'], ['0', 'zero'], ["'0'", 'zero'], ['(0)', 'zero'], ['((0))', 'zero'],
            [" ( '0' ) ", 'zero'], ["''", 'empty'], ["('')", 'empty'], ['NULL', 'null'], ['(null)', 'null'],
            ["'NULL'", null], ['"0"', null], ['+0', null], ['-0', null], ['0.0', null], ["'00'", null],
            ['0+0', null], ['(0+0)', null], ['0 -- ignored', null], ['0/*ignored*/', null],
            ["0\0", null], ['(0))', null], ['((0)', null], ['0;SELECT 1', null],
            [str_repeat('(', 9).'0'.str_repeat(')', 9), null], [str_repeat(' ', 65), null], [0, null],
        ];
    }

    #[DataProvider('literals')]
    public function test_metadata_accepts_only_bounded_literal_defaults(mixed $raw, ?string $expected): void
    {
        self::assertSame($expected, (new PredbIdentitySchemaAttestation)->defaultKind($raw));
    }
}

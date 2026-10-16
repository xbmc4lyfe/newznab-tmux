<?php

declare(strict_types=1);

namespace Tests\Unit\Predb;

use App\Services\Predb\PredbIdentitySchema;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PredbIdentityTriggerSpecificationTest extends TestCase
{
    public static function triggers(): array
    {
        $sqlite = ['insert' => 'f12f3b32fc488e36a3be7bcb0c0bc2f22cdce7c8b23c1a5e3dad5dd43e75b38c',
            'update' => '5986861c16609f8218964ec944433c1e18c2368919a6dffd416880da33848039',
            'delete' => 'e8d93e1b255bba110a364a58b2c89d45c551531ab15db7e2dfe8657880ba4d5b'];
        $mysql = ['insert' => '985aad68ff1cd9c7d3a260535720494f605eef16ce69157e0bb69736b839c368',
            'update' => '51ace34a051e40b46875fb78a3830e9bf6ded6f9f38a20e9962a54fd2743cf47',
            'delete' => '3cf78977293b8caac8f8282041b6b940854a8203ea7536367538f6ab19c8674e'];
        $cases = [];
        foreach (['sqlite' => $sqlite, 'mysql' => $mysql, 'mariadb' => $mysql] as $driver => $hashes) {
            foreach ($hashes as $event => $hash) {
                $cases[$driver.' '.$event] = [$driver, $event, $hash];
            }
        }

        return $cases;
    }

    #[DataProvider('triggers')]
    public function test_structured_trigger_keeps_the_frozen_existing_definition(string $driver, string $event, string $hash): void
    {
        $schema = new PredbIdentitySchema;
        $name = 'predb_identity_'.$event;
        $specification = $schema->specifications($driver)[$name];
        self::assertSame($name, $specification['name']);
        self::assertSame('predb', $specification['table']);
        self::assertSame('AFTER', $specification['timing']);
        self::assertSame(strtoupper($event), $specification['event']);
        self::assertSame('ROW', $specification['orientation']);
        self::assertStringStartsWith('BEGIN ', $specification['action_statement']);
        self::assertStringEndsWith('END', $specification['action_statement']);
        self::assertSame($hash, hash('sha256', $schema->definitions($driver)[$name]));
        if ($driver === 'sqlite' && $event === 'update') {
            self::assertStringContainsString('OLD.filename COLLATE BINARY', $specification['when']);
        } else {
            self::assertNull($specification['when']);
        }
        if ($driver !== 'sqlite' && $event === 'update') {
            self::assertStringContainsString('CAST(OLD.filename AS BINARY)', $specification['action_statement']);
        }
    }
}

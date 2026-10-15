<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Releases\Verification\ManifestStore;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class ManifestOccupiedCodecTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('testing', config('database.default'));
        $this->directory = sys_get_temp_dir().'/manifest-codec-'.bin2hex(random_bytes(12));
        config(['release_verification.storage_path' => $this->directory]);
        mkdir($this->directory.'/manifests', 0700, true);
    }

    protected function tearDown(): void
    {
        app('files')->deleteDirectory($this->directory);
        parent::tearDown();
    }

    #[DataProvider('validEncodings')]
    public function test_public_write_reuses_equivalent_gzip_without_replacing_existing_bytes(int $level, bool $filename): void
    {
        $store = new ManifestStore;
        $manifest = ['identity' => 'codec-test', 'files' => [], 'note' => str_repeat('compressible ', 200)];
        $prepared = $store->prepare($manifest);
        $json = json_encode($manifest, JSON_THROW_ON_ERROR);
        $body = gzencode($json, $level);
        $this->assertIsString($body);
        if ($filename) {
            $body = substr($body, 0, 3).chr(ord($body[3]) | 8).substr($body, 4, 6)."inventory.json\0".substr($body, 10);
        }
        $path = $store->path($prepared->name);
        file_put_contents($path, $body);
        $this->assertSame($prepared->name, $store->writePrepared($prepared));
        $this->assertSame($body, file_get_contents($path));
        $this->assertSame($manifest, $store->read($prepared->name));
        $this->assertSame([$prepared->name], array_values(array_diff(scandir($this->directory.'/manifests'), ['.', '..'])));
    }

    /** @return array<string, array{int, bool}> */
    public static function validEncodings(): array
    {
        return ['stored' => [0, false], 'maximum compression' => [9, false], 'optional filename' => [9, true]];
    }

    #[DataProvider('invalidEncodings')]
    public function test_public_write_rejects_invalid_occupied_target_and_preserves_it(string $kind): void
    {
        $store = new ManifestStore;
        $manifest = ['identity' => 'codec-test', 'files' => []];
        $prepared = $store->prepare($manifest);
        $body = gzencode(json_encode($manifest, JSON_THROW_ON_ERROR), 0);
        $this->assertIsString($body);
        $body = match ($kind) {
            'truncated trailer' => substr($body, 0, -1),
            'wrong crc' => substr_replace($body, chr(ord($body[strlen($body) - 8]) ^ 1), -8, 1),
            'wrong json' => gzencode(json_encode(['identity' => 'wrong-test', 'files' => []], JSON_THROW_ON_ERROR)),
            'extra member' => $body.gzencode('{}'),
            'trailing bytes' => $body.'trailing',
            'compressed cap' => str_repeat('x', 70000),
            'expansion bomb' => gzencode(str_repeat('x', 1048576), 9),
            default => throw new \InvalidArgumentException('Unknown gzip test case.'),
        };
        $this->assertIsString($body);
        $path = $store->path($prepared->name);
        file_put_contents($path, $body);
        $rejected = false;
        try {
            $store->writePrepared($prepared);
        } catch (RuntimeException $exception) {
            $rejected = true;
            $this->assertSame('Manifest integrity check failed.', $exception->getMessage());
        }
        $this->assertTrue($rejected, 'An invalid occupied manifest was accepted.');
        $this->assertSame($body, file_get_contents($path));
        $this->assertSame([$prepared->name], array_values(array_diff(scandir($this->directory.'/manifests'), ['.', '..'])));
    }

    /** @return array<string, array{string}> */
    public static function invalidEncodings(): array
    {
        $cases = ['truncated trailer', 'wrong crc', 'wrong json', 'extra member', 'trailing bytes', 'compressed cap', 'expansion bomb'];

        return array_combine($cases, array_map(static fn (string $case): array => [$case], $cases));
    }
}

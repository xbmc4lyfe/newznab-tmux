<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Releases\Verification\NativeSpotCheckPlanner;
use App\Services\Releases\Verification\SpotCheckPlanner;
use JsonSerializable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class NativeSpotCheckPlannerTest extends TestCase
{
    #[Test]
    public function it_requires_at_least_1024_articles(): void
    {
        self::assertFalse(NativeSpotCheckPlanner::supportsInventory($this->manifest(1023)));
        self::assertTrue(NativeSpotCheckPlanner::supportsInventory($this->manifest(1024)));
        self::assertTrue(NativeSpotCheckPlanner::supportsInventory($this->manifest(1025)));
    }

    #[Test]
    public function it_rejects_coercible_consumed_values_before_serialization(): void
    {
        $native = $this->nativePlanner();
        $manifest = $this->manifest();
        foreach (['100', 100.0, 100.9, new \stdClass, null] as $bytes) {
            $candidate = $manifest;
            $candidate['files'][0]['segments'][0]['bytes'] = $bytes;
            self::assertNull($native->select($candidate, 8, 1000));
        }
        foreach (['1', 1.0, 1.9, new \stdClass, null] as $number) {
            $candidate = $manifest;
            $candidate['files'][0]['segments'][0]['number'] = $number;
            self::assertNull($native->select($candidate, 8, 1000));
        }
        foreach (['128', 128.0, new \stdClass] as $totalparts) {
            $candidate = $manifest;
            $candidate['files'][0]['totalparts'] = $totalparts;
            self::assertNull($native->select($candidate, 8, 1000));
        }
        $manifest['identity'] = new class
        {
            public function __toString(): string
            {
                throw new \LogicException('Native admission must not stringify objects.');
            }
        };
        self::assertNull($native->select($manifest, 8, 1000));
    }

    #[Test]
    public function it_does_not_serialize_unused_manifest_metadata(): void
    {
        $manifest = $this->manifest();
        $hook = new class implements JsonSerializable
        {
            public function jsonSerialize(): mixed
            {
                throw new \LogicException('Unused metadata must not be serialized.');
            }
        };
        $manifest['metadata'] = $hook;
        $manifest['files'][0]['unused'] = $hook;
        $manifest['files'][0]['segments'][0]['unused'] = $hook;

        self::assertTrue(NativeSpotCheckPlanner::supportsInventory($manifest));
        self::assertNull((new NativeSpotCheckPlanner('/missing/native/library.so'))->select($manifest, 8, 1000));
    }

    #[Test]
    public function it_falls_back_for_missing_unreadable_and_non_sampler_libraries(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'nntmux-native-test-');
        self::assertNotFalse($path);
        try {
            file_put_contents($path, 'This is not the versioned sampler ABI.');
            self::assertNull((new NativeSpotCheckPlanner($path))->select($this->manifest(), 8, 1000));
            chmod($path, 0000);
            self::assertNull((new NativeSpotCheckPlanner($path))->select($this->manifest(), 8, 1000));
            self::assertNull((new NativeSpotCheckPlanner($path.'-missing'))->select($this->manifest(), 8, 1000));
        } finally {
            chmod($path, 0600);
            unlink($path);
        }
    }

    #[Test]
    public function it_falls_back_when_an_existing_library_lacks_the_versioned_sampler_abi(): void
    {
        if (PHP_OS_FAMILY !== 'Linux') {
            self::markTestSkipped('ABI loading is supported only on Linux.');
        }
        $path = getenv('NNTMUX_TEST_OLD_NATIVE_LIBRARY');
        if ($path === false || $path === '') {
            $path = '/lib/x86_64-linux-gnu/libc.so.6';
        }
        if (! is_file($path)) {
            self::markTestSkipped('No existing library lacking the versioned sampler ABI is available.');
        }

        self::assertNull((new NativeSpotCheckPlanner($path))->select($this->manifest(), 8, 1000));
    }

    #[Test]
    public function it_delegates_samples_larger_than_the_native_batch_limit_to_php(): void
    {
        $native = $this->nativePlanner();
        $manifest = $this->manifest();
        $expected = (new SpotCheckPlanner(new NativeSpotCheckPlanner('/missing/native/library.so')))->select($manifest, 17, 2000);

        self::assertCount(17, $expected);
        self::assertNull($native->select($manifest, 17, 2000));
        self::assertSame($expected, (new SpotCheckPlanner($native))->select($manifest, 17, 2000));
    }

    #[Test]
    public function it_matches_php_for_ties_duplicates_inferred_endpoints_and_budgets(): void
    {
        $native = $this->nativePlanner();
        $manifest = $this->manifest();
        $manifest['files'][0]['segments'][0]['messageid'] = 'shared';
        $manifest['files'][0]['segments'][1]['messageid'] = 'shared';
        $manifest['files'][0]['segments'][1]['bytes'] = 200;
        $manifest['files'][1]['segments'][0]['messageid'] = 'shared';
        $manifest['files'][2]['totalparts_inferred'] = true;
        $manifest['files'][2]['segments'][127]['bytes'] = 101;
        $php = new SpotCheckPlanner(new NativeSpotCheckPlanner('/missing/native/library.so'));

        foreach ([[1, 1000, null], [8, 1000, null], [16, 350, null], [16, 1000, 100],
            [16, 200000, null], [0, 1000, null], [8, 0, null], [8, 1000, 0]] as [$articles, $bytes, $articleBytes]) {
            $expected = $php->select($manifest, $articles, $bytes, $articleBytes);
            self::assertSame($expected, $native->select($manifest, $articles, $bytes, $articleBytes));
        }
    }

    #[Test]
    public function it_preserves_missing_optional_fields_and_rejects_non_list_inventories(): void
    {
        $native = $this->nativePlanner();
        $manifest = $this->manifest();
        unset($manifest['identity'], $manifest['files'][0]['totalparts'], $manifest['files'][0]['totalparts_inferred']);
        $manifest['files'][1]['totalparts'] = null;
        $manifest['files'][1]['totalparts_inferred'] = null;
        $php = new SpotCheckPlanner(new NativeSpotCheckPlanner('/missing/native/library.so'));

        self::assertSame($php->select($manifest, 8, 1000), $native->select($manifest, 8, 1000));
        $manifest['files'][0]['segments'] = array_combine(
            array_map(static fn (int $number): string => 'part-'.$number, range(1, 128)),
            $manifest['files'][0]['segments'],
        );
        self::assertNull($native->select($manifest, 8, 1000));
        self::assertSame($php->select($manifest, 8, 1000), (new SpotCheckPlanner($native))->select($manifest, 8, 1000));
    }

    #[Test]
    public function it_preserves_php_early_stop_after_a_round_adds_no_articles(): void
    {
        $native = $this->nativePlanner();
        $segments = [
            ['number' => 1024, 'messageid' => 'terminal', 'bytes' => 100],
            ['number' => 1, 'messageid' => 'early', 'bytes' => 100],
        ];
        for ($number = 2; $number <= 1023; $number++) {
            $segments[] = ['number' => $number, 'messageid' => 'oversize', 'bytes' => 1];
        }
        $manifest = ['identity' => 'early-stop', 'files' => [
            ['key' => 'file', 'totalparts' => 1024, 'totalparts_inferred' => true, 'segments' => $segments],
        ]];
        $php = new SpotCheckPlanner(new NativeSpotCheckPlanner('/missing/native/library.so'));
        $expected = $php->select($manifest, 4, 150);

        self::assertSame(['terminal'], array_column($expected, 'messageid'));
        self::assertSame($expected, $native->select($manifest, 4, 150));
    }

    #[Test]
    public function it_projects_typed_values_independently_of_serialize_precision_and_unused_hooks(): void
    {
        $native = $this->nativePlanner();
        $manifest = $this->manifest();
        $expected = $native->select($manifest, 8, 1000);
        self::assertNotNull($expected);
        $manifest['ignored'] = new class implements JsonSerializable
        {
            public function jsonSerialize(): mixed
            {
                throw new \LogicException('Unused metadata must not be serialized.');
            }
        };
        $precision = ini_get('serialize_precision');
        try {
            ini_set('serialize_precision', '2');
            self::assertSame($expected, $native->select($manifest, 8, 1000));
            $manifest['files'][0]['segments'][0]['bytes'] = 100.9;
            self::assertNull($native->select($manifest, 8, 1000));
        } finally {
            if ($precision !== false) {
                ini_set('serialize_precision', $precision);
            }
        }
    }

    #[Test]
    public function it_falls_back_before_a_repeated_key_can_expand_the_native_result(): void
    {
        $native = $this->nativePlanner();
        $manifest = $this->manifest();
        $manifest['files'] = [$manifest['files'][0]];
        $manifest['files'][0]['key'] = str_repeat('x', 100000);
        $manifest['files'][0]['segments'] = array_fill(0, 1024, ['number' => 1, 'messageid' => 'one', 'bytes' => 1]);
        $manifest['files'][0]['segments'][1] = ['number' => 2, 'messageid' => 'two', 'bytes' => 1];
        $php = new SpotCheckPlanner(new NativeSpotCheckPlanner('/missing/native/library.so'));

        self::assertNull($native->select($manifest, 2, 2));
        self::assertSame($php->select($manifest, 2, 2), (new SpotCheckPlanner($native))->select($manifest, 2, 2));
    }

    #[Test]
    public function it_preserves_php_integer_overflow_coercion_through_fallback(): void
    {
        $native = $this->nativePlanner();
        $manifest = $this->manifest();
        $parts = array_fill(0, 1024, ['number' => 1, 'messageid' => 'second', 'bytes' => 2]);
        $parts[0] = ['number' => 1024, 'messageid' => 'first', 'bytes' => PHP_INT_MAX];
        $manifest['files'] = [['key' => 'file', 'totalparts' => 1024,
            'totalparts_inferred' => true, 'segments' => $parts]];
        $php = new SpotCheckPlanner(new NativeSpotCheckPlanner('/missing/native/library.so'));
        $expected = $php->select($manifest, 2, PHP_INT_MAX);

        self::assertSame(['first', 'second'], array_column($expected, 'messageid'));
        self::assertNull($native->select($manifest, 2, PHP_INT_MAX));
        self::assertSame($expected, (new SpotCheckPlanner($native))->select($manifest, 2, PHP_INT_MAX));
    }

    #[Test]
    public function it_frees_malformed_and_over_budget_results_before_php_fallback(): void
    {
        $path = getenv('NNTMUX_TEST_BAD_NATIVE_LIBRARY');
        if (PHP_OS_FAMILY !== 'Linux' || $path === false || $path === '') {
            self::markTestSkipped('Set NNTMUX_TEST_BAD_NATIVE_LIBRARY to the isolated test ABI fixture.');
        }
        $counter = \FFI::cdef('int nntmux_test_free_count(void);', $path);
        $native = new NativeSpotCheckPlanner($path);
        $manifest = $this->manifest();
        $before = $this->freeCount($counter);

        self::assertNull($native->select($manifest, 4, 1001));
        self::assertSame($before + 2, $this->freeCount($counter));
        self::assertNull($native->select($manifest, 4, 1002));
        self::assertSame($before + 3, $this->freeCount($counter));
        $php = new SpotCheckPlanner(new NativeSpotCheckPlanner('/missing/native/library.so'));
        self::assertSame($php->select($manifest, 4, 1002), (new SpotCheckPlanner($native))->select($manifest, 4, 1002));
        self::assertSame($before + 4, $this->freeCount($counter));
    }

    private function freeCount(\FFI $counter): int
    {
        return $counter->nntmux_test_free_count(); // @phpstan-ignore method.notFound
    }

    private function nativePlanner(): NativeSpotCheckPlanner
    {
        $path = getenv('NNTMUX_TEST_NATIVE_LIBRARY');
        if (PHP_OS_FAMILY !== 'Linux' || $path === false || $path === '') {
            self::markTestSkipped('Set NNTMUX_TEST_NATIVE_LIBRARY to the versioned Linux sampler library.');
        }

        $planner = new NativeSpotCheckPlanner($path);
        self::assertNotNull($planner->select($this->manifest(), 1, 1000), 'The opted-in native library must load and select a sample.');

        return $planner;
    }

    /** @return array<string, mixed> */
    private function manifest(int $articles = 1024): array
    {
        $files = [];
        for ($index = 0; $index < 8; $index++) {
            $segments = [];
            for ($number = 1; $number <= 128 && $index * 128 + $number <= $articles; $number++) {
                $segments[] = ['number' => $number, 'messageid' => 'file-'.$index.'-'.$number, 'bytes' => 100];
            }
            $files[] = ['key' => 'file-'.$index, 'totalparts' => 128, 'totalparts_inferred' => false, 'segments' => $segments];
        }
        if ($articles > 1024) {
            $files[7]['segments'][] = ['number' => 129, 'messageid' => 'extra', 'bytes' => 100];
        }

        return ['identity' => 'native-regression', 'files' => $files];
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Releases\Verification\ManifestStore;
use App\Services\Releases\Verification\PreparationRunDiagnostics;
use JsonException;
use RuntimeException;
use Tests\TestCase;

class ManifestReadDiagnosticsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['release_verification.storage_path' => storage_path('framework/testing/read-phases-'.bin2hex(random_bytes(8)))]);
        mkdir(config('release_verification.storage_path').'/manifests', 0700, true);
    }

    protected function tearDown(): void
    {
        app('files')->deleteDirectory(config('release_verification.storage_path'));
        parent::tearDown();
    }

    public function test_diagnostics_preserve_decoded_metadata_and_do_not_leak_into_plain_reads(): void
    {
        $json = '{"identity":"private-inventory","files":[{"segments":[{"messageid":"private@article"}]}]}';
        $name = $this->fixture($json);
        $store = new ManifestStore;
        $diagnostics = new PreparationRunDiagnostics(hrtime(true));
        $this->assertSame(['identity' => 'private-inventory', 'files' => [['segments' => [['messageid' => 'private@article']]]]], $store->readWithDiagnostics($name, $diagnostics));
        $before = $this->report($diagnostics);
        $this->assertSame($store->read($name), $store->readWithDiagnostics($name, new PreparationRunDiagnostics(hrtime(true))));
        $this->assertSame($before['phase_distributions'], $this->report($diagnostics)['phase_distributions']);
        foreach (['file_read', 'gzip_decode', 'hash', 'json_decode'] as $phase) {
            $this->assertSame(1, $before['phase_distributions'][$phase]['count']);
            $this->assertSame(0, $before['phase_distributions'][$phase]['failures']);
        }
        $this->assertSame(1, $before['manifest_sizes']['compressed_bytes']['le_4096']);
        $this->assertSame(1, $before['manifest_sizes']['json_bytes']['le_4096']);
        $this->assertSame(1, $before['manifest_sizes']['files']['le_1']);
        $encoded = json_encode($before, JSON_THROW_ON_ERROR);
        foreach (['private-inventory', 'private@article', $name] as $private) {
            $this->assertStringNotContainsString($private, $encoded);
        }
    }

    public function test_hash_failure_records_its_phase_and_retains_the_integrity_exception(): void
    {
        $name = str_repeat('a', 64).'.json.gz';
        file_put_contents((new ManifestStore)->path($name), gzencode('{}'));
        $diagnostics = new PreparationRunDiagnostics(hrtime(true));
        try {
            (new ManifestStore)->readWithDiagnostics($name, $diagnostics);
            $this->fail('Mismatched content must remain rejected.');
        } catch (RuntimeException $error) {
            $this->assertSame('Manifest integrity check failed.', $error->getMessage());
        }
        $phases = $this->report($diagnostics)['phase_distributions'];
        $this->assertSame(1, $phases['hash']['failures']);
        $this->assertSame(0, $phases['json_decode']['count']);
    }

    public function test_json_failure_is_recorded_without_replacing_the_original_exception(): void
    {
        $diagnostics = new PreparationRunDiagnostics(hrtime(true));
        try {
            (new ManifestStore)->readWithDiagnostics($this->fixture('{invalid'), $diagnostics);
            $this->fail('Invalid JSON must remain rejected.');
        } catch (JsonException $error) {
            $this->assertSame(JSON_ERROR_SYNTAX, $error->getCode());
        }
        $this->assertSame(1, $this->report($diagnostics)['phase_distributions']['json_decode']['failures']);
        $this->assertSame(0, array_sum($this->report($diagnostics)['manifest_sizes']['files']));
    }

    public function test_nested_read_restores_outer_scope_and_preserves_file_warnings(): void
    {
        $store = new ManifestStore;
        $innerName = $this->fixture('{"files":[]}');
        $outer = new PreparationRunDiagnostics(hrtime(true));
        $inner = new PreparationRunDiagnostics(hrtime(true));
        $warnings = 0;
        set_error_handler(function (int $severity, string $message) use ($store, $innerName, $inner, &$warnings): bool {
            $this->assertSame(E_WARNING, $severity);
            $this->assertStringContainsString('file_get_contents', $message);
            $warnings++;
            $this->assertSame(['files' => []], $store->readWithDiagnostics($innerName, $inner));

            return true;
        });
        try {
            $store->readWithDiagnostics(str_repeat('b', 64).'.json.gz', $outer);
            $this->fail('A missing file must remain rejected.');
        } catch (RuntimeException $error) {
            $this->assertSame('Manifest integrity check failed.', $error->getMessage());
        } finally {
            restore_error_handler();
        }
        $store->read($innerName);
        $this->assertSame(1, $warnings);
        $this->assertSame(1, $this->report($outer)['phase_distributions']['file_read']['failures']);
        $this->assertSame(1, $this->report($outer)['phase_distributions']['file_read']['count']);
        $this->assertSame(1, $this->report($inner)['phase_distributions']['file_read']['count']);
        $this->assertSame(0, $this->report($outer)['phase_distributions']['gzip_decode']['count']);
    }

    public function test_gzip_warning_and_false_result_are_preserved_and_recorded(): void
    {
        $store = new ManifestStore;
        $name = str_repeat('c', 64).'.json.gz';
        file_put_contents($store->path($name), 'not gzip');
        $diagnostics = new PreparationRunDiagnostics(hrtime(true));
        $warnings = 0;
        set_error_handler(function (int $severity, string $message) use (&$warnings): bool {
            $this->assertSame(E_WARNING, $severity);
            $this->assertStringContainsString('gzdecode', $message);
            $warnings++;

            return true;
        });
        try {
            $store->readWithDiagnostics($name, $diagnostics);
            $this->fail('Invalid gzip must remain rejected.');
        } catch (RuntimeException $error) {
            $this->assertSame('Manifest integrity check failed.', $error->getMessage());
        } finally {
            restore_error_handler();
        }
        $this->assertSame(1, $warnings);
        $phases = $this->report($diagnostics)['phase_distributions'];
        $this->assertSame(1, $phases['gzip_decode']['failures']);
        $this->assertSame(0, $phases['hash']['count']);
        $this->assertSame(0, array_sum($this->report($diagnostics)['manifest_sizes']['json_bytes']));
    }

    public function test_throwing_file_warning_handler_preserves_the_exact_original_exception(): void
    {
        $original = new RuntimeException('Private warning converted to failure');
        $store = new ManifestStore;
        $diagnostics = new PreparationRunDiagnostics(hrtime(true));
        set_error_handler(static function () use ($original): never {
            throw $original;
        });
        try {
            $store->readWithDiagnostics(str_repeat('d', 64).'.json.gz', $diagnostics);
            $this->fail('The original warning exception must escape.');
        } catch (RuntimeException $error) {
            $this->assertSame($original, $error);
        } finally {
            restore_error_handler();
        }
        $this->assertSame(1, $this->report($diagnostics)['phase_distributions']['file_read']['failures']);
        $store->read($this->fixture('{}'));
        $this->assertSame(1, $this->report($diagnostics)['phase_distributions']['file_read']['count']);
    }

    public function test_invalid_reference_and_scalar_json_remain_rejected(): void
    {
        foreach (['invalid-private-name', $this->fixture('42')] as $name) {
            $diagnostics = new PreparationRunDiagnostics(hrtime(true));
            try {
                (new ManifestStore)->readWithDiagnostics($name, $diagnostics);
                $this->fail('Invalid manifests must remain rejected.');
            } catch (RuntimeException $error) {
                $this->assertSame($name === 'invalid-private-name' ? 'Invalid manifest reference.' : 'Invalid manifest contents.', $error->getMessage());
            }
            $this->assertSame($name === 'invalid-private-name' ? 0 : 1, $this->report($diagnostics)['phase_distributions']['file_read']['count']);
        }
    }

    public function test_valid_json_scalars_are_shape_failures_without_decode_failures(): void
    {
        foreach (['false', 'null', '42'] as $json) {
            $diagnostics = new PreparationRunDiagnostics(hrtime(true));
            try {
                (new ManifestStore)->readWithDiagnostics($this->fixture($json), $diagnostics);
                $this->fail('Scalar JSON must remain rejected as manifest contents.');
            } catch (RuntimeException $error) {
                $this->assertSame('Invalid manifest contents.', $error->getMessage());
            }
            $decode = $this->report($diagnostics)['phase_distributions']['json_decode'];
            $this->assertSame(1, $decode['count']);
            $this->assertSame(0, $decode['failures']);
            $this->assertSame(0, array_sum($this->report($diagnostics)['manifest_sizes']['files']));
        }
    }

    public function test_latency_buckets_and_maxima_remain_bounded_for_failures(): void
    {
        $diagnostics = new PreparationRunDiagnostics(hrtime(true));
        foreach ([0.1, 1.0, 10.0, 100.0, 1000.0, 10000.0, 10001.0] as $milliseconds) {
            $diagnostics->recordPhase('file_read', $milliseconds, $milliseconds === 10001.0);
        }
        $phase = $this->report($diagnostics)['phase_distributions']['file_read'];
        $this->assertSame(7, $phase['count']);
        $this->assertSame(1, $phase['failures']);
        $this->assertSame(21112.1, $phase['total_ms']);
        $this->assertSame(10001.0, $phase['max_ms']);
        $this->assertSame(['le_0_1' => 1, 'le_1' => 1, 'le_10' => 1, 'le_100' => 1, 'le_1000' => 1, 'le_10000' => 1, 'gt_10000' => 1], $phase['latency_buckets']);
        $this->assertSame(0, $this->report(new PreparationRunDiagnostics(hrtime(true)))['phase_distributions']['file_read']['count']);
    }

    private function fixture(string $json): string
    {
        $name = hash('sha256', $json).'.json.gz';
        file_put_contents((new ManifestStore)->path($name), gzencode($json));

        return $name;
    }

    private function report(PreparationRunDiagnostics $diagnostics): array
    {
        return $diagnostics->report(1, 30, 1, PHP_INT_MAX, false, false);
    }
}

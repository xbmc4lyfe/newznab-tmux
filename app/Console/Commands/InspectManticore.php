<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Search\Support\ManticoreClientFactory;
use App\Services\Search\Support\ManticoreIndexRegistry;
use App\Services\Search\Support\ManticoreSchemaInspector;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Console\Command;
use Throwable;

final class InspectManticore extends Command
{
    public const string FFI_CDEF = <<<'CDEF'
    int32_t nntmux_manticore_extract_version(const char *input, size_t input_len, char *out, size_t cap);
    int32_t nntmux_manticore_table_name_safe(const char *name, size_t name_len);
    int32_t nntmux_manticore_needs_rebuild(size_t missing, size_t incompatible, size_t missing_settings);
    CDEF;

    /**
     * Production default stays false until benchmark/parity sign-off; see
     * tests/Unit/RustApiV1ManticoreMediaIndexCommandsParityTest.php.
     */
    private static bool $useRustFfi = false;

    private static FFI|false|null $ffi = null;

    protected $signature = 'manticore:inspect {--json : Emit machine-readable JSON}';

    protected $description = 'Report Manticore version and table schema compatibility';

    public function handle(): int
    {
        try {
            $client = ManticoreClientFactory::make(config('search.drivers.manticore', []));
            $versionResponse = $client->sql('SELECT VERSION() AS version', true);
        } catch (Throwable $e) {
            $this->error('Unable to connect to Manticore: '.$e->getMessage());

            return self::FAILURE;
        }

        $report = ['version' => self::extractVersion($versionResponse), 'compatible' => true, 'tables' => []];
        $configured = config('search.drivers.manticore.indexes', []);

        foreach (ManticoreIndexRegistry::definitions() as $logical => $definition) {
            $table = (string) ($configured[$logical] ?? $logical.'_rt');
            try {
                if (! self::isSafeTableName($table)) {
                    throw new \RuntimeException('Unsafe configured table name');
                }
                $actual = $client->table($table)->describe();
                $comparison = ManticoreSchemaInspector::compareColumns(is_array($actual) ? $actual : [], $definition['columns']);
                $actualSettings = $client->tables()->settings(['table' => $table]);
                $missingSettings = ManticoreSchemaInspector::missingSettings($actualSettings, ManticoreIndexRegistry::inspectableSettings());
                $needsRebuild = self::tableNeedsRebuild($comparison['missing'], $comparison['incompatible'], $missingSettings);
                $report['tables'][$table] = [...$comparison, 'missing_settings' => $missingSettings, 'needs_rebuild' => $needsRebuild];
                $report['compatible'] = $report['compatible'] && ! $needsRebuild;
            } catch (Throwable $e) {
                $report['tables'][$table] = ['missing_table' => true, 'needs_rebuild' => true, 'error' => $e->getMessage()];
                $report['compatible'] = false;
            }
        }

        if ($this->option('json')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->info('Manticore version: '.$report['version']);
            foreach ($report['tables'] as $table => $status) {
                $this->line(sprintf('%-20s %s', $table, $status['needs_rebuild'] ? 'REBUILD REQUIRED' : 'compatible'));
            }
            if (! $report['compatible']) {
                $this->warn('Run manticore:create-indexes --drop --index=releases during a maintenance window for release-only schema changes, then repopulate the affected index.');
            }
        }

        return $report['compatible'] ? self::SUCCESS : self::FAILURE;
    }

    public static function setUseRustFfi(?bool $useRustFfi): void
    {
        self::$useRustFfi = $useRustFfi ?? false;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi;
    }

    public static function setFfiForTesting(FFI|false|null $ffi): void
    {
        self::$ffi = $ffi;
    }

    /**
     * First `major.minor[.patch]` in the JSON-encoded `SELECT VERSION()` response, or `unknown`.
     */
    public static function extractVersion(mixed $response): string
    {
        return self::$useRustFfi ? self::extractVersionNative($response) : self::extractVersionPhp($response);
    }

    public static function extractVersionPhp(mixed $response): string
    {
        $encoded = json_encode($response);
        if (is_string($encoded) && preg_match('/\d+\.\d+(?:\.\d+)?/', $encoded, $match) === 1) {
            return $match[0];
        }

        return 'unknown';
    }

    public static function extractVersionNative(mixed $response): string
    {
        $ffi = self::ffi();
        $encoded = json_encode($response);
        if ($ffi === null || ! is_string($encoded)) {
            return self::extractVersionPhp($response);
        }

        try {
            $capacity = 0;
            $out = RustPipelineLibrary::obtainScratch($ffi, max(\strlen($encoded), 16), $capacity);
            if ($out === null) {
                return self::extractVersionPhp($response);
            }
            $written = self::callFfi($ffi, 'nntmux_manticore_extract_version', $encoded, \strlen($encoded), $out, $capacity);
            if (! \is_int($written) || $written <= 0 || $written > $capacity) {
                return self::extractVersionPhp($response);
            }

            return FFI::string($out, $written);
        } catch (Throwable) {
            return self::extractVersionPhp($response);
        }
    }

    /**
     * Configured table names must match `/^[a-zA-Z0-9_]+$/` before they reach a Manticore request.
     */
    public static function isSafeTableName(string $table): bool
    {
        return self::$useRustFfi ? self::isSafeTableNameNative($table) : self::isSafeTableNamePhp($table);
    }

    public static function isSafeTableNamePhp(string $table): bool
    {
        return preg_match('/^[a-zA-Z0-9_]+$/', $table) === 1;
    }

    public static function isSafeTableNameNative(string $table): bool
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return self::isSafeTableNamePhp($table);
        }

        try {
            return match (self::callFfi($ffi, 'nntmux_manticore_table_name_safe', $table, \strlen($table))) {
                0 => false,
                1 => true,
                default => self::isSafeTableNamePhp($table),
            };
        } catch (Throwable) {
            return self::isSafeTableNamePhp($table);
        }
    }

    /**
     * A table needs a rebuild when columns are missing or incompatible, or settings are missing.
     *
     * @param  array<array-key, mixed>  $missing
     * @param  array<array-key, mixed>  $incompatible
     * @param  array<array-key, mixed>  $missingSettings
     */
    public static function tableNeedsRebuild(array $missing, array $incompatible, array $missingSettings): bool
    {
        return self::$useRustFfi
            ? self::tableNeedsRebuildNative($missing, $incompatible, $missingSettings)
            : self::tableNeedsRebuildPhp($missing, $incompatible, $missingSettings);
    }

    /**
     * @param  array<array-key, mixed>  $missing
     * @param  array<array-key, mixed>  $incompatible
     * @param  array<array-key, mixed>  $missingSettings
     */
    public static function tableNeedsRebuildPhp(array $missing, array $incompatible, array $missingSettings): bool
    {
        return $missing !== [] || $incompatible !== [] || $missingSettings !== [];
    }

    /**
     * @param  array<array-key, mixed>  $missing
     * @param  array<array-key, mixed>  $incompatible
     * @param  array<array-key, mixed>  $missingSettings
     */
    public static function tableNeedsRebuildNative(array $missing, array $incompatible, array $missingSettings): bool
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return self::tableNeedsRebuildPhp($missing, $incompatible, $missingSettings);
        }

        try {
            return match (self::callFfi($ffi, 'nntmux_manticore_needs_rebuild', \count($missing), \count($incompatible), \count($missingSettings))) {
                0 => false,
                1 => true,
                default => self::tableNeedsRebuildPhp($missing, $incompatible, $missingSettings),
            };
        } catch (Throwable) {
            return self::tableNeedsRebuildPhp($missing, $incompatible, $missingSettings);
        }
    }

    private static function callFfi(FFI $ffi, string $symbol, mixed ...$args): mixed
    {
        return $ffi->$symbol(...$args);
    }

    private static function ffi(): ?FFI
    {
        if (self::$ffi === false) {
            return null;
        }

        if (self::$ffi instanceof FFI) {
            return self::$ffi;
        }

        $ffi = RustPipelineLibrary::resolveDomain('search', self::FFI_CDEF);
        self::$ffi = $ffi ?? false;

        return $ffi;
    }
}

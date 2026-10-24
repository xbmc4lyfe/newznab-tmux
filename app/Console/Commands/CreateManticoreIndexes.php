<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Search\Support\ManticoreClientFactory;
use App\Services\Search\Support\ManticoreIndexRegistry;
use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use Illuminate\Console\Command;
use Manticoresearch\Client;
use Manticoresearch\Exceptions\ResponseException;
use Throwable;

class CreateManticoreIndexes extends Command
{
    public const string FFI_CDEF = <<<'CDEF'
    int32_t nntmux_manticore_selected_indexes(
        const char *payload,
        size_t payload_len,
        const size_t *lens,
        size_t count,
        size_t *out_indices,
        size_t out_cap
    );
    int32_t nntmux_manticore_unknown_indexes(
        const char *selected,
        size_t selected_len,
        const size_t *selected_lens,
        size_t selected_count,
        const char *known,
        size_t known_len,
        const size_t *known_lens,
        size_t known_count,
        size_t *out_indices,
        size_t out_cap
    );
    int32_t nntmux_manticore_default_index_name(const char *logical, size_t logical_len, char *out, size_t cap);
    int32_t nntmux_manticore_create_error_kind(const char *message, size_t message_len);
    CDEF;

    public const int CREATE_ERROR_ALREADY_EXISTS = 0;

    public const int CREATE_ERROR_DATA_DIR = 1;

    public const int CREATE_ERROR_GENERIC = 2;

    /**
     * Production default stays false until benchmark/parity sign-off; see
     * tests/Unit/RustApiV1ManticoreMediaIndexCommandsParityTest.php.
     */
    private static bool $useRustFfi = false;

    private static FFI|false|null $ffi = null;

    protected $signature = 'manticore:create-indexes
                            {--drop : Drop selected indexes before creating}
                            {--index=* : Logical index name(s) to create; defaults to all indexes}';

    protected $description = 'Create Manticore Search indexes based on configuration';

    protected Client $client;

    public function handle(): int
    {
        $this->info('Creating Manticore Search indexes...');
        $this->client = ManticoreClientFactory::make(config('search.drivers.manticore', []));

        try {
            $this->client->nodes()->status();
        } catch (Throwable $e) {
            $this->error('Failed to connect to Manticore Search: '.$e->getMessage());
            $this->info('Check the configured host, authentication, and whether Manticore is running.');

            return self::FAILURE;
        }

        $configuredNames = config('search.drivers.manticore.indexes', []);
        $selected = self::selectedIndexes($this->option('index'));
        $definitions = ManticoreIndexRegistry::definitions();
        if ($selected !== []) {
            $unknown = self::unknownIndexes($selected, array_keys($definitions));
            if ($unknown !== []) {
                $this->error('Unknown logical index: '.implode(', ', $unknown));

                return self::FAILURE;
            }
            $definitions = array_intersect_key($definitions, array_flip($selected));
        }
        $hasErrors = false;
        foreach ($definitions as $logical => $schema) {
            $indexName = self::resolveIndexName($configuredNames, $logical);
            if (! $this->createIndex($indexName, $schema, (bool) $this->option('drop'))) {
                $hasErrors = true;
            }
        }

        if ($hasErrors) {
            $this->error('Some Manticore tables could not be created.');

            return self::FAILURE;
        }

        $this->info('All Manticore Search tables are ready.');

        return self::SUCCESS;
    }

    /** @param array<string, mixed> $schema */
    protected function createIndex(string $indexName, array $schema, bool $dropExisting): bool
    {
        $tables = $this->client->tables();

        try {
            if ($dropExisting) {
                $this->info("Dropping {$indexName}...");
                $tables->drop(['index' => $indexName, 'body' => ['silent' => true]]);
            }

            $tables->create(['index' => $indexName, 'body' => $schema]);
            $this->info("Created {$indexName}.");

            return true;
        } catch (ResponseException $e) {
            $kind = self::createErrorKind($e->getMessage());
            if ($kind === self::CREATE_ERROR_ALREADY_EXISTS) {
                $this->warn("{$indexName} already exists; use --drop during a maintenance rebuild.");

                return true;
            }

            if ($kind === self::CREATE_ERROR_DATA_DIR) {
                $this->error("Failed to create {$indexName}: configure a writable Manticore data_dir.");
            } else {
                $this->error("Failed to create {$indexName}: ".$e->getMessage());
            }

            return false;
        } catch (Throwable $e) {
            $this->error("Failed to create {$indexName}: ".$e->getMessage());

            return false;
        }
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
     * `--index` values as strings, without the empty and `"0"` values `array_filter()` drops.
     *
     * @return list<string>
     */
    public static function selectedIndexes(mixed $option): array
    {
        return self::$useRustFfi ? self::selectedIndexesNative($option) : self::selectedIndexesPhp($option);
    }

    /**
     * @return list<string>
     */
    public static function selectedIndexesPhp(mixed $option): array
    {
        return array_values(array_filter(array_map('strval', (array) $option)));
    }

    /**
     * @return list<string>
     */
    public static function selectedIndexesNative(mixed $option): array
    {
        $values = array_values(array_map('strval', (array) $option));
        $count = \count($values);
        $ffi = self::ffi();
        if ($ffi === null || $count === 0) {
            return self::selectedIndexesPhp($option);
        }

        try {
            $payload = implode('', $values);
            $lens = self::lengths($ffi, $values);
            $indices = $ffi->new("size_t[{$count}]");
            $kept = self::callFfi($ffi, 'nntmux_manticore_selected_indexes', $payload, \strlen($payload), $lens, $count, $indices, $count);
            if (! \is_int($kept) || $kept < 0 || $kept > $count) {
                return self::selectedIndexesPhp($option);
            }

            $selected = [];
            for ($index = 0; $index < $kept; $index++) {
                $selected[] = $values[(int) $indices[$index]];
            }

            return $selected;
        } catch (Throwable) {
            return self::selectedIndexesPhp($option);
        }
    }

    /**
     * Selected names that are not known logical indexes, keyed like `array_diff()`.
     *
     * @param  array<array-key, string>  $selected
     * @param  array<array-key, int|string>  $known
     * @return array<array-key, string>
     */
    public static function unknownIndexes(array $selected, array $known): array
    {
        return self::$useRustFfi ? self::unknownIndexesNative($selected, $known) : self::unknownIndexesPhp($selected, $known);
    }

    /**
     * @param  array<array-key, string>  $selected
     * @param  array<array-key, int|string>  $known
     * @return array<array-key, string>
     */
    public static function unknownIndexesPhp(array $selected, array $known): array
    {
        return array_diff($selected, $known);
    }

    /**
     * @param  array<array-key, string>  $selected
     * @param  array<array-key, int|string>  $known
     * @return array<array-key, string>
     */
    public static function unknownIndexesNative(array $selected, array $known): array
    {
        $count = \count($selected);
        $ffi = self::ffi();
        if ($ffi === null || $count === 0 || $known === []) {
            return self::unknownIndexesPhp($selected, $known);
        }

        try {
            $keys = array_keys($selected);
            $values = array_values($selected);
            $knownValues = array_map('strval', array_values($known));
            $payload = implode('', $values);
            $knownPayload = implode('', $knownValues);
            $knownCount = \count($knownValues);
            $indices = $ffi->new("size_t[{$count}]");
            $written = self::callFfi(
                $ffi,
                'nntmux_manticore_unknown_indexes',
                $payload,
                \strlen($payload),
                self::lengths($ffi, $values),
                $count,
                $knownPayload,
                \strlen($knownPayload),
                self::lengths($ffi, $knownValues),
                $knownCount,
                $indices,
                $count,
            );
            if (! \is_int($written) || $written < 0 || $written > $count) {
                return self::unknownIndexesPhp($selected, $known);
            }

            $unknown = [];
            for ($index = 0; $index < $written; $index++) {
                $position = (int) $indices[$index];
                $unknown[$keys[$position]] = $values[$position];
            }

            return $unknown;
        } catch (Throwable) {
            return self::unknownIndexesPhp($selected, $known);
        }
    }

    /**
     * The configured physical table for a logical index, defaulting to `{logical}_rt`.
     */
    public static function resolveIndexName(mixed $configured, int|string $logical): string
    {
        return self::$useRustFfi ? self::resolveIndexNameNative($configured, $logical) : self::resolveIndexNamePhp($configured, $logical);
    }

    public static function resolveIndexNamePhp(mixed $configured, int|string $logical): string
    {
        return (string) ($configured[$logical] ?? $logical.'_rt');
    }

    /**
     * Only the `{logical}_rt` default is built natively; configured names keep the PHP cast.
     */
    public static function resolveIndexNameNative(mixed $configured, int|string $logical): string
    {
        $ffi = self::ffi();
        $isDefault = $configured === null || (\is_array($configured) && ! isset($configured[$logical]));
        if ($ffi === null || ! $isDefault) {
            return self::resolveIndexNamePhp($configured, $logical);
        }

        try {
            $logical = (string) $logical;
            $capacity = 0;
            $out = RustPipelineLibrary::obtainScratch($ffi, \strlen($logical) + 3, $capacity);
            if ($out === null) {
                return self::resolveIndexNamePhp($configured, $logical);
            }
            $written = self::callFfi($ffi, 'nntmux_manticore_default_index_name', $logical, \strlen($logical), $out, $capacity);
            if (! \is_int($written) || $written <= 0 || $written > $capacity) {
                return self::resolveIndexNamePhp($configured, $logical);
            }

            return FFI::string($out, $written);
        } catch (Throwable) {
            return self::resolveIndexNamePhp($configured, $logical);
        }
    }

    /**
     * Classify a Manticore create error: `already exists`, missing `data_dir`, or anything else.
     */
    public static function createErrorKind(string $message): int
    {
        return self::$useRustFfi ? self::createErrorKindNative($message) : self::createErrorKindPhp($message);
    }

    public static function createErrorKindPhp(string $message): int
    {
        return match (true) {
            str_contains($message, 'already exists') => self::CREATE_ERROR_ALREADY_EXISTS,
            str_contains($message, 'data_dir') => self::CREATE_ERROR_DATA_DIR,
            default => self::CREATE_ERROR_GENERIC,
        };
    }

    public static function createErrorKindNative(string $message): int
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return self::createErrorKindPhp($message);
        }

        try {
            $kind = self::callFfi($ffi, 'nntmux_manticore_create_error_kind', $message, \strlen($message));

            return match ($kind) {
                self::CREATE_ERROR_ALREADY_EXISTS, self::CREATE_ERROR_DATA_DIR, self::CREATE_ERROR_GENERIC => $kind,
                default => self::createErrorKindPhp($message),
            };
        } catch (Throwable) {
            return self::createErrorKindPhp($message);
        }
    }

    /**
     * @param  list<string>  $values
     */
    private static function lengths(FFI $ffi, array $values): CData
    {
        $count = max(\count($values), 1);
        $lens = $ffi->new("size_t[{$count}]");
        foreach ($values as $index => $value) {
            $lens[$index] = \strlen($value);
        }

        return $lens;
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

<?php

declare(strict_types=1);

namespace App\Facades;

use App\Providers\SearchServiceProvider;
use Elastic\Elasticsearch\Client;
use Illuminate\Support\Facades\Facade;

/**
 * Elasticsearch Facade - provides static access to Elasticsearch client.
 *
 * @method static \Elastic\Elasticsearch\Endpoints\Indices indices()
 * @method static \Elastic\Elasticsearch\Endpoints\Cluster cluster()
 * @method static \Elastic\Elasticsearch\Response\Elasticsearch search(array $params)
 * @method static \Elastic\Elasticsearch\Response\Elasticsearch bulk(array $params)
 * @method static \Elastic\Elasticsearch\Response\Elasticsearch get(array $params)
 * @method static \Elastic\Elasticsearch\Response\Elasticsearch deleteByQuery(array $params)
 *
 * @see Client
 */
class Elasticsearch extends Facade // @phpstan-ignore missingType.iterableValue
{
    /** Bytes Elasticsearch rejects anywhere in an index name. */
    private const string INVALID_INDEX_BYTES = '\\/*?"<>| ,#:';

    private const int INDEX_MAX_BYTES = 255;

    /**
     * Rust stays opt-in: the FFI round trip is slower than the PHP helper (< 1.5x); see
     * tests/Unit/RustSearchProviderFacadeFacetsParityTest.php.
     */
    private const bool RUST_FFI_DEFAULT = false;

    private static ?bool $useRustFfi = null;

    protected static function getFacadeAccessor(): string
    {
        return 'elasticsearch';
    }

    public static function setUseRustFfi(?bool $use): void
    {
        self::$useRustFfi = $use;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi ?? self::RUST_FFI_DEFAULT;
    }

    /**
     * Normalized index name, whether Elasticsearch accepts it, and the bulk operation count for a body of
     * action/source lines (`balanced` when the body holds complete pairs).
     *
     * @return array{index: string, valid: bool, operations: int, balanced: bool}
     */
    public static function normalizeIndexOperationParams(string $indexName, int $bodyCount): array
    {
        return self::usesRustFfi() ? self::normalizeIndexOperationParamsNative($indexName, $bodyCount) : self::normalizeIndexOperationParamsPhp($indexName, $bodyCount);
    }

    /**
     * @return array{index: string, valid: bool, operations: int, balanced: bool}
     */
    public static function normalizeIndexOperationParamsPhp(string $indexName, int $bodyCount): array
    {
        $index = strtolower(trim($indexName));
        $valid = $index !== '' && \strlen($index) <= self::INDEX_MAX_BYTES && $index !== '.' && $index !== '..'
            && strspn($index, '-_+', 0, 1) === 0 && strpbrk($index, self::INVALID_INDEX_BYTES) === false;

        return [
            'index' => $index,
            'valid' => $valid,
            'operations' => $bodyCount > 0 ? intdiv($bodyCount, 2) : 0,
            'balanced' => $bodyCount >= 0 && $bodyCount % 2 === 0,
        ];
    }

    /**
     * @return array{index: string, valid: bool, operations: int, balanced: bool}
     */
    public static function normalizeIndexOperationParamsNative(string $indexName, int $bodyCount): array
    {
        $result = SearchServiceProvider::evaluateNative(2, [$indexName, (string) $bodyCount]);
        if ($result !== null && \count($result) === 4 && \in_array($result[1], ['0', '1'], true)
            && \in_array($result[3], ['0', '1'], true) && preg_match('/^(0|[1-9][0-9]*)$/', $result[2]) === 1) {
            return [
                'index' => $result[0],
                'valid' => $result[1] === '1',
                'operations' => (int) $result[2],
                'balanced' => $result[3] === '1',
            ];
        }

        return self::normalizeIndexOperationParamsPhp($indexName, $bodyCount);
    }
}

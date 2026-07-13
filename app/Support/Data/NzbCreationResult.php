<?php

declare(strict_types=1);

namespace App\Support\Data;

final readonly class NzbCreationResult
{
    public const string FAILURE_NONE = 'none';

    public const string FAILURE_DETERMINISTIC = 'deterministic';

    public const string FAILURE_TRANSIENT = 'transient';

    /**
     * @param  list<int>  $collectionIds
     */
    private function __construct(
        public bool $success,
        public string $failureType,
        public string $reason,
        public ?string $path,
        public array $collectionIds,
    ) {}

    /**
     * @param  list<int>  $collectionIds
     */
    public static function success(string $path, array $collectionIds): self
    {
        return new self(true, self::FAILURE_NONE, '', $path, $collectionIds);
    }

    /**
     * @param  list<int>  $collectionIds
     */
    public static function deterministic(string $reason, array $collectionIds = [], ?string $path = null): self
    {
        return new self(false, self::FAILURE_DETERMINISTIC, $reason, $path, $collectionIds);
    }

    /**
     * @param  list<int>  $collectionIds
     */
    public static function transient(string $reason, array $collectionIds = [], ?string $path = null): self
    {
        return new self(false, self::FAILURE_TRANSIENT, $reason, $path, $collectionIds);
    }

    public function isDeterministicFailure(): bool
    {
        return $this->failureType === self::FAILURE_DETERMINISTIC;
    }

    public function isTransientFailure(): bool
    {
        return $this->failureType === self::FAILURE_TRANSIENT;
    }
}

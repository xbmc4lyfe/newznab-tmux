<?php

declare(strict_types=1);

namespace App\Services\NameFixing;

/**
 * FIFO discovery cache. Payload bytes include query keys and serialized complete rows;
 * this bounds retained string payload, not PHP's allocator or array/object overhead.
 */
final class FilenameNominationCache
{
    /** @var array<string, array{rows: array<int, array<string, mixed>|object>, bytes: int}> */
    private array $entries = [];

    private int $rowCount = 0;

    private int $payloadBytes = 0;

    public function __construct(
        private readonly int $maxRows = 5000,
        private readonly int $maxPayloadBytes = 8 * 1024 * 1024,
        private readonly int $maxQueries = 5000,
    ) {}

    /**
     * @param  callable(): array<int, array<string, mixed>|object>  $resolve
     * @return array<int, array<string, mixed>|object>
     */
    public function remember(string $query, callable $resolve): array
    {
        if (array_key_exists($query, $this->entries)) {
            return $this->entries[$query]['rows'];
        }
        $rows = $resolve();
        $rowCount = count($rows);
        // Never truncate a response: even an uncached tail can disprove uniqueness.
        if ($rowCount > $this->maxRows || $this->maxQueries < 1) {
            return $rows;
        }
        $bytes = strlen($query) + strlen(serialize($rows));
        if ($bytes > $this->maxPayloadBytes) {
            return $rows;
        }
        while ($this->entries !== [] && (
            $this->rowCount + $rowCount > $this->maxRows
            || $this->payloadBytes + $bytes > $this->maxPayloadBytes
            || count($this->entries) >= $this->maxQueries
        )) {
            $oldest = array_key_first($this->entries);
            $this->rowCount -= count($this->entries[$oldest]['rows']);
            $this->payloadBytes -= $this->entries[$oldest]['bytes'];
            unset($this->entries[$oldest]);
        }
        $this->entries[$query] = ['rows' => $rows, 'bytes' => $bytes];
        $this->rowCount += $rowCount;
        $this->payloadBytes += $bytes;

        return $rows;
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Api\V1;

use Illuminate\Support\Collection;

final class ReleaseRows
{
    /**
     * @var list<mixed>|null
     */
    private ?array $rows = null;

    public function __construct(private readonly mixed $releases) {}

    /**
     * @return list<mixed>
     */
    public function rows(): array
    {
        if ($this->rows !== null) {
            return $this->rows;
        }

        if ($this->releases === null || $this->releases === false || $this->releases === []) {
            return $this->rows = [];
        }

        if ($this->releases instanceof Collection) {
            return $this->rows = $this->releases->values()->all();
        }

        if (\is_array($this->releases)) {
            return $this->rows = array_values($this->releases);
        }

        if ($this->releases instanceof \Traversable) {
            return $this->rows = array_values(iterator_to_array($this->releases));
        }

        if (\is_object($this->releases)) {
            return $this->rows = [$this->releases];
        }

        return $this->rows = [];
    }

    public function totalRows(): int
    {
        $rows = $this->rows();
        if ($rows === []) {
            return 0;
        }

        $totalRows = $this->value($rows[0], '_totalrows');
        if ($totalRows !== null) {
            return (int) $totalRows;
        }

        return \count($rows);
    }

    private function value(mixed $row, string $key): mixed
    {
        if (\is_array($row) && \array_key_exists($key, $row)) {
            return $row[$key];
        }

        if (\is_object($row) && isset($row->{$key})) {
            return $row->{$key};
        }

        return null;
    }
}

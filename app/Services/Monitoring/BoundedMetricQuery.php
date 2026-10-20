<?php

declare(strict_types=1);

namespace App\Services\Monitoring;

use Illuminate\Database\MySqlConnection;
use Illuminate\Database\Query\Builder;

/** Metrics must never leave an unbounded database query running after the exporter exits. */
class BoundedMetricQuery
{
    /** @return list<object> */
    public function get(Builder $query): array
    {
        $connection = $query->getConnection();
        if (! $connection instanceof MySqlConnection) {
            return $query->get()->all();
        }

        // Statement-local limits also cancel server work; a PHP process alarm alone does not.
        $sql = $connection->isMaria()
            ? 'SET STATEMENT max_statement_time=2 FOR '.$query->toSql()
            : preg_replace('/\Aselect\b/i', 'select /*+ MAX_EXECUTION_TIME(2000) */', $query->toSql()) ?? throw new \LogicException('Invalid metrics query.');

        return $connection->select($sql, $query->getBindings());
    }
}

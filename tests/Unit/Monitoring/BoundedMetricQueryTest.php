<?php

declare(strict_types=1);

namespace Tests\Unit\Monitoring;

use App\Services\Monitoring\BoundedMetricQuery;
use Illuminate\Database\MySqlConnection;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Grammars\MySqlGrammar;
use Illuminate\Database\Query\Processors\Processor;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BoundedMetricQueryTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public static function databases(): array
    {
        return [
            'MariaDB' => [true, 'SET STATEMENT max_statement_time=2 FOR select * from `candidates` where `status` = ?'],
            'MySQL' => [false, 'select /*+ MAX_EXECUTION_TIME(2000) */ * from `candidates` where `status` = ?'],
        ];
    }

    #[DataProvider('databases')]
    public function test_database_deadline_is_statement_local_and_retains_bound_parameters(bool $maria, string $sql): void
    {
        $connection = Mockery::mock(MySqlConnection::class);
        $connection->shouldReceive('getTablePrefix')->andReturn('');
        $connection->shouldReceive('isMaria')->once()->andReturn($maria);
        $connection->shouldReceive('select')->once()->with($sql, ['pending'])->andReturn([(object) ['id' => 7]]);
        $query = (new Builder($connection, new MySqlGrammar($connection), new Processor))->from('candidates')->where('status', 'pending');

        $this->assertSame(7, (new BoundedMetricQuery)->get($query)[0]->id);
    }
}

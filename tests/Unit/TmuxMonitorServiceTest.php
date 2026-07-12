<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Tmux\TmuxMonitorService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;

class TmuxMonitorServiceTest extends TestCase
{
    public function test_calculate_statistics_backfills_missing_work_count_defaults(): void
    {
        $reflection = new ReflectionClass(TmuxMonitorService::class);
        /** @var TmuxMonitorService $monitor */
        $monitor = $reflection->newInstanceWithoutConstructor();

        $runVar = [
            'counts' => [
                'now' => [
                    'processnfo' => 2,
                    'tv' => 1,
                ],
                'start' => [],
                'diff' => [],
                'percent' => [],
            ],
        ];

        $runVarProperty = new ReflectionProperty(TmuxMonitorService::class, 'runVar');
        $runVarProperty->setValue($monitor, $runVar);

        $iterationsProperty = new ReflectionProperty(TmuxMonitorService::class, 'iterations');
        $iterationsProperty->setValue($monitor, 1);

        $calculate = new ReflectionMethod(TmuxMonitorService::class, 'calculateStatistics');
        $calculate->invoke($monitor);

        $updatedRunVar = $runVarProperty->getValue($monitor);

        $this->assertSame(0, $updatedRunVar['counts']['now']['work']);
        $this->assertSame(0, $updatedRunVar['counts']['now']['work_available']);
        $this->assertSame('0', $updatedRunVar['counts']['diff']['work']);
        $this->assertSame('0', $updatedRunVar['counts']['diff']['work_available']);
        $this->assertSame(2, $updatedRunVar['counts']['now']['total_work']);
    }
}

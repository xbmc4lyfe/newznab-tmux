<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Monitoring\PrometheusMetricsExporter;
use Illuminate\Console\Command;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'monitoring:export-metrics')]
class ExportMonitoringMetrics extends Command
{
    /**
     * @var string
     */
    protected $signature = 'monitoring:export-metrics
                            {--print : Print the metrics instead of pushing them to the Pushgateway}
                            {--scheduled : Limit the scheduled export runtime to four minutes}';

    /**
     * @var string
     */
    protected $description = 'Push NNTmux application metrics to the Prometheus Pushgateway';

    public function handle(PrometheusMetricsExporter $exporter): int
    {
        if ($this->option('print')) {
            $this->output->write($exporter->render());

            return self::SUCCESS;
        }

        if (! config('monitoring.enabled')) {
            $this->components->info('Monitoring is disabled (MONITORING_ENABLED=false); nothing to export.');

            return self::SUCCESS;
        }

        if ($this->option('scheduled')) {
            try {
                $result = Process::path(base_path())->timeout(240)
                    ->run([PHP_BINARY, base_path('artisan'), 'monitoring:export-metrics']);
            } catch (ProcessTimedOutException) {
                $this->components->error('Monitoring export exceeded its four-minute runtime limit.');

                return self::FAILURE;
            }

            $this->output->write($result->output());
            $this->output->write($result->errorOutput());

            return $result->exitCode();
        }

        if (! $exporter->push()) {
            $this->components->error('Could not push metrics to '.config('monitoring.pushgateway.url').'. See the application log for details.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}

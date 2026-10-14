<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Monitoring\PrometheusMetricsExporter;
use Illuminate\Console\Command;
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

        $scheduled = (bool) $this->option('scheduled');
        if ($scheduled && ! function_exists('pcntl_alarm')) {
            $this->components->error('Scheduled monitoring exports require the PCNTL extension for their runtime limit.');

            return self::FAILURE;
        }

        $previousHandler = null;
        if ($scheduled) {
            $previousHandler = pcntl_signal_get_handler(SIGALRM);
            // The OS default terminates even when PHP is blocked inside database or Redis I/O.
            pcntl_signal(SIGALRM, SIG_DFL);
            pcntl_alarm(240);
        }

        try {
            if (! $exporter->push()) {
                $this->components->error('Could not push metrics to '.config('monitoring.pushgateway.url').'. See the application log for details.');

                return self::FAILURE;
            }

            return self::SUCCESS;
        } finally {
            if ($scheduled) {
                pcntl_alarm(0);
                pcntl_signal(SIGALRM, $previousHandler);
            }
        }
    }
}

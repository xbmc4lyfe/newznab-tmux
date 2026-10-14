<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schedule;

Schedule::exec('php /tmp/scheduled-worker.php')->everyMinute()->runInBackground();

<?php

use App\Enums\NzbImportStatus;
use App\Services\Nzb\DirectorySyncBridge;
use App\Services\Nzb\NzbUploadManifestService;
use Carbon\Carbon;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Filesystem\Filesystem;

// Offline synthetic fixture only; bootstrap no application, DB, queues or network.
function now()
{
    return Carbon::now();
}
$repo = dirname(__DIR__, 2);
require $repo.'/vendor/autoload.php';
$container = new Container;
Container::setInstance($container);
$container->instance('config', new Repository([]));
if (getenv('CUSTODY_VARIANT') === 'rust') {
    require getenv('CUSTODY_BRIDGE');
}
$fs = new Filesystem;
$service = new NzbUploadManifestService($fs);
$root = getenv('CUSTODY_OWNED');
$xml = '<nzb>'.str_repeat('x', 4096).'</nzb>';
$times = [];
$warmup = (int) getenv('CUSTODY_WARMUP');
$count = (int) getenv('CUSTODY_COUNT');
try {
    foreach (['directory' => $root, 'file' => __FILE__, 'missing' => $root.'/missing'] as $kind => $path) {
        $actual = DirectorySyncBridge::sync($path, 'parity');
        if ($actual !== ($kind === 'directory')) {
            throw new RuntimeException('Primitive parity failed');
        }
    }
    for ($i = 0; $i < $count + $warmup; $i++) {
        $dir = $root.'/flow-'.$i;
        mkdir($dir);
        $source = $dir.'/fixture.nzb';
        file_put_contents($source, $xml);
        file_put_contents($dir.'/info.nfo', 'synthetic companion');
        $start = hrtime(true);
        $service->create($dir, 'synthetic-'.$i, 'fixture.nzb', 'info.nfo', 'synthetic companion');
        $capture = $service->finalizeInput($source, $xml, $service->reserveInput($source));
        if ($capture === null || $capture['digest'] !== hash('sha256', $xml)) {
            throw new RuntimeException('Capture failed');
        }
        $service->recordNzbOutcome($source, NzbImportStatus::Failed, null, null, inputCapture: $capture);
        if (! $service->deleteCapturedInput($source, $capture)) {
            throw new RuntimeException('Input cleanup failed');
        }
        $mp = $dir.'/'.NzbUploadManifestService::FILENAME;
        if (! $service->deleteTerminalCompanion($mp)) {
            throw new RuntimeException('Companion cleanup failed');
        }
        $elapsed = (hrtime(true) - $start) / 1e6;
        $manifest = $service->read($mp);
        if ($manifest['state'] !== 'nzb_failed' || $manifest['relinquished_attempt'] !== $capture['attempt']
            || (isset($manifest['delete_input_pending']) || isset($manifest['delete_terminal_nfo']))
            || file_exists($source) || file_exists($dir.'/info.nfo')) {
            throw new RuntimeException('Custody parity failed');
        }
        if ($i >= $warmup) {
            $times[] = $elapsed;
        }
        $fs->deleteDirectory($dir);
    }
    echo json_encode(['flow_ms' => $times, 'parity' => true], JSON_THROW_ON_ERROR), "\n";
} finally {
    $fs->deleteDirectory($root);
}

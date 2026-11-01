<?php
// Dumps every entry of the PHP schedule. From the php-final checkout, with the default environment:
// php -d ffi.enable=false <this file> <checkout> > schedule.json
$root = $argv[1];
chdir($root);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$schedule = $app->make(Illuminate\Console\Scheduling\Schedule::class);
$rows = [];
foreach ($schedule->events() as $event) {
    $command = $event->command ?? '';
    if (preg_match("/artisan'? (.+)$/", (string) $command, $match)) {
        $command = $match[1];
    }
    $rows[] = [
        'command' => $command !== '' ? $command : ($event->description ?? 'closure'),
        'cron' => $event->expression,
    ];
}
echo json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";

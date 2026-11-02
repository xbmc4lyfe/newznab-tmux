<?php
// Golden data for the movie name parser. Run from the PHP reference checkout:
//   cd ~/git.xbmc4lyfe/newznab-tmux-php-ref && php <this file> > <this directory>/movie_parse.jsonl

use App\Services\MovieService;
use Illuminate\Support\Facades\Cache;

require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config(['cache.default' => 'array']);

$service = new MovieService;
$parse = new ReflectionMethod($service, 'parseMovieSearchName');
$title = new ReflectionProperty($service, 'currentTitle');
$year = new ReflectionProperty($service, 'currentYear');
foreach (array_filter(array_map('trim', file(__DIR__.'/movie_names.txt'))) as $name) {
    $parsed = $parse->invoke($service, $name);
    echo json_encode([
        'input' => $name,
        'title' => $parsed ? $title->getValue($service) : null,
        'year' => $parsed ? $year->getValue($service) : null,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).PHP_EOL;
}

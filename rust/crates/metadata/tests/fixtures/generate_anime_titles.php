<?php
// Golden data for the anime title extractor. Run from the PHP reference checkout:
//   cd ~/git.xbmc4lyfe/newznab-tmux-php-ref && php <this file> > <this directory>/anime_titles.jsonl

use App\Services\AnimeProcessor;

require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$processor = new AnimeProcessor(false);
$extract = new ReflectionMethod($processor, 'extractTitleEpisode');
foreach (array_filter(array_map('trim', file(__DIR__.'/anime_names.txt'))) as $name) {
    $result = $extract->invoke($processor, $name);
    echo json_encode(['input' => $name, 'title' => $result['title'] ?? null], JSON_UNESCAPED_UNICODE).PHP_EOL;
}

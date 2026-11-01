<?php
// Golden data for the TV name parser. Run from the PHP reference checkout:
//   cd ~/git.xbmc4lyfe/newznab-tmux-php-ref && php <this file> > <this directory>/tv_parse.jsonl

use App\Services\TvProcessing\ParsedTvRelease;
use App\Services\TvProcessing\Providers\LocalDbProvider;

require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$provider = new LocalDbProvider;
$names = array_values(array_filter(array_map('trim', file(__DIR__.'/tv_names.txt'))));
foreach ($names as $name) {
    $info = $provider->parseInfo($name);
    $parsed = ParsedTvRelease::fromParseInfo($info);
    echo json_encode([
        'input' => $name,
        'parsed' => $parsed === null ? null : [
            'name' => $parsed->name,
            'clean_name' => $parsed->cleanName,
            'country' => $parsed->country,
            'season' => $parsed->season,
            'episode' => $parsed->episode,
            'season_pack' => $parsed->seasonPack,
            'airdate' => $parsed->airdate,
            'year' => $parsed->year,
            'title_without_year' => $parsed->titleWithoutYear(),
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).PHP_EOL;
}

$cases = [
    ['Doctor Who', 2005, 'Doctor Who', '2005-03-26', '', ''],
    ['Doctor Who', 2005, 'Doctor Who', '1963-11-23', '', ''],
    ['Doctor Who', null, 'Doctor Who (2005)', null, '', ''],
    ['The Office', null, 'The Office', '2005-03-24', 'US', 'US'],
    ['The Office', null, 'The Office', '2001-07-09', 'US', 'UK'],
    ['Office', null, 'The Office', null, 'UK', 'GB'],
    ['Office', 2005, 'The Office', '1999', '', ''],
    ['Shameless US', 2011, 'Shameless (US)', 2011, '', ''],
    ['Totally Different', null, 'Show Name', null, '', ''],
    ['Grey Anatomy', null, "Grey's Anatomy", '2005-03-27', '', ''],
    ['CAFÉ', null, 'café', null, '', ''],
    ['', null, 'x', null, '', ''],
];
foreach ($cases as $case) {
    echo json_encode(['score' => $case, 'result' => $provider->scoreCandidate(...$case)], JSON_UNESCAPED_UNICODE).PHP_EOL;
}

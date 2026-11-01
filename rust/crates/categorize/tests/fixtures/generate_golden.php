<?php
// Regenerates golden.jsonl. Run from anywhere after rust/porting/php-ref-setup.sh:
//   php generate_golden.php <php-final checkout> < corpus.txt > golden.jsonl
//   php generate_golden.php <php-final checkout> < branches.txt > golden_branches.jsonl
// branches.txt names one input for each rule outcome; its rows also record the cleaned name.
declare(strict_types=1);

$ref = $argv[1];
require $ref.'/vendor/autoload.php';
$app = require $ref.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\Categorization\CategorizationPipeline;
use App\Services\NameFixing\Extractors\ObfuscatedSubjectExtractor;
use App\Services\NameFixing\NzbSplitUnwrapper;
use App\Traits\DetectsHashedNames;
use Illuminate\Support\Facades\DB;

$hashed = new class { use DetectsHashedNames; };
$groups = ['', 'alt.binaries.anime', 'alt.binaries.hdtv', 'alt.binaries.movies', 'alt.binaries.erotica',
    'alt.binaries.sounds.mp3', 'alt.binaries.games', 'alt.binaries.warez', 'alt.binaries.e-book', 'alt.binaries.misc'];
$posters = ['', 'someone@animetosho.org', '<PC@MASTER.RACE>', 'anon@y.com', 'nobody@example.com'];

DB::beginTransaction();
$groupIds = [];
foreach ($groups as $index => $group) {
    if ($group !== '') {
        $groupIds[$group] = DB::table('usenet_groups')->insertGetId(['name' => $group.'.golden', 'description' => 'golden']);
    }
}
$pipeline = CategorizationPipeline::createDefault();
$set = function (bool $foreign, bool $webDl) use ($pipeline): void {
    $reflection = new ReflectionObject($pipeline);
    $reflection->getProperty('categorizeForeign')->setValue($pipeline, $foreign);
    $reflection->getProperty('catWebDL')->setValue($pipeline, $webDl);
};
$unwrapper = new NzbSplitUnwrapper;
$extractor = new ObfuscatedSubjectExtractor;

$line = 0;
while (($raw = fgets(STDIN)) !== false) {
    $name = preg_replace_callback('/\\\\(n|\\\\)/', static fn (array $m): string => $m[1] === 'n' ? "\n" : '\\', rtrim($raw, "\n"));
    $line++;
    $names = ['n' => $name, 'h' => $hashed->isHashedOrGibberish($name), 'u' => $unwrapper->unwrap($name), 'x' => $extractor->extract($name), 'r' => []];
    $group = $groups[$line % count($groups)];
    $poster = $posters[$line % count($posters)];
    foreach ([[true, true, '', ''], [false, false, '', ''], [true, false, $group, $poster], [false, true, $group, $poster]] as [$foreign, $webDl, $g, $p]) {
        $set($foreign, $webDl);
        $result = $pipeline->categorize($g === '' ? 0 : $groupIds[$g], $name, $p, true);
        $names['r'][] = [
            'f' => $foreign, 'w' => $webDl, 'g' => $g === '' ? '' : $g.'.golden', 'p' => $p,
            'c' => $result['categories_id'], 'm' => $result['debug']['matched_by'],
            'v' => $result['debug']['final_confidence'], 'l' => $result['debug']['locked_to_misc'],
            'e' => $result['debug']['release_name'],
        ];
    }
    echo json_encode($names, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
}
DB::rollBack();

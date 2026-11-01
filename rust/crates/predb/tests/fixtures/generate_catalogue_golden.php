<?php
// Regenerates catalogue_golden.json.gz.b64: the catalogue sources' parsers run by the PHP over the sample
// payloads in catalogues/ and the inputs its own tests use, with each result or error message.
// php generate_catalogue_golden.php <php-final checkout> | gzip -9n | base64 | tr -d '\n' > catalogue_golden.json.gz.b64
// Compressed because the cases repeat their page templates (about 200 KB of JSON against 30 KB), and
// base64 because the repository normalises line endings in text files, which would corrupt raw gzip.
// nzedb-2024-05-02-subset.csv is kept with LF endings for the same reason; the producer's records end
// in CRLF, so the inputs restore them.
//
// An input is a spec, resolved the same way here and in tests/catalogues.rs:
//   {"file": name} | {"text": s} | {"repeat": [s, n]} | {"gzip": spec} | {"concat": [spec, ...]}
// A case is {name, input spec, params, out: {"ok": result} or {"error": message}}.
// plus "ops": replace/cut_after/append/prepend/drop_tail/flip_from_end applied in order.
// Text that isn't valid UTF-8 is {"b64": ...}.
declare(strict_types=1);

$ref = $argv[1];
require $ref.'/vendor/autoload.php';
$app = require $ref.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config(['logging.default' => 'null']);
date_default_timezone_set('UTC');

use App\Services\Predb\Feeds\PredbFeedEntry;
use App\Services\Predb\Feeds\Sources\CrowdnfoSource;
use App\Services\Predb\Feeds\Sources\DatsPcIsoSource;
use App\Services\Predb\Feeds\Sources\G4uSource;
use App\Services\Predb\Feeds\Sources\GabberSource;
use App\Services\Predb\Feeds\Sources\KnabenSource;
use App\Services\Predb\Feeds\Sources\Nima4kSource;
use App\Services\Predb\Feeds\Sources\NswdbSource;
use App\Services\Predb\Feeds\Sources\NzedbArchiveSource;
use App\Services\Predb\Feeds\Sources\SceneArchiveSqlSource;
use App\Services\Predb\Feeds\Sources\SeriousAboutSoundSource;
use App\Services\Predb\Feeds\Sources\ThreeDsDbSource;
use Tests\Unit\G4uSourceTest;
use Tests\Unit\GabberSourceTest;
use Tests\Unit\Nima4kSourceTest;
use Tests\Unit\Predb\CrowdnfoMalformedInputTest;
use Tests\Unit\Predb\CrowdnfoSourceTest;
use Tests\Unit\Predb\DatsPcIsoSourceTest;
use Tests\Unit\Predb\KnabenSourceTest;
use Tests\Unit\Predb\NswdbSourceTest;
use Tests\Unit\Predb\NzedbArchiveSourceTest;
use Tests\Unit\Predb\SceneArchiveSqlSourceTest;
use Tests\Unit\Predb\SeriousAboutSoundSourceTest;

const BASE = __DIR__.'/catalogues';

function enc(string $s): string|array
{
    return mb_check_encoding($s, 'UTF-8') ? $s : ['b64' => base64_encode($s)];
}

function arg(string|array $v): string
{
    return is_array($v) ? base64_decode($v['b64']) : $v;
}

function resolve(array $s): string
{
    if (isset($s['file'])) {
        $b = file_get_contents(BASE.'/'.$s['file']);
    } elseif (isset($s['text'])) {
        $b = arg($s['text']);
    } elseif (isset($s['repeat'])) {
        $b = str_repeat(arg($s['repeat'][0]), $s['repeat'][1]);
    } elseif (isset($s['gzip'])) {
        $b = gzencode(resolve($s['gzip']));
    } elseif (isset($s['concat'])) {
        $b = implode('', array_map('resolve', $s['concat']));
    } else {
        throw new LogicException('bad spec');
    }
    foreach ($s['ops'] ?? [] as $op) {
        $b = match ($op[0]) {
            'replace' => str_replace(arg($op[1]), arg($op[2]), $b),
            'cut_after' => substr($b, 0, strpos($b, arg($op[1])) + strlen(arg($op[1]))),
            'append' => $b.arg($op[1]),
            'prepend' => arg($op[1]).$b,
            'drop_tail' => substr($b, 0, -$op[1]),
            'flip_from_end' => (static function () use ($b, $op): string {
                $b[strlen($b) - $op[1]] = chr(ord($b[strlen($b) - $op[1]]) ^ 255);

                return $b;
            })(),
        };
    }

    return $b;
}

function T(string $s, array $ops = []): array
{
    return ['text' => enc($s)] + ($ops ? ['ops' => $ops] : []);
}
function Fi(string $name, array $ops = []): array
{
    return ['file' => $name] + ($ops ? ['ops' => $ops] : []);
}
function Gz(array $inner, array $ops = []): array
{
    return ['gzip' => $inner] + ($ops ? ['ops' => $ops] : []);
}
function rep(string $a, string $b): array
{
    return ['replace', enc($a), enc($b)];
}

const LABELS = ['g4u' => 'g4u.to', 'gabber' => 'gabber.od.ua', 'crowdnfo' => 'crowdnfo.net', 'seriousaboutsound' => 'seriousaboutsound.net',
    'nima4k' => 'nima4k.org', 'knaben' => 'knaben.org', 'nswdb' => 'nswdb.com', '3dsdb' => '3dsdb.com', 'dats_pciso_1998' => 'dats.site',
    'nzedb_archive' => 'nzedb-pre-dumps'];

/** [title] or [title, category]. These sources fill nothing else, which is asserted here and in the Rust test. */
function dump(array $entries): array
{
    global $currentSource;

    return array_map(static function (PredbFeedEntry $e) use ($currentSource): array {
        if ($e->source !== LABELS[$currentSource] || $e->size !== null || $e->files !== null || $e->predate !== null || $e->nuked !== 0
            || $e->nukeReason !== null || $e->listedAt !== null || $e->enrichOnly !== false) {
            throw new LogicException('unexpected entry fields for '.$currentSource);
        }

        return $e->category === null ? [$e->title] : [$e->title, $e->category];
    }, $entries);
}

function phpArgs(array $p): array
{
    $map = ['page_size' => 'pageSize', 'max_bytes' => 'maximumBodyBytes', 'max_records' => 'maximumRecords', 'max_csv_bytes' => 'maximumCsvBytes',
        'max_file_bytes' => 'maxFileBytes', 'max_statement_bytes' => 'maxStatementBytes', 'max_scratch_bytes' => 'maxScratchBytes',
        'max_bucket_bytes' => 'maxBucketBytes', 'max_frame_index_bytes' => 'maxFrameIndexBytes'];
    $out = [];
    foreach ($p as $k => $v) {
        $out[$map[$k]] = $v;
    }

    return $out;
}

$G = [];
function add(string $source, string $name, array $spec, array $params, callable $run): void
{
    global $G;
    foreach ($G[$source] ?? [] as $existing) {
        if ($existing['name'] === $name) {
            throw new LogicException("duplicate case $source/$name");
        }
    }
    global $currentSource;
    $currentSource = $source;
    try {
        $out = ['ok' => $run(resolve($spec), $params)];
    } catch (Throwable $e) {
        $out = ['error' => $e->getMessage()];
    }
    $G[$source][] = ['name' => $name, 'input' => $spec, 'params' => (object) $params, 'out' => $out];
}

$obj = static fn (array $a): object => (object) $a;

// ---- HTML sources -------------------------------------------------------------------------
$html = static fn (string $class) => static fn (string $b, array $p): array => ['entries' => dump((new $class('x', ...phpArgs($p)))->parseHtml($b))];

// G4U
foreach (['fixture' => [], 'page size 2' => ['page_size' => 2], 'page size 12' => ['page_size' => 12]] as $n => $p) {
    add('g4u', $n, Fi('g4u.html'), $p, $html(G4uSource::class));
}
$g4uHeader = '<div><h1>Updates from today, 2026-10-11</h1><span class="w3-right">1</span></div>';
$g4uCard = static fn (string $t): string => '<a href="/en/1-editorial-game-name"><ul class="mgame"><li class="mtitel"><b>Editorial Game Name</b><div class="w3-opacity-min">'.htmlspecialchars($t, ENT_QUOTES, 'UTF-8').'</div></li></ul></a>';
$g4uPage = static fn (string $cards, int $n): string => '<html><body><div class="w3-content w3-center w3-light-grey"><div><h1>Updates from today, 2026-10-11</h1><span class="w3-right">'.$n.'</span></div>'.$cards.'</div></body></html>';
foreach (G4uSourceTest::invalidListings() as $n => [$h]) {
    add('g4u', "invalid: $n", T($h), [], $html(G4uSource::class));
}
add('g4u', 'next heading ends the section', T(str_replace('</div></body>', '<div><h2>Updates from yesterday, 2026-10-10</h2></div><a>Invalid older card</a></div></body>', $g4uPage($g4uCard('Today.Release-GRP'), 1))), [], $html(G4uSource::class));
$cards = '';
for ($i = 1; $i <= 12; $i++) {
    $cards .= $g4uCard('Game.'.$i.'-GRP');
}
add('g4u', 'twelve cards, size 50', T($g4uPage($cards, 12)), ['page_size' => 50], $html(G4uSource::class));
add('g4u', 'twelve cards, size 2', T($g4uPage($cards, 12)), ['page_size' => 2], $html(G4uSource::class));
add('g4u', 'invalid card past the size', T($g4uPage($g4uCard('Good.Release-GRP').$g4uCard('Editorial Game Name'), 2)), ['page_size' => 1], $html(G4uSource::class));
$unicode = str_replace('</li></ul>', '<div><span class="w3-opacity-min">Windows; 3 GB; 2023</span></div></li></ul>', str_replace('Léon_天空', 'L&#233;on_&#x5929;&#x7a7a;', $g4uCard('Léon_天空.DD+5.1.H.264-MixedGroup')));
add('g4u', 'numeric references decode', T($g4uPage($unicode, 1)), [], $html(G4uSource::class));
add('g4u', 'entities decode once', T($g4uPage($g4uCard('Literal.&amp;.Name-GRP'), 1)), [], $html(G4uSource::class));
add('g4u', 'title of 255 characters', T($g4uPage($g4uCard(str_repeat('é', 251).'-GRP'), 1)), [], $html(G4uSource::class));
add('g4u', 'C1 reference in a title', T($g4uPage($g4uCard('Exact.Release-GRP'), 1), [rep('Exact.Release-GRP', 'Exact.&#x85;Release-GRP')]), [], $html(G4uSource::class));
add('g4u', 'FFFE reference in a title', T($g4uPage($g4uCard('Exact.Release-GRP'), 1), [rep('Exact.Release-GRP', 'Exact.&#xFFFE;Release-GRP')]), [], $html(G4uSource::class));
add('g4u', 'huge declared count', T($g4uPage($g4uCard('A.B-C'), 1), [rep('>1</span>', '>99999999999999999999999</span>')]), [], $html(G4uSource::class));
add('g4u', 'body limit exact', Fi('g4u.html'), ['max_bytes' => strlen(file_get_contents(BASE.'/g4u.html'))], $html(G4uSource::class));
add('g4u', 'body limit one short', Fi('g4u.html'), ['max_bytes' => strlen(file_get_contents(BASE.'/g4u.html')) - 1], $html(G4uSource::class));
add('g4u', 'empty', T(''), [], $html(G4uSource::class));
add('g4u', 'comment between cards', T($g4uPage('<!-- c -->'.$g4uCard('Exact.Release-GRP'), 1)), [], $html(G4uSource::class));

// Gabber
foreach (['fixture' => [], 'page size 2' => ['page_size' => 2]] as $n => $p) {
    add('gabber', $n, Fi('gabber.html'), $p, $html(GabberSource::class));
}
$gabberPage = static function (array $titles): string {
    $items = '';
    foreach ($titles as $t) {
        $items .= '<li><a href="#irrelevant">'.htmlspecialchars($t, ENT_QUOTES, 'UTF-8').'</a></li>';
    }

    return '<div class="view-id-0day_releases"><div class="view-content"><h3><a><span class="date-display-single">2026-10-10</span></a></h3><div class="views-row"><div class="views-field-field-genre"><div class="field-content">Hardcore</div></div><div class="views-field-body"><div class="field-content"><ul class="0day-hardcore">'.$items.'</ul></div></div></div></div></div>';
};
$g = file_get_contents(BASE.'/gabber.html');
add('gabber', 'hrefs and older content never supply identity', T(str_replace('Older content is outside the latest scope.', '<a>Invented_Older_Release-WEB-2026</a>', preg_replace('/href="[^"]*"/', 'href="#unrelated-slug"', $g))), [], $html(GabberSource::class));
add('gabber', 'case duplicates keep the first spelling', T($gabberPage(["DJ_OSkar_-_I'm_Over_You-WEB-2026", "dj_oskar_-_i'm_over_you-web-2026", 'DJ_OSkar_-_Im_Over_You-WEB-2026'])), ['page_size' => 2], $html(GabberSource::class));
add('gabber', 'entities decode once', T($gabberPage(["Léon_天空_(It's_Music)_DD+5.1-WEB-2026", 'Literal_&amp;_Name-WEB-2026'])), [], $html(GabberSource::class));
add('gabber', 'forty-two names', T($gabberPage(array_map(static fn (int $n): string => 'Music_'.$n.'-WEB-2026', range(1, 42)))), [], $html(GabberSource::class));
add('gabber', 'forty-two names, size 2', T($gabberPage(array_map(static fn (int $n): string => 'Music_'.$n.'-WEB-2026', range(1, 42)))), ['page_size' => 2], $html(GabberSource::class));
add('gabber', 'malformed name past the size', T($gabberPage(['Music-WEB-2026', 'Human album heading'])), ['page_size' => 1], $html(GabberSource::class));
add('gabber', 'title of 255 characters', T($gabberPage([str_repeat('é', 246).'-WEB-2026'])), [], $html(GabberSource::class));
add('gabber', 'C1 reference in a name', T($gabberPage(['Exact_Title-WEB-2026']), [rep('Exact_Title-WEB-2026', 'Exact&#x85;Title-WEB-2026')]), [], $html(GabberSource::class));
add('gabber', 'body limit exact', Fi('gabber.html'), ['max_bytes' => strlen($g)], $html(GabberSource::class));
add('gabber', 'body limit one short', Fi('gabber.html'), ['max_bytes' => strlen($g) - 1], $html(GabberSource::class));
$gp = $gabberPage(['Exact_Title-WEB-2026']);
$gHeader = '<h3><a><span class="date-display-single">2026-10-10</span></a></h3>';
$gRow = substr($gp, strpos($gp, '<div class="views-row">'), -12);
$gabberInvalid = [
    'error page' => '<html><body>Unavailable</body></html>',
    'human RSS' => '<rss><channel><item><title>Artist - Human Album Title</title></item></channel></rss>',
    'duplicate catalogue' => $gp.$gp,
    'missing content' => str_replace('view-content', 'editorial', $gp),
    'duplicate content' => str_replace('class="view-id-0day_releases">', 'class="view-id-0day_releases"><div class="view-content"></div>', $gp),
    'missing day' => str_replace($gHeader, '', $gp),
    'invalid date' => str_replace('2026-10-10', '2026-02-31', $gp),
    'nested date' => str_replace('>2026-10-10<', '><b>2026-10-10</b><', $gp),
    'multiple dates' => str_replace('</a></h3>', '<span class="date-display-single">2026-10-10</span></a></h3>', $gp),
    'extra heading link' => str_replace('</h3>', '<a>Other</a></h3>', $gp),
    'content before day' => str_replace($gHeader, '<aside>Editorial</aside>'.$gHeader, $gp),
    'empty day' => '<div class="view-id-0day_releases"><div class="view-content">'.$gHeader.'</div></div>',
    'unrecognized latest node' => str_replace($gHeader, $gHeader.'<aside>Editorial</aside>', $gp),
    'unexpected latest text' => str_replace($gHeader, $gHeader.'Editorial', $gp),
    'unrecognized row' => str_replace('class="views-row"', 'class="editorial"', $gp),
    'missing genre' => str_replace('views-field-field-genre', 'editorial', $gp),
    'duplicate genre field' => str_replace('<div class="views-field-body">', '<div class="views-field-field-genre"><div class="field-content">Hardcore</div></div><div class="views-field-body">', $gp),
    'duplicate genre row' => str_replace($gRow, $gRow.$gRow, $gp),
    'unknown genre' => str_replace('>Hardcore<', '>Requests<', $gp),
    'list disagrees with genre' => str_replace('0day-hardcore', '0day-hardstyle', $gp),
    'nested genre' => str_replace('>Hardcore<', '><b>Hardcore</b><', $gp),
    'extra body child' => str_replace('</ul>', '</ul><a>Hidden_Name-WEB-2026</a>', $gp),
    'extra row child' => str_replace('<div class="views-field-body">', '<aside>Editorial</aside><div class="views-field-body">', $gp),
    'duplicate directory list' => str_replace('</ul>', '</ul><ul class="0day-hardcore"><li><a>Other_Name-WEB-2026</a></li></ul>', $gp),
    'empty directory list' => str_replace('<li><a href="#irrelevant">Exact_Title-WEB-2026</a></li>', '', $gp),
    'unexpected list child' => str_replace('</ul>', '<div>Hidden_Name-WEB-2026</div></ul>', $gp),
    'extra item link' => str_replace('</li>', '<a>Other_Name-WEB-2026</a></li>', $gp),
    'extra item text' => str_replace('</a></li>', '</a>Extra</li>', $gp),
    'nested literal' => str_replace('>Exact_Title-WEB-2026<', '><b>Exact_Title-WEB-2026</b><', $gp),
    'human literal' => str_replace('Exact_Title-WEB-2026', 'Human Album Title', $gp),
    'empty literal' => str_replace('Exact_Title-WEB-2026', '', $gp),
    'leading space' => str_replace('Exact_Title-WEB-2026', ' Exact_Title-WEB-2026', $gp),
    'internal tab' => str_replace('Exact_Title-WEB-2026', "Exact\tTitle-WEB-2026", $gp),
    'NBSP' => str_replace('Exact_Title-WEB-2026', 'Exact&nbsp;Title-WEB-2026', $gp),
    'format character' => str_replace('Exact_Title-WEB-2026', 'Exact&#x200b;Title-WEB-2026', $gp),
    'DEL' => str_replace('Exact_Title-WEB-2026', 'Exact&#x7f;Title-WEB-2026', $gp),
    'raw NUL' => str_replace('Exact_Title-WEB-2026', "Exact\0Title-WEB-2026", $gp),
    'encoded NUL' => str_replace('Exact_Title-WEB-2026', 'Exact&#0;Title-WEB-2026', $gp),
    'surrogate' => str_replace('Exact_Title-WEB-2026', 'Exact&#xd800;Title-WEB-2026', $gp),
    'invalid UTF8' => str_replace('Exact_Title-WEB-2026', "Exact\xc3\x28-WEB-2026", $gp),
    'overlength identity' => str_replace('Exact_Title-WEB-2026', str_repeat('é', 247).'-WEB-2026', $gp),
];
foreach ($gabberInvalid as $n => $h) {
    add('gabber', "invalid: $n", T($h), [], $html(GabberSource::class));
}

// crowdNFO
foreach (['fixture' => [], 'page size 1' => ['page_size' => 1]] as $n => $p) {
    add('crowdnfo', $n, Fi('crowdnfo-home.html'), $p, $html(CrowdnfoSource::class));
}
foreach (CrowdnfoSourceTest::invalidChanges() as $n => [$from, $to]) {
    add('crowdnfo', "invalid: $n", Fi('crowdnfo-home.html', [rep($from, $to)]), [], $html(CrowdnfoSource::class));
}
$cTitle = 'American.Pickers.S02E06.Im.Bickerhimmel.GERMAN.DOKU.WS.HDTVRiP.XviD-TVP';
foreach (CrowdnfoMalformedInputTest::invalidTitleBytes() as $n => [$insert]) {
    add('crowdnfo', "bytes: $n", Fi('crowdnfo-home.html', [rep($cTitle, 'American.'.$insert.'Pickers.S02E06.Im.Bickerhimmel.GERMAN.DOKU.WS.HDTVRiP.XviD-TVP')]), [], $html(CrowdnfoSource::class));
}
foreach (CrowdnfoMalformedInputTest::duplicateAttributes() as $n => [$attribute]) {
    add('crowdnfo', "duplicate attribute: $n", Fi('crowdnfo-home.html', [rep('href="/release/312884"', 'href="/release/312884" '.$attribute)]), [], $html(CrowdnfoSource::class));
}
add('crowdnfo', 'truncated heading', Fi('crowdnfo-home.html', [['cut_after', $cTitle]]), [], $html(CrowdnfoSource::class));
add('crowdnfo', 'truncated heading with document closing', Fi('crowdnfo-home.html', [['cut_after', $cTitle], ['append', '</body></html>']]), [], $html(CrowdnfoSource::class));
add('crowdnfo', 'numeric references and layout whitespace', Fi('crowdnfo-home.html', [rep($cTitle, 'L&#233;on_&#x5929;&#x7A7A;.1994.DD+5.1.H.264-MixedGroup')]), [], $html(CrowdnfoSource::class));
add('crowdnfo', 'C1 reference in a title', Fi('crowdnfo-home.html', [rep($cTitle, 'American.&#x85;Pickers.S02E06-TVP')]), [], $html(CrowdnfoSource::class));
add('crowdnfo', 'tag-like text in comments and scripts', Fi('crowdnfo-home.html', [rep('</body>', '<!-- <div><a href="/release/9"> --><script>const ignored = "<div><a href=\'/release/9\'>";</script></body>')]), [], $html(CrowdnfoSource::class));
add('crowdnfo', 'unterminated comment', Fi('crowdnfo-home.html', [rep('</body>', '<!-- unfinished</body>')]), [], $html(CrowdnfoSource::class));
add('crowdnfo', 'unterminated script', Fi('crowdnfo-home.html', [rep('</body>', '<script>const unfinished = "</body>')]), [], $html(CrowdnfoSource::class));
add('crowdnfo', 'invalid UTF-8', T("\xff"), [], $html(CrowdnfoSource::class));
add('crowdnfo', 'body limit', Fi('crowdnfo-home.html'), ['max_bytes' => 100], $html(CrowdnfoSource::class));
add('crowdnfo', 'tab title', Fi('crowdnfo-home.html', [rep($cTitle, "American.Pickers\tS02E06-TVP")]), [], $html(CrowdnfoSource::class));
add('crowdnfo', 'NBSP trailing the title is not trimmed', Fi('crowdnfo-home.html', [rep($cTitle.'</a>', $cTitle.'&nbsp;</a>')]), [], $html(CrowdnfoSource::class));

// SeriousAboutSound
$serious = SeriousAboutSoundSourceTest::class;
foreach (['fixture' => [], 'page size 1' => ['page_size' => 1]] as $n => $p) {
    add('seriousaboutsound', $n, Fi('seriousaboutsound-releases.html'), $p, $html(SeriousAboutSoundSource::class));
}
add('seriousaboutsound', 'whole scope validated before the cap', Fi('seriousaboutsound-releases.html', [rep('data-rowid="53220"', 'data-rowid="53257"')]), ['page_size' => 1], $html(SeriousAboutSoundSource::class));
foreach ($serious::invalidMutations() as $n => [$from, $to]) {
    add('seriousaboutsound', "invalid: $n", Fi('seriousaboutsound-releases.html', [rep($from, $to)]), [], $html(SeriousAboutSoundSource::class));
}
add('seriousaboutsound', 'unicode punctuation is not transliterated', Fi('seriousaboutsound-releases.html', [rep('Nerutto_-_Elements-(DP26)-SINGLE-WEB-2022-iDC', 'Rosalía_天空-(CAT1)-WEB-2026-GRP')]), [], $html(SeriousAboutSoundSource::class));
add('seriousaboutsound', 'body limit', Fi('seriousaboutsound-releases.html'), ['max_bytes' => 100], $html(SeriousAboutSoundSource::class));
add('seriousaboutsound', 'C1 reference in a title', Fi('seriousaboutsound-releases.html', [rep('Nerutto_-_Elements-(DP26)-SINGLE-WEB-2022-iDC', 'Nerutto_&#x85;Elements-(DP26)-iDC')]), [], $html(SeriousAboutSoundSource::class));
add('seriousaboutsound', 'only pinned rows', Fi('seriousaboutsound-releases.html', [['cut_after', 'Renegade\'s Corner</span></a></span></h4></div></li>'], ['append', "\n</ol></body></html>"]]), [], $html(SeriousAboutSoundSource::class));

// NIMA4K
$h = static fn (string $t): string => htmlspecialchars($t, ENT_QUOTES, 'UTF-8');
$card = static fn (string $t): string => '<div class="article uk-clearfix"><div class="cover-box"><img alt="'.$h($t).'"></div><div class="article-right"><h2><span class="subtitle uk-visible-large">'.$h($t).'</span></h2></div></div>';
$nima = $html(Nima4kSource::class);
foreach (['fixture' => [], 'page size 3' => ['page_size' => 3]] as $n => $p) {
    add('nima4k', $n, Fi('nima4k.html'), $p, $nima);
}
add('nima4k', 'record limit keeps literals', T($card('Léon_天空.1994.DD+5.1.H.264-MixedGroup').$card('Second.Release-GRP')), ['page_size' => 1], $nima);
foreach (Nima4kSourceTest::invalidListings() as $n => [$page]) {
    add('nima4k', "invalid: $n", T($page), [], $nima);
}
$thin = static fn (string $n, array $labels): bool => array_reduce($labels, static fn (bool $carry, string $label): bool => $carry || str_ends_with($n, ' '.$label), false);
$position = 0;
foreach (Nima4kSourceTest::malformedLiteralCharacters() as $n => [$character, $inCover]) {
    // Both positions share one code path; sample the cover for a quarter of the characters.
    if ($inCover && $position++ % 4 !== 0) {
        continue;
    }
    $c = $card('Exact.Release-GRP');
    $page = $inCover ? str_replace('alt="Exact.Release-GRP"', 'alt="Exact.'.$character.'Release-GRP"', $c) : str_replace('>Exact.Release-GRP</span>', '>Exact.'.$character.'Release-GRP</span>', $c);
    add('nima4k', "character: $n", T($page), [], $nima);
}
$formatted = "\t\r\n".$card('Léon_天空.&amp;&#1;.DD+5.1-GRP')."\r\n\t";
$formatted = str_replace('<h2>', "<h2>\r\n\t", $formatted);
$formatted = str_replace('>Léon_天空.', ">\t\r\nLéon_天空.", $formatted);
add('nima4k', 'valid formatting and entities', T(str_replace('</span>', "\t\r\n</span>", $formatted)), [], $nima);
$between = static function (string $family, array $fragments, ?callable $wrap = null) use ($card, $nima): void {
    foreach ($fragments as $n => [$fragment]) {
        $page = $card('First.Release-GRP').$fragment.$card('Second.Release-GRP');
        add('nima4k', "$family: $n", T($wrap ? $wrap($page) : $page), ['page_size' => 1], $nima);
    }
};
$leon = static fn (string $fragment): string => $card('Léon.Release-GRP').$fragment.$card('Second.Release-GRP');
foreach (Nima4kSourceTest::inertNumericReferenceFragments() as $n => [$f]) {
    add('nima4k', "inert numeric: $n", T($card('Léon_天空.&amp;&#1;.DD+5.1-GRP').$f.$card('Second.Release-GRP')), ['page_size' => 1], $nima);
}
$between('decoded invalid reference', Nima4kSourceTest::decodedInvalidReferenceFragments());
$between('complete parser fragment', Nima4kSourceTest::completeParserFragments());
$between('discarded parser fragment', array_filter(Nima4kSourceTest::discardedParserFragments(), static fn (string $n): bool => $thin($n, ['safe', 'C1', 'nul']), ARRAY_FILTER_USE_KEY));
$between('well formed closing', Nima4kSourceTest::wellFormedClosingFragments());
$between('malformed closing', array_filter(Nima4kSourceTest::malformedClosingFragments(), static fn (string $n): bool => $thin($n, ['safe', 'nul']), ARRAY_FILTER_USE_KEY));
$between('numeric whitespace C1', Nima4kSourceTest::numericWhitespaceC1Controls());
$between('numeric CR mixed invalid', array_map(static fn (array $a): array => ['<svg xmlns="http://www.w3.org/2000/svg"><script>&#13;'.$a[0].'</script></svg>'], Nima4kSourceTest::numericCrMixedInvalidReferences()));
$between('semantic decoded numeric', Nima4kSourceTest::semanticDecodedNumericFragments());
$between('delayed review active tail', array_map(static fn (array $a): array => [$a[0]], Nima4kSourceTest::delayedReviewActiveTails()));
foreach (Nima4kSourceTest::wellFormedStructuralTags() as $n => [$tag]) {
    $attribute = ' data-marker="safe"';
    add('nima4k', "structural tag: $n", T('<html'.($tag === 'html' ? $attribute : '').'><head'.($tag === 'head' ? $attribute : '').'></head><body'.($tag === 'body' ? $attribute : '').'>'.$card('First.Release-GRP').$card('Second.Release-GRP').'</body></html>'), ['page_size' => 1], $nima);
}
foreach (array_filter(Nima4kSourceTest::misplacedStructuralFragments(), static fn (string $n): bool => $thin($n, ['safe', 'C1']), ARRAY_FILTER_USE_KEY) as $n => [$fragment]) {
    add('nima4k', "misplaced structural: $n", T('<html><head></head><body>'.$card('First.Release-GRP').$fragment.$card('Second.Release-GRP').'</body></html>'), ['page_size' => 1], $nima);
}
foreach (array_filter(Nima4kSourceTest::malformedParserFragments(), static fn (string $n): bool => $thin($n, ['safe', 'nul', 'C1']), ARRAY_FILTER_USE_KEY) as $n => [$prolog, $fragment]) {
    add('nima4k', "malformed parser: $n", T($prolog.$card('First.Release-GRP').$fragment.$card('Second.Release-GRP')), ['page_size' => 1], $nima);
}
foreach (Nima4kSourceTest::numericWhitespaceContexts() as $n => [$f]) {
    add('nima4k', "numeric whitespace context: $n", T($leon($f)), [], $nima);
}
foreach (Nima4kSourceTest::numericWhitespaceEscapedLabels() as $n => [$literal]) {
    add('nima4k', "numeric whitespace label: $n", T($card($literal)), [], $nima);
}
foreach (Nima4kSourceTest::numericWhitespaceCrLabels() as $n => [$reference]) {
    add('nima4k', "numeric CR in label: $n", T(str_replace('Exact.Release-GRP', 'Exact'.$reference.'Release-GRP', $card('Exact.Release-GRP'))), [], $nima);
}
foreach (Nima4kSourceTest::numericCrTokenSpellings() as $n => [$reference]) {
    add('nima4k', "numeric CR spelling: $n", T($card('Léon.Release-GRP').'<p>before'.$reference.' after</p>'.$card('Second.Release-GRP')), [], $nima);
}
foreach (Nima4kSourceTest::standardUtf8AliasDeclarations() as $n => [$declaration]) {
    add('nima4k', "utf-8 alias: $n", T('<html><head>'.$declaration.'</head><body>'.$card('Léon_天空.Release-GRP').'</body></html>'), [], $nima);
}
foreach (Nima4kSourceTest::semanticQuotedUtf8Parameters() as $n => [$charset, $afterFirst]) {
    $meta = '<meta http-equiv="Content-Type" content="'.$h('text/html; charset='.$charset).'">';
    add('nima4k', "quoted utf-8: $n", T($afterFirst ? $card('First.Release-GRP').$meta.$card('Léon.Release-GRP') : '<html><head>'.$meta.'</head><body>'.$card('First.Release-GRP').$card('Léon.Release-GRP').'</body></html>'), [], $nima);
}
foreach (Nima4kSourceTest::semanticConflictingQuotedParameters() as $n => [$charset, $afterLimit]) {
    $meta = '<meta http-equiv="Content-Type" content="'.$h('text/html; charset='.$charset).'">';
    add('nima4k', "conflicting charset: $n", T(($afterLimit ? $card('First.Release-GRP') : '').$meta.$card('Léon.Release-GRP')), ['page_size' => 1], $nima);
}
foreach ([false, true] as $afterLimit) {
    $declaration = '<meta charset="unicode-1-1-utf-8x">';
    add('nima4k', 'utf-8 lookalike '.($afterLimit ? 'after limit' : 'first'), T($afterLimit ? $card('First.Release-GRP').$declaration.$card('Léon.Release-GRP') : '<html><head>'.$declaration.'</head><body>'.$card('Léon.Release-GRP').'</body></html>'), ['page_size' => 1], $nima);
}
foreach (['<meta charset=windows-1252>', '<meta http-equiv="content-type" content="text/html; charset=utf-8; charset=latin1">', '<meta http-equiv=" Content-Type " content="text/html;charset=UTF-8">', '<meta http-equiv="Content-Type" content="charset=\'utf-8\' junk">'] as $i => $declaration) {
    add('nima4k', "meta declaration $i", T($declaration.$card('Exact.Release-GRP')), [], $nima);
}
foreach (['text/html; charset=utf-8', 'text/html;charset="UTF-8"', "text/html; charset=' utf-8 '", 'text/html; CHARSET = utf8 ; x=1', 'text/html; charset=latin1', 'text/html; charset=utf-8; charset=latin1', 'charset="utf-8" junk', 'charset="utf-8', 'text/html', 'charset=utf-8 ;', 'x;charset=\'utf-8\'', "charset=\"utf-8\"\n"] as $i => $content) {
    add('nima4k', "content-type parameter $i", T('<meta http-equiv="Content-Type" content="'.$h($content).'">'.$card('Léon.Release-GRP')), [], $nima);
}
add('nima4k', 'template wrapper is excluded', T('<template>'.$card('Hidden.Release-GRP').'</template>'.$card('Visible.Release-GRP')), [], $nima);
foreach (['unterminated PI' => '<?unfinished ', 'terminated PI' => '<?note safe?>', 'proper comment' => '<!-- <!hidden> <![CDATA[hidden]]> -->'] as $n => $fragment) {
    add('nima4k', "declaration: $n", T($card('First.Release-GRP').$fragment), ['page_size' => 1], $nima);
}
foreach (['<!hidden>', '<![CDATA[hidden]]>', '<1>', '</b>', '<!--hidden-->', '<?hidden value?>'] as $n => $markup) {
    add('nima4k', "markup in subtitle: $markup", T(str_replace('>Exact.Release-GRP</span>', '>Exact.'.$markup.'Release-GRP</span>', $card('Exact.Release-GRP'))), [], $nima);
}
foreach (['&period;', '&Tab;'] as $reference) {
    add('nima4k', "html5 reference: $reference", T(str_replace('Exact.Release-GRP', 'Exact'.$reference.'Release-GRP', $card('Exact.Release-GRP'))), [], $nima);
}
foreach (['Exact&period;Release-GRP', 'Exact&amp;period;Release-GRP', 'Exact&Release-GRP'] as $literal) {
    add('nima4k', "escaped reference label: $literal", T($card($literal)), [], $nima);
}
add('nima4k', 'raw-text elements inside a card', T(str_replace('</h2>', '</h2><script>const x = "</scripted>";</script><textarea>foo </b> bar</textarea>', $card('Léon.Release-GRP')).$card('Second.Release-GRP')), [], $nima);
foreach (['iframe', 'xmp', 'noembed', 'noframes'] as $tag) {
    add('nima4k', "raw text element: $tag", T($leon('<'.$tag.'>fallback </b> text</'.$tag.'>')), [], $nima);
    add('nima4k', "semantic inert card: $tag", T('<'.$tag.'>'.$card('Hidden.Release-GRP').'</'.$tag.'>'.$card('Visible.Release-GRP')), ['page_size' => 1], $nima);
}
foreach (['title', 'textarea', 'template'] as $tag) {
    add('nima4k', "semantic inert card: $tag", T('<'.$tag.'>'.$card('Hidden.Release-GRP').'</'.$tag.'>'.$card('Visible.Release-GRP')), ['page_size' => 1], $nima);
}
foreach (Nima4kSourceTest::semanticInertNumericFragments() as $n => [$f]) {
    add('nima4k', "semantic inert numeric: $n", T($leon($f)), [], $nima);
}
foreach (Nima4kSourceTest::delayedReviewInertFragments() as $n => [$f]) {
    add('nima4k', "delayed review inert: $n", T($card('Exact.&period;.Release-GRP').$f.$card('Second.Release-GRP')), [], $nima);
}
add('nima4k', 'double escaped script hides a card', T('<script><!--<script></script>'.$card('Hidden.Release-GRP').'</script>'.$card('Visible.Release-GRP')), ['page_size' => 1], $nima);
add('nima4k', 'wide sibling layout', T(str_repeat('<i></i><span></span>', 64).implode('', array_map(static fn (int $i): string => '<section>'.$card('Exact.'.$i.'-GRP').'</section>', range(0, 7)))), ['page_size' => 1], $nima);
add('nima4k', 'wide sibling layout, invalid last card', T(str_repeat('<i></i><span></span>', 64).implode('', array_map(static fn (int $i): string => '<section>'.($i === 7 ? str_replace('alt="Exact.7-GRP"', 'alt="Different-GRP"', $card('Exact.7-GRP')) : $card('Exact.'.$i.'-GRP')).'</section>', range(0, 7)))), ['page_size' => 1], $nima);
add('nima4k', 'card after the record limit is malformed', T($card('First.Release-GRP').str_replace('Second.Release-GRP</span>', 'Second.&#1;Release-GRP</span>', $card('Second.Release-GRP'))), ['page_size' => 1], $nima);
foreach ([251, 252] as $depth) {
    add('nima4k', "nesting depth $depth", T(str_repeat('<div>', $depth).$card('Exact.Release-GRP').str_repeat('</div>', $depth)), [], $nima);
}
add('nima4k', 'body limit', Fi('nima4k.html'), ['max_bytes' => 100], $nima);
add('nima4k', 'body limit exact', Fi('nima4k.html'), ['max_bytes' => strlen(file_get_contents(BASE.'/nima4k.html'))], $nima);
add('nima4k', 'empty', T(''), [], $nima);
add('nima4k', 'empty subtitle after trim', T(str_replace('>Exact.Release-GRP</span>', '> </span>', $card('Exact.Release-GRP'))), [], $nima);

// ---- Knaben -------------------------------------------------------------------------------
$knaben = static function (string $b, array $p): array {
    $s = new KnabenSource('x', ...phpArgs($p));
    $e = $s->parseJson($b);

    return ['entries' => dump($e), 'rejections' => (object) $s->rejectionCounts(), 'accepted' => $s->acceptedCount()];
};
$response = static fn (array $changes = []): string => json_encode(['hits' => [array_replace([
    'title' => 'Release.2026.1080p-GROUP_Uploader', 'category' => 'Movies / HD', 'categoryId' => [3001000, 3000000], 'date' => null,
], $changes)], 'total' => ['relation' => 'eq', 'value' => 1]], JSON_THROW_ON_ERROR);
add('knaben', 'fixture', Fi('knaben-movies.json'), ['page_size' => 10], $knaben);
add('knaben', 'fixture, hit limit', Fi('knaben-movies.json'), ['page_size' => 9], $knaben);
add('knaben', 'fixture, size above the API cap', Fi('knaben-movies.json'), ['page_size' => 999], $knaben);
foreach (KnabenSourceTest::nonliteralTitles() as $n => [$title]) {
    add('knaben', "title: $n", T($response(['title' => $title])), [], $knaben);
}
add('knaben', 'custom category and date', T($response(['category' => 'Movies / Custom', 'date' => '2026-10-11T07:57:00+00:00'])), [], $knaben);
add('knaben', 'empty category is kept', T($response(['category' => ''])), [], $knaben);
add('knaben', 'null category', T($response(['category' => null])), [], $knaben);
add('knaben', 'non-movie category', T($response(['categoryId' => [2000000], 'category' => 'TV / HD'])), [], $knaben);
add('knaben', 'ignored members with tokens in strings', T('{"hits":[{"title":"Release.2026.1080p-GROUP_Uploader","category":"Movies / HD","categoryId":[3001000,3000000],"date":null}],"total":{"relation":"gte","value":5},"ignored":{"punctuation":"\\"},[\\\\","objects":[{"same":true},{"same":null}],"number":2.5}}'), [], $knaben);
foreach (KnabenSourceTest::malformedResponses() as $n => [$json]) {
    add('knaben', "response: $n", T($json), ['page_size' => 10], $knaben);
}
foreach (KnabenSourceTest::malformedHits() as $n => [$changes]) {
    add('knaben', "hit: $n", T($response($changes)), [], $knaben);
}
$valid = '{"hits":[],"total":{"relation":"eq","value":0}}';
$raw = [
    'depth 64 is allowed' => '{"hits":[],"total":{"relation":"eq","value":0},"ignored":'.str_repeat('[', 63).'0'.str_repeat(']', 63).'}',
    'depth 65 is not' => '{"hits":[],"total":{"relation":"eq","value":0},"ignored":'.str_repeat('[', 64).'0'.str_repeat(']', 64).'}',
    'float total value' => '{"hits":[],"total":{"relation":"eq","value":0.0}}',
    'exponent total value' => '{"hits":[],"total":{"relation":"eq","value":0e0}}',
    'negative zero total value' => '{"hits":[],"total":{"relation":"eq","value":-0}}',
    'huge total value' => '{"hits":[],"total":{"relation":"eq","value":9223372036854775808}}',
    'largest integer total value' => '{"hits":[],"total":{"relation":"eq","value":9223372036854775807}}',
    'float category id' => '{"hits":[{"title":"A.B-C","categoryId":[3000000.0]}],"total":{"relation":"eq","value":1}}',
    'null title' => '{"hits":[{"title":null,"categoryId":[3000000]}],"total":{"relation":"eq","value":1}}',
    'null hit' => '{"hits":[null],"total":{"relation":"eq","value":1}}',
    'null total relation' => '{"hits":[],"total":{"relation":null,"value":0}}',
    'total below the hit count' => '{"hits":[{"title":"A.B-C","categoryId":[3000000]}],"total":{"relation":"gte","value":0}}',
    'trailing garbage' => $valid.' x',
    'trailing whitespace' => $valid." \n\t\r",
    'leading BOM' => "\xEF\xBB\xBF".$valid,
    'whitespace only' => ' ',
    'NUL property name' => '{"hits":[],"total":{"relation":"eq","value":0},"\u0000a":1}',
    'empty property name' => '{"hits":[],"total":{"relation":"eq","value":0},"":1}',
    'lone surrogate escape' => '{"hits":[],"total":{"relation":"eq","value":0},"x":"\ud800"}',
    'invalid UTF-8 in a string' => "{\"hits\":[],\"total\":{\"relation\":\"eq\",\"value\":0},\"x\":\"\xff\"}",
    'escaped duplicate inside an array of objects' => '{"hits":[],"total":{"relation":"eq","value":0},"x":[{"a":1,"a":2}]}',
    'duplicate after a syntax error' => '{"hits":[],"hits":[],"total":{"relation":"eq","value":0},"x":}',
    'category ids with a string among ints' => '{"hits":[{"title":"A.B-C","categoryId":[3000000,"3000000"]}],"total":{"relation":"eq","value":1}}',
    'zero category id' => '{"hits":[{"title":"A.B-C","categoryId":[3000000,0]}],"total":{"relation":"eq","value":1}}',
    'date as a number' => '{"hits":[{"title":"A.B-C","categoryId":[3000000],"date":5}],"total":{"relation":"eq","value":1}}',
    'rejected hit is still validated after a later bad one' => '{"hits":[{"title":"A B","categoryId":[3000000]},{"title":"","categoryId":[3000000]}],"total":{"relation":"eq","value":2}}',
];
foreach ($raw as $n => $json) {
    add('knaben', "raw: $n", T($json), ['page_size' => 10], $knaben);
}
add('knaben', 'raw: body of 1 MiB + 1', ['repeat' => ['x', 1048577]], [], $knaben);

// ---- complete XML catalogues ---------------------------------------------------------------
$xmlRun = static fn (string $class) => static function (string $b, array $p) use ($class): array {
    $s = new $class('x', ...phpArgs($p));
    $e = $s->parseXml($b);

    return ['entries' => dump($e), 'rejections' => (object) $s->rejectionCounts(), 'accepted' => $s->acceptedCount(), 'normalized' => $s->boundaryNormalizedCount()];
};
$record = static fn (int $id, string $t): string => '<release><id>'.$id.'</id><releasename>'.htmlspecialchars($t, ENT_XML1, 'UTF-8').'</releasename></release>';
$nswdb = file_get_contents(BASE.'/nswdb.xml');
add('nswdb', 'fixture', Fi('nswdb.xml'), [], $xmlRun(NswdbSource::class));
add('nswdb', 'body limit exact', Fi('nswdb.xml'), ['max_bytes' => strlen($nswdb)], $xmlRun(NswdbSource::class));
add('nswdb', 'body limit one short', Fi('nswdb.xml'), ['max_bytes' => strlen($nswdb) - 1], $xmlRun(NswdbSource::class));
add('nswdb', 'truncated export', Fi('nswdb.xml', [['drop_tail', 12]]), [], $xmlRun(NswdbSource::class));
add('3dsdb', 'fixture', Fi('3dsdb.xml'), [], $xmlRun(ThreeDsDbSource::class));
add('nswdb', 'unicode and technical tokens', T('<releases>'.$record(1, 'Léon_天空.1994.DD+5.1.H.264-MixedGroup').'</releases>'), [], $xmlRun(NswdbSource::class));
add('nswdb', 'titles without a terminal group', T('<releases>'.$record(2622, 'MARIO_Plus_RABBIDS_SPARKS_OF_HOPE_NSW').$record(2635, 'Asterix_And_Obelix_XXXL_The_Ram_From_Hibernia_NSW-NiiNTENDO[0001]').$record(3110, 'Otome_Game_no_Hametsu_Flag_Shikanai_')."</releases>"), [], $xmlRun(NswdbSource::class));
foreach (NswdbSourceTest::observedOuterFormatting() as $n => [$id, $raw, $title]) {
    add('nswdb', "outer formatting: $n", T('<releases>'.$record($id, $raw).'</releases>'), [], $xmlRun(NswdbSource::class));
}
foreach (NswdbSourceTest::internalWhitespace() as $n => [$title]) {
    add('nswdb', "internal whitespace: $n", T('<releases>'.$record(1, $title).'</releases>'), [], $xmlRun(NswdbSource::class));
}
add('nswdb', 'boundary normalized duplicate', T('<releases>'.$record(1, 'exact.title-grp').$record(9, " Exact.Title-GRP\t").'</releases>'), [], $xmlRun(NswdbSource::class));
add('nswdb', 'duplicates keep the highest id', T('<releases>'.$record(1, 'Exact.Title-GRP').$record(9, 'exact.title-grp').$record(5, 'Exact.Title-GRP').$record(2, 'Other.Title-GRP').'</releases>'), [], $xmlRun(NswdbSource::class));
add('nswdb', 'accents stay distinct', T('<releases>'.$record(1, 'Léon.1994-GRP').$record(2, 'Leon.1994-GRP').'</releases>'), [], $xmlRun(NswdbSource::class));
foreach (NswdbSourceTest::invalidStructures() as $n => [$xml]) {
    add('nswdb', "invalid: $n", T($xml), [], $xmlRun(NswdbSource::class));
}
add('nswdb', 'record limit counts rejected titles', T('<releases>'.$record(1, 'Exact.Release-GRP').$record(2, 'Editorial title').'</releases>'), ['max_records' => 1], $xmlRun(NswdbSource::class));
add('nswdb', 'record limit exact', T('<releases>'.$record(1, 'A.Release-GRP').$record(2, 'B.Release-GRP').'</releases>'), ['max_records' => 2], $xmlRun(NswdbSource::class));
add('nswdb', 'overlength identity', T('<releases>'.$record(1, str_repeat('a', 256).'-GRP').'</releases>'), [], $xmlRun(NswdbSource::class));
add('nswdb', 'maximum length identity', T('<releases>'.$record(1, str_repeat('é', 255)).'</releases>'), [], $xmlRun(NswdbSource::class));
add('nswdb', 'missing, multiple and nested titles', T('<releases><release><id>1</id></release><release><id>2</id><releasename>A-GRP</releasename><releasename>B-GRP</releasename></release><release><id>3</id><releasename><b>Exact.Release-GRP</b></releasename></release></releases>'), [], $xmlRun(NswdbSource::class));
foreach ([
    'xml declaration' => '<?xml version="1.0" encoding="UTF-8"?>'."\n<releases>".'%R%'."</releases>\n",
    'comments and processing instructions' => '<releases><!-- c --><?pi x?>%R%<!-- d --></releases>',
    'cdata title' => '<releases><release><id>1</id><releasename><![CDATA[Exact.Release-GRP]]></releasename></release></releases>',
    'predefined and numeric references' => '<releases><release><id>1</id><releasename>A.&amp;.B&#45;&#x47;RP</releasename></release></releases>',
    'attributes are ignored' => '<releases a="1"><release b="2"><id c="3">1</id><releasename d="4">Exact.Release-GRP</releasename></release></releases>',
    'unknown entity' => '<releases><release><id>1</id><releasename>A.&nbsp;.B-GRP</releasename></release></releases>',
    'control character reference' => '<releases><release><id>1</id><releasename>A.&#1;.B-GRP</releasename></release></releases>',
    'raw control character' => "<releases><release><id>1</id><releasename>A.\x01.B-GRP</releasename></release></releases>",
    'text in root' => '<releases>text%R%</releases>',
    'text after root' => '<releases>%R%</releases>text',
    'second root' => '<releases>%R%</releases><releases>%R%</releases>',
    'leading whitespace before the declaration' => ' <?xml version="1.0"?><releases>%R%</releases>',
    'duplicate attribute' => '<releases a="1" a="2">%R%</releases>',
    'cdata end in text' => '<releases><release><id>1</id><releasename>A]]>B</releasename></release></releases>',
    'prefixed element without a namespace' => '<releases><x:release><id>1</id><releasename>A.B-GRP</releasename></x:release></releases>',
    'xml:lang attribute' => '<releases xml:lang="en">%R%</releases>',
    'id with surrounding whitespace' => '<releases><release><id> 1</id><releasename>A.B-GRP</releasename></release></releases>',
    'id beyond 64 bits' => '<releases><release><id>9223372036854775808</id><releasename>A.B-GRP</releasename></release></releases>',
    'largest id' => '<releases><release><id>9223372036854775807</id><releasename>A.B-GRP</releasename></release></releases>',
    'id with a leading zero' => '<releases><release><id>01</id><releasename>A.B-GRP</releasename></release></releases>',
    'text between records' => '<releases>%R%  %R2%</releases>',
    'NBSP around the title' => "<releases><release><id>1</id><releasename>\u{a0}A.B-GRP\u{a0}</releasename></release></releases>",
    'vertical tab and NUL trim' => "<releases><release><id>1</id><releasename>&#11;A.B-GRP&#11;</releasename></release></releases>",
] as $n => $xml) {
    $xml = str_replace(['%R%', '%R2%'], [$record(1, 'Exact.Release-GRP'), $record(2, 'Other.Release-GRP')], $xml);
    add('nswdb', "xml: $n", T($xml), [], $xmlRun(NswdbSource::class));
}

foreach (['utf-8' => ['UTF-8', 'Léon'], 'utf8 label' => ['utf8', 'Léon'], 'latin1 with ascii' => ['ISO-8859-1', 'Leon'], 'latin1 with utf-8 bytes' => ['ISO-8859-1', 'Léon'], 'windows-1252 with utf-8 bytes' => ['windows-1252', 'Léon'], 'utf-16' => ['UTF-16', 'Leon'], 'bogus with ascii' => ['bogus', 'Leon'], 'bogus with utf-8 bytes' => ['bogus', 'Léon']] as $n => [$encoding, $name]) {
    add('nswdb', "xml encoding: $n", T('<?xml version="1.0" encoding="'.$encoding.'"?><releases>'.$record(1, $name.'.Rel-GRP').'</releases>'), [], $xmlRun(NswdbSource::class));
}

// ---- DATS PC ISO --------------------------------------------------------------------------
$dats = static function (string $b, array $p): array {
    $s = new DatsPcIsoSource('x', ...phpArgs($p));
    $e = $s->parseXml($b);

    return ['entries' => dump($e), 'rejections' => (object) $s->rejectionCounts(), 'accepted' => $s->acceptedCount()];
};
add('dats_pciso_1998', 'fixture', Fi('dats-pciso-1998-subset.xml'), [], $dats);
add('dats_pciso_1998', 'record limit', Fi('dats-pciso-1998-subset.xml'), ['page_size' => 1, 'max_records' => 3], $dats);
add('dats_pciso_1998', 'body limit', Fi('dats-pciso-1998-subset.xml'), ['max_bytes' => 10], $dats);
add('dats_pciso_1998', 'unicode and nonstandard separator', Fi('dats-pciso-1998-subset.xml', [rep('F22.Air.Dominance.Fighter-GOBBLE', 'Léon_天空_German_Ind')]), [], $dats);
foreach (DatsPcIsoSourceTest::invalidChanges() as $n => [$from, $to]) {
    add('dats_pciso_1998', "invalid: $n", Fi('dats-pciso-1998-subset.xml', [rep($from, $to)]), [], $dats);
}
add('dats_pciso_1998', 'text in a game', Fi('dats-pciso-1998-subset.xml', [rep('<description>', 'text<description>')]), [], $dats);
add('dats_pciso_1998', 'empty annotation', Fi('dats-pciso-1998-subset.xml', [rep('<rom name="Alien.Earth-RAZORISO"', '<nukedreason></nukedreason><rom name="Alien.Earth-RAZORISO"')]), [], $dats);
add('dats_pciso_1998', 'valid annotation', Fi('dats-pciso-1998-subset.xml', [rep('<rom name="Alien.Earth-RAZORISO"', '<nukedreason>fake</nukedreason><rom name="Alien.Earth-RAZORISO"')]), [], $dats);
add('dats_pciso_1998', 'case-insensitive game duplicate', Fi('dats-pciso-1998-subset.xml', [rep('1998-02-04-Alien.Earth-RAZORISO', '1998-01-01-f22.air.dominance.fighter-gobble')]), [], $dats);
add('dats_pciso_1998', 'attribute tab is normalized to a space', Fi('dats-pciso-1998-subset.xml', [rep('F22.Air.Dominance.Fighter-GOBBLE', "F22.Air\tDominance-GOBBLE")]), [], $dats);

// ---- nZEDb archive ------------------------------------------------------------------------
$nzedb = static function (string $b, array $p): array {
    $s = new NzedbArchiveSource('x', ...phpArgs($p));
    $e = $s->parseGzip($b);

    return ['entries' => dump($e), 'rejections' => (object) $s->rejectionCounts(), 'accepted' => $s->acceptedCount()];
};
$row = static function (string $title, ?string $category = null, ?string $nfo = null): string {
    $field = static fn (?string $v): string => $v === null ? '\\N' : "'".str_replace(['\\', "'", "\0", "\r", "\n", "\t"], ['\\\\', "\\'", '\\0', '\\r', '\\n', '\\t'], $v)."'";

    return implode("\t\t", array_map($field, [$title, $nfo, '999MB', '14', 'ignored.alias', '0', null, $category, '2024-05-02 11:40:08', 'upstream-provider', '0', null]))."\r\n";
};
add('nzedb_archive', 'fixture', Gz(Fi('nzedb-2024-05-02-subset.csv', [rep("\n", "\r\n")])), [], $nzedb);
add('nzedb_archive', 'record limit counts raw rows', Gz(Fi('nzedb-2024-05-02-subset.csv', [rep("\n", "\r\n")])), ['page_size' => 1, 'max_records' => 2], $nzedb);
$r = $row('Exact.Release.2024-GRP');
$malformed = [
    'empty export' => '',
    'eleven fields' => implode("\t\t", array_slice(explode("\t\t", substr($r, 0, -2)), 0, 11))."\r\n",
    'thirteen fields' => substr($r, 0, -2)."\t\t'unsupported'\r\n",
    'missing final terminator' => substr($r, 0, -2),
    'LF instead of CRLF' => str_replace("\r\n", "\n", $r),
    'unterminated title field' => str_replace("Exact.Release.2024-GRP'", 'Exact.Release.2024-GRP', $r),
    'unsupported enclosure' => str_replace("'", '"', $r),
    'terminal backslash' => "'Exact\\",
    'unknown escape' => str_replace('Exact.Release', 'Exact\\q.Release', $r),
    'unescaped inner quote' => str_replace('Exact.Release', "Exact.O'Neil.Release", $r),
    'malformed NULL token' => str_replace('\\N', 'NULL', $r),
    'extra trailing record' => $r.'garbage',
    'invalid UTF8' => str_replace('Exact', "\xff", $r),
];
foreach ($malformed as $n => $csv) {
    add('nzedb_archive', "csv: $n", Gz(T($csv)), [], $nzedb);
}
$gz = Gz(T($r));
foreach ([
    'not gzip' => T($r),
    'truncated trailer' => Gz(T($r), [['drop_tail', 4]]),
    'corrupt CRC' => Gz(T($r), [['flip_from_end', 8]]),
    'trailing junk' => Gz(T($r), [['append', 'junk']]),
    'multiple members' => ['concat' => [$gz, $gz]],
    'truncated body' => Gz(T($r), [['drop_tail', 12]]),
    'header only' => T("\x1f\x8b\x08\x00"),
] as $n => $spec) {
    add('nzedb_archive', "gzip: $n", $spec, [], $nzedb);
}
add('nzedb_archive', 'body limit', Gz(Fi('nzedb-2024-05-02-subset.csv', [rep("\n", "\r\n")])), ['max_bytes' => 10], $nzedb);
add('nzedb_archive', 'decoded limit', Gz(T($row('Exact.Release.2024-GRP', nfo: str_repeat('a', 5000)))), ['max_csv_bytes' => 1000], $nzedb);
add('nzedb_archive', 'invalid titles are counted', Gz(T($row(str_repeat('é', 249).'-Group').$row(str_repeat('é', 250).'-Group').$row("Control.\0.Release-GRP").$row(''))), [], $nzedb);
add('nzedb_archive', 'outer whitespace titles', Gz(T($row(' Leading.Release-GRP').$row("Trailing.Release-GRP\t").$row("   ").$row('Nbsp.Release-GRP'."\u{a0}"))), [], $nzedb);
add('nzedb_archive', 'null title', Gz(T(str_replace("'Exact.Release.2024-GRP'", '\\N', $r))), [], $nzedb);
add('nzedb_archive', 'case duplicates', Gz(T($row('Exact.Release.2024-GRP').$row('exact.release.2024-grp'))), [], $nzedb);
add('nzedb_archive', 'categories', Gz(T($row('First.Release-GRP', '0').$row('Second.Release-GRP', "bad\0category").$row('Third.Release-GRP', '').$row('Fourth.Release-GRP', str_repeat('c', 255)).$row('Fifth.Release-GRP', str_repeat('c', 256)).$row('Sixth.Release-GRP', "tab\there"))), [], $nzedb);
add('nzedb_archive', 'escapes in a title', Gz(T($row("It's.A.Title\\Here-GRP"))), [], $nzedb);

// ---- scene archive SQL --------------------------------------------------------------------
$sceneFile = file_get_contents(BASE.'/scene-archive-sql-subset.sql');
$scene = static function (string $b, array $p): array {
    $path = tempnam(sys_get_temp_dir(), 'scene-golden-');
    file_put_contents($path, $b);
    try {
        $args = phpArgs($p);
        $s = new SceneArchiveSqlSource($path, ...$args);
        $pages = [];
        for ($page = 1; $page < 1000; $page++) {
            $entries = $s->fetch($page);
            if ($entries === []) {
                break;
            }
            $pages[] = array_map(static fn (PredbFeedEntry $e): string => $e->title, $entries);
        }

        return ['pages' => $pages, 'rejections' => (object) $s->rejectionCounts(), 'accepted' => $s->acceptedCount()];
    } finally {
        @unlink($path);
    }
};
add('scene_archive_sql', 'fixture', Fi('scene-archive-sql-subset.sql'), [], $scene);
add('scene_archive_sql', 'fixture, page size 2', Fi('scene-archive-sql-subset.sql'), ['page_size' => 2], $scene);
add('scene_archive_sql', 'out of order ids keep row order', Fi('scene-archive-sql-subset.sql', [rep("2694710,'HDTV'", "2699999,'HDTV'")]), [], $scene);
add('scene_archive_sql', 'unlock-only ending', Fi('scene-archive-sql-subset.sql', [rep('/*!40000 ALTER TABLE `predb` ENABLE KEYS */;', '')]), [], $scene);
add('scene_archive_sql', 'later malformed statement', Fi('scene-archive-sql-subset.sql', [rep('UNLOCK TABLES;', "INSERT INTO `predb` VALUES (999);\nUNLOCK TABLES;")]), ['page_size' => 1], $scene);
foreach (SceneArchiveSqlSourceTest::invalidChanges() as $n => [$from, $to]) {
    add('scene_archive_sql', "invalid: $n", Fi('scene-archive-sql-subset.sql', [rep($from, $to)]), [], $scene);
}
foreach ([['max_file_bytes' => 100], ['max_statement_bytes' => 100], ['max_scratch_bytes' => 100], ['max_frame_index_bytes' => 45], ['max_bucket_bytes' => 64], ['max_scratch_bytes' => 1]] as $limits) {
    add('scene_archive_sql', 'limit '.json_encode($limits), Fi('scene-archive-sql-subset.sql'), $limits, $scene);
}
foreach ([['page_size' => 0], ['page_size' => 10001], ['max_frame_index_bytes' => 44], ['max_bucket_bytes' => 31], ['max_file_bytes' => 536870913]] as $limits) {
    add('scene_archive_sql', 'out of bounds '.json_encode($limits), Fi('scene-archive-sql-subset.sql'), $limits, $scene);
}
add('scene_archive_sql', 'noncanonical and conflicting titles', Fi('scene-archive-sql-subset.sql', [rep('Mean.Streets.SE.1973.DVDRip.XviD.Hungarian-GTB', 'Mean Streets-GTB'), rep('Schach.Und.Matt.2-SiLENTGATE', 'Schach.Und.Matt.2-OTHER')]), [], $scene);
add('scene_archive_sql', 'semicolon and apostrophe in strings', Fi('scene-archive-sql-subset.sql', [rep('ER.S12E13.iTALiAN.LD.HDTV.XviD-TRiBECA', "ER.King\\'s.S12E13.iTALiAN.LD.HDTV.XviD-TRiBECA"), rep("'0',NULL,'1183557532'", "'0','reason; ignored','1183557532'")]), [], $scene);
add('scene_archive_sql', 'doubled quote in a string', Fi('scene-archive-sql-subset.sql', [rep('ER.S12E13.iTALiAN.LD.HDTV.XviD-TRiBECA', "ER.King''s.S12E13.iTALiAN.LD.HDTV.XviD-TRiBECA")]), [], $scene);
add('scene_archive_sql', 'statements sharing a line', Fi('scene-archive-sql-subset.sql', [rep("UNLOCK TABLES;\n", "UNLOCK TABLES; /*!40000 ALTER TABLE `predb` ENABLE KEYS */;\n"), rep("\n/*!40000 ALTER TABLE `predb` ENABLE KEYS */;", '')]), [], $scene);
add('scene_archive_sql', 'comment lines', Fi('scene-archive-sql-subset.sql', [['prepend', "-- MySQL dump\n# note\n  -- indented\n"]]), [], $scene);
add('scene_archive_sql', 'bare double dash is not a comment', Fi('scene-archive-sql-subset.sql', [['prepend', "--\n"]]), [], $scene);
add('scene_archive_sql', 'duplicate title differing in case', Fi('scene-archive-sql-subset.sql', [rep('Schach.Und.Matt.2-SiLENTGATE', 'er.s12e13.italian.ld.hdtv.xvid-tribeca')]), [], $scene);
add('scene_archive_sql', 'signed and NULL numeric columns', Fi('scene-archive-sql-subset.sql', [rep("'1183557625',696,49", "'-1183557625',NULL,NULL")]), [], $scene);
add('scene_archive_sql', 'id above 32 bits', Fi('scene-archive-sql-subset.sql', [rep('(2694710,', '(2147483648,')]), [], $scene);
add('scene_archive_sql', 'id of zero', Fi('scene-archive-sql-subset.sql', [rep('(2694710,', '(0,')]), [], $scene);
add('scene_archive_sql', 'null section', Fi('scene-archive-sql-subset.sql', [rep("2694710,'HDTV'", '2694710,NULL')]), [], $scene);
add('scene_archive_sql', 'section too long', Fi('scene-archive-sql-subset.sql', [rep("2694710,'HDTV'", "2694710,'".str_repeat('S', 21)."'")]), [], $scene);
add('scene_archive_sql', 'trailing data after the statement', Fi('scene-archive-sql-subset.sql', [rep("INSERT INTO `predb` VALUES (2694739", "INSERT INTO `predb` VALUES (2694739")]), [], $scene);
add('scene_archive_sql', 'empty file', T(''), [], $scene);
add('scene_archive_sql', 'file without rows', T(substr($sceneFile, 0, strpos($sceneFile, 'INSERT INTO'))."UNLOCK TABLES;\n"), [], $scene);

ksort($G);

echo json_encode($G, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE), "\n";

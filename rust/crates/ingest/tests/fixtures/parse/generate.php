<?php
// Regenerates golden.jsonl and regexes.json from the PHP reference.
// Needs rust/porting/php-ref-setup.sh to have built its database.
// Usage: php generate.php [path to the php-final checkout]
use App\Services\Binaries\HeaderParser;
use App\Services\Binaries\PartHandler;
use App\Services\BlacklistService;
use App\Services\CollectionsCleaningService;
use App\Services\XrefService;
use App\Support\Utf8;
use Illuminate\Support\Facades\DB;

$ref = $argv[1] ?? getenv('HOME').'/git.xbmc4lyfe/newznab-tmux-php-ref';
$here = __DIR__;
chdir($ref);
require $ref.'/vendor/autoload.php';
$app = require $ref.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config(['cache.default' => 'array']);

final class NoBlacklist extends BlacklistService
{
    public function isBlackListed(array $msg, string $groupName): bool
    {
        return false;
    }
}

$out = fopen($here.'/golden.jsonl', 'w');
$emit = static fn (array $row) => fwrite($out, json_encode($row)."\n");

// Real subjects: the examples in the seeded regexes' descriptions.
$subjects = [];
foreach (DB::select('SELECT description FROM collection_regexes ORDER BY id') as $row) {
    foreach (explode(':::', $row->description) as $example) {
        if (trim($example) !== '') {
            $subjects[trim($example)] = true;
        }
    }
}
$subjects = array_map(strval(...), array_keys($subjects));
array_push($subjects,
    'Some.Release-GRP [01/10] - "some.release.part01.rar" yEnc (1/50)',
    'Some.Release-GRP - "some.release.vol003+4.par2" 12.5 MB yEnc (3/50)',
    'no part counter here',
    'Usenet Index Post (1/2)',
    '  Usenet Index Post (1/2)',
    ' leading space yEnc (2/9)',
    'zero part yEnc (0/9)',
    'Broderick_Smith-Unknown_Country-2009-404 "00-broderick_smith-unknown_country-2009.sfv" yEnc (1/3)',
    'Artist - Album (2004) - 03 - Track.mp3 yEnc (2/4)',
    '"short.mp3" yEnc (1/2)',
    'a (3 of 12) b yEnc (1/2)',
    "caf\xe9 [01/10] \"caf\xe9.rar\" yEnc (1/2)",
    'file 03-12 x yEnc (1/2)',
    'tab	in subject yEnc (1/2)',
);
$groups = ['alt.binaries.teevee', 'alt.binaries.boneless', 'alt.binaries.sounds.mp3', 'alt.binaries.nl', 'alt.binaries.misc'];

$parser = new HeaderParser(new NoBlacklist, false);
$cleaner = new CollectionsCleaningService;
foreach ($subjects as $subject) {
    $parser->reset();
    $parsed = $parser->parse([['Number' => 5, 'Subject' => $subject, 'From' => 'a@b', 'Date' => '', 'Message-ID' => '<a@b>', 'Bytes' => 10]], 'alt.binaries.misc');
    $matches = $parsed['headers'][0]['matches'] ?? null;
    if (! preg_match('/^\s*(?!Usenet Index Post)(.+?)\s+\((\d+)\/(\d+)\)/', $subject, $raw)) {
        $raw = null;
    }
    $base = $matches[1] ?? $subject;
    $names = [];
    foreach ($groups as $group) {
        $clean = $cleaner->collectionsCleaner($base, $group);
        $names[$group] = [(int) $clean['id'], bin2hex($clean['name'])];
    }
    $counter = preg_match(HeaderParser::FILE_COUNTER_PATTERN, $base, $count) ? [(int) $count[1], (int) $count[3]] : [0, 0];
    $emit(['kind' => 'subject', 'subject' => bin2hex($subject),
        'raw_base' => $raw === null ? null : bin2hex($raw[1]), 'part' => $raw === null ? null : (int) $raw[2], 'total' => $raw === null ? null : (int) $raw[3],
        'base' => bin2hex($base), 'parsed' => $matches !== null, 'counter' => $counter, 'names' => $names]);
}

// Which regexes each group sees, as RegexService orders them.
$regexes = [];
foreach ($groups as $group) {
    $regexes[$group] = array_map(static fn (object $row): array => [(int) $row->id, $row->regex],
        DB::select("SELECT r.id, r.regex FROM collection_regexes r WHERE ? REGEXP r.group_regex AND r.status = 1 ORDER BY r.ordinal ASC, r.group_regex ASC", [$group]));
}
file_put_contents($here.'/regexes.json', json_encode($regexes, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

// Binary identity, from BinaryHandler::identityHash.
$identity = new ReflectionMethod(App\Services\Binaries\BinaryHandler::class, 'identityHash');
$handler = new App\Services\Binaries\BinaryHandler;
foreach ([['Some Name  yEnc', 'Poster <a@b>', 0], ['Some Name yEnc', 'POSTER <A@B>', 0], ['x', 'y', 7], ["  tab\tname  ", ' me ', 0], ['Éa', 'Ü', 0], ["caf\xe9", "m\xfcller", 0]] as [$base, $from, $file]) {
    $emit(['kind' => 'binary', 'base' => bin2hex($base), 'from' => bin2hex($from), 'file' => $file,
        'hash' => $identity->invoke($handler, ['matches' => [1 => $base], 'From' => $from], $file)]);
}

// Xref tokens and groups.
$xref = new XrefService;
foreach (['news.example.com alt.binaries.teevee:123 alt.binaries.misc:9', 'alt.binaries.a alt.binaries.a:5', 'alt.test:1 a.b.teevee:4', 'alt.binarios.x:1 es.binaer.y:2 xyzz.binaries.z:3', '', 'alt.binaries.a:5x'] as $value) {
    $emit(['kind' => 'xref', 'xref' => bin2hex($value), 'tokens' => array_map('bin2hex', $xref->extractTokens($value)), 'groups' => array_map('bin2hex', $xref->extractGroupNames($value))]);
}

// Message ID validation.
foreach (['<a@b>', ' <a@b> ', '', "<a\x7f@b>", "<\xe9@b>", str_repeat('a', 255), str_repeat('a', 256)] as $id) {
    $emit(['kind' => 'message_id', 'id' => bin2hex($id), 'error' => PartHandler::validationError(['matches' => [2 => 1], 'Message-ID' => $id])]);
}

// Dates.
foreach (['26 Jun 2014 13:08:22 GMT', 'Thu, 26 Jun 2014 13:08:22 +0000', 'Thu, 26 Jun 2014 13:08:22 +0000 (UTC)', '26 Jun 2014 13:08 -0500', 'Thu, 26 Jun 14 13:08:22 GMT', '2014-06-26 13:08:22', 'garbage', '', 'Thu, 26 Jun 2014 13:08:22 EST', 'Thu, 26 Jun 2014 25:08:22 GMT', 'Sun, 32 Jun 2014 13:08:22 GMT', '1 Jan 2020 00:00:00 +0530', 'Wed, 01 Jan 2020 00:00:00 UT', 'Mon, 2 Mar 2020 3:04:05 -0800'] as $date) {
    $emit(['kind' => 'date', 'date' => $date, 'time' => strtotime($date)]);
}

// Obfuscation marking over synthetic ranges, against the PHP rule.
$hash = static fn (string $seed): string => md5($seed);
$ranges = [];
$nyuu = [];
for ($i = 0; $i < 40; $i++) {
    $nyuu[] = ['Number' => 100 + $i, 'Subject' => '[40/62] "'.$hash("n$i").'" yEnc ('.($i + 2).'/687)'];
}
$nyuu[] = ['Number' => 200, 'Subject' => '[40/62] "'.$hash('first').'" yEnc (1/687)'];
$nyuu[] = ['Number' => 201, 'Subject' => '[40/62] "'.$hash('last').'" yEnc (687/687)'];
$ranges['nyuu'] = $nyuu;
$real = [];
for ($i = 1; $i <= 60; $i++) {
    $real[] = ['Number' => 1000 + $i, 'Subject' => '"'.$hash('one upload').'.part07.rar" yEnc ('.$i.'/60)'];
}
$ranges['real_upload'] = $real;
$ranges['mixed'] = array_merge($nyuu, $real);
$robin = [];
for ($i = 0; $i < 40; $i++) {
    $robin[] = ['Number' => 2000 + $i, 'Subject' => '"'.$hash("f$i").'" yEnc ('.(($i % 2) + 2).'/100)'];
}
$ranges['round_robin'] = $robin;
$volumes = [];
for ($i = 0; $i < 30; $i++) {
    $volumes[] = ['Number' => 3000 + $i, 'Subject' => '"'.$hash('vol').'.part'.sprintf('%02d', $i).'.rar" yEnc ('.($i + 2).'/90)'];
}
$ranges['numbered_volumes'] = $volumes;
$few = array_slice($nyuu, 0, 10);
$ranges['too_few'] = $few;
$ranges['short_tokens'] = array_map(static fn (int $i): array => ['Number' => 4000 + $i, 'Subject' => 'abc'.$i.' yEnc ('.($i + 2).'/90)'], range(0, 30));
$marker = new HeaderParser(new NoBlacklist, false);
foreach ($ranges as $name => $headers) {
    foreach ([16, 8, 0] as $min) {
        $marker->markPerArticleObfuscated($headers, $min);
        $numbers = $marker->perArticleObfuscatedNumbers();
        sort($numbers);
        $emit(['kind' => 'obfuscation', 'name' => $name, 'min' => $min, 'marked' => $numbers,
            'headers' => array_map(static fn (array $h): array => [$h['Number'], bin2hex($h['Subject'])], $headers)]);
    }
}

// Article range bounds ignore numbers outside the request.
$rangeHeaders = [['Number' => 5, 'Date' => 'a'], ['Number' => 9, 'Date' => 'b'], ['Number' => 99999, 'Date' => 'c'], ['Number' => 1, 'Date' => 'd']];
$emit(['kind' => 'range', 'headers' => $rangeHeaders, 'first' => 5, 'last' => 10, 'result' => (new HeaderParser(new NoBlacklist, false))->getArticleRange($rangeHeaders, 5, 10)]);

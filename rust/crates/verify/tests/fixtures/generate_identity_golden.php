<?php

declare(strict_types=1);

// php generate_identity_golden.php > identity_golden.json
// Records what the PHP rules say about subject and filename pairs, and sample plans.

require getenv('PHP_REF') ?: getenv('HOME').'/git.xbmc4lyfe/newznab-tmux-php-ref/vendor/autoload.php';

use App\Services\Releases\Verification\HeaderFilenameIdentity;
use App\Services\Releases\Verification\SpotCheckPlanner;

$pairs = [
    ['Release name anything.bin yEnc (1/2)', 'anything.bin', false],
    ['Release name yEnc (1/2)', 'anything.bin', false],
    ['[1/9] - "Readable.Release.Name.mkv" yEnc', 'Readable.Release.Name.mkv', false],
    ['[1/9] - "Readable.Release.Name.mkv" yEnc', 'Different.Release.mkv', false],
    ['[1/9] - "Xk29dPq8LmZ0Yt5R.mkv" yEnc', 'setup.exe', false],
    ['[1/9] - "Xk29dPq8LmZ0Yt5R.mkv" yEnc', 'b7Qe1ZrT9wXy.mkv', false],
    ["[1/1] - 'movie.mkv' yEnc", 'other.mkv', false],
    ['[1/9] - "Final.Fantasy.VI.iso" yEnc', 'Final.Fantasy.VII.iso', false],
    ['[1/9] - "Final Fantasy VI.iso" yEnc', 'final.fantasy.vi.iso', false],
    ["[1/2] - 'O'Brien's Show.mkv' yEnc", "O'Brien's Show.mkv", false],
    ['[1/2] - "Caf\u{00e9}.mkv" yEnc', "Cafe\u{0301}.mkv", false],
    ['[1/2] - "File.part01.rar" yEnc', 'File.part02.rar', false],
    ['[1/2] - "Show 1 10.mkv" yEnc', 'Show 11 0.mkv', false],
    ['[1/2] - "C++ Guide.pdf" yEnc', 'C Guide.pdf', false],
    ['"one.rar" yEnc "two.rar" yEnc', 'one.rar', false],
    ['[1/2] - "a.par2" yEnc', 'a.mkv', false],
    ['[1/2] - "movie.mkv" yEnc', 'movie.par2', false],
    ['[1/2] - "movie.mkv" yEnc', 'movie.mkv', true],
    ['[1/2] - "movie" yEnc', 'random', true],
    ['[1/2] - "body.mkv" yEnc', 'body.exe', false],
    ['[1/2] - "body.exe" yEnc', 'body.exe', false],
    ['[1/2] - "BBC2Documentary.mkv" yEnc', 'b7Qe1ZrT9wXy.mkv', false],
    ['[1/2] - "0123456789abcdef01.mkv" yEnc', 'anything.mkv', false],
    ['anything.bin.mkv yEnc', 'anything.bin', false],
    ['the yEnc file', 'file', false],
    ['"yEnc in name.rar" yEnc', 'yEnc in name.rar', false],
    ['[1/3] "a.rar" [2/3] "b.rar" (1/3) yEnc', 'b.rar', false],
    ['"a b.rar" - "c.rar"', 'c.rar', false],
    ['[1/2] - "x.mkv" yEnc', 'x.mkv:stream', false],
    ['[1/2] - "x.mkv" yEnc', 'x.mkv. ', false],
];
$names = ['file.par2', 'file.PAR2.', 'README', 'b7Qe1ZrT9wXy', 'file.mkv', 'a:b.par2', 'Show.S01E01', '0123456789abcdef01'];

$manifest = ['identity' => str_repeat('cd', 20), 'files' => [
    ['key' => 'k1', 'totalparts' => 3, 'segments' => [
        ['number' => 1, 'messageid' => 'a1', 'bytes' => 100], ['number' => 2, 'messageid' => 'a2', 'bytes' => 200], ['number' => 3, 'messageid' => 'a3', 'bytes' => 300]]],
    ['key' => 'k2', 'totalparts' => 2, 'totalparts_inferred' => true, 'segments' => [
        ['number' => 1, 'messageid' => 'b1', 'bytes' => 100], ['number' => 2, 'messageid' => 'b2', 'bytes' => 100]]],
    ['key' => 'k3', 'totalparts' => 1, 'segments' => [['number' => 1, 'messageid' => 'c1', 'bytes' => 5000]]],
    ['key' => 'k4', 'totalparts' => 1, 'segments' => [['number' => 1, 'messageid' => 'a1', 'bytes' => 50]]],
]];
$planner = new SpotCheckPlanner(new class extends App\Services\Releases\Verification\NativeSpotCheckPlanner {});
$plans = [];
foreach ([[1, 1048576, 1048576], [3, 1048576, 1048576], [16, 1048576, 1048576], [16, 1000, 1000], [2, 250, 150], [0, 1000, 1000]] as [$articles, $bytes, $articleBytes]) {
    $plans[] = ['articles' => $articles, 'bytes' => $bytes, 'article_bytes' => $articleBytes,
        'plan' => $planner->select($manifest, $articles, $bytes, $articleBytes)];
}

$out = ['pairs' => [], 'names' => [], 'manifest' => $manifest, 'plans' => $plans];
foreach ($pairs as [$subject, $actual, $packet]) {
    $out['pairs'][] = ['subject' => $subject, 'actual' => $actual, 'packet' => $packet, 'matches' => HeaderFilenameIdentity::matches($subject, $actual, $packet)];
}
foreach ($names as $name) {
    $out['names'][] = ['name' => $name, 'par2' => HeaderFilenameIdentity::isPar2Filename($name), 'payload' => HeaderFilenameIdentity::provesPayload($name)];
}
echo json_encode($out, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n";

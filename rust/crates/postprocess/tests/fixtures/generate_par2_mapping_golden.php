<?php
// Regenerates par2_mapping_golden.json from the real PHP class (no app bootstrap). After
// rust/porting/php-ref-setup.sh:
//   php generate_par2_mapping_golden.php <php-final checkout> > par2_mapping_golden.json
declare(strict_types=1);

$ref = $argv[1];
require $ref.'/vendor/autoload.php';

use App\Services\AdditionalProcessing\DTO\Par2FileEntry;
use App\Services\AdditionalProcessing\Media\Par2FileMapper;

$nzb = static fn (int $size, int $segments, int $filecount = 1): array => [
    'title' => 'f', 'segments' => $segments > 0 ? array_map(static fn (int $n): string => "s{$n}@x", range(1, $segments)) : [],
    'size' => $size, 'filecount' => $filecount, 'firstsegment' => true,
];
$file = static fn (string $name, int $size): array => ['name' => $name, 'size' => $size, 'hash16k' => ''];

$cases = [
    'unique sizes' => [[$nzb(1_030_000, 3), $nzb(52_000_000, 70), $nzb(10_300, 1)], [$file('Movie.mkv', 50_000_000), $file('Movie.nfo', 10_000), $file('Movie.srt', 1_000_000)], null, []],
    'equal volumes stay unmatched' => [[$nzb(52_000_000, 70), $nzb(52_000_000, 70), $nzb(30_000_000, 40)], [$file('a.part01.rar', 50_000_000), $file('a.part02.rar', 50_000_000), $file('a.part03.rar', 29_000_000)], null, []],
    'verified volume frees the rest' => [[$nzb(52_000_000, 70), $nzb(52_000_000, 70)], [$file('a.part01.rar', 50_000_000), $file('a.part02.rar', 50_000_000)], null, [0 => 1]],
    'segment counts decide' => [[$nzb(52_000_000, 70), $nzb(52_000_000, 71)], [$file('x.mkv', 50_000_000)], 716_800, []],
    'segment counts decide both' => [[$nzb(52_000_000, 70), $nzb(52_100_000, 71)], [$file('x.mkv', 50_000_000), $file('y.mkv', 50_300_000)], 716_800, []],
    'merged entries are skipped' => [[$nzb(52_000_000, 70, 2), $nzb(1_000, 1)], [$file('x.mkv', 50_000_000)], null, []],
    'decoded posted size' => [[$nzb(50_000_000, 70)], [$file('x.mkv', 50_049_000)], null, []],
    'too much bigger' => [[$nzb(50_000_000, 70)], [$file('x.mkv', 50_100_000)], null, []],
    'too small' => [[$nzb(50_000_000, 70)], [$file('x.mkv', 44_000_000)], null, []],
    'zero and empty' => [[$nzb(0, 1), $nzb(500, 0), $nzb(1_000, 1)], [$file('z.bin', 980), $file('n.bin', 0)], null, []],
    'one remux' => [[$nzb(61_500_000_000, 83_706)], [$file('Film.2160p.BluRay.REMUX.mkv', 60_000_000_000), $file('Film.nfo', 4_000)], 716_800, []],
];

$mapper = new Par2FileMapper;
$out = [];
foreach ($cases as $name => [$contents, $entries, $partSize, $verifiedIndexes]) {
    $objects = array_map(static fn (array $f): Par2FileEntry => new Par2FileEntry($f['name'], $f['size']), $entries);
    $verified = [];
    foreach ($verifiedIndexes as $source => $fileIndex) {
        $verified[$source] = $objects[$fileIndex];
    }
    $mapped = [];
    foreach ($mapper->map($contents, $objects, $partSize, $verified) as $source => $m) {
        $mapped[] = ['source_index' => $source, 'file_index' => array_search($m->file, $objects, true), 'confidence' => $m->confidence->value];
    }
    $candidates = [];
    foreach ($objects as $object) {
        $candidates[] = $mapper->candidateIndexes($contents, $object, $partSize);
    }
    // Segment lists are stored as counts; the test rebuilds them.
    $nzbOut = array_map(static fn (array $e): array => ['size' => $e['size'], 'segments' => count($e['segments']), 'filecount' => $e['filecount']], $contents);
    $out[] = ['name' => $name, 'nzb' => $nzbOut, 'files' => $entries, 'article_part_size' => $partSize,
        'verified' => (object) $verifiedIndexes, 'mapped' => $mapped, 'candidates' => $candidates];
}
echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";

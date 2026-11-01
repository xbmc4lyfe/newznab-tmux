<?php
// Regenerates nzb_contents_golden.json from the real PHP classes (no app bootstrap). After
// rust/porting/php-ref-setup.sh:
//   php generate_nzb_contents_golden.php <php-final checkout> > nzb_contents_golden.json
declare(strict_types=1);

$ref = $argv[1];
require $ref.'/vendor/autoload.php';

use App\Services\AdditionalProcessing\NzbContentParser;
use App\Services\Nzb\NzbParserService;

$segment = static fn (string $id, ?int $number = 1, string $bytes = '1000'): string => $number === null
    ? '<segment bytes="'.$bytes.'">'.$id.'</segment>'
    : '<segment bytes="'.$bytes.'" number="'.$number.'">'.$id.'</segment>';
$file = static fn (string $subject, array $segments): string => '<file poster="p" date="1" subject="'.htmlspecialchars($subject, ENT_QUOTES | ENT_XML1).'">'
    .'<groups><group>alt.binaries.test</group></groups><segments>'.implode('', $segments).'</segments></file>';
$nzb = static fn (array $files, string $head = ''): string => '<?xml version="1.0" encoding="UTF-8"?>'."\n"
    .'<nzb xmlns="http://www.newzbin.com/DTD/2003/nzb">'.($head === '' ? '' : '<head>'.$head.'</head>').implode('', $files).'</nzb>';

$documents = [
    'rar set' => $nzb([
        $file('[1/4] - "Movie.2160p.part02.rar" yEnc (1/2)', [$segment('b1@x'), $segment('b2@x', 2)]),
        $file('[2/4] - "Movie.2160p.part01.rar" yEnc (1/2)', [$segment('a2@x', 2), $segment('a1@x')]),
        $file('[3/4] - "Movie.2160p.par2" yEnc (1/1)', [$segment('p@x')]),
        $file('[4/4] - "Movie.2160p.vol00+01.par2" yEnc (1/1)', [$segment('v@x', 1, '77')]),
    ]),
    'one big mkv' => $nzb([$file('"Show.S01E01.1080p.WEB-DL.mkv" yEnc (1/3)', [$segment('m1@x'), $segment('m2@x', 2), $segment('m3@x', 3)])]),
    'split mkv' => $nzb([
        $file('"Film.mkv.001" yEnc (1/2)', [$segment('f1@x'), $segment('f2@x', 2)]),
        $file('"Film.mkv.002" yEnc (1/2)', [$segment('g1@x'), $segment('g2@x', 2)]),
        $file('"Film.mkv.010" yEnc (1/1)', [$segment('h1@x')]),
    ]),
    'merged by strip count' => $nzb([
        $file('Pack 1 of 3 "data.bin" yEnc', [$segment('d1@x')]),
        $file('Pack 2 of 3 "data.bin" yEnc', [$segment('d2@x', 2)]),
    ]),
    'old rar naming' => $nzb([
        $file('"Old.r00" yEnc (1/1)', [$segment('r0@x')]),
        $file('"Old.rar" yEnc (1/1)', [$segment('r@x')]),
        $file('"Old.r01" yEnc (1/1)', [$segment('r1@x')]),
    ]),
    'missing header segment' => $nzb([$file('"Late.mkv" yEnc (2/2)', [$segment('l2@x', 2)])]),
    'unnumbered segments' => $nzb([$file('"Loose.mkv" yEnc (1/2)', [$segment('u2@x', null), $segment('u1@x', null, '12abc')])]),
    'case and numbers' => $nzb([
        $file('"b10.mkv" yEnc (1/1)', [$segment('n10@x')]),
        $file('"B2.mkv" yEnc (1/1)', [$segment('n2@x')]),
        $file('"b2.mkv" yEnc (1/1)', [$segment('nb2@x')]),
        $file('"a 01.mkv" yEnc (1/1)', [$segment('a01@x')]),
        $file('"a 1.mkv" yEnc (1/1)', [$segment('a1x@x')]),
    ]),
    'declared count ok' => $nzb([$file('"Ok.mkv" yEnc (1/1)', [$segment('ok@x')])], '<meta type="nntmux:declared-file-count">1</meta>'),
    'declared count too high' => $nzb([$file('"Ok.mkv" yEnc (1/1)', [$segment('ok@x')])], '<meta type="nntmux:declared-file-count">2</meta>'),
    'unsafe name' => $nzb([$file('"../escape.mkv" yEnc (1/1)', [$segment('e@x')])]),
    'dot name' => $nzb([$file('".hidden.mkv" yEnc (1/1)', [$segment('e@x')])]),
    'part count mismatch' => $nzb([$file('"Short.mkv" yEnc (1/3)', [$segment('s1@x'), $segment('s2@x', 2)])]),
    'duplicate first article' => $nzb([
        $file('"One.mkv" yEnc (1/1)', [$segment('same@x')]),
        $file('"Two.mkv" yEnc (1/1)', [$segment('same@x')]),
    ]),
    'bare subject' => $nzb([$file('Bare.Name.mkv yEnc (1/1)', [$segment('bare@x')])]),
    'entity' => '<?xml version="1.0"?><!DOCTYPE nzb [<!ENTITY x "y">]><nzb><file subject="&quot;E.mkv&quot; yEnc (1/1)"><segments><segment number="1" bytes="1">e@x</segment></segments></file></nzb>',
    'escaped text' => $nzb([$file('"Amp &amp; Co.mkv" yEnc (1/1)', [$segment('a&amp;b@x')])]),
    'control bytes' => "<nzb>\x0F".'<file subject="&quot;C.mkv&quot; yEnc (1/1)"><segments><segment number="1" bytes="5">c@x</segment></segments></file></nzb>',
    'other control byte' => "<nzb>\x01".'<file subject="&quot;C.mkv&quot; yEnc (1/1)"><segments><segment number="1" bytes="5">c@x</segment></segments></file></nzb>',
    'wrong root' => '<notnzb><file subject="x"/></notnzb>',
    'broken' => '<nzb><file subject="x">',
    'cut after a file' => '<nzb><file subject="&quot;A.mkv&quot; yEnc (1/1)"><segments><segment number="1" bytes="5">a@x</segment></segments></file>',
    'cut inside a later file' => '<nzb><file subject="&quot;A.mkv&quot; yEnc (1/1)"><segments><segment number="1" bytes="5">a@x</segment></segments></file><file subject="&quot;B.mkv&quot; yEnc (1/1)"><segments>',
    'repeated segment number' => $nzb([$file('"Twice.mkv" yEnc (1/2)', [$segment('t1@x'), $segment('t2@x', 1)])]),
    'empty nzb' => '<nzb/>',
    'uppercase root' => '<NZB><file subject="&quot;U.mkv&quot; yEnc (1/1)"><segments><segment number="1" bytes="9">u@x</segment></segments></file></NZB>',
];

$parser = new NzbParserService;
// Only the pure inventory check is called, so the parser's services aren't needed.
$content = (new ReflectionClass(NzbContentParser::class))->newInstanceWithoutConstructor();
$inventory = new ReflectionMethod($content, 'sourceFileInventory');
$out = ['documents' => []];
foreach ($documents as $name => $xml) {
    $list = $parser->parseNzbFileList($xml, ['no-file-key' => false, 'strip-count' => true]);
    ksort($list, SORT_NATURAL);
    $files = [];
    foreach ($list as $file) {
        $files[] = ['title' => $file['title'], 'segments' => $file['segments'], 'size' => (int) $file['size'],
            'filecount' => $file['filecount'], 'firstsegment' => $file['firstsegment']];
    }
    $out['documents'][] = ['name' => $name, 'xml' => $xml, 'files' => $files,
        'inventory' => $list === [] ? null : $inventory->invoke($content, $xml, $list)];
}

$words = ['a', 'A', 'a1', 'a01', 'a001', 'a2', 'a10', 'a 1', 'a  1', ' a1', 'a1b', 'a1.5', 'a1.10', 'x0.5', 'x0.05', '1', '01', '10', '9', '', 'part01', 'part1', 'z', 'Z', 'img12.png', 'img10.png', 'rfc822.txt', 'rfc1.txt'];
$out['strnatcmp'] = [];
foreach ($words as $left) {
    foreach ($words as $right) {
        $out['strnatcmp'][] = [$left, $right, strnatcmp($left, $right) <=> 0];
    }
}

echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), "\n";

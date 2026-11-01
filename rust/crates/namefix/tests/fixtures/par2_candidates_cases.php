<?php
// Included by generate_par2_golden.php in `candidates` mode: runs the real
// NzbContentsService::par2Candidates (private, through reflection, without its constructor so
// no database is needed) over hand-built NZBs and records what it picked, its manifest and the
// signatures progress rows are keyed by.
declare(strict_types=1);
ini_set("display_errors", "stderr");
libxml_use_internal_errors(true);

use App\Services\Nzb\NzbContentsService;
use App\Services\Nzb\NzbParserService;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;

$container = new Container;
Container::setInstance($container);
$container->instance('config', new Repository(['tmux' => ['fix_names' => ['par2_max_probe_bytes' => 2097152]]]));

function seg(int|string $number, int|string $bytes, string $id): string
{
    return '<segment number="'.$number.'" bytes="'.$bytes.'">'.$id.'</segment>';
}

function file_xml(string $subject, array $segments, bool $wrap = true): string
{
    return '<file poster="p@x" date="1" subject="'.htmlspecialchars($subject, ENT_QUOTES | ENT_XML1).'"><groups><group>alt.binaries.test</group></groups>'
        .($wrap ? '<segments>'.implode('', $segments).'</segments>' : '').'</file>';
}

function nzb(array $files): string
{
    return '<?xml version="1.0" encoding="UTF-8"?><nzb xmlns="http://www.newzbin.com/DTD/2003/nzb">'.implode('', $files).'</nzb>';
}

function run_case(string $name, string $xml, int $maxBytes = 2097152): array
{
    $reflection = new ReflectionClass(NzbContentsService::class);
    $service = $reflection->newInstanceWithoutConstructor();
    $parser = (new ReflectionClass(NzbParserService::class))->newInstanceWithoutConstructor();
    $reflection->getProperty('parserService')->setValue($service, $parser);
    $doc = simplexml_load_string($xml);
    if ($doc === false) {
        return ['name' => $name, 'xml' => $xml, 'max_bytes' => $maxBytes, 'parsed' => false];
    }
    $config = app('config');
    $config->set('tmux.fix_names.par2_max_probe_bytes', $maxBytes);
    $method = $reflection->getMethod('par2Candidates');
    try {
        $candidates = $method->invoke($service, $doc);
    } catch (Throwable $e) {
        return ['name' => $name, 'xml' => $xml, 'max_bytes' => $maxBytes, 'parsed' => true, 'error' => (new ReflectionClass($e))->getShortName()];
    }
    $manifest = $reflection->getProperty('par2Manifest')->getValue($service);
    $nonExplicit = $reflection->getProperty('par2NonExplicitBytes')->getValue($service);
    $valid = $reflection->getProperty('par2StructureValid')->getValue($service);
    $signature = hash('sha256', json_encode(['guid' => 'abc', 'group' => 7, 'article_limit' => 2097152,
        'state_limit' => 8388608, 'alternate' => true, 'manifest' => $manifest, 'candidates' => $candidates], JSON_THROW_ON_ERROR));
    $family = $reflection->getMethod('par2FamilyKey');
    $per = [];
    foreach ($candidates as $c) {
        $per[] = ['subject' => $c['subject'], 'explicit' => $c['explicit'], 'classification' => $c['classification'], 'segments' => $c['segments'],
            'signature' => hash('sha256', json_encode(['subject' => $c['subject'], 'explicit' => $c['explicit'], 'segments' => array_map(static fn (array $s): array => [
                'number' => $s['number'], 'id' => $s['id'], 'bytes' => $s['bytes']], $c['segments'])], JSON_THROW_ON_ERROR)),
            'family' => $family->invoke($service, $c['subject'])];
    }

    return ['name' => $name, 'xml' => $xml, 'max_bytes' => $maxBytes, 'parsed' => true, 'candidates' => $per,
        'manifest' => json_encode($manifest), 'non_explicit_bytes' => $nonExplicit, 'structure_valid' => $valid, 'signature' => $signature];
}

$cases = [];
$rar = fn (int $n) => file_xml('[ '.$n.'/9 ] - "Some.Show.S01E01.rar" yEnc (1/3)', [seg(1, 700000, 'r'.$n.'a@x'), seg(2, 700000, 'r'.$n.'b@x'), seg(3, 100, 'r'.$n.'c@x')]);
$cases[] = run_case('standard-index-and-volumes', nzb([
    $rar(1),
    file_xml('[1/3] - "Some.Show.S01E01.par2" yEnc (1/1)', [seg(1, 40000, 'idx@x')]),
    file_xml('[2/3] - "Some.Show.S01E01.vol000+01.par2" yEnc (1/50)', array_map(fn ($i) => seg($i, 700000, "v1-$i@x"), range(1, 3))),
    file_xml('[3/3] - "Some.Show.S01E01.vol001+02.par2" yEnc (1/90)', [seg(1, 700000, 'v2-1@x')]),
]));
$cases[] = run_case('volumes-before-index-reordered', nzb([
    file_xml('"Other.vol000+01.PAR2" yEnc (1/2)', [seg(1, 500, 'ov1@x'), seg(2, 500, 'ov2@x')]),
    file_xml('"Some.vol000+01.par2" yEnc (1/2)', [seg(1, 500, 'sv1@x')]),
    file_xml('"Some.par2" yEnc (1/1)', [seg(1, 500, 'si@x')]),
    file_xml('"Some.vol001+02.par2" yEnc (1/2)', [seg(1, 500, 'sv2@x')]),
    file_xml('"video.mkv" yEnc (1/1)', [seg(1, 500, 'm@x')]),
]));
$cases[] = run_case('unnumbered-segments-out-of-order', nzb([
    file_xml('"pack.rar" yEnc (1/3)', [seg(3, 5, 'c@x'), seg(1, 5, 'a@x'), seg(2, 5, 'b@x')]),
    file_xml('"idx.par2" yEnc (1/3)', [seg(3, 5, 'ic@x'), seg(1, 5, 'ia@x'), seg(2, 5, 'ib@x')]),
]));
$cases[] = run_case('invalid-segments', nzb([
    file_xml('"gap.par2" yEnc (1/3)', [seg(1, 5, 'a@x'), seg(3, 5, 'c@x')]),
    file_xml('"zero.par2" yEnc (1/1)', [seg(1, 0, 'a@x')]),
    file_xml('"empty-id.par2" yEnc (1/1)', [seg(1, 5, '   ')]),
    file_xml('"lead0.par2" yEnc (1/1)', [seg('01', 5, 'a@x')]),
    file_xml('"plus.par2" yEnc (1/1)', [seg('+1', 5, 'p@x')]),
    file_xml('"spaces.par2" yEnc (1/1)', [seg(' 1 ', ' 5 ', ' sp@x ')]),
    file_xml('"dup.par2" yEnc (1/2)', [seg(1, 5, 'a@x'), seg(1, 5, 'b@x')]),
    file_xml('"alpha.par2" yEnc (1/1)', [seg('x', 5, 'a@x')]),
    file_xml('"big.par2" yEnc (1/1)', [seg(1, '99999999999999999999', 'a@x')]),
]));
$cases[] = run_case('no-segments', nzb([file_xml('"none.par2" yEnc (1/1)', [], false), file_xml('"empty.par2" yEnc (1/1)', [])]));
$cases[] = run_case('no-files', nzb([]));
$cases[] = run_case('not-xml', 'this is not xml');
$cases[] = run_case('unclosed-xml', '<nzb><file subject="a">');
$cases[] = run_case('hidden-and-legacy', nzb([
    file_xml('Release.Name.2024.mkv', [seg(1, 100000, 'big@x'), seg(2, 100000, 'big2@x')]),
    file_xml('qxz9f8a7s6d5f4g3h2j1k0', [seg(1, 3000, 'h1@x')]),
    file_xml('abc123def456', [seg(1, 1000, 'h2@x')]),
    file_xml('abc123def457', [seg(1, 2000, 'h3@x')]),
    file_xml('"movie.par" something (1/1)', [seg(1, 900, 'l1@x')]),
    file_xml('"movie.01" something (1/1)', [seg(1, 800, 'l2@x')]),
    file_xml('"movie.001" other (1/1)', [seg(1, 800, 'l3@x')]),
    file_xml('obfuscated blob (1/3)', [seg(1, 3000000, 'ob1@x'), seg(2, 3000000, 'ob2@x'), seg(3, 3000000, 'ob3@x')]),
    file_xml('hidden but huge (1/1)', [seg(1, 3000000, 'hu@x')]),
    file_xml('part.rar (1/1)', [seg(1, 10, 'sr@x')]),
    file_xml('part.nfo yEnc (2/2)', [seg(1, 10, 'sn@x')]),
]));
$cases[] = run_case('hidden-small-limit', nzb([
    file_xml('abc123def456', [seg(1, 1000, 'h2@x')]),
    file_xml('abc123def457', [seg(1, 2000, 'h3@x')]),
    file_xml('abc123def458', [seg(1, 2001, 'h4@x')]),
], ), 2000);
$cases[] = run_case('duplicate-files', nzb([
    file_xml('"dup.par2" yEnc (1/1)', [seg(1, 5, 'a@x')]),
    file_xml('"dup.par2" yEnc (1/1)', [seg(1, 5, 'a@x')]),
    file_xml('"dup.vol000+01.par2" yEnc (1/1)', [seg(1, 5, 'v@x')]),
    file_xml('"dup.vol000+01.par2" yEnc (1/1)', [seg(1, 5, 'v@x')]),
]));
$cases[] = run_case('entities-and-unicode', nzb([
    file_xml('"R&D <Café> \u{1F600} \'q\' \"x\".par2" yEnc (1/1)', [seg(1, 5, 'id&amp;1@x')]),
    file_xml("path/with/slashes \u{e9}t\u{e9}.vol003+04.par2 yEnc (1/1)", [seg(1, 5, 'id2@x')]),
    file_xml('  [01/10] "Spaced Name.vol010+20.PAR2"  (1/1)', [seg(1, 5, 'id3@x')]),
    file_xml('[01/10] Bare.Name.par2 trailing (1/1)', [seg(1, 5, 'id4@x')]),
    file_xml('Bare.Name.vol1+1.par2', [seg(1, 5, 'id5@x')]),
]));
$cases[] = run_case('multipart-par2-index', nzb([
    file_xml('"Many.par2" yEnc (1/2)', [seg(1, 5, 'm1@x'), seg(2, 5, 'm2@x')]),
    file_xml('("Single.par2") (1/1)', [seg(1, 5, 's@x')]),
    file_xml('no-extension-but-par2-in-name.par2', [seg(1, 5, 'n@x')]),
]));

$subjects = ['"a.par2" yEnc (1/1)', '[1/3] - "A.B.vol000+01.par2" yEnc (1/50)', 'x (3/4)', 'x [3/4]', 'x 3/4', 'x (0/0)', 'x 5)', 'plain', 'a (1/99999999999999999999)',
    "trailing newline (2/2)\n", '[02/10] bare.vol01+02.PAR2 more', "  \t'quoted.par2' ", 'weird."thing.par2"', 'A.PAR2', 'dir/a.par2'];
$extract = (new ReflectionClass(NzbParserService::class))->newInstanceWithoutConstructor();
$parts = [];
$family = (new ReflectionClass(NzbContentsService::class))->getMethod('par2FamilyKey');
$service = (new ReflectionClass(NzbContentsService::class))->newInstanceWithoutConstructor();
foreach ($subjects as $subject) {
    $parts[] = ['subject' => $subject, 'parts' => $extract->extractPartsTotal($subject), 'family' => $family->invoke($service, $subject)];
}

echo json_encode(['cases' => $cases, 'subjects' => $parts], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), "\n";

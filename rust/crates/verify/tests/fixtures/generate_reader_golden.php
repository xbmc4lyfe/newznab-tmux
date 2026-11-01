<?php

declare(strict_types=1);

// php generate_reader_golden.php > reader_golden.json
// Records what the PHP reader and coverage rules make of NZB documents.

require getenv('PHP_REF') ?: getenv('HOME').'/git.xbmc4lyfe/newznab-tmux-php-ref/vendor/autoload.php';

use App\Services\Nzb\NzbArticleCoverage;
use App\Services\Nzb\NzbArticleFingerprint;
use App\Services\Releases\Verification\NzbManifestReader;

$ns = 'xmlns="http://www.newzbin.com/DTD/2003/nzb"';
$file = '<file poster="p" date="1" subject="[01/03] - &quot;a.rar&quot; yEnc (1/2)"><groups><group>alt.binaries.a</group></groups><segments><segment number="1" bytes="10">one@x</segment><segment number="2" bytes="20"> &lt;two@x&gt; </segment></segments></file>';
$docs = [
    'plain' => "<nzb>$file</nzb>",
    'namespaced' => "<?xml version=\"1.0\"?>\n<nzb $ns>$file</nzb>",
    'head' => "<nzb $ns><head><meta type=\"nntmux:declared-file-count\">7</meta><meta type=\"nntmux:verification-mode\">spot-check</meta><meta type=\"password\">pa&amp;ss</meta><meta type=\"password\"></meta></head>$file</nzb>",
    'two_heads_password' => "<nzb $ns><head/><head><meta type=\"password\">x</meta></head>$file</nzb>",
    'password_child' => "<nzb $ns><head><meta type=\"password\"><b>x</b></meta></head>$file</nzb>",
    'no_files' => "<nzb $ns><head/></nzb>",
    'wrong_root' => "<feed>$file</feed>",
    'wrong_namespace' => '<nzb xmlns="urn:other">'.$file.'</nzb>',
    'malformed' => "<nzb><file></nzb>",
    'no_counter' => '<nzb><file subject="plain name"><groups><group>g</group><group>g</group></groups><segments><segment number="3" bytes="5">a@x</segment><segment number="3" bytes="5">b@x</segment></segments></file></nzb>',
    'foreign_file' => "<nzb $ns xmlns:o=\"urn:o\"><o:file subject=\"x\"><segments/></o:file>$file</nzb>",
    'foreign_segment' => "<nzb $ns xmlns:o=\"urn:o\"><file subject=\"s\"><groups><group>g</group></groups><segments><o:segment number=\"1\" bytes=\"1\">f@x</o:segment><segment number=\"1\" bytes=\"1\">k@x</segment></segments></file></nzb>",
    'bad_number' => '<nzb><file subject="s"><segments><segment number="0" bytes="1">a@x</segment></segments></file></nzb>',
    'bad_id' => '<nzb><file subject="s"><segments><segment number="1" bytes="1">a b@x</segment></segments></file></nzb>',
    'two_segments' => '<nzb><file subject="s"><segments><segment number="1" bytes="1">a@x</segment></segments><segments><segment number="2" bytes="1">b@x</segment></segments></file></nzb>',
    'cdata' => '<nzb><file subject="s"><segments><segment number="1" bytes="1"><![CDATA[c@x]]></segment></segments></file></nzb>',
];
$facts = (object) ['searchname' => 'Search', 'fromname' => 'From', 'postdate' => '2026-01-02 03:04:05', 'groups_id' => 3];
$coverage = (new ReflectionClass(NzbArticleCoverage::class))->newInstanceWithoutConstructor();
$reader = new NzbManifestReader;
$out = [];
foreach ($docs as $name => $xml) {
    $entry = ['xml' => $xml];
    try {
        $entry['manifest'] = $reader->read($xml, $facts);
    } catch (Throwable $error) {
        $entry['error'] = $error::class === App\Exceptions\NzbPasswordMetadataRejected::class ? 'password' : 'invalid';
    }
    $parsed = $coverage->parseXml($xml);
    $ids = $parsed === null ? null : $coverage->coverageIds($parsed);
    if ($ids !== null) {
        $list = array_map(static fn (string $id): string => substr($id, 1), array_map('strval', array_keys($ids)));
        sort($list);
        $entry['ids'] = $list;
    }
    $entry['fingerprint'] = $parsed === null ? null : NzbArticleFingerprint::fromXml($parsed);
    $out[$name] = $entry;
}
echo json_encode($out, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n";

<?php

declare(strict_types=1);

// Run from anywhere: php generate_manifest_golden.php > manifest_golden.json
// Records what the PHP reference computes for manifests the Rust port must agree with.

require getenv('PHP_REF') ?: getenv('HOME').'/git.xbmc4lyfe/newznab-tmux-php-ref/vendor/autoload.php';

use App\Services\Nzb\NzbArticleFingerprint;
use App\Services\Nzb\NzbSourceGuard;
use App\Services\Releases\Verification\CandidateHeaderInventory;
use App\Services\Releases\Verification\PreparedManifest;
use App\Services\Releases\Verification\VerificationLedger;
use App\Services\Releases\Verification\VerifiedNzbWriter;

function collection(array $overrides = []): array
{
    return array_replace(['legacy_release_id' => null, 'identity' => str_repeat('ab', 20),
        'subject' => 'Some/Release "name" é € 😀 [01/02]', 'fromname' => 'poster <poster@example.com>',
        'date' => '2026-10-31 12:30:45', 'groups_id' => 7, 'regex_id' => 3, 'expected_files' => 2,
        'groups' => ['alt.binaries.a', 'alt.binaries.b'], 'files' => [
            ['key' => str_repeat('0a', 16), 'subject' => 'file & one.rar "q"', 'totalparts' => 2, 'segments' => [
                ['number' => 1, 'messageid' => 'one@example.com', 'bytes' => 700000],
                ['number' => 2, 'messageid' => 'two@example.com', 'bytes' => 11],
            ]],
            ['key' => str_repeat('0b', 16), 'subject' => "tab\tline\nfile.par2", 'totalparts' => 1, 'segments' => [
                ['number' => 1, 'messageid' => '<three@example.com>', 'bytes' => 42],
            ]],
        ], 'conflict' => false], $overrides);
}

$cases = [
    'ready' => collection(),
    'waiting_files' => collection(['expected_files' => 3]),
    'waiting_articles' => (static function () {
        $m = collection();
        $m['files'][0]['totalparts'] = 5;

        return $m;
    })(),
    'conflict' => collection(['conflict' => true]),
    'no_files' => collection(['files' => []]),
    'duplicate_article' => (static function () {
        $m = collection();
        $m['files'][1]['segments'][0]['messageid'] = 'one@example.com';

        return $m;
    })(),
    'duplicate_key' => (static function () {
        $m = collection();
        $m['files'][1]['key'] = $m['files'][0]['key'];

        return $m;
    })(),
    'too_many_parts' => (static function () {
        $m = collection();
        $m['files'][1]['totalparts'] = 0;

        return $m;
    })(),
    'bad_message_id' => (static function () {
        $m = collection();
        $m['files'][0]['segments'][0]['messageid'] = 'has space@example.com';

        return $m;
    })(),
    'spot_check' => collection(['passwords' => ['pa<ss', "b&d\rvalue"], 'verification_mode' => 'spot_check', 'date' => '1999-01-02 03:04:05']),
    'empty_file_skipped' => (static function () {
        $m = collection(['verification_mode' => 'spot_check']);
        $m['files'][1]['segments'] = [];
        $m['files'][1]['totalparts'] = 3;

        return $m;
    })(),
    'quotes_in_text' => (static function () {
        $m = collection(['passwords' => ['pa"ss', ''], 'groups' => ['alt.binaries.a', '']]);
        $m['files'][0]['segments'][0]['messageid'] = 'quo"te@example.com';

        return $m;
    })(),
    'no_groups' => collection(['groups' => []]),
    'empty_document' => collection(['files' => [], 'expected_files' => 0]),
    'fractional_date' => collection(['date' => '2026-10-31 12:30:45.250']),
    'iso_date' => collection(['date' => '2026-10-31T12:30:45+02:00']),
    'date_only' => collection(['date' => '2026-10-31']),
];

$writer = (new ReflectionClass(VerifiedNzbWriter::class))->newInstanceWithoutConstructor();
$inventory = new CandidateHeaderInventory;
$out = ['manifests' => [], 'fingerprints' => [], 'keys' => []];
foreach ($cases as $name => $manifest) {
    $prepared = new PreparedManifest($manifest);
    $document = $writer->document($manifest);
    $assessment = $inventory->assessment($manifest);
    $out['manifests'][$name] = ['manifest' => $manifest, 'json' => json_encode($manifest, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE),
        'name' => $prepared->name, 'status' => $assessment['status'], 'reason' => $assessment['reason'],
        'partial_rejection' => $inventory->partialRejection($manifest), 'completeness' => $inventory->completeness($manifest),
        'bytes' => $inventory->bytes($manifest), 'document' => $document, 'digest' => NzbSourceGuard::digest($document)];
}
foreach ([['one@x', 'two@x'], ['two@x', 'one@x', '<one@x>', ' "two@x" '], ["'<three@x>'"], ['bad id@x', 'one@x'], [], ['']] as $ids) {
    $out['fingerprints'][] = ['ids' => $ids, 'value' => NzbArticleFingerprint::fromMessageIds($ids)];
}
foreach ([[1234, 'ABCDEF', 'The.Name.Ünï-2026', 'The Name', 9], [0, null, '', '', 0], [5, '', 'x', '', 0]] as [$size, $fingerprint, $name, $search, $predb]) {
    $keys = VerificationLedger::publicationKeys($size, $fingerprint, $name, $search, $predb);
    $out['keys'][] = ['size' => $size, 'fingerprint' => $fingerprint, 'name' => $name, 'search' => $search, 'predb' => $predb,
        'keys' => $keys, 'stripes' => array_map(VerificationLedger::publicationStripe(...), $keys)];
}
echo json_encode($out, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n";

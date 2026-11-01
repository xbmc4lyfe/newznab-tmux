<?php
// Regenerates par2_golden.json from the real PHP classes (accumulator, payload budgets, size
// helpers) and par2_candidates.json (NZB candidate selection and signatures). Run from anywhere:
//   php generate_par2_golden.php <php-final checkout> accumulator > par2_golden.json
//   php generate_par2_golden.php <php-final checkout> candidates > par2_candidates.json
// Packets are built here with real MD5 checksums; nothing needs a database.
declare(strict_types=1);

$ref = $argv[1];
$mode = $argv[2] ?? 'accumulator';
require $ref.'/vendor/autoload.php';

use App\Services\Nzb\NzbContentsService;
use App\Services\Nzb\NzbParserService;
use App\Services\Par2PacketAccumulator;
use App\Services\Par2Processor;
use App\Support\Par2FileHashEvidence;

function short(Throwable $e): string
{
    return (new ReflectionClass($e))->getShortName();
}

function pkt(string $set, string $type, string $body): string
{
    $tail = $set.$type.$body;

    return "PAR2\0PKT".pack('P', 64 + strlen($body)).md5($tail, true).$tail;
}

function pad(string $s): string
{
    return $s.str_repeat("\0", (4 - strlen($s) % 4) % 4);
}

const T_MAIN = "PAR 2.0\0Main\0\0\0\0";
const T_DESC = "PAR 2.0\0FileDesc";
const T_IFSC = "PAR 2.0\0IFSC\0\0\0\0";
const T_RECV = "PAR 2.0\0RecvSlic";
const T_CREATOR = "PAR 2.0\0Creator\0";
const T_UNI = "PAR 2.0\0UniFileN";
const T_RFSC = "PAR 2.0\0RFSC\0\0\0\0";

function id(int $n): string
{
    return md5('id'.$n, true);
}

function main(string $set, array $rec, array $other = []): string
{
    return pkt($set, T_MAIN, pack('P', 16384).pack('V', count($rec)).implode('', $rec).implode('', $other));
}

function desc(string $set, string $fileId, string $name, int|string $size = 1000, ?string $hash = null, ?string $h16 = null): string
{
    $sizeBytes = is_int($size) ? pack('P', $size) : $size;

    return pkt($set, T_DESC, $fileId.($hash ?? md5($name.'full', true)).($h16 ?? md5($name.'16k', true)).$sizeBytes.pad($name));
}

function ifsc(string $set, string $fileId, int $blocks): string
{
    $body = $fileId;
    for ($i = 0; $i < $blocks; $i++) {
        $body .= md5((string) $i, true).pack('V', $i);
    }

    return pkt($set, T_IFSC, $body);
}

function recv(string $set, int $exp, int $payload): string
{
    return pkt($set, T_RECV, pack('V', $exp).str_repeat('R', $payload));
}

function chunks(string $stream, array $sizes): array
{
    $out = [];
    $at = 0;
    foreach ($sizes as $size) {
        if ($at >= strlen($stream)) {
            break;
        }
        $out[] = substr($stream, $at, $size);
        $at += $size;
    }
    if ($at < strlen($stream)) {
        $out[] = substr($stream, $at);
    }

    return $out;
}

$SET1 = md5('set1', true);
$SET2 = md5('set2', true);
$A = id(1);
$B = id(2);
$C = id(3);

if ($mode === 'candidates') {
    require __DIR__.'/par2_candidates_cases.php';
    exit(0);
}

$scenarios = [];
$add = function (string $name, array $chunkList, int $max = 262144) use (&$scenarios): void {
    $scenarios[] = ['name' => $name, 'max' => $max, 'chunks' => $chunkList];
};

$full = main($SET1, [$A, $B]).desc($SET1, $A, 'Some.Release.2024.1080p.mkv', 5000000).desc($SET1, $B, 'Some.Release.2024.1080p.nfo', 1200).ifsc($SET1, $A, 3).recv($SET1, 0, 40).recv($SET1, 1, 40);
$add('complete-one-chunk', [$full]);
$add('complete-split-1', chunks($full, array_fill(0, 150, 1)));
$add('complete-split-17', chunks($full, array_fill(0, 100, 17)));
$add('complete-split-header', chunks($full, [3, 5, 8, 16, 40, 100, 7]));
$add('descriptors-before-main', [desc($SET1, $A, 'a.bin', 10).main($SET1, [$A])]);
$add('ifsc-before-desc', [main($SET1, [$A]).ifsc($SET1, $A, 2).desc($SET1, $A, 'ifsc.first.mkv', 77)]);
$add('main-only', [main($SET1, [$A, $B])]);
$add('desc-only', [desc($SET1, $A, 'only.desc.rar', 123)]);
$add('main-missing-desc', [main($SET1, [$A, $B]).desc($SET1, $A, 'one.rar', 5)]);
$add('other-file-ids', [main($SET1, [$A], [$B]).desc($SET1, $A, 'x.rar', 5).desc($SET1, $B, 'y.rar', 6)]);
$add('two-sets', [main($SET1, [$A]).desc($SET1, $A, 'first.rar', 10).main($SET2, [$A]).desc($SET2, $A, 'second.rar', 20)]);
$add('two-sets-interleaved', [desc($SET2, $B, 'second.rar', 20).desc($SET1, $A, 'first.rar', 10).main($SET1, [$A]).main($SET2, [$B])]);
$add('same-file-id-two-sets', [desc($SET1, $A, 'same.id.a.rar', 10).desc($SET2, $A, 'same.id.b.rar', 20)]);
$add('empty-name', [main($SET1, [$A]).desc($SET1, $A, '', 10)]);
$add('zero-name', [main($SET1, [$A]).desc($SET1, $A, '0', 10)]);
$add('space-name', [main($SET1, [$A]).desc($SET1, $A, '   ', 10)]);
$add('trailing-space-name', [main($SET1, [$A]).desc($SET1, $A, 'name with trailing  ', 10)]);
$add('non-utf8-name', [main($SET1, [$A]).desc($SET1, $A, "caf\xe9.rar", 10)]);
$add('unicode-name', [main($SET1, [$A]).desc($SET1, $A, "dossier \u{e9}t\u{e9}/x.rar", 10)]);
$add('huge-size', [main($SET1, [$A]).desc($SET1, $A, 'huge.bin', "\xff\xff\xff\xff\xff\xff\xff\xff")]);
$add('size-2^63', [main($SET1, [$A]).desc($SET1, $A, 'big.bin', "\x00\x00\x00\x00\x00\x00\x00\x80")]);
$add('size-int-max', [main($SET1, [$A]).desc($SET1, $A, 'max.bin', "\xff\xff\xff\xff\xff\xff\xff\x7f")]);
$add('duplicate-identical', [main($SET1, [$A]).desc($SET1, $A, 'dup.rar', 10).desc($SET1, $A, 'dup.rar', 10)]);
$add('conflicting-identity', [desc($SET1, $A, 'dup.rar', 10).desc($SET1, $A, 'dup.rar', 11)]);
$add('conflicting-main', [main($SET1, [$A]).main($SET1, [$B])]);
$add('creator-and-optional', [pkt($SET1, T_CREATOR, pad('creator text')).pkt($SET1, T_UNI, $A.pad('u')).pkt($SET1, T_RFSC, pad('abcd')).main($SET1, [$A]).desc($SET1, $A, 'c.rar', 4)]);
$add('payload-skip-spans-chunks', chunks(main($SET1, [$A]).desc($SET1, $A, 'p.rar', 4).recv($SET1, 0, 4000).desc($SET1, $B, 'after.rar', 4), [150, 1000, 1000, 3000]));
$add('skip-only', [recv($SET1, 0, 100)]);
$bad = $full;
$bad[100] = $bad[100] === 'x' ? 'y' : 'x';
$add('bad-checksum', [$bad]);
$add('bad-marker', ["XAR2\0PKT".substr($full, 8)]);
$add('bad-marker-partial', ['PAR3']);
$add('partial-marker-ok', ['PAR2']);
$add('unknown-type', [pkt($SET1, "PAR 2.0\0Bogus\0\0\0\0\0", pad('zz'))]);
$add('length-not-multiple-of-four', ["PAR2\0PKT".pack('P', 66).str_repeat("\0", 50)]);
$add('length-too-small', ["PAR2\0PKT".pack('P', 60).str_repeat("\0", 50)]);
$add('length-high-bit', ["PAR2\0PKT"."\x40\0\0\0\0\0\0\x80".str_repeat("\0", 50)]);
$add('main-count-too-big', [pkt($SET1, T_MAIN, pack('P', 16384).pack('V', 5).$A)]);
$add('main-bad-body', [pkt($SET1, T_MAIN, pack('P', 16384).pack('V', 0).'abcd')]);
$add('desc-short-body', [pkt($SET1, T_DESC, str_repeat("\0", 52))]);
$add('ifsc-bad-body', [pkt($SET1, T_IFSC, $A.str_repeat("\0", 8))]);
$add('packet-over-state-limit', [main($SET1, [$A]).desc($SET1, $A, str_repeat('n', 600), 1)], 700);
$add('state-limit-after-append', chunks($full, [200, 200, 5000]), 900);
$add('state-limit-empty', [''], 40);
$add('tail-state-limit', [substr($full, 0, 100)], 130);
$add('append-after-failure', [main($SET1, [$A]), 'garbage-not-par2', desc($SET1, $A, 'x.rar', 1)]);
$add('resume-header-boundary', [substr($full, 0, 64), substr($full, 64, 1), substr($full, 65)]);
$add('empty-chunks', ['', main($SET1, [$A]), '']);

$out = [];
foreach ($scenarios as $scenario) {
    $acc = null;
    $steps = [];
    try {
        $acc = new Par2PacketAccumulator($scenario['max']);
    } catch (Throwable $e) {
        $out[] = ['name' => $scenario['name'], 'max' => $scenario['max'], 'chunks' => array_map('base64_encode', $scenario['chunks']), 'constructor_error' => short($e)];

        continue;
    }
    foreach ($scenario['chunks'] as $chunk) {
        try {
            $acc->append($chunk);
            $steps[] = ['ok' => true, 'state' => json_encode($acc->exportState()), 'bytes' => $acc->stateBytes()];
        } catch (Throwable $e) {
            $steps[] = ['ok' => false, 'error' => short($e), 'state' => json_encode($acc->exportState()), 'bytes' => $acc->stateBytes()];
        }
    }
    $result = ['name' => $scenario['name'], 'max' => $scenario['max'], 'chunks' => array_map('base64_encode', $scenario['chunks']), 'steps' => $steps];
    $probe = function (callable $fn) {
        try {
            return ['ok' => $fn()];
        } catch (Throwable $e) {
            return ['error' => short($e)];
        }
    };
    $result['is_complete'] = $probe(fn () => $acc->isComplete());
    $result['main_complete'] = $probe(fn () => $acc->hasCompleteMainInventory());
    $result['files_by_set'] = $probe(function () use ($acc) {
        $sets = [];
        foreach ($acc->filesBySet() as $set => $files) {
            $list = [];
            foreach ($files as $fileId => $file) {
                $list[] = ['file_id' => (string) $fileId, 'name' => isset($file['name']) ? base64_encode($file['name']) : null,
                    'size' => isset($file['size']) ? (is_int($file['size']) ? (string) $file['size'] : sprintf('%.0f', $file['size'])) : null,
                    'size_float' => isset($file['size']) && is_float($file['size']),
                    'size_decimal' => $file['size_decimal'] ?? null, 'hash' => $file['hash'] ?? null, 'hash_16K' => $file['hash_16K'] ?? null,
                    'blocks' => $file['blocks'] ?? null];
            }
            $sets[] = ['set' => (string) $set, 'files' => $list];
        }

        return $sets;
    });
    $result['descriptors'] = $probe(fn () => array_map(static fn (array $d): array => ['filename_b64' => base64_encode($d['filename_bytes'])] + $d + [], $acc->descriptors()));
    foreach ($result['descriptors']['ok'] ?? [] as $i => $d) {
        unset($result['descriptors']['ok'][$i]['filename_bytes']);
    }
    $result['from_state'] = $probe(function () use ($acc, $scenario) {
        $round = Par2PacketAccumulator::fromState($acc->exportState(), $scenario['max']);

        return json_encode($round->exportState()) === json_encode($acc->exportState());
    });
    $budgets = [];
    foreach ([null, 0, 1, 500, 5000, 1200, 6200, 5001200, 6000000, PHP_INT_MAX] as $payload) {
        $budgets[] = ['payload' => $payload, 'exceeds' => $probe(fn () => Par2Processor::exceedsPayloadBudget($acc, $payload))];
    }
    $result['budgets'] = $budgets;
    $out[] = $result;
}

$states = [];
$good = (new Par2PacketAccumulator)->exportState();
$acc = new Par2PacketAccumulator;
$acc->append($full);
$state = $acc->exportState();
$mutate = function (string $name, array $s) use (&$states): void {
    $try = null;
    try {
        $r = Par2PacketAccumulator::fromState($s, 262144);
        $try = ['ok' => json_encode($r->exportState())];
    } catch (Throwable $e) {
        $try = ['error' => short($e)];
    }
    $states[] = ['name' => $name, 'state' => json_encode($s), 'result' => $try];
};
$mutate('empty', $good);
$mutate('full', $state);
$mutate('wrong-version', ['version' => 2] + $state);
$mutate('string-version', ['version' => '1'] + $state);
$mutate('missing-key', ['version' => 1, 'packets' => [], 'tail' => '']);
$mutate('extra-key', $state + ['extra' => 1]);
$mutate('packets-not-list', ['packets' => ['a' => 'b']] + $state);
$mutate('packet-not-string', ['packets' => [1]] + $state);
$mutate('tail-not-string', ['tail' => 5] + $state);
$mutate('skip-negative', ['skip_remaining' => -1] + $state);
$mutate('skip-with-tail', ['skip_remaining' => 4, 'tail' => base64_encode('PAR2')] + $good);
$mutate('skip-only', ['skip_remaining' => 4000] + $good);
$mutate('bad-base64', ['packets' => ['@@@@']] + $state);
$mutate('noncanonical-base64', ['tail' => 'UEFSMg'] + $good);
$mutate('tail-ok', ['tail' => base64_encode('PAR2')] + $good);
$mutate('tail-bad-marker', ['tail' => base64_encode('XXXX')] + $good);
$mutate('packet-reordered', ['packets' => array_reverse($state['packets'])] + $state);
$mutate('duplicate-packet', ['packets' => [$state['packets'][0], $state['packets'][0]]] + $state);

$sizes = [];
foreach ([0, 5, -1, '0', '12', '012', '9223372036854775807', '18446744073709551615', '18446744073709551616', '', 'x', ' 1', '1 ', '+1', 1.5, null] as $v) {
    $sizes[] = ['input' => is_float($v) ? ['float' => $v] : $v, 'result' => Par2FileHashEvidence::sizeDecimal($v)];
}
$le = [];
foreach (["\0\0\0\0\0\0\0\0", "\x01\0\0\0\0\0\0\0", "\xff\xff\xff\xff\0\0\0\0", "\0\0\0\0\x01\0\0\0", "\xff\xff\xff\xff\xff\xff\xff\xff"] as $bytes) {
    $le[] = ['bytes_b64' => base64_encode($bytes), 'result' => Par2FileHashEvidence::littleEndianSize($bytes)];
}

$fits = [];
$fileSets = [
    [['name' => 'a', 'size_decimal' => '100'], ['name' => 'b', 'size_decimal' => '200']],
    [['name' => 'a', 'size_decimal' => '9223372036854775807']],
    [['name' => 'a', 'size_decimal' => '9223372036854775808']],
    [['name' => 'a', 'size_decimal' => '18446744073709551615']],
    [['blocks' => 3]],
    [['name' => 'a', 'size_decimal' => '0'], ['blocks' => 3]],
    [],
];
foreach ($fileSets as $files) {
    foreach ([null, -1, 0, 1, 100, 299, 300, 301, PHP_INT_MAX] as $max) {
        $fits[] = ['files' => $files, 'max' => $max, 'fits' => Par2Processor::setFitsPayloadBudget($files, $max)];
    }
}

echo json_encode(['scenarios' => $out, 'states' => $states, 'sizes' => $sizes, 'little_endian' => $le, 'fits' => $fits], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR | JSON_PARTIAL_OUTPUT_ON_ERROR), "\n";

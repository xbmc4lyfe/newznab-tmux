<?php

// Regenerates planner-golden.json from the php-final reference, seeded so the corpus is stable:
//   php generate_planner_golden.php "$PHP_REF" /tmp/ffi/release/libnzbyenc.dylib > planner-golden.json
// The planner and obfuscation rule run as PHP, with their native paths turned off.
// The article fingerprint and release names come from the FFI library.

declare(strict_types=1);

[$script, $reference, $library] = $argv;
require $reference.'/vendor/autoload.php';

use App\Services\Binaries\HeaderParser;
use App\Services\Releases\Verification\SpotCheckPlanner;

mt_srand(20261101);

$ffi = FFI::cdef(<<<'C'
    void* nntmux_article_fingerprint_new(void);
    int nntmux_article_fingerprint_add(void* state, const uint8_t* message_id, size_t length);
    int nntmux_article_fingerprint_value(void* state, uint8_t* output);
    void nntmux_article_fingerprint_free(void* state);
    uint8_t* nntmux_release_names_html(const uint8_t* data, size_t length, size_t* output_length);
    void nntmux_release_names_free(uint8_t* data, size_t length);
    C, $library);

function token(int $length): string
{
    $alphabet = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    $out = '';
    for ($i = 0; $i < $length; $i++) {
        $out .= $alphabet[mt_rand(0, 61)];
    }

    return $out;
}

// Spot-check plans. A native planner pointed at no library always declines, so the PHP rule runs.
$noLibrary = new App\Services\Releases\Verification\NativeSpotCheckPlanner('/nonexistent');
$planner = new SpotCheckPlanner($noLibrary);
$plans = [];
for ($case = 0; $case < 300; $case++) {
    $files = [];
    $fileCount = mt_rand(0, 6);
    for ($f = 0; $f < $fileCount; $f++) {
        $parts = mt_rand(0, 30);
        $inferred = mt_rand(0, 2) === 0;
        $segments = [];
        for ($s = 1; $s <= $parts; $s++) {
            $segments[] = [
                'number' => mt_rand(0, 40) === 0 ? 0 : $s,
                'messageid' => mt_rand(0, 25) === 0 ? 'shared@id' : (mt_rand(0, 40) === 0 ? '' : token(12).'@x'),
                'bytes' => mt_rand(0, 40) === 0 ? 0 : mt_rand(1, 800_000),
            ];
        }
        $files[] = [
            'key' => mt_rand(0, 8) === 0 ? 'duplicate-key' : 'file-'.$f,
            'totalparts' => mt_rand(0, 3) === 0 ? null : max(0, $parts - mt_rand(-2, 2)),
            'totalparts_inferred' => $inferred,
            'segments' => $segments,
        ];
    }
    $manifest = ['identity' => 'release-'.mt_rand(0, 1000), 'files' => $files];
    $maximumArticles = [0, 1, 3, 8, 16, 17, 64][mt_rand(0, 6)];
    $maximumHeaderBytes = [0, 500_000, 2_000_000, 20_000_000, 200_000_000][mt_rand(0, 4)];
    $maximumArticleBytes = mt_rand(0, 2) === 0 ? null : [400_000, 800_000][mt_rand(0, 1)];
    $plans[] = [
        'manifest' => $manifest,
        'maximum_articles' => $maximumArticles,
        'maximum_header_bytes' => $maximumHeaderBytes,
        'maximum_article_bytes' => $maximumArticleBytes,
        'expected' => $planner->select($manifest, $maximumArticles, $maximumHeaderBytes, $maximumArticleBytes),
    ];
}

// Per-article obfuscation.
$parser = new HeaderParser(native: false);
$ranges = [];
for ($case = 0; $case < 60; $case++) {
    $headers = [];
    $shape = $case % 6;
    $total = [2, 5, 50, 300][mt_rand(0, 3)];
    $count = mt_rand(0, 120);
    for ($i = 1; $i <= $count; $i++) {
        $part = mt_rand(1, $total);
        $name = match ($shape) {
            0 => token(24).'.rar',
            1 => 'repeated'.token(20)[0].'name.part'.mt_rand(1, 9).'.rar',
            2 => '[1/9] - "'.token(32).'" ',
            3 => 'stable0123456789abcdef.part07.rar',
            4 => str_repeat(' ', mt_rand(0, 3)).token(mt_rand(10, 20)).' file '.mt_rand(1, 3).' of 9 ',
            default => mt_rand(0, 1) ? token(18).'.bin' : 'no token here',
        };
        $subject = $name.' yEnc ('.$part.'/'.$total.')';
        if ($case === 59 && $i === 1) {
            $subject = str_repeat('x', 700).' ('.$part.'/'.$total.')';
        }
        $headers[] = ['Number' => (string) (1000 + $i), 'Subject' => $subject];
    }
    foreach ([1, 3, 16] as $minimum) {
        $parser->markPerArticleObfuscated($headers, $minimum);
        $ranges[] = [
            'subjects' => array_map(static fn (array $header): string => bin2hex($header['Subject']), $headers),
            'minimum' => $minimum,
            'expected' => array_map(intval(...), $parser->perArticleObfuscatedNumbers()),
        ];
    }
}

// Article fingerprints.
$fingerprints = [];
foreach ([
    [],
    ['a@b'],
    ['<a@b>', 'a@b', ' <a@b> '],
    ['a@b', 'c@d'],
    ['c@d', 'a@b'],
    ['bad id with space'],
    ['a@b', ''],
    [str_repeat('x', 255)],
    [str_repeat('x', 256)],
    array_map(static fn (int $i): string => 'id'.$i.'@x', range(1, 5000)),
    array_map(static fn (int $i): string => 'id'.($i % 7).'@x', range(1, 5000)),
] as $ids) {
    $state = $ffi->nntmux_article_fingerprint_new();
    foreach ($ids as $id) {
        $bytes = $ffi->new('uint8_t['.max(1, strlen($id)).']');
        FFI::memcpy($bytes, $id, strlen($id));
        $ffi->nntmux_article_fingerprint_add($state, $bytes, strlen($id));
    }
    $output = $ffi->new('uint8_t[40]');
    $status = $ffi->nntmux_article_fingerprint_value($state, $output);
    $ffi->nntmux_article_fingerprint_free($state);
    $fingerprints[] = [
        'ids' => count($ids) > 300 ? null : array_map(bin2hex(...), $ids),
        'generated_count' => count($ids) > 300 ? count($ids) : null,
        'duplicates' => count($ids) > 300 && count(array_unique($ids)) < count($ids),
        'value' => $status === 1 ? bin2hex(FFI::string($output, 40)) : null,
    ];
}

// Admin release names.
$names = [];
foreach ([
    ['url_prefix' => '/details/', 'url_suffix' => '', 'releases' => []],
    ['url_prefix' => 'https://x.test/details/', 'url_suffix' => '?a=1&b=2', 'releases' => [
        ['guid' => 'abc-123', 'searchname' => 'Clean.Name-GRP', 'name' => 'Clean.Name-GRP'],
        ['guid' => 'def', 'searchname' => '"Quoted <b>name</b>" yEnc', 'name' => 'raw & original'],
        ['guid' => 'ghi', 'searchname' => null, 'name' => null],
        ['guid' => 'jkl', 'searchname' => 'Ünï.çødé', 'name' => '[1/2] "Ünï.çødé" yEnc'],
    ]],
    ['url_prefix' => '/details/', 'url_suffix' => '', 'releases' => [['guid' => 'needs/escaping', 'searchname' => 'x', 'name' => 'y']]],
] as $request) {
    $input = json_encode($request, JSON_THROW_ON_ERROR);
    $bytes = $ffi->new('uint8_t['.strlen($input).']');
    FFI::memcpy($bytes, $input, strlen($input));
    $length = $ffi->new('size_t[1]');
    $result = $ffi->nntmux_release_names_html($bytes, strlen($input), FFI::addr($length[0]));
    $rows = null;
    if ($result !== null) {
        $text = $length[0] === 0 ? '' : FFI::string($result, $length[0]);
        $rows = $text === '' ? [] : explode("\xff", $text);
        $ffi->nntmux_release_names_free($result, $length[0]);
    }
    $names[] = ['request' => $input, 'rows' => $rows];
}

echo json_encode(['plans' => $plans, 'ranges' => $ranges, 'fingerprints' => $fingerprints, 'names' => $names], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), "\n";

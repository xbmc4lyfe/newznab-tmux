<?php

// Regenerates golden.json from the php-final reference. Run from anywhere:
//   CARGO_TARGET_DIR=/tmp/ffi cargo build --release --manifest-path "$PHP_REF/docker/app/nzb-yenc-ffi/Cargo.toml"
//   php generate_golden.php "$PHP_REF" /tmp/ffi/release/libnzbyenc.dylib > golden.json
// YencService runs on its PHP decoder; "native_raw" and "framing" come from the FFI library.

declare(strict_types=1);

[$script, $reference, $library] = $argv;
require $reference.'/vendor/autoload.php';

use App\Services\Yenc\ArticleFrame;
use App\Services\YencService;
use Tests\Fixtures\YencArticles;

require_once $reference.'/tests/Fixtures/YencArticles.php';

$ffi = FFI::cdef(<<<'C'
    size_t rapidyenc_decode_ex(int is_raw, const void* src, void* dest, size_t src_length, int* state);
    char* nntmux_yenc_framing_metadata_json(const uint8_t* data, size_t length);
    void nntmux_metadata_string_free(char* value);
    C, $library);

function hexOrNull(?string $value): ?string
{
    return $value === null ? null : bin2hex($value);
}

function fieldsHex(?array $fields): array|object|null
{
    if ($fields === null) {
        return null;
    }
    ksort($fields, SORT_STRING);

    return array_map(bin2hex(...), $fields) ?: (object) [];
}

function nativeRaw(FFI $ffi, string $payload): string
{
    if ($payload === '') {
        return '';
    }
    $output = $ffi->new('char['.strlen($payload).']');
    $written = $ffi->rapidyenc_decode_ex(0, $payload, $output, strlen($payload), null);

    return bin2hex(FFI::string($output, $written));
}

// Kept as the library's JSON text, because PHP would round its 64-bit integers.
function framing(FFI $ffi, string $text): ?string
{
    $bytes = $ffi->new('uint8_t['.max(1, strlen($text)).']');
    FFI::memcpy($bytes, $text, strlen($text));
    $json = $ffi->nntmux_yenc_framing_metadata_json($bytes, strlen($text));
    if ($json === null) {
        return null;
    }
    $decoded = FFI::string($json);
    $ffi->nntmux_metadata_string_free($json);

    return $decoded;
}

$articles = [
    'plain' => 'ordinary text',
    'header only' => "=ybegin line=128 size=3 name=x\nklm",
    'quoted header' => "quoted =ybegin line=128 size=3 name=x\nklm\n=yend size=3",
    'discussion' => "=ybegin is an example\nabc\n=yend is the end",
    'wrong control prefix' => "=ybeginning line=128 size=3 name=x\nklm\n=yend size=3",
    'empty' => '',
    'corrupt size' => "=ybegin line=128 size=9 name=x\nklm\n=yend size=3",
    'corrupt checksum' => "=ybegin line=128 size=3 name=x\nklm\n=yend size=3 crc32=deadbeef",
    'invalid checksum' => "=ybegin line=128 size=3 name=x\nklm\n=yend size=3 crc32=xyz",
    'missing trailer size' => "=ybegin line=128 size=3 name=x\nklm\n=yend crc32=a3830348",
    'overflow' => "=ybegin line=128 size=99999999999999999999999999 name=x\nklm\n=yend size=3",
    'dangling escape' => "=ybegin line=128 size=3 name=x\nklm=\n=yend size=3",
    'missing range' => "=ybegin part=1 line=128 size=9 name=x\nklm\n=yend size=3 part=1 pcrc32=a3830348",
    'reversed range' => "=ybegin part=1 line=128 size=9 name=x\n=ypart begin=6 end=4\nklm\n=yend size=3 part=1 pcrc32=a3830348",
    'part mismatch' => "=ybegin part=1 line=128 size=9 name=x\n=ypart begin=1 end=3\nklm\n=yend size=3 part=2 pcrc32=a3830348",
    'range starts at zero' => "=ybegin part=1 line=128 size=9 name=x\n=ypart begin=0 end=2\nklm\n=yend size=3 part=1 pcrc32=a3830348",
    'range exceeds file' => "=ybegin part=1 line=128 size=2 name=x\n=ypart begin=1 end=3\nklm\n=yend size=3 part=1 pcrc32=a3830348",
    'part exceeds total' => "=ybegin part=2 total=1 line=128 size=9 name=x\n=ypart begin=1 end=3\nklm\n=yend size=3 part=2 pcrc32=a3830348",
    'total mismatch' => "=ybegin part=1 total=3 line=128 size=9 name=x\n=ypart begin=1 end=3\nklm\n=yend size=3 part=1 total=2 pcrc32=a3830348",
    'trailer total without header total' => "=ybegin part=1 line=128 size=9 name=x\n=ypart begin=1 end=3\nklm\n=yend size=3 part=1 total=2 pcrc32=a3830348",
    'trailer part without header' => "=ybegin line=128 size=3 name=x\nklm\n=yend size=3 part=1 pcrc32=a3830348",
    'missing part crc' => "=ybegin part=1 line=128 size=9 name=x\n=ypart begin=1 end=3\nklm\n=yend size=3 part=1 crc32=a3830348",
    'bad part crc' => "=ybegin part=1 line=128 size=9 name=x\n=ypart begin=1 end=3\nklm\n=yend size=3 part=1 pcrc32=deadbeef crc32=a3830348",
    'good multipart' => "=ybegin part=2 total=3 line=128 size=9 name=multi.bin\r\n=ypart begin=4 end=6\r\nklm\r\n=yend size=3 part=2 pcrc32=a3830348 crc32=deadbeef",
    'duplicate fields' => "=ybegin\tline=64 LINE=128 size=9 size=3 extra-field= unknown_2=v NaMe= first name=second size=999\r\r\nklm\r\n=yend size=9 SIZE=3 crc32=a3830348 name=trailer ignored=after\r\r\n",
    'empty name and part name' => "=ybegin line=128 size=3 names=other NAME=\r\n=ypart begin=9 BEGIN=1 end=3 name=part after=\r\nklm\n=yend size=3 crc32=a3830348\n",
    'binary filename' => "=ybegin line=128 size=3 name=\x00\xff\tfile\rinside.bin\r\nklm\n=yend size=3 crc32=a3830348",
    'filename blanks' => "=ybegin line=128 size=3 name= \x0b\x00 spaced.bin \t \r\nklm\n=yend size=3 crc32=a3830348",
    'empty payload' => "=ybegin line=128 size=0 name=empty.bin\n=yend size=0 crc32=0",
    'zero padded uppercase' => "=YBEGIN line=128 size=0 name=empty\n\n=YEND size=0 crc32=00000000",
    'short crc' => "=YBEGIN line=128 size=0 name=empty\n\n=YEND size=0 crc32=0",
    'escape pairs' => "=ybegin line=128 size=2 name=x\n====\n=yend size=2",
    'escape before crlf' => "=ybegin line=128 size=4 name=x\r\nklm=\r\nM\r\n=yend size=4",
    'escape before lf' => "=ybegin line=128 size=4 name=x\nklm=\nM\n=yend size=4",
    'escape before cr' => "=ybegin line=128 size=4 name=x\nklm=\rM\n=yend size=4",
    'trailing pairs span lines 1' => "=ybegin line=128 size=1 name=x\n=\n=\n=yend size=1",
    'trailing pairs span lines 2' => "=ybegin line=128 size=2 name=x\n==\r=\n=\n=yend size=2",
    'trailing pairs span lines 3' => "=ybegin line=128 size=2 name=x\n=\r\n=\n=\r=\n=yend size=2",
    'unmatched recovered size matches' => "=ybegin line=128 size=1 name=x\n=\n==\n=yend size=1",
    'dangling after pairs' => "=ybegin line=128 size=2 name=x\n====k=\n=yend size=2",
    'false control then truncated' => "=ybeginning line=128 size=3 name=x\n=ybegin line=128 size=9 name=truncated\n".YencArticles::article('ABC')."\nignored suffix",
    'body control prefix' => "=ybegin line=128 size=5 name=x\n=yendx\n=yend size=5",
    'preamble and suffix' => "preamble\r\n".YencArticles::article(YencArticles::randomBytes(4096))."\r\nsuffix",
    'all bytes' => YencArticles::article(YencArticles::bytes()),
    'all bytes lf' => YencArticles::article(YencArticles::bytes(), "\n"),
    'all bytes multipart' => YencArticles::part(YencArticles::bytes(), 'archive.rar', 512, 1, 1, 2),
    'multipart no trailer part' => str_replace('=yend size=3 part=1 ', '=yend size=3 ', YencArticles::part('abc', 'movie.mkv', 6, 1, 1, 2)),
    'leading zero numbers' => "=ybegin line=0128 size=003 name=x\nklm\n=yend size=0003 crc32=a3830348",
    'negative size' => "=ybegin line=128 size=-3 name=x\nklm\n=yend size=-3",
    'max int' => "=ybegin line=9223372036854775807 size=9223372036854775807 name=x\nklm\n=yend size=3",
    'over max int' => "=ybegin line=9223372036854775808 size=9223372036854775808 name=x\nklm\n=yend size=3",
    'second frame ignored' => "=ybegin line=128 size=3 name=first\nklm\n=yend size=3 crc32=a3830348\n=ybegin line=128 size=3 name=second\nnop\n=yend size=3",
    'part line without newline' => "=ybegin part=1 line=128 size=9 name=x\n=ypart begin=1 end=3",
    'ypart uppercase tab' => "=ybegin part=1 line=128 size=9 name=x\n=YPART\tbegin=1\tend=3\nklm\n=yend\tsize=3\tpart=1\tpcrc32=a3830348",
    'yend at end no space' => "=ybegin line=128 size=3 name=x\nklm\n=yend",
    'key with bad characters' => "=ybegin 1line=2 line=128 si.ze=3 size=3 name=x\nklm\n=yend size=3",
    'tab before value' => "=ybegin line=128\tsize=3 name=x\ty\nklm\n=yend size=3",
];

$service = new YencService;
$cases = [];
foreach ($articles as $name => $article) {
    $strict = $article;
    try {
        $decoded = $service->decode($strict);
        $strictResult = ['decoded' => $decoded === false ? null : bin2hex($decoded), 'error' => null];
    } catch (RuntimeException $exception) {
        $strictResult = ['decoded' => null, 'error' => $exception->getMessage()];
    }
    $lenient = $article;
    $service->decodeIgnore($lenient);
    [$withFrame, $part] = $service->decodeWithFrame($article);
    $frame = ArticleFrame::parse($article);
    $metadata = $service->extractMetadata($article);
    $cases[] = [
        'name' => $name,
        'article' => bin2hex($article),
        'frame' => $frame === null ? null : [
            'header' => fieldsHex($frame->header),
            'part' => fieldsHex($frame->part),
            'trailer' => fieldsHex($frame->trailer),
            'payload_offset' => $frame->payloadOffset,
            'payload_length' => $frame->payloadLength,
        ],
        'strict' => $strictResult,
        'lenient' => bin2hex($lenient),
        'with_frame' => bin2hex($withFrame),
        'part' => $part === null ? null : [
            'name' => bin2hex($part->name),
            'size' => $part->size,
            'part' => $part->part,
            'begin' => $part->begin,
            'end' => $part->end,
            'offset' => $part->offset(),
            'length' => $part->length(),
        ],
        'integrity_with_lenient' => $service->hasValidatedIntegrity($article, $withFrame),
        'metadata' => $metadata === null ? null : [
            'name' => bin2hex($metadata['name']),
            'size' => $metadata['size'],
            'line' => $metadata['line'],
            'crc32' => hexOrNull($metadata['crc32']),
        ],
        'native_raw' => $frame === null ? null : nativeRaw($ffi, substr($article, $frame->payloadOffset, $frame->payloadLength)),
        'framing' => framing($ffi, $article),
    ];
}

$encodings = [];
foreach ([
    ['ABC', 'a.bin', 128, true],
    ['', 'empty', 128, true],
    ['', 'empty', 128, false],
    [YencArticles::bytes(), ' spaced name ', 1, true],
    [YencArticles::bytes(), 'binary file', 2, true],
    [YencArticles::bytes(), 'binary file', 3, false],
    [str_repeat(YencArticles::bytes(), 3), 'binary file', 127, true],
    [str_repeat(YencArticles::bytes(), 3), 'binary file', 254, true],
    [str_repeat(YencArticles::bytes(), 3), 'binary file', 300, true],
    ['ABC', "\x00\x0bnul.bin\x00", 128, true],
] as [$data, $filename, $length, $crc]) {
    $encodings[] = [
        'data' => bin2hex($data),
        'filename' => bin2hex($filename),
        'line_length' => $length,
        'include_crc32' => $crc,
        'encoded' => bin2hex($service->encode($data, $filename, $length, $crc)),
    ];
}

echo json_encode(['cases' => $cases, 'encodings' => $encodings], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";

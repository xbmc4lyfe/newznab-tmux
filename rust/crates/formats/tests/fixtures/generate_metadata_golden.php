<?php

// Regenerates metadata-golden.json from the FFI library built at the php-final tag:
//   CARGO_TARGET_DIR=/tmp/ffi cargo build --release --manifest-path "$PHP_REF/docker/app/nzb-yenc-ffi/Cargo.toml"
//   php generate_metadata_golden.php /tmp/ffi/release/libnzbyenc.dylib > metadata-golden.json
// Parser output stays as JSON text, because PHP would round its 64-bit integers.

declare(strict_types=1);

$ffi = FFI::cdef(<<<'C'
    char* nntmux_article_dates_v1_json(const uint8_t* data, size_t length);
    char* nntmux_nntp_header_metadata_json(const uint8_t* data, size_t length);
    char* nntmux_nntp_header_pair_comparison_json(const uint8_t* data, size_t length, int identity_match);
    char* nntmux_nntp_body_measurement_json(const uint8_t* data, size_t length);
    char* nntmux_nntp_lines_body_relationship_json(const uint8_t* data, size_t length);
    char* nntmux_article_identity_v2_json(const uint8_t* data, size_t length, int32_t overview_response_code, int32_t overview_attempted, int32_t head_response_code, int32_t body_response_code);
    char* nntmux_article_identity_xref_v1_json(const uint8_t* data, size_t length, int32_t head_response_code, int32_t overview_response_code, int32_t overview_attempted, int32_t overview_row_count, int32_t overview_format_response_code);
    void nntmux_metadata_string_free(char* value);
    C, $argv[1]);

function call(FFI $ffi, string $function, string $bytes, array $arguments = []): ?string
{
    $buffer = $ffi->new('uint8_t['.max(1, strlen($bytes)).']');
    FFI::memcpy($buffer, $bytes, strlen($bytes));
    $json = $ffi->$function($buffer, strlen($bytes), ...$arguments);
    if ($json === null) {
        return null;
    }
    $text = FFI::string($json);
    $ffi->nntmux_metadata_string_free($json);

    return $text;
}

function field(?string $value): string
{
    return $value === null ? "\xff\xff\xff\xff" : pack('V', strlen($value)).$value;
}

function lines(array $lines): string
{
    return pack('V', count($lines)).implode('', array_map(field(...), $lines));
}

$headers = [
    'empty' => '',
    'ordinary' => "Path: news.example!not-for-mail\r\nFrom: poster <p@example.com>\r\nNewsgroups: alt.binaries.test,alt.binaries.misc\r\nSubject: [1/2] - \"file.rar\" yEnc (1/10)\r\nMessage-ID: <abc@example.com>\r\nDate: Mon, 26 Jun 2014 13:08:22 GMT\r\nLines: 3\r\nBytes: 1200\r\nXref: news.example alt.binaries.test:679871775 alt.binaries.misc:12\r\nInjection-Date: Mon, 26 Jun 2014 13:08:30 +0000\r\n",
    'folded and duplicates' => "Subject: one\r\n two\r\n\tthree\r\nsubject: other\r\nMESSAGE-ID: <a@b>\r\nMessage-ID: <c@d>\r\nDate: bad date\r\nDate: Tue, 27 Jun 2014 01:00:00 -0400\r\nLines: 18446744073709551616\r\nLines: x\r\n",
    'lf only and no colon' => "Subject: x\nnot a header\n: empty name\nName With Space: v\n continuation\nXref: a b:1\n",
    'binary' => "Subject: \xff\xfe\x00raw\r\nMessage-ID: <\xc3\xa9@x>\r\nXref: host group:\xff\r\nDate: \x80\r\n",
    'body after separator' => "Subject: x\r\n\r\nBody: not header\r\n",
    'date forms' => "Date: 26 Jun 2014 13:08:22 GMT\r\nNNTP-Posting-Date: Thu, 01 Jan 1970 00:00:00 UTC\r\nX-Received-Date: Sun, 31 Feb 2021 25:61:61 +9999\r\nInjection-Date: Fri, 13 Nov 2026 12:00:00 Z\r\nExpires: 99 Foo 99\r\n",
    'xref forms' => "Xref: host a.b:1 c.d:18446744073709551615 e:0 f:-1 g h:1:2\r\nNewsgroups: a.b, c.d ,e\r\nXref: other a.b:2\r\n",
    'long value' => 'Subject: '.str_repeat('x', 5000)."\r\nMessage-ID: <".str_repeat('y', 1000)."@z>\r\n",
    'lines match' => "Lines: 2\r\n",
];
$bodies = [
    'empty' => '',
    'two lines' => "abc\r\ndef\r\n",
    'no final newline' => "abc\r\ndef",
    'lf only' => "a\nb\nc\n",
    'mixed' => "a\r\nb\nc\rd\r\n\r\n",
    'long lines' => str_repeat('z', 4000)."\r\n".str_repeat('y', 998)."\r\n",
    'binary' => "\x00\xff\r\n\xfe\n",
];

$out = ['headers' => [], 'dates' => [], 'pairs' => [], 'bodies' => [], 'relationships' => [], 'identities' => [], 'xrefs' => []];
foreach ($headers as $name => $header) {
    $out['headers'][] = ['name' => $name, 'input' => bin2hex($header), 'json' => call($ffi, 'nntmux_nntp_header_metadata_json', $header)];
    $out['dates'][] = ['name' => $name, 'input' => bin2hex($header), 'json' => call($ffi, 'nntmux_article_dates_v1_json', $header)];
    foreach ($headers as $otherName => $other) {
        foreach ([[-1, null], [0, false], [1, true]] as [$code, $identity]) {
            if ($name === 'long value' || $otherName === 'long value') {
                continue;
            }
            $out['pairs'][] = [
                'left' => bin2hex($header), 'right' => bin2hex($other), 'identity' => $identity,
                'json' => call($ffi, 'nntmux_nntp_header_pair_comparison_json', pack('V', strlen($header)).$header.pack('V', strlen($other)).$other, [$code]),
            ];
        }
    }
    foreach ($bodies as $bodyName => $body) {
        $out['relationships'][] = [
            'name' => $name.' / '.$bodyName, 'headers' => bin2hex($header), 'body' => bin2hex($body),
            'json' => call($ffi, 'nntmux_nntp_lines_body_relationship_json', pack('V', strlen($header)).$header.$body),
        ];
    }
}
foreach ($bodies as $name => $body) {
    $out['bodies'][] = ['name' => $name, 'input' => bin2hex($body), 'json' => call($ffi, 'nntmux_nntp_body_measurement_json', $body)];
}

$overviewRows = [
    null,
    "1\tSubject\tFrom\tdate\t<abc@example.com>\t\t100\t2\tXref: host a.b:1",
    "1\tSubject\tFrom\tdate\t<other@example.com>\t\t100\t2",
    'not tabbed',
];
$responses = [
    [null, null, null, null],
    ['0 <abc@example.com> head follows', '0 <abc@example.com> body follows', 221, 222],
    ['0 <other@example.com>', 'no id here', 221, 222],
    ['article missing', null, 430, null],
    ["\xff", "<a@b> <c@d>", 221, 222],
];
foreach (['abc@example.com', '<abc@example.com>', 'bad id', '', "x\xff@y"] as $request) {
    foreach (['ordinary', 'folded and duplicates', 'binary', 'empty'] as $headerName) {
        foreach ($overviewRows as $row) {
            foreach ($responses as [$headText, $bodyText, $headCode, $bodyCode]) {
                foreach ([[null, false], [224, true], [500, true]] as [$overviewCode, $attempted]) {
                    $packed = 'AI02'.field($request).field($headers[$headerName]).field($row).field($headText).field($bodyText);
                    $out['identities'][] = [
                        'request' => bin2hex($request), 'headers' => bin2hex($headers[$headerName]),
                        'overview_row' => $row === null ? null : bin2hex($row),
                        'head_text' => $headText === null ? null : bin2hex($headText),
                        'body_text' => $bodyText === null ? null : bin2hex($bodyText),
                        'overview_code' => $overviewCode, 'attempted' => $attempted, 'head_code' => $headCode, 'body_code' => $bodyCode,
                        'json' => call($ffi, 'nntmux_article_identity_v2_json', $packed, [$overviewCode ?? -1, (int) $attempted, $headCode ?? -1, $bodyCode ?? -1]),
                    ];
                }
            }
        }
    }
}

$format = ['Subject:', 'From:', 'Date:', 'Message-ID:', 'References:', 'Bytes:', 'Lines:', 'Xref:full'];
foreach (['ordinary', 'xref forms', 'folded and duplicates', 'empty'] as $headerName) {
    foreach ([[], ["1\ts\tf\td\t<a@b>\t\t1\t1\tXref: host a.b:1"], ["1\ts\tf\td\t<a@b>\t\t1\t1\tXref: other z.z:9", "2\tshort"]] as $rows) {
        foreach ([[$format, 215], [['Subject:'], 215], [[], 500]] as [$overviewFormat, $formatCode]) {
            foreach ([[221, 224, true], [430, 423, true], [221, null, false]] as [$headCode, $overviewCode, $attempted]) {
                $packed = 'XR01'.field($headers[$headerName]).field(lines($rows)).field(lines($overviewFormat));
                $out['xrefs'][] = [
                    'headers' => bin2hex($headers[$headerName]), 'rows' => array_map(bin2hex(...), $rows),
                    'format' => array_map(bin2hex(...), $overviewFormat), 'head_code' => $headCode, 'overview_code' => $overviewCode,
                    'attempted' => $attempted, 'format_code' => $formatCode,
                    'json' => call($ffi, 'nntmux_article_identity_xref_v1_json', $packed, [$headCode, $overviewCode ?? -1, (int) $attempted, count($rows), $formatCode]),
                ];
            }
        }
    }
}

echo json_encode($out, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";

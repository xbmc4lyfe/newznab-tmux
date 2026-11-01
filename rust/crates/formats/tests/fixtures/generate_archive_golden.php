<?php

// Regenerates archive-golden.json from the FFI library built at the php-final tag:
//   php generate_archive_golden.php "$PHP_REF" /tmp/ffi/release/libnzbyenc.dylib > archive-golden.json
// Records the SHA-256 of each parser's exact JSON for every sample and prefix, so the fixture stays small.

declare(strict_types=1);

[$script, $reference, $library] = $argv;
require_once $reference.'/tests/Fixtures/ArchiveParsingBenchmarkFixtures.php';

use Tests\Fixtures\ArchiveParsingBenchmarkFixtures as Fixtures;

$ffi = FFI::cdef(<<<'C'
    char* nntmux_format_metadata_json(const uint8_t* data, size_t length);
    char* nntmux_par2_metadata_json(const uint8_t* data, size_t length);
    char* nntmux_seven_zip_ranges_json(const uint8_t* data, size_t length);
    char* nntmux_par2_source_ranges_json(const uint8_t* data, size_t length);
    void nntmux_metadata_string_free(char* value);
    C, $library);

function digest(FFI $ffi, string $function, string $bytes): ?string
{
    $buffer = $ffi->new('uint8_t['.max(1, strlen($bytes)).']');
    FFI::memcpy($buffer, $bytes, strlen($bytes));
    $json = $ffi->$function($buffer, strlen($bytes));
    if ($json === null) {
        return null;
    }
    $hash = hash('sha256', FFI::string($json));
    $ffi->nntmux_metadata_string_free($json);

    return $hash;
}

$samples = [];
$files = glob(__DIR__.'/archives/*');
sort($files, SORT_STRING);
foreach ($files as $file) {
    if (basename($file) === 'README.md') {
        continue;
    }
    $bytes = file_get_contents($file);
    $lengths = array_values(array_unique(array_filter([0, 1, 7, 64, 512, 4096, 16384, 65536, strlen($bytes)], static fn (int $length): bool => $length <= strlen($bytes))));
    foreach ($lengths as $length) {
        $prefix = substr($bytes, 0, $length);
        $samples[] = [
            'file' => basename($file),
            'length' => $length,
            'format' => digest($ffi, 'nntmux_format_metadata_json', $prefix),
            'par2' => digest($ffi, 'nntmux_par2_metadata_json', $prefix),
        ];
    }
}

$generated = [];
foreach (Fixtures::strata() as $name => $definition) {
    if ($name === 'public-rename-index') {
        continue;
    }
    $bytes = Fixtures::make($name, $reference)['bytes'];
    $function = ['par2' => 'nntmux_par2_metadata_json', 'formats' => 'nntmux_format_metadata_json',
        'seven-ranges' => 'nntmux_seven_zip_ranges_json', 'par2-ranges' => 'nntmux_par2_source_ranges_json'][$definition['operation']];
    $generated[] = [
        'name' => $name,
        'operation' => $definition['operation'],
        'input_sha256' => hash('sha256', $bytes),
        'bytes' => strlen($bytes) <= 262144 ? bin2hex($bytes) : null,
        'json_sha256' => digest($ffi, $function, $bytes),
    ];
}

echo json_encode(['samples' => $samples, 'generated' => $generated], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";

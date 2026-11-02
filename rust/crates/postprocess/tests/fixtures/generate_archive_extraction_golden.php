<?php
// Regenerates archive_extraction_golden.json from the real PHP class (no app bootstrap). After
// rust/porting/php-ref-setup.sh:
//   php generate_archive_extraction_golden.php <php-final checkout> > archive_extraction_golden.json
// Byte strings are hex.
declare(strict_types=1);

date_default_timezone_set('UTC');
$ref = $argv[1];
require $ref.'/vendor/autoload.php';

use App\Services\AdditionalProcessing\ArchiveExtractionService;
use App\Services\AdditionalProcessing\Config\ProcessingConfiguration;
use App\Services\AdditionalProcessing\State\ReleaseProcessingContext;
use dariusiii\rarinfo\ArchiveInfo;

$service = (new ReflectionClass(ArchiveExtractionService::class))->newInstanceWithoutConstructor();

$nfoNames = [
    'Movie.nfo', 'dir/Movie.NFO', 'file_id.diz', 'FILE_ID.DIZ', 'x.inf', 'readme.txt', 'Read.Me',
    'release.nfo', 'notes.txt', 'other.txt', '00-group.nfo', '0-group_x.txt', '00-.nfo',
    'group-release.name.nfo', 'Group-Release.TXT', '-release.nfo', 'group-.nfo', 'group_x-y.nfo',
    'a.nfo/', 'a.nfo\n', "a.nfo\n", "a-b.txt\n", "readme.txt\n", 'movie.mkv', 'nfo', '', 'dir/',
    "gr\xC3\xB6up-x.nfo", 'grp-rel+x.nfo', 'x.nfo.bak', 'sub/00-g.txt',
];

$sevenZipStart = static function (int $offset, int $size, string $endHeader): string {
    $start = pack('PPV', $offset, $size, crc32($endHeader));

    return "7z\xBC\xAF\x27\x1C\x00\x04".pack('V', crc32($start)).$start;
};
$endHeader = "\x01\x04\x06\x00\x01\x09\x10\x00\x07\x0B\x01\x00";
$full = $sevenZipStart(4, strlen($endHeader), $endHeader)."PACK".$endHeader;
$cut = substr($full, 0, 40);
$badCrc = $sevenZipStart(4, strlen($endHeader), $endHeader.'x');
$huge = "7z\xBC\xAF\x27\x1C\x00\x04\0\0\0\0".pack('PPV', 1 << 41, 10, 0);
$negative = "7z\xBC\xAF\x27\x1C\x00\x04\0\0\0\0".pack('PPV', -1, 10, 0);
$sevenZip = [];
foreach ([
    'whole' => [$full, $full],
    'cut head, full tail' => [$cut, substr($full, -20)],
    'tail too short' => [$cut, substr($endHeader, 1)],
    'bad crc' => [$badCrc."PACK".$endHeader, $endHeader],
    'empty end header' => [$sevenZipStart(0, 0, ''), $endHeader],
    'too big' => [$huge, $endHeader],
    'negative offset' => [$negative, $endHeader],
    'not 7z' => ["Rar!\x1A\x07\x00".str_repeat("\0", 30), $endHeader],
    'short' => [substr($full, 0, 31), $endHeader],
] as $name => [$head, $tail]) {
    $joined = $service->withSevenZipEndHeader($head, $tail);
    $sevenZip[] = [
        'name' => $name,
        'head' => bin2hex($head),
        'tail' => bin2hex($tail),
        'needs_end_header' => $service->needsSevenZipEndHeader($head),
        'joined' => $joined === null ? null : bin2hex($joined),
    ];
}

$pad = static fn (string $start, int $length = 400): string => str_pad($start, $length, "\0");
$ts = str_repeat("\0", 400);
$ts[0] = $ts[188] = $ts[376] = "\x47";
$blobs = [
    'avi' => $pad("RIFF\0\0\0\0AVI LIST"),
    'riff wav' => $pad("RIFF\0\0\0\0WAVEfmt "),
    'mkv' => $pad("\x1A\x45\xDF\xA3"),
    'mpg ps' => $pad("\x00\x00\x01\xBA"),
    'mpg es' => $pad("\x00\x00\x01\xB3"),
    'ts' => $ts,
    'ts short' => substr($ts, 0, 300),
    'mp4' => $pad("\0\0\0\x20ftypisom"),
    'mp4 binary brand' => $pad("\0\0\0\x20ftyp\x01som"),
    'wmv' => $pad("\x30\x26\xB2\x75\x8E\x66\xCF\x11"),
    'flv' => $pad("FLV\x01"),
    'ogg' => $pad('OggS'),
    'too short' => "\x1A\x45\xDF\xA3",
    'rar4' => $pad("Rar!\x1A\x07\x00"),
    'rar5' => $pad("Rar!\x1A\x07\x01\x00"),
    'zip' => $pad("PK\x03\x04"),
    '7z' => $pad("7z\xBC\xAF\x27\x1C"),
    'rar with mkv inside' => "Rar!\x1A\x07\x00".$pad("\x1A\x45\xDF\xA3"),
    'empty' => '',
    'junk' => $pad('hello world'),
];
$signatures = [];
foreach ($blobs as $name => $data) {
    $signatures[] = [
        'name' => $name,
        'data' => bin2hex($data),
        'archive' => ArchiveExtractionService::hasArchiveSignature($data),
        'video' => $service->detectStandaloneVideo($data),
    ];
}

$listings = [
    'plain' => "Path = Movie.2160p.mkv\nSize = 60000000000\nPacked Size = 59000000000\nModified = 2024-03-05 10:11:12.1234567\nAttributes = A\nCRC = 0A1B2C3D\nEncrypted = -\nMethod = Copy\n\nPath = sub\nFolder = +\nSize = 0\n\nPath = sub/x.nfo\nSize = 12\nAttributes = A -rw-r--r--\nCRC = FFFFFFFF\nEncrypted = +\nMethod = LZMA2:24\n",
    'directory by attribute' => "Path = d\nSize = 0\nAttributes = D\n\nPath = f\nSize = 1\n",
    'crlf and no trailing newline' => "Path = a.mkv\r\nSize = 5\r\nModified = 2020-01-02 03:04\r\nMethod =",
    'empty value' => "Path = a\nSize = 7\nCRC =\nModified =\n",
    'utf8 c1 inside character' => "Path = \xC3\x85\xE4\xB9\x85.mkv\nSize = 2\n",
    'c1 control' => "Path = a\xC2\x85b\nSize = 1\n\nPath = ok\nSize = 1\n",
    'line break in name' => "Path = first\nline.mkv\nSize = 1\n\nPath = ok\nSize = 3\n",
    'duplicate key' => "Path = a\nPath = b\nSize = 1\n\nPath = c\nSize = 2\n",
    'bad key' => "Path = a\n1Bad = x\nSize = 1\n",
    'no size' => "Path = a\n\nPath = b\nSize = 2\n",
    'no path' => "Size = 2\n",
    'blank lines only' => "\n\n \t\n",
    'odd size' => "Path = a\nSize =  12abc\n\nPath = b\nSize = -3\n\nPath = c\nSize = x\n",
    'long crc' => "Path = a\nSize = 1\nCRC = 123456789\n",
    'lower crc' => "Path = a\nSize = 1\nCRC = 00abcdef\n",
    'date only' => "Path = a\nSize = 1\nModified = 2021-06-07\n",
    'bad date' => "Path = a\nSize = 1\nModified = soon\n",
    'separator in value' => "Path = a = b\nSize = 1\n",
    'key with punctuation' => "Path = a\nSize = 1\nPacked Size (x)._-1 = 3\n",
];
$sevenZipListings = [];
foreach ($listings as $name => $output) {
    $files = ArchiveExtractionService::parseSevenZipListing($output, $reliable);
    $sevenZipListings[] = [
        'name' => $name,
        'output' => bin2hex($output),
        'reliable' => $reliable,
        'files' => array_map(static fn (array $file): array => ['name' => bin2hex($file['name'])] + $file, $files),
    ];
}

$entries = [
    ['name' => 'stored.nfo', 'size' => 10, 'range' => '100-109', 'compressed' => 0, 'pass' => 0, 'source' => 'main'],
    ['name' => 'missing-source.jpg', 'size' => 3, 'range' => '5-7', 'compressed' => 0],
    ['name' => 'cut.jpg', 'size' => 10, 'range' => '100-105', 'compressed' => 0],
    ['name' => 'packed.nfo', 'size' => 10, 'range' => '100-109', 'compressed' => 1],
    ['name' => 'unknown-method.nfo', 'size' => 10, 'range' => '100-109'],
    ['name' => 'locked.nfo', 'size' => 10, 'range' => '100-109', 'compressed' => 0, 'pass' => 1],
    ['name' => 'dir', 'size' => 10, 'range' => '100-109', 'compressed' => 0, 'is_dir' => true],
    ['name' => 'nested.nfo', 'size' => 10, 'range' => '100-109', 'compressed' => 0, 'source' => 'main > inner.rar'],
    ['name' => 'empty.txt', 'size' => 0, 'range' => '100-99', 'compressed' => 0],
    ['name' => 'no-range.nfo', 'size' => 10, 'compressed' => 0],
    ['name' => 'stored.nfo', 'size' => 4, 'range' => '0-3', 'compressed' => 0],
];

// The whole service over every fixture archive, reading stored files itself with no clients.
$config = (new ReflectionClass(ProcessingConfiguration::class))->newInstanceWithoutConstructor();
// Readonly, so set from inside the class.
(Closure::bind(function (): void {
    $this->extractUsingRarInfo = true;
    $this->unrarPath = false;
    $this->unzipPath = false;
    $this->sevenZipPath = false;
    $this->timeoutPath = false;
    $this->timeoutSeconds = 0;
    $this->debugMode = false;
}, $config, ProcessingConfiguration::class))();
$typeNames = [0 => 'None', ArchiveInfo::TYPE_RAR => 'Rar', ArchiveInfo::TYPE_ZIP => 'Zip', ArchiveInfo::TYPE_SRR => 'Srr', ArchiveInfo::TYPE_SFV => 'Sfv', ArchiveInfo::TYPE_PAR2 => 'Par2', ArchiveInfo::TYPE_SZIP => 'Szip'];
$inspections = [];
$archives = glob(__DIR__.'/archives/*');
sort($archives);
$inputs = [];
foreach ($archives as $path) {
    $data = file_get_contents($path);
    $inputs[basename($path)] = $data;
    $inputs[basename($path).' (first 3000 bytes)'] = substr($data, 0, 3000);
}
$inputs['inline mkv'] = $blobs['mkv'];
foreach ($inputs as $name => $data) {
    if ($data === '') {
        continue;
    }
    $context = (new ReflectionClass(ReleaseProcessingContext::class))->newInstanceWithoutConstructor();
    $extractor = new ArchiveExtractionService($config);
    try {
        $result = $extractor->processCompressedData($data, $context, sys_get_temp_dir().'/');
    } catch (Throwable $e) {
        $inspections[] = ['name' => $name, 'throws' => true];
        continue;
    }
    $names = [];
    foreach ($result['files'] as $file) {
        if (is_array($file) && isset($file['name'])) {
            $names[] = bin2hex((string) $file['name']);
        }
    }
    $extracted = [];
    try {
        foreach ((new ArchiveExtractionService($config))->extractSpecificFiles($data, array_map('hex2bin', $names), sys_get_temp_dir().'/') as $file => $bytes) {
            $extracted[bin2hex((string) $file)] = md5($bytes);
        }
        ksort($extracted);
    } catch (Throwable $e) {
        $extracted = null;
    }
    $inspections[] = [
        'name' => $name,
        'throws' => false,
        'success' => $result['success'],
        'has_password' => $result['hasPassword'],
        'password_status' => $result['passwordStatus'],
        'listing_only' => $result['listingOnly'] ?? false,
        'undecodable' => $result['undecodable'] ?? false,
        'video' => $result['standaloneVideoType'] ?? null,
        // Read with empty(), so '' and no marker are the same.
        'marker' => ($result['archiveMarker'] ?? '') === '' ? null : $result['archiveMarker'],
        'kind' => isset($result['dataSummary']['main_type']) ? $typeNames[$result['dataSummary']['main_type']] : null,
        'is_volume' => ! empty($result['dataSummary']['is_volume']),
        'rar_listing_complete' => $result['rarListingComplete'] ?? null,
        'entries' => count($result['files']),
        'names' => $names,
        'extracted' => $extracted === null ? null : (object) $extracted,
        'checked' => $context->compressedFilesChecked,
        'retryable' => $context->archiveRetryable,
        'flag_undecodable' => $context->archiveListingUndecodable,
    ];
}

echo json_encode([
    'inspections' => $inspections,
    'nfo' => array_map(static fn (string $name): array => [bin2hex($name), $service->isNfoFile($name)], $nfoNames),
    'seven_zip' => $sevenZip,
    'signatures' => $signatures,
    'listings' => $sevenZipListings,
    'stored_entries' => [
        'input' => $entries,
        'output' => ArchiveExtractionService::completeStoredEntries($entries),
    ],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";

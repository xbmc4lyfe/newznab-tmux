<?php
// Regenerates release_file_evidence_golden.json from the real PHP classes (no app bootstrap).
// After rust/porting/php-ref-setup.sh:
//   php generate_release_file_evidence_golden.php <php-final checkout> > release_file_evidence_golden.json
declare(strict_types=1);

$ref = $argv[1];
require $ref.'/vendor/autoload.php';

use App\Services\AdditionalProcessing\ReleaseFileManager;
use App\Services\NameFixing\FileNameCleaner;

$manager = (new ReflectionClass(ReleaseFileManager::class))->newInstanceWithoutConstructor();
$cleaner = new FileNameCleaner;
(new ReflectionProperty(ReleaseFileManager::class, 'fileNameCleaner'))->setValue($manager, $cleaner);
$method = static fn (string $name): Closure => (new ReflectionMethod(ReleaseFileManager::class, $name))->getClosure($manager);
$normalize = $method('normalizeNfoEncoding');
$extract = $method('extractReleaseNameFromFile');

$filenames = [
    'Movie.2160p.nfo', 'MOVIE.NFO', 'movie.diz', 'movie.info', 'movie.inf', 'movie.nfo.bak',
    'file_id.diz', 'FILE-ID.DIZ', 'readme.txt', 'release.txt', 'information.txt', 'notes.diz',
    'note.txt', 'about.txt', '00-group.nfo', '000-group.nfo', 'grp-movie.2160p.nfo', 'info.txt',
    'movie.info.txt', 'dir/sub/00-group.nfo', 'dir/readme.txt', 'readme.md', 'movie.mkv',
    'movie.txt', 'group release.nfo', "readme.txt\n", "movie.nfo\n", 'a.b/info.txt', '',
];
$names = [];
foreach ($filenames as $filename) {
    $names[] = ['name' => $filename, 'nfo' => $manager->isNfoFilename($filename)];
}

$scene = "  Release Date ....: 2021-05-01\r\n  Source ..........: UHD BluRay\r\n";
$encodings = [
    'ascii' => $scene,
    'utf8' => "Fran\xc3\xa7ais ".$scene,
    'utf8 bom' => "\xef\xbb\xbf".$scene,
    'utf16le bom' => "\xff\xfe".mb_convert_encoding($scene, 'UTF-16LE', 'UTF-8'),
    'utf16be bom' => "\xfe\xff".mb_convert_encoding("Fran\xc3\xa7ais ".$scene, 'UTF-16BE', 'UTF-8'),
    'utf16le odd' => "\xff\xfeA\x00B",
    'utf16le lone surrogate' => "\xff\xfeA\x00\x00\xd8B\x00",
    'utf16le pair' => "\xff\xfe\x3d\xd8\x00\xdeA\x00",
    'utf16 bom only' => "\xff\xfe",
    'utf8 bom then utf16' => "\xef\xbb\xbf\xff\xfeA\x00",
    'cp437 art' => "\xdb\xdb\xb2\xb1\xb0 GROUP \xb0\xb1\xb2\xdb\xdb\r\n\xc9\xcd\xcd\xbb\r\n\xba x \xba\r\n\xc8\xcd\xcd\xbc",
    'cp437 all high' => implode('', array_map('chr', range(128, 255))),
    'cp437 low controls' => "\x01\x02\x1a\x7f".implode('', array_map('chr', range(128, 140))),
    'latin1' => "Fran\xe7ais",
    'overlong' => "\xc0\xafx",
    'surrogate utf8' => "\xed\xa0\x80x",
    'empty' => '',
];
$normalized = [];
foreach ($encodings as $label => $data) {
    $normalized[] = ['name' => $label, 'data' => bin2hex($data), 'normalized' => bin2hex($normalize($data))];
}

$inner = [
    'Some.Movie.2021.2160p.UHD.BluRay.x265-GROUP.mkv',
    'some.movie.2021.2160p.uhd.bluray.x265-group.mkv',
    'dir/Show.Name.S01E01.1080p.WEB-DL.DDP5.1.H.264-GRP.mkv',
    'Show_Name_S01E01_1080p_WEB-DL-GRP.mp4',
    'the show name s01e01 1080p web-dl-grp.mkv',
    'NBA__NZBSPLIT__bdab31d6f79989608009e7e8eadcbe66__NZBSPLIT__NBA_20260419_PHI_BOS_1080p60_ABC.7z.073',
    'TEST__NZBSPLIT__1234567890abcdef__NZBSPLIT__setup.7z.001',
    'abcdef0123456789.mkv',
    'movie.mkv',
    'movie',
    'a.b',
    'Movie.2021.mkv.mkv',
    'Movie.2021.2160p.part01.rar',
    'Movie.2021.2160p.r00',
    'Movie (2021) [2160p].mkv',
    'Movie.2021.2160p-GRP',
    "Movie.2021.2160p-GRP\n",
    "Movie.2021.2160p-GRP.mkv\n",
    'über.film.2021.2160p-grp.mkv',
    'Movie 2021 2160p x',
    'Movie - 2021 - x',
    '00001.m2ts',
    'BDMV/STREAM/00001.m2ts',
    'VIDEO_TS/VTS_01_1.VOB',
    'Movie.2021.2160p.BluRay.REMUX.HEVC.DTS-HD.MA.TrueHD.7.1.Atmos-FGT.iso',
    '',
];
$extractions = [];
foreach ($inner as $name) {
    $extracted = $extract($name);
    $candidate = $extracted === null ? null : $cleaner->normalizeCandidateTitle($extracted);
    $extractions[] = [
        'name' => $name,
        'extracted' => $extracted,
        'candidate' => $candidate,
        'plausible' => $candidate === null ? null : $cleaner->isPlausibleReleaseTitle($candidate),
    ];
}

echo json_encode([
    'nfo_filenames' => $names,
    'encodings' => $normalized,
    'extractions' => $extractions,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR), "\n";

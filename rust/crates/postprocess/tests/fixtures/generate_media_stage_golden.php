<?php
// Regenerates media_stage_golden.json from the real PHP classes (no app bootstrap). After
// rust/porting/php-ref-setup.sh and `python3 generate_stored_rar.py`:
//   php generate_media_stage_golden.php <php-final checkout> > media_stage_golden.json
// Byte strings are hex; long ones are given as their length and SHA-1.
declare(strict_types=1);

$ref = $argv[1];
require $ref.'/vendor/autoload.php';

use App\Services\AdditionalProcessing\Config\ProcessingConfiguration;
use App\Services\AdditionalProcessing\DTO\ArchiveCandidate;
use App\Services\AdditionalProcessing\DTO\ArchivedMediaEntry;
use App\Services\AdditionalProcessing\Enums\VideoContainer;
use App\Services\AdditionalProcessing\Media\ArchivedMediaSlicer;
use App\Services\AdditionalProcessing\Media\ContainerSplicer;
use App\Services\AdditionalProcessing\State\ReleaseProcessingContext;
use dariusiii\rarinfo\ArchiveInfo;

set_error_handler(static function (int $level, string $message, string $file, int $line): bool {
    if (($level & (E_DEPRECATED | E_USER_DEPRECATED)) !== 0 || (error_reporting() & $level) === 0) {
        return true;
    }
    throw new ErrorException($message, 0, $level, $file, $line);
});

$config = static function (array $values): ProcessingConfiguration {
    $config = (new ReflectionClass(ProcessingConfiguration::class))->newInstanceWithoutConstructor();
    (function () use ($values): void {
        foreach ($values as $key => $value) {
            $this->{$key} = $value;
        }
    })->call($config);

    return $config;
};
$context = static fn (): ReleaseProcessingContext => (new ReflectionClass(ReleaseProcessingContext::class))->newInstanceWithoutConstructor();
$digest = static fn (?string $bytes): ?array => $bytes === null ? null : ['len' => strlen($bytes), 'sha256' => hash('sha256', $bytes)];
$entry = static fn (ArchivedMediaEntry $entry): array => [
    'name' => bin2hex($entry->name),
    'real_size' => $entry->realSize,
    'source_index' => $entry->sourceIndex,
    'head' => $digest($entry->headBytes),
    'range_start' => $entry->rangeStart,
    'in_volume_end' => $entry->inVolumeEnd,
    'volume_bytes_downloaded' => $entry->volumeBytesDownloaded,
    'volume_segments_downloaded' => $entry->volumeSegmentsDownloaded,
    'is_sample' => $entry->isSample,
    'container' => $entry->container?->value,
    'complete' => $entry->isComplete(),
    'continuation' => array_map($entry->continuationBytes(...), [1, 20000, 65536, 1048576]),
];

$stored = __DIR__.'/media/stored';
$volumes = [];
foreach (['movie.part1.rar', 'movie.part2.rar', 'movie.part3.rar'] as $name) {
    $volumes[$name] = file_get_contents($stored.'/'.$name);
}
$cuts = [
    'part1' => $volumes['movie.part1.rar'],
    'part1 cut in the feature' => substr($volumes['movie.part1.rar'], 0, 60000),
    'part1 cut in the sample' => substr($volumes['movie.part1.rar'], 0, 20000),
    'part2' => $volumes['movie.part2.rar'],
    'part3' => $volumes['movie.part3.rar'],
    'garbage' => str_repeat('junk', 100),
];
$settings = [
    'defaults' => ['mediaArchiveSlicing' => true, 'processThumbnails' => true, 'mediaSampleMaxBytes' => 8388608, 'mediaMainVideoMinBytes' => 104857600],
    'small' => ['mediaArchiveSlicing' => true, 'processThumbnails' => true, 'mediaSampleMaxBytes' => 20000, 'mediaMainVideoMinBytes' => 30000],
    'slicing off' => ['mediaArchiveSlicing' => false, 'processThumbnails' => true, 'mediaSampleMaxBytes' => 8388608, 'mediaMainVideoMinBytes' => 0],
    'thumbnails off' => ['mediaArchiveSlicing' => true, 'processThumbnails' => false, 'mediaSampleMaxBytes' => 8388608, 'mediaMainVideoMinBytes' => 0],
];

// Registering each volume's stored videos.
$registers = [];
foreach ($cuts as $cut => $data) {
    $archive = new ArchiveInfo;
    $archive->setData($data, true);
    $files = $archive->error ? [] : $archive->getArchiveFileList();
    foreach ($settings as $setting => $values) {
        $state = $context();
        $source = new ArchiveCandidate('"movie.part1.rar" yEnc (1/3)', ['a@x', 'b@x', 'c@x'], true, 4);
        (new ArchivedMediaSlicer($config($values)))->register($state, $data, is_array($files) ? $files : [], $source);
        $registers[] = [
            'volume' => $cut,
            'settings' => $setting,
            'values' => $values,
            'entries' => array_map($entry, $state->archivedMedia),
        ];
    }
}

// Chunks of a stored video read from a later volume.
$slicer = new ArchivedMediaSlicer($config($settings['defaults']));
$chunks = [];
foreach ($cuts as $cut => $data) {
    foreach (['Movie.2023.1080p.BluRay.x264-GRP.mkv', 'other\\dir/Movie.2023.1080p.BluRay.x264-GRP.mkv', 'Sample/movie-sample.mkv', 'movie-sample.mkv', 'Extras/packed.mkv', 'Movie.2023.1080p.BluRay.x264-GRP.nfo', 'missing.mkv'] as $name) {
        $chunks[] = ['volume' => $cut, 'name' => bin2hex($name), 'chunk' => $digest($slicer->chunkFromVolume($data, $name))];
    }
}

// Keeping at most two, a sample replacing a feature, samples first.
$make = static fn (string $name, bool $sample): ArchivedMediaEntry => new ArchivedMediaEntry($name, 10, 0, 'h', 0, 9, 1, 1, $sample, null);
$sequences = [
    [['feature1', false], ['sample1', true], ['feature2', false], ['sample2', true], ['sample3', true], ['sample1', true]],
    [['sample1', true], ['sample2', true], ['feature1', false]],
    [['feature1', false], ['feature2', false], ['sample1', true], ['feature1', false]],
];
$remembered = [];
foreach ($sequences as $sequence) {
    $state = $context();
    $steps = [];
    foreach ($sequence as [$name, $sample]) {
        $steps[] = [
            'name' => $name,
            'sample' => $sample,
            'kept' => $state->rememberArchivedMedia($make($name, $sample)),
            'after' => array_map(static fn (ArchivedMediaEntry $known): string => $known->name, $state->archivedMedia),
        ];
    }
    $remembered[] = $steps;
}

// Splicing a chunk from the middle onto the file's headers.
$cluster = "\x1F\x43\xB6\x75";
$ts = static fn (int $offset): string => str_repeat('y', $offset).str_repeat("\x47".str_repeat("\x11", 187), 5);
$splices = [
    ['matroska', "\x1A\x45\xDF\xA3header".$cluster.'first', 'junk'.$cluster.'later'],
    ['matroska', "\x1A\x45\xDF\xA3header", 'junk'.$cluster.'later'],
    ['matroska', "\x1A\x45\xDF\xA3".$cluster, 'no cluster'],
    ['mpegts', 'head', $ts(7)],
    ['mpegts', 'head', str_repeat('z', 900)],
    ['mpeg', 'head', "zz\x00\x00\x01\xBApack"],
    ['mpeg', 'head', 'no pack'],
    ['avi', 'RIFF....AVI LIST....hdrlLIST....movi00dc', 'xx01wbdata'],
    ['avi', 'RIFF....AVI LIST....hdrlLIST....movi', 'x9dc 12db'],
    ['avi', 'movi at the start', '00dc'],
    ['avi', 'RIFF....AVI XXXX....movi', '00dc'],
    ['avi', 'RIFF....AVI LIST....movi', 'no chunk ab01'],
    ['mov', 'head', 'mid'],
    ['asf', 'head', 'mid'],
    ['ogg', 'head', 'mid'],
];
$splicer = new ContainerSplicer;
$spliced = [];
foreach ($splices as [$container, $head, $mid]) {
    $result = $splicer->splice($head, $mid, VideoContainer::from($container));
    $spliced[] = ['container' => $container, 'head' => bin2hex($head), 'mid' => bin2hex($mid), 'result' => $result === null ? null : bin2hex($result)];
}

echo json_encode([
    'registers' => $registers,
    'chunks' => $chunks,
    'remembered' => $remembered,
    'splices' => $spliced,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";

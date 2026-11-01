<?php
// Regenerates planner_golden.json from the real PHP classes. After rust/porting/php-ref-setup.sh:
//   php generate_planner_golden.php <php-final checkout> > planner_golden.json
declare(strict_types=1);

$ref = $argv[1];
require $ref.'/vendor/autoload.php';
$app = require $ref.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\AdditionalProcessing\AdditionalWorkPlanner;
use App\Services\AdditionalProcessing\Config\ProcessingConfiguration;
use App\Services\AdditionalProcessing\DTO\ArchiveCandidate;
use App\Services\AdditionalProcessing\Media\ImageCandidateRanker;

$file = static fn (string $title, int $segments = 3, int $size = 2000000, array $extra = []): array => [
    'title' => $title,
    'segments' => array_map(static fn (int $n): string => md5($title).$n.'@x', $segments > 0 ? range(1, $segments) : []),
    'size' => $size,
    'filecount' => 1,
    'firstsegment' => true,
    ...$extra,
];

$releases = [
    'rar set' => [$file('"Movie.2020.part01.rar" yEnc (1/50)'), $file('"Movie.2020.part02.rar" yEnc (1/50)'), $file('"Movie.2020.nfo" yEnc (1/1)', 1, 3000), $file('"Movie.2020.par2" yEnc (1/1)', 1, 5000), $file('"Movie.2020.vol00+01.par2" yEnc (1/2)', 2, 50000)],
    'old rar' => [$file('Movie.r00 yEnc (1/50)'), $file('Movie.rar yEnc (1/50)'), $file('Movie.r01 yEnc (1/50)')],
    'old rar missing header' => [$file('"Show.rar" yEnc (2/50)', 3, 1000, ['firstsegment' => false]), $file('"Show.r00" yEnc (1/50)'), $file('"Show.r01" yEnc (1/50)')],
    'part missing header' => [$file('"Show.part01.rar" yEnc', 3, 1000, ['firstsegment' => false]), $file('"Show.part02.rar" yEnc', 3, 1000, ['firstsegment' => false]), $file('"Show.part03.rar" yEnc')],
    '7z volumes' => [$file('"Pack.7z.001" yEnc (1/9)'), $file('"Pack.7z.002" yEnc (1/9)', 5), $file('"Pack.7z.003" yEnc (1/9)', 4)],
    'single 7z' => [$file('[01/02] - Pack.7z yEnc (1/9)', 5)],
    'one segment 7z' => [$file('"Lone.7z" yEnc (1/1)', 1)],
    'numbered split' => [$file('"Data.bin.001" yEnc'), $file('"Data.bin.002" yEnc', 2), $file('Other.003 yEnc (1/2)', 2)],
    'media split' => [$file('"Film.mkv.001" yEnc (1/9)', 4), $file('"Film.mkv.002" yEnc (1/9)', 4), $file('[02/02] - Film.mp4.001 yEnc')],
    'hashed' => [$file('"0123456789abcdef0123456789abcdef.101" yEnc (1/50)'), $file('"0123456789abcdef0123456789abcdef.102" yEnc (2/50)')],
    'zip' => [$file('"Thing.zip" yEnc (1/5)'), $file('"Thing.z01" yEnc (1/5)'), $file('"Thing.zipr01" yEnc')],
    'sample' => [$file('"movie-sample.mkv" yEnc (1/5)', 5), $file('"Sample.jpg" yEnc (1/1)', 1, 20000), $file('"movie.sample.rar" yEnc (1/2)', 2)],
    'sample archive only' => [$file('"sample.part01.rar" yEnc (1/2)', 2)],
    'media and audio' => [$file('"Sample.avi" yEnc (1/4)', 4), $file('"Track01.flac" yEnc (1/4)', 4), $file('"Track02.mp3" yEnc (1/4)', 4)],
    'audio no delimiter' => [$file('Track01.flac', 2), $file('Track02.MP3 yEnc', 2)],
    'images' => [$file('"cover.jpg" yEnc', 1, 30000), $file('"screens.png" yEnc', 2, 900000), $file('"cd1.jpg" yEnc', 1, 40000), $file('"icon.png" yEnc', 1, 100), $file('"huge.webp" yEnc', 9, 99000000), $file('"poster.jpeg" yEnc', 1, 50000), $file('"contact sheet.webp" yEnc', 1, 60000), $file('"nosize.jpg" yEnc', 40, 0)],
    'images in a music group' => [$file('"cover.jpg" yEnc', 1, 30000)],
    'books' => array_map(static fn (int $n): array => $file('"Book'.$n.' epub.rar" yEnc', 1, 1000), range(1, 90)),
    'few books' => [$file('some epub file.rar'), $file('"mobi title.rar" yEnc (1/1) and a long tail after it'), $file('"other.txt"')],
    'unnamed' => [$file('"abcdefgh" yEnc (1/9)', 5, 900), $file('"ijklmnop" yEnc (1/9)', 5, 5000), $file('"qrstuvwx" yEnc (1/9)', 1, 300), $file('"yzabcdef" yEnc (1/9)', 2, 70000), $file('"ghijkl" yEnc', 3, 1)],
    'lone unnamed' => [$file('"abcdefghijkl" yEnc (1/9)', 5, 9000)],
    'merged unnamed' => [$file('"abcdefgh" yEnc (1/9)', 5, 9000, ['filecount' => 2]), $file('"ijklmnop" yEnc (1/9)', 5, 9000)],
    'nothing' => [$file('"readme.txt" yEnc (1/1)', 1, 100)],
    'no segments' => [$file('"Movie.part01.rar" yEnc', 0), $file('"Sample.mkv" yEnc', 0), $file('"pic.jpg" yEnc', 0, 50000)],
    'par2 indexes' => [$file('"a.par2" yEnc', 2, 9000), $file('"a.vol01+02.par2" yEnc', 3, 100), $file('"b.par2" yEnc', 4, 5000), $file('"b.par2" yEnc', 4, 1000, ['filecount' => 2])],
    'main videos' => [$file('"Big.mkv" yEnc', 3, 700000000), $file('"Bigger.mp4"', 3, 900000000), $file('"Biggest.ts" yEnc', 3, 999000000), $file('"small.avi" yEnc', 3, 100000), $file('"big-sample.mkv" yEnc', 3, 800000000), $file('"Big.rar" yEnc', 3, 800000000)],
    'duplicates' => [$file('"a.rar" yEnc', 3), ['title' => '"b.rar" yEnc', 'segments' => ['s1', 's1', 's2', ''], 'size' => 1, 'filecount' => 1, 'firstsegment' => true], ['title' => '"c.rar" yEnc', 'segments' => ['s2', 's3'], 'size' => 1, 'filecount' => 1, 'firstsegment' => true]],
    'support names' => [$file('"x.srs" yEnc', 1), $file('"x.sfv" yEnc', 1), $file('"x.nzb" yEnc', 1), $file('"sample.nfo" yEnc', 1), $file('x.inf'), $file('sample.ofn yEnc'), $file('"sample.sfv" and then a very long tail of text.mkv" yEnc')],
];

$config = static function (array $overrides) use ($app): ProcessingConfiguration {
    $base = new ProcessingConfiguration;
    $clone = (new ReflectionClass($base))->newInstanceWithoutConstructor();
    foreach ((new ReflectionClass($base))->getProperties() as $property) {
        $name = $property->getName();
        $value = array_key_exists($name, $overrides) ? $overrides[$name] : ($property->isInitialized($base) ? $property->getValue($base) : null);
        (function () use ($name, $value) { $this->{$name} = $value; })->call($clone);
    }

    return $clone;
};

$profiles = [
    'all' => ['processThumbnails' => true, 'processJPGSample' => true, 'processMediaInfo' => true, 'processAudioInfo' => true, 'segmentsToDownload' => 2, 'maximumRarSegments' => 3, 'archiveProbeFiles' => 3, 'par2MediaMapping' => true, 'par2IndexMaxSegments' => 2, 'mediaMainVideoFallback' => true, 'mediaMainVideoMinBytes' => 104857600, 'mediaImageMaxBytes' => 8388608],
    'none' => ['processThumbnails' => false, 'processJPGSample' => false, 'processMediaInfo' => false, 'processAudioInfo' => false, 'segmentsToDownload' => 1, 'maximumRarSegments' => 1, 'archiveProbeFiles' => 0, 'par2MediaMapping' => false, 'par2IndexMaxSegments' => 1, 'mediaMainVideoFallback' => false, 'mediaMainVideoMinBytes' => 0, 'mediaImageMaxBytes' => 1048576],
];

$candidate = static fn (ArchiveCandidate $c): array => [
    'title' => $c->title, 'message_ids' => $c->messageIds, 'likely_first_volume' => $c->likelyFirstVolume, 'source_index' => $c->sourceIndex,
    'tail_message_ids' => $c->tailMessageIds, 'has_first_segment' => $c->hasFirstSegment, 'set_key' => $c->setKey,
];

$cases = [];
foreach ($profiles as $profile => $overrides) {
    $planner = new AdditionalWorkPlanner($config($overrides));
    foreach ($releases as $name => $nzb) {
        $group = $name === 'images in a music group' ? 'alt.binaries.sounds.flac' : 'alt.binaries.test';
        $plan = $planner->plan($nzb, $group);
        $cases[] = [
            'name' => $name, 'profile' => $profile, 'group' => $group,
            'config' => [
                'process_thumbnails' => $overrides['processThumbnails'], 'process_jpg_sample' => $overrides['processJPGSample'],
                'process_media_info' => $overrides['processMediaInfo'], 'process_audio_info' => $overrides['processAudioInfo'],
                'segments_to_download' => $overrides['segmentsToDownload'], 'maximum_rar_segments' => $overrides['maximumRarSegments'],
                'archive_probe_files' => $overrides['archiveProbeFiles'], 'par2_media_mapping' => $overrides['par2MediaMapping'],
                'par2_index_max_segments' => $overrides['par2IndexMaxSegments'], 'media_main_video_fallback' => $overrides['mediaMainVideoFallback'],
                'media_main_video_min_bytes' => $overrides['mediaMainVideoMinBytes'], 'media_image_max_bytes' => $overrides['mediaImageMaxBytes'],
            ],
            'nzb' => $nzb,
            'plan' => [
                'sample_message_ids' => $plan->sampleMessageIds, 'jpg_message_ids' => $plan->jpgMessageIds,
                'media_info_message_id' => $plan->mediaInfoMessageId, 'audio_info_message_id' => $plan->audioInfoMessageId,
                'audio_info_extension' => $plan->audioInfoExtension, 'archive_candidates' => array_map($candidate, $plan->archiveCandidates),
                'book_file_count' => $plan->bookFileCount, 'book_flood' => $plan->bookFlood,
                'duplicate_message_id_count' => $plan->duplicateMessageIdCount, 'unsupported_reasons' => $plan->unsupportedReasons,
                'probe_candidates' => array_map($candidate, $plan->probeCandidates), 'probe_is_only_file' => $plan->probeIsOnlyFile,
                'par2_index_candidates' => array_map($candidate, $plan->par2IndexCandidates), 'main_video_source_indexes' => $plan->mainVideoSourceIndexes,
                'image_candidates' => array_map(static fn ($i): array => ['title' => $i->title, 'message_ids' => $i->messageIds, 'source_index' => $i->sourceIndex, 'posted_size' => $i->postedSize], $plan->imageCandidates),
            ],
        ];
    }
}

$names = ['"Movie.part01.rar" yEnc (1/50)', 'Movie.part01.rar yEnc (1/50)', '[01/10] - Movie.mkv.001 yEnc', '- First Movie.rar - yEnc', 'Poster [01/10] - x.r00 yEnc (1/2)', '(1 of 5) a.7z.001', 'MOVIE.ZIP', 'a.zipr01', 'a.z01', '0123456789ABCDEF0123456789abcdef.101', 'a.123', 'plain name', 'a.7z', '"  Spaced  "', 'yEnc only', 'name.rar - - [1/2]'];
$sets = [];
foreach ($names as $subject) {
    $sets[] = ['subject' => $subject, 'file_name' => ArchiveCandidate::fileName($subject), 'set_key' => ArchiveCandidate::volumeSetKey($subject)];
}
$ranks = [];
foreach (['CD1.screens.jpg', 'cover.back.jpg', 'cover.jpg', 'dsc0001.jpg', '"my poster.png" yEnc', 'a\\b\\thumb.webp', 'path/to/label.jpg', 'scene-sample.jpg', 'disc2.png', 'sig.jpg', 'design.jpg', 'folder.jpeg', 'screenshots/x.jpg'] as $name) {
    $ranks[] = ['name' => $name, 'rank' => ImageCandidateRanker::rank($name)];
}

echo json_encode(['cases' => $cases, 'sets' => $sets, 'ranks' => $ranks], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";

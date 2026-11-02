<?php
// Regenerates media_golden.json from the real PHP classes (no app bootstrap). After
// rust/porting/php-ref-setup.sh, with ffmpeg and ffprobe on PATH:
//   php generate_media_golden.php <php-final checkout> > media_golden.json
// Byte strings are hex. Image fixtures live in media/.
declare(strict_types=1);

date_default_timezone_set('UTC');
$ref = $argv[1];
require $ref.'/vendor/autoload.php';

use App\Services\AdditionalProcessing\Config\ProcessingConfiguration;
use App\Services\AdditionalProcessing\DTO\MediaSourceHints;
use App\Services\AdditionalProcessing\Enums\VideoContainer;
use App\Services\AdditionalProcessing\Media\FfmpegFrameGrabber;
use App\Services\AdditionalProcessing\Media\FrameQualityScorer;
use App\Services\AdditionalProcessing\Media\ImageCandidateRanker;
use App\Services\AdditionalProcessing\Media\ImageInspector;
use App\Services\AdditionalProcessing\Media\MediaToolFactory;
use App\Services\AdditionalProcessing\Media\ThumbnailFrameSelector;
use App\Services\AdditionalProcessing\Media\VideoFrameGrabber;
use App\Services\AdditionalProcessing\MediaExtractionService;
use Illuminate\Container\Container;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Facade;

// Laravel's handler: a warning or notice the call didn't silence is an exception.
set_error_handler(static function (int $level, string $message, string $file, int $line): bool {
    if (($level & (E_DEPRECATED | E_USER_DEPRECATED)) !== 0 || (error_reporting() & $level) === 0) {
        return true;
    }
    throw new ErrorException($message, 0, $level, $file, $line);
});

$app = new Container;
$app->instance('files', new Filesystem);
Facade::setFacadeApplication($app);

$media = __DIR__.'/media';
$work = sys_get_temp_dir().'/media_golden_'.bin2hex(random_bytes(4));
mkdir($work);

$config = static function (array $values): ProcessingConfiguration {
    $config = (new ReflectionClass(ProcessingConfiguration::class))->newInstanceWithoutConstructor();
    (function () use ($values): void {
        foreach ($values as $key => $value) {
            $this->{$key} = $value;
        }
    })->call($config);

    return $config;
};

// A stand-in for ffmpeg or ffprobe that records its arguments and then answers.
$recorder = static function (string $name, string $script) use ($work): string {
    $path = $work.'/'.$name;
    file_put_contents($path, "#!/bin/sh\nprintf '%s\\n' \"\$@\" > \"\$0.args\"\n".$script);
    chmod($path, 0755);

    return $path;
};
$recorded = static fn (string $path): array => file_exists($path.'.args')
    ? explode("\n", rtrim(file_get_contents($path.'.args'), "\n"))
    : [];
$hide = static fn (array $args): array => array_map(
    static fn (string $arg): string => str_replace([$work, $media], ['WORK', 'MEDIA'], $arg),
    $args,
);

// Containers.
$pad = static fn (string $head): string => str_pad($head, 64, "\0");
$ts = static function (int $stride, int $offset, int $packets = 5): string {
    $data = str_repeat('x', $offset);
    for ($i = 0; $i < $packets; $i++) {
        $data .= "\x47".str_repeat("\x10", $stride - 1);
    }

    return $data;
};
$heads = [
    'matroska' => $pad("\x1A\x45\xDF\xA3\x01\x00"),
    'avi' => $pad('RIFF'."\x10\0\0\0".'AVI LIST'),
    'riff wave' => $pad('RIFF'."\x10\0\0\0".'WAVEfmt '),
    'mpeg ps' => $pad("\x00\x00\x01\xBA\x44"),
    'mpeg es' => $pad("\x00\x00\x01\xB3\x14"),
    'mp4 ftyp' => $pad("\0\0\0\x20ftypisom"),
    'mov moov' => $pad("\0\0\0\x20moov"),
    'asf' => $pad("\x30\x26\xB2\x75\x8E\x66\xCF\x11\xA6\xD9"),
    'ogg' => $pad('OggS'."\0\x02"),
    'ts 188' => $ts(188, 0),
    'ts 192' => $ts(192, 4),
    'ts 188 offset' => $ts(188, 100),
    'ts offset too far' => $ts(188, 188),
    'ts three packets' => $ts(188, 0, 3),
    'ts four packets' => $ts(188, 0, 4),
    'short' => "\x1A\x45\xDF\xA3\x01\x00\x00\x00\x00\x00\x00",
    'twelve' => "\x1A\x45\xDF\xA3\x01\x00\x00\x00\x00\x00\x00\x00",
    'text' => $pad('#EXTM3U'),
    'empty' => '',
];
$containers = [];
foreach ($heads as $name => $head) {
    $containers[] = ['name' => $name, 'head' => bin2hex($head), 'container' => VideoContainer::detect($head)?->value];
}
$fileNames = [];
foreach ([
    'Movie.2160p.mkv', 'a.MK3D', 'x.webm', 'x.avi', 'x.divx', 'x.xvid', 'x.mpg', 'x.mpeg', 'x.vob', 'x.m2v',
    'x.mpv', 'x.ts', 'x.m2ts', 'x.mts', 'x.mp4', 'x.m4v', 'x.mov', 'x.qt', 'x.wmv', 'x.asf', 'x.ogv', 'x.ogm',
    '"Show.S01E01.mkv" yEnc (1/50)', '"Show.S01E01.mkv"', 'Show.mkv yEnc', 'x.mkv.par2', 'x.rar', 'mkv',
    'x.m', 'x.mpeg4', '  x.MKV  ', "x.mkv\n", 'x.mkv"x', 'x.ts (1/2)',
] as $name) {
    $fileNames[] = ['name' => $name, 'container' => VideoContainer::fromFileName($name)?->value];
}

// Source hints and the frame window.
$hintsOf = static fn (array $hint): ?MediaSourceHints => match ($hint[0]) {
    'none' => null,
    'complete' => MediaSourceHints::complete(),
    'head' => MediaSourceHints::head($hint[1], $hint[2]),
    'mid' => MediaSourceHints::mid($hint[1] ?? 0),
};
$hints = [];
foreach ([
    ['complete'], ['head', null, 100], ['head', 0, 100], ['head', 1000, 250], ['head', 1000, 1000],
    ['head', 1000, 2000], ['head', 1000, 0], ['head', -5, 10], ['mid'], ['mid', 500],
] as $hint) {
    $made = $hintsOf($hint);
    $hints[] = [
        'hint' => $hint,
        'position' => $made->position->value,
        'real_size' => $made->realSize,
        'available_bytes' => $made->availableBytes,
        'fraction' => $made->availableFraction(),
    ];
}

final class FakeGrabber implements VideoFrameGrabber
{
    /** @var list<array{0: float, 1: float, 2: int}> */
    public array $calls = [];

    /**
     * @param  array{duration: float|null, bitRate: int|null}  $probe
     * @param  list<list<string>>  $frames
     */
    public function __construct(public array $probe, public array $frames = []) {}

    public function grab(string $videoPath, ?VideoContainer $container, float $from, float $spacing, int $count, string $outputDirectory): array
    {
        $this->calls[] = [$from, $spacing, $count];

        return $this->frames[count($this->calls) - 1] ?? [];
    }

    public function probe(string $videoPath, ?VideoContainer $container): array
    {
        return $this->probe;
    }
}

$scorer = new FrameQualityScorer(12.0, 24.0, 232.0);
$windows = [];
foreach ([1, 4, 7] as $attempts) {
    foreach ([[null, null], [3600.0, null], [3600.0, 8_000_000], [null, 8_000_000], [100.5, 1_234_567], [0.002, null]] as [$duration, $bitRate]) {
        foreach ([0, 1_000_000, 123_456_789] as $fileSize) {
            // A path per size: PHP caches filesize() by path.
            $video = $work.'/window_'.$fileSize.'.bin';
            file_put_contents($video, str_repeat("\0", min($fileSize, 4096)));
            if ($fileSize > 4096) {
                $handle = fopen($video, 'r+');
                ftruncate($handle, $fileSize);
                fclose($handle);
            }
            foreach ([['none'], ['complete'], ['head', null, 0], ['head', null, 50_000_000], ['head', 4_000_000_000, 1_000_000_000],
                ['head', 4_000_000_000, 0], ['head', 1_000_000, 1_000_000], ['mid', 9]] as $hint) {
                $selector = new ThumbnailFrameSelector(
                    $config(['thumbnailFrameAttempts' => $attempts]),
                    new FakeGrabber(['duration' => $duration, 'bitRate' => $bitRate]),
                    $scorer,
                );
                $windows[] = [
                    'attempts' => $attempts,
                    'duration' => $duration,
                    'bit_rate' => $bitRate,
                    'file_size' => $fileSize,
                    'hint' => $hint,
                    'window' => $selector->window($video, null, $hintsOf($hint)),
                ];
            }
        }
    }
}

// Frame selection with scripted grabs.
$selections = [];
$mkv = $work.'/video.mkv';
file_put_contents($mkv, str_pad("\x1A\x45\xDF\xA3", 8192, "\x01"));
$playlist = $work.'/playlist.mkv';
file_put_contents($playlist, "\xEF\xBB\xBF \n#extm3u\nhttp://x/a.ts\n");
$concat = $work.'/concat.mkv';
file_put_contents($concat, "ffconcat version 1.0\nfile a.mkv\n");
$unknownText = $work.'/notes.mkv';
file_put_contents($unknownText, str_repeat('plain text ', 100));
foreach ([
    'first window wins' => [$mkv, ['duration' => 100.0, 'bitRate' => null], ['complete'], [['black.jpg', 'detail.jpg', 'dark.jpg']]],
    'retry from the start' => [$mkv, ['duration' => 100.0, 'bitRate' => null], ['complete'], [['black.jpg', 'white.jpg'], ['grey.jpg', 'gradient.jpg']]],
    'all blank' => [$mkv, ['duration' => 100.0, 'bitRate' => null], ['complete'], [['black.jpg'], ['white.jpg', 'grey.jpg']]],
    'no duration' => [$mkv, ['duration' => null, 'bitRate' => null], ['none'], [['bright.jpg', 'dark.jpg']]],
    'mid chunk' => [$mkv, ['duration' => 100.0, 'bitRate' => null], ['mid'], [['dot.jpg', 'detail.jpg']]],
    'playlist refused' => [$playlist, ['duration' => 100.0, 'bitRate' => null], ['complete'], [['detail.jpg']]],
    'concat refused' => [$concat, ['duration' => 100.0, 'bitRate' => null], ['complete'], [['detail.jpg']]],
    'other text tried' => [$unknownText, ['duration' => null, 'bitRate' => null], ['complete'], [['detail.jpg']]],
    'missing frames skipped' => [$mkv, ['duration' => 10.0, 'bitRate' => null], ['complete'], [['missing.jpg', 'text.jpg', 'odd.jpg']]],
] as $name => [$video, $probe, $hint, $frames]) {
    $grabber = new FakeGrabber($probe, array_map(
        static fn (array $list): array => array_map(static fn (string $frame): string => $media.'/'.$frame, $list),
        $frames,
    ));
    $selector = new ThumbnailFrameSelector($config(['thumbnailFrameAttempts' => 4]), $grabber, $scorer);
    $best = $selector->select($video, $work, $hintsOf($hint));
    $selections[] = [
        'name' => $name,
        'video' => basename($video),
        'head' => bin2hex((string) file_get_contents($video, false, null, 0, 64)),
        'probe' => $probe,
        'hint' => $hint,
        'frames' => $frames,
        'calls' => $grabber->calls,
        'best' => $best === null ? null : basename($best),
    ];
}

// Image measures and checks.
$images = [];
$inspector = new ImageInspector($scorer, 12.0);
$names = array_map('basename', glob($media.'/*'));
sort($names);
foreach ($names as $name) {
    if (preg_match('/\.(jpg|png|webp|gif)$/', $name) !== 1) {
        continue;
    }
    $images[] = [
        'name' => $name,
        'measure' => $scorer->measure($media.'/'.$name),
        'usable' => $inspector->isUsable($media.'/'.$name),
    ];
}
$best = [];
foreach ([
    ['black.jpg', 'detail.jpg', 'gradient.jpg'],
    ['dark.jpg', 'bright.jpg'],
    ['bright.jpg', 'detail.jpg'],
    ['black.jpg', 'white.jpg', 'grey.jpg'],
    ['dot.jpg', 'dark.jpg'],
    ['text.jpg', 'missing.jpg', 'odd.jpg'],
    [],
] as $list) {
    $chosen = $scorer->best(array_map(static fn (string $name): string => $media.'/'.$name, $list));
    $best[] = ['frames' => $list, 'best' => $chosen === null ? null : basename($chosen)];
}
// Thresholds matter only through best(), so a stricter scorer is checked too.
$strict = new FrameQualityScorer(40.0, 60.0, 180.0);
foreach ([['detail.jpg', 'gradient.jpg', 'bright.jpg'], ['dot.jpg', 'gradient.jpg']] as $list) {
    $chosen = $strict->best(array_map(static fn (string $name): string => $media.'/'.$name, $list));
    $best[] = ['strict' => true, 'frames' => $list, 'best' => $chosen === null ? null : basename($chosen)];
}

// Image candidate names.
$ranked = [];
foreach ([
    'screens.jpg', 'Movie.Screenshots.jpg', 'screencap01.jpg', 'caps.png', 'Contact Sheet.jpg', 'contact_sheet.png',
    'sheet.jpg', 'thumbs.jpg', 'thumbnail.jpg', 'preview.png', 'sample.jpg', 'samples.jpg', 'cover.jpg', 'Front.jpg',
    'poster.jpg', 'folder.jpg', 'box.jpg', 'keyart.jpg', 'back.jpg', 'rear.jpg', 'cd1.jpg', 'CD.jpg', 'dvd2.jpg',
    'disc.jpg', 'disk3.jpg', 'label.jpg', 'inlay.jpg', 'insert.jpg', 'proof.jpg', 'logo.png', 'banner.jpg',
    'avatar.png', 'sig.jpg', 'signature.jpg', 'spine.jpg', 'CD1.screens.jpg', 'cover.back.jpg', 'covers.jpg',
    'screensaver.jpg', 'backdrop.jpg', 'Movie.2160p.jpg', 'x/y/screens.jpg', 'x\\y\\back.jpg',
    '"Movie.Cover.jpg" yEnc (1/3)', '[01/10] - "proof.jpg" yEnc', ' cover.jpg ', 'fronts.jpg', 'my-front-1.jpg',
    'thumb', '', 'a"b', 'samplesheet.jpg', '00-sample.jpg',
] as $name) {
    $ranked[] = ['name' => $name, 'file_name' => ImageCandidateRanker::fileName($name), 'rank' => ImageCandidateRanker::rank($name)];
}
$sortInput = [['back.jpg', 50], ['cover.jpg', 10], ['screens.jpg', 5], ['x.jpg', 7], ['front.jpg', 30], ['caps.jpg', 9], ['cd1.jpg', 99], ['y.jpg', 7]];
$sorted = array_map(
    static fn (array $item): string => $item[0],
    ImageCandidateRanker::sort($sortInput, static fn (array $item): string => $item[0], static fn (array $item): int => $item[1]),
);

// ffprobe output parsing.
$probes = [];
foreach ([
    'format duration' => '{"format":{"duration":"12.500000"},"streams":[{"codec_type":"video","bit_rate":"1000"}]}',
    'stream duration' => '{"format":{"duration":"N/A"},"streams":[{"codec_type":"audio","duration":"3"},{"codec_type":"video","duration":"7.25","bit_rate":"2000.9"}]}',
    'matroska tags' => '{"format":{},"streams":[{"codec_type":"video","tags":{"DURATION":"01:02:03.500000000","BPS":"8000000"}}]}',
    'lowercase tags' => '{"format":{},"streams":[{"codec_type":"video","tags":{"duration":"00:00:10","bps":"500"}}]}',
    'bad clock' => '{"format":{},"streams":[{"codec_type":"video","tags":{"DURATION":"1:2:3"}}]}',
    'zero duration' => '{"format":{"duration":"0"},"streams":[{"codec_type":"video","duration":"0","bit_rate":"0"}]}',
    'negative' => '{"format":{"duration":"-4"},"streams":[{"codec_type":"video","bit_rate":"-1"}]}',
    'numbers' => '{"format":{"duration":30},"streams":[{"codec_type":"video","bit_rate":12345}]}',
    'exponent' => '{"format":{"duration":"1e2"},"streams":[{"codec_type":"video","tags":{"BPS":" 42"}}]}',
    'no video stream' => '{"format":{"duration":"5"},"streams":[{"codec_type":"audio","bit_rate":"1"}]}',
    'stream not object' => '{"format":{"duration":"5"},"streams":[1,"x",{"codec_type":"video","bit_rate":"77"}]}',
    'tags not object' => '{"format":{},"streams":[{"codec_type":"video","tags":"x"}]}',
    'streams not list' => '{"format":{"duration":"9"},"streams":"x"}',
    'format not object' => '{"format":"x","streams":[]}',
    'not json' => 'garbage',
    'json scalar' => '42',
    'empty' => '',
    'huge bit rate' => '{"format":{},"streams":[{"codec_type":"video","bit_rate":"1e30"}]}',
    'fraction bit rate' => '{"format":{},"streams":[{"codec_type":"video","bit_rate":"99.99"}]}',
] as $name => $json) {
    $ffprobe = $recorder('ffprobe_'.md5($name), "cat <<'JSON'\n".$json."\nJSON\n");
    $tools = new MediaToolFactory($config(['mediaFfmpegTimeout' => 30, 'ffmpegPath' => false, 'ffprobePath' => $ffprobe]));
    $grabber = new FfmpegFrameGrabber($tools);
    $attempt = static function (?VideoContainer $container) use ($grabber, $media): array {
        try {
            return $grabber->probe($media.'/clip.mkv', $container);
        } catch (Throwable $e) {
            return ['error' => $e->getMessage()];
        }
    };
    $probes[] = [
        'name' => $name,
        'json' => $json,
        'mkv' => $attempt(VideoContainer::Matroska),
        'args' => $hide($recorded($ffprobe)),
        'unknown' => $attempt(null),
        'unknown_args' => $hide($recorded($ffprobe)),
    ];
}
$failing = $recorder('ffprobe_failing', "echo '{\"format\":{\"duration\":\"5\"}}'\nexit 1\n");
$probeFailure = (new FfmpegFrameGrabber(new MediaToolFactory($config(['mediaFfmpegTimeout' => 30, 'ffmpegPath' => false, 'ffprobePath' => $failing]))))
    ->probe($media.'/clip.mkv', null);

// Frame grabs with the real ffmpeg.
$ffmpeg = trim((string) shell_exec('command -v ffmpeg'));
$ffprobeBinary = trim((string) shell_exec('command -v ffprobe'));
$wrapper = $recorder('ffmpeg_wrapper', 'exec '.escapeshellarg($ffmpeg)." \"\$@\"\n");
$grabs = [];
foreach ([
    ['clip.mkv', 'matroska', 3.0, 2.0, 4],
    ['clip.mkv', null, 0.0, 0.0, 4],
    ['clip.mkv', 'matroska', 0.0, 0.0, 1],
    ['clip.mkv', 'matroska', 2.25, 3.333, 10],
    ['clip.mkv', 'matroska', -1.0, -1.0, 0],
    ['clip.mkv', 'matroska', 50.0, 1.0, 4],
    ['clip.mkv', 'avi', 0.0, 0.0, 2],
    ['black.mkv', 'matroska', 0.0, 0.0, 3],
] as [$file, $container, $from, $spacing, $count]) {
    $out = $work.'/grab_'.count($grabs);
    mkdir($out);
    $grabber = new FfmpegFrameGrabber(new MediaToolFactory($config(['mediaFfmpegTimeout' => 30, 'ffmpegPath' => $wrapper, 'ffprobePath' => $ffprobeBinary])));
    $frames = $grabber->grab($media.'/'.$file, $container === null ? null : VideoContainer::from($container), $from, $spacing, $count, $out.'/');
    $args = $hide($recorded($wrapper));
    $pattern = $args[count($args) - 1] ?? '';
    $grabs[] = [
        'file' => $file,
        'container' => $container,
        'from' => $from,
        'spacing' => $spacing,
        'count' => $count,
        'args' => array_map(
            static fn (string $arg): string => preg_replace('/frame_[0-9a-f]{8}_/', 'frame_RANDOM_', $arg),
            $args,
        ),
        'frames' => array_map(static fn (string $frame): array => [
            'name' => preg_replace('/frame_[0-9a-f]{8}_/', 'frame_RANDOM_', basename($frame)),
            'measure' => $scorer->measure($frame),
        ], $frames),
    ];
}

// Duration and the video clip.
$service = (new ReflectionClass(MediaExtractionService::class))->newInstanceWithoutConstructor();
$serviceConfig = $config([
    'mediaFfmpegTimeout' => 30, 'ffmpegPath' => $wrapper, 'ffprobePath' => $ffprobeBinary,
    'processVideo' => true, 'ffmpegDuration' => 5, 'debugMode' => false,
]);
(function () use ($serviceConfig): void {
    $this->config = $serviceConfig;
    $this->tools = new MediaToolFactory($serviceConfig);
})->call($service);
$durations = [];
foreach (['clip.mkv', 'black.mkv', 'detail.jpg', 'text.jpg', 'missing.mkv'] as $file) {
    $durations[] = ['file' => $file, 'duration' => $service->getMediaDuration($media.'/'.$file)];
}
@unlink($wrapper.'.args');
$videoTmp = $work.'/video/';
mkdir($videoTmp);
$videos = [];
foreach ([['clip.mkv', ['complete']], ['clip.mkv', ['head', null, 100]], ['clip.mkv', ['none']], ['clip.mkv', ['mid']], ['missing.mkv', ['complete']]] as [$file, $hint]) {
    $videos[] = [
        'file' => $file,
        'hint' => $hint,
        'made' => $service->getVideo($media.'/'.$file, $videoTmp, 'guid', $hintsOf($hint)),
        'ffmpeg_ran' => file_exists($wrapper.'.args'),
        'files' => array_values(array_diff(scandir($videoTmp), ['.', '..'])),
    ];
}

echo json_encode([
    'containers' => $containers,
    'file_names' => $fileNames,
    'hints' => $hints,
    'windows' => $windows,
    'selections' => $selections,
    'images' => $images,
    'best' => $best,
    'ranked' => $ranked,
    'sort_input' => $sortInput,
    'sorted' => $sorted,
    'probes' => $probes,
    'probe_failure' => $probeFailure,
    'grabs' => $grabs,
    'durations' => $durations,
    'videos' => $videos,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION)."\n";

(new Filesystem)->deleteDirectory($work);

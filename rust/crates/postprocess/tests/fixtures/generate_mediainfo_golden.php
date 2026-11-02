<?php
// Regenerates mediainfo_golden.json from the real php-mediainfo parser (no app bootstrap), over
// the OLDXML samples in mediainfo/. After rust/porting/php-ref-setup.sh:
//   php generate_mediainfo_golden.php <php-final checkout> > mediainfo_golden.json
//
// MediaInfo::addData and ReleaseExtraService::addFromXml write through Eloquent, which needs the
// app. Their bodies are copied below, with each insert recorded instead (values bound as PDO would
// bind them: objects through __toString, and an array, which can't be bound, marked). The
// duplicate checks, and the failure an array caused once it reached an insert, are left to the
// database tests.
declare(strict_types=1);

$ref = $argv[1];
require $ref.'/vendor/autoload.php';

use Mhor\MediaInfo\Container\MediaInfoContainer;
use Mhor\MediaInfo\Parser\MediaInfoOutputParser;

/** How PDO would bind a value; an array can't be bound. */
function bound(mixed $value): mixed
{
    if (is_array($value)) {
        return ['unbindable' => true];
    }
    if (is_object($value)) {
        return (string) $value;
    }

    return $value;
}

function addData(MediaInfoContainer $xmlArray): ?array
{
    $mediainfoArray = $xmlArray->getGeneral();
    if (! $mediainfoArray) {
        return null;
    }
    $mediaUniqueId = $mediainfoArray->get('unique_id');
    if ($mediaUniqueId === null || $mediaUniqueId === '0' || $mediaUniqueId === '0x0' || $mediaUniqueId === 0) {
        $mediaUniqueId = null;
    }

    return [
        'movie_name' => bound($mediainfoArray->get('movie_name') ?? null),
        'file_name' => bound($mediainfoArray->get('file_name') ?? null),
        'unique_id' => bound($mediaUniqueId),
    ];
}

function addFromXml(MediaInfoContainer $arrXml): array
{
    $rows = ['video' => [], 'audio' => [], 'subs' => []];
    $containerFormat = '';
    $overallBitRate = '';
    $general = $arrXml->getGeneral();
    $audios = $arrXml->getAudios();
    $videos = $arrXml->getVideos();
    $subtitles = $arrXml->getSubtitles();

    if ($general !== null) {
        if ($general->get('format') !== null) {
            $containerFormat = $general->get('format')->getFullName();
        }
        $videoDuration = $videoFormat = $videoCodec = $videoWidth = $videoHeight = $videoAspect = $videoFrameRate = $videoLibrary = $videoBitRate = '';
        if (! empty($videos)) {
            foreach ($videos as $video) {
                if ($video->get('duration') !== null) {
                    $videoDuration = $video->get('duration')->getMilliseconds();
                }
                if ($video->get('bit_rate') !== null) {
                    $overallBitRate = $video->get('bit_rate');
                }
                if ($video->get('format') !== null) {
                    $videoFormat = $video->get('format')->getFullName();
                }
                if ($video->get('codec_id') !== null) {
                    $videoCodec = $video->get('codec_id');
                }
                if ($video->get('width') !== null) {
                    $videoWidth = $video->get('width')->getAbsoluteValue();
                }
                if ($video->get('height') !== null) {
                    $videoHeight = $video->get('height')->getAbsoluteValue();
                }
                if ($video->get('display_aspect_ratio') !== null) {
                    $videoAspect = $video->get('display_aspect_ratio')->getTextValue();
                }
                if ($video->get('frame_rate') !== null) {
                    $videoFrameRate = $video->get('frame_rate')->getTextValue();
                }
                if ($video->get('encoded_library') !== null) {
                    $videoLibrary = $video->get('encoded_library');
                }
                if ($video->get('encoded_library_version') !== null) {
                    $videoLibrary .= $video->get('encoded_library_version');
                }
                if ($video->get('writing_library') !== null) {
                    $videoLibrary = $video->get('writing_library')->getFullName();
                }
                if ($video->get('nominal_bit_rate') !== null) {
                    $videoBitRate = $video->get('nominal_bit_rate')->getTextValue();
                }
                if (! empty($videoBitRate)) {
                    $overallBitRate = $videoBitRate;
                }
                // addVideo's typed parameters.
                (static function (string $containerFormat, mixed $overallBitRate, mixed $videoDuration, string $videoFormat, mixed $videoCodec, mixed $videoWidth, mixed $videoHeight, string $videoAspect, string $videoFrameRate, mixed $videoLibrary) use (&$rows): void {
                    $rows['video'][] = [
                        'containerformat' => $containerFormat,
                        'overallbitrate' => bound($overallBitRate),
                        'videoduration' => is_numeric($videoDuration) ? realDuration($videoDuration) : $videoDuration,
                        'videoformat' => $videoFormat,
                        'videocodec' => bound($videoCodec),
                        'videowidth' => bound($videoWidth),
                        'videoheight' => bound($videoHeight),
                        'videoaspect' => $videoAspect,
                        'videoframerate' => $videoFrameRate,
                        'videolibrary' => bound($videoLibrary),
                    ];
                })($containerFormat, $overallBitRate, $videoDuration, $videoFormat, $videoCodec, $videoWidth, $videoHeight, $videoAspect, $videoFrameRate, $videoLibrary);
            }
        }

        $audioID = 1;
        $audioFormat = $audioMode = $audioBitRateMode = $audioBitRate = $audioChannels = $audioSampleRate = $audioLibrary = $audioLanguage = $audioTitle = '';
        if (! empty($audios)) {
            foreach ($audios as $audio) {
                if ($audio->get('id') !== null) {
                    $audioID = $audio->get('id')->getFullName();
                }
                if ($audio->get('format') !== null) {
                    $audioFormat = $audio->get('format')->getFullName();
                }
                if ($audio->get('format_settings_sbr') !== null) {
                    $audioMode = $audio->get('format_settings_sbr')->getFullName();
                }
                if ($audio->get('bit_rate_mode') !== null) {
                    $audioBitRateMode = $audio->get('bit_rate_mode')->getFullName();
                }
                if ($audio->get('bit_rate') !== null) {
                    $audioBitRate = $audio->get('bit_rate')->getTextValue();
                }
                if ($audio->get('channel_s') !== null) {
                    $audioChannels = $audio->get('channel_s')->getAbsoluteValue();
                }
                if ($audio->get('sampling_rate') !== null) {
                    $audioSampleRate = $audio->get('sampling_rate')->getTextValue();
                }
                if ($audio->get('encoded_library') !== null) {
                    $audioLibrary = $audio->get('encoded_library');
                }
                if ($audio->get('language') !== null) {
                    $audioLanguage = $audio->get('language');
                }
                if ($audio->get('title') !== null) {
                    $audioTitle = $audio->get('title');
                }
                $rows['audio'][] = [
                    'audioid' => bound($audioID),
                    'audioformat' => bound(\is_array($audioFormat) ? implode($audioFormat) : $audioFormat),
                    'audiomode' => bound(\is_array($audioMode) ? implode($audioMode) : $audioMode),
                    'audiobitratemode' => bound(\is_array($audioBitRateMode) ? implode($audioBitRateMode) : $audioBitRateMode),
                    'audiobitrate' => bound(\is_array($audioBitRate) ? implode($audioBitRate) : $audioBitRate),
                    'audiochannels' => bound(\is_array($audioChannels) ? implode($audioChannels) : $audioChannels),
                    'audiosamplerate' => bound(\is_array($audioSampleRate) ? implode($audioSampleRate) : $audioSampleRate),
                    'audiolibrary' => bound(\is_array($audioLibrary) ? implode($audioLibrary) : $audioLibrary),
                    'audiolanguage' => bound(! empty($audioLanguage) ? $audioLanguage[1] : ''),
                    'audiotitle' => bound(\is_array($audioTitle) ? implode($audioTitle) : $audioTitle),
                ];
            }
        }

        if (! empty($subtitles)) {
            foreach ($subtitles as $subtitle) {
                $subsID = 1;
                $subsLanguage = 'Unknown';
                if ($subtitle->get('id') !== null) {
                    $subsID = $subtitle->get('id')->getFullName();
                }
                if ($subtitle->get('language') !== null) {
                    $subsLanguage = $subtitle->get('language');
                }
                $subs = '';
                if (! empty($subsLanguage)) {
                    if (! empty($subsLanguage[1])) {
                        $subs = $subsLanguage[1];
                    } elseif (! empty($subsLanguage[0])) {
                        $subs = $subsLanguage[0];
                    }
                }
                $rows['subs'][] = ['subsid' => bound($subsID), 'subslanguage' => bound($subs)];
            }
        }
    }

    return $rows;
}

$cases = [];
foreach (glob(__DIR__.'/mediainfo/*.xml') as $path) {
    $case = ['name' => basename($path), 'parse_error' => null, 'media_info' => null, 'extra' => null, 'extra_error' => null];
    try {
        $parser = new MediaInfoOutputParser;
        $parser->parse((string) file_get_contents($path));
        $container = $parser->getMediaInfoContainer(['attribute_checkers' => null, 'ignore_unknown_track_types' => true]);
    } catch (Throwable $e) {
        $case['parse_error'] = get_class($e);
        $cases[] = $case;

        continue;
    }
    try {
        $case['media_info'] = ['row' => addData($container)];
    } catch (Throwable $e) {
        $case['media_info'] = ['error' => get_class($e)];
    }
    try {
        $case['extra'] = addFromXml($container);
    } catch (Throwable $e) {
        $case['extra_error'] = get_class($e);
    }
    $cases[] = $case;
}

echo json_encode($cases, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), "\n";

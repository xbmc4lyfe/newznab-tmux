<?php
// Regenerates golden.jsonl from the names in ../../../categorize/tests/fixtures plus pairs here.
// php generate_golden.php <php-final checkout> <corpus.txt> ... > golden.jsonl
declare(strict_types=1);

$ref = $argv[1];
require $ref.'/vendor/autoload.php';
$app = require $ref.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\NameFixing\FileNameCleaner;
use App\Services\NameFixing\NzbSplitUnwrapper;
use App\Services\NameFixing\OriginalFilenameEvidence;
use App\Services\NameFixing\PredbMatchSelector;
use App\Services\NameFixing\SeasonPackDetector as S;
use App\Services\Predb\PredbIdentityKeys;

$cleaner = new FileNameCleaner;
$unwrapper = new NzbSplitUnwrapper;
$selector = new PredbMatchSelector;
$keys = new PredbIdentityKeys;
$names = [];
foreach (array_slice($argv, 2) as $file) {
    foreach (file($file, FILE_IGNORE_NEW_LINES) as $line) {
        $names[preg_replace_callback('/\\\\(n|\\\\)/', static fn (array $m): string => $m[1] === 'n' ? "\n" : '\\', $line)] = true;
    }
}
$names = array_map('strval', array_keys($names));
$variants = [];
foreach ($names as $name) {
    // A sample of the corpus: this fixture repeats each name in dozens of fields.
    if (crc32($name) % 4 !== 0 && ! preg_match('/NZBSPLIT|[^\x00-\x7f]/', $name)) {
        continue;
    }
    $variants[$name] = true;
    if (strlen($name) < 120 && ! str_contains($name, "\n")) {
        foreach (['.mkv', '.part01.rar', '.vol01+02.par2', '.r00', '.7z.001', '.nfo', '.DTS.rar', '.sample.mkv'] as $i => $suffix) {
            if ((crc32($name) + $i) % 8 === 0) {
                $variants[$name.$suffix] = true;
            }
        }
        if (crc32($name) % 7 === 0) {
            $variants['some/dir\\'.$name] = true;
            $variants['01 - '.$name] = true;
            $variants['__NZBSPLIT__deadbeef01__NZBSPLIT__'.str_replace('.', '_', $name).'.rar" yEnc (1/5)'] = true;
            $variants['Show.Name.S01E0'.(crc32($name) % 9).'.'.$name] = true;
        }
    }
}
$out = fn (mixed $value) => $value === false ? null : $value;
foreach (array_keys($variants) as $name) {
    $name = (string) $name;
    if (! mb_check_encoding($name, 'UTF-8')) {
        continue;
    }
    $evidence = new OriginalFilenameEvidence($name);
    $title = $cleaner->stripFileSuffixes($name);
    $hits = [['id' => 7, 'title' => $title, 'filename' => $name, 'source' => 'x'], ['id' => 8, 'title' => $title.'.other', 'filename' => '', 'source' => 'x']];
    echo json_encode([
        'n' => $name,
        'strip' => $title,
        'clean' => $out($cleaner->cleanForMatching($name)),
        'cleanp' => $out($cleaner->cleanForMatching($name, preserveLeadingNumber: true)),
        'norm' => $cleaner->normalizeCandidateTitle($name),
        'fmt' => $cleaner->formatSearchName($name),
        'plaus' => $cleaner->isPlausibleReleaseTitle($name),
        'scene' => $cleaner->looksLikeSceneRelease($name),
        'tech' => $cleaner->isLiteralTechnicalSceneTitle($name),
        'title' => $cleaner->cleanForTitleMatch($name),
        'payload' => $unwrapper->literalPayload($name),
        'trail' => $unwrapper->stripLiteralSubjectTrailer($name),
        'base' => $evidence->basename, 'stem' => $evidence->stem,
        'pipe' => $evidence->isLocallyRejectedPipe(),
        'keys' => ($k = $evidence->canonicalNominationKeys()) === null ? null : array_map(md5(...), $k),
        'acc' => $evidence->accepts($title),
        'acca' => $evidence->accepts('Unrelated.Title-GRP', $name),
        'row' => ($r = $keys->forRow((object) ['id' => 5, 'title' => $title, 'filename' => $name])) === null ? null : md5(json_encode($r, JSON_THROW_ON_ERROR)),
        'sel' => $selector->selectBestMatch($title, $hits)['id'] ?? null,
        'sele' => $selector->selectBestMatch($title, $hits, $evidence)['id'] ?? null,
        'sp' => S::isSeasonPack($name), 'ep' => S::isEpisodeName($name),
        'un' => S::isUnclassifiedEpisodeName($name), 'anc' => S::isAncillaryName($name),
        'id' => S::episodeIdentity($name),
        'one' => S::seasonNameFromEpisode($name),
        'set' => S::seasonNameFromEpisodes([$name, preg_replace('/E0?(\d)/i', 'E2$1', $name, 1) ?? $name]),
        'multi' => S::isMultiEpisodeSet([$name, preg_replace('/E0?(\d)/i', 'E2$1', $name, 1) ?? $name]),
        'nons' => S::hasNonSingleEpisodeEvidence([$name]),
        'fold' => S::seasonFolder([$name.'/x.mkv']),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
}

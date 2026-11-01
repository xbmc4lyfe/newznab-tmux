<?php
// Regenerates golden.jsonl (and patterns.json) from the real PHP classes. Run after
// rust/porting/php-ref-setup.sh, from anywhere:
//   php generate_golden.php <php-final checkout> > golden.jsonl
//   php generate_golden.php <php-final checkout> patterns > patterns.json
//   php build_samples.php <php-final checkout> > samples.txt
// Inputs: the categorize corpus and branch names, samples.txt (strings from the PHP tests that
// mention these classes), and the synthetic file lists, donors, hits and NFO texts below.
declare(strict_types=1);

$ref = $argv[1];
$mode = $argv[2] ?? 'golden';
require $ref.'/vendor/autoload.php';
$app = require $ref.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\NameFixing\ConstrainedPredbMatcher;
use App\Services\NameFixing\Data\NameFixResult;
use App\Services\NameFixing\DonorMatchSelector;
use App\Services\NameFixing\Extractors\FileNameExtractor;
use App\Services\NameFixing\Extractors\NfoNameExtractor;
use App\Services\NameFixing\FilePrioritizer;
use App\Services\NameFixing\NameCheckerService;
use App\Services\NameFixing\PredbIdentityKey;

if ($mode === 'patterns') {
    // Every regex literal in the ported PHP files, so a test can check the Rust source has it.
    $base = $ref.'/app/Services/NameFixing/';
    $out = [];
    foreach (['Patterns/TvPatterns.php', 'Patterns/MoviePatterns.php', 'Patterns/GamePatterns.php', 'Patterns/AppPatterns.php',
        'Extractors/FileNameExtractor.php', 'Extractors/NfoNameExtractor.php', 'FilePrioritizer.php',
        'ConstrainedPredbMatcher.php', 'PredbIdentityKey.php', 'DonorMatchSelector.php'] as $file) {
        foreach (token_get_all((string) file_get_contents($base.$file)) as $token) {
            if (! is_array($token) || $token[0] !== T_CONSTANT_ENCAPSED_STRING) {
                continue;
            }
            $value = eval('return '.$token[1].';');
            if (strlen($value) > 4 && preg_match('/^([\/~])(.*)\1([a-zA-Z]*)$/s', $value, $m)) {
                $out[$file."\0".$m[2]."\0".$m[3]] = ['file' => $file, 'body' => $m[2], 'flags' => $m[3]];
            }
        }
    }
    echo json_encode(array_values($out), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT), "\n";
    exit(0);
}

$fixtures = dirname(__DIR__, 3).'/categorize/tests/fixtures/';
$checker = new NameCheckerService;
$files = new FileNameExtractor;
$nfo = new NfoNameExtractor;
$prioritizer = new FilePrioritizer;
$donors = new DonorMatchSelector;
$matcher = new ConstrainedPredbMatcher;

// PHP raises warnings the Laravel handler would turn into exceptions (an unset `$result[2]`);
// record them and carry on with PHP's own fallback so the value is comparable.
$warnings = [];
set_error_handler(static function (int $no, string $message) use (&$warnings): bool {
    $warnings[] = $message;

    return true;
});

$result = static fn (?NameFixResult $r): ?array => $r === null ? null : [
    'new_name' => $r->newName, 'method' => $r->method, 'checker' => $r->checkerName, 'confidence' => $r->confidence,
];
$json = static fn (array $row): string => json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR)."\n";
$emit = static function (array $row) use (&$warnings, $json): void {
    if ($warnings !== []) {
        $row['warn'] = array_values(array_unique($warnings));
        $warnings = [];
    }
    echo $json($row);
};
$decode = static fn (string $line): string => preg_replace_callback('/\\\\(n|\\\\)/', static fn (array $m): string => $m[1] === 'n' ? "\n" : '\\', $line);

// ---------------------------------------------------------------------------------------
// Names: the shared corpus, the branch names, the PHP test samples and hand-written paths.
// ---------------------------------------------------------------------------------------
$names = [];
// The shared corpus repeats itself, so keep every second one of its plain names.
foreach ([$fixtures.'corpus.txt', $fixtures.'branches.txt', __DIR__.'/samples.txt'] as $path) {
    foreach (file($path, FILE_IGNORE_NEW_LINES) as $line) {
        $name = $decode($line);
        if (! str_ends_with($path, 'corpus.txt') || crc32($name) % 2 === 0 || strlen($name) < 30
            || preg_match('/[^\x00-\x7f]|NZBSPLIT|\\\\|\n/', $name) === 1) {
            $names[$name] = true;
        }
    }
}
$title = 'The.West.Wing.S07E01.The.Ticket.1080p.BluRay.10bit.EAC3.2.0.x265-iVy';
$homefront = '[pcela] Homefront (2013) - [BD 1080p][H264 8bit][DTS 5 1][MULTI][English+Polish]';
$handwritten = [
    'NBA__NZBSPLIT__bdab31d6f79989608009e7e8eadcbe66__NZBSPLIT__NBA_20260419_PHI_BOS_1080p60_ABC.7z.073',
    'TEST__NZBSPLIT__1234567890abcdef__NZBSPLIT__setup.7z.001',
    $title.'.mkv', 'D:\\Unsorted\\'.$title.'.mkv', $title.'.mkv.rar.par2', $title,
    'Film.2026.1080p.WEB-DL.DDP5.1.H.264-NTb.mkv',
    "Cafe\u{0301}.Movie.2026.1080p.BluRay.x264-GROUP.mkv",
    str_repeat('é', 170).'.Movie.2026.1080p.BluRay.x264-GROUP.mkv',
    str_repeat('a', 260).'.Movie.2026.1080p.BluRay.x264-GROUP.mkv',
    "Rosal\u{200B}ia.Movie.2026.1080p.BluRay.x264-GROUP.mkv",
    $homefront.'.md5', $homefront.'.nfo', $homefront.'.sha1', $homefront.'[86BC5CED].mkv',
    '/srv/usenet/Chateau.Gaillard.Le.pari.fou.de.Richard.Coeur.de.Lion.2026.HDTVLight.1080p.x264.VF.AC3.2.0.@BS.rar',
    'Ordinary World (2016) 1080p\\Ordinary World (2016) 1080p.mkv', 'Ordinary World (2016) 1080p/Ordinary World (2016) 1080p.mkv',
    'Other World (2016) 1080p/Ordinary World (2016) 1080p.mkv', 'Ordinary World (2016)/Ordinary World (2016).mkv',
    'Léon (1994) 1080p/Léon (1994) 1080p.mkv', 'Ordinary World (12016) 1080p/Ordinary World (12016) 1080p.mkv',
    'Léon.1994.HDTVLight.1080p.x264.@BS.rar', '天空之城.1986.HDTVLight.1080p.x264.@BS.rar',
    'Film.2026.HDTVLight.foo1080px.x264.@BS.rar', 'Film.2026.BD123.1080p.x264.@BS.rar', '[pcela] BS (2013) - [BD 1080p][H264 8bit].mkv',
    'Readme.2026.1080p.r1000', $homefront.'.r9', $homefront.'.r10000', $homefront.'.r100a', $homefront.'.r1000.exe', $homefront.'.r999',
    'Canonical.Archive.Release-GRP/obfuscated.r00', 'Canonical.Archive.Release-GRP/obfuscated.r9', 'Canonical.Archive.Release-GRP/obfuscated.r9999',
    "Show.Name.S01E01.720p.HDTV.x264-GRP\\file.rar", "Show.Name.S01E01.x264-TVP\\file.rar", 'Show.Name.S01E01.720p.HDTV.x264-TVP\\file.r00',
    "Title 1080i DD5.1 MPEG2-R&C.ts", "tvp-Show.Name.S01E02.720p.avi", "x\\tvp-show.s01e02.xvid.mkv",
    'Show.Name.S01E02.1080p.AMZN.WEB-DL.DDP5.1.H.264-GRP.mkv', 'D:\\tv\\Show Name S01E02 1080p WEB-DL x264.mkv', 'D:\\tv\\Show.Name.S01E02.mkv',
    "D:\\tv\\Show.Name.S01E02.720p.HDTV.x264-GRP.mkv\n", "D:\\tv\\Show.Name.S01E02.mkv\n", "Movie.Name.2010.BDRip.XviD-GRP\n", "Movie.Name.2010.BDRip.XviD-GRP.avi\n",
    'Movies\\Some.Movie.2160p.UHD.BluRay.HDR.HEVC-GRP.mkv', 'Movies\\Some Movie 2020 2160p WEB-DL DV HEVC.mkv', 'Movies/Some Movie 1999 720p BluRay x264 AAC.mp4',
    'Movies\\Some Movie 2010 DVDRip XVID.avi', 'Movies/Some.Movie.2010.1080p.BDRip.x264-GRP.mkv',
    'some.movie.2010.720p.bluray.x264.grp.mkv', 'Some.Movie.2010.720p.BluRay.x264.mkv',
    'Show.Name.AMZN.1080p.WEB-DL.DDP5.1.H.264-GRP.mkv', 'Show.Name.NF.WEB-DL-GRP.mkv',
    'Artist-Album-WEB-2019-GRP\\01-track.mp3', 'Artist-Album-CD-FLAC-1999-GRP\\01.flac', 'VA-Compilation-2011-C4\\01.mp3', 'Artist-Album-2011-ABC\\01.mp3',
    'Artist.Album.FLAC.2011.REMASTERED-GRP\\01.flac', 'Artist.Album.MP3.2011.DELUXE-GRP/01.mp3', 'Artist.Album.AAC.2011.EDITION-GRP.mp3',
    'Some Movie (2010)\\Some Movie (2010).avi', 'Some Movie (2010)\\Some Movie (2010).iso', 'Some Movie (2010)\\Some Movie (2010).mkv', 'Some Movie (2010)\\Some Movie (2010).MP4',
    'Some Movie (2010)\\Some.avi.Movie (2010).avi', 'x\\Movie (2010).AVI',
    'Some.Release.Name-GRP\\sub\\file.rar', 'Some.Release.Name-GRP\\CD1\\file.r01', 'Some.Release.Name-GRP/Disc1/file.zip', 'Some Release Name\\x.7z', 'Some.Release-GRP\\file.part01.rar',
    'Some.Release.Name\\Cover\\x.jpg', 'Some.Release.Name-GRP\\x.zip',
    'XXX.IMAGESET.Name\\img01.jpg', 'Pics IMAGESET Model\\img01.jpg',
    'VIDEOOT-ABC123\\Some Video Name-proof.part01.rar', 'VIDEOOT-ABC123\\Some_Video_Name_Here.mp4', 'VIDEOOT-ABC123\\Some Video Name Éé.mp4', "VIDEOOT-ABC123\\Some_Video_Name_Hereé.rar.vol01+02",
    'Sd Porn SDPORN Name 01.mp4', 'sdporn-something.mp4', "SDPORN something\n",
    '1080i DD5.1 MPEG2-R&C.ts',
    'Show.Name.S01E02.480p.DVD.nSD.XviD.NhaNC3.avi', 'Show.Name.1x02.480p.HDTV.nSD.x264.NhaNC3.mkv', 'Show.Name.S01E02.720p.HDTV.nSD.x264.NhaNC3',
    'Show.Name.211.hdtv-lol.mp4', 'Show.Name.211.hdtv-lol.nzb', 'Show.Name-S01E02-XVID-DL.avi', 'Show Name - s01e02 - Episode Title.avi', 'Show - Name - s01e02 - Ep.mkv',
    'Game Name (USA) (En,Fr).nds', 'Game Name (Europe)(En).nds', '3DS_0123 something 0456 - Game Title.3ds', 'Game [0100ABCD12345678].nsp', 'Game Title [01005EF00CFA2000].XCI', 'Game [x].nsz',
    'Game.Name.PS4-GRP/some.bin', 'Game.Name.PS3.USA.PROPER-GRP\\f.iso', 'Game.Name.XBOX360-GRP.iso', 'Game.Name.NSW-GRP.nsp', 'Game.Name.XBOXONE-GRP\\x.iso', 'Game.Name.PSV-GRP/x.bin',
    'Some Book Title.epub', 'Some Book Title.EPUB', 'Some.Book.epub.Title.mobi', 'Some Book.CBR', 'Some Book.azw3', 'dir\\Some Book.azw',
    'Author.Name.Audiobook.Title.2011.MP3-GRP\\01.mp3', 'Some.Audiobook.Name/x.m4b', 'Audiobook x\\y',
    'Some.Scene.Name-GRP', 'Some.Scene.Name-GRP.rar', 'Some.Scene.Name-GRP.part01.rar', 'readme-GRP', 'Some Folder Name 2010', 'some folder name',
    'x.mkv', 'a/b', 'a\\b', "a/b\n", "a/\n", 'a/', '/a', '\\a', 'Some Movie 2010 1080p BluRay x264.mkv', 'Some.Movie.2010.1080p.BluRay.x264.mkv', '[Group] Some Movie 2010 1080p BluRay x264.mkv',
    'Some Movie 2010 1080p BluRay x264.rar', 'Some Movie 2010 1080p BluRay x264.nfo', 'Some.Show.2010.720p.HDTV.XviD.mkv',
    'Tools.Name.v1.2.3-GRP.zip', 'x/.hidden', 'Film.2026.1080p.BluRay.x264-GROUP.mkv',
    'Movies\\Some Movie 2020 Extended 2160p WEB-DL DV HEVC-GRP.mkv', 'Movies\\Some Movie 2020 Extended 1080p BluRay x264-GRP.mkv', 'Movies\\Some Movie 2020 Extended UHD HDR10 HEVC-GRP.mkv',
    'Show.Name.AMZN.1080p.WEB-DL.DDP5.1.H.264.GRP.mkv', 'dir/Show Name HULU 720p WEB-RIP x264.mp4', 'Artist.Album.MP3.Deluxe.2011.REMASTERED-GRP/01.mp3',
    'Artist Album FLAC Deluxe 2011 EDITION-GRP.', 'Show-S01X02-XVID-DL.avi', 'x\\Show Name-S01X02-XVID-DL.avi', 'Show Name-S01X02-XVID-DL.avi', 'Show - s01x02 - Title.', 'x - s01e02 - Title.', 'Show - s01e02 - Title.avi', 'Show.Name-S01X02-XVID-DL.avi',
    'Game Name PS4 USA-GRP/x.bin', 'Dir.Name.XBOXONE-GRP.x', 'Dir Name PSV x-GRP.',
    'VIDEO_TS\\VTS_01_0.IFO', 'x\\VIDEO_TS.VOB', 'x\\AUDIO_TS.IFO', 'x\\12.jpg', 'x\\Release.url', 'x\\RELEASE.URL', '.url', 'a.sample.mkv', 'a-proof-b.jpg',
];
foreach ($handwritten as $name) {
    $names[$name] = true;
}
// Names built from pattern-shaped parts, so every checker pattern and extractor branch is reached.
mt_srand(11);
$pick = static fn (array $a): string => $a[mt_rand(0, count($a) - 1)];
$shows = ['Show.Name', 'Some.Show', 'The.Big.Show', 'Daily.Show', 'Anime.Name', 'UFC', 'Movie.Name', 'Game.Name', 'App.Name', 'Adobe.Photoshop', 'Microsoft.Office', 'Café.Show'];
$res = ['480p', '720p', '1080p', '2160p', '4K', 'UHD', '1080i', '720', '1080'];
$src = ['HDTV', 'WEB-DL', 'WEBRip', 'WEB', 'BluRay', 'BDRip', 'DVDRip', 'DVD', 'REMUX', 'BRRip', 'PDTV', 'CAM', 'HDRip', 'AMZN.WEB-DL', 'NF.WEB-DL', 'HULU.WEBRip', 'DSNP.WEB-DL', 'iT.WEB-DL', 'Blu-Ray', 'DVD-R'];
$vc = ['x264', 'x265', 'H.264', 'H264', 'H.265', 'HEVC', 'XviD', 'AV1', 'MPEG2', 'DivX', 'h265', 'WMV'];
$ac = ['AAC', 'AC3', 'DD5.1', 'DTS', 'DTS-HD', 'DTS-HD.MA', 'TrueHD', 'Atmos', 'EAC3', 'FLAC', 'MP3', 'AAC LC', 'DD5'];
$hdr = ['HDR', 'HDR10', 'HDR10+', 'DV', 'Dolby.Vision', 'HLG', 'DoVi'];
$grp = ['GROUP', 'GRP', 'NTb', 'CODEX', 'PLAZA', 'SKIDROW', 'FitGirl', 'iVy', 'RARBG', 'x', 'TEPES'];
$lang = ['FRENCH', 'GERMAN', 'MULTi', 'Italian', 'SPANISH', 'Korean', 'Japanese', 'Russian', 'Deutsch', 'Nordic'];
$plat = ['NSW', 'PS5', 'PS4', 'PS3', 'PSV', 'XBSX', 'XBOX360', 'XBOXONE', 'Switch', 'WiiU', 'NDS', 'GC', 'PSP', 'PC', 'WIN64', 'MACOSX', 'LINUX'];
$region = ['EUR', 'USA', 'JPN', 'MULTI3', 'NTSC', 'PAL', 'DLC', 'GOTY', 'Region.Free', 'XBLA'];
$sep = ['.', '.', '.', ' ', '_', '-'];
$templates = [
    static fn () => [$pick($shows), sprintf('S%02dE%02d', mt_rand(1, 20), mt_rand(1, 30)), $pick($res), $pick($src), $pick($hdr), $pick($vc)],
    static fn () => [$pick($shows), sprintf('S%02dE%02d', mt_rand(1, 20), mt_rand(1, 30)), $pick(['2160p', '4K', 'UHD']), $pick(['AMZN', 'ATVP', 'DSNP', 'HMAX', 'NF', 'PCOK']), $pick(['WEB-DL', 'WEBRip']), $pick($hdr), $pick(['HEVC', 'x265', 'H.265'])],
    static fn () => [$pick($shows), sprintf('S%02dE%02d', mt_rand(1, 20), mt_rand(1, 30)), $pick(['1080p', '720p']), $pick(['AMZN', 'NF', 'HULU', 'STAN']), $pick(['WEB-DL', 'WEBRip'])],
    static fn () => [$pick($shows), sprintf('S%02dE%02d', mt_rand(1, 20), mt_rand(1, 30)), $pick($src), $pick($vc)],
    static fn () => [$pick($shows), sprintf('%dx%02d', mt_rand(1, 20), mt_rand(1, 30)), $pick($res), $pick($src), $pick($vc)],
    static fn () => [$pick($shows), sprintf('S%02dE%02d', mt_rand(1, 20), mt_rand(1, 30)), $pick($ac), $pick($src), $pick($res), $pick($vc)],
    static fn () => [$pick($shows), (string) mt_rand(1990, 2025), sprintf('S%02dE%02d', mt_rand(1, 20), mt_rand(1, 30)), $pick($src)],
    static fn () => [$pick($shows), sprintf('S%02dE%02d', mt_rand(1, 20), mt_rand(1, 30)), 'Something', (string) mt_rand(1990, 2025), $pick($vc)],
    static fn () => [$pick($shows), sprintf('%04d', mt_rand(1990, 2025)), sprintf('%02d', mt_rand(1, 12)), sprintf('%02d', mt_rand(1, 28)), $pick(['720p', '1080p', '2160p', 'HDTV', 'WEB-DL', 'WEBRip']), $pick($grp)],
    static fn () => [$pick(['UFC', 'WWE', 'NBA', 'F1', 'Formula 1', 'MotoGP', 'NFL', 'NHL', 'MLB', 'Boxing', 'IndyCar', 'NNS', 'NSCS']), sprintf('%04d', mt_rand(1990, 2025)), sprintf('%02d', mt_rand(1, 12)), sprintf('%02d', mt_rand(1, 28)), $pick(['UFC', 'WWE', 'NBA', 'F1', 'MotoGP', 'NFL', 'Boxing']), (string) mt_rand(1990, 2025), $pick($src)],
    static fn () => [$pick($shows), sprintf('S%02d', mt_rand(1, 20)), $pick(['COMPLETE', 'FULL']), $pick(['720p', '1080p', '2160p', 'HDTV', 'WEB-DL', 'BluRay']), $pick($grp)],
    static fn () => [$pick($shows), sprintf('%03d', mt_rand(1, 999)), $pick(['480p', '720p', '1080p', '2160p']), $pick(['HEVC', 'x265', 'x264', 'H.264']), '10bit'],
    static fn () => [$pick($shows), (string) mt_rand(1950, 2025), $pick(['2160p', '4K', 'UHD']), $pick($hdr), $pick(['REMUX', 'BluRay', 'WEB-DL', 'UHD.BluRay']), $pick(['HEVC', 'x265', 'AV1']), $pick(['Atmos', 'DTS', 'TrueHD'])],
    static fn () => [$pick($shows), (string) mt_rand(1950, 2025), $pick(['2160p', '4K']), $pick(['REMUX', 'Complete.UHD']), $pick(['HEVC', 'x265'])],
    static fn () => [$pick($shows), (string) mt_rand(1950, 2025), $pick(['2160p', '4K']), $pick(['AMZN', 'NF', 'VUDU']), $pick(['WEB-DL', 'WEBRip']), $pick(['HDR10+', 'DV']), $pick(['HEVC', 'x265'])],
    static fn () => [$pick($shows), (string) mt_rand(1950, 2025), $pick(['1080p', '720p']), $pick(['AMZN', 'NF', 'ROKU']), $pick(['WEB-DL', 'WEBRip']), $pick(['x264', 'H.264', 'HEVC'])],
    static fn () => [$pick($shows), (string) mt_rand(1950, 2025), $pick(['1080p', '720p', '480p']), $pick($vc)],
    static fn () => [$pick($shows), (string) mt_rand(1950, 2025), $pick($src), $pick($vc), $pick($res)],
    static fn () => [$pick($shows), (string) mt_rand(1950, 2025), $pick($src), $pick($vc), $pick($ac)],
    static fn () => [$pick($shows), $pick($res), $pick($src), $pick($ac), $pick($vc)],
    static fn () => [$pick($shows), $pick($res), $pick($ac), 'Extra', $pick($src), (string) mt_rand(1950, 2025)],
    static fn () => [$pick($shows), $pick($lang), (string) mt_rand(1950, 2025), $pick($ac), $pick($src)],
    static fn () => [$pick($shows), (string) mt_rand(1950, 2025), $pick($res), $pick($src)],
    static fn () => [$pick($shows), $pick($plat)],
    static fn () => [$pick($shows), $pick($plat), $pick(['INTERNAL', 'PROPER', 'READNFO', 'READ.NFO', 'MULTI5'])],
    static fn () => [$pick($shows), $pick($region), $pick(['DLC.Complete', 'FRENCH', 'GERMAN', 'PROPER', 'PSN', 'UMD', 'READ.NFO']), $pick($plat)],
    static fn () => [$pick($shows), $pick($plat), $pick(['CODEX', 'DUPLEX', 'PLAZA', 'SKIDROW', 'RELOADED', 'CPY', 'EMPRESS', 'RAZOR1911', 'HOODLUM', 'DARKSiDERS', 'FLT', 'TiNYiSO', 'SKY', 'SWAG'])],
    static fn () => [$pick($shows), $pick(['PC', 'WIN64', 'MAC', 'MACOSX', 'LINUX']), $pick(['CODEX', 'SKIDROW', 'RELOADED', 'GOG', 'PROPHET', 'P2P', 'SiMPLEX', 'rG'])],
    static fn () => [$pick($shows), $pick(['DLC', 'Update', 'Patch', 'Hotfix']), $pick(['v1.2', '1.2.3', '7', '']), $pick(['CODEX', 'SKIDROW', 'PLAZA', 'GOG', 'P2P', 'TiNYiSO'])],
    static fn () => [$pick($shows), 'GOG', $pick(['Classic', 'Galaxy', ''])],
    static fn () => [$pick($shows), $pick(['REPACK', 'RIP']), $pick(['FitGirl', 'DODI', 'xatab', 'R.G.', 'Mechanics'])],
    static fn () => [$pick($shows), 'v'.mt_rand(1, 9).'.'.mt_rand(0, 9), $pick(['Linux', 'UNIX', 'MacOS']), $pick(['RPM', 'DEB', '']), $pick(['X64', 'X86', 'ARM64', '']), $pick(['Incl', 'With', '']), $pick(['Keygen', 'Patch', 'Crack', 'Serial', 'License'])],
    static fn () => [$pick($shows), 'v'.mt_rand(1, 9).'.'.mt_rand(0, 9), $pick(['WinAll', 'win32', 'win64', 'x64', 'x86']), $pick(['freeware', 'portable', 'repack', ''])],
    static fn () => [$pick($shows), $pick(['MacOS', 'Mac OS X', 'OSX']), $pick(['Incl', 'Multilingual', 'x64'])],
    static fn () => [$pick($shows), $pick(['Linux', 'Ubuntu', 'Debian', 'CentOS', 'RHEL', 'Fedora']), $pick(['x64', 'x86', 'arm64', ''])],
    static fn () => [$pick($shows), (string) mt_rand(2000, 2030), 'v'.mt_rand(1, 30).'.0', $pick(['Multilingual', 'MULTi', 'Portable', 'Repack', 'Cracked', 'Pro'])],
    static fn () => [$pick($shows), 'v'.mt_rand(1, 9).'.'.mt_rand(0, 9).'.'.mt_rand(0, 9), $pick(['Multilingual', 'MULTi', 'Portable', 'Repack', 'Cracked'])],
    static fn () => [$pick($shows), $pick(['Premiere', 'Illustrator', 'Lightroom', 'After Effects', 'InDesign']), $pick(['2024', 'CC']), 'x64'],
    static fn () => [$pick(['Microsoft', 'Office', 'Windows', 'Visual Studio']), (string) mt_rand(10, 2030), 'Pro', 'Plus'],
    static fn () => [$pick($shows), $pick(['OUTLAWS', 'ALiAS', 'outlaws'])],
];
$synthetic = [];
for ($i = 0; $i < 2600; $i++) {
    $parts = array_values(array_filter($templates[$i % count($templates)](), static fn (string $p): bool => $p !== ''));
    $joiner = $pick($sep);
    $name = implode($joiner, $parts);
    $tail = mt_rand(0, 9);
    if ($tail < 5) {
        $name .= '-'.$pick($grp);
    }
    $style = mt_rand(0, 11);
    $name = match (true) {
        $style === 0 => strtolower($name),
        $style === 1 => strtoupper($name),
        $style === 2 => $name.'.mkv',
        $style === 3 => 'D:\\dl\\'.$name.'.avi',
        $style === 4 => $name."\n",
        $style === 5 => 'prefix words '.$name.' trailing words',
        $style === 6 => "line one\n".$name."\nline three",
        default => $name,
    };
    $synthetic[$name] = true;
}
foreach (array_keys($synthetic) as $name) {
    $names[$name] = true;
}
$names = array_values(array_filter(array_map('strval', array_keys($names)), static fn (string $n): bool => mb_check_encoding($n, 'UTF-8')));

foreach ($names as $name) {
    $row = ['k' => 'name', 'n' => $name,
        'check' => $result($checker->check((object) [], $name)),
        'file' => $result($files->extractFromFile($name)),
        'crc' => $prioritizer->getCrcPriority($name),
    ];
    $emit($row);
    if (crc32($name) % 6 === 0 && strlen($name) < 150) {
        $emit(['k' => 'ident', 'n' => $name, 'key' => PredbIdentityKey::normalize($name), 'parse' => $matcher->parse($name)]);
    }
}

// ---------------------------------------------------------------------------------------
// File lists for the prioritizers.
// ---------------------------------------------------------------------------------------
$lists = [
    ['Film ;-)/VIDEO_TS/VIDEO_TS.VOB', 'Film ;-)/VIDEO_TS/VTS_01_1.VOB', 'Film ;-)/1.jpg', 'Film ;-)/cover the fisher king.jpg', 'Film ;-)/Extreem Online Contact.url', 'Film ;-)/release.nfo'],
    ['The Illusionist (2006)\\VIDEO_TS\\VIDEO_TS.BUP', 'The Illusionist (2006)\\VIDEO_TS\\VTS_01_0.IFO'],
    ['a.mkv', 'bb.mkv', 'ccc.AVI', 'dddd.mp4', 'e.nfo', 'f.srr', 'g.SRS', 'h.rar', 'ii.rar', 'j.part01.rar', 'k.part02.rar', 'l.part001.rar', 'm.r00', 'n.sample.mkv', 'o.txt', 'p.TS', 'q.m2ts', 'r.wmv', 's.divx', 't.m4v', 'u.sub.idx', 'v-proof.jpg', 'w.thumbs.db', 'x.subs.rar', 'y.sfv', 'z'],
    ['ab.mkv', 'cd.mkv', 'ef.mkv', 'g.rar', 'h.rar', 'ij.rar', 'ZZ.NFO', 'aa.nfo'],
    ['d\\VIDEO_TS\\VIDEO_TS.VOB', 'd\\a.mkv', 'd/b.MKV', 'x\\1.jpg', 'x\\123.jpg', 'x\\1234.jpg', 'x\\.url', 'x\\y.URL', 'x\\Audio_TS.ifo', 'x\\vts_12_34.vob', 'x\\vts-1-2.vob'],
    [], ['single.mkv'], ["a.mkv\n", "b.rar\n", "c.nfo\n"],
];
mt_srand(7);
$pool = array_values(array_filter($names, static fn (string $n): bool => strlen($n) < 120 && ! str_contains($n, "\n")));
$suffixes = ['.mkv', '.rar', '.part01.rar', '.part02.rar', '.nfo', '.srr', '.r00', '.sample.mkv', '.avi', '.par2', '.sfv', '.nzb', '.7z', '.vol01+02.par2', '.ts', '', '.url', '.jpg'];
for ($i = 0; $i < 160; $i++) {
    $list = [];
    for ($j = 0, $n = mt_rand(1, 14); $j < $n; $j++) {
        $list[] = $pool[mt_rand(0, count($pool) - 1)].$suffixes[mt_rand(0, count($suffixes) - 1)];
    }
    $lists[] = $list;
}
foreach ($lists as $list) {
    $emit(['k' => 'files', 'files' => $list, 'pm' => $prioritizer->prioritizeForMatching($list),
        'pp' => $prioritizer->prioritizeForPreDb($list), 'crc' => array_map($prioritizer->getCrcPriority(...), $list)]);
}

// ---------------------------------------------------------------------------------------
// Donors.
// ---------------------------------------------------------------------------------------
$donorJson = static fn (?object $d): ?array => $d === null ? null : get_object_vars($d);
$cases = [
    [[['releases_id' => 20, 'relsize' => 950], ['releases_id' => 10, 'relsize' => 1050], ['releases_id' => 30, 'relsize' => 900]], 1000, 10],
    [[['releases_id' => 10, 'relsize' => 0], ['releases_id' => 20, 'relsize' => 500]], 1000, 10],
    [[['releases_id' => 30, 'relsize' => 1000]], 0, 10],
    [[['releases_id' => 30, 'relsize' => 1000]], -5, 10],
    [[], 1000, 10],
    [[['releases_id' => 1, 'relsize' => 1000], ['releases_id' => 2, 'relsize' => 1000]], 1000, 0],
    [[['releases_id' => 2, 'relsize' => 1000], ['releases_id' => 1, 'relsize' => 1000]], 1000, 0],
    [[['id' => 5, 'size' => 1000], ['releases_id' => 4, 'size' => 1000]], 1000, 5],
    [[['relsize' => 1000], ['id' => 9, 'relsize' => 1000]], 1000, 5],
    [[['relsize' => 1000, 'size' => 0], ['releases_id' => 3, 'relsize' => null, 'size' => 1000]], 1000, 5],
    [[['releases_id' => 1, 'relsize' => 1050], ['releases_id' => 2, 'relsize' => 1049]], 1000, 5],
    [[['releases_id' => 1, 'relsize' => 105], ['releases_id' => 2, 'relsize' => 100]], 100, 5],
    [[['releases_id' => 1, 'relsize' => 100], ['releases_id' => 2, 'relsize' => 105]], 100, 5],
    [[['releases_id' => 1, 'relsize' => 3], ['releases_id' => 2, 'relsize' => 7]], 2, 34],
    [[['releases_id' => 1, 'relsize' => -100]], 100, 5],
];
for ($i = 0; $i < 200; $i++) {
    $set = [];
    for ($j = 0, $n = mt_rand(1, 6); $j < $n; $j++) {
        $d = [];
        if (mt_rand(0, 9) > 0) {
            $d['releases_id'] = mt_rand(1, 12);
        }
        if (mt_rand(0, 4) === 0) {
            $d['id'] = mt_rand(1, 12);
        }
        if (mt_rand(0, 9) > 1) {
            $d['relsize'] = mt_rand(0, 3) === 0 ? null : mt_rand(0, 30) * 100;
        }
        if (mt_rand(0, 3) === 0) {
            $d['size'] = mt_rand(0, 30) * 100;
        }
        $set[] = $d;
    }
    $cases[] = [$set, mt_rand(0, 3000), [0, 1, 5, 10, 50][mt_rand(0, 4)]];
}
foreach ($cases as [$set, $target, $tolerance]) {
    $objects = array_map(static fn (array $d): object => (object) $d, $set);
    $picked = $donors->select($objects, $target, $tolerance);
    $index = $picked === null ? null : array_search($picked, $objects, true);
    $emit(['k' => 'donor', 'donors' => $set, 'target' => $target, 'tolerance' => $tolerance, 'sel' => $index]);
}
$ok = static fn (int $id, int $pre, string $t, ?string $s = null): array => ['releases_id' => $id, 'predb_id' => $pre, 'canonical_title' => $t, 'searchname' => $s ?? $t];
$canonical = [
    [$ok(5, 1, 'Some.Movie.2020.1080p-GRP'), $ok(3, 1, 'Some.Movie.2020.1080p-GRP', 'some.movie.2020.1080p-grp')],
    [$ok(5, 1, 'Some.Movie.2020.1080p-GRP'), $ok(3, 2, 'Some.Movie.2020.1080p-GRP')],
    [$ok(5, 1, 'Some.Movie.2020.1080p-GRP'), $ok(3, 1, 'Some.Movie.2020.1080p-GRX')],
    [$ok(5, 1, 'Some.Movie.2020.1080p-GRP'), $ok(3, 1, 'Other.Movie.2020.1080p-GRP')],
    [$ok(5, 0, 'Some.Movie.2020.1080p-GRP')], [$ok(5, -1, 'Some.Movie.2020.1080p-GRP')],
    [$ok(5, 1, '   ', '   ')], [$ok(5, 1, '', '')], [$ok(5, 1, "Tab\tTitle", "Tab\tTitle")], [$ok(5, 1, "Nul\0Title", "Nul\0Title")],
    [$ok(5, 1, "Zero\u{200B}Width", "Zero\u{200B}Width")], [$ok(5, 1, str_repeat('a', 255))], [$ok(5, 1, str_repeat('a', 256))],
    [$ok(5, 1, str_repeat('é', 255))], [$ok(5, 1, str_repeat('é', 256))],
    [$ok(5, 1, 'Straße.2020-GRP', 'STRASSE.2020-GRP')], [$ok(5, 1, 'Straße.2020-GRP', 'STRAßE.2020-GRP')], [$ok(5, 1, 'ΟΔΟΣ.2020-GRP', 'οδος.2020-GRP')],
    [$ok(5, 1, 'İstanbul.2020-GRP', 'i̇stanbul.2020-GRP')],
    [['releases_id' => 5, 'predb_id' => 1, 'canonical_title' => 'A.B-C']], [['releases_id' => 5, 'predb_id' => 1, 'canonical_title' => 'A.B-C', 'searchname' => null]],
    [['releases_id' => 5, 'predb_id' => 1, 'searchname' => 'A.B-C']], [['predb_id' => 1, 'canonical_title' => 'A.B-C', 'searchname' => 'a.b-c']],
    [$ok(5, 1, 'A.B-C'), ['predb_id' => 1, 'canonical_title' => 'A.B-C', 'searchname' => 'a.b-c']],
    [],
];
$many = [];
for ($i = 0; $i < 256; $i++) {
    $many[] = $ok(1000 - $i, 9, 'Many.Donors.2020-GRP');
}
$canonical[] = $many;
$canonical[] = array_merge($many, [$ok(1, 9, 'Many.Donors.2020-GRP')]);
foreach ($canonical as $set) {
    $objects = array_map(static fn (array $d): object => (object) $d, $set);
    $emit(['k' => 'canonical', 'donors' => $set, 'sel' => $donorJson($donors->selectCanonical($objects))]);
}

// ---------------------------------------------------------------------------------------
// Constrained PreDB matching and identity keys.
// ---------------------------------------------------------------------------------------
$queries = [
    ['kazata.kara.s1e2', [['id' => 1, 'title' => 'Kanata.Kara.S01E02.1080p.AMZN.WEB-DL.JPN.DDP2.0.H.264-ToonsHub']]],
    ['Kazata.Kara.S01E02.1080p.AMZN.WEB-DL.JPN.DDP2.0.H.264-ToonsHub', [['id' => 1, 'title' => 'Kanata.Kara.S01E02.1080p.AMZN.WEB-DL.JPN.DDP2.0.H.264-ToonsHub']]],
    ['Known.Movie.2026', [['id' => 1, 'title' => 'Known.Movie.2026.1080p.WEB-DL-GRP'], ['id' => 2, 'title' => 'Known.Movie.2026.720p.WEB-DL-GRP']]],
    ['Known.Movie.2026', [['id' => 1, 'title' => 'Known.Movie.2026.1080p.WEB-DL-GRP']]],
    ['Known.Movie.2026', array_fill(0, 51, ['id' => 1, 'title' => 'Known.Movie.2026'])],
    ['Known.Movie.2026', array_fill(0, 50, ['id' => 1, 'title' => 'Known.Movie.2026'])],
    ['Known.Movie.2.2026', [['id' => 1, 'title' => 'Known.Movie.3.2026']]],
    ['Known.Sample.Movie.2026.1080p.WEB-DL-GRP', [['id' => 1, 'title' => 'Known.Movie.2026.1080p.WEB-DL-GRP']]],
    ['Known.Cover.Movie.2026.1080p.WEB-DL-GRP', [['id' => 1, 'title' => 'Known.Movie.2026.1080p.WEB-DL-GRP']]],
    ['Known.Movie.2026', [['id' => 0, 'title' => 'Known.Movie.2026'], ['id' => -3, 'title' => 'Known.Movie.2026'], ['id' => 4, 'title' => 'Known.Movie.2026']]],
    ['Known.Movie.2026', [['id' => 4, 'title' => 'Known.Movie.2026'], ['id' => 4, 'title' => 'Known.Movie.2026.1080p']]],
    ['Great.Long.Title.Words.2020', [['id' => 1, 'title' => 'Great.Long.Title.Wordz.2020'], ['id' => 2, 'title' => 'Great.Long.Title.Words.2020']]],
    ['Greatest.Adventure.Ever.Made.2020', [['id' => 1, 'title' => 'Greatest.Adventure.Ever.Mxxe.2020'], ['id' => 2, 'title' => 'Greatest.Adventurx.Ever.Made.2020']]],
    ['Greatest.Adventure.Ever.Made.2020', [['id' => 1, 'title' => 'Greatesx.Adventure.Ever.Made.2020'], ['id' => 2, 'title' => 'Greatest.Adventurx.Evex.Made.2020']]],
    ['Greatest.Adventure.Ever.Made.2020', [['id' => 1, 'title' => 'Greatest.Adventurx.Evex.Madx.2020']]],
    ['Greatest.Adventurez.2020', [['id' => 1, 'title' => 'Greatest.Adventure.2020']]],
    ['Greatest.Adventure.2020', [['id' => 1, 'title' => 'Greatest.Adventure.2020'], ['id' => 2, 'title' => 'Greatest.Adventure.2020'], ['id' => 3, 'title' => 'Greatest.Adventuree.2020']]],
    ['Greatest.Adventure.2020.EXTENDED', [['id' => 1, 'title' => 'Greatest.Adventure.2020']]],
    ['Greatest.Adventure.2020', [['id' => 1, 'title' => 'Greatest.Adventure.2020.EXTENDED']]],
    ['Greatest.Adventure.2020.GERMAN', [['id' => 1, 'title' => 'Greatest.Adventure.2020.GERMAN.1080p.BluRay.x264-GRP']]],
    ['Greatest.Adventure.2020.1080p.BluRay.x264-GRP', [['id' => 1, 'title' => 'Greatest.Adventure.2020.1080p.BluRay.x264-GRP']]],
    ['Greatest.Adventure.2020.1080p.BluRay.x264-GRP.mkv', [['id' => 1, 'title' => 'Greatest Adventure 2020 1080p BluRay x264-GRP']]],
    ['Greatest.Adventure.S01E02.720p.HDTV.x264-GRP', [['id' => 1, 'title' => 'Greatest.Adventure.S1E2.720p.HDTV.x264-GRP']]],
    ['Greatest.Adventure.S01E02E03.720p.HDTV.x264-GRP', [['id' => 1, 'title' => 'Greatest.Adventure.S01E02E03.720p.HDTV.x264-GRP']]],
    ['Greatest.Adventure.S01E002.720p.HDTV.x264-GRP', [['id' => 1, 'title' => 'Greatest.Adventure.S01E02.720p.HDTV.x264-GRP']]],
    ['Greatest.Adventure.S00E00.720p.HDTV.x264-GRP', [['id' => 1, 'title' => 'Greatest.Adventure.S0E0.720p.HDTV.x264-GRP']]],
    ['The.Greatest.Adventure.Of.All.2020.XXX.1080p', [['id' => 1, 'title' => 'Greatest.Adventure.All.2020.XXX.1080p']]],
    ['Greatest.Adventure.2020.2021', [['id' => 1, 'title' => 'Greatest.Adventure.2020.2021']]],
    ['Ünïcode.Tïtle.Wörds.2020', [['id' => 1, 'title' => 'Ünïcode.Tïtle.Wörds.2020']]],
    ['Ünïcode.Tïtle.Wörds.2020', [['id' => 1, 'title' => 'Unicode.Title.Words.2020']]],
    ["Cafe\u{0301}.Society.Movie.2020", [['id' => 1, 'title' => "Caf\u{00e9}.Society.Movie.2020"]]],
    ['日本語の.タイトル.2020', [['id' => 1, 'title' => '日本語の.タイトル.2020']]],
    ['', []], ['   ', [['id' => 1, 'title' => 'x']]], ['2020', [['id' => 1, 'title' => '2020']]],
    [str_repeat('a', 513), []], [str_repeat('a', 512), [['id' => 1, 'title' => str_repeat('a', 512)]]],
    [implode('.', array_fill(0, 65, 'word')).'.2020', []], [implode('.', array_fill(0, 64, 'word')).'.2020', []],
    ["Spaced Name\u{00a0}Here\u{180e}2020", [['id' => 1, 'title' => 'Spaced.Name.Here.2020']]],
];
foreach ($names as $i => $name) {
    if (crc32($name) % 40 !== 0 || strlen($name) > 200 || str_contains($name, "\n")) {
        continue;
    }
    $mutated = preg_replace('/([a-z]{6,})/i', '$1x', $name, 1) ?? $name;
    $queries[] = [$name, [
        ['id' => 11, 'title' => $name], ['id' => 12, 'title' => $mutated], ['id' => 13, 'title' => preg_replace('/(19|20)\d\d/', '1999', $name, 1) ?? $name],
        ['id' => 14, 'title' => $name.'.PROPER'], ['id' => 15, 'title' => $names[($i + 7) % count($names)]],
    ]];
    $queries[] = [$mutated, [['id' => 21, 'title' => $name], ['id' => 22, 'title' => $mutated]]];
    $queries[] = [strtolower((string) preg_replace('/[-.].*$/', '', $name)), [['id' => 31, 'title' => $name]]];
}
foreach ($queries as [$query, $hits]) {
    $emit(['k' => 'constrained', 'q' => $query, 'hits' => $hits,
        'suggest' => $matcher->suggest($query, $hits), 'complete' => $matcher->completeEvidence($query, $hits)]);
}

// ---------------------------------------------------------------------------------------
// NFO texts.
// ---------------------------------------------------------------------------------------
$target = 'Example.Movie.2026.1080p.BluRay.x264-TARGET';
$source = 'Example.Movie.2026.1080p.BluRay.x264-SOURCE';
$show = 'Example.Show.S01E02.1080p.HDTV.x264-TARGET';
$nfos = [
    'Filename : '.$target."\n", 'Source : '.$source."\n", 'Source : '.$source."\nFilename : ".$target."\n", 'Filename : '.$target."\nSource : ".$source."\n",
    'Release : '.$target."\n", "Source :\n    ".$source."\n", '  sOuRcE : '.$source."\r\n", "Source:\n    ".$source."\n",
    "Source :\n    Example.Show.S01E02.1080p.HDTV.x264-SOURCE\n    Filename : ".$show."\n",
    'Source : '.$source."\n\n    ".'Source.Code.Movie.2026.1080p.BluRay.x264-TARGET'."\n",
    "Source :\n    C:\\incoming\\Example.Show.S01E02.1080p.HDTV.x264-SOURCE.mkv\n",
    "Source :\n    C:\\incoming\\Example.Show.S01E02.1080p.HDTV.x264-SOURCE.mkv\n    Filename : D:\\output\\".$show.".mkv\n",
    "Example Movie (2026)\nSource : BluRay\nVideo : 1920x1080\nAudio : DTS\n", 'Source : '.$source."\nRelease : ".$target."\n",
    'Release Name = '.$target."\n", 'File Name: '.$target."\r\n", "Complete name   : D:\\dl\\".$target.".mkv\n", 'Filename:'.$target, "   FILENAME :   {$target}   \nrest\n",
    "Release : not.a.technical.name\nFilename : ".$target."\n", "Filename : ".$target."\nFilename : Other.Movie.2026.720p.BluRay.x264-OTHER\n",
    "Filename : ".$target."\nRelease : ".$target.".mkv\n", "Filename : ".str_repeat('a', 300)."-GRP\n", "Filename : Bad\u{200B}Name.2026.1080p.BluRay.x264-GRP\n",
    "a\rb\r\nc\n\nd", "\n\n", '', "Source", "Source:", "x\nSource : y\n  z\n   Source : w\n    k\nFoo : bar\n    cont\n",
    // TV
    "File : C:\\tv\\Example.Show.S01E02.720p.HDTV.x264-GRP.mkv  \nrest\n", "Name: /mnt/tv/Example Show S01E02 720p.avi \n", ': Example.Show.S01E02.720p  ', ":  Example.Show.S1D02 and more\n",
    "Release Name: Example.Show.S01E02.720p.HDTV.x264-GRP\n", "path: D:\\x\\Show.S12E345.stuff.rar  \n",
    // movies
    ": Some Movie 2010 BDRip XviD-GRP  \n", "Source : Some Movie 2010 BDRip XviD-GRP  \n", "   Some.Movie.2010.DVDRip.XviD-GRP   \n", "x   Source Some.Movie.2010.DVDRip.XviD-GRP   \n",
    "   Some.Movie.2010.NTSC.DVD.MULTi.DVDR-GRP   \n", "   Some Movie NTSC DVD MULTi DVDR GRP \n", "info :  Movie 1999 bluray   remux\n", "info :  Movie 1999 DVD   \n", "info :  Movie 1999 DVDR\n", "info :  Movie 1999 DVDRip\n",
    // music
    "x\n   Some-Station-FM-04-12  \nrest", "   Artist-Radio-FM-12-31", "  -FM-01-02",
    // title (year)
    "Some Movie (2010)\nLanguage: English\nVideo: 1920x1080\nSource: BluRay\nAudio Format: DTS\n", "The Film (1999)\nSprache: Deutsch\nWidth: 1280\nFormat: DVD\nCodec: x264\nAudio: AC3\n",
    "Title (2001)\nLangue: FR\nLargeur: 720\nOriginal Format: AVI\nAudio: Dolby TrueHD\n", "Title (2001)\nIdiomas: Spanish\nVideo Resolution: 1 080\nTV system: PAL\nAudio Codec: 0x0055 MPEG-1 Layer 3\n",
    "Title (2001)\nlang: ES\nframe size: (640\ncodec name: h264\naudio format: A_DTS\n", "Title (2001) pdf\n.pdf here\n", "Audio Book Title (2001)\n", "Title (2001)\nLanguage: Englisch\nres: 1280 @\nvideo format: MPEG-4 Visual\naudio: AC-3\n",
    "Title (2001)\nVideo: 2160\nSource: WEB-DL\nAudio: EAC3\n", "Title (2001)\nresolution: 720x480\nsource: Ripped \nDTS-HD\n", "Title (2001)\nresolution 1920x1080\nsource: DBrip\naudio: DTSHD\n",
    "Title (2001)\nVideo: 272\nSource: CAMRIP\nAudio: AAC LC\n", "Title (2001)\nVideo: 494\nSource: HD\nAudio: FLAC\n", "Title (2001)\nres: 810\nsource: DVD R1 \nCodec: (Divx\n", "Title (2001)\nvideo 816\nSource: bd\nvideo codec: x.264\n",
    "Title (2001)\nvideo 820\nSource: VHSRIP\ncodec: NTSC\n", "Title (2001)\nvideo 1280x720\nSource: BD\nwriting library: x-264\n", "Title (2001)\nwidth: 640\ntype: XVID\n", "Title (2001)\nwidth 2160\nformat: (AC3\n",
    "Title (2001)\nWIDTH 704\nformat DD5.1\n", "Title (2001)\nwidth 688\nformat: DD5\n", "Title (2001)\nwidth 1 920\naudio format: Dolby\n", "Title (2001)\nwidth 3840\naudio: MP3\n",
    "Title (2001)\nlanguage: Latin\nvideo: 1 920x1080 \n", "Title (2001)\nlanguage: Japenese\nres: 336\n", "Title (2001)\nResolution: 528\n", "Title (2001)\nVideo: 608\nsource: VOD\n",
    "Title (2001)\nVideo: 1080\nsource: R5\n", "Title (2001)\nVideo: 1280\nsource: TS\n", "Title (2001)\nVideo: 1920x1080\nsource: PD\n",
    // games
    "ALiAS presents\nSome Game (c) 2010 Publisher Inc\n", "PROPHET\nSome.Game.Name.(C).2010.Studio\n", "ROGUE\nSome Game Name *ISO* by someone\n", "RELOADED\nnothing here\n", "just text\nSome Game (c) Publisher\n",
    "Game Type : Action\nOther Game Title (c) Corp\n", "LARGEISO\nGame *ISO*\n", "MEDIUMISO\n  Game (C) (c) 2011", "FAiRLiGHT\nx (c) y\n",
    // iguana
    "Supplier : IGUANA\nSome Movie Title (2005)\n [*] English\n [*] DTS 5.1\nFormat : DVD9\n[1280x720] Resolution\n",
    "Supplier : IGUANA\n [*] German\n [*] DT 2.0 MONO\nFormat: x264\n[640x480] Resolution\n[1920x1080] Resolution\n", "Supplier IGUANA\n\nnothing\n", "Supplier: IGUANA\nSome Movie (2005) pdf.pdf\n [*] Dutch\n",
    "Supplier: IGUANA\nFormat: H.264\n[1280x.+] Resolution\n", "Supplier: IGUANA\n[640x.+] Resolution\n[1920x.+] Resolution\n",
    "Supplier: IGUANA\n .pdf\n Movie Name (2009)\n [*] French\n [*] DTS 6.1 MONO\nFormat: DVD5\n", "Supplier: IGUANA\n .pdf\n Movie Name (2009)\n [*] Spanish\n [*] DTS 7.0\nFormat: DVDR\n",
    "IGUANA only: .pdf Supplier: x IGUANA\n",
];
// A realistic NFO: ASCII art, long runs of spaces, and a field block.
$art = str_repeat("   ##########################################################################   \n", 12).str_repeat('  .:::.      .:::.      .:::.      '."\n", 30);
$nfos[] = $art."\n  Release Name : $target\n  Source       : $source\n  Size         : 4.3 GB\n  Video        : x264, 1920x1080\n  Audio        : DTS 5.1\n".$art;
$nfos[] = $art."\n  Group : TEST\n   Another.Movie.2012.720p.BluRay.x264-NFO    \n".$art;
$nfos[] = str_repeat("Lorem ipsum dolor sit amet, consectetur adipiscing elit sed do eiusmod tempor incididunt ut labore\n", 400);
$nfos[] = str_repeat('word ', 2000)."\nSome.Release.Name.2020.720p.HDTV.x264-GRP and Another-Release-Name-2021\n";
$nfos[] = implode("\n", array_map(static fn (int $n): string => "Line $n : Some.Release.Number.$n.2020.720p.HDTV.x264-GR$n", range(1, 105)));
$nfos[] = implode("\n", array_map(static fn (int $n): string => "Line $n : Some.Release.Number.$n.2020.720p.HDTV.x264-GR$n", range(1, 100)));
$nfos[] = implode("\n", array_map(static fn (int $n): string => "Filename : Some.Release.Number.$n.2020.720p.HDTV.x264-GR$n", range(1, 101)));
$nfos[] = "Filename: Mixed.CASE.Name.2020.720p.HDTV.x264-GRP\nFilename: mixed.case.name.2020.720p.hdtv.x264-grp\nRelease: Other.Name.2020.720p.HDTV.x264-GRP\n";
$nfos[] = "Release: ".str_repeat('a', 250).".2020.720p.x264-GRP\nRelease: x\u{0007}y.2020.720p.x264-GRP\nSome Name Here-GRP and a.b-c\n";
$tpl = [
    static fn (string $n): string => "Filename : $n\n",
    static fn (string $n): string => "Release : $n.mkv\nSource : $n\n",
    static fn (string $n): string => "   $n   \n   Another line\n",
    static fn (string $n): string => ": $n  \n",
    static fn (string $n): string => "Path : C:\\dl\\$n.mkv  \nSize: 1 GB\n",
    static fn (string $n): string => "Complete name : /dl/$n/$n.mkv\nFormat : Matroska\n",
    static fn (string $n): string => "$n (2010)\nLanguage: English\nVideo: 1920x1080\nSource: BluRay\nAudio: DTS\n",
    static fn (string $n): string => "Some title\n   $n-GRP\n",
    static fn (string $n): string => "Supplier: IGUANA\n$n (2010)\n [*] French\n [*] DTS 5.1 MONO\n",
    static fn (string $n): string => "ALiAS\n$n (c) 2010\n",
];
foreach ($names as $name) {
    if (crc32($name) % 11 !== 0 || strlen($name) > 150 || str_contains($name, "\n")) {
        continue;
    }
    $nfos[] = $tpl[crc32($name) % count($tpl)]($name);
}
foreach ($nfos as $text) {
    $identity = $nfo->identityText($text);
    $view = $nfo->predbCandidates($text);
    $emit(['k' => 'nfo', 'n' => $text, 'id' => $identity, 'pc' => $view,
        'nfo' => $result($nfo->extractFromNfo($text)), 'tv' => $result($nfo->checkForTv($text)), 'movie' => $result($nfo->checkForMovie($text)),
        'music' => $result($nfo->checkForMusic($text)), 'ty' => $result($nfo->checkForTitleYear($text)), 'game' => $result($nfo->checkForGame($text)),
        'ig' => $result($nfo->checkForIguana($text)), 'chk' => $result($checker->check((object) [], $identity))]);
}

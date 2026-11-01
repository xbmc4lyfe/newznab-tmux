<?php

// Writes cleaning_golden.json: the built-in release name patterns in ReleaseCleaningService, which
// need no database. Run from anywhere with PHP 8.5:
//   php -d display_errors=stderr generate_cleaning_golden.php > cleaning_golden.json

declare(strict_types=1);

require getenv('HOME').'/git.xbmc4lyfe/newznab-tmux-php-ref/vendor/autoload.php';

use App\Services\CollectionsCleaningService;
use App\Services\NameFixing\NzbSplitUnwrapper;
use App\Services\ReleaseCleaningService;

$class = new ReflectionClass(ReleaseCleaningService::class);
$service = $class->newInstanceWithoutConstructor();
$service->e0 = CollectionsCleaningService::REGEX_FILE_EXTENSIONS;
$service->e1 = CollectionsCleaningService::REGEX_FILE_EXTENSIONS.CollectionsCleaningService::REGEX_END;
$service->e2 = CollectionsCleaningService::REGEX_FILE_EXTENSIONS.CollectionsCleaningService::REGEX_SUBJECT_SIZE.CollectionsCleaningService::REGEX_END;
$class->getProperty('nzbSplitUnwrapper')->setValue($service, new NzbSplitUnwrapper);

$subjects = [
    '<TOWN><www.town.ag > <download all our files with>>> www.ssl-news.info <<< > [05/87] - "Deep.Black.Ass.5.XXX.1080p.WEBRip.x264-TBP.part03.rar" - 7,87 GB yEnc',
    '<TOWN><www.town.ag > <partner of www.ssl-news.info > [02/24] - "Dragons.Den.UK.S11E02.HDTV.x264-ANGELiC.nfo" - 288,96 MB yEnc',
    '<TOWN><www.town.ag > <SSL - News.Info> [6/6] - "TTT.Magazine.2013.08.vol0+1.par2" - 33,47 MB yEnc',
    '[ TOWN ]-[ www.town.ag ]-[ partner of www.ssl-news.info ]-[ 1080p ] - [320/352] - "Gq7YGEWLy8wAA2NhbZx5LukEa.vol000+5.par2" - 17.09 GB yEnc',
    '<TOWN><www.town.ag > <download all our files with>>> www.ssl-news.info <<< >IP Scanner Pro 3.21-Sebaro - [1/3] - "IP Scanner Pro 3.21-Sebaro.rar" yEnc',
    '(05/10) -<TOWN><www.town.ag > <partner of www.ssl-news.info > - "D.Olivier.Wer Boeses.saet-gsx-.part4.rar" - 741,51 kB - yEnc',
    '[ TOWN ]-[ www.town.ag ]-[ partner of www.ssl-news.info ]-[ MOVIE ] [14/19] - "Night.Vision.2011.DVDRip.x264-IGUANA.part12.rar" - 660,80 MB yEnc',
    '[ TOWN ]-[ www.town.ag ]-[ partner of www.ssl-news.info ]-[ MOVIE ] [01/84] - "The.Butterfly.Effect.2.2006.1080p.BluRay.x264-LCHD.par2" - 7,49 GB yEnc',
    '[ TOWN ]-[ www.town.ag ]-[ partner of www.ssl-news.info ] [22/22] - "Arsenio.Hall.2013.09.11.Magic.Johnson.720p.HDTV.x264-2HD.vol31+11.par2" - 1,45 GB yEnc',
    '[ TOWN ]-[ www.town.ag ]-[ partner of www.ssl-news.info ] [01/28] - "Arsenio.Hall.2013.09.18.Dr.Phil.McGraw.HDTV.x264-2HD.par2" - 352,58 MB yEnc',
    '4675.-.Wedding.Planner.multi3.(EU) <TOWN><www.town.ag > <partner of www.ssl-news.info > <Games-NDS >  [01/10] - "4675.-.Wedding.Planner.multi3.(EU).par2" - 72,80 MB - yEnc',
    '4675.-.Wedding.Planner.multi3.(EU) <TOWN><www.town.ag > <partner of www.ssl-news.info > <Games-NDS >  [01/10] - "4675.-.Wedding.Planner.multi3.(EU).par2" - 72,80 MB',
    'Marco.Fehr.-.In.the.Mix.at.Der.Club-09-01-SAT-2012-XDS <TOWN><www.town.ag > <partner of www.ssl-news.info >  [01/13] - "Marco.Fehr.-.In.the.Mix.at.Der.Club-09-01-SAT-2012-XDS.par2" - 92,12 MB - yEnc',
    'Marco.Fehr.-.In.the.Mix.at.Der.Club-09-01-SAT-2012-XDS <TOWN><www.town.ag > <partner of www.ssl-news.info >  [01/13] - "Marco.Fehr.-.In.the.Mix.at.Der.Club-09-01-SAT-2012-XDS.par2" - 92,12 MB',
    '<TOWN><www.town.ag > <partner of www.ssl-news.info > JetBrains.IntelliJ.IDEA.v11.1.4.Ultimate.Edition.MacOSX.Incl.Keymaker-EMBRACE  [01/18] - "JetBrains.IntelliJ.IDEA.v11.1.4.Ultimate.Edition.MacOSX.Incl.Keymaker-EMBRACE.par2" - 200,77 MB - yEnc',
    '<TOWN><www.town.ag > <partner of www.ssl-news.info > JetBrains.IntelliJ.IDEA.v11.1.4.Ultimate.Edition.MacOSX.Incl.Keymaker-EMBRACE  [01/18] - "JetBrains.IntelliJ.IDEA.v11.1.4.Ultimate.Edition.MacOSX.Incl.Keymaker-EMBRACE.par2" - 200,77 MB',
    '<TOWN><www.town.ag > <partner of www.ssl-news.info > [01/18] - "2012-11.-.Supurbia.-.Volume.Tw o.Digital-1920.K6-Empire.par2" - 421,98 MB yEnc',
    '<TOWN> www.town.ag > sponsored by www.ssl-news.info > (1/3) "HolzWerken_40.par2" - 43,89 MB - yEnc',
    '(1/9)<<<www.town.ag>>> sponsored by ssl-news.info<<<[HorribleSubs]_AIURA_-_01_[480p].mkv "[HorribleSubs]_AIURA_-_01_[480p].par2" yEnc',
    '[ TOWN ]-[ www.town.ag ]-[ Assassins.Creed.IV.Black.Flag.XBOX360-COMPLEX ]-[ partner of www.ssl-news.info ] [074/195]- "complex-ac4.bf.d1.r71" yEnc',
    '(TOWN)(www.town.ag ) (partner of www.ssl-news.info ) Twinz-Conversation-CD-FLAC-1995-CUSTODES  [01/23] - #34;Twinz-Conversation-CD-FLAC-1995-CUSTODES.par2#34; - 266,00 MB - yEnc',
    '<TOWN><www.town.ag > <partner of www.ssl-news.info > Greek.S04E06.Katerstimmung.German.DL.Dubbed.WEB-DL.XviD-GEZ  [01/22] - "Greek.S04E06.Katerstimmung.German.DL.Dubbed.WEB-DL.XviD-GEZ.par2" - 526,99 MB - yEnc',
    '[ TOWN ]-[ www.town.ag ]-[ ANIME ] [01/17] - "[Chyuu] Nanatsu no Taizai - 12 [720p][D1F49539].par2" - 585,03 MB yEnc',
    '<TOWN> nothing here at www.town.ag',
    '[140022]-[04] - [01/40] - "140022-04.nfo" yEnc',
    '[01/10] - "Some.Release.Name.2019.1080p.WEB-DL-GRP.mkv" yEnc',
    '[01/10] - "Some.Release.Name.2019.1080p.WEB-DL-GRP.part01.rar" - 1,00 GB yEnc',
    '[ 1 / 10 ] #34;Other.Release.S01E02.720p-GRP.vol01+02.par2#34; yEnc',
    '[01/10] - "abcdefghijklmnopqrstuv.bin" yEnc',
    '[01/10] - "Scrambled.Release.2019.1080p-GRP.mkv" lRap',
    '[01/10] - "Scrambled.Release.2019.1080p-GRP.mkv" - (1/5) lRap (1/5)',
    '[12345]-[FULL]-[#a.b.teevee@EFNet]-[ Show.Name.S01E01.720p.HDTV.x264-GRP ]-[01/20] - "show.r00" yEnc',
    '[12345]-[#a.b.moovee@EFNet]-[FULL]-[ Movie.Name.2019.720p.BluRay.x264-GRP ] [01/20] - "movie.nfo" yEnc',
    'plain subject   with  spaces - yEnc',
    'plain subject yEnc',
    "Caf\xC3\xA9 subject   yEnc",
    '',
];
$files = ['Some.Release.Name.2019.1080p.WEB-DL-GRP.mkv', 'path/to/Annual.Report.Final.pdf', 'C:\\x\\Show.S01E02.720p-GRP.part01.rar',
    'Movie.2019.1080p-GRP-sample.mkv', 'Movie 2019  1080p GRP.mkv', 'abcdefghijklmnopqrstuv.bin', 'readme.txt', '.hidden', "Caf\xC3\xA9.2019.1080p-GRP.mkv", ''];

$built = [];
foreach ($subjects as $subject) {
    foreach (['alt.binaries.test', 'alt.binaries.teevee'] as $group) {
        $service->subject = $subject;
        $service->groupName = $group;
        $result = preg_match('/www\.town\.ag$/i', $subject) ? $service->generic_town()
            : ($group === 'alt.binaries.teevee' ? $service->teevee() : $service->generic());
        $built[] = ['subject' => $subject, 'group' => $group, 'text' => is_string($result) ? $result : null,
            'name' => is_array($result) ? $result['cleansubject'] : null];
    }
}
$names = [];
foreach ($files as $file) {
    $names[] = ['file' => $file, 'name' => $service->releaseNameFromFile($file)];
}
$helpers = [];
foreach ($subjects as $subject) {
    $helpers[] = ['subject' => $subject, 'clean' => $service->releaseCleanerHelper($subject)];
}
echo json_encode(['built_in' => $built, 'files' => $names, 'helpers' => $helpers], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), "\n";

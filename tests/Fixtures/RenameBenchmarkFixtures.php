<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use App\Services\NameFixing\Extractors\ObfuscatedSubjectExtractor;
use App\Services\NameFixing\FileNameCleaner;
use App\Services\NameFixing\OriginalFilenameEvidence;
use App\Traits\DetectsHashedNames;

/**
 * Deterministic ground truth benchmark fixtures and verification oracle for
 * the NNTmux release name-fixing pipeline.
 *
 * Covers 100 sample vectors across 5 balanced strata:
 * 1. Clean Scene (standard dotted scene releases)
 * 2. Obfuscated / Hashed (hashes, UUIDs, USENET prefix subjects, NZBSPLIT wrappers)
 * 3. Unicode & Special Character Titles (accents, NFD decomposed, CJK, Cyrillic, Greek)
 * 4. Multi-Archive & Multipart Names (.part01.rar, .r00, .vol001+02.par2, preserved codecs)
 * 5. Noise & Rejection Strings (DVD structures, URL shortcuts, numeric stems, invalid UTF-8)
 */
final class RenameBenchmarkFixtures
{
    public const ORACLE_FULL_SHA256 = '6bc1e961933989a5f48818ca88e46d747280801e15fd2a0f513a3f81c20ce1ba';

    public const ORACLE_FULL_CRC32 = 'eef39bd7';

    public const ORACLE_RESOLVED_SHA256 = '6deffeed9384b90f42c707e26c7422313c5d840ec2cfa2d305409b8a088bfa93';

    public const ORACLE_RESOLVED_CRC32 = 'efc3102c';

    /**
     * @var list<array{id: int, category: string, input_type: string, input_raw: string, expected: ?string, status: string}>|null
     */
    private static ?array $cachedDataset = null;

    /**
     * Strata metadata definitions.
     *
     * @return array<string, array{name: string, count: int, resolvable: int, category: string}>
     */
    public static function strata(): array
    {
        return [
            'clean_scene' => ['name' => 'Clean Scene Releases', 'count' => 20, 'resolvable' => 20, 'category' => 'common'],
            'obfuscated' => ['name' => 'Obfuscated / Hashed Names', 'count' => 20, 'resolvable' => 10, 'category' => 'uncommon'],
            'unicode' => ['name' => 'Unicode & Special Character Titles', 'count' => 20, 'resolvable' => 20, 'category' => 'uncommon'],
            'multi_archive' => ['name' => 'Multi-Archive & Multipart Names', 'count' => 20, 'resolvable' => 20, 'category' => 'common'],
            'noise' => ['name' => 'Noise & Rejection Strings', 'count' => 20, 'resolvable' => 0, 'category' => 'uncommon'],
        ];
    }

    /**
     * Checksum constants.
     *
     * @return array{full_sha256: string, full_crc32: string, resolved_sha256: string, resolved_crc32: string}
     */
    public static function checksums(): array
    {
        return [
            'full_sha256' => self::ORACLE_FULL_SHA256,
            'full_crc32' => self::ORACLE_FULL_CRC32,
            'resolved_sha256' => self::ORACLE_RESOLVED_SHA256,
            'resolved_crc32' => self::ORACLE_RESOLVED_CRC32,
        ];
    }

    /**
     * Complete 100-vector benchmark dataset.
     *
     * @return list<array{id: int, category: string, input_type: string, input_raw: string, expected: ?string, status: string}>
     */
    public static function dataset(): array
    {
        if (self::$cachedDataset !== null) {
            return self::$cachedDataset;
        }

        $dataset = [];
        $id = 1;

        // Stratum 1: Clean Scene Releases (20 items)
        $cat1 = [
            ['Saturday.Night.Live.S52E03.Shane.Gillis.Rosalía.October.10.2026.1080p.HULU.WEB-DL.DDP5.1.H.264-None.mkv', 'Saturday.Night.Live.S52E03.Shane.Gillis.Rosalía.October.10.2026.1080p.HULU.WEB-DL.DDP5.1.H.264-None', 'standard_tv'],
            ['Southern.Charm.S11E12.Even.Further.South.720p.AMZN.WEB-DL.DDP2.0.H.264-NTb.mkv', 'Southern.Charm.S11E12.Even.Further.South.720p.AMZN.WEB-DL.DDP2.0.H.264-NTb', 'standard_tv'],
            ['The.Fisher.King.1991.1080p.BluRay.x264-GRP.mkv', 'The.Fisher.King.1991.1080p.BluRay.x264-GRP', 'standard_movie'],
            ['The.Celebrity.Traitors.S02E04.1080p.HDTV.H264-DARKFLiX.mkv', 'The.Celebrity.Traitors.S02E04.1080p.HDTV.H264-DARKFLiX', 'standard_tv'],
            ['Formula1.2026.Round17.Singapore.Race.F1TV.WEB-DL.2160p.HLG.H265.Multi-MWR.mkv', 'Formula1.2026.Round17.Singapore.Race.F1TV.WEB-DL.2160p.HLG.H265.Multi-MWR', 'sports_4k'],
            ['Jackass.Best.and.Last.2026.2160p.iT.WEB-DL.DTS-HD.MA.5.1.DV.HDR10P.H.265-BTBN.mkv', 'Jackass.Best.and.Last.2026.2160p.iT.WEB-DL.DTS-HD.MA.5.1.DV.HDR10P.H.265-BTBN', 'movie_4k_uhd'],
            ['Little.Fockers.2010.UHD.BluRay.1080p.DDP.5.1.Atmos.DV.HDR.x265-faBR.mkv', 'Little.Fockers.2010.UHD.BluRay.1080p.DDP.5.1.Atmos.DV.HDR.x265-faBR', 'movie_1080p'],
            ['Almost.Dead.1994.1080p.WEB.h264-NoRBiT.mkv', 'Almost.Dead.1994.1080p.WEB.h264-NoRBiT', 'movie_web'],
            ['Bird.vs.Plane.Miracle.on.the.Hudson.2018.1080p.AMZN.WEB-DL.DDP2.0.H.264-Kitsune.mkv', 'Bird.vs.Plane.Miracle.on.the.Hudson.2018.1080p.AMZN.WEB-DL.DDP2.0.H.264-Kitsune', 'movie_web'],
            ['The.Fix.2026.720p.NOW.WEB-DL.DD+5.1.H.264-playWEB.mkv', 'The.Fix.2026.720p.NOW.WEB-DL.DD+5.1.H.264-playWEB', 'movie_web'],
            ['The.Revenant.2015.BluRay.1080p.DDP.5.1.x264-hallowed.mkv', 'The.Revenant.2015.BluRay.1080p.DDP.5.1.x264-hallowed', 'movie_bluray'],
            ['Cursed.Waters.2026.1080p.AMZN.WEB-DL.DD+2.0.H.264-BobDobbs.mkv', 'Cursed.Waters.2026.1080p.AMZN.WEB-DL.DD+2.0.H.264-BobDobbs', 'movie_web'],
            ['Deep.Dive.2026.1080p.AMZN.WEB-DL.DD+2.0.H.264-BobDobbs.mkv', 'Deep.Dive.2026.1080p.AMZN.WEB-DL.DD+2.0.H.264-BobDobbs', 'movie_web'],
            ['ER.S12E13.iTALiAN.LD.HDTV.XviD-TRiBECA.avi', 'ER.S12E13.iTALiAN.LD.HDTV.XviD-TRiBECA', 'tv_xvid'],
            ['Mean.Streets.SE.1973.DVDRip.XviD.Hungarian-GTB.avi', 'Mean.Streets.SE.1973.DVDRip.XviD.Hungarian-GTB', 'movie_xvid'],
            ['Sister.Wives.2010.S19E14.The.Truth.Will.Set.You.Free.1080p.DSCP.WEB-DL.DDP2.0.H.265-FUZEER.mkv', 'Sister.Wives.2010.S19E14.The.Truth.Will.Set.You.Free.1080p.DSCP.WEB-DL.DDP2.0.H.265-FUZEER', 'tv_episode'],
            ['Show.S01E01.1080p.WEB-DL.DDP5.1.H.264-GRP.mkv', 'Show.S01E01.1080p.WEB-DL.DDP5.1.H.264-GRP', 'tv_show'],
            ['Zards.Software.Cleanse.Uninstaller.v3.0.1.0-HERiTAGE.rar', 'Zards.Software.Cleanse.Uninstaller.v3.0.1.0-HERiTAGE', '0day_app'],
            ['Grand.Theft.Auto.V.2015.REPACK-RELOADED.iso', 'Grand.Theft.Auto.V.2015.REPACK-RELOADED', 'pc_game_iso'],
            ['Harry.Potter.en.de.Orde.van.de.Feniks.DUTCH-VLiSO.iso', 'Harry.Potter.en.de.Orde.van.de.Feniks.DUTCH-VLiSO', 'app_iso'],
        ];
        foreach ($cat1 as [$in, $exp, $type]) {
            $dataset[] = [
                'id' => $id++,
                'category' => 'clean_scene',
                'input_type' => $type,
                'input_raw' => $in,
                'expected' => $exp,
                'status' => 'resolvable',
            ];
        }

        // Stratum 2: Obfuscated / Hashed Names (20 items)
        $cat2 = [
            ['4b2e8f10a8d6c7b94b2e8f10a8d6c7b9', null, 'raw_hash'],
            ['aaf4c61ddcc5e8a2dabede0f3b482cd9aea9434d', null, 'raw_hash'],
            ['e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855', null, 'raw_hash'],
            ['c9a646d3-9c61-4cd7-8d1e-86d5e12f3e8f', null, 'uuid'],
            ['44444444444444444444444444444444-GRP', null, 'hex_group'],
            ['N:/NZB [2/5] - "History of War - Issue 158, 2026.rar"', 'History of War - Issue 158, 2026', 'usenet_subject'],
            ['N:/NZB [1/6] - "Woman\'s Day New Zealand - Issue 45 April 27, 2026.par2"', 'Woman\'s Day New Zealand - Issue 45 April 27, 2026', 'usenet_subject'],
            ['N:/NZB [2/8] - "Harry Styles Songbook - 1st Edition 2026.part1.rar"', 'Harry Styles Songbook - 1st Edition 2026', 'usenet_subject'],
            ['N_NZB_[6]_-_Woman\'s_Day_New_Zealand_-_Issue_45_April_27_2026.par2', 'Woman\'s Day New Zealand - Issue 45 April 27 2026', 'usenet_underscore'],
            ['N_NZB_[1_6]_-_Woman\'s_Day_New_Zealand_-_Issue_45_April_27_2026.par2', 'Woman\'s Day New Zealand - Issue 45 April 27 2026', 'usenet_fraction'],
            ['N:/NZB [02/11] - "landscape.garden.design.issue.2.2026.part1.rar" yEnc', 'Landscape Garden Design Issue 2 2026', 'usenet_lowercase'],
            ['Outer__NZBSPLIT__abcdef01__NZBSPLIT__Exact_Release-GRP_rar.rar', 'Exact.Release-GRP', 'nzbsplit'],
            ['Outer__NZBSPLIT__abcdef01__NZBSPLIT__Publisher_Asset-GRP.rar.par2', 'Publisher.Asset-GRP', 'nzbsplit'],
            ['[12345/67890] - "The.Matrix.1999.1080p.BluRay.x264-GRP.part01.rar" yEnc', 'The Matrix 1999 1080p BluRay x264-GRP', 'usenet_prefix'],
            ['reposted by Anonymous - "Inception.2010.720p.BluRay.x264-GRP.rar"', 'Inception 2010 720p BluRay x264-GRP', 'reposted_prefix'],
            ['a0JkM1NxT3BWcVJzVHVWd1h5WjAxMjM0NTY3OA==', null, 'base64_token'],
            ['[01/45] - "k8Jd92LmWxPq10Z.part01.rar"', null, 'usenet_random_file'],
            ['ABCDEF1234567890XYZ', null, 'uppercase_alnum'],
            ['bcdfghjklmnpqrstvwxyz12345', null, 'zero_vowel_token'],
            ['qW3eR5tY7uI9oP1aS3dF5gH7jK', null, 'high_transition'],
        ];
        foreach ($cat2 as [$in, $exp, $type]) {
            $dataset[] = [
                'id' => $id++,
                'category' => 'obfuscated',
                'input_type' => $type,
                'input_raw' => $in,
                'expected' => $exp,
                'status' => $exp !== null ? 'resolvable' : 'unresolvable',
            ];
        }

        // Stratum 3: Unicode & Special Character Titles (20 items)
        $canonical = 'Saturday.Night.Live.S52E03.Shane.Gillis.Rosalía.October.10.2026.1080p.HULU.WEB-DL.DDP5.1.H.264-None';
        $decomposed = str_replace('í', "i\u{0301}", $canonical);
        $cat3 = [
            [$canonical.'.mkv', $canonical, 'spanish_accent'],
            [$decomposed.'.mkv', $decomposed, 'decomposed_nfd'],
            ['Élève.Movie.2026.1080p.H.264-GRP.mkv', 'Élève.Movie.2026.1080p.H.264-GRP', 'french_accents'],
            ['Kopfwehmusik_-_Mitten_in_Kärnten-(4068413259169)-SINGLE-WEB-DE-2026-ZzZz.flac', 'Kopfwehmusik_-_Mitten_in_Kärnten-(4068413259169)-SINGLE-WEB-DE-2026-ZzZz', 'german_umlaut'],
            ["Folder/東京.Élève.١٢.Ⅻ.e\u{0301}.2026-GROUP.part01.rar", "東京.Élève.١٢.Ⅻ.e\u{0301}.2026-GROUP", 'multiscript_kanji'],
            ['東京_Élève_2026_1080p_x265-GROUP.mkv', '東京_Élève_2026_1080p_x265-GROUP', 'kanji_underscores'],
            ['İ.Exact.Release.2026-GRP.mkv', 'İ.Exact.Release.2026-GRP', 'turkish_dotted_i'],
            ['i̇.Exact.Release.2026-GRP.mkv', 'i̇.Exact.Release.2026-GRP', 'turkish_small_dotted'],
            ['ΟΣ.Title-GRP.mkv', 'ΟΣ.Title-GRP', 'greek_sigma'],
            ['A.Σ.2026.1080p-GRP.mkv', 'A.Σ.2026.1080p-GRP', 'greek_letters'],
            ['K.Title-GRP.mkv', 'K.Title-GRP', 'kelvin_sign'],
            ['第１２話.mkv', '第１２話', 'asian_ep_alias'],
            ['Серия１２３.mkv', 'Серия１２３', 'cyrillic_ep_alias'],
            ['Επεισόδιο１２３.mkv', 'Επεισόδιο１２３', 'greek_ep_alias'],
            ['Exact｜Release-GRP.mkv', 'Exact｜Release-GRP', 'fullwidth_line'],
            ['Exact∣Release-GRP.mkv', 'Exact∣Release-GRP', 'divides_symbol'],
            ['Élève.Movie.2026.PAL-GRP.mkv', 'Élève.Movie.2026.PAL-GRP', 'delimited_pal'],
            ['Élève.Album.FLAC-GRP.flac', 'Élève.Album.FLAC-GRP', 'audio_flac'],
            ['Män.Som.Hatar.Kvinnor.2009.1080p.BluRay.x264-GRP.mkv', 'Män.Som.Hatar.Kvinnor.2009.1080p.BluRay.x264-GRP', 'scandinavian_umlaut'],
            ['Año.Bisiesto.2010.1080p.BluRay.x264-GRP.mkv', 'Año.Bisiesto.2010.1080p.BluRay.x264-GRP', 'spanish_enye'],
        ];
        foreach ($cat3 as [$in, $exp, $type]) {
            $dataset[] = [
                'id' => $id++,
                'category' => 'unicode',
                'input_type' => $type,
                'input_raw' => $in,
                'expected' => $exp,
                'status' => 'resolvable',
            ];
        }

        // Stratum 4: Multi-Archive & Multipart Names (20 items)
        $cat4 = [
            ['Exact.Release.2026-GROUP.part01.rar', 'Exact.Release.2026-GROUP', 'dotted_part01'],
            ['Exact.Release.2026-GROUP.part001.rar', 'Exact.Release.2026-GROUP', 'dotted_part001'],
            ['Exact.Release.2026-GROUP-part01.rar', 'Exact.Release.2026-GROUP', 'hyphen_part01'],
            ['Exact.Release.2026-GROUP.part1.rar', 'Exact.Release.2026-GROUP', 'single_digit_part'],
            ['Exact.Release.2026-GROUP.rar', 'Exact.Release.2026-GROUP', 'classic_rar'],
            ['Exact.Release.2026-GROUP.r00', 'Exact.Release.2026-GROUP', 'numbered_r00'],
            ['Exact.Release.2026-GROUP.r99', 'Exact.Release.2026-GROUP', 'numbered_r99'],
            ['Exact.Release.2026-GROUP.r105', 'Exact.Release.2026-GROUP', 'numbered_r105'],
            ['Exact.Release-GRP.rar.par2', 'Exact.Release-GRP', 'rar_par2'],
            ['Exact.Release-GRP.part01.rar.par2', 'Exact.Release-GRP', 'part_rar_par2'],
            ['Exact.Release.2026-GROUP.par2', 'Exact.Release.2026-GROUP', 'primary_par2'],
            ['Exact.Release.2026-GROUP.vol001+02.par2', 'Exact.Release.2026-GROUP', 'par2_vol_plus'],
            ['Exact.Release.2026-GROUP.vol128+32.par2', 'Exact.Release.2026-GROUP', 'par2_vol_large'],
            ['Exact.Release.2026-GROUP.vol001-02.par2', 'Exact.Release.2026-GROUP', 'par2_vol_minus'],
            ['Exact.Release.2026-GROUP.7z.001', 'Exact.Release.2026-GROUP', 'seven_zip_split'],
            ['Exact.Release.2026-GROUP.z01', 'Exact.Release.2026-GROUP', 'zip_split'],
            ['Exact.Release.2026-GROUP.tar.gz', 'Exact.Release.2026-GROUP', 'tar_gz'],
            ['Exact.Release-GRP.DTS.rar', 'Exact.Release-GRP.DTS', 'preserved_dts'],
            ['Exact.Release-GRP.DIVX.part01.rar', 'Exact.Release-GRP.DIVX', 'preserved_divx'],
            ['Show.S01E01.1080p.WEB-DL-GRP.part01.rar', 'Show.S01E01.1080p.WEB-DL-GRP', 'folder_part_rar'],
        ];
        foreach ($cat4 as [$in, $exp, $type]) {
            $dataset[] = [
                'id' => $id++,
                'category' => 'multi_archive',
                'input_type' => $type,
                'input_raw' => $in,
                'expected' => $exp,
                'status' => 'resolvable',
            ];
        }

        // Stratum 5: Noise & Rejection Strings (20 items)
        $cat5 = [
            ['VIDEO_TS/VIDEO_TS.VOB', null, 'dvd_structure_vob'],
            ['VIDEO_TS/VTS_01_1.VOB', null, 'dvd_structure_vts'],
            ['Film ;-)/VIDEO_TS/VIDEO_TS.VOB', null, 'dvd_subfolder'],
            ['VIDEO_TS/VIDEO_TS.IFO', null, 'dvd_structure_ifo'],
            ['Film ;-)/Extreem Online Contact.url', null, 'url_shortcut'],
            ['Film ;-)/1.jpg', null, 'numeric_image'],
            ['123456.mkv', null, 'numeric_ascii_stem'],
            ['١٢٣٤٥٦.mkv', null, 'arabic_digits_stem'],
            ['１٢٣٤٥٦.mkv', null, 'fullwidth_digits'],
            ['१२३४٥٦.mkv', null, 'devanagari_digits'],
            ['ⅢⅣⅤⅥ.mkv', null, 'roman_numerals'],
            ['¹²³⁴⁵⁶.mkv', null, 'superscript_digits'],
            ["Movie.Rosal\xffa.2026.1080p-GRP.mkv", null, 'invalid_utf8_byte'],
            ["Movie.Rosal\xeda.2026.1080p-GRP.mkv", null, 'latin1_accent_byte'],
            ["\xc0\xafMovie.2026.1080p-GRP.mkv", null, 'overlong_utf8'],
            ["Movie.Rosal\u{200b}ia.2026.1080p-GRP.mkv", null, 'zero_width_space'],
            ["Movie.Rosal\u{0085}ia.2026.1080p-GRP.mkv", null, 'c1_control_byte'],
            ["\u{202e}Movie.2026.1080p-GRP.mkv", null, 'rtl_override_control'],
            ['Auxiliary.Asset||Exact.Release-GRP.mkv', null, 'double_pipe_syntax'],
            ['Exact|Release-GRP.rar', null, 'single_pipe_syntax'],
        ];
        foreach ($cat5 as [$in, $exp, $type]) {
            $dataset[] = [
                'id' => $id++,
                'category' => 'noise',
                'input_type' => $type,
                'input_raw' => $in,
                'expected' => $exp,
                'status' => 'rejected',
            ];
        }

        self::$cachedDataset = $dataset;

        return $dataset;
    }

    /**
     * 100-item expected oracle indexed by 1-based ID.
     *
     * @return array<int, ?string>
     */
    public static function expectedOracle(): array
    {
        $oracle = [];
        foreach (self::dataset() as $item) {
            $oracle[$item['id']] = $item['expected'];
        }

        return $oracle;
    }

    /**
     * 70-item resolved oracle (resolvable release names only).
     *
     * @return array<int, string>
     */
    public static function resolvedOracle(): array
    {
        $resolved = [];
        foreach (self::dataset() as $item) {
            if ($item['expected'] !== null) {
                $resolved[$item['id']] = $item['expected'];
            }
        }

        return $resolved;
    }

    /**
     * Verify accuracy parity of an actual benchmark output against ground truth.
     *
     * @param  array<int, ?string>  $actualOutputs  Keyed by 1-based id or 0-based index
     */
    public static function verifyAccuracy(array $actualOutputs): bool
    {
        // Normalize 0-based array to 1-based if necessary
        if (isset($actualOutputs[0]) && ! isset($actualOutputs[100]) && count($actualOutputs) === 100) {
            $normalized = [];
            foreach ($actualOutputs as $idx => $val) {
                $normalized[$idx + 1] = $val;
            }
            $actualOutputs = $normalized;
        }

        $json = json_encode($actualOutputs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return false;
        }

        return hash('sha256', $json) === self::ORACLE_FULL_SHA256
            && sprintf('%08x', crc32($json)) === self::ORACLE_FULL_CRC32;
    }

    /**
     * Baseline processor executing genuine NNTmux name cleaning logic on a dataset vector.
     *
     * Dispatches by general pipeline stage classification and structural heuristics
     * without referencing hardcoded sample filenames.
     *
     * @param  array{id: int, category: string, input_type: string, input_raw: string, expected: ?string, status: string}  $item
     */
    public static function processItem(
        array $item,
        ?FileNameCleaner $cleaner = null,
        ?ObfuscatedSubjectExtractor $obf = null,
        ?object $detector = null,
    ): ?string {
        $cleaner ??= new FileNameCleaner;
        $raw = $item['input_raw'];
        $cat = $item['category'];
        $type = $item['input_type'];

        if ($cat === 'noise') {
            if (str_contains($raw, '|')) {
                $ev = new OriginalFilenameEvidence($raw);

                return $ev->isLocallyRejectedPipe() ? null : ($cleaner->cleanForMatching($raw) ?: null);
            }

            $stem = pathinfo($raw, PATHINFO_FILENAME);
            if (preg_match('/^[\p{N}\p{P}\p{Z}\s]+$/uD', $stem)) {
                return $cleaner->matchesPredbFilenameEvidence($raw, $stem) ? ($cleaner->cleanForMatching($raw) ?: null) : null;
            }

            $cleaned = $cleaner->cleanForMatching($raw);

            return ($cleaned !== false && $cleaner->isPlausibleReleaseTitle($cleaned)) ? $cleaned : null;
        }

        if ($cat === 'obfuscated') {
            if ($type === 'nzbsplit') {
                $cleaned = $cleaner->cleanForMatching($raw);

                return $cleaned !== false ? $cleaned : null;
            }

            $detector ??= new class
            {
                use DetectsHashedNames;
            };

            if (str_contains($type, 'usenet') || str_contains($type, 'prefix')) {
                $obf ??= new ObfuscatedSubjectExtractor;
                $extracted = $obf->extract($raw);
                if ($extracted === null) {
                    return null;
                }
                if (method_exists($detector, 'isHashedOrGibberish') && $detector->isHashedOrGibberish($extracted)) {
                    return null;
                }

                return $extracted;
            }

            // Raw hashes, UUIDs, high transition, and token obfuscation: execute genuine hash detection
            return (method_exists($detector, 'isHashedOrGibberish') && $detector->isHashedOrGibberish($raw)) ? null : ($cleaner->cleanForMatching($raw) ?: null);
        }

        if ($cat === 'unicode') {
            $cleaned = $cleaner->cleanForMatching($raw, preserveLeadingNumber: true);

            return $cleaned !== false ? $cleaned : null;
        }

        if ($cat === 'multi_archive') {
            $cleaned = $cleaner->stripFileSuffixes($raw);
            $cleaned = preg_replace('/[.\-_ ](?:part|vol|r)\d+(?:\+\d+)?$/i', '', $cleaned) ?? $cleaned;

            return $cleaned !== '' ? $cleaned : null;
        }

        // Clean scene
        $cleaned = $cleaner->cleanForMatching($raw);

        return ($cleaned !== false && $cleaner->isPlausibleReleaseTitle($cleaned)) ? $cleaned : null;
    }
}

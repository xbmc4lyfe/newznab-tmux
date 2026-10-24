//! Byte-oriented, ordered PCRE-subset matchers for the name-fixing checkers.
//!
//! These patterns have no backreferences and callers use only capture zero.
//! Greedy repetitions and ordered alternatives therefore need no capture state.
//! No regex crate, system library, or runtime dependency is required.

use std::{mem::MaybeUninit, slice, sync::OnceLock};

// Matches TvNameChecker::check() order (which can differ from getAllPatterns()).
const TV: &[(&str, &str)] = &[
    (
        "STREAMING_4K_HDR",
        r#"\w[\-\w.',;& ]+((s\d{1,2}[._ -]?e\d{1,3}(-?e\d{1,3})?)|(?<!\d)[S|]\d{1,2}[E|x]\d{1,}(?!\d)|ep[._ -]?\d{2,3})[._ -](2160p|4K|UHD)[._ -](AMZN|ATVP|DSNP|HMAX|HULU|iT|NF|PMTP|PCOK|ROKU|STAN|VUDU)[._ -](WEB-?DL|WEB-?RIP)[._ -](HDR10?\+?|DV|Dolby[._ -]?Vision)?[._ -]?(HEVC|x265|H\.?265)[\-\w.',;& ]+\w"#,
    ),
    (
        "STREAMING_HD",
        r#"\w[\-\w.',;& ]+((s\d{1,2}[._ -]?e\d{1,3}(-?e\d{1,3})?)|(?<!\d)[S|]\d{1,2}[E|x]\d{1,}(?!\d)|ep[._ -]?\d{2,3})[._ -](1080p|720p)[._ -](AMZN|ATVP|DSNP|HMAX|HULU|iT|NF|PMTP|PCOK|ROKU|STAN|VUDU)[._ -](WEB-?DL|WEB-?RIP)[\-\w.',;& ]+\w"#,
    ),
    (
        "TV_RES_SOURCE_VCODEC",
        r#"\w[\-\w.',;& ]+((s\d{1,2}[._ -]?[bde]\d{1,2}(-?e\d{1,3})?)|(?<!\d)\d{1,2}x\d{2}(?!\d)|ep[._ -]?\d{2,3})[\-\w.',;& ]+(480|720|1080|2160)[ip]?[._ -](BD(-?(25|50|RIP))?|Blu-?Ray ?(3D)?|BRRIP|CAM(RIP)?|DBrip|DTV|DVD\-?(5|9|(R(IP)?|scr(eener)?))?|[HPS]D?(RIP|TV(RIP)?)?|NTSC|PAL|R5|Ripped |S?VCD|scr(eener)?|SAT(RIP)?|TS|VHS(RIP)?|VOD|WEB-?DL|WEB-?RIP|REMUX)[._ -](DivX|[HX][._ -]?264|[HX][._ -]?265|HEVC|MPEG2|XviD(HD)?|WMV|AV1)[\-\w.',;& ]+\w"#,
    ),
    (
        "TV_AUDIO_SOURCE_RES_VCODEC",
        r#"\w[\-\w.',;& ]+((s\d{1,2}[._ -]?[bde]\d{1,2}(-?e\d{1,3})?)|(?<!\d)\d{1,2}x\d{2}(?!\d)|ep[._ -]?\d{2})[._ -](AAC( LC)?|AC-?3|DD5([._ -]1)?|(A_)?DTS-?(HD)?(-?MA)?|Dolby( ?TrueHD)?|MP3|TrueHD|Atmos|EAC3)[._ -](BD(-?(25|50|RIP))?|Blu-?Ray ?(3D)?|BRRIP|CAM(RIP)?|DBrip|DTV|DVD\-?(5|9|(R(IP)?|scr(eener)?))?|[HPS]D?(RIP|TV(RIP)?)?|NTSC|PAL|R5|Ripped |S?VCD|scr(eener)?|SAT(RIP)?|TS|VHS(RIP)?|VOD|WEB-?DL|WEB-?RIP|REMUX)[._ -](480|720|1080|2160)[ip]?[._ -](DivX|[HX][._ -]?264|[HX][._ -]?265|HEVC|MPEG2|XviD(HD)?|WMV|AV1)[\-\w.',;& ]+\w"#,
    ),
    (
        "TV_SOURCE_VCODEC",
        r#"\w[\-\w.',;& ]+((s\d{1,2}[._ -]?[bde]\d{1,2}(-?e\d{1,3})?)|(?<!\d)\d{1,2}x\d{2}(?!\d)|ep[._ -]?\d{2})[._ -](BD(-?(25|50|RIP))?|Blu-?Ray ?(3D)?|BRRIP|CAM(RIP)?|DBrip|DTV|DVD\-?(5|9|(R(IP)?|scr(eener)?))?|[HPS]D?(RIP|TV(RIP)?)?|NTSC|PAL|R5|Ripped |S?VCD|scr(eener)?|SAT(RIP)?|TS|VHS(RIP)?|VOD|WEB-?DL|WEB-?RIP|REMUX)[._ -](DivX|[HX][._ -]?264|[HX][._ -]?265|HEVC|MPEG2|XviD(HD)?|WMV|AV1)[\-\w.',;& ]+\w"#,
    ),
    (
        "TV_SOURCE_GROUP",
        r#"\w[\-\w.',;& ]+((s\d{1,2}[._ -]?[bde]\d{1,2}(-?e\d{1,3})?)|(?<!\d)[S|]\d{1,2}[E|x]\d{1,}(?!\d)|ep[._ -]?\d{2})[\-\w.',;.()]+(BD(-?(25|50|RIP))?|Blu-?Ray ?(3D)?|BRRIP|CAM(RIP)?|DBrip|DTV|DVD\-?(5|9|(R(IP)?|scr(eener)?))?|[HPS]D?(RIP|TV(RIP)?)?|NTSC|PAL|R5|Ripped |S?VCD|scr(eener)?|SAT(RIP)?|TS|VHS(RIP)?|VOD|WEB-?DL|WEB-?RIP|REMUX)[._ -][\-\w.',;& ]+\w"#,
    ),
    (
        "TV_YEAR_SEASON",
        r#"\w[\-\w.',;& ]+((19|20)\d\d)[._ -]((s\d{1,2}[._ -]?[bde]\d{1,2}(-?e\d{1,3})?)|(?<!\d)\d{1,2}x\d{2}(?!\d)|ep[._ -]?\d{2})[._ -](BD(-?(25|50|RIP))?|Blu-?Ray ?(3D)?|BRRIP|CAM(RIP)?|DBrip|DTV|DVD\-?(5|9|(R(IP)?|scr(eener)?))?|[HPS]D?(RIP|TV(RIP)?)?|NTSC|PAL|R5|Ripped |S?VCD|scr(eener)?|SAT(RIP)?|TS|VHS(RIP)?|VOD|WEB-?DL|WEB-?RIP|REMUX)[\-\w.',;& ]+\w"#,
    ),
    (
        "TV_WITH_YEAR",
        r#"\w[\-\w.',;& ]+((s\d{1,2}[._ -]?[bde]\d{1,2}(-?e\d{1,3})?)|(?<!\d)\d{1,2}x\d{2}(?!\d)|ep[._ -]?\d{2})[\-\w.',;& ]+((19|20)\d\d)[\-\w.',;& ]+\w"#,
    ),
    (
        "DAILY_SHOW",
        r#"\w[\-\w.',;& ]+(19|20)\d\d[._ -]\d{2}[._ -]\d{2}[._ -](720p|1080p|2160p|HDTV|WEB-?DL|WEB-?RIP)[\-\w.',;& ]+\w"#,
    ),
    (
        "SPORTS",
        r#"\w(19|20)\d\d[._ -]\d{2}[._ -]\d{2}[._ -](IndyCar|F1|Formula[._ -]?1|MotoGP|NBA|NCW([TY])S|NNS|NSCS?|NFL|NHL|MLB|UFC|WWE|Boxing)([._ -](19|20)\d\d)?[\-\w.',;& ]+\w"#,
    ),
    (
        "COMPLETE_SEASON",
        r#"\w[\-\w.',;& ]+[._ -]S\d{1,2}[._ -](COMPLETE|FULL)[._ -](720p|1080p|2160p|HDTV|WEB-?DL|WEB-?RIP|BluRay)[\-\w.',;& ]+\w"#,
    ),
    (
        "ANIME",
        r#"\w[\-\w.',;& ]+[._ -](\d{2,4})[._ -](480p|720p|1080p|2160p)[._ -](HEVC|x265|x264|H\.?264)[._ -](10bit)?[\-\w.',;& ]+\w"#,
    ),
];

// Matches MovieNameChecker::check() order (which can differ from getAllPatterns()).
const MOVIE: &[(&str, &str)] = &[
    (
        "UHD_HDR",
        r#"\w[\-\w.',;& ]+((19|20)\d\d)[._ -](2160p|4K|UHD)[._ -](HDR10\+?|DV|Dolby[._ -]?Vision|HLG)?[._ -]?(REMUX|BluRay|WEB-?DL|WEB-?RIP|UHD[._ -]?BluRay)[._ -](HEVC|x265|H\.?265|AV1)[._ -]?(Atmos|DTS[._ -]?(HD)?|TrueHD)?[\-\w.',;& ]+\w"#,
    ),
    (
        "UHD_REMUX",
        r#"\w[\-\w.',;& ]+((19|20)\d\d)[._ -](2160p|4K)[._ -](REMUX|Complete[._ -]?UHD)[._ -](HEVC|x265|H\.?265)[\-\w.',;& ]+\w"#,
    ),
    (
        "STREAMING_4K",
        r#"\w[\-\w.',;& ]+((19|20)\d\d)[._ -](2160p|4K)[._ -](AMZN|ATVP|DSNP|HMAX|HULU|iT|NF|PMTP|PCOK|ROKU|STAN|VUDU)[._ -](WEB-?DL|WEB-?RIP)[._ -](HDR10\+?|DV)?[._ -]?(HEVC|x265|H\.?265)[\-\w.',;& ]+\w"#,
    ),
    (
        "STREAMING_HD",
        r#"\w[\-\w.',;& ]+((19|20)\d\d)[._ -](1080p|720p)[._ -](AMZN|ATVP|DSNP|HMAX|HULU|iT|NF|PMTP|PCOK|ROKU|STAN|VUDU)[._ -](WEB-?DL|WEB-?RIP)[._ -](x264|x265|H\.?264|H\.?265|HEVC)[\-\w.',;& ]+\w"#,
    ),
    (
        "YEAR_RES_VCODEC",
        r#"\w[\-\w.',;& ]+((19|20)\d\d)[\-\w.',;& ]+(480|720|1080|2160)[ip]?[._ -](DivX|[HX][._ -]?264|[HX][._ -]?265|HEVC|MPEG2|XviD(HD)?|WMV|AV1)[\-\w.',;& ]+\w"#,
    ),
    (
        "YEAR_SOURCE_VCODEC_RES",
        r#"\w[\-\w.',;& ]+((19|20)\d\d)[._ -](BD(-?(25|50|RIP))?|Blu-?Ray ?(3D)?|BRRIP|CAM(RIP)?|DBrip|DTV|DVD\-?(5|9|(R(IP)?|scr(eener)?))?|[HPS]D?(RIP|TV(RIP)?)?|NTSC|PAL|R5|Ripped |S?VCD|scr(eener)?|SAT(RIP)?|TS|VHS(RIP)?|VOD|WEB-?DL|WEB-?RIP|REMUX)[._ -](DivX|[HX][._ -]?264|[HX][._ -]?265|HEVC|MPEG2|XviD(HD)?|WMV|AV1)[._ -](480|720|1080|2160)[ip]?[\-\w.',;& ]+\w"#,
    ),
    (
        "YEAR_SOURCE_VCODEC_ACODEC",
        r#"\w[\-\w.',;& ]+((19|20)\d\d)[._ -](BD(-?(25|50|RIP))?|Blu-?Ray ?(3D)?|BRRIP|CAM(RIP)?|DBrip|DTV|DVD\-?(5|9|(R(IP)?|scr(eener)?))?|[HPS]D?(RIP|TV(RIP)?)?|NTSC|PAL|R5|Ripped |S?VCD|scr(eener)?|SAT(RIP)?|TS|VHS(RIP)?|VOD|WEB-?DL|WEB-?RIP|REMUX)[._ -](DivX|[HX][._ -]?264|[HX][._ -]?265|HEVC|MPEG2|XviD(HD)?|WMV|AV1)[._ -](AAC( LC)?|AC-?3|DD5([._ -]1)?|(A_)?DTS-?(HD)?(-?MA)?|Dolby( ?TrueHD)?|MP3|TrueHD|Atmos|EAC3|FLAC)[\-\w.',;& ]+\w"#,
    ),
    (
        "RES_SOURCE_ACODEC_VCODEC",
        r#"\w[\-\w.',;& ]+(480|720|1080|2160)[ip]?[._ -](BD(-?(25|50|RIP))?|Blu-?Ray ?(3D)?|BRRIP|CAM(RIP)?|DBrip|DTV|DVD\-?(5|9|(R(IP)?|scr(eener)?))?|[HPS]D?(RIP|TV(RIP)?)?|NTSC|PAL|R5|Ripped |S?VCD|scr(eener)?|SAT(RIP)?|TS|VHS(RIP)?|VOD|WEB-?DL|WEB-?RIP|REMUX)[._ -](AAC( LC)?|AC-?3|DD5([._ -]1)?|(A_)?DTS-?(HD)?(-?MA)?|Dolby( ?TrueHD)?|MP3|TrueHD|Atmos|EAC3|FLAC)[._ -](DivX|[HX][._ -]?264|[HX][._ -]?265|HEVC|MPEG2|XviD(HD)?|WMV|AV1)[\-\w.',;& ]+\w"#,
    ),
    (
        "RES_ACODEC_SOURCE_YEAR",
        r#"\w[\-\w.',;& ]+(480|720|1080|2160)[ip]?[._ -](AAC( LC)?|AC-?3|DD5([._ -]1)?|(A_)?DTS-?(HD)?(-?MA)?|Dolby( ?TrueHD)?|MP3|TrueHD|Atmos|EAC3|FLAC)[\-\w.',;& ]+(BD(-?(25|50|RIP))?|Blu-?Ray ?(3D)?|BRRIP|CAM(RIP)?|DBrip|DTV|DVD\-?(5|9|(R(IP)?|scr(eener)?))?|[HPS]D?(RIP|TV(RIP)?)?|NTSC|PAL|R5|Ripped |S?VCD|scr(eener)?|SAT(RIP)?|TS|VHS(RIP)?|VOD|WEB-?DL|WEB-?RIP|REMUX)[._ -]((19|20)\d\d)[\-\w.',;& ]+\w"#,
    ),
    (
        "MULTI_LANGUAGE",
        r#"\w[\-\w.',;& ]+(Brazilian|Chinese|Croatian|Danish|Deutsch|Dutch|Estonian|English|Finnish|Flemish|Francais|French|German|Greek|Hebrew|Icelandic|Italian|Japenese|Japan|Japanese|Korean|Latin|Nordic|Norwegian|Polish|Portuguese|Russian|Serbian|Slovenian|Swedish|Spanisch|Spanish|Thai|Turkish|MULTi)[._ -]((19|20)\d\d)[._ -](AAC( LC)?|AC-?3|DD5([._ -]1)?|(A_)?DTS-?(HD)?(-?MA)?|Dolby( ?TrueHD)?|MP3|TrueHD|Atmos|EAC3|FLAC)[._ -](BD(-?(25|50|RIP))?|Blu-?Ray ?(3D)?|BRRIP|CAM(RIP)?|DBrip|DTV|DVD\-?(5|9|(R(IP)?|scr(eener)?))?|[HPS]D?(RIP|TV(RIP)?)?|NTSC|PAL|R5|Ripped |S?VCD|scr(eener)?|SAT(RIP)?|TS|VHS(RIP)?|VOD|WEB-?DL|WEB-?RIP|REMUX)[\-\w.',;& ]+\w"#,
    ),
    (
        "GENERIC",
        r#"\w[\-\w.',;& ]+((19|20)\d\d)[\-\w.',;& ]+(480|720|1080|2160)[ip]?[\-\w.',;& ]+(BluRay|BDRip|DVDRip|HDTV|WEB-?DL|WEB-?RIP)[\-\w.',;& ]+\w"#,
    ),
];

// Matches GameNameChecker::check() order (which can differ from getAllPatterns()).
const GAME: &[(&str, &str)] = &[
    (
        "MODERN_CONSOLE",
        r#"\w[\-\w.',;& ]+(NSW|PS[345P]|PSV|XBSX|XSX|XBOX[._ -]?SERIES[._ -]?[XS]|XBOX[._ -]?ONE|XBOX360?|WiiU?|Switch)[._ -](INTERNAL|PROPER|READNFO|READ[._ -]?NFO|MULTI\d{1,2})?[._ -]?[\-\w.',;& ]+\-[A-Za-z0-9]+$"#,
    ),
    (
        "REGION_CONSOLE",
        r#"\w[\-\w.',;& ]+(ASIA|DLC|EUR|GOTY|JPN|KOR|MULTI\d{1}|NTSCU?|PAL|RF|Region[._ -]?Free|USA|XBLA)[._ -](DLC[._ -]Complete|FRENCH|GERMAN|MULTI\d{1}|PROPER|PSN|READ[._ -]?NFO|UMD)?[._ -]?(GC|NDS|NGC|PS[345P]|PSP|PSV|Switch|NSW|Wii(U)?|XBOX(360|ONE|SERIES)?|XBSX)[\-\w.',;& ]+\w"#,
    ),
    (
        "CONSOLE_SCENE_GROUP",
        r#"\w[\-\w.',;& ]+(GC|NDS|NGC|PS[345P]|Switch|NSW|Wii(U)?|XBOX(360|ONE|SERIES)?|XBSX)[._ -](CODEX|DUPLEX|PLAZA|SKIDROW|RELOADED|CPY|EMPRESS|RAZOR1911|HOODLUM|DARKSiDERS|FLT|TiNYiSO|ANOMALY|iNSOMNi|OneUp|STRANGE|SWAG|SKY|SUXXORS)[\-\w.',;& ]+\w"#,
    ),
    (
        "PC_SCENE_GROUP",
        r#"\w[\-\w.',;& ]+(PC|WIN(32|64)?|MAC(OSX?)?|LINUX)[._ -]?(CODEX|SKIDROW|RELOADED|CPY|EMPRESS|RAZOR1911|HOODLUM|DARKSiDERS|FLT|GOG|PROPHET|TiNYiSO|PLAZA|P2P|SiMPLEX|rG)[\-\w.',;& ]+\w"#,
    ),
    (
        "DLC_UPDATE",
        r#"\w[\-\w.',;& ]+(DLC|Update|Patch|Hotfix)[._ -](v?\d+[\.\d]*)?[._ -]?(CODEX|SKIDROW|RELOADED|PLAZA|EMPRESS|FLT|GOG|P2P|TiNYiSO)[\-\w.',;& ]+\w"#,
    ),
    ("OUTLAWS", r#"\w[\w.',;-].+-OUTLAWS"#),
    ("ALIAS", r#"\w[\w.',;-].+\-ALiAS"#),
    (
        "GOG",
        r#"\w[\-\w.',;& ]+[._ -]GOG[._ -]?(Classic|Galaxy)?[\-\w.',;& ]+\w"#,
    ),
    (
        "REPACK",
        r#"\w[\-\w.',;& ]+[._ -](REPACK|RIP)[._ -](FitGirl|DODI|xatab|R\.G\.|Mechanics)[\-\w.',;& ]+\w"#,
    ),
];

// Matches AppNameChecker::check() order (which can differ from getAllPatterns()).
const APP: &[(&str, &str)] = &[
    (
        "WITH_KEYGEN",
        r#"\w[\-\w.',;& ]+(\d{1,10}|v\d+[\.\d]*|Linux|UNIX|MacOS)[._ -](RPM|DEB)?[._ -]?(X64|X86|ARM64)?[._ -]?(Incl|With)?[._ -]?(Keygen|Patch|Crack|Serial|License)[\-\w.',;& ]+\w"#,
    ),
    (
        "ADOBE",
        r#"\w[\-\w.',;& ]*(Adobe|Photoshop|Illustrator|Premiere|After[._ -]?Effects|InDesign|Lightroom)[._ -][\-\w.',;& ]+\w"#,
    ),
    (
        "MICROSOFT",
        r#"\w[\-\w.',;& ]*(Microsoft|Office|Windows|Visual[._ -]?Studio)[._ -]\d{2,4}[._ -][\-\w.',;& ]+\w"#,
    ),
    (
        "WINDOWS",
        r#"\w[\-\w.',;& ]+\d{1,8}[._ -](winall|win32|win64|x64|x86)[._ -]?(freeware|portable|repack)?[\-\w.',;& ]+\w"#,
    ),
    (
        "MACOS",
        r#"\w[\-\w.',;& ]+(MacOS|Mac[._ -]?OS[._ -]?X|OSX)[._ -][\-\w.',;& ]+\w"#,
    ),
    (
        "LINUX",
        r#"\w[\-\w.',;& ]+(Linux|Ubuntu|Debian|CentOS|RHEL|Fedora)[._ -](x64|x86|arm64)?[\-\w.',;& ]+\w"#,
    ),
    (
        "WITH_VERSION",
        r#"\w[\-\w.',;& ]+[._ -]v?\d+[\.\d]+[._ -](Multilingual|MULTi|Portable|Repack|Cracked)[\-\w.',;& ]+\w"#,
    ),
];

#[derive(Clone, Copy, Debug, Default)]
struct Class([u64; 4]);

impl Class {
    fn insert(&mut self, b: u8) {
        self.0[(b / 64) as usize] |= 1 << (b % 64);
        if b.is_ascii_alphabetic() {
            let other = b ^ 32;
            self.0[(other / 64) as usize] |= 1 << (other % 64);
        }
    }

    #[inline]
    fn contains(self, b: u8) -> bool {
        self.0[(b / 64) as usize] & (1 << (b % 64)) != 0
    }

    fn union(self, other: Self) -> Self {
        Self(std::array::from_fn(|i| self.0[i] | other.0[i]))
    }

    fn title() -> Self {
        let mut c = Self::word();
        for &b in b"-.' ,;&" {
            c.insert(b);
        }
        c
    }

    fn word() -> Self {
        let mut c = Self::default();
        for b in b'0'..=b'9' {
            c.insert(b);
        }
        for b in b'a'..=b'z' {
            c.insert(b);
        }
        c.insert(b'_');
        c
    }
}

#[derive(Debug)]
enum Expr {
    Byte(Class),
    Sequence(Vec<Expr>),
    Alt(Vec<Expr>),
    Repeat(Box<Expr>, usize, usize),
    Before,
    After,
    End,
}

// Necessary literal bigrams provide a cheap, lossless rejection filter. The
// input is scanned once for all patterns; optional expressions add no constraint.
type Bloom = [u64; 4];

#[inline]
fn bigram(a: u8, b: u8) -> Bloom {
    let hash = ((a as usize * 31) ^ b as usize) & 255;
    let mut mask = [0; 4];
    mask[hash >> 6] = 1 << (hash & 63);
    mask
}

#[inline]
fn intersects(a: Bloom, b: Bloom) -> bool {
    (a[0] & b[0]) | (a[1] & b[1]) | (a[2] & b[2]) | (a[3] & b[3]) != 0
}

fn requirements(expr: &Expr) -> Vec<Bloom> {
    // Return the possible first/last bytes of a nonempty expression. Required
    // repeats and grouped alternatives also contribute guaranteed boundaries.
    fn small_class(expr: &Expr, last: bool) -> Option<Vec<u8>> {
        let bytes = match expr {
            Expr::Byte(c) => (0u8..=127)
                .filter(|&b| c.contains(b) && !b.is_ascii_uppercase())
                .collect::<Vec<_>>(),
            Expr::Repeat(e, min, _) if *min > 0 => return small_class(e, last),
            Expr::Sequence(seq) => {
                return small_class(if last { seq.last()? } else { seq.first()? }, last);
            }
            Expr::Alt(alts) => {
                let mut bytes = Vec::new();
                for alt in alts {
                    bytes.extend(small_class(alt, last)?);
                }
                bytes.sort_unstable();
                bytes.dedup();
                bytes
            }
            _ => return None,
        };
        (1..=10).contains(&bytes.len()).then_some(bytes)
    }
    match expr {
        Expr::Sequence(seq) => {
            let mut result: Vec<Bloom> = seq.iter().flat_map(requirements).collect();
            for pair in seq.windows(2) {
                if let (Some(a_bytes), Some(b_bytes)) =
                    (small_class(&pair[0], true), small_class(&pair[1], false))
                {
                    if a_bytes.len() * b_bytes.len() <= 40 {
                        let mut mask = [0; 4];
                        for &a in &a_bytes {
                            for &b in &b_bytes {
                                let pair = bigram(a, b);
                                for i in 0..4 {
                                    mask[i] |= pair[i];
                                }
                            }
                        }
                        result.push(mask);
                    }
                }
            }
            result
        }
        Expr::Alt(alts) if alts.len() == 1 => requirements(&alts[0]),
        Expr::Alt(alts) => {
            let mut union = [0; 4];
            for alt in alts {
                let rs = requirements(alt);
                let Some(best) = rs
                    .into_iter()
                    .min_by_key(|mask| mask.iter().map(|word| word.count_ones()).sum::<u32>())
                else {
                    return Vec::new();
                };
                for i in 0..4 {
                    union[i] |= best[i];
                }
            }
            vec![union]
        }
        Expr::Repeat(e, min, _) if *min > 0 => requirements(e),
        _ => Vec::new(),
    }
}

struct Parser<'a> {
    bytes: &'a [u8],
    pos: usize,
}

impl Parser<'_> {
    fn take(&mut self) -> u8 {
        let b = self.bytes[self.pos];
        self.pos += 1;
        b
    }

    fn escaped(&mut self) -> Class {
        let b = self.take();
        let mut c = Class::default();
        match b {
            b'w' => return Class::word(),
            b'd' => {
                for b in b'0'..=b'9' {
                    c.insert(b);
                }
            }
            _ => c.insert(b),
        }
        c
    }

    fn number(&mut self) -> usize {
        let mut n = 0;
        while self.pos < self.bytes.len() && self.bytes[self.pos].is_ascii_digit() {
            n = n * 10 + usize::from(self.take() - b'0');
        }
        n
    }

    // Only invoked for the fixed, audited expressions above, never caller input.
    fn parse(&mut self) -> Expr {
        let mut alternatives = Vec::new();
        let mut sequence = Vec::new();
        while self.pos < self.bytes.len() && self.bytes[self.pos] != b')' {
            if self.bytes[self.pos] == b'|' {
                self.pos += 1;
                alternatives.push(Expr::Sequence(std::mem::take(&mut sequence)));
                continue;
            }
            let mut expr = if self.bytes[self.pos..].starts_with(b"(?<!\\d)") {
                self.pos += 7;
                Expr::Before
            } else if self.bytes[self.pos..].starts_with(b"(?!\\d)") {
                self.pos += 6;
                Expr::After
            } else {
                match self.take() {
                    b'(' => {
                        let e = self.parse();
                        assert_eq!(self.take(), b')');
                        e
                    }
                    b'[' => {
                        let mut c = Class::default();
                        while self.bytes[self.pos] != b']' {
                            let start = self.take();
                            if start == b'\\' {
                                let e = self.escaped();
                                for (dst, src) in c.0.iter_mut().zip(e.0) {
                                    *dst |= src;
                                }
                            } else if self.bytes[self.pos] == b'-'
                                && self.bytes[self.pos + 1] != b']'
                            {
                                self.pos += 1;
                                let end = self.take();
                                for b in start..=end {
                                    c.insert(b);
                                }
                            } else {
                                c.insert(start);
                            }
                        }
                        self.pos += 1;
                        Expr::Byte(c)
                    }
                    b'\\' => Expr::Byte(self.escaped()),
                    b'$' => Expr::End,
                    b'.' => {
                        let mut c = Class([u64::MAX; 4]);
                        c.0[0] &= !(1 << b'\n');
                        Expr::Byte(c)
                    }
                    b => {
                        let mut c = Class::default();
                        c.insert(b);
                        Expr::Byte(c)
                    }
                }
            };
            if self.pos < self.bytes.len() {
                let bounds = match self.bytes[self.pos] {
                    b'?' => Some((0, 1)),
                    b'*' => Some((0, usize::MAX)),
                    b'+' => Some((1, usize::MAX)),
                    b'{' => {
                        self.pos += 1;
                        let min = self.number();
                        let max = if self.bytes[self.pos] == b',' {
                            self.pos += 1;
                            if self.bytes[self.pos] == b'}' {
                                usize::MAX
                            } else {
                                self.number()
                            }
                        } else {
                            min
                        };
                        assert_eq!(self.bytes[self.pos], b'}');
                        Some((min, max))
                    }
                    _ => None,
                };
                if let Some((min, max)) = bounds {
                    self.pos += 1;
                    expr = Expr::Repeat(Box::new(expr), min, max);
                }
            }
            sequence.push(expr);
        }
        alternatives.push(Expr::Sequence(sequence));
        Expr::Alt(alternatives)
    }
}

#[derive(Debug)]
enum Op {
    Byte(Class, usize),
    Repeat(Class, usize, u8),
    TailRepeat(Class, usize, Class),
    Split(usize, usize),
    Before(usize),
    After(usize),
    End(usize),
    Accept,
}

fn compile(expr: &Expr, next: usize, ops: &mut Vec<Op>) -> usize {
    let op = match expr {
        Expr::Sequence(seq) => {
            return seq.iter().rev().fold(next, |next, e| compile(e, next, ops));
        }
        Expr::Alt(alts) => {
            let mut choices = alts.iter().rev();
            let mut entry = compile(choices.next().unwrap(), next, ops);
            for choice in choices {
                let first = compile(choice, next, ops);
                ops.push(Op::Split(first, entry));
                entry = ops.len() - 1;
            }
            return entry;
        }
        Expr::Byte(c) => Op::Byte(*c, next),
        Expr::Repeat(e, min, max) => {
            if let Expr::Byte(c) = **e {
                if *max == usize::MAX {
                    if let Op::Byte(end_c, 0) = ops[next] {
                        // Normalize the minimum before sharing tail failures:
                        // a shorter suffix can fail only because of its minimum.
                        ops.push(Op::TailRepeat(c, 0, end_c));
                        let mut entry = ops.len() - 1;
                        for _ in 0..*min {
                            ops.push(Op::Byte(c, entry));
                            entry = ops.len() - 1;
                        }
                        return entry;
                    }
                    // Normalize unbounded repeats so every scanned position is
                    // the same zero-minimum state and can share failure memo.
                    ops.push(Op::Repeat(c, next, 0));
                    let mut entry = ops.len() - 1;
                    for _ in 0..*min {
                        ops.push(Op::Byte(c, entry));
                        entry = ops.len() - 1;
                    }
                    return entry;
                }
                // Bounded byte repeats need only an acyclic greedy chain.
                // Reserve memo rows for unbounded repeat continuations.
                let mut entry = next;
                for _ in *min..*max {
                    ops.push(Op::Byte(c, entry));
                    let first = ops.len() - 1;
                    ops.push(Op::Split(first, next));
                    entry = ops.len() - 1;
                }
                for _ in 0..*min {
                    ops.push(Op::Byte(c, entry));
                    entry = ops.len() - 1;
                }
                return entry;
            } else {
                // Every repeated group in this pattern set is optional.
                assert_eq!((*min, *max), (0, 1));
                let first = compile(e, next, ops);
                Op::Split(first, next)
            }
        }
        Expr::Before => Op::Before(next),
        Expr::After => Op::After(next),
        Expr::End => Op::End(next),
    };
    ops.push(op);
    ops.len() - 1
}

#[derive(Clone, Copy)]
struct Lookahead {
    first0: Class,
    first1: Class,
    min_rem: usize,
}

impl Lookahead {
    #[inline]
    fn matches(self, input: &[u8], pos: usize) -> bool {
        input.len() - pos >= self.min_rem
            && (self.min_rem == 0 || self.first0.contains(input[pos]))
            && (self.min_rem < 2 || self.first1.contains(input[pos + 1]))
    }
}

// Only unbounded repeat continuations need memoization. Each row records
// failed candidate positions, rather than every byte/split instruction.
struct Memo {
    stack: [MaybeUninit<[u64; 4]>; 4],
    bits: Vec<u64>,
    stride: usize,
}

impl Memo {
    fn new(repeats: usize, len: usize) -> Self {
        assert!(repeats <= 4);
        let mut stack = [MaybeUninit::uninit(); 4];
        let (bits, stride) = if len <= 256 {
            for row in &mut stack[..repeats] {
                row.write([0; 4]);
            }
            (Vec::new(), 4)
        } else {
            let stride = (len + 1).div_ceil(64);
            (vec![0; repeats * stride], stride)
        };
        Self {
            stack,
            bits,
            stride,
        }
    }

    #[inline]
    fn word(&mut self, repeat_idx: u8, pos: usize) -> Option<&mut u64> {
        if self.stride == 4 {
            // 256 bytes have 257 positions. Leaving the terminal position
            // uncached costs constant work and keeps the rows at four words.
            if pos == 256 {
                return None;
            }
            // SAFETY: new initializes every row assigned to an Op::Repeat;
            // compiled repeat indices are unique and below the row count.
            Some(&mut unsafe { self.stack[usize::from(repeat_idx)].assume_init_mut() }[pos >> 6])
        } else {
            Some(&mut self.bits[usize::from(repeat_idx) * self.stride + (pos >> 6)])
        }
    }

    #[inline]
    fn seen(&mut self, repeat_idx: u8, pos: usize) -> bool {
        self.word(repeat_idx, pos)
            .is_some_and(|word| *word & (1 << (pos & 63)) != 0)
    }

    #[inline]
    fn visit(&mut self, repeat_idx: u8, pos: usize) -> bool {
        let Some(word) = self.word(repeat_idx, pos) else {
            return false;
        };
        let mask = 1 << (pos & 63);
        let seen = *word & mask != 0;
        *word |= mask;
        seen
    }
    // Reuse a cached title run only up to its first failed continuation. Do
    // not mark while scanning: those candidates still need their first try.
    fn repeat_limit(&mut self, repeat_idx: u8, pos: usize, end: usize) -> usize {
        let mut bit = pos + 1;
        let last = if self.stride == 4 { end.min(255) } else { end };
        while bit <= last {
            let high = last.min(bit | 63);
            let mask = (u64::MAX << (bit & 63)) & (u64::MAX >> (63 - (high & 63)));
            let seen = *self.word(repeat_idx, bit).unwrap() & mask;
            if seen != 0 {
                let first = (bit & !63) + seen.trailing_zeros() as usize;
                return first - 1;
            }
            bit = high + 1;
        }
        end
    }
}

// Most repeats share the title alphabet. Cache its runs across patterns;
// source and tail repeats then reuse the scan done by the first title repeat.
struct Subject<'a> {
    input: &'a [u8],
    title_runs: [Option<(usize, usize)>; 4],
    last_digit: Option<usize>,
}

impl Subject<'_> {
    fn repeat_end(
        &mut self,
        c: Class,
        pos: usize,
        limit: usize,
        memo: &mut Memo,
        repeat_idx: Option<u8>,
    ) -> usize {
        // Short names cost less to scan than to look up in the run cache.
        let cache = self.input.len() >= 128 && c.0 == Class::title().0;
        if cache {
            for &(start, end) in self.title_runs.iter().flatten() {
                if pos >= start && pos <= end {
                    return repeat_idx.map_or(end.min(limit), |idx| {
                        memo.repeat_limit(idx, pos, end.min(limit))
                    });
                }
            }
        }
        let mut end = pos;
        while end < limit && c.contains(self.input[end]) {
            end += 1;
            if repeat_idx.is_some_and(|idx| memo.seen(idx, end)) {
                // Revisited suffixes have already failed. Check during the
                // scan, including non-title classes such as OUTLAWS' dot.
                return end - 1;
            }
        }
        if cache && (end < limit || limit == self.input.len()) {
            self.title_runs.rotate_right(1);
            self.title_runs[0] = Some((pos, end));
        }
        end
    }
}

struct Pattern {
    name: &'static str,
    ops: Vec<Op>,
    entry: usize,
    repeat_count: usize,
    // All but SPORTS and OUTLAWS/ALIAS start with a word plus a greedy
    // title class. Later word starts in the same run cannot add a match.
    title: Option<Class>,
    first: Vec<Lookahead>,
    required: Vec<Bloom>,
    union_mask: Bloom,
    needs_digit: bool,
}

impl Pattern {
    fn new(name: &'static str, regex: &str) -> Self {
        let expr = Parser {
            bytes: regex.as_bytes(),
            pos: 0,
        }
        .parse();
        fn needs_digit(expr: &Expr) -> bool {
            match expr {
                Expr::Byte(c) => (0..=127u8)
                    .filter(|&b| c.contains(b))
                    .all(|b| b.is_ascii_digit()),
                Expr::Sequence(seq) => seq.iter().any(needs_digit),
                Expr::Alt(alts) => alts.iter().all(needs_digit),
                Expr::Repeat(e, min, _) => *min > 0 && needs_digit(e),
                _ => false,
            }
        }
        let needs_digit = needs_digit(&expr);
        let mut union_mask = [0; 4];
        let required = requirements(&expr)
            .into_iter()
            .filter(|mask| {
                if mask.iter().map(|word| word.count_ones()).sum::<u32>() == 1 {
                    for i in 0..4 {
                        union_mask[i] |= mask[i];
                    }
                    false
                } else {
                    true
                }
            })
            .collect();
        let top = match &expr {
            Expr::Alt(alts) if alts.len() == 1 => &alts[0],
            _ => &expr,
        };
        let title = match top {
            Expr::Sequence(seq) => match seq.get(1) {
                Some(Expr::Repeat(e, _, _)) => match **e {
                    Expr::Byte(c) => Some(c),
                    _ => None,
                },
                _ => None,
            },
            _ => None,
        };
        let mut ops = vec![Op::Accept];
        let entry = compile(&expr, 0, &mut ops);
        let mut repeat_count = 0;
        for op in &mut ops {
            if let Op::Repeat(_, _, repeat_idx) = op {
                *repeat_idx = u8::try_from(repeat_count).unwrap();
                repeat_count += 1;
            }
        }
        assert!(repeat_count <= 4, "{name}: too many memoized repeats");
        let mut first: Vec<Lookahead> = Vec::with_capacity(ops.len());
        for op in &ops {
            let mut look = match *op {
                Op::Accept => Lookahead {
                    first0: Class::default(),
                    first1: Class::default(),
                    min_rem: 0,
                },
                Op::Byte(c, next) => Lookahead {
                    first0: c,
                    first1: first[next].first0,
                    min_rem: 1 + first[next].min_rem,
                },
                Op::TailRepeat(c, min, end_c) => Lookahead {
                    first0: if min > 0 { c } else { c.union(end_c) },
                    first1: if min > 1 { c } else { c.union(end_c) },
                    min_rem: min + 1,
                },
                Op::Repeat(c, next, _) => {
                    let rest = first[next];
                    Lookahead {
                        first0: c.union(rest.first0),
                        first1: c.union(rest.first0).union(rest.first1),
                        min_rem: rest.min_rem,
                    }
                }
                Op::Split(a, b) => Lookahead {
                    first0: first[a].first0.union(first[b].first0),
                    first1: first[a].first1.union(first[b].first1),
                    min_rem: first[a].min_rem.min(first[b].min_rem),
                },
                Op::Before(next) | Op::After(next) | Op::End(next) => first[next],
            };
            // Nullable prefixes must never reject a valid short continuation.
            if look.min_rem == 0 {
                look.first0 = Class([u64::MAX; 4]);
            }
            if look.min_rem < 2 {
                look.first1 = Class([u64::MAX; 4]);
            }
            first.push(look);
        }
        Self {
            name,
            ops,
            entry,
            repeat_count,
            title,
            first,
            required,
            union_mask,
            needs_digit,
        }
    }

    fn run(
        &self,
        mut pc: usize,
        mut pos: usize,
        subject: &mut Subject<'_>,
        budget: &mut usize,
        memo: &mut Memo,
    ) -> Result<Option<usize>, ()> {
        let input = subject.input;
        loop {
            if *budget == 0 {
                return Err(());
            }
            *budget -= 1;
            match self.ops[pc] {
                Op::Byte(c, next) => {
                    if pos == input.len() || !c.contains(input[pos]) {
                        return Ok(None);
                    }
                    pos += 1;
                    pc = next;
                }
                Op::Repeat(c, next, repeat_idx) => {
                    if memo.seen(repeat_idx, pos) {
                        return Ok(None);
                    }
                    let end = subject.repeat_end(c, pos, input.len(), memo, Some(repeat_idx));
                    let look = self.first[next];
                    for candidate in (pos..=end).rev() {
                        // Lookahead failures must also be marked so later
                        // nested repeats cannot rescan the same near-miss run.
                        if memo.visit(repeat_idx, candidate) || !look.matches(input, candidate) {
                            continue;
                        }
                        if let Some(end) = self.run(next, candidate, subject, budget, memo)? {
                            return Ok(Some(end));
                        }
                    }
                    return Ok(None);
                }
                Op::TailRepeat(c, min, end_c) => {
                    let mut end = subject.repeat_end(c, pos, input.len(), memo, None);
                    if end - pos < min {
                        return Ok(None);
                    }
                    if end < input.len() && end_c.contains(input[end]) {
                        return Ok(Some(end + 1));
                    }
                    // The final byte is separate from the repeat's minimum.
                    while end > pos + min {
                        if end_c.contains(input[end - 1]) {
                            return Ok(Some(end));
                        }
                        end -= 1;
                    }
                    return Ok(None);
                }
                Op::Split(first, second) => {
                    if self.first[first].matches(input, pos) {
                        if let Some(end) = self.run(first, pos, subject, budget, memo)? {
                            return Ok(Some(end));
                        }
                    }
                    if !self.first[second].matches(input, pos) {
                        return Ok(None);
                    }
                    pc = second;
                }
                Op::Before(next) => {
                    if pos > 0 && input[pos - 1].is_ascii_digit() {
                        return Ok(None);
                    }
                    pc = next;
                }
                Op::After(next) => {
                    if pos < input.len() && input[pos].is_ascii_digit() {
                        return Ok(None);
                    }
                    pc = next;
                }
                Op::End(next) => {
                    if pos != input.len() && !(pos + 1 == input.len() && input[pos] == b'\n') {
                        return Ok(None);
                    }
                    pc = next;
                }
                Op::Accept => return Ok(Some(pos)),
            }
        }
    }

    #[cfg(test)]
    fn find<'a>(&self, input: &'a [u8], budget: &mut usize) -> Result<Option<&'a [u8]>, ()> {
        let mut subject = Subject {
            input,
            title_runs: [None; 4],
            last_digit: input.iter().rposition(u8::is_ascii_digit),
        };
        self.find_in(&mut subject, budget)
    }

    fn find_in<'a>(
        &self,
        subject: &mut Subject<'a>,
        budget: &mut usize,
    ) -> Result<Option<&'a [u8]>, ()> {
        let input = subject.input;
        let last_start = if self.needs_digit {
            let Some(last_digit) = subject.last_digit else {
                return Ok(None);
            };
            last_digit
        } else {
            input.len()
        };
        let mut memo = Memo::new(self.repeat_count, input.len());
        let mut start = 0;
        while start < input.len() && start <= last_start {
            if !Class::word().contains(input[start]) {
                start += 1;
                continue;
            }
            if let Some(end) = self.run(self.entry, start, subject, budget, &mut memo)? {
                return Ok(Some(&input[start..end]));
            }
            start += 1;
            if let Some(title) = self.title {
                while start < input.len() && title.contains(input[start]) {
                    start += 1;
                }
            }
        }
        Ok(None)
    }
}

// The same compiled patterns serve both individual and fused entry points.
fn patterns(index: usize) -> &'static [Pattern] {
    static COMPILED: OnceLock<[Vec<Pattern>; 4]> = OnceLock::new();
    &COMPILED.get_or_init(|| {
        [TV, MOVIE, GAME, APP].map(|source| {
            source
                .iter()
                .map(|&(name, regex)| Pattern::new(name, regex))
                .collect()
        })
    })[index]
}

unsafe fn prepare<'a>(
    input: *const u8,
    input_len: usize,
) -> Result<(Subject<'a>, [u64; 4]), isize> {
    if (input.is_null() && input_len != 0) || input_len > isize::MAX as usize {
        return Err(-1);
    }
    let input = if input_len == 0 {
        &[]
    } else {
        unsafe { slice::from_raw_parts(input, input_len) }
    };
    let mut bloom = [0u64; 4];
    let mut last_digit = None;
    // One shared pass validates ASCII, locates the last digit, and builds the
    // case-insensitive bigram bloom used by every checker.
    for (pos, &byte) in input.iter().enumerate() {
        if !byte.is_ascii() {
            return Err(if std::str::from_utf8(input).is_err() {
                -1
            } else {
                -2
            });
        }
        if byte.is_ascii_digit() {
            last_digit = Some(pos);
        }
        if pos > 0 {
            let hash = ((input[pos - 1].to_ascii_lowercase() as usize * 31)
                ^ byte.to_ascii_lowercase() as usize)
                & 255;
            bloom[hash >> 6] |= 1u64 << (hash & 63);
        }
    }
    if input_len > 4096 {
        return Err(-2);
    }
    Ok((
        Subject {
            input,
            title_runs: [None; 4],
            last_digit,
        },
        bloom,
    ))
}

fn first_match<'a>(
    index: usize,
    subject: &mut Subject<'a>,
    bloom: [u64; 4],
    budget: &mut usize,
) -> Result<Option<(&'static str, &'a [u8])>, isize> {
    for pattern in patterns(index) {
        if (bloom[0] & pattern.union_mask[0]) != pattern.union_mask[0]
            || (bloom[1] & pattern.union_mask[1]) != pattern.union_mask[1]
            || (bloom[2] & pattern.union_mask[2]) != pattern.union_mask[2]
            || (bloom[3] & pattern.union_mask[3]) != pattern.union_mask[3]
            || pattern
                .required
                .iter()
                .any(|&mask| !intersects(bloom, mask))
        {
            continue;
        }
        if let Some(matched) = pattern.find_in(subject, budget).map_err(|()| -2isize)? {
            return Ok(Some((pattern.name, matched)));
        }
    }
    Ok(None)
}

unsafe fn check(
    input: *const u8,
    input_len: usize,
    out_buf: *mut u8,
    out_cap: usize,
    index: usize,
) -> isize {
    let (mut subject, bloom) = match unsafe { prepare(input, input_len) } {
        Ok(prepared) => prepared,
        Err(code) => return code,
    };
    match first_match(index, &mut subject, bloom, &mut 100_000) {
        Ok(Some((name, matched))) => {
            let len = name.len() + 1 + matched.len();
            if out_buf.is_null() || out_cap < len {
                return -3;
            }
            unsafe {
                std::ptr::copy_nonoverlapping(name.as_ptr(), out_buf, name.len());
                *out_buf.add(name.len()) = 0;
                std::ptr::copy_nonoverlapping(
                    matched.as_ptr(),
                    out_buf.add(name.len() + 1),
                    matched.len(),
                );
            }
            len as isize
        }
        Ok(None) => 0,
        Err(code) => code,
    }
}

unsafe fn check_all(
    input: *const u8,
    input_len: usize,
    out_buf: *mut u8,
    out_cap: usize,
    collect: bool,
) -> isize {
    let (mut subject, bloom) = match unsafe { prepare(input, input_len) } {
        Ok(prepared) => prepared,
        Err(code) => return code,
    };
    let mut matches = [None; 4];
    let mut len = 0;
    for (index, slot) in matches.iter_mut().enumerate() {
        // Keep the individual checker's bounded-work allowance. A decline in
        // any checker declines the whole call, with no partial output.
        match first_match(index, &mut subject, bloom, &mut 100_000) {
            Ok(Some((name, matched))) => {
                // NUL-delimited collected records cannot represent an embedded
                // NUL consumed by OUTLAWS/ALIAS' dot repeat. PHP retains it.
                if collect && matched.contains(&0) {
                    return -2;
                }

                if len > 0 {
                    len += 1;
                } // record separator
                len += 2 + name.len() + 1 + matched.len();
                *slot = Some((name, matched));
                if !collect {
                    break;
                }
            }
            Ok(None) => {}
            Err(code) => return code,
        }
    }
    if len == 0 {
        return 0;
    }
    if out_buf.is_null() || out_cap < len {
        return -3;
    }
    let mut offset = 0;
    for (index, (name, matched)) in matches
        .into_iter()
        .enumerate()
        .filter_map(|(i, m)| m.map(|m| (i, m)))
    {
        unsafe {
            if offset > 0 {
                *out_buf.add(offset) = 0;
                offset += 1;
            }
            *out_buf.add(offset) = index as u8;
            *out_buf.add(offset + 1) = 0;
            offset += 2;
            std::ptr::copy_nonoverlapping(name.as_ptr(), out_buf.add(offset), name.len());
            offset += name.len();
            *out_buf.add(offset) = 0;
            offset += 1;
            std::ptr::copy_nonoverlapping(matched.as_ptr(), out_buf.add(offset), matched.len());
            offset += matched.len();
        }
    }
    len as isize
}

macro_rules! export_checker {
    ($function:ident, $index:expr) => {
        /// Returns `PatternName\0MatchedText`, zero for no match, or -1 invalid
        /// input, -2 decline to PHP, -3 insufficient output capacity.
        ///
        /// # Safety
        /// Input must be readable for `input_len` bytes; output must be writable
        /// for `out_cap` bytes. The allocations must not overlap. Null input is
        /// allowed only with zero length; null output is treated as zero capacity.
        #[unsafe(no_mangle)]
        pub unsafe extern "C" fn $function(
            input: *const u8,
            input_len: usize,
            out_buf: *mut u8,
            out_cap: usize,
        ) -> isize {
            unsafe { check(input, input_len, out_buf, out_cap, $index) }
        }
    };
}
export_checker!(nntmux_name_check_tv, 0);
export_checker!(nntmux_name_check_movie, 1);
export_checker!(nntmux_name_check_game, 2);
export_checker!(nntmux_name_check_app, 3);

/// Returns the first `CheckerIdx\0PatternName\0MatchedText` in TV/Movie/Game/App
/// order, zero if none, or -1 invalid input, -2 decline, -3 insufficient capacity.
/// CheckerIdx is a raw byte (0 through 3), not an ASCII digit.
///
/// # Safety
/// Input must be readable for `input_len` bytes and output writable for
/// `out_cap` bytes, with no overlap. Null input requires zero length.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_name_check_all_first(
    input: *const u8,
    input_len: usize,
    out_buf: *mut u8,
    out_cap: usize,
) -> isize {
    unsafe { check_all(input, input_len, out_buf, out_cap, false) }
}

/// Returns up to four records as in `nntmux_name_check_all_first`, separated by
/// NUL, without a trailing NUL. Errors never write partial records.
///
/// # Safety
/// Input must be readable for `input_len` bytes and output writable for
/// `out_cap` bytes, with no overlap. Null input requires zero length.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_name_check_all_collect(
    input: *const u8,
    input_len: usize,
    out_buf: *mut u8,
    out_cap: usize,
) -> isize {
    unsafe { check_all(input, input_len, out_buf, out_cap, true) }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn fused_records_preserve_checker_order_and_atomic_output() {
        let input = b"Show.S01E01.1080p.NF.WEB-DL-GROUP!Movie.2023.2160p.HDR10.BluRay.HEVC-GROUP!Game.Name-OUTLAWS!Adobe.Photoshop.2024.v25.0-GROUP";
        let mut expected = Vec::new();
        let mut buf = [0x7f; 2048];
        for index in 0..4 {
            let len = unsafe {
                check(
                    input.as_ptr(),
                    input.len(),
                    buf.as_mut_ptr(),
                    buf.len(),
                    index,
                )
            };
            assert!(len > 0);
            if index > 0 {
                expected.push(0);
            }
            expected.extend_from_slice(&[index as u8, 0]);
            expected.extend_from_slice(&buf[..len as usize]);
        }
        let len = unsafe {
            nntmux_name_check_all_collect(
                input.as_ptr(),
                input.len(),
                buf.as_mut_ptr(),
                expected.len(),
            )
        };
        assert_eq!(len as usize, expected.len());
        assert_eq!(&buf[..len as usize], expected);
        buf.fill(0x7f);
        assert_eq!(
            unsafe {
                nntmux_name_check_all_collect(
                    input.as_ptr(),
                    input.len(),
                    buf.as_mut_ptr(),
                    expected.len() - 1,
                )
            },
            -3
        );
        assert!(buf.iter().all(|&b| b == 0x7f));
        let len = unsafe {
            nntmux_name_check_all_first(input.as_ptr(), input.len(), buf.as_mut_ptr(), buf.len())
        };
        assert_eq!(&buf[..len as usize], &expected[..len as usize]);
        let binary = b"Game.Na\0me-OUTLAWS";
        buf.fill(0x7f);
        assert_eq!(
            unsafe {
                nntmux_name_check_all_collect(
                    binary.as_ptr(),
                    binary.len(),
                    buf.as_mut_ptr(),
                    buf.len(),
                )
            },
            -2
        );
        assert!(buf.iter().all(|&b| b == 0x7f));
    }

    #[test]
    fn fixed_patterns_compile() {
        for &(name, regex) in TV.iter().chain(MOVIE).chain(GAME).chain(APP) {
            Pattern::new(name, regex);
        }
    }

    #[test]
    fn rejection_filters_include_small_classes_and_required_repeat_boundaries() {
        for (regex, positives, negative) in [
            (r#"s\d{1,2}"#, ["s0", "S99"], "subtitle"),
            (r#"(19|20)\d\d"#, ["1999", "2024"], "20x"),
            (r#"PS[345P]"#, ["PS5", "psp"], "PSx"),
            (r#"[._ -]GOG"#, [".GOG", " gog"], "GOG"),
        ] {
            let expr = Parser {
                bytes: regex.as_bytes(),
                pos: 0,
            }
            .parse();
            let required = requirements(&expr);
            let passes = |input: &str| {
                let mut bloom = [0; 4];
                for pair in input.as_bytes().windows(2) {
                    let mask = bigram(pair[0].to_ascii_lowercase(), pair[1].to_ascii_lowercase());
                    for i in 0..4 {
                        bloom[i] |= mask[i];
                    }
                }
                required.iter().all(|&mask| intersects(bloom, mask))
            };
            for input in positives {
                assert!(passes(input), "false rejection: {regex} {input}");
            }
            assert!(
                !passes(negative),
                "missing class constraint: {regex} {negative}"
            );
        }
    }

    #[test]
    fn repeat_memo_preserves_matches_at_stack_and_heap_boundaries() {
        let pattern = Pattern::new("boundary", r#"\w[.a]+a{1,3}[.a]+Z$"#);
        for len in [63, 64, 65, 127, 128, 129, 255, 256, 257, 4096] {
            let input = format!("X{}Z", "a".repeat(len - 2));
            assert_eq!(
                pattern.find(input.as_bytes(), &mut 100_000),
                Ok(Some(input.as_bytes()))
            );
            let miss = format!("X{}!", "a".repeat(len - 2));
            assert_eq!(pattern.find(miss.as_bytes(), &mut 100_000), Ok(None));
        }
    }

    #[test]
    fn memo_keeps_repeat_rows_independent_without_short_input_heap_storage() {
        for len in [0, 1, 63, 64, 65, 255, 256, 257, 4096] {
            for repeats in 1..=4 {
                let mut memo = Memo::new(repeats, len);
                if len <= 256 {
                    assert_eq!(memo.bits.capacity(), 0);
                }
                for repeat_idx in 0..repeats as u8 {
                    for pos in 0..=len {
                        assert!(!memo.seen(repeat_idx, pos));
                        assert!(!memo.visit(repeat_idx, pos));
                        // The extra terminal position has no stack bit.
                        if len != 256 || pos != 256 {
                            assert!(memo.visit(repeat_idx, pos));
                        }
                    }
                }
            }
        }
    }

    #[test]
    fn nested_repeats_have_linear_work() {
        let pattern = Pattern::new(
            "stress",
            r#"\w[\-\w.',;& ]+(19|20)\d\d[\-\w.',;& ]+(480|720|1080|2160)[ip]?[\-\w.',;& ]+(BluRay|BDRip|DVDRip|HDTV|WEB-?DL|WEB-?RIP)[\-\w.',;& ]+\w"#,
        );
        let input = format!("Title.{}BluRay!", "2023.1080p.".repeat(150));
        let mut budget = 100_000;
        assert_eq!(pattern.find(input.as_bytes(), &mut budget), Ok(None));
    }

    #[test]
    fn tail_repeat_preserves_minimum_and_greedy_span() {
        let pattern = Pattern::new("tail", r#"\w[.a]+\w"#);
        for (input, expected) in [
            ("X.a!", Some("X.a")),
            ("Xa!", None),
            ("X..z!", Some("X..z")),
            ("X.aa..a!", Some("X.aa..a")),
        ] {
            assert_eq!(
                pattern.find(input.as_bytes(), &mut 100_000).unwrap(),
                expected.map(str::as_bytes)
            );
        }
    }

    #[test]
    fn optimized_states_match_exhaustive_reference_backtracking() {
        fn reference(expr: &Expr, input: &[u8], pos: usize) -> Vec<usize> {
            match expr {
                Expr::Byte(c) => {
                    if pos < input.len() && c.contains(input[pos]) {
                        vec![pos + 1]
                    } else {
                        vec![]
                    }
                }
                Expr::Sequence(seq) => seq.iter().fold(vec![pos], |positions, e| {
                    positions
                        .into_iter()
                        .flat_map(|p| reference(e, input, p))
                        .collect()
                }),
                Expr::Alt(alts) => alts.iter().flat_map(|e| reference(e, input, pos)).collect(),
                Expr::Repeat(e, min, max) => {
                    let mut layers = vec![vec![pos]];
                    for _ in 0..(*max).min(input.len() + 1) {
                        let next: Vec<_> = layers
                            .last()
                            .unwrap()
                            .iter()
                            .flat_map(|&p| reference(e, input, p))
                            .collect();
                        if next.is_empty() {
                            break;
                        }
                        layers.push(next);
                    }
                    layers
                        .into_iter()
                        .enumerate()
                        .rev()
                        .filter(|(n, _)| n >= min)
                        .flat_map(|(_, positions)| positions)
                        .collect()
                }
                Expr::Before if pos == 0 || !input[pos - 1].is_ascii_digit() => vec![pos],
                Expr::After if pos == input.len() || !input[pos].is_ascii_digit() => vec![pos],
                Expr::End
                    if pos == input.len() || (pos + 1 == input.len() && input[pos] == b'\n') =>
                {
                    vec![pos]
                }
                _ => vec![],
            }
        }
        for regex in [
            r#"\w[.a1]+\w"#,
            r#"\w[.a1]+(a)?[.a1]+\w"#,
            r#"\w[.a1]+(aa|a1|a)[.a1]+(a|1)[.a1]+\w"#,
            r#"\w[.a1]*(a|a1)?[.a1]+\w"#,
            r#"\w[.a1]+a?1?[.a1]+\w"#,
            r#"\w[.a1]+a{1,3}[.a1]+\w"#,
            r#"\w[.a1]+(?<!\d)1(?!\d)[.a1]+\w"#,
            r#"\w[.a1]+(a|1)[.a1]+$"#,
        ] {
            let expr = Parser {
                bytes: regex.as_bytes(),
                pos: 0,
            }
            .parse();
            let pattern = Pattern::new("reference", regex);
            for len in 0..=6u32 {
                for mut code in 0..5usize.pow(len) {
                    let mut input = vec![0; len as usize];
                    for byte in &mut input {
                        *byte = b"a1.!\n"[code % 5];
                        code /= 5;
                    }
                    let expected = (0..input.len()).find_map(|start| {
                        reference(&expr, &input, start)
                            .first()
                            .map(|&end| (start, end))
                    });
                    let mut subject = Subject {
                        input: &input,
                        title_runs: [None; 4],
                        last_digit: input.iter().rposition(u8::is_ascii_digit),
                    };
                    let mut memo = Memo::new(pattern.repeat_count, input.len());
                    let mut actual = None;
                    for start in 0..input.len() {
                        if let Some(end) = pattern
                            .run(pattern.entry, start, &mut subject, &mut 100_000, &mut memo)
                            .unwrap()
                        {
                            actual = Some((start, end));
                            break;
                        }
                    }
                    assert_eq!(actual, expected, "{regex} {input:?}");
                }
            }
        }
    }

    #[test]
    fn ffi_contract_and_greedy_span() {
        let input = b"[Game.Name-OUTLAWS and Other-OUTLAWS]";
        let mut buf = [0x7f; 128];
        let call = |input: &[u8], buf: &mut [u8]| unsafe {
            nntmux_name_check_game(input.as_ptr(), input.len(), buf.as_mut_ptr(), buf.len())
        };
        let n = call(input, &mut buf);
        assert!(n > 0);
        assert_eq!(
            &buf[..n as usize],
            b"OUTLAWS\0Game.Name-OUTLAWS and Other-OUTLAWS"
        );
        assert_eq!(call(input, &mut buf[..1]), -3);
        assert_eq!(call(b"\xff", &mut buf), -1);
        assert_eq!(call("Café".as_bytes(), &mut buf), -2);
        assert_eq!(call(b"", &mut buf), 0);
        assert_eq!(call(b"No release", &mut buf), 0);
        assert_eq!(
            unsafe { nntmux_name_check_tv(std::ptr::null(), 1, std::ptr::null_mut(), 0) },
            -1
        );
    }
}

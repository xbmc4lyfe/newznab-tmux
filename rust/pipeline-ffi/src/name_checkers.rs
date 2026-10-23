//! Byte-oriented, ordered PCRE-subset matchers for the name-fixing checkers.
//!
//! These patterns have no backreferences and callers use only capture zero.
//! Greedy repetitions and ordered alternatives therefore need no capture state.
//! No regex crate, system library, or runtime dependency is required.

use std::{slice, sync::OnceLock};

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
fn requirements(expr: &Expr) -> Vec<Vec<usize>> {
    fn literal(expr: &Expr) -> Option<u8> {
        let Expr::Byte(c) = expr else {
            return None;
        };
        let mut bytes = (0u8..=127).filter(|&b| c.contains(b) && !b.is_ascii_uppercase());
        let b = bytes.next()?;
        if bytes.next().is_none() {
            Some(b)
        } else {
            None
        }
    }
    match expr {
        Expr::Sequence(seq) => {
            let mut result: Vec<Vec<usize>> = seq.iter().flat_map(requirements).collect();
            for pair in seq.windows(2) {
                if let (Some(a), Some(b)) = (literal(&pair[0]), literal(&pair[1])) {
                    result.push(vec![usize::from(a) * 128 + usize::from(b)]);
                }
            }
            result
        }
        Expr::Alt(alts) if alts.len() == 1 => requirements(&alts[0]),
        Expr::Alt(alts) => {
            let mut union = Vec::new();
            for alt in alts {
                let rs = requirements(alt);
                let Some(best) = rs.into_iter().min_by_key(Vec::len) else {
                    return Vec::new();
                };
                union.extend(best);
            }
            union.sort_unstable();
            union.dedup();
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
    Repeat(Class, usize, usize, usize),
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
                Op::Repeat(c, *min, *max, next)
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

struct Pattern {
    name: &'static str,
    ops: Vec<Op>,
    entry: usize,
    // All but SPORTS and OUTLAWS/ALIAS start with a word plus a greedy
    // title class. Later word starts in the same run cannot add a match.
    title: Option<Class>,
    first: Vec<(Class, bool)>,
    required: Vec<Vec<usize>>,
}

impl Pattern {
    fn new(name: &'static str, regex: &str) -> Self {
        let expr = Parser {
            bytes: regex.as_bytes(),
            pos: 0,
        }
        .parse();
        let required = requirements(&expr);
        let mut ops = vec![Op::Accept];
        let entry = compile(&expr, 0, &mut ops);
        let title = match ops[entry] {
            Op::Byte(_, next) => match ops[next] {
                Op::Repeat(c, _, _, _) => Some(c),
                _ => None,
            },
            _ => None,
        };
        // Every edge points to an earlier instruction, so FIRST sets can be
        // computed in one pass. Skip impossible greedy backtracking positions.
        let mut first: Vec<(Class, bool)> = Vec::with_capacity(ops.len());
        for op in &ops {
            let (mut c, nullable) = match *op {
                Op::Accept => (Class::default(), true),
                Op::Byte(c, _) => (c, false),
                Op::Repeat(c, min, _, next) => {
                    let mut c = c;
                    if min == 0 {
                        for (dst, src) in c.0.iter_mut().zip(first[next].0.0) {
                            *dst |= src;
                        }
                    }
                    (c, min == 0 && first[next].1)
                }
                Op::Split(a, b) => {
                    let mut c = first[a].0;
                    for (dst, src) in c.0.iter_mut().zip(first[b].0.0) {
                        *dst |= src;
                    }
                    (c, first[a].1 || first[b].1)
                }
                Op::Before(next) | Op::After(next) | Op::End(next) => first[next],
            };
            // A nullable continuation can accept without looking at a byte.
            if nullable {
                c = Class([u64::MAX; 4]);
            }
            first.push((c, nullable));
        }
        Self {
            name,
            ops,
            entry,
            title,
            first,
            required,
        }
    }

    fn run(
        &self,
        mut pc: usize,
        mut pos: usize,
        input: &[u8],
        budget: &mut usize,
    ) -> Result<Option<usize>, ()> {
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
                Op::Repeat(c, min, max, next) => {
                    let mut end = pos;
                    let limit = input.len().min(pos.saturating_add(max));
                    while end < limit && c.contains(input[end]) {
                        end += 1;
                    }
                    if end - pos < min {
                        return Ok(None);
                    }
                    let (first, nullable) = self.first[next];
                    for candidate in (pos + min..=end).rev() {
                        if !nullable
                            && (candidate == input.len() || !first.contains(input[candidate]))
                        {
                            continue;
                        }
                        if let Some(end) = self.run(next, candidate, input, budget)? {
                            return Ok(Some(end));
                        }
                    }
                    return Ok(None);
                }
                Op::Split(first, second) => {
                    if let Some(end) = self.run(first, pos, input, budget)? {
                        return Ok(Some(end));
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

    fn find<'a>(&self, input: &'a [u8], budget: &mut usize) -> Result<Option<&'a [u8]>, ()> {
        let mut start = 0;
        while start < input.len() {
            if !Class::word().contains(input[start]) {
                start += 1;
                continue;
            }
            if let Some(end) = self.run(self.entry, start, input, budget)? {
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

unsafe fn check(
    input: *const u8,
    input_len: usize,
    out_buf: *mut u8,
    out_cap: usize,
    source: &'static [(&'static str, &'static str)],
    compiled: &'static OnceLock<Vec<Pattern>>,
) -> isize {
    if (input.is_null() && input_len != 0) || input_len > isize::MAX as usize {
        return -1;
    }
    let input = if input_len == 0 {
        &[]
    } else {
        unsafe { slice::from_raw_parts(input, input_len) }
    };
    if std::str::from_utf8(input).is_err() {
        return -1;
    }
    // PHP's byte-mode PCRE can use locale-specific character tables. Decline
    // non-ASCII, and bounded-work exhaustion, rather than guessing their result.
    if !input.is_ascii() || input_len > 4096 {
        return -2;
    }
    let patterns =
        compiled.get_or_init(|| source.iter().map(|&(n, r)| Pattern::new(n, r)).collect());
    let mut pairs = [0u64; 256];
    for pair in input.windows(2) {
        let id = usize::from(pair[0].to_ascii_lowercase()) * 128
            + usize::from(pair[1].to_ascii_lowercase());
        pairs[id / 64] |= 1 << (id % 64);
    }
    let mut budget = 100_000;
    for pattern in patterns {
        if pattern.required.iter().any(|constraint| {
            !constraint
                .iter()
                .any(|&id| pairs[id / 64] & (1 << (id % 64)) != 0)
        }) {
            continue;
        }
        match pattern.find(input, &mut budget) {
            Ok(Some(matched)) => {
                let len = pattern.name.len() + 1 + matched.len();
                if out_buf.is_null() || out_cap < len {
                    return -3;
                }
                // No partial writes on failure. The caller owns distinct input
                // and output allocations for the duration of this call.
                unsafe {
                    std::ptr::copy_nonoverlapping(
                        pattern.name.as_ptr(),
                        out_buf,
                        pattern.name.len(),
                    );
                    *out_buf.add(pattern.name.len()) = 0;
                    std::ptr::copy_nonoverlapping(
                        matched.as_ptr(),
                        out_buf.add(pattern.name.len() + 1),
                        matched.len(),
                    );
                }
                return len as isize;
            }
            Ok(None) => {}
            Err(()) => return -2,
        }
    }
    0
}

macro_rules! export_checker {
    ($function:ident, $source:ident) => {
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
            static COMPILED: OnceLock<Vec<Pattern>> = OnceLock::new();
            unsafe { check(input, input_len, out_buf, out_cap, $source, &COMPILED) }
        }
    };
}

export_checker!(nntmux_name_check_tv, TV);
export_checker!(nntmux_name_check_movie, MOVIE);
export_checker!(nntmux_name_check_game, GAME);
export_checker!(nntmux_name_check_app, APP);

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn fixed_patterns_compile() {
        for &(name, regex) in TV.iter().chain(MOVIE).chain(GAME).chain(APP) {
            Pattern::new(name, regex);
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

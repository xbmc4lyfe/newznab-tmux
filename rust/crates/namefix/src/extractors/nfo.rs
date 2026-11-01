//! `NfoNameExtractor`: release names from NFO text.
//!
//! Every stage works on [`identity_text`], the NFO with its `Source :` fields removed, so an
//! upstream release named in a Source line is never taken for this release's identity. The
//! technical enrichment (language, resolution, source, codecs) reads the original NFO.
//!
//! PHP quirk kept: the IGUANA stage reads `$result[2]` of a pattern whose second group is the
//! optional ` MONO`. When it is absent PHP raises "Undefined array key 2", which Laravel turns
//! into an `ErrorException`; this port, like PHP with warnings ignored, appends a bare `.`.

use nntmux_categorize::pcre::{PHP_WS, trim_chars};
use nntmux_categorize::{mi, ms, rx};
use nntmux_core::pcre::Pcre;
use nntmux_predb::filename::{
    clean_for_matching, exact_value, has_control, is_literal_technical_scene_title,
};
use nntmux_predb::frx;

use crate::compat::{caps, pcre_caps, str_replace};
use crate::result::NameFixResult;
use crate::{pcre, rx_m};

fn found(name: impl Into<String>, method: &str) -> Option<NameFixResult> {
    Some(NameFixResult::from_match(name, method, "NFO"))
}

/// The NFO without upstream `Source` fields. Line boundaries are kept (as `\n`), and an
/// indented continuation of a Source field is blanked until the next field or a blank line;
/// a Windows drive path (`C:\...`) stays a continuation.
pub fn identity_text(nfo: &str) -> String {
    let mut source_field = false;
    let mut lines = Vec::new();
    for line in split_lines(nfo) {
        if mi!(line, r"^[ \t]*Source[ \t]*:") {
            source_field = true;
            lines.push("");
            continue;
        }
        if source_field
            && ms!(line, r"^[ \t]+\S")
            && (!ms!(line, r"^[ \t]*[A-Za-z][A-Za-z0-9 _-]*[ \t]*:")
                || ms!(line, r"^[ \t]*[A-Za-z]:[\\/]"))
        {
            lines.push("");
            continue;
        }
        source_field = false;
        lines.push(line);
    }
    lines.join("\n")
}

/// `preg_split('/\r\n|\r|\n/')`.
fn split_lines(text: &str) -> Vec<&str> {
    let bytes = text.as_bytes();
    let mut lines = Vec::new();
    let (mut start, mut at) = (0, 0);
    while at < bytes.len() {
        match bytes[at] {
            b'\r' | b'\n' => {
                lines.push(&text[start..at]);
                at += if bytes[at] == b'\r' && bytes.get(at + 1) == Some(&b'\n') {
                    2
                } else {
                    1
                };
                start = at;
            }
            _ => at += 1,
        }
    }
    lines.push(&text[start..]);
    lines
}

/// `PREDB_REGEX`, shared with the file extractor and the update service.
fn predb_regex() -> &'static Pcre {
    pcre!(r"/([\w.'()\[\]-]+(?:[\s._-]+[\w.'()\[\]-]+)+[-.][\w]+)/ui")
}

/// Names the NFO declares on `Filename`, `File Name`, `Complete Name` and `Release` lines.
fn literal_name_fields(identity: &str) -> Vec<String> {
    rx_m!(r"^[ \t]*(?:Filename|File[ \t]+Name|Complete[ \t]+Name|Release(?:[ \t]+Name)?)[ \t]*[:=][ \t]*([^\r\n]+)$")
        .captures_iter(identity.as_bytes())
        .filter_map(|c| c.get(1))
        .map(|field| {
            let field = String::from_utf8_lossy(field.as_bytes());
            trim_chars(&field, PHP_WS).to_owned()
        })
        .collect()
}

/// Every non-overlapping match of the PreDB-shaped name pattern, in order.
///
/// `Pcre` can't iterate, and its matches carry no offsets. The pattern has no anchor, boundary
/// or lookaround, so a match is the same wherever the search resumes, and the first occurrence
/// of the matched text in the rest of the subject is where it was found.
fn predb_matches(identity: &str) -> impl Iterator<Item = String> {
    let mut rest = identity;
    std::iter::from_fn(move || {
        let found = pcre_caps(predb_regex(), rest)?;
        let text = found.text_or_empty(0);
        let at = rest.find(&text)?;
        rest = &rest[at + text.len()..];
        Some(text)
    })
}

/// A bounded Source-safe view for authoritative lookup.
#[derive(Clone, Debug, Default, PartialEq)]
pub struct PredbCandidates {
    /// Distinct titles (by lowercase), in first-seen order, each with its last spelling.
    pub titles: Vec<String>,
    /// False when there were more than 100 distinct titles, which leaves `titles` empty.
    pub complete: bool,
}

/// `predbCandidates`: the declared name fields (cleaned like file names) and every PreDB-shaped
/// run of text, minus anything over 255 characters or containing control characters.
pub fn predb_candidates(nfo: &str) -> PredbCandidates {
    let identity = identity_text(nfo);
    let declared = literal_name_fields(&identity)
        .into_iter()
        .filter_map(|field| clean_for_matching(&field, false));
    let shaped = predb_matches(&identity).map(|title| trim_chars(&title, PHP_WS).to_owned());

    // A PHP array keeps a key's first position when a later title overwrites its value.
    let mut titles: Vec<(String, String)> = Vec::new();
    for title in declared.chain(shaped) {
        if title.chars().count() > 255 || has_control(&title) {
            continue;
        }
        let key = exact_value(&title);
        match titles.iter_mut().find(|(seen, _)| *seen == key) {
            Some(slot) => slot.1 = title,
            None => titles.push((key, title)),
        }
        if titles.len() > 100 {
            return PredbCandidates {
                titles: Vec::new(),
                complete: false,
            };
        }
    }
    PredbCandidates {
        titles: titles.into_iter().map(|(_, title)| title).collect(),
        complete: true,
    }
}

/// `checkForTv`.
pub fn check_for_tv(nfo: &str) -> Option<NameFixResult> {
    let nfo = identity_text(nfo);
    if let Some(c) = caps(
        rx!(i r":\s*.*[\\\/]([A-Z0-9].+?S\d+[.-_ ]?[ED]\d+.+?)\.\w{2,}\s+"),
        &nfo,
    ) {
        return found(c.text_or_empty(1), "Generic TV 1");
    }
    if let Some(c) = caps(
        rx!(i r"(?:(\:\s{1,}))(.+?S\d{1,3}[.-_ ]?[ED]\d{1,3}.+?)(\s{2,}|\r|\n)"),
        &nfo,
    ) {
        return found(c.text_or_empty(2), "Generic TV 2");
    }
    None
}

/// `checkForMovie`.
pub fn check_for_movie(nfo: &str) -> Option<NameFixResult> {
    let nfo = identity_text(nfo);
    if let Some(c) = pcre_caps(
        frx!(i r"(?:((?!Source\s)\:\s{1,}))(.+?(19|20)\d\d.+?(BDRip|bluray|DVD(R|Rip)?|XVID).+?)(\s{2,}|\r|\n)"),
        &nfo,
    ) {
        return found(c.text_or_empty(2), "Generic Movies 1");
    }
    if let Some(c) = pcre_caps(
        frx!(i r"(?:(\s{2,}))((?!Source).+?[\.\-_ ](19|20)\d\d.+?(BDRip|bluray|DVD(R|Rip)?|XVID).+?)(\s{2,}|\r|\n)"),
        &nfo,
    ) {
        return found(c.text_or_empty(2), "Generic Movies 2");
    }
    if let Some(c) = caps(
        rx!(i r"(?:(\s{2,}))(.+?[\.\-_ ](NTSC|MULTi).+?(MULTi|DVDR)[\.\-_ ].+?)(\s{2,}|\r|\n)"),
        &nfo,
    ) {
        return found(c.text_or_empty(2), "Generic Movies 3");
    }
    None
}

/// `checkForMusic`.
pub fn check_for_music(nfo: &str) -> Option<NameFixResult> {
    let nfo = identity_text(nfo);
    let c = caps(rx!(i r"(?:\s{2,})(.+?-FM-\d{2}-\d{2})"), &nfo)?;
    found(
        str_replace(&c.text_or_empty(1), "-FM-", "-FM-Radio-MP3-"),
        "Music FM RADIO",
    )
}

/// `checkForTitleYear`: `Title (Year)` plus whatever language, resolution, source and audio
/// codec the NFO states.
pub fn check_for_title_year(nfo: &str) -> Option<NameFixResult> {
    let metadata = nfo;
    let nfo = identity_text(nfo);
    if mi!(nfo, r"\.pdf|Audio ?Book") {
        return None;
    }
    let c = caps(
        rx!(i r#"(\w[\-\w`~!@#$%^&*()_+={}|"<>?\[\]\;',.\/ ]+\s?\((19|20)\d\d\))"#),
        &nfo,
    )?;
    let mut name = c.text_or_empty(0);
    name = append_language(name, metadata);
    name = append_resolution(name, metadata);
    name = append_source(name, metadata);
    name = append_audio_codec(name, metadata);
    name.push_str("-NoGroup");
    found(name, "Title (Year)")
}

/// `checkForGame`: only NFOs naming a known game group or `Game Type`.
pub fn check_for_game(nfo: &str) -> Option<NameFixResult> {
    let nfo = identity_text(nfo);
    if !mi!(
        nfo,
        r"ALiAS|BAT-TEAM|FAiRLiGHT|Game Type|Glamoury|HI2U|iTWINS|JAGUAR|(LARGE|MEDIUM)ISO|MAZE|nERv|PROPHET|PROFiT|PROCYON|RELOADED|REVOLVER|ROGUE|ViTALiTY"
    ) {
        return None;
    }
    if let Some(c) = caps(rx!(i r"\w[\w.+&*\/\()',;: -]+\(c\)[\-\w.',;& ]+\w"), &nfo) {
        // str_replace with an array applies each search in turn.
        let name = str_replace(&c.text_or_empty(0), "(c)", "(GAMES) (c)");
        return found(str_replace(&name, "(C)", "(GAMES) (c)"), "PC Games (c)");
    }
    if let Some(c) = caps(rx!(i r"\w[\w.+&*\/()',;: -]+\*ISO\*"), &nfo) {
        return found(
            str_replace(&c.text_or_empty(0), "*ISO*", "*ISO* (PC GAMES)"),
            "PC Games *ISO*",
        );
    }
    None
}

/// `checkForIguana`: NFOs from the IGUANA supplier, assembled from their field lines.
pub fn check_for_iguana(nfo: &str) -> Option<NameFixResult> {
    let nfo = identity_text(nfo);
    if !mi!(nfo, r"Supplier.+?IGUANA") {
        return None;
    }
    let mut name = String::new();
    if let Some(c) = caps(
        rx!(i r#"\w[\-\w`~!@#$%^&*()+={}|:"<>?\[\]\;',.\/ ]+\s\((19|20)\d\d\)"#),
        &nfo,
    ) {
        name = c.text_or_empty(0);
    }
    if let Some(c) = caps(
        rx!(i r"\s\[\*\] (English|Dutch|French|German|Spanish)\b"),
        &nfo,
    ) {
        name.push('.');
        name.push_str(&c.text_or_empty(1));
    }
    if let Some(c) = caps(rx!(i r"\s\[\*\] (DT?S [2567][._ -][0-2]( MONO)?)\b"), &nfo) {
        // `$result[2]` is the optional ` MONO`, not the sound format in group 1.
        name.push('.');
        name.push_str(&c.text_or_empty(2));
    }
    if let Some(c) = caps(rx!(i r"Format.+(DVD([59R])?|[HX][._ -]?264)\b"), &nfo) {
        name.push('.');
        name.push_str(&c.text_or_empty(1));
    }
    if let Some(c) = caps(rx!(i r"\[(640x.+|1280x.+|1920x.+)\] Resolution\b"), &nfo) {
        let resolution = c.text_or_empty(1);
        name.push('.');
        name.push_str(match resolution.as_str() {
            "640x.+" => "480p",
            "1280x.+" => "720p",
            "1920x.+" => "1080p",
            other => other,
        });
    }
    if name.is_empty() {
        return None;
    }
    found(format!("{name}.IGUANA"), "IGUANA")
}

fn append_language(mut name: String, nfo: &str) -> String {
    if let Some(c) = caps(
        rx!(i r"(idiomas|lang|language|langue|sprache).*?\b(?P<lang>Brazilian|Chinese|Croatian|Danish|DE|Deutsch|Dutch|Estonian|ES|English|Englisch|Finnish|Flemish|Francais|French|FR|German|Greek|Hebrew|Icelandic|Italian|Japenese|Japan|Japanese|Korean|Latin|Nordic|Norwegian|Polish|Portuguese|Russian|Serbian|Slovenian|Swedish|Spanisch|Spanish|Thai|Turkish)\b"),
        nfo,
    ) {
        let lang = c.named("lang").unwrap_or_default();
        name.push('.');
        name.push_str(match lang.as_str() {
            "DE" => "GERMAN",
            "Englisch" => "ENGLISH",
            "FR" => "FRENCH",
            "ES" => "SPANISH",
            other => other,
        });
    }
    name
}

fn append_resolution(mut name: String, nfo: &str) -> String {
    if let Some(c) = caps(
        rx!(i r"(frame size|(video )?res(olution)?|video).*?(?P<res>(272|336|480|494|528|608|\(?640|688|704|720x480|810|816|820|1 ?080|1280( \@)?|1 ?920(x1080)?))"),
        nfo,
    ) {
        let res = c.named("res").unwrap_or_default();
        name.push('.');
        name.push_str(match res.as_str() {
            "272" | "336" | "480" | "494" | "608" | "640" | "(640" | "688" | "704" | "720x480" => {
                "480p"
            }
            "1280x720" | "1280" | "1280 @" => "720p",
            "810" | "816" | "820" | "1920" | "1 920" | "1080" | "1 080" | "1920x1080" => "1080p",
            "2160" => "2160p",
            other => other,
        });
    } else if let Some(c) = caps(
        rx!(i r"(largeur|width).*?(?P<res>(\(?640|688|704|720|1280( \@)?|1 ?920))"),
        nfo,
    ) {
        let res = c.named("res").unwrap_or_default();
        name.push('.');
        name.push_str(match res.as_str() {
            "640" | "(640" | "688" | "704" | "720" => "480p",
            "1280 @" | "1280" => "720p",
            "1920" | "1 920" => "1080p",
            "2160" => "2160p",
            other => other,
        });
    }
    name
}

fn append_source(mut name: String, nfo: &str) -> String {
    if let Some(c) = caps(
        rx!(i r"source.*?\b(?P<source>BD(-?(25|50|RIP))?|Blu-?Ray ?(3D)?|BRRIP|CAM(RIP)?|DBrip|DTV|DVD\-?(5|9|(R(IP)?|scr(eener)?))?|[HPS]D?(RIP|TV(RIP)?)?|NTSC|PAL|R5|Ripped |S?VCD|scr(eener)?|SAT(RIP)?|TS|VHS(RIP)?|VOD|WEB-DL)\b"),
        nfo,
    ) {
        let source = c.named("source").unwrap_or_default();
        name.push('.');
        name.push_str(match source.as_str() {
            "BD" => "Bluray.x264",
            "CAMRIP" => "CAM",
            "DBrip" => "BDRIP",
            "DVD R1" | "NTSC" | "PAL" | "VOD" => "DVD",
            "HD" => "HDTV",
            "Ripped " => "DVDRIP",
            other => other,
        });
    } else if let Some(c) = caps(
        rx!(i r"(codec( (name|code))?|(original )?format|res(olution)|video( (codec|format|res))?|tv system|type|writing library).*?\b(?P<video>AVC|AVI|DBrip|DIVX|\(Divx|DVD|[HX][._ -]?264|MPEG-4 Visual|NTSC|PAL|WMV|XVID)\b"),
        nfo,
    ) {
        let video = c.named("video").unwrap_or_default();
        name.push('.');
        name.push_str(match video.as_str() {
            "AVI" => "DVDRIP",
            "DBrip" => "BDRIP",
            "(Divx" => "DIVX",
            "h264" | "h-264" | "h.264" => "H264",
            "MPEG-4 Visual" | "x264" | "x-264" | "x.264" => "x264",
            "NTSC" | "PAL" => "DVD",
            other => other,
        });
    }
    name
}

fn append_audio_codec(mut name: String, nfo: &str) -> String {
    if let Some(c) = caps(
        rx!(i r"(audio( format)?|codec( name)?|format).*?\b(?P<audio>0x0055 MPEG-1 Layer 3|AAC( LC)?|AC-?3|\(AC3|DD5(.1)?|(A_)?DTS-?(HD)?(-?MA)?|Dolby(\s?TrueHD)?|TrueHD|FLAC|MP3)\b"),
        nfo,
    ) {
        let audio = c.named("audio").unwrap_or_default();
        name.push('.');
        name.push_str(match audio.as_str() {
            "0x0055 MPEG-1 Layer 3" => "MP3",
            "AC-3" | "(AC3" => "AC3",
            "AAC LC" => "AAC",
            "A_DTS" | "DTS-HD" | "DTSHD" => "DTS",
            other => other,
        });
    }
    name
}

/// `extractFromNfo`: a complete technical name on a declared name line, else the first of the
/// TV, movie, music, Title (Year), game and IGUANA stages to match.
pub fn extract_from_nfo(nfo: &str) -> Option<NameFixResult> {
    for field in literal_name_fields(&identity_text(nfo)) {
        if let Some(title) = clean_for_matching(&field, false)
            && is_literal_technical_scene_title(&title)
        {
            return found(title, "Complete literal NFO field");
        }
    }
    check_for_tv(nfo)
        .or_else(|| check_for_movie(nfo))
        .or_else(|| check_for_music(nfo))
        .or_else(|| check_for_title_year(nfo))
        .or_else(|| check_for_game(nfo))
        .or_else(|| check_for_iguana(nfo))
}

//! `NzbContentsService::parseNzb` and the `NzbParserService` subject checks it uses: the NFO to
//! fetch, the PAR2 index files, and the completion from the NZB.

use std::sync::LazyLock;

use nntmux_core::pcre::Pcre;

use crate::additional::nzb_contents::DocumentFile;

macro_rules! pattern {
    ($name:ident, $pattern:expr) => {
        static $name: LazyLock<Pcre> =
            LazyLock::new(|| Pcre::new($pattern).expect("a valid regex"));
    };
}

pattern!(NFO_EXTENSION, r"/\.\b(nfo|diz|info?)\b(?![.-])/i");
pattern!(
    NFO_NAMED,
    r#"/(?:^|["\s])(?:file(?:_?id)?|readme|release|info(?:rmation)?|about|desc(?:ription)?|notes?|read\.?me|00-|000-|0-|_-_).*?\.(?:txt|nfo|diz)(?:["\s]|$)/i"#
);
pattern!(SINGLE_PART, r"/\(1\/1\)$/i");
pattern!(
    NFO_WORD,
    r#"/(?:^|["\s])(?:nfo|info|readme|release|file_?id|about)(?:["\s]|$)/i"#
);
pattern!(SCENE_SINGLE, r#"/^[a-z0-9._-]+["\s]*\(1\/1\)/i"#);
pattern!(
    SCENE_NAME,
    r#"/^[a-z0-9]+[._-][a-z0-9._-]+["\s]*\(1\/1\)/i"#
);
pattern!(SMALL_YENC, r"/yEnc\s*\(\d+\)\s*\[1\/1\]/i");
pattern!(
    EXCLUDED,
    r"/\.(?:exe|com|bat|cmd|scr|dll|msi|pkg|deb|rpm|apk|ipa|app|zip|rar|[rst]\d{2}|7z|ace|tar|gz|bz2|xz|lzma|cab|iso|bin|cue|img|mdf|nrg|dmg|vhd|mp3|flac|ogg|aac|wav|wma|m4a|opus|ape|wv|mpc|avi|mkv|mp4|mov|wmv|mpg|mpeg|ts|vob|m2ts|webm|flv|ogv|divx|xvid|jpg|jpeg|png|gif|bmp|tif|tiff|psd|webp|svg|ico|raw|cr2|nef|pdf|doc|docx|xls|xlsx|ppt|pptx|odt|ods|odp|rtf|epub|mobi|azw|html|htm|css|js|php|py|java|c|cpp|h|cs|sql|json|xml|yml|yaml|db|dbf|mdb|accdb|sqlite|csv|par2?|sfv|md5|sha1|sha256|sha512|crc|url|lnk|cfg|ini|inf|sys|tmp|bak|log|srt|sub|idx|ass|ssa|vtt)\b/i"
);
pattern!(PAR2_INDEX, r"/\.par2$/i");
pattern!(PAR2_ANYWHERE, r#"/\.par2(?:["\s]|$)/i"#);
pattern!(PARTS_TOTAL, r"/(?:[(\[])?(\d+)[\/)\]](\d+)[)\]]?$/");
pattern!(PARTS_TAIL, r"/(\d+)\)$/");

/// An NFO candidate, as `detectNfoFile` and `detectHiddenNfoFile` report it.
#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub struct Detection {
    pub hidden: bool,
    pub priority: u8,
}

/// `detectNfoFile`: a subject naming an NFO.
pub fn detect_nfo_file(subject: &str) -> Option<Detection> {
    let subject = subject.as_bytes();
    if NFO_EXTENSION.is_match(subject) {
        return Some(Detection {
            hidden: false,
            priority: 1,
        });
    }
    NFO_NAMED.is_match(subject).then_some(Detection {
        hidden: false,
        priority: 2,
    })
}

/// `detectHiddenNfoFile`: a small file that could be an NFO under another name.
pub fn detect_hidden_nfo_file(subject: &str, segment_count: usize) -> Option<Detection> {
    let subject = subject.as_bytes();
    let candidate = (segment_count == 1 && SINGLE_PART.is_match(subject))
        || (segment_count <= 2 && NFO_WORD.is_match(subject))
        || (segment_count == 1 && SCENE_SINGLE.is_match(subject) && SCENE_NAME.is_match(subject))
        || (segment_count == 1 && SMALL_YENC.is_match(subject));
    (candidate && !EXCLUDED.is_match(subject)).then_some(Detection {
        hidden: true,
        priority: 10,
    })
}

/// `detectPar2IndexFile`.
pub fn is_par2_index(subject: &str) -> bool {
    PAR2_INDEX.is_match(subject.as_bytes())
}

/// `extractPartsTotal`: the part count a subject announces, or 0.
pub fn parts_total(subject: &str) -> i64 {
    let subject = subject.as_bytes();
    if let Some(found) = PARTS_TOTAL.captures(subject) {
        let total = php_int(found.group(2).unwrap_or_default());
        if total > 0 {
            return total;
        }
    }
    PARTS_TAIL
        .captures(subject)
        .map_or(0, |found| php_int(found.group(1).unwrap_or_default()))
}

/// PHP's `(int)` of a digit string, saturating as a float-to-int cast of a huge value would not;
/// part counts that size don't occur.
fn php_int(digits: &[u8]) -> i64 {
    std::str::from_utf8(digits)
        .ok()
        .and_then(|digits| digits.parse().ok())
        .unwrap_or(i64::MAX)
}

/// `calculateCompletion`.
pub fn completion(actual: i64, announced: i64) -> f64 {
    if announced > 0 {
        (actual as f64 / announced as f64 * 100.0).min(100.0)
    } else {
        0.0
    }
}

/// The NFO article `parseNzb` picked.
#[derive(Clone, Debug, PartialEq, Eq)]
pub struct NfoArticle {
    pub message_id: String,
    pub hidden: bool,
    pub priority: u8,
}

/// What one pass over the NZB found.
#[derive(Clone, Debug, PartialEq)]
pub struct Scan {
    /// The NFO to fetch, when asked for. An explicitly named NFO wins over a hidden one.
    pub nfo: Option<NfoArticle>,
    pub completion: f64,
    /// First articles of PAR2 index files, in order, when the NZB holds anything besides PAR2.
    pub par2_indexes: Vec<String>,
}

/// The loop of `parseNzb`. PAR2 parsing, which PHP did inline until one succeeded, is left to the
/// caller with [`Scan::par2_indexes`].
pub fn scan(files: &[DocumentFile], nfo_check: bool) -> Scan {
    let has_non_par2 = files
        .iter()
        .any(|file| !PAR2_ANYWHERE.is_match(file.subject.as_bytes()));
    let (mut actual, mut announced) = (0i64, 0i64);
    let mut nfo: Option<NfoArticle> = None;
    let (mut found, mut hidden_found) = (!nfo_check, !nfo_check);
    let mut par2_indexes = Vec::new();
    for file in files {
        actual += file.segments.len() as i64;
        announced = announced.saturating_add(parts_total(&file.subject));
        let Some(first) = file.segments.first() else {
            continue;
        };
        if !found && let Some(detection) = detect_nfo_file(&file.subject) {
            nfo = Some(NfoArticle {
                message_id: first.clone(),
                hidden: detection.hidden,
                priority: detection.priority,
            });
            found = true;
        }
        if !found
            && !hidden_found
            && let Some(detection) = detect_hidden_nfo_file(&file.subject, file.segments.len())
        {
            nfo = Some(NfoArticle {
                message_id: first.clone(),
                hidden: detection.hidden,
                priority: detection.priority,
            });
            hidden_found = true;
        }
        if has_non_par2 && is_par2_index(&file.subject) {
            par2_indexes.push(first.clone());
        }
    }
    Scan {
        nfo,
        completion: completion(actual, announced),
        par2_indexes,
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    fn file(subject: &str, segments: usize) -> DocumentFile {
        DocumentFile {
            subject: subject.to_owned(),
            segments: (0..segments)
                .map(|index| format!("{subject}{index}@x"))
                .collect(),
            numbers: vec![None; segments],
        }
    }

    #[test]
    fn an_explicit_nfo_beats_a_hidden_one() {
        let files = [
            file("\"abc\" yEnc (1/1)", 1),
            file("\"Movie.2160p.nfo\" yEnc (1/1)", 1),
            file("\"Movie.2160p.mkv\" yEnc (1/50)", 50),
        ];
        let scan = scan(&files, true);
        let nfo = scan.nfo.expect("an NFO");
        assert!(!nfo.hidden);
        assert_eq!(nfo.message_id, "\"Movie.2160p.nfo\" yEnc (1/1)0@x");
        assert_eq!(scan.completion, 100.0);
    }

    #[test]
    fn parts_and_completion() {
        assert_eq!(parts_total("x yEnc (1/20)"), 20);
        assert_eq!(parts_total("x [3/7]"), 7);
        assert_eq!(parts_total("x (12)"), 12);
        assert_eq!(parts_total("x"), 0);
        assert_eq!(completion(5, 10), 50.0);
        assert_eq!(completion(5, 0), 0.0);
        assert_eq!(completion(20, 10), 100.0);
    }
}

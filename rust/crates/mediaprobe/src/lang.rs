//! Language codes as Matroska writes them (ISO 639-2, or BCP 47 in newer files) mapped to one
//! three-letter code and an English name.

/// (ISO 639-1, ISO 639-2/B, ISO 639-2/T, English name)
const LANGUAGES: &[(&str, &str, &str, &str)] = &[
    ("en", "eng", "eng", "English"),
    ("fr", "fre", "fra", "French"),
    ("de", "ger", "deu", "German"),
    ("es", "spa", "spa", "Spanish"),
    ("it", "ita", "ita", "Italian"),
    ("pt", "por", "por", "Portuguese"),
    ("nl", "dut", "nld", "Dutch"),
    ("sv", "swe", "swe", "Swedish"),
    ("da", "dan", "dan", "Danish"),
    ("no", "nor", "nor", "Norwegian"),
    ("nb", "nob", "nob", "Norwegian"),
    ("nn", "nno", "nno", "Norwegian"),
    ("fi", "fin", "fin", "Finnish"),
    ("is", "ice", "isl", "Icelandic"),
    ("pl", "pol", "pol", "Polish"),
    ("cs", "cze", "ces", "Czech"),
    ("sk", "slo", "slk", "Slovak"),
    ("hu", "hun", "hun", "Hungarian"),
    ("ro", "rum", "ron", "Romanian"),
    ("bg", "bul", "bul", "Bulgarian"),
    ("hr", "hrv", "hrv", "Croatian"),
    ("sr", "srp", "srp", "Serbian"),
    ("sl", "slv", "slv", "Slovenian"),
    ("el", "gre", "ell", "Greek"),
    ("tr", "tur", "tur", "Turkish"),
    ("ru", "rus", "rus", "Russian"),
    ("uk", "ukr", "ukr", "Ukrainian"),
    ("et", "est", "est", "Estonian"),
    ("lv", "lav", "lav", "Latvian"),
    ("lt", "lit", "lit", "Lithuanian"),
    ("he", "heb", "heb", "Hebrew"),
    ("ar", "ara", "ara", "Arabic"),
    ("fa", "per", "fas", "Persian"),
    ("hi", "hin", "hin", "Hindi"),
    ("ta", "tam", "tam", "Tamil"),
    ("te", "tel", "tel", "Telugu"),
    ("th", "tha", "tha", "Thai"),
    ("vi", "vie", "vie", "Vietnamese"),
    ("id", "ind", "ind", "Indonesian"),
    ("ms", "may", "msa", "Malay"),
    ("zh", "chi", "zho", "Chinese"),
    ("ja", "jpn", "jpn", "Japanese"),
    ("ko", "kor", "kor", "Korean"),
    ("ca", "cat", "cat", "Catalan"),
    ("eu", "baq", "eus", "Basque"),
    ("gl", "glg", "glg", "Galician"),
    ("fil", "fil", "fil", "Filipino"),
    ("tl", "tgl", "tgl", "Tagalog"),
];

/// A normalized three-letter code (ISO 639-2/B), or the input lowercased when unknown.
/// `und`, `zxx` and empty stay `und`.
pub fn code(tag: &str) -> String {
    let primary = tag
        .split(['-', '_'])
        .next()
        .unwrap_or_default()
        .to_ascii_lowercase();
    if primary.is_empty() || primary == "und" || primary == "zxx" || primary == "mul" {
        return "und".into();
    }
    LANGUAGES
        .iter()
        .find(|(one, b, t, _)| *one == primary || *b == primary || *t == primary)
        .map_or(primary, |(_, b, _, _)| (*b).to_string())
}

/// The English name, or `None` for unknown and undetermined languages.
pub fn name(tag: &str) -> Option<&'static str> {
    let code = code(tag);
    LANGUAGES
        .iter()
        .find(|(_, b, _, _)| *b == code)
        .map(|(_, _, _, name)| *name)
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn normalizes_codes() {
        assert_eq!(code("en"), "eng");
        assert_eq!(code("en-US"), "eng");
        assert_eq!(code("fra"), "fre");
        assert_eq!(code("ger"), "ger");
        assert_eq!(code("pt-BR"), "por");
        assert_eq!(code(""), "und");
        assert_eq!(code("tlh"), "tlh");
        assert_eq!(name("deu"), Some("German"));
        assert_eq!(name("und"), None);
    }
}

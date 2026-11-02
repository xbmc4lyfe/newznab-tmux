//! What a post's NNTP and yEnc headers say about the file and the uploader.

use serde::Serialize;
use sha2::{Digest, Sha256};
use std::collections::BTreeMap;

/// The fields of an article's `=ybegin`, `=ypart` and `=yend` lines.
#[derive(Clone, Debug, Default, PartialEq, Eq, Serialize)]
pub struct YencFields {
    pub name: Option<String>,
    pub line: Option<u32>,
    pub size: Option<u64>,
    pub part: Option<u32>,
    pub total: Option<u32>,
    pub begin: Option<u64>,
    pub end: Option<u64>,
    /// CRC32 of this part's decoded bytes.
    pub pcrc32: Option<u32>,
    /// CRC32 of the whole posted file, when the poster wrote it (usually on the last part).
    pub crc32: Option<u32>,
}

/// Reads the yEnc control lines of a raw (still encoded) article body.
///
/// Only lines that start with `=y` are looked at, so the encoded data is never decoded here.
pub fn yenc_fields(article: &[u8]) -> YencFields {
    let mut fields = YencFields::default();
    for raw in article.split(|&b| b == b'\n') {
        let line = raw.strip_suffix(b"\r").unwrap_or(raw);
        if !line.starts_with(b"=y") {
            continue;
        }
        let text = String::from_utf8_lossy(line);
        let (keyword, rest) = text.split_once(' ').unwrap_or((&text, ""));
        // `name=` runs to the end of the line and may contain spaces and `=`.
        let (pairs, name) = match rest.find("name=") {
            Some(at) => (&rest[..at], Some(rest[at + 5..].trim().to_string())),
            None => (rest, None),
        };
        let mut values = BTreeMap::new();
        for pair in pairs.split_whitespace() {
            if let Some((key, value)) = pair.split_once('=') {
                values.insert(key.to_ascii_lowercase(), value.to_string());
            }
        }
        let num = |key: &str| values.get(key).and_then(|v| v.parse::<u64>().ok());
        let crc = |key: &str| {
            values
                .get(key)
                .and_then(|v| u32::from_str_radix(v.trim_start_matches("0x"), 16).ok())
        };
        match keyword {
            "=ybegin" => {
                fields.name = name.filter(|n| !n.is_empty()).or(fields.name);
                fields.line = num("line")
                    .and_then(|v| u32::try_from(v).ok())
                    .or(fields.line);
                fields.size = num("size").or(fields.size);
                fields.part = num("part")
                    .and_then(|v| u32::try_from(v).ok())
                    .or(fields.part);
                fields.total = num("total")
                    .and_then(|v| u32::try_from(v).ok())
                    .or(fields.total);
            }
            "=ypart" => {
                fields.begin = num("begin").or(fields.begin);
                fields.end = num("end").or(fields.end);
            }
            "=yend" => {
                fields.pcrc32 = crc("pcrc32").or(fields.pcrc32);
                fields.crc32 = crc("crc32").or(fields.crc32);
            }
            _ => {}
        }
    }
    fields
}

/// Inputs to a poster signature. Header names are matched case-insensitively.
#[derive(Clone, Debug)]
pub struct PostingEvidence<'a> {
    pub headers: &'a BTreeMap<String, String>,
    pub yenc: Option<&'a YencFields>,
    /// Decoded bytes per article (the usual segment size), when known.
    pub segment_size: Option<u64>,
    /// The PAR2 creator packet's text, e.g. `ParPar v0.4.5`.
    pub par2_creator: Option<&'a str>,
}

/// A stable description of how a post was made, independent of what was posted.
#[derive(Clone, Debug, Default, PartialEq, Eq, Serialize)]
pub struct PosterSignature {
    /// A tool name, when a header or the Message-ID says so.
    pub software: Option<String>,
    /// The Message-ID with letters and digits replaced by classes, e.g. `<a32@a5.a3>`.
    pub message_id_shape: Option<String>,
    /// The upload's injection host, from `Injection-Info`, `NNTP-Posting-Host` or `Path`.
    pub injection_host: Option<String>,
    pub groups: Vec<String>,
    pub line_length: Option<u32>,
    pub segment_size: Option<u64>,
    pub par2_creator: Option<String>,
    /// First 16 hex characters of a SHA-256 over every field above.
    pub hash: String,
}

const KNOWN_TOOLS: &[&str] = &[
    "ngpost",
    "nyuu",
    "powerpost",
    "newsmangler",
    "gopoststuff",
    "jbinup",
    "newsup",
    "camelsystem",
    "postbot",
    "newspost",
];

pub fn poster_signature(evidence: &PostingEvidence<'_>) -> PosterSignature {
    let header = |name: &str| {
        evidence
            .headers
            .iter()
            .find(|(key, _)| key.eq_ignore_ascii_case(name))
            .map(|(_, value)| value.trim().to_string())
            .filter(|value| !value.is_empty())
    };
    let message_id = header("message-id");
    let agent = header("user-agent")
        .or_else(|| header("x-newsposter"))
        .or_else(|| header("x-newsreader"))
        .or_else(|| header("x-mailer"));
    let lower_id = message_id
        .as_deref()
        .unwrap_or_default()
        .to_ascii_lowercase();
    let software = agent
        .as_deref()
        .map(|a| a.split_whitespace().next().unwrap_or(a).to_string())
        .or_else(|| {
            KNOWN_TOOLS
                .iter()
                .find(|tool| lower_id.contains(*tool))
                .map(|tool| tool.to_string())
        });
    let injection_host = header("injection-info")
        .and_then(|v| v.split(';').next().map(|h| h.trim().to_ascii_lowercase()))
        .or_else(|| header("nntp-posting-host").map(|h| h.to_ascii_lowercase()))
        .or_else(|| {
            // The entry after the local host in Path is the server that took the upload.
            header("path").and_then(|path| {
                let hops: Vec<&str> = path.split('!').filter(|h| !h.is_empty()).collect();
                hops.len()
                    .checked_sub(2)
                    .map(|i| hops[i].to_ascii_lowercase())
            })
        })
        .filter(|h| !h.is_empty());
    let mut groups: Vec<String> = header("newsgroups")
        .map(|v| {
            v.split(',')
                .map(|g| g.trim().to_ascii_lowercase())
                .filter(|g| !g.is_empty())
                .collect()
        })
        .unwrap_or_default();
    groups.sort();
    groups.dedup();
    let mut signature = PosterSignature {
        software,
        message_id_shape: message_id.as_deref().map(shape),
        injection_host,
        groups,
        line_length: evidence.yenc.and_then(|y| y.line),
        segment_size: evidence.segment_size,
        par2_creator: evidence.par2_creator.map(|c| c.trim().to_string()),
        hash: String::new(),
    };
    let mut hasher = Sha256::new();
    for part in [
        signature.software.clone().unwrap_or_default(),
        signature.message_id_shape.clone().unwrap_or_default(),
        signature.injection_host.clone().unwrap_or_default(),
        signature.groups.join(","),
        signature
            .line_length
            .map(|l| l.to_string())
            .unwrap_or_default(),
        signature
            .segment_size
            .map(|s| s.to_string())
            .unwrap_or_default(),
        signature.par2_creator.clone().unwrap_or_default(),
    ] {
        hasher.update(part.as_bytes());
        hasher.update([0]);
    }
    signature.hash = hex::encode(&hasher.finalize()[..8]);
    signature
}

/// Replaces runs of letters with `a<n>` and digits with `d<n>`, keeping punctuation, so
/// `<Kq3x9@ngPost.com>` becomes `<a2d1a1d1@a6.a3>`. Known tool names stay literal.
pub fn shape(message_id: &str) -> String {
    let lower = message_id.to_ascii_lowercase();
    let mut out = String::new();
    let mut chars = lower.char_indices().peekable();
    while let Some((at, c)) = chars.next() {
        if let Some(tool) = KNOWN_TOOLS.iter().find(|t| lower[at..].starts_with(*t)) {
            out.push_str(tool);
            for _ in 1..tool.len() {
                chars.next();
            }
            continue;
        }
        let class = if c.is_ascii_alphabetic() {
            'a'
        } else if c.is_ascii_digit() {
            'd'
        } else {
            out.push(c);
            continue;
        };
        let mut run = 1;
        while let Some(&(_, next)) = chars.peek() {
            let same = if class == 'a' {
                next.is_ascii_alphabetic()
            } else {
                next.is_ascii_digit()
            };
            if !same {
                break;
            }
            run += 1;
            chars.next();
        }
        out.push(class);
        out.push_str(&run.to_string());
    }
    out
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn reads_yenc_lines() {
        let body = b"=ybegin part=41 total=200 line=128 size=104857600 name=Some Movie 2024.part01.rar\r\n\
=ypart begin=28672001 end=29388800\r\nENCODED\r\n=yend size=716800 part=41 pcrc32=0a1b2c3d crc32=DEADBEEF\r\n";
        let fields = yenc_fields(body);
        assert_eq!(fields.name.as_deref(), Some("Some Movie 2024.part01.rar"));
        assert_eq!(fields.line, Some(128));
        assert_eq!(fields.size, Some(104_857_600));
        assert_eq!((fields.part, fields.total), (Some(41), Some(200)));
        assert_eq!(
            (fields.begin, fields.end),
            (Some(28_672_001), Some(29_388_800))
        );
        assert_eq!(fields.pcrc32, Some(0x0A1B_2C3D));
        assert_eq!(fields.crc32, Some(0xDEAD_BEEF));
    }

    #[test]
    fn signature_is_stable_across_posts_from_one_tool() {
        let mut a = BTreeMap::new();
        a.insert("Message-ID".into(), "<Xk29Fq@ngPost.com>".into());
        a.insert("Newsgroups".into(), "alt.binaries.b, alt.binaries.a".into());
        a.insert(
            "Injection-Info".into(),
            "News.Example.NET; posting-account=\"x\"".into(),
        );
        let mut b = a.clone();
        b.insert("Message-ID".into(), "<Pz81Ma@ngPost.com>".into());
        let yenc = YencFields {
            line: Some(128),
            ..YencFields::default()
        };
        let sig = |headers| {
            poster_signature(&PostingEvidence {
                headers,
                yenc: Some(&yenc),
                segment_size: Some(716_800),
                par2_creator: Some("ParPar v0.4.5"),
            })
        };
        let (first, second) = (sig(&a), sig(&b));
        assert_eq!(first.software.as_deref(), Some("ngpost"));
        assert_eq!(first.injection_host.as_deref(), Some("news.example.net"));
        assert_eq!(first.groups, vec!["alt.binaries.a", "alt.binaries.b"]);
        assert_eq!(first.hash, second.hash);
        assert_eq!(first.hash.len(), 16);
    }

    #[test]
    fn shapes_message_ids() {
        assert_eq!(shape("<Kq3x9@ngPost.com>"), "<a2d1a1d1@ngpost.a3>");
        assert_eq!(shape("<part1of20.abc@news>"), "<a4d1a2d2.a3@a4>");
    }

    #[test]
    fn path_gives_the_injection_host() {
        let mut headers = BTreeMap::new();
        headers.insert(
            "Path".into(),
            "reader.example!feeder.x!upload.y!not-for-mail".into(),
        );
        let sig = poster_signature(&PostingEvidence {
            headers: &headers,
            yenc: None,
            segment_size: None,
            par2_creator: None,
        });
        assert_eq!(sig.injection_host.as_deref(), Some("upload.y"));
    }
}

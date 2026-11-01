//! Reads just what identification needs from an NZB: each file's subject and size. A scan beats
//! a full XML parse here because one run decodes hundreds of gigabytes of NZB.

/// One `<file>` of an NZB.
#[derive(Clone, Debug, PartialEq, Eq)]
pub struct NzbFile {
    pub subject: String,
    pub bytes: u64,
    pub group: Option<String>,
    /// The message ID of the file's first article, which holds any archive header.
    pub first_message_id: Option<String>,
}

#[derive(Clone, Debug, Default, PartialEq, Eq)]
pub struct NzbSummary {
    /// Biggest first, so a pass over them meets the main payload first.
    pub files: Vec<NzbFile>,
    /// Encoded bytes of everything that isn't PAR2, which is what compares with a PreDB size.
    pub payload_bytes: u64,
    pub payload_files: u32,
    pub par2_files: u32,
}

pub fn summarize(xml: &str) -> NzbSummary {
    let mut summary = NzbSummary::default();
    let mut rest = xml;
    while let Some(start) = rest.find("<file ") {
        rest = &rest[start..];
        let end = rest.find("</file>").unwrap_or(rest.len());
        let (element, after) = rest.split_at(end);
        let open_end = element.find('>').unwrap_or(element.len());
        let subject = attribute(&element[..open_end], "subject")
            .map(unescape)
            .unwrap_or_default();
        let (bytes, first_message_id) = segments(element);
        let group = element
            .split_once("<group>")
            .and_then(|(_, rest)| rest.split_once("</group>"))
            .map(|(group, _)| group.trim().to_owned());
        if subject.to_ascii_lowercase().contains(".par2") {
            summary.par2_files += 1;
        } else {
            summary.payload_files += 1;
            summary.payload_bytes = summary.payload_bytes.saturating_add(bytes);
        }
        summary.files.push(NzbFile {
            subject,
            bytes,
            group,
            first_message_id,
        });
        rest = after.get(7..).unwrap_or("");
    }
    summary
        .files
        .sort_by_key(|file| std::cmp::Reverse(file.bytes));
    summary
}

fn attribute<'a>(open_tag: &'a str, name: &str) -> Option<&'a str> {
    for quote in ['"', '\''] {
        let needle = format!("{name}={quote}");
        if let Some(start) = open_tag.find(&needle) {
            let value = &open_tag[start + needle.len()..];
            return value.find(quote).map(|end| &value[..end]);
        }
    }
    None
}

/// The file's total bytes, and the message ID of its lowest-numbered segment.
fn segments(element: &str) -> (u64, Option<String>) {
    let mut total = 0u64;
    let mut first: Option<(u64, String)> = None;
    let mut rest = element;
    while let Some(start) = rest.find("<segment ") {
        rest = &rest[start + 9..];
        let open_end = rest.find('>').unwrap_or(rest.len());
        let tag = &rest[..open_end];
        if let Some(bytes) = attribute(tag, "bytes") {
            total = total.saturating_add(bytes.parse().unwrap_or(0));
        }
        let number = attribute(tag, "number")
            .and_then(|n| n.parse().ok())
            .unwrap_or(u64::MAX);
        if first.as_ref().is_none_or(|(lowest, _)| number < *lowest)
            && let Some(body) = rest.get(open_end + 1..)
            && let Some((id, _)) = body.split_once("</segment>")
        {
            first = Some((number, unescape(id.trim())));
        }
    }
    (total, first.map(|(_, id)| id))
}

fn unescape(value: &str) -> String {
    if !value.contains('&') {
        return value.to_owned();
    }
    let mut out = String::with_capacity(value.len());
    let mut rest = value;
    while let Some(start) = rest.find('&') {
        out.push_str(&rest[..start]);
        rest = &rest[start..];
        let Some(end) = rest.find(';').filter(|end| *end <= 10) else {
            out.push('&');
            rest = &rest[1..];
            continue;
        };
        let entity = &rest[1..end];
        let decoded = match entity {
            "amp" => Some('&'),
            "quot" => Some('"'),
            "apos" => Some('\''),
            "lt" => Some('<'),
            "gt" => Some('>'),
            _ => entity
                .strip_prefix("#x")
                .and_then(|hex| u32::from_str_radix(hex, 16).ok())
                .or_else(|| entity.strip_prefix('#').and_then(|dec| dec.parse().ok()))
                .and_then(char::from_u32),
        };
        match decoded {
            Some(c) => out.push(c),
            None => out.push_str(&rest[..=end]),
        }
        rest = &rest[end + 1..];
    }
    out.push_str(rest);
    out
}

#[cfg(test)]
mod tests {
    use super::*;

    const SAMPLE: &str = r#"<?xml version="1.0"?>
<nzb xmlns="http://www.newzbin.com/DTD/2003/nzb">
<file poster="a &lt;a@b&gt;" date="1" subject="[1/3] - &quot;Some.Film.2021.1080p.WEB-DL.H264-GRP.mkv&quot; yEnc (1/2)">
 <groups><group>alt.binaries.misc</group></groups>
 <segments>
  <segment bytes="700000" number="1">a@b</segment>
  <segment bytes="300000" number="2">c@d</segment>
 </segments>
</file>
<file subject="[2/3] - &quot;x.par2&quot; yEnc (1/1)" date="1" poster="p">
 <segments><segment number="1" bytes="5000">e@f</segment></segments>
</file>
<file poster="p" subject='[3/3] - "x.vol00+01.PAR2" yEnc'><segments><segment bytes="9000">g@h</segment></segments></file>
</nzb>"#;

    #[test]
    fn reads_subjects_and_sizes() {
        let summary = summarize(SAMPLE);
        assert_eq!(summary.files.len(), 3);
        assert_eq!(
            summary.files[0].subject,
            r#"[1/3] - "Some.Film.2021.1080p.WEB-DL.H264-GRP.mkv" yEnc (1/2)"#
        );
        assert_eq!(summary.files[0].bytes, 1_000_000);
        assert_eq!(summary.payload_bytes, 1_000_000);
        assert_eq!(summary.payload_files, 1);
        assert_eq!(summary.par2_files, 2);
        assert_eq!(summary.files[0].first_message_id.as_deref(), Some("a@b"));
        assert_eq!(summary.files[0].group.as_deref(), Some("alt.binaries.misc"));
    }

    #[test]
    fn a_single_file_post_has_one_payload_file() {
        let summary = summarize(
            r#"<file subject="funxd.mkv yEnc"><segments><segment bytes="60000000000">a</segment></segments></file>"#,
        );
        assert_eq!(summary.payload_files, 1);
        assert_eq!(summary.payload_bytes, 60_000_000_000);
    }

    #[test]
    fn the_first_article_is_found_whatever_the_order() {
        let summary = summarize(
            r#"<file subject="x"><segments><segment bytes="2" number="2">b&amp;@c</segment><segment bytes="1" number="1">a&amp;@c</segment></segments></file>"#,
        );
        assert_eq!(summary.files[0].first_message_id.as_deref(), Some("a&@c"));
    }

    #[test]
    fn unescapes_entities() {
        assert_eq!(
            unescape("a &amp; b &#38; c &#x26; d &nope; &"),
            "a & b & c & d &nope; &"
        );
    }

    #[test]
    fn broken_input_gives_what_it_can() {
        assert_eq!(summarize("not xml"), NzbSummary::default());
        assert_eq!(summarize(r#"<file subject="x""#).files.len(), 1);
    }
}

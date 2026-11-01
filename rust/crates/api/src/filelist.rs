//! `NzbParserService::parseNzbFileList` in its default form: one entry per NZB file.

use fancy_regex::Regex;
use quick_xml::events::{BytesStart, Event};
use quick_xml::{Reader, XmlVersion};
use std::sync::LazyLock;

use crate::json::J;

static EXTENSION: LazyLock<Regex> = LazyLock::new(|| {
    Regex::new(concat!(
        r#"(?i)\.(\d{2,3}|7z|ace|ai7|srr|srt|sub|aiff|asc|avi|audio|bin|bz2|"#,
        r#"c|cfc|cfm|chm|class|conf|cpp|cs|css|csv|cue|deb|divx|doc|dot|"#,
        r#"eml|enc|exe|file|gif|gz|hlp|htm|html|image|iso|jar|java|jpeg|"#,
        r#"jpg|js|lua|m|m3u|mkv|mm|mov|mp3|mp4|mpg|nfo|nzb|odc|odf|odg|odi|odp|"#,
        r#"ods|odt|ogg|par2|parity|pdf|pgp|php|pl|png|ppt|ps|py|r\d{2,3}|"#,
        r#"ram|rar|rb|rm|rpm|rtf|sfv|sig|sql|srs|swf|sxc|sxd|sxi|sxw|tar|"#,
        r#"tex|tgz|txt|vcf|video|vsd|wav|wma|wmv|xls|xml|xpi|xvid|zip7|zip)"#,
        r#"[" ](?!([\)|\-]))"#,
    ))
    .expect("static regex")
});
static SPLIT_RAR: LazyLock<regex::Regex> =
    LazyLock::new(|| regex::Regex::new(r"(?i)\.r\d{2,3}").expect("static regex"));
static PARTS_TOTAL: LazyLock<regex::Regex> =
    LazyLock::new(|| regex::Regex::new(r"(\d+)\)$").expect("static regex"));

#[derive(Default)]
struct File {
    title: String,
    segments: Vec<(Option<i64>, String)>,
    size: i64,
    groups: Vec<String>,
}

fn attribute(element: &BytesStart<'_>, name: &str) -> Option<String> {
    element
        .attributes()
        .flatten()
        .find(|attribute| attribute.key.0 == name)
        .and_then(|attribute| {
            attribute
                .normalized_value(XmlVersion::default())
                .ok()
                .map(|value| value.into_owned())
        })
}

fn local_name(element: &BytesStart<'_>) -> String {
    let local = element.local_name();
    let name: &str = local.as_ref();
    name.to_owned()
}

/// Reads the `<file>` entries. Invalid XML or another root gives nothing, as `simplexml` does.
fn read_files(xml: &[u8]) -> Option<Vec<File>> {
    let cleaned: Vec<u8> = xml.iter().copied().filter(|byte| *byte != 0x0f).collect();
    let mut reader = Reader::from_reader(cleaned.as_slice());
    reader.config_mut().check_end_names = true;
    let mut buffer = Vec::new();
    let mut files = Vec::new();
    let mut current: Option<File> = None;
    let mut text_target: Option<Text> = None;
    let mut root_seen = false;
    loop {
        match reader.read_event_into(&mut buffer).ok()? {
            Event::Start(element) => {
                let name = local_name(&element);
                if !root_seen {
                    root_seen = true;
                    if !name.eq_ignore_ascii_case("nzb") {
                        return None;
                    }
                    buffer.clear();
                    continue;
                }
                match name.as_str() {
                    "file" => {
                        current = Some(File {
                            title: attribute(&element, "subject").unwrap_or_default(),
                            ..File::default()
                        });
                    }
                    "segment" if current.is_some() => {
                        let number = attribute(&element, "number")
                            .map(|value| crate::input::php_int(&value));
                        if let Some(file) = &mut current {
                            file.size += attribute(&element, "bytes")
                                .map_or(0, |value| crate::input::php_int(&value));
                            file.segments.push((number, String::new()));
                        }
                        text_target = Some(Text::Segment);
                    }
                    "group" if current.is_some() => {
                        if let Some(file) = &mut current {
                            file.groups.push(String::new());
                        }
                        text_target = Some(Text::Group);
                    }
                    _ => {}
                }
            }
            Event::Empty(element) => {
                let name = local_name(&element);
                if !root_seen {
                    return name.eq_ignore_ascii_case("nzb").then(Vec::new);
                }
                if name == "file" {
                    files.push(File {
                        title: attribute(&element, "subject").unwrap_or_default(),
                        ..File::default()
                    });
                }
            }
            event @ (Event::Text(_) | Event::GeneralRef(_)) => {
                let decoded = match &event {
                    Event::Text(text) => {
                        let content: &str = text.as_ref();
                        content.to_owned()
                    }
                    Event::GeneralRef(reference) => {
                        let name: &str = reference.as_ref();
                        match name {
                            "amp" => "&".to_owned(),
                            "lt" => "<".to_owned(),
                            "gt" => ">".to_owned(),
                            "quot" => "\"".to_owned(),
                            "apos" => "'".to_owned(),
                            _ => String::new(),
                        }
                    }
                    _ => String::new(),
                };
                if let (Some(target), Some(file)) = (&text_target, &mut current) {
                    let slot = match target {
                        Text::Segment => file.segments.last_mut().map(|segment| &mut segment.1),
                        Text::Group => file.groups.last_mut(),
                    };
                    if let Some(slot) = slot {
                        slot.push_str(&decoded);
                    }
                }
            }
            Event::End(element) => {
                let local = element.local_name();
                let name: &str = local.as_ref();
                match name {
                    "file" => files.extend(current.take()),
                    "segment" | "group" => text_target = None,
                    _ => {}
                }
            }
            Event::Eof => return root_seen.then_some(files),
            _ => {}
        }
        buffer.clear();
    }
}

enum Text {
    Segment,
    Group,
}

fn extension(title: &str) -> String {
    match EXTENSION.captures(title) {
        Ok(Some(found)) => {
            let whole = found.get(0).map_or("", |part| part.as_str());
            if SPLIT_RAR.is_match(whole) {
                "rar".to_owned()
            } else {
                found
                    .get(1)
                    .map_or(String::new(), |part| part.as_str().to_lowercase())
            }
        }
        _ => String::new(),
    }
}

pub fn parse(xml: &[u8]) -> Vec<J> {
    read_files(xml)
        .unwrap_or_default()
        .into_iter()
        .map(|mut file| {
            // Listed out of order is common; readers expect file order. Unnumbered ones stay put.
            if file.segments.iter().all(|(number, _)| number.is_some()) {
                file.segments.sort_by_key(|(number, _)| *number);
            }
            let first = file
                .segments
                .first()
                .is_none_or(|(number, _)| number.unwrap_or(1) == 1);
            let actual = i64::try_from(file.segments.len()).unwrap_or(i64::MAX);
            let mut entry = vec![
                ("title".to_owned(), J::Str(file.title.clone())),
                ("filecount".to_owned(), J::Int(1)),
                ("ext".to_owned(), J::Str(extension(&file.title))),
                (
                    "segments".to_owned(),
                    J::Arr(
                        file.segments
                            .into_iter()
                            .map(|(_, id)| J::Str(id))
                            .collect(),
                    ),
                ),
                ("firstsegment".to_owned(), J::Bool(first)),
                ("size".to_owned(), J::Int(file.size)),
            ];
            if let Some(found) = PARTS_TOTAL.captures(&file.title) {
                entry.push(("partstotal".to_owned(), J::str(&found[1])));
            }
            entry.push(("partsactual".to_owned(), J::Int(actual)));
            entry.push((
                "groups".to_owned(),
                J::Arr(file.groups.into_iter().map(J::Str).collect()),
            ));
            J::Obj(entry)
        })
        .collect()
}

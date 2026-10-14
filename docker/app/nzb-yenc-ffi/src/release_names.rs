use serde::de::{self, SeqAccess, Visitor};
use serde::{Deserialize, Deserializer};
use std::fmt;

const MAX_INPUT_BYTES: usize = 8 * 1024 * 1024;
const MAX_ROWS: usize = 1000;
const MAX_ROW_BYTES: usize = 65536;
const MAX_OUTPUT_BYTES: usize = 16 * 1024 * 1024;

#[derive(Deserialize)]
struct ReleaseName {
    searchname: Option<String>,
    name: Option<String>,
    guid: String,
}

#[derive(Deserialize)]
struct Request {
    url_prefix: String,
    url_suffix: String,
    releases: Batch,
}

struct Batch(Vec<ReleaseName>);

impl<'de> Deserialize<'de> for Batch {
    fn deserialize<D: Deserializer<'de>>(deserializer: D) -> Result<Self, D::Error> {
        struct BatchVisitor;

        impl<'de> Visitor<'de> for BatchVisitor {
            type Value = Batch;

            fn expecting(&self, formatter: &mut fmt::Formatter) -> fmt::Result {
                formatter.write_str("a list of at most 1000 release names")
            }

            fn visit_seq<A: SeqAccess<'de>>(self, mut sequence: A) -> Result<Batch, A::Error> {
                let mut rows = Vec::new();
                while let Some(row) = sequence.next_element::<ReleaseName>()? {
                    if rows.len() == MAX_ROWS {
                        return Err(de::Error::custom("too many release names"));
                    }
                    if row.guid.is_empty()
                        || row.guid.len() > 128
                        || matches!(row.guid.as_str(), "." | "..")
                        || !row.guid.bytes().all(|byte| {
                            byte.is_ascii_alphanumeric()
                                || matches!(byte, b'.' | b'_' | b'~' | b'-')
                        })
                    {
                        return Err(de::Error::custom("release GUID is not a safe path segment"));
                    }
                    let size = row.guid.len()
                        + row.searchname.as_deref().unwrap_or_default().len()
                        + row.name.as_deref().unwrap_or_default().len();
                    if size > MAX_ROW_BYTES {
                        return Err(de::Error::custom("release name row exceeds byte limit"));
                    }
                    rows.push(row);
                }
                Ok(Batch(rows))
            }
        }

        deserializer.deserialize_seq(BatchVisitor)
    }
}

/// Expand Laravel's detail URL template and render presentation-only names.
pub fn render(input: &[u8]) -> Option<Vec<String>> {
    if input.len() > MAX_INPUT_BYTES {
        return None;
    }
    let Request {
        url_prefix,
        url_suffix,
        releases: Batch(rows),
    } = serde_json::from_slice(input).ok()?;
    if url_prefix.len() + url_suffix.len() > MAX_ROW_BYTES {
        return None;
    }
    let escaped_prefix = escape(&url_prefix);
    let escaped_suffix = escape(&url_suffix);
    let mut html = Vec::with_capacity(rows.len());
    let mut output_bytes = 0usize;
    for row in rows {
        let display = clean(row.searchname.as_deref().unwrap_or_default());
        let original = clean(row.name.as_deref().unwrap_or_default());
        let escaped_display = escape(display);
        let mut row_html = format!(
            "<a href=\"{escaped_prefix}{}{escaped_suffix}\" class=\"text-gray-900 dark:text-gray-100 font-medium max-w-md wrap-break-word break-all block hover:text-blue-600 dark:hover:text-blue-400 hover:underline focus-visible:underline\" title=\"{escaped_display}\">{escaped_display}</a>",
            row.guid
        );
        if !original.is_empty() && original != display {
            let escaped_original = escape(original);
            row_html.push_str(&format!(
                    "<div class=\"text-gray-500 dark:text-gray-400 text-xs mt-1 max-w-md truncate\" title=\"{escaped_original}\">{escaped_original}</div>"
                ));
        }
        output_bytes = output_bytes.checked_add(row_html.len())?;
        if output_bytes > MAX_OUTPUT_BYTES {
            return None;
        }
        html.push(row_html);
    }
    Some(html)
}

/// Match ReleaseNameDisplay's anchored PCRE without changing stored subjects.
fn clean(name: &str) -> &str {
    cleaned_filename(name).unwrap_or(name)
}

fn cleaned_filename(name: &str) -> Option<&str> {
    let bytes = name.as_bytes();
    let mut position = 0;
    skip_whitespace(bytes, &mut position);
    consume(bytes, &mut position, b'[')?;
    skip_whitespace(bytes, &mut position);
    consume_digits(bytes, &mut position)?;
    skip_whitespace(bytes, &mut position);
    consume(bytes, &mut position, b'/')?;
    skip_whitespace(bytes, &mut position);
    consume_digits(bytes, &mut position)?;
    skip_whitespace(bytes, &mut position);
    consume(bytes, &mut position, b']')?;
    skip_whitespace(bytes, &mut position);
    if bytes.get(position) == Some(&b'-') {
        position += 1;
    }
    skip_whitespace(bytes, &mut position);
    let quote = [b"\"".as_slice(), b"#34;", b"&#34;", b"&quot;"]
        .into_iter()
        .find(|quote| {
            bytes[position..]
                .get(..quote.len())
                .is_some_and(|head| head.eq_ignore_ascii_case(quote))
        })?;
    position += quote.len();
    let start = position;
    let length = bytes[start..]
        .windows(quote.len())
        .position(|candidate| candidate.eq_ignore_ascii_case(quote))?;
    let file = &name[start..start + length];
    if file.is_empty() || file.bytes().any(|byte| matches!(byte, b'\r' | b'\n')) {
        return None;
    }
    position += length + quote.len();
    skip_whitespace(bytes, &mut position);
    if position < bytes.len() {
        if bytes.get(position) == Some(&b'-') {
            position += 1;
        }
        skip_whitespace(bytes, &mut position);
        if !bytes[position..]
            .get(..4)
            .is_some_and(|head| head.eq_ignore_ascii_case(b"yenc"))
        {
            return None;
        }
        position += 4;
        let before_whitespace = position;
        skip_whitespace(bytes, &mut position);
        if bytes.get(position) == Some(&b'(') && position > before_whitespace {
            position += 1;
            consume_digits(bytes, &mut position)?;
            consume(bytes, &mut position, b'/')?;
            consume_digits(bytes, &mut position)?;
            consume(bytes, &mut position, b')')?;
            skip_whitespace(bytes, &mut position);
        }
        if position != bytes.len() {
            return None;
        }
    }
    let filename = file.trim_matches([' ', '\t', '\n', '\r', '\0', '\u{b}']);
    (!filename.is_empty()).then_some(filename)
}

fn consume(bytes: &[u8], position: &mut usize, expected: u8) -> Option<()> {
    if bytes.get(*position) != Some(&expected) {
        return None;
    }
    *position += 1;
    Some(())
}

fn consume_digits(bytes: &[u8], position: &mut usize) -> Option<()> {
    let start = *position;
    while bytes.get(*position).is_some_and(u8::is_ascii_digit) {
        *position += 1;
    }
    (*position > start).then_some(())
}

fn skip_whitespace(bytes: &[u8], position: &mut usize) {
    // PCRE without UCP uses ASCII whitespace, including vertical tab.
    while bytes
        .get(*position)
        .is_some_and(|byte| matches!(byte, b' ' | b'\t' | b'\n' | b'\r' | 0x0b | 0x0c))
    {
        *position += 1;
    }
}

fn escape(value: &str) -> String {
    let mut escaped = String::with_capacity(value.len());
    for character in value.chars() {
        match character {
            '&' => escaped.push_str("&amp;"),
            '<' => escaped.push_str("&lt;"),
            '>' => escaped.push_str("&gt;"),
            '"' => escaped.push_str("&quot;"),
            '\'' => escaped.push_str("&#039;"),
            character => escaped.push(character),
        }
    }
    escaped
}

#[cfg(test)]
mod tests {
    use super::*;
    use serde_json::json;

    fn input(releases: serde_json::Value) -> Vec<u8> {
        serde_json::to_vec(
            &json!({"url_prefix": "/details/", "url_suffix": "", "releases": releases}),
        )
        .unwrap()
    }

    fn rows(releases: serde_json::Value) -> Vec<String> {
        render(&input(releases)).unwrap()
    }

    #[test]
    fn renders_exact_escaped_html_and_preserves_unicode() {
        let html = render(
            &serde_json::to_vec(&json!({
                "url_prefix": "/details/",
                "url_suffix": "?\" onmouseover='x'&a=<é>",
                "releases": [{
                    "searchname": "映画 & <script>\"'",
                    "name": "<img onerror=\"x\">&quot;'",
                    "guid": "x"
                }]
            }))
            .unwrap(),
        )
        .unwrap();
        assert_eq!(
            html,
            [
                "<a href=\"/details/x?&quot; onmouseover=&#039;x&#039;&amp;a=&lt;é&gt;\" class=\"text-gray-900 dark:text-gray-100 font-medium max-w-md wrap-break-word break-all block hover:text-blue-600 dark:hover:text-blue-400 hover:underline focus-visible:underline\" title=\"映画 &amp; &lt;script&gt;&quot;&#039;\">映画 &amp; &lt;script&gt;&quot;&#039;</a><div class=\"text-gray-500 dark:text-gray-400 text-xs mt-1 max-w-md truncate\" title=\"&lt;img onerror=&quot;x&quot;&gt;&amp;quot;&#039;\">&lt;img onerror=&quot;x&quot;&gt;&amp;quot;&#039;</div>"
            ]
        );
    }

    #[test]
    fn cleans_wrappers_and_omits_an_equal_original() {
        let html = rows(json!([{
            "searchname": "[ 01 / 10 ]-\" Unknown.File.enc \"",
            "name": "[1/2] &quot;Unknown.File.enc&quot; - yEnC (1/20)",
            "guid": "guid"
        }]));
        assert_eq!(
            html,
            [
                "<a href=\"/details/guid\" class=\"text-gray-900 dark:text-gray-100 font-medium max-w-md wrap-break-word break-all block hover:text-blue-600 dark:hover:text-blue-400 hover:underline focus-visible:underline\" title=\"Unknown.File.enc\">Unknown.File.enc</a>"
            ]
        );
    }

    #[test]
    fn nullable_names_render_as_empty_and_distinct_originals_remain_visible() {
        assert_eq!(
            rows(json!([{"searchname": null,"name": null,"guid":"guid"}])),
            [
                "<a href=\"/details/guid\" class=\"text-gray-900 dark:text-gray-100 font-medium max-w-md wrap-break-word break-all block hover:text-blue-600 dark:hover:text-blue-400 hover:underline focus-visible:underline\" title=\"\"></a>"
            ]
        );
        assert_eq!(
            rows(
                json!([{"searchname":"Recovered","name":"[1/2] #34;Original.rar#34; yEnc","guid":"guid"}])
            ),
            [
                "<a href=\"/details/guid\" class=\"text-gray-900 dark:text-gray-100 font-medium max-w-md wrap-break-word break-all block hover:text-blue-600 dark:hover:text-blue-400 hover:underline focus-visible:underline\" title=\"Recovered\">Recovered</a><div class=\"text-gray-500 dark:text-gray-400 text-xs mt-1 max-w-md truncate\" title=\"Original.rar\">Original.rar</div>"
            ]
        );
    }

    #[test]
    fn accepts_empty_batch_and_rejects_malformed_input() {
        assert_eq!(render(&input(json!([]))), Some(Vec::new()));
        for input in [
            b"".as_slice(),
            b"null",
            b"{}",
            b"[]",
            b"{\"url_prefix\":null,\"url_suffix\":\"\",\"releases\":[]}",
            b"{\"url_prefix\":\"/details/\",\"releases\":[]}",
            b"{\"url_prefix\":\"\",\"url_suffix\":\"\",\"releases\":{}}",
            b"[null]",
            b"[{\"url\":null}]",
            b"[{\"url\":\"x\",\"searchname\":3}]",
            b"[{}]",
            b"[] trailing",
            b"[\"\xff\"]",
        ] {
            assert!(render(input).is_none(), "accepted {input:?}");
        }
        for releases in [
            json!([null]),
            json!([{}]),
            json!([{"guid":null}]),
            json!([{"guid":"x","searchname":3}]),
        ] {
            assert!(render(&input(releases)).is_none());
        }
    }

    #[test]
    fn expands_safe_guids_and_escapes_both_shared_url_parts() {
        let input = serde_json::to_vec(&json!({
            "url_prefix": "/a&\"/details/", "url_suffix": "?a='value'&b=1",
            "releases": [{"guid":"12345678-1234-1234-abcd-123456789abc", "searchname":"Title", "name":null}]
        })).unwrap();
        assert_eq!(render(&input), Some(vec!["<a href=\"/a&amp;&quot;/details/12345678-1234-1234-abcd-123456789abc?a=&#039;value&#039;&amp;b=1\" class=\"text-gray-900 dark:text-gray-100 font-medium max-w-md wrap-break-word break-all block hover:text-blue-600 dark:hover:text-blue-400 hover:underline focus-visible:underline\" title=\"Title\">Title</a>".to_owned()]));
        for guid in ["abc123", "AZaz09._~-", &"x".repeat(128)] {
            assert_eq!(
                rows(json!([{"guid":guid,"searchname":null,"name":null}])).len(),
                1
            );
        }
    }

    #[test]
    fn rejects_unsafe_guids_and_oversized_shared_url_templates() {
        for guid in [
            "",
            ".",
            "..",
            "../escape",
            "x/y",
            "x\\y",
            "%2f",
            "a?b",
            "a#b",
            "a b",
            "é",
            "a\0b",
            &"x".repeat(129),
        ] {
            assert!(
                render(&input(json!([{"guid":guid,"name":null,"searchname":null}]))).is_none(),
                "accepted {guid:?}"
            );
        }
        for (prefix, suffix, valid) in [
            ("x".repeat(32768), "y".repeat(32768), true),
            ("x".repeat(32768), "y".repeat(32769), false),
        ] {
            let input =
                serde_json::to_vec(&json!({"url_prefix":prefix,"url_suffix":suffix,"releases":[]}))
                    .unwrap();
            assert_eq!(render(&input).is_some(), valid);
        }
    }

    #[test]
    fn bounds_total_html_after_shared_template_expansion_and_escaping() {
        let row = json!({"guid":"x","searchname":null,"name":null});
        for (count, valid) in [(51, true), (52, false)] {
            let input = serde_json::to_vec(&json!({
                "url_prefix": "&".repeat(65536), "url_suffix": "",
                "releases": vec![row.clone(); count]
            }))
            .unwrap();
            assert_eq!(render(&input).is_some(), valid, "row count {count}");
        }
    }

    #[test]
    fn bounds_batch_size_and_combined_row_bytes() {
        let row = json!({"searchname":"x","name":null,"guid":"u"});
        assert_eq!(rows(json!(vec![row.clone(); 1000])).len(), 1000);
        assert!(render(&input(json!(vec![row; 1001]))).is_none());
        let at_limit = json!([{"searchname":"é".repeat(32767),"name":"x","guid":"u"}]);
        assert_eq!(rows(at_limit).len(), 1);
        let over_limit = json!([{"searchname":"é".repeat(32767),"name":"xx","guid":"u"}]);
        assert!(render(&input(over_limit)).is_none());
    }

    #[test]
    fn bounds_total_json_bytes_including_whitespace() {
        let empty = input(json!([]));
        let mut input = vec![b' '; 8 * 1024 * 1024 - empty.len()];
        input.extend_from_slice(&empty);
        assert_eq!(render(&input), Some(Vec::new()));
        input.push(b' ');
        assert!(render(&input).is_none());
    }

    #[test]
    fn concurrent_batches_keep_row_order_and_thread_local_names() {
        let workers: Vec<_> = (0..8)
            .map(|worker| {
                std::thread::spawn(move || {
                    let title = format!("Thread-{worker} 映画\0");
                    let input = input(json!([
                        {"searchname": title, "name": null, "guid": "first"},
                        {"searchname": "[1/2] #34;0#34; yEnc", "name": "0", "guid": "second"}
                    ]));
                    let expected = vec![
                        format!("<a href=\"/details/first\" class=\"text-gray-900 dark:text-gray-100 font-medium max-w-md wrap-break-word break-all block hover:text-blue-600 dark:hover:text-blue-400 hover:underline focus-visible:underline\" title=\"Thread-{worker} 映画\0\">Thread-{worker} 映画\0</a>"),
                        "<a href=\"/details/second\" class=\"text-gray-900 dark:text-gray-100 font-medium max-w-md wrap-break-word break-all block hover:text-blue-600 dark:hover:text-blue-400 hover:underline focus-visible:underline\" title=\"0\">0</a>".to_owned(),
                    ];
                    for _ in 0..100 {
                        assert_eq!(render(&input), Some(expected.clone()));
                    }
                })
            })
            .collect();
        for worker in workers {
            worker.join().unwrap();
        }
    }

    #[test]
    fn cleaner_matches_php_wrapper_corpus_and_ascii_character_rules() {
        for (subject, expected) in [
            (
                "[2/3] \"1b0ObvkpLy4cDgLV0fW3GoPaxUHKj.rar\"",
                "1b0ObvkpLy4cDgLV0fW3GoPaxUHKj.rar",
            ),
            ("[01/10] - \"Patritcy_A.enc\"", "Patritcy_A.enc"),
            (
                "[1/9] - \"039vphxklmk0hwouhsfx.mkv\"",
                "039vphxklmk0hwouhsfx.mkv",
            ),
            (
                "[2/10] \"Gd71jJg4qdgsblviMRr2Wg.rar\"",
                "Gd71jJg4qdgsblviMRr2Wg.rar",
            ),
            ("[1/2] - #34;Unknown.File.enc#34;", "Unknown.File.enc"),
            ("[ 01 / 10 ]-\"Unknown.File.enc\"", "Unknown.File.enc"),
            ("[1/2] - &quot;Unknown.File.enc&quot;", "Unknown.File.enc"),
            ("[1/2] - &#34;Unknown.File.enc&#34;", "Unknown.File.enc"),
            ("[1/2] - \"0\"", "0"),
            (
                "\r\n\t[\u{b}01\u{c}/2 ] - &QUOT;映画☃.rar&quot; - YeNc\t(01/002) \r\n",
                "映画☃.rar",
            ),
            ("[1/2] \"\0\t file.rar \u{b}\0\"", "file.rar"),
            ("[1/2] \"\u{c}file.rar\u{c}\"", "\u{c}file.rar\u{c}"),
            ("[1/2] \"\u{a0}file.rar\u{a0}\"", "\u{a0}file.rar\u{a0}"),
            ("[1/2] #34;other \" quote#34; yEnc", "other \" quote"),
            ("[1/2] \"file\"yEnc", "file"),
        ] {
            assert_eq!(clean(subject), expected, "subject {subject:?}");
        }
    }

    #[test]
    fn cleaner_preserves_invalid_wrappers_and_meaningful_suffixes_verbatim() {
        for subject in [
            "Some.Movie.2020.1080p-GRP",
            "[Director Cut] Some.Movie.2020",
            "[1/2] - \"Unknown.File.enc",
            "[1/2] - \"\" yEnc",
            "[1/2] - \"Unknown.File.enc\" extra title",
            "[a/b] - \"Unknown.File.enc\"",
            "[1/2] - \"Unknown.File.enc#34;",
            "[1/2] - \"   \" yEnc",
            "[1/2] \"Unknown.File.enc\" \"extra title\"",
            "[1/2] #34;Unknown.File.enc#34; #34;extra title#34;",
            "[1/2] \"before\nafter\" yEnc",
            "[1/2] \"before\rafter\" yEnc",
            "[1/2] \"file\" -",
            "[1/2] \"file\" yEnc (1 /2)",
            "[1/2] \"file\" yEnc(1/2)",
            "[١/2] \"file\"",
            "\u{a0}[1/2] \"file\"",
            "[1/2] \"\0\u{b}\" yEnc",
            "[1/2] \"first\"second\"",
        ] {
            assert_eq!(clean(subject), subject, "subject {subject:?}");
        }
    }
}

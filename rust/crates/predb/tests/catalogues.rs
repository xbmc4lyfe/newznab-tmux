//! The catalogue sources against `fixtures/generate_catalogue_golden.php`, plus the transport and
//! stateful behaviour (one request, local paging, a file changing under the reader) the PHP
//! tests covered.
//!
//! Every golden case is a payload the PHP parser read, with the entries or the error message it
//! gave. `DIVERGENCES` lists the few where the Rust reader answers differently and says why; each
//! is asserted from both sides so the list can't go stale.

use std::collections::BTreeMap;
use std::io::{Read, Write};
use std::path::PathBuf;

use base64::Engine;
use nntmux_predb::feeds::http::FeedHttp;
use nntmux_predb::feeds::sources::parse::{self, NzedbLimits, SceneArchiveLimits};
use nntmux_predb::feeds::sources::{
    DatsPcIsoSource, Nima4kSource, NzedbArchiveSource, SceneArchiveSqlSource, XmlCatalogueSource,
    make,
};
use nntmux_predb::feeds::{Entry, FeedConfig, FeedSource};
use serde_json::{Value, json};
use wiremock::matchers::{method, path, query_param};
use wiremock::{Mock, MockServer, ResponseTemplate};

fn config() -> FeedConfig {
    FeedConfig {
        request_delay_ms: 0,
        timeout_seconds: 5,
        ..FeedConfig::from_env()
    }
}

fn http() -> FeedHttp {
    FeedHttp::new(&config()).expect("http")
}

fn golden() -> Value {
    let compressed = base64::engine::general_purpose::STANDARD
        .decode(include_str!("fixtures/catalogue_golden.json.gz.b64").trim())
        .expect("base64");
    let mut json = String::new();
    flate2::read::GzDecoder::new(&compressed[..])
        .read_to_string(&mut json)
        .expect("gunzip");
    serde_json::from_str(&json).expect("golden")
}

fn fixture(name: &str) -> Vec<u8> {
    let path = PathBuf::from(env!("CARGO_MANIFEST_DIR"))
        .join("tests/fixtures/catalogues")
        .join(name);
    std::fs::read(&path).unwrap_or_else(|error| panic!("{}: {error}", path.display()))
}

// ---- golden input specs, resolved as the generator does ---------------------------------------

fn text_arg(value: &Value) -> Vec<u8> {
    match value {
        Value::String(text) => text.clone().into_bytes(),
        other => base64::engine::general_purpose::STANDARD
            .decode(other["b64"].as_str().expect("b64"))
            .expect("base64"),
    }
}

fn replace(haystack: &[u8], from: &[u8], to: &[u8]) -> Vec<u8> {
    if from.is_empty() {
        return haystack.to_vec();
    }
    let mut out = Vec::with_capacity(haystack.len());
    let mut at = 0;
    while at < haystack.len() {
        if haystack[at..].starts_with(from) {
            out.extend_from_slice(to);
            at += from.len();
        } else {
            out.push(haystack[at]);
            at += 1;
        }
    }
    out
}

fn find(haystack: &[u8], needle: &[u8]) -> Option<usize> {
    haystack.windows(needle.len()).position(|w| w == needle)
}

/// The nZEDb sample is kept with LF endings; the producer's records end in CRLF.
fn nzedb_csv() -> Vec<u8> {
    replace(&fixture("nzedb-2024-05-02-subset.csv"), b"\n", b"\r\n")
}

fn gzip(bytes: &[u8]) -> Vec<u8> {
    let mut encoder = flate2::write::GzEncoder::new(Vec::new(), flate2::Compression::default());
    encoder.write_all(bytes).expect("gzip");
    encoder.finish().expect("gzip")
}

fn resolve(spec: &Value) -> Vec<u8> {
    let mut bytes = if let Some(name) = spec["file"].as_str() {
        fixture(name)
    } else if let Some(text) = spec.get("text") {
        text_arg(text)
    } else if let Some(repeat) = spec.get("repeat") {
        text_arg(&repeat[0]).repeat(repeat[1].as_u64().expect("count") as usize)
    } else if let Some(inner) = spec.get("gzip") {
        gzip(&resolve(inner))
    } else if let Some(parts) = spec.get("concat") {
        parts
            .as_array()
            .expect("parts")
            .iter()
            .flat_map(resolve)
            .collect()
    } else {
        panic!("bad spec {spec}");
    };
    for op in spec["ops"].as_array().into_iter().flatten() {
        bytes = match op[0].as_str().expect("op") {
            "replace" => replace(&bytes, &text_arg(&op[1]), &text_arg(&op[2])),
            "cut_after" => {
                let needle = text_arg(&op[1]);
                bytes.truncate(find(&bytes, &needle).expect("needle") + needle.len());
                bytes
            }
            "append" => [bytes, text_arg(&op[1])].concat(),
            "prepend" => [text_arg(&op[1]), bytes].concat(),
            "drop_tail" => {
                bytes.truncate(bytes.len() - op[1].as_u64().expect("n") as usize);
                bytes
            }
            "flip_from_end" => {
                let at = bytes.len() - op[1].as_u64().expect("n") as usize;
                bytes[at] ^= 255;
                bytes
            }
            other => panic!("op {other}"),
        };
    }
    bytes
}

/// `[title]` or `[title, category]`, after checking the fields these sources never fill.
fn dump(entries: &[Entry], label: &str) -> Value {
    Value::Array(
        entries
            .iter()
            .map(|e| {
                assert_eq!(e.source, label);
                assert!(
                    e.size.is_none()
                        && e.files.is_none()
                        && e.predate.is_none()
                        && e.listed_at.is_none()
                        && e.nuke_reason.is_none()
                        && e.nuked == 0
                        && !e.enrich_only,
                    "{e:?}"
                );
                match &e.category {
                    None => json!([e.title]),
                    Some(category) => json!([e.title, category]),
                }
            })
            .collect(),
    )
}

fn param(case: &Value, name: &str) -> Option<usize> {
    case["params"][name].as_u64().map(|value| value as usize)
}

fn counts(map: &BTreeMap<&'static str, usize>) -> Value {
    json!(map)
}

type Run = fn(&Value, &[u8]) -> Result<Value, String>;

fn html_run(
    case: &Value,
    bytes: &[u8],
    label: &str,
    parse: fn(&[u8], usize, usize) -> nntmux_predb::feeds::FeedResult<Vec<Entry>>,
) -> Result<Value, String> {
    parse(
        bytes,
        param(case, "page_size").unwrap_or(100),
        param(case, "max_bytes").unwrap_or(1 << 20),
    )
    .map(|entries| json!({"entries": dump(&entries, label)}))
    .map_err(|error| error.to_string())
}

fn run_knaben(case: &Value, bytes: &[u8]) -> Result<Value, String> {
    let size = param(case, "page_size").unwrap_or(100).clamp(1, 300);
    parse::knaben(bytes, size, 1 << 20)
        .map(|page| {
            json!({
                "entries": dump(&page.entries, "knaben.org"),
                "rejections": counts(&page.rejections),
                "accepted": page.entries.len(),
            })
        })
        .map_err(|error| error.to_string())
}

fn run_xml(
    key: &'static str,
    label: &'static str,
    case: &Value,
    bytes: &[u8],
) -> Result<Value, String> {
    parse::xml_catalogue(
        key,
        label,
        bytes,
        param(case, "max_bytes").unwrap_or(8 << 20),
        param(case, "max_records").unwrap_or(5000),
    )
    .map(|catalogue| {
        json!({
            "entries": dump(&catalogue.entries, label),
            "rejections": counts(&catalogue.rejections),
            "accepted": catalogue.entries.len(),
            "normalized": catalogue.boundary_normalized,
        })
    })
    .map_err(|error| error.to_string())
}

fn run_dats(case: &Value, bytes: &[u8]) -> Result<Value, String> {
    parse::dats_pciso(
        bytes,
        param(case, "max_bytes").unwrap_or(1 << 20),
        param(case, "max_records").unwrap_or(5000),
    )
    .map(|catalogue| {
        json!({
            "entries": dump(&catalogue.entries, "dats.site"),
            "rejections": counts(&catalogue.rejections),
            "accepted": catalogue.entries.len(),
        })
    })
    .map_err(|error| error.to_string())
}

fn run_nzedb(case: &Value, bytes: &[u8]) -> Result<Value, String> {
    let limits = NzedbLimits {
        maximum_body_bytes: param(case, "max_bytes").unwrap_or(1 << 20),
        maximum_csv_bytes: param(case, "max_csv_bytes").unwrap_or(8 << 20),
        maximum_records: param(case, "max_records").unwrap_or(5000),
    };
    parse::nzedb_gzip(bytes, limits)
        .map(|catalogue| {
            json!({
                "entries": dump(&catalogue.entries, "nzedb-pre-dumps"),
                "rejections": counts(&catalogue.rejections),
                "accepted": catalogue.entries.len(),
            })
        })
        .map_err(|error| error.to_string())
}

/// Case-name prefixes where the Rust reader answers some inputs differently, and why. The PHP read
/// every NIMA4K page twice, with libxml and with an HTML5 parser, and refused a page when the
/// legacy reader complained or the two disagreed. The Rust reads it once, with an HTML5 parser, so
/// whatever only libxml diagnoses is either read as the HTML5 tree has it (accepted) or refused
/// with a different message. `divergent_nima4k_inputs_are_read_as_html5_has_them` pins the
/// accepted readings. At least one case under each prefix must still differ.
const DIVERGENCES: &[(&str, &str, &str)] = &[
    (
        "nswdb",
        "xml encoding: latin1 with utf-8 bytes",
        "a declared ISO-8859-1 is not honoured: PHP read UTF-8 bytes as Latin-1 and imported mojibake, this refuses them",
    ),
    (
        "nswdb",
        "xml encoding: windows-1252 with utf-8 bytes",
        "a declared windows-1252 is not honoured, as above",
    ),
    (
        "nswdb",
        "xml encoding: bogus with ascii",
        "an unknown declared encoding on plain ASCII text is not refused",
    ),
    (
        "nima4k",
        "malformed closing:",
        "end tags with attributes: libxml reports them, HTML5 ignores the attributes",
    ),
    (
        "nima4k",
        "misplaced structural:",
        "repeated html, head and body start tags: libxml reports them, HTML5 merges them",
    ),
    (
        "nima4k",
        "malformed parser:",
        "invalid attribute and element names and a malformed doctype: libxml discards, HTML5 recovers",
    ),
    (
        "nima4k",
        "delayed review active tail:",
        "invalid attribute names and unfinished tags after raw text: libxml reports them",
    ),
    (
        "nima4k",
        "declaration: unterminated PI",
        "an unfinished processing instruction runs to the end as an HTML5 bogus comment",
    ),
    (
        "nima4k",
        "markup in subtitle:",
        "a stray end tag or an unreadable tag inside a subtitle: libxml reports it, HTML5 ignores or keeps it as text",
    ),
    (
        "nima4k",
        "html5 reference:",
        "HTML5-only named references decode here; libxml leaves them undecoded and the two readings disagreed",
    ),
];

fn check(source: &str, run: Run) {
    let golden = golden();
    let cases = golden[source].as_array().expect("cases");
    assert!(!cases.is_empty(), "{source}");
    let mut failures = Vec::new();
    let mut differing: BTreeMap<&str, usize> = BTreeMap::new();
    for case in cases {
        let name = case["name"].as_str().expect("name");
        let actual = run(case, &resolve(&case["input"]));
        let matches = match (&case["out"]["ok"], &case["out"]["error"], &actual) {
            (expected, Value::Null, Ok(actual)) => expected == actual,
            (Value::Null, expected, Err(actual)) => expected.as_str() == Some(actual.as_str()),
            _ => false,
        };
        let known = DIVERGENCES
            .iter()
            .find(|(s, prefix, _)| *s == source && name.starts_with(prefix));
        match (matches, known) {
            (true, _) => {}
            (false, Some((_, prefix, _))) => *differing.entry(prefix).or_default() += 1,
            (false, None) => failures.push(format!(
                "{source}/{name}\n  PHP:  {}\n  Rust: {actual:?}",
                if case["out"]["error"].is_null() {
                    case["out"]["ok"].to_string()
                } else {
                    format!("error {}", case["out"]["error"])
                }
            )),
        }
    }
    for (_, prefix, reason) in DIVERGENCES.iter().filter(|(s, _, _)| *s == source) {
        assert!(
            differing.contains_key(prefix),
            "{source}/{prefix}: no case differs any more; drop the divergence ({reason})"
        );
    }
    assert!(failures.is_empty(), "{}", failures.join("\n"));
}

#[test]
fn g4u_matches_the_php() {
    check("g4u", |case, bytes| {
        html_run(case, bytes, "g4u.to", parse::g4u)
    });
}

#[test]
fn gabber_matches_the_php() {
    check("gabber", |case, bytes| {
        html_run(case, bytes, "gabber.od.ua", parse::gabber)
    });
}

#[test]
fn crowdnfo_matches_the_php() {
    check("crowdnfo", |case, bytes| {
        html_run(case, bytes, "crowdnfo.net", parse::crowdnfo)
    });
}

#[test]
fn seriousaboutsound_matches_the_php() {
    check("seriousaboutsound", |case, bytes| {
        html_run(
            case,
            bytes,
            "seriousaboutsound.net",
            parse::seriousaboutsound,
        )
    });
}

#[test]
fn nima4k_matches_the_php() {
    check("nima4k", |case, bytes| {
        html_run(case, bytes, "nima4k.org", parse::nima4k)
    });
}

#[test]
fn knaben_matches_the_php() {
    check("knaben", run_knaben);
}

#[test]
fn nswdb_matches_the_php() {
    check("nswdb", |case, bytes| {
        run_xml("nswdb", "nswdb.com", case, bytes)
    });
}

#[test]
fn three_ds_db_matches_the_php() {
    check("3dsdb", |case, bytes| {
        run_xml("3dsdb", "3dsdb.com", case, bytes)
    });
}

#[test]
fn dats_pciso_matches_the_php() {
    check("dats_pciso_1998", run_dats);
}

#[test]
fn nzedb_archive_matches_the_php() {
    check("nzedb_archive", run_nzedb);
}

fn scene_limits(case: &Value) -> SceneArchiveLimits {
    let defaults = SceneArchiveLimits::default();
    SceneArchiveLimits {
        max_file_bytes: param(case, "max_file_bytes").map_or(defaults.max_file_bytes, |v| v as u64),
        max_statement_bytes: param(case, "max_statement_bytes")
            .unwrap_or(defaults.max_statement_bytes),
        max_scratch_bytes: param(case, "max_scratch_bytes").unwrap_or(defaults.max_scratch_bytes),
        max_bucket_bytes: param(case, "max_bucket_bytes").unwrap_or(defaults.max_bucket_bytes),
        max_frame_index_bytes: param(case, "max_frame_index_bytes")
            .unwrap_or(defaults.max_frame_index_bytes),
    }
}

#[tokio::test]
async fn scene_archive_sql_matches_the_php() {
    let golden = golden();
    let mut failures = Vec::new();
    for case in golden["scene_archive_sql"].as_array().expect("cases") {
        let name = case["name"].as_str().expect("name");
        let dump = dump_file(&resolve(&case["input"]));
        let file = dump.path.clone();
        let page_size = param(case, "page_size").unwrap_or(100);
        let actual: Result<Value, String> = async {
            let mut source =
                SceneArchiveSqlSource::with_limits(&file, page_size, scene_limits(case))
                    .map_err(|error| format!("{error:#}"))?;
            let mut pages = Vec::new();
            for page in 1..1000 {
                let entries = source.fetch(page).await.map_err(|e| e.to_string())?;
                if entries.is_empty() {
                    break;
                }
                assert!(entries.iter().all(|e| e.source == "scene-archive-sql"));
                pages.push(entries.into_iter().map(|e| e.title).collect::<Vec<_>>());
            }
            Ok(json!({
                "pages": pages,
                "rejections": counts(source.rejection_counts()),
                "accepted": source.accepted_count(),
            }))
        }
        .await;
        let matches = match (&case["out"]["ok"], &case["out"]["error"], &actual) {
            (expected, Value::Null, Ok(actual)) => expected == actual,
            (Value::Null, expected, Err(actual)) => expected.as_str() == Some(actual.as_str()),
            _ => false,
        };
        if !matches {
            failures.push(format!(
                "scene_archive_sql/{name}\n  PHP:  {}\n  Rust: {actual:?}",
                case["out"]
            ));
        }
    }
    assert!(failures.is_empty(), "{}", failures.join("\n"));
}

// ---- transport: one bounded request, local paging, no hidden state -----------------------------

async fn serving(body: Vec<u8>) -> MockServer {
    let server = MockServer::start().await;
    Mock::given(method("GET"))
        .respond_with(ResponseTemplate::new(200).set_body_bytes(body))
        .mount(&server)
        .await;
    server
}

fn built(
    key: &str,
    server: &MockServer,
    tweak: impl FnOnce(&mut FeedConfig),
) -> Box<dyn FeedSource> {
    let mut settings = config();
    settings.endpoint_override = Some(server.uri());
    tweak(&mut settings);
    make(key, &settings, &FeedHttp::new(&settings).expect("http")).expect("make")
}

fn titles(entries: &[Entry]) -> Vec<&str> {
    entries.iter().map(|e| e.title.as_str()).collect()
}

async fn request_count(server: &MockServer) -> usize {
    server.received_requests().await.expect("recording").len()
}

#[tokio::test]
async fn knaben_requests_one_bounded_latest_movie_page() {
    let server = serving(fixture("knaben-movies.json")).await;
    let mut source = built("knaben", &server, |c| c.page_size = 10);
    assert_eq!(source.fetch(1).await.expect("page").len(), 8);
    assert!(source.fetch(2).await.expect("later page").is_empty());
    assert!(source.fetch(0).await.is_err());
    assert_eq!(
        source.accepted_count(),
        None,
        "Knaben is not a complete catalogue"
    );

    let requests = server.received_requests().await.expect("recording");
    assert_eq!(requests.len(), 1);
    let query: BTreeMap<String, String> = requests[0].url.query_pairs().into_owned().collect();
    assert_eq!(
        query,
        BTreeMap::from(
            [
                ("c", "3000000"),
                ("o", "date"),
                ("d", "desc"),
                ("s", "10"),
                ("f", "0")
            ]
            .map(|(k, v)| (k.to_owned(), v.to_owned()))
        )
    );
    assert_eq!(
        requests[0].headers.get("accept-encoding").expect("header"),
        "gzip"
    );
    assert_eq!(
        requests[0].headers.get("user-agent").expect("agent"),
        "NNTmux-PreDB-Importer/1.0"
    );
}

#[tokio::test]
async fn knaben_caps_the_requested_size_and_reads_gzip() {
    let server = MockServer::start().await;
    Mock::given(method("GET"))
        .and(query_param("s", "300"))
        .respond_with(
            ResponseTemplate::new(200)
                .insert_header("Content-Encoding", "gzip")
                .set_body_bytes(gzip(&fixture("knaben-movies.json"))),
        )
        .mount(&server)
        .await;
    let mut source = built("knaben", &server, |c| c.page_size = 999);
    assert_eq!(source.fetch(1).await.expect("page").len(), 8);
}

#[tokio::test]
async fn knaben_failures_and_oversize_bodies_are_errors() {
    let blocked = MockServer::start().await;
    Mock::given(method("GET"))
        .respond_with(ResponseTemplate::new(403).set_body_string("blocked"))
        .mount(&blocked)
        .await;
    let error = built("knaben", &blocked, |_| {})
        .fetch(1)
        .await
        .expect_err("403");
    assert!(error.to_string().contains("403"), "{error}");
    assert_eq!(request_count(&blocked).await, 1);

    for body in [
        ResponseTemplate::new(200).set_body_bytes(vec![b'x'; (1 << 20) + 1]),
        ResponseTemplate::new(200)
            .insert_header("Content-Encoding", "gzip")
            .set_body_bytes(gzip(&vec![b'x'; (1 << 20) + 1])),
    ] {
        let server = MockServer::start().await;
        Mock::given(method("GET"))
            .respond_with(body)
            .mount(&server)
            .await;
        let error = built("knaben", &server, |_| {})
            .fetch(1)
            .await
            .expect_err("oversize");
        assert!(error.to_string().contains("body limit"), "{error}");
    }
}

#[tokio::test]
async fn latest_only_html_sources_make_one_request() {
    for (key, file, count) in [
        ("g4u", "g4u.html", 10),
        ("crowdnfo", "crowdnfo-home.html", 5),
        ("seriousaboutsound", "seriousaboutsound-releases.html", 3),
        ("nima4k", "nima4k.html", 10),
        ("gabber", "gabber.html", 5),
    ] {
        let server = serving(fixture(file)).await;
        let mut source = built(key, &server, |_| {});
        assert_eq!(source.key(), key);
        assert_eq!(source.fetch(1).await.expect(key).len(), count, "{key}");
        assert!(source.fetch(2).await.expect(key).is_empty(), "{key}");
        assert_eq!(request_count(&server).await, 1, "{key}");
        assert_eq!(source.accepted_count(), None, "{key}");
    }
}

#[tokio::test]
async fn heading_sources_reject_page_zero_without_a_request() {
    for (key, message) in [
        ("crowdnfo", "CROWDNFO pages start at one."),
        ("seriousaboutsound", "SERIOUSABOUTSOUND pages start at one."),
        ("knaben", "KNABEN pages start at one."),
        ("nswdb", "NSWDB local pages start at one."),
        ("3dsdb", "3DSDB local pages start at one."),
        (
            "dats_pciso_1998",
            "DATS_PCISO_1998 local pages start at one.",
        ),
        ("nzedb_archive", "NZEDB_ARCHIVE local pages start at one."),
    ] {
        let server = MockServer::start().await;
        let error = built(key, &server, |_| {}).fetch(0).await.expect_err(key);
        assert_eq!(error.to_string(), message);
        assert_eq!(request_count(&server).await, 0, "{key}");
    }
}

#[tokio::test]
async fn nima4k_reports_its_own_body_limit_message() {
    let server = serving(fixture("nima4k.html")).await;
    let mut source = Nima4kSource::new(server.uri(), 100, http()).with_maximum_body_bytes(100);
    let error = source.fetch(1).await.expect_err("limit");
    assert_eq!(error.to_string(), "NIMA4K HTML exceeds the body limit.");
}

#[tokio::test]
async fn gabber_sends_one_request_and_never_follows_redirects() {
    let gz = ResponseTemplate::new(200)
        .insert_header("Content-Encoding", "gzip")
        .set_body_bytes(gzip(&fixture("gabber.html")));
    let server = MockServer::start().await;
    Mock::given(method("GET"))
        .respond_with(gz)
        .mount(&server)
        .await;
    let mut source = built("gabber", &server, |_| {});
    assert_eq!(source.fetch(1).await.expect("page").len(), 5);
    assert!(source.fetch(2).await.expect("later page").is_empty());
    let requests = server.received_requests().await.expect("recording");
    assert_eq!(requests.len(), 1);
    assert_eq!(
        requests[0].headers.get("accept-encoding").expect("header"),
        "gzip"
    );

    for (status, message) in [
        (503, "Gabber returned HTTP status 503."),
        (429, "Gabber returned HTTP status 429."),
        (404, "Gabber returned HTTP status 404."),
        (302, "GABBER returned an unsuccessful listing response."),
    ] {
        let server = MockServer::start().await;
        Mock::given(method("GET"))
            .and(path("/"))
            .respond_with(
                ResponseTemplate::new(status)
                    .insert_header("Location", format!("{}/private", server.uri()))
                    .insert_header("Retry-After", "0")
                    .set_body_string("PRIVATE_PAYLOAD"),
            )
            .mount(&server)
            .await;
        Mock::given(method("GET"))
            .and(path("/private"))
            .respond_with(ResponseTemplate::new(200).set_body_bytes(fixture("gabber.html")))
            .mount(&server)
            .await;
        let error = built("gabber", &server, |_| {})
            .fetch(1)
            .await
            .expect_err("status");
        assert_eq!(error.to_string(), message);
        assert!(
            matches!(error, nntmux_predb::feeds::FeedError::Failed(_)),
            "a 429 must not reach the command's Retry-After backoff"
        );
        assert_eq!(request_count(&server).await, 1, "{status}");
    }
}

#[tokio::test]
async fn a_server_error_is_retried_once_by_the_catalogues_but_not_by_gabber() {
    let server = MockServer::start().await;
    Mock::given(method("GET"))
        .respond_with(ResponseTemplate::new(503))
        .mount(&server)
        .await;
    assert!(built("g4u", &server, |_| {}).fetch(1).await.is_err());
    assert_eq!(request_count(&server).await, 2);
}

#[tokio::test]
async fn nswdb_is_fetched_once_and_paged_locally() {
    for gzipped in [false, true] {
        let body = fixture("nswdb.xml");
        let response = if gzipped {
            ResponseTemplate::new(200)
                .insert_header("Content-Encoding", "gzip")
                .set_body_bytes(gzip(&body))
        } else {
            ResponseTemplate::new(200).set_body_bytes(body)
        };
        let server = MockServer::start().await;
        Mock::given(method("GET"))
            .respond_with(response)
            .mount(&server)
            .await;
        let mut source = XmlCatalogueSource::nswdb(server.uri(), 2, http());
        assert_eq!(source.accepted_count(), Some(0));
        let first = source.fetch(1).await.expect("page 1");
        assert_eq!(
            titles(&first),
            [
                "Ayakashi_Gohan_Okawari_for_S_JPN_NSW-HR",
                "MARIO_Plus_RABBIDS_SPARKS_OF_HOPE_NSW"
            ]
        );
        assert_eq!(
            titles(&source.fetch(2).await.expect("page 2")),
            ["Mega_Man_11_NSW-VENOM", "ARMS.PROPER.NSW-BigBlueBox"]
        );
        assert_eq!(source.fetch(3).await.expect("page 3").len(), 2);
        assert!(source.fetch(4).await.expect("page 4").is_empty());
        assert!(source.fetch(u32::MAX).await.expect("far page").is_empty());
        assert_eq!(source.fetch(1).await.expect("again"), first);
        assert_eq!(source.accepted_count(), Some(6));
        assert_eq!(source.boundary_normalized_count(), 1);
        assert_eq!(request_count(&server).await, 1);
    }
}

#[tokio::test]
async fn a_failed_catalogue_download_is_never_cached_as_a_prefix() {
    let good = fixture("nswdb.xml");
    let server = MockServer::start().await;
    Mock::given(method("GET"))
        .respond_with(ResponseTemplate::new(200).set_body_bytes(good[..good.len() - 12].to_vec()))
        .up_to_n_times(1)
        .mount(&server)
        .await;
    Mock::given(method("GET"))
        .respond_with(ResponseTemplate::new(200).set_body_bytes(good))
        .mount(&server)
        .await;
    let mut source = XmlCatalogueSource::nswdb(server.uri(), 100, http());
    source.fetch(1).await.expect_err("truncated export");
    assert_eq!(source.accepted_count(), Some(0));
    assert!(source.rejection_counts().is_empty());
    assert_eq!(source.fetch(1).await.expect("complete export").len(), 6);
    assert_eq!(request_count(&server).await, 2);
}

#[tokio::test]
async fn a_truncated_gzip_transport_is_not_a_complete_export() {
    let gz = gzip(&fixture("nswdb.xml"));
    let server = MockServer::start().await;
    Mock::given(method("GET"))
        .respond_with(
            ResponseTemplate::new(200)
                .insert_header("Content-Encoding", "gzip")
                .set_body_bytes(gz[..gz.len() - 8].to_vec()),
        )
        .mount(&server)
        .await;
    built("nswdb", &server, |_| {})
        .fetch(1)
        .await
        .expect_err("truncated gzip");
}

#[tokio::test]
async fn nswdb_decoded_size_is_capped_even_when_the_wire_is_small() {
    let xml = format!("<releases>{}</releases>", " ".repeat((8 << 20) + 1));
    let server = MockServer::start().await;
    Mock::given(method("GET"))
        .respond_with(
            ResponseTemplate::new(200)
                .insert_header("Content-Encoding", "gzip")
                .set_body_bytes(gzip(xml.as_bytes())),
        )
        .mount(&server)
        .await;
    let error = built("nswdb", &server, |_| {})
        .fetch(1)
        .await
        .expect_err("bomb");
    assert!(error.to_string().contains("body limit"), "{error}");
}

#[tokio::test]
async fn dats_pciso_is_fetched_once_and_paged_locally() {
    let server = serving(fixture("dats-pciso-1998-subset.xml")).await;
    let mut source = DatsPcIsoSource::new(server.uri(), 2, http());
    assert_eq!(source.key(), "dats_pciso_1998");
    assert_eq!(source.fetch(1).await.expect("page 1").len(), 2);
    assert_eq!(source.accepted_count(), Some(3));
    assert_eq!(
        source.fetch(2).await.expect("page 2")[0].title,
        "Bundesliga_Manager_98_GERMAN_ISO_FF"
    );
    assert!(source.fetch(3).await.expect("page 3").is_empty());
    assert!(source.fetch(u32::MAX).await.expect("far page").is_empty());
    assert_eq!(source.fetch(1).await.expect("again").len(), 2);
    assert_eq!(request_count(&server).await, 1);
}

#[tokio::test]
async fn nzedb_archive_pages_share_one_verified_download() {
    let server = serving(gzip(&nzedb_csv())).await;
    let mut source = NzedbArchiveSource::new(server.uri(), 2, http());
    assert_eq!(source.fetch(1).await.expect("page 1").len(), 2);
    let second = source.fetch(2).await.expect("page 2");
    assert_eq!(
        second[0].title,
        "The.Little.Mermaid.1989.FiNNiSH.HDR.2160p.WEB.H265-TOOSA"
    );
    assert_eq!(source.fetch(3).await.expect("page 3").len(), 1);
    assert!(source.fetch(4).await.expect("page 4").is_empty());
    assert_eq!(source.accepted_count(), Some(5));
    assert_eq!(request_count(&server).await, 1);
}

#[test]
fn nzedb_wire_and_decoded_limits_are_independent() {
    let csv = nzedb_csv();
    let gz = gzip(&csv);
    let limits = |body: usize, decoded: usize| NzedbLimits {
        maximum_body_bytes: body,
        maximum_csv_bytes: decoded,
        maximum_records: 5000,
    };
    assert_eq!(
        parse::nzedb_gzip(&gz, limits(gz.len(), 1 << 20))
            .expect("exact")
            .entries
            .len(),
        5
    );
    let error = parse::nzedb_gzip(&gz, limits(gz.len() - 1, 1 << 20)).expect_err("one short");
    assert_eq!(
        error.to_string(),
        "NZEDB_ARCHIVE response exceeds the body limit."
    );
    let error = parse::nzedb_gzip(&gz, limits(1 << 20, csv.len() - 1)).expect_err("decoded");
    assert_eq!(
        error.to_string(),
        "NZEDB_ARCHIVE decoded CSV exceeds its byte limit."
    );
    assert_eq!(
        parse::nzedb_gzip(&gz, limits(1 << 20, csv.len()))
            .expect("exact decoded")
            .entries
            .len(),
        5
    );
}

// ---- scene archive SQL: a local file read by frames, never executed -----------------------------

/// A file in a directory of its own, removed afterwards.
struct Dump {
    directory: PathBuf,
    path: PathBuf,
}

impl Drop for Dump {
    fn drop(&mut self) {
        let _ = std::fs::remove_dir_all(&self.directory);
    }
}

fn scratch_directory() -> PathBuf {
    static NEXT: std::sync::atomic::AtomicUsize = std::sync::atomic::AtomicUsize::new(0);
    let directory = std::env::temp_dir().join(format!(
        "nntmux-predb-catalogues-{}-{}",
        std::process::id(),
        NEXT.fetch_add(1, std::sync::atomic::Ordering::Relaxed)
    ));
    std::fs::create_dir_all(&directory).expect("scratch directory");
    directory
}

fn dump_file(contents: &[u8]) -> Dump {
    let directory = scratch_directory();
    let path = directory.join("dump.sql");
    std::fs::write(&path, contents).expect("write");
    Dump { directory, path }
}

#[tokio::test]
async fn scene_archive_pages_repeats_and_jumps_are_stable() {
    let dump = dump_file(&fixture("scene-archive-sql-subset.sql"));
    let mut source = SceneArchiveSqlSource::new(&dump.path, 2).expect("source");
    assert_eq!(source.key(), "scene_archive_sql");
    assert_eq!(source.accepted_count(), Some(0));
    let first = source.fetch(1).await.expect("page 1");
    let second = source.fetch(2).await.expect("page 2");
    let third = source.fetch(3).await.expect("page 3");
    assert_eq!(first.len() + second.len() + third.len(), 6);
    assert_eq!(source.fetch(1).await.expect("again"), first);
    assert_eq!(source.fetch(2).await.expect("again"), second);
    assert_eq!(source.fetch(3).await.expect("again"), third);
    assert!(source.fetch(4).await.expect("page 4").is_empty());
    assert!(source.fetch(u32::MAX).await.expect("far page").is_empty());
    assert_eq!(source.accepted_count(), Some(6));

    // A fresh reader jumping straight to the last page reads through the earlier ones.
    let mut jumping = SceneArchiveSqlSource::new(&dump.path, 2).expect("source");
    assert_eq!(jumping.fetch(3).await.expect("page 3"), third);
    assert_eq!(jumping.fetch(2).await.expect("page 2"), second);
}

#[tokio::test]
async fn scene_archive_rejects_a_file_that_changes_after_validation() {
    let original = fixture("scene-archive-sql-subset.sql");
    let dump = dump_file(&original);
    let mut source = SceneArchiveSqlSource::new(&dump.path, 1).expect("source");
    source.fetch(1).await.expect("page 1");
    // Same length, so only the stat fingerprint and the frame hashes can tell.
    std::fs::write(&dump.path, replace(&original, b"ER.S12E13", b"ER.S12E14")).expect("rewrite");
    let error = source.fetch(2).await.expect_err("modified");
    assert!(
        error.to_string().contains("replaced or modified")
            || error
                .to_string()
                .contains("changed after complete validation"),
        "{error}"
    );
    assert_eq!(
        source.accepted_count(),
        Some(0),
        "an error clears the counts"
    );

    let dump = dump_file(&original);
    let mut source = SceneArchiveSqlSource::new(&dump.path, 1).expect("source");
    source.fetch(1).await.expect("page 1");
    let replacement = dump.path.with_extension("new");
    std::fs::write(&replacement, &original).expect("replacement");
    std::fs::rename(&replacement, &dump.path).expect("rename");
    let error = source.fetch(2).await.expect_err("replaced");
    assert_eq!(
        error.to_string(),
        "SCENE_ARCHIVE_SQL input was replaced or modified."
    );
}

#[tokio::test]
async fn scene_archive_only_opens_local_regular_files() {
    let dump = dump_file(&fixture("scene-archive-sql-subset.sql"));
    let directory = dump.directory.clone();
    for target in [
        "https://example.org/data.sql".to_owned(),
        "php://memory".to_owned(),
        String::new(),
        directory.display().to_string(),
        directory.join("missing.sql").display().to_string(),
    ] {
        let mut source = SceneArchiveSqlSource::new(&target, 100).expect("source");
        let error = source.fetch(1).await.expect_err(&target);
        assert_eq!(
            error.to_string(),
            "SCENE_ARCHIVE_SQL requires a local regular SQL metadata file.",
            "{target}"
        );
    }
}

#[test]
fn scene_archive_parse_function_lists_every_eligible_title() {
    let dump = dump_file(&fixture("scene-archive-sql-subset.sql"));
    let (titles, rejections) =
        parse::scene_archive_sql(&dump.path, SceneArchiveLimits::default()).expect("parse");
    assert_eq!(titles.len(), 6);
    assert_eq!(titles[0], "ER.S12E13.iTALiAN.LD.HDTV.XviD-TRiBECA");
    assert!(rejections.is_empty());
}

// ---- construction through the factory ----------------------------------------------------------

#[tokio::test]
async fn the_factory_builds_every_catalogue_source() {
    let server = MockServer::start().await;
    let dump = dump_file(&fixture("scene-archive-sql-subset.sql"));
    for (key, complete) in [
        ("knaben", false),
        ("crowdnfo", false),
        ("seriousaboutsound", false),
        ("g4u", false),
        ("gabber", false),
        ("nima4k", false),
        ("nswdb", true),
        ("3dsdb", true),
        ("dats_pciso_1998", true),
        ("nzedb_archive", true),
        ("scene_archive_sql", true),
    ] {
        let source = built(key, &server, |c| {
            c.scene_archive_sql_file = dump.path.display().to_string();
        });
        assert_eq!(source.key(), key);
        assert_eq!(source.accepted_count().is_some(), complete, "{key}");
    }
    let mut settings = config();
    settings.page_size = 10_001;
    settings.scene_archive_sql_file = dump.path.display().to_string();
    let error = make("scene_archive_sql", &settings, &http())
        .err()
        .expect("page size");
    assert!(
        error.to_string().contains("SCENE_ARCHIVE_SQL limits"),
        "{error}"
    );
}

#[test]
fn single_page_and_manual_only_lists_cover_the_ported_sources() {
    use nntmux_predb::feeds::{MANUAL_ONLY_SOURCES, SINGLE_PAGE_SOURCES};
    for key in [
        "knaben",
        "crowdnfo",
        "seriousaboutsound",
        "g4u",
        "gabber",
        "nima4k",
    ] {
        assert!(SINGLE_PAGE_SOURCES.contains(&key), "{key}");
    }
    for key in [
        "nswdb",
        "3dsdb",
        "dats_pciso_1998",
        "nzedb_archive",
        "scene_archive_sql",
    ] {
        assert!(MANUAL_ONLY_SOURCES.contains(&key), "{key}");
    }
}

// ---- where the single HTML5 reading differs from the PHP's two --------------------------------

fn nima4k_card(title: &str) -> String {
    format!(
        "<div class=\"article uk-clearfix\"><div class=\"cover-box\"><img alt=\"{title}\"></div><div class=\"article-right\"><h2><span class=\"subtitle uk-visible-large\">{title}</span></h2></div></div>"
    )
}

fn nima4k_titles(html: &str) -> Result<Vec<String>, String> {
    parse::nima4k(html.as_bytes(), 100, 1 << 20)
        .map(|entries| entries.into_iter().map(|e| e.title).collect())
        .map_err(|error| error.to_string())
}

#[test]
fn divergent_nima4k_inputs_are_read_as_html5_has_them() {
    let first = nima4k_card("First.Release-GRP");
    let second = nima4k_card("Second.Release-GRP");
    let both = ["First.Release-GRP", "Second.Release-GRP"];

    // Each of these the PHP refused because libxml complained; HTML5 recovers the same tree a
    // browser builds, and the two release names are unaffected.
    for fragment in [
        "<body data-marker=\"safe\"></body>",
        "<html data-marker=\"safe\"></html>",
        "<head data-marker=\"safe\"></head>",
        "<div =\"safe\"></div>",
        "<div>safe</div data-marker=\"safe\">",
    ] {
        let page = format!("<html><head></head><body>{first}{fragment}{second}</body></html>");
        assert_eq!(
            nima4k_titles(&page).as_deref(),
            Ok(&both.map(String::from)[..]),
            "{fragment}"
        );
    }
    assert_eq!(
        nima4k_titles(&format!("<!DOCTYPE html junk=\"safe\">{first}{second}")).as_deref(),
        Ok(&both.map(String::from)[..])
    );
    assert_eq!(
        nima4k_titles(&format!("{first}<?unfinished ")).as_deref(),
        Ok(&["First.Release-GRP".to_owned()][..])
    );
    // A stray end tag inside a subtitle is ignored, and HTML5-only named references decode.
    let stray = nima4k_card("Exact.Release-GRP")
        .replace(">Exact.Release-GRP</span>", ">Exact.</b>Release-GRP</span>");
    assert_eq!(
        nima4k_titles(&stray).as_deref(),
        Ok(&["Exact.Release-GRP".to_owned()][..])
    );
    let named = nima4k_card("Exact&period;Release-GRP");
    assert_eq!(
        nima4k_titles(&named).as_deref(),
        Ok(&["Exact.Release-GRP".to_owned()][..])
    );

    // These are still refused, with this reader's own message.
    for (page, message) in [
        (
            nima4k_card("Exact.Release-GRP").replace(
                ">Exact.Release-GRP</span>",
                ">Exact.<!hidden>Release-GRP</span>",
            ),
            "NIMA4K returned an invalid release subtitle.",
        ),
        (
            nima4k_card("Exact.Release-GRP")
                .replace(">Exact.Release-GRP</span>", ">Exact.<1>Release-GRP</span>"),
            "NIMA4K returned a nonliteral or inconsistent release name.",
        ),
    ] {
        assert_eq!(nima4k_titles(&page), Err(message.to_owned()));
    }
}

#[test]
fn nima4k_refuses_cards_the_html5_reading_hides() {
    let hidden = nima4k_card("Hidden.Release-GRP");
    let visible = nima4k_card("Visible.Release-GRP");
    for tag in [
        "template", "iframe", "xmp", "noembed", "noframes", "title", "textarea",
    ] {
        let page = format!("<{tag}>{hidden}</{tag}>{visible}");
        assert_eq!(
            nima4k_titles(&page),
            Err("NIMA4K returned inconsistent HTML release semantics.".to_owned()),
            "{tag}"
        );
    }
    // Comments, scripts and styles are inert by themselves and may mention card markup.
    let page = format!("<!-- {hidden} --><script>var x = '{hidden}';</script>{visible}");
    assert_eq!(
        nima4k_titles(&page),
        Ok(vec!["Visible.Release-GRP".to_owned()])
    );
}

#[test]
fn nima4k_depth_matches_what_libxml_would_have_kept() {
    let card = nima4k_card("Exact.Release-GRP");
    for (depth, accepted) in [(240, true), (251, true), (252, false), (260, false)] {
        let page = format!("{}{card}{}", "<div>".repeat(depth), "</div>".repeat(depth));
        assert_eq!(nima4k_titles(&page).is_ok(), accepted, "{depth}");
    }
}

#[test]
fn hostile_nesting_is_refused_before_it_is_parsed() {
    // html5ever is quadratic in the depth, so 200,000 open divs would take minutes to build.
    let started = std::time::Instant::now();
    let deep = "<div>".repeat(200_000);
    let card = nima4k_card("Exact.Release-GRP");
    assert_eq!(
        nima4k_titles(&format!("{deep}{card}")),
        Err("NIMA4K returned invalid HTML.".to_owned())
    );
    for (label, parser) in [
        ("G4U", parse::g4u as fn(&[u8], usize, usize) -> _),
        ("CROWDNFO", parse::crowdnfo),
        ("SERIOUSABOUTSOUND", parse::seriousaboutsound),
        ("Gabber", parse::gabber),
    ] {
        let error = parser(deep.as_bytes(), 100, 1 << 20).expect_err(label);
        assert_eq!(error.to_string(), format!("{label} returned invalid HTML."));
    }
    // Void elements and closed tags don't count towards it.
    let flat = format!("{}{card}", "<br><div></div>".repeat(5000));
    assert_eq!(
        nima4k_titles(&flat),
        Ok(vec!["Exact.Release-GRP".to_owned()])
    );
    assert!(started.elapsed().as_secs() < 20, "{:?}", started.elapsed());
}

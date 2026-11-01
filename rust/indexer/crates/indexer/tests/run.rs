//! Runs the real binary: logs, the metrics endpoint, trace export, and a clean exit on SIGTERM.

use std::io::{BufRead, BufReader, Read, Write};
use std::net::{TcpListener, TcpStream};
use std::process::{Command, Stdio};
use std::sync::mpsc;
use std::thread;
use std::time::Duration;

const BIN: &str = env!("CARGO_BIN_EXE_indexer");

fn get(addr: &str, path: &str) -> String {
    let mut stream = TcpStream::connect(addr).unwrap();
    write!(
        stream,
        "GET {path} HTTP/1.1\r\nHost: {addr}\r\nConnection: close\r\n\r\n"
    )
    .unwrap();
    let mut response = String::new();
    stream.read_to_string(&mut response).unwrap();
    response
}

/// Accepts OTLP posts and reports each request's path and body size.
fn fake_collector() -> (String, mpsc::Receiver<(String, usize)>) {
    let listener = TcpListener::bind("127.0.0.1:0").unwrap();
    let endpoint = format!("http://{}", listener.local_addr().unwrap());
    let (sender, receiver) = mpsc::channel();
    thread::spawn(move || {
        for stream in listener.incoming() {
            let mut stream = stream.unwrap();
            let mut reader = BufReader::new(stream.try_clone().unwrap());
            let mut request_line = String::new();
            reader.read_line(&mut request_line).unwrap();
            let mut body_len = 0;
            loop {
                let mut header = String::new();
                reader.read_line(&mut header).unwrap();
                if header.trim().is_empty() {
                    break;
                }
                if let Some((name, value)) = header.split_once(':')
                    && name.eq_ignore_ascii_case("content-length")
                {
                    body_len = value.trim().parse().unwrap();
                }
            }
            reader.read_exact(&mut vec![0; body_len]).unwrap();
            stream
                .write_all(b"HTTP/1.1 200 OK\r\nContent-Length: 0\r\nConnection: close\r\n\r\n")
                .unwrap();
            let path = request_line.split_whitespace().nth(1).unwrap().to_owned();
            sender.send((path, body_len)).unwrap();
        }
    });
    (endpoint, receiver)
}

#[test]
fn run_serves_metrics_exports_traces_and_exits_cleanly() {
    let (endpoint, posts) = fake_collector();
    let mut child = Command::new(BIN)
        .arg("run")
        .env_remove("RUST_LOG")
        .env("INDEXER_TELEMETRY__METRICS__LISTEN", "127.0.0.1:0")
        .env("INDEXER_TELEMETRY__LOG_FORMAT", "json")
        .env("INDEXER_TELEMETRY__OTLP__ENDPOINT", &endpoint)
        .stdout(Stdio::piped())
        .spawn()
        .unwrap();

    let mut logs = BufReader::new(child.stdout.take().unwrap()).lines();
    let addr = logs
        .by_ref()
        .map(Result::unwrap)
        .find_map(|line| {
            let (_, rest) = line.split_once(r#""addr":""#)?;
            Some(rest.split_once('"')?.0.to_owned())
        })
        .expect("the binary logs its metrics address as JSON");

    let health = get(&addr, "/healthz");
    assert!(health.starts_with("HTTP/1.1 200"), "{health}");

    let metrics = get(&addr, "/metrics");
    assert!(metrics.starts_with("HTTP/1.1 200"), "{metrics}");
    assert!(metrics.contains("text/plain; version=0.0.4"), "{metrics}");
    let version = env!("CARGO_PKG_VERSION");
    assert!(
        metrics.contains(&format!(r#"indexer_build_info{{version="{version}"}} 1"#)),
        "{metrics}"
    );
    assert!(
        metrics.contains("indexer_new_high_quality_releases_total 0"),
        "{metrics}"
    );

    assert!(get(&addr, "/nope").starts_with("HTTP/1.1 404"));

    let killed = Command::new("kill")
        .arg(child.id().to_string())
        .status()
        .unwrap();
    assert!(killed.success());
    let status = child.wait().unwrap();
    assert!(status.success(), "SIGTERM must exit cleanly, got {status}");

    // Exit flushes the batch, so the startup span has already been posted.
    let (path, body_len) = posts
        .recv_timeout(Duration::from_secs(5))
        .expect("spans were posted");
    assert_eq!(path, "/v1/traces");
    assert!(body_len > 0);
}

#[test]
fn config_prints_effective_values_and_rejects_bad_input() {
    let output = Command::new(BIN)
        .args(["config"])
        .env("INDEXER_TELEMETRY__LOG_FILTER", "debug")
        .output()
        .unwrap();
    assert!(output.status.success());
    let printed = String::from_utf8(output.stdout).unwrap();
    assert!(printed.contains(r#"log_filter = "debug""#), "{printed}");

    let missing = Command::new(BIN)
        .args(["config", "--config", "/nonexistent/indexer.toml"])
        .output()
        .unwrap();
    assert!(!missing.status.success());
    let stderr = String::from_utf8(missing.stderr).unwrap();
    assert!(stderr.contains("does not exist"), "{stderr}");
}

#[test]
fn run_fails_when_the_metrics_port_is_taken() {
    let taken = TcpListener::bind("127.0.0.1:0").unwrap();
    let output = Command::new(BIN)
        .arg("run")
        .env_remove("RUST_LOG")
        .env(
            "INDEXER_TELEMETRY__METRICS__LISTEN",
            taken.local_addr().unwrap().to_string(),
        )
        .output()
        .unwrap();
    assert!(!output.status.success());
    let stderr = String::from_utf8(output.stderr).unwrap();
    assert!(stderr.contains("binding metrics listener"), "{stderr}");
}

/// A data directory holding a catalog with one release.
fn data_dir() -> tempfile::TempDir {
    let dir = tempfile::tempdir().unwrap();
    let paths = indexer_store::StorePaths::under(dir.path());
    let catalog = indexer_store::Catalog::open(&paths.catalog).unwrap();
    catalog
        .conn()
        .execute(
            "INSERT INTO releases (id, guid, name, search_name, size_bytes, completion,
                 group_id, legacy_category, password_status, is_renamed, grabs,
                 inner_file_count, posted_at, tvdb_id, season, episode)
             VALUES (1, 'abc123', 'n', 'Silo.S03E02.1080p.WEB-DL-FLUX', 2000000000, 100, 1,
                 5040, 0, 1, 0, 1, 1700000000, 121361, 3, 2)",
            [],
        )
        .unwrap();
    drop(catalog);
    drop(indexer_store::NzbStore::open(&paths.nzbs).unwrap());
    dir
}

/// The value of a JSON log field on the first line that has it.
fn logged(logs: &mut impl Iterator<Item = String>, field: &str) -> String {
    let key = format!(r#""{field}":""#);
    logs.find_map(|line| {
        let (_, rest) = line.split_once(&key)?;
        Some(rest.split_once('"')?.0.to_owned())
    })
    .unwrap_or_else(|| panic!("the binary logs {field} as JSON"))
}

#[test]
fn run_serves_the_newznab_api_from_the_catalog() {
    let data = data_dir();
    let mut child = Command::new(BIN)
        .arg("run")
        .env_remove("RUST_LOG")
        .env("INDEXER_STORAGE__DATA_DIR", data.path())
        .env("INDEXER_TELEMETRY__METRICS__LISTEN", "127.0.0.1:0")
        .env("INDEXER_TELEMETRY__LOG_FORMAT", "json")
        .env("INDEXER_API__LISTEN", "127.0.0.1:0")
        .env("INDEXER_API__API_KEYS", r#"["sekrit"]"#)
        .stdout(Stdio::piped())
        .spawn()
        .unwrap();
    let mut logs = BufReader::new(child.stdout.take().unwrap())
        .lines()
        .map(Result::unwrap);
    let metrics_addr = logged(&mut logs, "addr");
    let api_addr = logged(&mut logs, "api_addr");

    let caps = get(&api_addr, "/api?t=caps");
    assert!(caps.starts_with("HTTP/1.1 200"), "{caps}");
    assert!(caps.contains("<tv-search available=\"yes\""), "{caps}");

    let found = get(
        &api_addr,
        "/api?t=tvsearch&apikey=sekrit&tvdbid=121361&season=3&ep=2",
    );
    assert!(
        found.contains("<guid isPermaLink=\"false\">abc123</guid>"),
        "{found}"
    );
    let text = get(&api_addr, "/api?t=search&apikey=sekrit&q=silo+s03e02");
    assert!(text.contains("total=\"1\""), "{text}");
    assert!(get(&api_addr, "/api?t=search&q=silo").starts_with("HTTP/1.1 401"));

    let metrics = get(&metrics_addr, "/metrics");
    assert!(
        metrics.contains(r#"indexer_api_requests_total{type="tvsearch",status="ok"} 1"#),
        "{metrics}"
    );
    assert!(
        metrics.contains(r#"indexer_api_request_duration_seconds_count{type="search"} 2"#),
        "{metrics}"
    );

    Command::new("kill")
        .arg(child.id().to_string())
        .status()
        .unwrap();
    assert!(child.wait().unwrap().success());
    assert!(data.path().join("search").is_dir(), "run built the index");
}

#[test]
fn reindex_rebuilds_the_search_index() {
    let data = data_dir();
    let output = Command::new(BIN)
        .arg("reindex")
        .env_remove("RUST_LOG")
        .env("INDEXER_STORAGE__DATA_DIR", data.path())
        .output()
        .unwrap();
    assert!(output.status.success(), "{output:?}");
    assert!(data.path().join("search").is_dir());
    assert!(!data.path().join("search.new").exists());
}

//! Behaviour the PHP client's tests pinned down, against a scripted server on a real socket.

use std::io::Write;
use std::sync::{Arc, Mutex};
use std::time::Duration;

use flate2::Compression;
use flate2::write::ZlibEncoder;
use nntmux_nntp::pipeline::{self, Batch, BatchError, Mode};
use nntmux_nntp::{Identifier, NntpClient, NntpConfig, NntpError, NntpPool, Provider, TlsOptions};
use tokio::io::{AsyncBufReadExt, AsyncReadExt, AsyncWriteExt, BufReader};
use tokio::net::TcpListener;

/// One expected command and the bytes that answer it. An empty reply says nothing.
type Step = (&'static str, Vec<u8>);

struct Server {
    port: u16,
    commands: Arc<Mutex<Vec<String>>>,
    connections: Arc<Mutex<usize>>,
}

impl Server {
    fn commands(&self) -> Vec<String> {
        self.commands.lock().unwrap().clone()
    }

    fn connections(&self) -> usize {
        *self.connections.lock().unwrap()
    }
}

/// Serves one script per connection, in order. After its script a connection stays open until
/// the client hangs up, so a client that reads too far times out instead of seeing EOF.
async fn serve(scripts: Vec<(Vec<u8>, Vec<Step>)>) -> Server {
    let listener = TcpListener::bind("127.0.0.1:0").await.unwrap();
    let port = listener.local_addr().unwrap().port();
    let commands = Arc::new(Mutex::new(Vec::new()));
    let connections = Arc::new(Mutex::new(0));
    let (log, count) = (Arc::clone(&commands), Arc::clone(&connections));
    tokio::spawn(async move {
        for (greeting, steps) in scripts {
            let Ok((stream, _)) = listener.accept().await else {
                return;
            };
            *count.lock().unwrap() += 1;
            let log = Arc::clone(&log);
            tokio::spawn(async move {
                let mut io = BufReader::new(stream);
                io.write_all(&greeting).await.unwrap();
                for (expected, reply) in steps {
                    if !expected.is_empty() {
                        let mut line = String::new();
                        if io.read_line(&mut line).await.unwrap_or(0) == 0 {
                            return;
                        }
                        let line = line.trim_end().to_string();
                        assert!(line.starts_with(expected), "{line:?} is not {expected:?}");
                        log.lock().unwrap().push(line);
                    }
                    if io.write_all(&reply).await.is_err() {
                        return;
                    }
                }
                let mut rest = Vec::new();
                while io.read_buf(&mut rest).await.unwrap_or(0) > 0 {
                    for line in String::from_utf8_lossy(&rest).lines() {
                        log.lock().unwrap().push(line.to_string());
                    }
                    rest.clear();
                }
            });
        }
    });
    Server {
        port,
        commands,
        connections,
    }
}

fn provider(port: u16) -> Provider {
    Provider {
        host: "127.0.0.1".into(),
        port,
        tls: false,
        username: String::new(),
        password: String::new(),
        socket_timeout: Duration::from_secs(1),
    }
}

fn config(primary: u16, alternate: Option<u16>) -> NntpConfig {
    NntpConfig {
        primary: provider(primary),
        alternate: alternate.map(provider),
        tls: TlsOptions::default(),
        primary_connections: 1,
        alternate_connections: 1,
        compressed_headers: false,
        use_alternate: alternate.is_some(),
        alternate_article_fallback: alternate.is_some(),
        fingerprint_breaker_seconds: 900,
        retries: 1,
    }
}

fn client(config: NntpConfig) -> NntpClient {
    NntpClient::new(Arc::new(config))
}

fn ready() -> Vec<u8> {
    b"200 ready\r\n".to_vec()
}

fn reply(text: &str) -> Vec<u8> {
    text.as_bytes().to_vec()
}

const FORMAT: &str = "215 follows\r\nSubject:\r\nFrom:\r\nDate:\r\nMessage-ID:\r\nReferences:\r\nBytes:\r\nLines:\r\nXref:full\r\n.\r\n";

async fn connected(server: &Server) -> NntpClient {
    let mut nntp = client(config(server.port, None));
    nntp.do_connect(false, false).await.unwrap();
    nntp
}

#[tokio::test]
async fn logs_in_with_the_wire_form_the_client_always_used() {
    let server = serve(vec![(
        ready(),
        vec![
            ("AUTHINFO user alice", reply("381 more\r\n")),
            ("AUTHINFO pass secret", reply("281 ok\r\n")),
        ],
    )])
    .await;
    let mut settings = config(server.port, None);
    settings.primary.username = "alice".into();
    settings.primary.password = "secret".into();
    let mut nntp = client(settings);
    nntp.do_connect(false, false).await.unwrap();
    assert!(nntp.is_posting_allowed());
    assert!(!nntp.is_compression_supported());
}

#[tokio::test]
async fn retries_a_refused_greeting_then_reports_the_first_failure() {
    let server = serve(vec![
        (reply("502 busy\r\n"), vec![]),
        (reply("201 no posting\r\n"), vec![]),
    ])
    .await;
    let mut settings = config(server.port, None);
    settings.retries = 2;
    let mut nntp = client(settings);
    nntp.do_connect(false, false).await.unwrap();
    assert!(!nntp.is_posting_allowed());
    assert_eq!(server.connections(), 2);

    let server = serve(vec![(reply("502 busy\r\n"), vec![])]).await;
    let error = client(config(server.port, None))
        .do_connect(false, false)
        .await
        .unwrap_err()
        .to_string();
    assert!(
        error.starts_with("Cannot connect to server 127.0.0.1 (non-ssl): "),
        "{error}"
    );
    assert!(error.contains("No permission"), "{error}");
}

#[tokio::test]
async fn a_rejected_login_reports_why() {
    let server = serve(vec![
        (
            ready(),
            vec![("AUTHINFO user a", reply("482 at limit\r\n"))],
        ),
        (ready(), vec![("AUTHINFO user a", reply("281 ok\r\n"))]),
    ])
    .await;
    let mut settings = config(server.port, None);
    settings.primary.username = "a".into();
    settings.retries = 1;
    let error = client(settings).do_connect(false, false).await.unwrap_err();
    assert_eq!(
        error.to_string(),
        "Cannot authenticate to server 127.0.0.1 (non-ssl) - a (Authentication rejected)"
    );
}

#[tokio::test]
async fn a_rejected_login_is_not_mistaken_for_a_session_next_time() {
    let server = serve(vec![
        (
            ready(),
            vec![("AUTHINFO user a", reply("502 too many connections\r\n"))],
        ),
        (ready(), vec![("AUTHINFO user a", reply("281 ok\r\n"))]),
    ])
    .await;
    let mut settings = config(server.port, None);
    settings.primary.username = "a".into();
    let mut nntp = client(settings);
    assert!(nntp.do_connect(false, false).await.is_err());
    nntp.do_connect(false, false).await.unwrap();
    assert_eq!(server.connections(), 2);
}

#[tokio::test]
async fn a_late_reply_is_never_taken_for_the_next_commands_answer() {
    let server = serve(vec![
        (ready(), vec![("BODY <a@x>", Vec::new()), ("", Vec::new())]),
        (
            ready(),
            vec![("BODY <b@x>", reply("222 0 <b@x>\r\nARTICLE-B\r\n.\r\n"))],
        ),
    ])
    .await;
    let mut settings = config(server.port, None);
    settings.primary.socket_timeout = Duration::from_millis(100);
    let mut nntp = client(settings);
    nntp.do_connect(false, false).await.unwrap();
    assert!(
        nntp.get_messages_by_message_id(&["a@x".to_string()], false, None)
            .await
            .is_err()
    );
    assert_eq!(
        nntp.get_messages_by_message_id(&["b@x".to_string()], false, None)
            .await
            .unwrap(),
        b"ARTICLE-B\r\n"
    );
    assert_eq!(server.connections(), 2);
}

#[tokio::test]
async fn the_pool_discards_a_session_whose_caller_was_cancelled() {
    let server = serve(vec![
        (
            ready(),
            vec![("BODY <a@x>", reply("222 0 <a@x>\r\npartial\r\n"))],
        ),
        (ready(), vec![]),
    ])
    .await;
    let mut settings = config(server.port, None);
    settings.primary.socket_timeout = Duration::from_secs(5);
    let pool = NntpPool::new(settings);
    let mut pooled = pool.acquire(false).await.unwrap();
    let ids = ["a@x".to_string()];
    let cancelled = tokio::time::timeout(
        Duration::from_millis(100),
        pooled.get_messages_by_message_id(&ids, false, None),
    )
    .await;
    assert!(cancelled.is_err());
    drop(pooled);
    drop(pool.acquire(false).await.unwrap());
    assert_eq!(server.connections(), 2);
}

#[tokio::test]
async fn a_lost_connection_selects_its_group_again() {
    let server = serve(vec![
        (
            ready(),
            vec![
                ("GROUP alt.test", reply("211 1 1 1 alt.test\r\n")),
                ("XOVER 1", Vec::new()),
            ],
        ),
        (
            ready(),
            vec![
                ("GROUP alt.test", reply("211 1 1 1 alt.test\r\n")),
                ("XOVER 2", reply("224 follows\r\n2\ts\r\n.\r\n")),
                ("LIST OVERVIEW.FMT", reply(FORMAT)),
            ],
        ),
    ])
    .await;
    let mut settings = config(server.port, None);
    settings.primary.socket_timeout = Duration::from_millis(200);
    let mut nntp = client(settings);
    nntp.do_connect(false, false).await.unwrap();
    nntp.select_group("alt.test", false).await.unwrap();
    assert!(nntp.get_xover("1").await.is_err());
    assert_eq!(nntp.get_xover("2").await.unwrap().len(), 1);
}

#[tokio::test]
async fn a_later_article_reconnects_after_an_earlier_one_timed_out() {
    let server = serve(vec![
        (
            ready(),
            vec![
                ("GROUP alt.test", reply("211 1 1 1 alt.test\r\n")),
                ("BODY <a@x>", Vec::new()),
            ],
        ),
        (
            ready(),
            vec![
                ("GROUP alt.test", reply("211 1 1 1 alt.test\r\n")),
                ("BODY <b@x>", reply("222 0 <b@x>\r\nsecond\r\n.\r\n")),
            ],
        ),
    ])
    .await;
    let mut settings = config(server.port, None);
    settings.primary.socket_timeout = Duration::from_millis(200);
    let mut nntp = client(settings);
    nntp.do_connect(false, false).await.unwrap();
    let first = nntp
        .get_messages("alt.test", &[Identifier::parse("a@x")], false, None)
        .await;
    assert!(first.is_err());
    let second = nntp
        .get_messages("alt.test", &[Identifier::parse("b@x")], false, None)
        .await
        .unwrap();
    assert_eq!(second, b"second\r\n");
}

#[tokio::test]
async fn a_kept_client_recovers_after_its_call_was_cancelled() {
    let server = serve(vec![
        (
            ready(),
            vec![("BODY <a@x>", reply("222 0 <a@x>\r\npartial\r\n"))],
        ),
        (
            ready(),
            vec![("BODY <b@x>", reply("222 0 <b@x>\r\nsecond\r\n.\r\n"))],
        ),
    ])
    .await;
    let mut settings = config(server.port, None);
    settings.primary.socket_timeout = Duration::from_secs(5);
    let mut nntp = client(settings);
    nntp.do_connect(false, false).await.unwrap();
    let ids = ["a@x".to_string()];
    let cancelled = tokio::time::timeout(
        Duration::from_millis(100),
        nntp.get_messages_by_message_id(&ids, false, None),
    )
    .await;
    assert!(cancelled.is_err());
    assert_eq!(
        nntp.get_messages_by_message_id(&["b@x".to_string()], false, None)
            .await
            .unwrap(),
        b"second\r\n"
    );
}

#[tokio::test]
async fn a_refused_group_keeps_the_previous_selection_and_is_asked_again() {
    let server = serve(vec![(
        ready(),
        vec![
            ("GROUP alt.first", reply("211 1 1 1 alt.first\r\n")),
            ("GROUP alt.second", reply("411 no such\r\n")),
            ("GROUP alt.second", reply("411 still no\r\n")),
        ],
    )])
    .await;
    let mut nntp = connected(&server).await;
    nntp.select_group("alt.first", false).await.unwrap();
    for _ in 0..2 {
        assert_eq!(
            nntp.select_group("alt.second", false).await.unwrap_err().code(),
            Some(411)
        );
        assert_eq!(nntp.current_group(), "alt.first");
    }
}

#[tokio::test]
async fn a_status_line_cut_off_by_a_timeout_is_not_a_reply() {
    let server = serve(vec![(ready(), vec![("BODY <a@b>", reply("222 0 <a@b> trunc"))])]).await;
    let mut settings = config(server.port, None);
    settings.primary.socket_timeout = Duration::from_millis(100);
    let mut nntp = client(settings);
    nntp.do_connect(false, false).await.unwrap();
    let error = nntp
        .get_messages_by_message_id(&["a@b".to_string()], false, None)
        .await
        .unwrap_err();
    assert_eq!(error.to_string(), "Failed to read from socket...!");
    assert!(!nntp.is_connected().await);
}

#[tokio::test]
async fn caches_the_group_summary_until_forced() {
    let server = serve(vec![(
        ready(),
        vec![
            ("GROUP alt.test", reply("211 10 5 14 alt.test\r\n")),
            ("GROUP alt.test", reply("211 11 5 15 alt.test\r\n")),
            ("GROUP missing", reply("411 no such\r\n")),
        ],
    )])
    .await;
    let mut nntp = connected(&server).await;
    let first = nntp.select_group("alt.test", false).await.unwrap();
    assert_eq!((first.count, first.first, first.last), (10, 5, 14));
    assert_eq!(first.group, "alt.test");
    assert_eq!(nntp.select_group("alt.test", false).await.unwrap(), first);
    assert_eq!(nntp.select_group("alt.test", true).await.unwrap().last, 15);
    assert_eq!(
        nntp.select_group("missing", false)
            .await
            .unwrap_err()
            .code(),
        Some(411)
    );
}

#[tokio::test]
async fn xover_names_fields_and_asks_for_the_format_once() {
    let server = serve(vec![(
        ready(),
        vec![
            ("XOVER 1-2", reply("224 follows\r\n1\tsub\tfrom\tdate\t<a@b>\t\t100\t5\tXref: host a:1\r\n..2\tdotted\r\n.\r\n")),
            ("LIST OVERVIEW.FMT", reply(FORMAT)),
            ("XOVER 3", reply("224 follows\r\n3\tsub\r\n.\r\n")),
        ],
    )])
    .await;
    let mut nntp = connected(&server).await;
    let rows = nntp.get_xover("1-2").await.unwrap();
    assert_eq!(rows[0].get("Number"), Some(&b"1"[..]));
    assert_eq!(rows[0].get("Message-ID"), Some(&b"<a@b>"[..]));
    assert_eq!(rows[0].get("Bytes"), Some(&b"100"[..]));
    assert_eq!(rows[0].get("Xref"), Some(&b"host a:1"[..]));
    assert_eq!(rows[1].get("Number"), Some(&b".2"[..]));
    assert_eq!(rows[1].get("Xref"), None);
    assert_eq!(nntp.get_xover("3").await.unwrap().len(), 1);
    assert_eq!(server.commands().len(), 3);
}

#[tokio::test]
async fn overview_fields_stay_aligned_whichever_call_cached_the_format() {
    let server = serve(vec![(
        ready(),
        vec![
            ("XOVER 1", reply("224 follows\r\n1\tsub\tfrom\r\n.\r\n")),
            ("LIST OVERVIEW.FMT", reply(FORMAT)),
            ("XOVER 2", reply("224 follows\r\n2\tother\tfrom2\r\n.\r\n")),
            ("XOVER 3", reply("224 follows\r\n3\tthird\r\n.\r\n")),
        ],
    )])
    .await;
    let mut nntp = connected(&server).await;
    let named = nntp.get_overview("1").await.unwrap();
    assert_eq!(named[0].get("Subject"), Some(&b"sub"[..]));
    assert_eq!(named[0].get("Date"), Some(&b""[..]));
    assert_eq!(named[0].get("Bytes"), Some(&b""[..]));
    let rows = nntp.get_xover("2").await.unwrap();
    assert_eq!(rows[0].get("Number"), Some(&b"2"[..]));
    assert_eq!(rows[0].get("Subject"), Some(&b"other"[..]));
    let again = nntp.get_overview("3").await.unwrap();
    assert_eq!(again[0].get("Number"), Some(&b"3"[..]));

    let server = serve(vec![(
        ready(),
        vec![
            ("XOVER 7", reply("224 follows\r\n7\tsub\r\n.\r\n")),
            ("LIST OVERVIEW.FMT", reply(FORMAT)),
            ("XOVER 8", reply("224 follows\r\n8\tsecond\r\n.\r\n")),
        ],
    )])
    .await;
    let mut nntp = connected(&server).await;
    nntp.get_xover("7").await.unwrap();
    let named = nntp.get_overview("8").await.unwrap();
    assert_eq!(named[0].get("Number"), Some(&b"8"[..]));
    assert_eq!(named[0].get("Subject"), Some(&b"second"[..]));
}

fn zlib(data: &[u8]) -> Vec<u8> {
    let mut encoder = ZlibEncoder::new(Vec::new(), Compression::default());
    encoder.write_all(data).unwrap();
    encoder.finish().unwrap()
}

async fn compressed_client(response: Vec<u8>) -> (NntpClient, Result<Vec<Vec<u8>>, NntpError>) {
    let mut wire = reply("224 compressed [COMPRESS=GZIP]\r\n");
    wire.extend_from_slice(&response);
    let server = serve(vec![(
        ready(),
        vec![
            ("XFEATURE COMPRESS GZIP", reply("290 ok\r\n")),
            ("XOVER 1-3", wire),
            ("LIST OVERVIEW.FMT", reply(FORMAT)),
        ],
    )])
    .await;
    let mut settings = config(server.port, None);
    settings.compressed_headers = true;
    let mut nntp = client(settings);
    nntp.do_connect(true, false).await.unwrap();
    let rows = nntp.get_xover("1-3").await.map(|rows| {
        rows.iter()
            .map(|row| row.get("Number").unwrap().to_vec())
            .collect()
    });
    (nntp, rows)
}

#[tokio::test]
async fn inflates_a_compressed_overview_whatever_its_bytes_contain() {
    let mut stream = zlib(b"1\tsub\r\n2\tsub\r\n3\tsub\r\n");
    stream.extend_from_slice(b".\r\n");
    let (nntp, rows) = compressed_client(stream).await;
    assert_eq!(
        rows.unwrap(),
        vec![b"1".to_vec(), b"2".to_vec(), b"3".to_vec()]
    );
    assert!(nntp.is_compression_enabled());
}

#[tokio::test]
async fn a_corrupt_compressed_overview_is_an_error_and_ends_the_session() {
    let (mut nntp, rows) = compressed_client(b"not zlib at all\r\n.\r\n".to_vec()).await;
    assert_eq!(
        rows.unwrap_err().to_string(),
        "Decompression of OVER headers failed."
    );
    assert!(!nntp.is_connected().await);
}

#[tokio::test]
async fn a_truncated_compressed_overview_is_an_error() {
    let mut stream = zlib(b"1\tsub\r\n");
    stream.truncate(stream.len() - 3);
    let (_, rows) = compressed_client(stream).await;
    assert!(
        rows.unwrap_err()
            .to_string()
            .starts_with("Error fetching data")
    );
}

#[tokio::test]
async fn a_finished_stream_needs_its_terminator() {
    let (_, rows) = compressed_client(zlib(b"1\tsub\r\n")).await;
    assert!(
        rows.unwrap_err()
            .to_string()
            .starts_with("Error fetching data")
    );
}

#[tokio::test]
async fn falls_back_to_plain_list_where_list_active_is_refused() {
    let server = serve(vec![(
        ready(),
        vec![
            ("LIST ACTIVE", reply("500 what\r\n")),
            (
                "LIST",
                reply("215 follows\r\nalt.test 14 5 y\r\nmalformed\r\n.\r\n"),
            ),
        ],
    )])
    .await;
    let mut nntp = connected(&server).await;
    let groups = nntp.get_groups(None).await.unwrap();
    assert_eq!(groups.len(), 1);
    assert_eq!(groups[0].group, "alt.test");
    assert_eq!(
        (groups[0].first.as_str(), groups[0].last.as_str()),
        ("5", "14")
    );
    assert_eq!(groups[0].posting.as_deref(), Some("y"));
}

#[tokio::test]
async fn unstuffs_once_and_leaves_the_next_response_unread() {
    let server = serve(vec![(
        ready(),
        vec![
            ("GROUP alt.test", reply("211 1 1 1 alt.test\r\n")),
            (
                "BODY <a@b>",
                reply("222 0 <a@b>\r\n..line\r\n...two\r\nplain\r\n.\r\n"),
            ),
            ("BODY 7", reply("430 gone\r\n")),
        ],
    )])
    .await;
    let mut nntp = connected(&server).await;
    let ids = [Identifier::parse("a@b")];
    assert_eq!(
        nntp.get_messages("alt.test", &ids, false, None)
            .await
            .unwrap(),
        b".line\r\n..two\r\nplain\r\n"
    );
    let error = nntp
        .get_messages("alt.test", &[Identifier::Number(7)], false, None)
        .await
        .unwrap_err();
    assert_eq!(error.code(), Some(430));
    assert!(error.is_missing_article());
}

#[tokio::test]
async fn long_lines_are_not_unstuffed_where_a_read_split_them() {
    let mut body = "x".repeat(8191);
    body.push_str("..tail");
    let wire = format!("222 0 <a@b>\r\n{body}\r\n.\r\n");
    let server = serve(vec![(ready(), vec![("BODY <a@b>", reply(&wire))])]).await;
    let mut nntp = connected(&server).await;
    let raw = nntp
        .get_messages_by_message_id(&["a@b".to_string()], false, None)
        .await
        .unwrap();
    assert_eq!(raw, format!("{body}\r\n").as_bytes());
}

#[tokio::test]
async fn an_incomplete_body_is_an_error_not_a_prefix() {
    let server = serve(vec![(
        ready(),
        vec![("BODY <a@b>", reply("222 0 <a@b>\r\npartial\r\n"))],
    )])
    .await;
    let mut nntp = connected(&server).await;
    assert!(
        nntp.get_messages_by_message_id(&["a@b".to_string()], false, None)
            .await
            .is_err()
    );
    assert!(!nntp.is_connected().await);
}

#[tokio::test]
async fn a_lost_lock_closes_the_session_without_returning_bytes() {
    let server = serve(vec![(
        ready(),
        vec![
            ("GROUP alt.test", reply("211 1 1 1 alt.test\r\n")),
            ("BODY <a@b>", reply("222 0 <a@b>\r\none\r\ntwo\r\n.\r\n")),
        ],
    )])
    .await;
    let mut nntp = connected(&server).await;
    let mut reads = 0;
    let mut heartbeat = || {
        reads += 1;
        false
    };
    let error = nntp
        .get_messages(
            "alt.test",
            &[Identifier::parse("a@b")],
            false,
            Some(&mut heartbeat),
        )
        .await
        .unwrap_err();
    assert!(error.to_string().contains("lock ownership was lost"));
    assert_eq!(reads, 1);
    assert!(!nntp.is_connected().await);
}

#[tokio::test]
async fn a_failed_later_article_returns_what_was_already_read() {
    let server = serve(vec![(
        ready(),
        vec![
            ("BODY <a@b>", reply("222 0 <a@b>\r\none\r\n.\r\n")),
            ("BODY <c@d>", reply("430 gone\r\n")),
        ],
    )])
    .await;
    let mut nntp = connected(&server).await;
    let ids = ["a@b".to_string(), "c@d".to_string()];
    assert_eq!(
        nntp.get_messages_by_message_id(&ids, false, None)
            .await
            .unwrap(),
        b"one\r\n"
    );
}

#[tokio::test]
async fn the_other_provider_fills_in_a_missing_article() {
    let primary = serve(vec![(ready(), vec![("BODY <a@b>", reply("430 gone\r\n"))])]).await;
    let alternate = serve(vec![(
        ready(),
        vec![(
            "BODY <a@b>",
            reply("222 0 <a@b>\r\nfrom alternate\r\n.\r\n"),
        )],
    )])
    .await;
    let mut nntp = client(config(primary.port, Some(alternate.port)));
    nntp.do_connect(false, false).await.unwrap();
    assert_eq!(
        nntp.get_messages_by_message_id(&["a@b".to_string()], true, None)
            .await
            .unwrap(),
        b"from alternate\r\n"
    );
}

#[tokio::test]
async fn an_article_keeps_its_yenc_part_and_integrity_verdict() {
    let article = "=ybegin part=2 total=3 line=128 size=9 name=multi.bin\r\n=ypart begin=4 end=6\r\nklm\r\n=yend size=3 part=2 pcrc32=a3830348\r\n";
    let wire = format!("222 0 <a@b>\r\n{article}.\r\n");
    let server = serve(vec![(ready(), vec![("BODY <a@b>", reply(&wire))])]).await;
    let mut nntp = connected(&server).await;
    let (article, validated) = nntp.get_article_by_message_id("a@b", false).await.unwrap();
    assert_eq!(article.data, b"ABC");
    let part = article.part.unwrap();
    assert_eq!((part.begin, part.end, part.size), (Some(4), Some(6), 9));
    assert!(validated);
}

fn bounded_script(body_reply: Vec<u8>) -> Vec<(Vec<u8>, Vec<Step>)> {
    vec![(
        ready(),
        vec![
            ("GROUP alt.test", reply("211 1 1 1 alt.test\r\n")),
            ("BODY <a@b>", body_reply),
        ],
    )]
}

#[tokio::test]
async fn a_bounded_read_returns_a_whole_plain_article_and_the_next_response_is_untouched() {
    let script = vec![(
        ready(),
        vec![
            ("GROUP alt.test", reply("211 1 1 1 alt.test\r\n")),
            ("BODY <a@b>", reply("222 0 <a@b>\r\n..PAR2\r\n.\r\n")),
            ("BODY <c@d>", reply("222 0 <c@d>\r\nsecond\r\n.\r\n")),
        ],
    )];
    let server = serve(script).await;
    let mut nntp = connected(&server).await;
    let first = nntp
        .get_message_bounded("alt.test", "a@b", 100, false, None)
        .await
        .unwrap();
    assert_eq!(first.body.as_deref(), Some(&b".PAR2\r\n"[..]));
    let second = nntp
        .get_message_bounded("alt.test", "c@d", 100, false, None)
        .await
        .unwrap();
    assert_eq!(second.body.as_deref(), Some(&b"second\r\n"[..]));
}

#[tokio::test]
async fn a_multipart_article_may_belong_to_a_file_larger_than_the_ceiling() {
    let article = "=ybegin part=1 total=9 line=128 size=99999 name=x\r\n=ypart begin=1 end=3\r\nklm\r\n=yend size=3 part=1 pcrc32=a3830348\r\n";
    let wire = format!("222 0 <a@b>\r\n{article}.\r\n");
    let server = serve(bounded_script(reply(&wire))).await;
    let mut nntp = connected(&server).await;
    let result = nntp
        .get_message_bounded("alt.test", "a@b", 3, false, None)
        .await
        .unwrap();
    assert_eq!(result.body.as_deref(), Some(&b"ABC"[..]));
    assert_eq!(result.part.unwrap().size, 99_999);
}

#[tokio::test]
async fn a_corrupt_bounded_article_discards_everything() {
    let article = "=ybegin line=128 size=3 name=x\r\nklm\r\n=yend size=3 crc32=deadbeef\r\n";
    let wire = format!("222 0 <a@b>\r\n{article}.\r\n");
    let server = serve(bounded_script(reply(&wire))).await;
    let mut nntp = connected(&server).await;
    let result = nntp
        .get_message_bounded("alt.test", "a@b", 100, false, None)
        .await
        .unwrap();
    assert!(result.body.is_none());
    assert!(
        result
            .error
            .unwrap()
            .starts_with("The article could not be read: CRC32")
    );
    assert!(!nntp.is_connected().await);
}

#[tokio::test]
async fn the_wire_ceiling_stops_an_unterminated_flood() {
    let mut flood = reply("222 0 <a@b>\r\n");
    flood.extend_from_slice("x\r\n".repeat(30_000).as_bytes());
    let server = serve(bounded_script(flood)).await;
    let mut nntp = connected(&server).await;
    let result = nntp
        .get_message_bounded("alt.test", "a@b", 1, false, None)
        .await
        .unwrap();
    assert!(result.oversized);
    assert_eq!(
        result.error.as_deref(),
        Some("Article wire byte ceiling exceeded.")
    );
    assert!(!nntp.is_connected().await);
}

#[tokio::test]
async fn an_oversized_article_is_not_retried_on_the_alternate() {
    let primary = serve(bounded_script(reply("222 0 <a@b>\r\ntoo long\r\n.\r\n"))).await;
    let alternate = serve(vec![]).await;
    let mut nntp = client(config(primary.port, Some(alternate.port)));
    nntp.do_connect(false, false).await.unwrap();
    let result = nntp
        .get_message_bounded("alt.test", "a@b", 2, true, None)
        .await
        .unwrap();
    assert!(result.oversized);
    assert_eq!(alternate.connections(), 0);
}

#[tokio::test]
async fn a_missing_article_keeps_the_socket_and_an_unsafe_status_drops_it() {
    let script = vec![(
        ready(),
        vec![
            ("GROUP alt.test", reply("211 1 1 1 alt.test\r\n")),
            ("BODY <a@b>", reply("430 gone\r\n")),
            ("BODY <c@d>", reply("423 not good enough\r\n")),
        ],
    )];
    let server = serve(script).await;
    let mut nntp = connected(&server).await;
    let missing = nntp
        .get_message_bounded("alt.test", "a@b", 100, false, None)
        .await
        .unwrap();
    assert_eq!(
        missing.error.as_deref(),
        Some("The article provider did not return a BODY.")
    );
    assert!(nntp.is_connected().await);
    assert_eq!(nntp.current_group(), "alt.test");
    nntp.get_message_bounded("alt.test", "c@d", 100, false, None)
        .await
        .unwrap();
    assert!(!nntp.is_connected().await);

    let server = serve(bounded_script(reply("430 no line ending"))).await;
    let mut nntp = connected(&server).await;
    nntp.get_message_bounded("alt.test", "a@b", 100, false, None)
        .await
        .unwrap();
    assert!(!nntp.is_connected().await);
}

#[tokio::test]
async fn a_lost_lock_is_not_retried_on_the_alternate() {
    let primary = serve(bounded_script(reply("222 0 <a@b>\r\nbytes\r\n"))).await;
    let alternate = serve(vec![]).await;
    let mut nntp = client(config(primary.port, Some(alternate.port)));
    nntp.do_connect(false, false).await.unwrap();
    let mut heartbeat = || false;
    let result = nntp
        .get_message_bounded("alt.test", "a@b", 100, true, Some(&mut heartbeat))
        .await
        .unwrap();
    assert!(result.error.unwrap().contains("lock ownership"));
    assert_eq!(alternate.connections(), 0);
}

#[tokio::test]
async fn bounded_reads_refuse_bad_requests_before_touching_the_network() {
    let mut nntp = client(config(1, None));
    assert!(matches!(
        nntp.get_message_bounded("g", "a@b", 0, false, None).await,
        Err(NntpError::Invalid(_))
    ));
    let injected = nntp
        .get_message_bounded("g", "a@b>\r\nQUIT", 10, false, None)
        .await
        .unwrap();
    assert_eq!(
        injected.error.as_deref(),
        Some("Invalid article Message-ID.")
    );
}

#[tokio::test]
async fn verification_counts_only_unstuffed_bytes_against_the_budget() {
    let server = serve(vec![(
        ready(),
        vec![("BODY <a@b>", reply("222 0 <a@b>\r\n..ab\r\n.\r\n"))],
    )])
    .await;
    let mut nntp = connected(&server).await;
    let (body, metadata) = nntp.verification_article("<a@b>", 5).await.unwrap();
    assert_eq!(body, b".ab\r\n");
    assert_eq!(metadata.response_code, Some(222));
    assert_eq!(metadata.response_text.as_deref(), Some(&b"0 <a@b>"[..]));

    let server = serve(vec![(
        ready(),
        vec![("BODY <a@b>", reply("222 0 <a@b>\r\nabcdef\r\n.\r\n"))],
    )])
    .await;
    let mut nntp = connected(&server).await;
    assert!(matches!(
        nntp.verification_article("a@b", 5).await,
        Err(NntpError::BudgetExceeded(_))
    ));
    assert!(!nntp.is_connected().await);
}

#[tokio::test]
async fn verification_tells_missing_from_unavailable() {
    let server = serve(vec![(
        ready(),
        vec![
            ("BODY <a@b>", reply("430 gone\r\n")),
            ("BODY <c@d>", reply("423 odd\r\n")),
        ],
    )])
    .await;
    let mut nntp = connected(&server).await;
    assert!(matches!(
        nntp.verification_article("a@b", 5).await,
        Err(NntpError::ArticleMissing(_))
    ));
    assert!(nntp.is_connected().await);
    assert!(matches!(
        nntp.verification_article("c@d", 5).await,
        Err(NntpError::ArticleMissing(_))
    ));
    assert!(!nntp.is_connected().await);
}

#[tokio::test]
async fn a_full_article_splits_headers_and_body_in_one_request() {
    let server = serve(vec![(
        ready(),
        vec![(
            "ARTICLE <a@b>",
            reply("220 0 <a@b> follows\r\nSubject: x\r\n..dotted: y\r\n\r\n..body\r\n.\r\n"),
        )],
    )])
    .await;
    let mut nntp = connected(&server).await;
    let article = nntp
        .get_fingerprint_full_article("a@b", 100, 100, false)
        .await
        .unwrap();
    assert_eq!(
        article.headers,
        vec![b"Subject: x\r\n".to_vec(), b".dotted: y\r\n".to_vec()]
    );
    assert_eq!(article.body, b".body\r\n");
    assert_eq!(
        article.article_response.response_text.as_deref(),
        Some(&b"0 <a@b> follows"[..])
    );
}

#[tokio::test]
async fn an_article_without_a_body_has_headers_and_an_empty_body() {
    let server = serve(vec![(
        ready(),
        vec![("ARTICLE <a@b>", reply("220 0 <a@b>\r\nSubject: x\r\n.\r\n"))],
    )])
    .await;
    let mut nntp = connected(&server).await;
    let article = nntp
        .get_fingerprint_full_article("a@b", 100, 100, false)
        .await
        .unwrap();
    assert!(article.body.is_empty());
    assert_eq!(article.headers.len(), 1);
}

#[tokio::test]
async fn fingerprint_reads_overview_head_and_body() {
    let server = serve(vec![(
        ready(),
        vec![
            ("OVER <a@b>", reply("224 ok\r\n1\ts\r\n.\r\n")),
            ("HEAD <a@b>", reply("221 0 <a@b>\r\nSubject: x\r\n.\r\n")),
            ("BODY <a@b>", reply("222 0 <a@b>\r\nbody\r\n.\r\n")),
        ],
    )])
    .await;
    let mut nntp = connected(&server).await;
    let article = nntp
        .get_fingerprint_article("a@b", 100, 100, false, true)
        .await
        .unwrap();
    assert_eq!(article.overview_raw.as_deref(), Some(&b"1\ts"[..]));
    assert_eq!(article.headers, vec![b"Subject: x\r\n".to_vec()]);
    assert_eq!(article.body, b"body\r\n");
    let commands: Vec<_> = article.command_results.iter().map(|c| c.command).collect();
    assert_eq!(commands, ["OVER MSGID", "HEAD", "BODY"]);
    assert!(article.command_results.iter().all(|c| c.available));
}

#[tokio::test]
async fn the_server_profile_reads_the_overview_schema_only_when_advertised() {
    let server = serve(vec![(
        ready(),
        vec![
            (
                "CAPABILITIES",
                reply("101 caps\r\nVERSION 2\r\nIMPLEMENTATION test 1\r\nOVER\r\n.\r\n"),
            ),
            ("LIST OVERVIEW.FMT", reply("215 fmt\r\nSubject:\r\n.\r\n")),
        ],
    )])
    .await;
    let mut nntp = connected(&server).await;
    let profile = nntp.get_fingerprint_server_profile(false).await.unwrap();
    assert_eq!(profile.capabilities_response_code, Some(101));
    assert_eq!(
        profile.implementation.as_deref(),
        Some(&b"IMPLEMENTATION test 1"[..])
    );
    assert_eq!(profile.overview_format_raw, vec![b"Subject:".to_vec()]);
}

#[tokio::test]
async fn a_preview_charges_every_byte_across_both_providers() {
    let primary = serve(vec![(
        ready(),
        vec![("BODY <a@b>", reply("222 0 <a@b>\r\npartial\r\n"))],
    )])
    .await;
    let alternate = serve(vec![(
        ready(),
        vec![("BODY <a@b>", reply("222 0 <a@b>\r\nok\r\n.\r\n"))],
    )])
    .await;
    let mut nntp = client(config(primary.port, Some(alternate.port)));
    nntp.do_connect(false, false).await.unwrap();
    let preview = nntp
        .get_bounded_article_by_message_id("a@b", 100, true)
        .await;
    assert_eq!(preview.data.as_deref(), Some(&b"ok\r\n"[..]));
    assert_eq!(preview.bytes_downloaded, 9 + 4 + 3);
    assert!(preview.error.is_none());
}

fn no_heartbeat() -> impl FnMut() -> Result<(), &'static str> + Send {
    || Ok(())
}

#[tokio::test]
async fn a_pipeline_keeps_each_response_with_its_request() {
    let first = serve(vec![(
        ready(),
        vec![
            ("BODY <a@x>", reply("222 1 <a@x> body\r\n..one\r\n.\r\n")),
            ("", reply("430 gone\r\n222 2 <c@x> body\r\nthree\r\n.\r\n")),
        ],
    )])
    .await;
    let second = serve(vec![(
        ready(),
        vec![("BODY <d@x>", reply("222 3 <d@x> body\r\nfour\r\n.\r\n"))],
    )])
    .await;
    let (mut left, mut right) = (connected(&first).await, connected(&second).await);
    let mut heartbeat = no_heartbeat();
    let results = pipeline::verification_article_batch(
        vec![
            Batch {
                client: &mut left,
                ids: vec!["a@x".into(), "b@x".into(), "c@x".into()],
            },
            Batch {
                client: &mut right,
                ids: vec!["d@x".into()],
            },
        ],
        100,
        1000,
        Mode::Body,
        &mut heartbeat,
    )
    .await
    .unwrap();
    assert_eq!(results["a@x"].as_deref().unwrap(), b".one\r\n");
    assert!(matches!(results["b@x"], Err(NntpError::ArticleMissing(_))));
    assert_eq!(results["c@x"].as_deref().unwrap(), b"three\r\n");
    assert_eq!(results["d@x"].as_deref().unwrap(), b"four\r\n");
    assert!(left.is_connected().await);
}

#[tokio::test]
async fn a_mismatched_identity_fails_that_request_and_fences_the_rest() {
    let server = serve(vec![(
        ready(),
        vec![(
            "BODY <a@x>",
            reply("222 1 <other@x> body\r\nbytes\r\n.\r\n"),
        )],
    )])
    .await;
    let mut nntp = connected(&server).await;
    let mut heartbeat = no_heartbeat();
    let results = pipeline::verification_article_batch(
        vec![Batch {
            client: &mut nntp,
            ids: vec!["a@x".into(), "b@x".into()],
        }],
        100,
        1000,
        Mode::Body,
        &mut heartbeat,
    )
    .await
    .unwrap();
    assert_eq!(
        results["a@x"].as_ref().unwrap_err().to_string(),
        "Verification response identity differs from request"
    );
    assert!(matches!(results["b@x"], Err(NntpError::PipelineRetry(_))));
    assert!(!nntp.is_connected().await);
}

#[tokio::test]
async fn an_oversized_pipeline_article_is_classified_as_over_budget() {
    let server = serve(vec![(
        ready(),
        vec![("BODY <a@x>", reply("222 1 <a@x> body\r\nabcdefgh\r\n.\r\n"))],
    )])
    .await;
    let mut nntp = connected(&server).await;
    let mut heartbeat = no_heartbeat();
    let results = pipeline::verification_article_batch(
        vec![Batch {
            client: &mut nntp,
            ids: vec!["a@x".into()],
        }],
        4,
        8,
        Mode::Body,
        &mut heartbeat,
    )
    .await
    .unwrap();
    assert!(matches!(results["a@x"], Err(NntpError::BudgetExceeded(_))));
}

#[tokio::test]
async fn stat_mode_reports_presence_without_a_body() {
    let server = serve(vec![(
        ready(),
        vec![("STAT <a@x>", reply("223 1 <a@x>\r\n"))],
    )])
    .await;
    let mut nntp = connected(&server).await;
    let mut heartbeat = no_heartbeat();
    let results = pipeline::verification_article_batch(
        vec![Batch {
            client: &mut nntp,
            ids: vec!["a@x".into()],
        }],
        4,
        8,
        Mode::Stat,
        &mut heartbeat,
    )
    .await
    .unwrap();
    assert!(results["a@x"].as_ref().unwrap().is_empty());
}

#[tokio::test]
async fn a_dead_or_compressed_connection_only_retries_its_own_requests() {
    let mut idle = client(config(1, None));
    let mut heartbeat = no_heartbeat();
    let results = pipeline::verification_article_batch(
        vec![Batch {
            client: &mut idle,
            ids: vec!["a@x".into()],
        }],
        4,
        8,
        Mode::Body,
        &mut heartbeat,
    )
    .await
    .unwrap();
    assert!(matches!(results["a@x"], Err(NntpError::PipelineRetry(_))));
}

#[tokio::test]
async fn a_heartbeat_failure_stops_the_batch_and_closes_its_connections() {
    let server = serve(vec![(ready(), vec![("BODY <a@x>", Vec::new())])]).await;
    let mut settings = config(server.port, None);
    settings.primary.socket_timeout = Duration::from_secs(10);
    let mut nntp = client(settings);
    nntp.do_connect(false, false).await.unwrap();
    let mut calls = 0;
    let mut heartbeat = move || {
        calls += 1;
        if calls > 1 { Err("lock lost") } else { Ok(()) }
    };
    let result = pipeline::verification_article_batch(
        vec![Batch {
            client: &mut nntp,
            ids: vec!["a@x".into()],
        }],
        4,
        8,
        Mode::Body,
        &mut heartbeat,
    )
    .await;
    assert!(matches!(result, Err(BatchError::Heartbeat("lock lost"))));
    assert!(!nntp.is_connected().await);
}

#[tokio::test]
async fn pipeline_budgets_are_checked_before_anything_is_sent() {
    let mut nntp = client(config(1, None));
    let mut heartbeat = no_heartbeat();
    let too_many = pipeline::verification_article_batch(
        vec![Batch {
            client: &mut nntp,
            ids: vec!["a@x".into(), "b@x".into(), "c@x".into()],
        }],
        4,
        8,
        Mode::Body,
        &mut heartbeat,
    )
    .await;
    assert!(matches!(too_many, Err(BatchError::Invalid(_))));
}

#[tokio::test]
async fn the_pool_holds_a_provider_to_its_connection_limit_and_reuses_sessions() {
    let server = serve(vec![(ready(), vec![])]).await;
    let pool = NntpPool::new(config(server.port, None));
    let first = pool.acquire(false).await.unwrap();
    let waiting = tokio::time::timeout(Duration::from_millis(100), pool.acquire(false)).await;
    assert!(waiting.is_err());
    drop(first);
    let second = pool.acquire(false).await.unwrap();
    assert_eq!(second.current_server(), "127.0.0.1");
    assert_eq!(server.connections(), 1);
}

#[tokio::test]
async fn the_breaker_blocks_a_failed_provider_and_lets_one_probe_through() {
    use nntmux_nntp::breaker;
    let Some(database) = nntmux_testkit::TestDb::create().await.unwrap() else {
        return;
    };
    let pool = &database.pool;
    let open = breaker::begin(pool, "primary").await.unwrap();
    assert!(open.skipped_reason.is_none());
    breaker::unavailable(pool, "primary", 900).await.unwrap();
    assert_eq!(
        breaker::begin(pool, "primary")
            .await
            .unwrap()
            .skipped_reason
            .as_deref(),
        Some("recent_provider_failure")
    );
    breaker::unavailable(pool, "primary", 0).await.unwrap();
    tokio::time::sleep(Duration::from_millis(5)).await;
    let probe = breaker::begin(pool, "primary").await.unwrap();
    assert!(probe.skipped_reason.is_none());
    assert_eq!(
        breaker::begin(pool, "primary")
            .await
            .unwrap()
            .skipped_reason
            .as_deref(),
        Some("provider_probe_in_progress")
    );
    breaker::reachable(pool, "primary", &probe).await.unwrap();
    assert!(
        breaker::begin(pool, "primary")
            .await
            .unwrap()
            .skipped_reason
            .is_none()
    );
}

mod tls {
    use super::*;
    use rcgen::{CertificateParams, CertifiedKey, IsCa, KeyPair, generate_simple_self_signed};
    use rustls::pki_types::{CertificateDer, PrivateKeyDer};
    use tokio_rustls::TlsAcceptor;

    /// Serves one TLS greeting with the given chain, then waits for the client to hang up.
    async fn tls_server(chain: Vec<CertificateDer<'static>>, key: PrivateKeyDer<'static>) -> u16 {
        let provider = Arc::new(rustls::crypto::ring::default_provider());
        let config = rustls::ServerConfig::builder_with_provider(provider)
            .with_safe_default_protocol_versions()
            .unwrap()
            .with_no_client_auth()
            .with_single_cert(chain, key)
            .unwrap();
        let acceptor = TlsAcceptor::from(Arc::new(config));
        let listener = TcpListener::bind("127.0.0.1:0").await.unwrap();
        let port = listener.local_addr().unwrap().port();
        tokio::spawn(async move {
            while let Ok((stream, _)) = listener.accept().await {
                let acceptor = acceptor.clone();
                tokio::spawn(async move {
                    if let Ok(mut stream) = acceptor.accept(stream).await {
                        let _ = stream.write_all(b"200 ready\r\n").await;
                        let mut rest = Vec::new();
                        let _ = stream.read_to_end(&mut rest).await;
                    }
                });
            }
        });
        port
    }

    async fn self_signed_server(name: &str) -> u16 {
        let CertifiedKey { cert, signing_key } =
            generate_simple_self_signed(vec![name.to_string()]).unwrap();
        tls_server(
            vec![cert.der().clone()],
            PrivateKeyDer::try_from(signing_key.serialize_der()).unwrap(),
        )
        .await
    }

    async fn connects(port: u16, tls: TlsOptions) -> bool {
        let mut settings = config(port, None);
        settings.primary.host = "localhost".into();
        settings.primary.tls = true;
        settings.tls = tls;
        client(settings).do_connect(false, false).await.is_ok()
    }

    #[tokio::test]
    async fn untrusted_certificates_are_refused_by_default() {
        let port = self_signed_server("localhost").await;
        assert!(!connects(port, TlsOptions::default()).await);
    }

    #[tokio::test]
    async fn turning_off_the_name_check_keeps_the_chain_check() {
        let port = self_signed_server("localhost").await;
        let options = TlsOptions {
            verify_host: false,
            ..TlsOptions::default()
        };
        assert!(!connects(port, options).await);
    }

    #[tokio::test]
    async fn allowing_self_signed_still_checks_the_name() {
        let options = TlsOptions {
            allow_self_signed: true,
            ..TlsOptions::default()
        };
        assert!(connects(self_signed_server("localhost").await, options.clone()).await);
        assert!(!connects(self_signed_server("other.example").await, options.clone()).await);
        let any_name = TlsOptions {
            verify_host: false,
            ..options
        };
        assert!(connects(self_signed_server("other.example").await, any_name).await);
    }

    #[tokio::test]
    async fn turning_off_the_peer_check_alone_still_checks_the_name() {
        let options = TlsOptions {
            verify_peer: false,
            ..TlsOptions::default()
        };
        assert!(connects(self_signed_server("localhost").await, options.clone()).await);
        assert!(!connects(self_signed_server("other.example").await, options).await);
    }

    #[tokio::test]
    async fn a_private_ca_is_trusted_through_ca_file_and_its_leaf_is_not_self_signed() {
        let ca_key = KeyPair::generate().unwrap();
        let mut ca_params = CertificateParams::new(Vec::new()).unwrap();
        ca_params.is_ca = IsCa::Ca(rcgen::BasicConstraints::Unconstrained);
        ca_params
            .distinguished_name
            .push(rcgen::DnType::CommonName, "test ca");
        let ca = ca_params.self_signed(&ca_key).unwrap();
        let issuer = rcgen::Issuer::from_params(&ca_params, &ca_key);
        let leaf_key = KeyPair::generate().unwrap();
        let mut leaf_params = CertificateParams::new(vec!["other.example".to_string()]).unwrap();
        leaf_params
            .distinguished_name
            .push(rcgen::DnType::CommonName, "leaf");
        let leaf = leaf_params.signed_by(&leaf_key, &issuer).unwrap();
        let port = tls_server(
            vec![leaf.der().clone()],
            PrivateKeyDer::try_from(leaf_key.serialize_der()).unwrap(),
        )
        .await;
        let ca_file = std::env::temp_dir().join(format!("nntmux-ca-{port}.pem"));
        std::fs::write(&ca_file, ca.pem()).unwrap();
        let trusted = TlsOptions {
            ca_file: Some(ca_file.clone()),
            ..TlsOptions::default()
        };
        // The chain is good, so only the name stands in the way.
        assert!(!connects(port, trusted.clone()).await);
        let any_name = TlsOptions {
            verify_host: false,
            ..trusted
        };
        assert!(connects(port, any_name).await);
        // A leaf from an unknown CA isn't self-signed, whatever SSL_ALLOW_SELF_SIGNED says.
        let unknown_issuer = TlsOptions {
            allow_self_signed: true,
            verify_host: false,
            ..TlsOptions::default()
        };
        assert!(!connects(port, unknown_issuer).await);
        let _ = std::fs::remove_file(ca_file);
    }
}

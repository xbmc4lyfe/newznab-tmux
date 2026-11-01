//! Announce parsing against `fixtures/generate_irc_golden.php`, and the IRC client against a
//! scripted local server.

use chrono::NaiveDateTime;
use nntmux_predb::feeds::Entry;
use nntmux_predb::irc::client::{IrcConnection, Read};
use nntmux_predb::irc::networks::parse_channels;
use nntmux_predb::irc::parser::{
    AnnounceParser, parse_bot_line, parse_privmsg, strip_control_characters,
};
use nntmux_predb::irc::{restart_delay, stream_entries};
use serde_json::{Value, json};
use tokio::io::{AsyncBufReadExt, AsyncWriteExt, BufReader};
use tokio::net::TcpListener;

fn dump(e: &Entry) -> Value {
    json!({
        "title": e.title, "source": e.source, "category": e.category, "size": e.size, "files": e.files,
        "nuked": e.nuked, "reason": e.nuke_reason, "enrich": e.enrich_only,
        "predate": e.predate.map(|d| d.format("%Y-%m-%d %H:%M:%S").to_string()),
        "listed": e.listed_at.map(|d| d.format("%Y-%m-%d %H:%M:%S").to_string()),
    })
}

fn golden() -> Value {
    serde_json::from_str(include_str!("fixtures/irc_golden.json")).expect("golden")
}

#[test]
fn announces_match_the_php() {
    let now =
        NaiveDateTime::parse_from_str("2026-08-01 10:00:00", "%Y-%m-%d %H:%M:%S").expect("time");
    for case in golden()["announce"].as_array().expect("announce") {
        let parser =
            AnnounceParser::new(case["format"].as_str().expect("format"), "src").expect("parser");
        let message = case["message"].as_str().expect("message");
        let actual = parser.parse(message, now).map_or(Value::Null, |e| dump(&e));
        assert_eq!(actual, case["out"], "{message:?}");
    }
    assert!(AnnounceParser::new("bogus", "src").is_err());
}

#[test]
fn stream_messages_match_the_php() {
    for case in golden()["streams"].as_array().expect("streams") {
        let format = case["format"].as_str().expect("format");
        let message = case["message"].as_str().expect("message");
        let actual: Vec<Value> = stream_entries(format, message).iter().map(dump).collect();
        assert_eq!(json!(actual), case["out"], "{message}");
    }
}

#[test]
fn restart_delays_match_the_php() {
    let delays: Vec<u64> = [1, 2, 3, 4, 7, 8, 30]
        .iter()
        .map(|f| restart_delay(*f))
        .collect();
    assert_eq!(json!(delays), golden()["delays"]);
}

#[test]
fn bot_lines_and_channels() {
    let line = parse_bot_line(
        "NEW: [DT: 2026-08-01 10:00:00] [TT: Some.Rel-GRP] [SC: #a.b.teevee] [CT: TV] [RQ: 123:a.b.teevee] [SZ: 700MB] [FL: 50] [FN: file.mkv] [NUKED: bad]",
    )
    .expect("line");
    assert_eq!(line.title, "Some.Rel-GRP");
    assert_eq!(line.req, "123:a.b.teevee");
    assert_eq!(line.filename.as_deref(), Some("file.mkv"));
    assert_eq!(line.nuked.as_deref(), Some("NUKED"));
    assert_eq!(line.reason.as_deref(), Some("bad"));
    assert!(parse_bot_line("chatter").is_none());

    let stripped =
        strip_control_characters("\u{3}04,05:n!u@h\u{2} PRIVMSG #pre\u{f} :  hello there \u{3}");
    assert_eq!(
        parse_privmsg(&stripped),
        Some(("n".to_owned(), "#pre".to_owned(), "hello there".to_owned()))
    );

    assert_eq!(
        parse_channels("#a, b:secret ,,#, c: "),
        vec![
            ("#a".to_owned(), None),
            ("#b".to_owned(), Some("secret".to_owned())),
            ("#c".to_owned(), None)
        ]
    );
    assert_eq!(parse_channels(""), vec![("#PreNNTmux".to_owned(), None)]);
}

#[tokio::test]
async fn client_logs_in_answering_pings_and_drops_oversized_frames() {
    let listener = TcpListener::bind("127.0.0.1:0").await.expect("bind");
    let port = listener.local_addr().expect("addr").port();
    let server = tokio::spawn(async move {
        let (stream, _) = listener.accept().await.expect("accept");
        let (reader, mut writer) = stream.into_split();
        let mut lines = BufReader::new(reader).lines();
        let mut seen = Vec::new();
        for _ in 0..3 {
            seen.push(lines.next_line().await.expect("read").expect("line"));
        }
        writer.write_all(b"PING :abc\r\n").await.expect("write");
        seen.push(lines.next_line().await.expect("read").expect("line"));
        writer
            .write_all(b":server.example 001 nick :Welcome\r\n")
            .await
            .expect("write");
        seen.push(lines.next_line().await.expect("read").expect("line"));
        let oversized = format!(":x PRIVMSG #pre :{}\r\n", "y".repeat(9000));
        writer.write_all(oversized.as_bytes()).await.expect("write");
        writer
            .write_all(b":a!b@c PRIVMSG #pre :fine\r\n")
            .await
            .expect("write");
        drop(writer);
        seen
    });
    let mut connection = IrcConnection::connect("127.0.0.1", port, false, true)
        .await
        .expect("connect");
    let origin = connection
        .login("nick", "user", "real", Some("pw"))
        .await
        .expect("login");
    assert_eq!(origin, "server.example");
    connection
        .join(&[("#pre".to_owned(), None)])
        .await
        .expect("join");
    let mut lines = Vec::new();
    loop {
        match connection.read().await.expect("read") {
            Read::Line(line) => lines.push(line),
            Read::Idle => {}
            Read::Closed => break,
        }
    }
    assert_eq!(lines, vec![":a!b@c PRIVMSG #pre :fine".to_owned()]);
    let seen = server.await.expect("server");
    assert_eq!(
        seen,
        vec![
            "PASS pw",
            "NICK nick",
            "USER user 0 * :real",
            "PONG abc",
            "JOIN #pre"
        ]
    );
}

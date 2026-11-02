//! A scripted NNTP server and download services that use it.

use std::sync::{Arc, Mutex};
use std::time::Duration;

use nntmux_nntp::{NntpClient, NntpConfig, Provider, TlsOptions};
use nntmux_postprocess::additional::download::{DownloadService, MappingPolicy};
use nntmux_postprocess::additional::planner::NzbFile;
use tokio::io::{AsyncBufReadExt, AsyncWriteExt, BufReader};
use tokio::net::TcpListener;

pub struct Server {
    pub port: u16,
    requests: Arc<Mutex<Vec<String>>>,
}

impl Server {
    pub fn bodies(&self) -> usize {
        self.requests
            .lock()
            .unwrap()
            .iter()
            .filter(|line| line.starts_with("BODY"))
            .count()
    }
}

/// Answers BODY from a table of Message-ID to yEnc article; anything else is missing.
pub async fn serve(articles: Vec<(&'static str, Vec<u8>)>) -> Server {
    let listener = TcpListener::bind("127.0.0.1:0").await.unwrap();
    let port = listener.local_addr().unwrap().port();
    let requests = Arc::new(Mutex::new(Vec::new()));
    let log = Arc::clone(&requests);
    tokio::spawn(async move {
        loop {
            let Ok((stream, _)) = listener.accept().await else {
                return;
            };
            let (log, articles) = (Arc::clone(&log), articles.clone());
            tokio::spawn(async move {
                let mut io = BufReader::new(stream);
                io.write_all(b"200 ready\r\n").await.unwrap();
                loop {
                    let mut line = String::new();
                    if io.read_line(&mut line).await.unwrap_or(0) == 0 {
                        return;
                    }
                    let line = line.trim_end().to_owned();
                    log.lock().unwrap().push(line.clone());
                    if let Some(id) = line.strip_prefix("BODY <") {
                        let id = id.trim_end_matches('>');
                        match articles.iter().find(|(known, _)| *known == id) {
                            Some((_, body)) => {
                                io.write_all(b"222 0 <x> body\r\n").await.unwrap();
                                for chunk in body.split_inclusive(|b| *b == b'\n') {
                                    if chunk.starts_with(b".") {
                                        io.write_all(b".").await.unwrap();
                                    }
                                    io.write_all(chunk).await.unwrap();
                                }
                                io.write_all(b"\r\n.\r\n").await.unwrap();
                            }
                            None => io.write_all(b"430 no such article\r\n").await.unwrap(),
                        }
                    } else if let Some(group) = line.strip_prefix("GROUP ") {
                        let reply = format!("211 1 1 1 {group}\r\n");
                        io.write_all(reply.as_bytes()).await.unwrap();
                    } else {
                        io.write_all(b"500 what\r\n").await.unwrap();
                    }
                }
            });
        }
    });
    Server { port, requests }
}

pub fn service(port: u16) -> DownloadService {
    DownloadService::new(client(port), false, MappingPolicy::default())
}

/// A client for the scripted server.
pub fn client(port: u16) -> NntpClient {
    let config = NntpConfig {
        primary: Provider {
            host: "127.0.0.1".into(),
            port,
            tls: false,
            username: String::new(),
            password: String::new(),
            socket_timeout: Duration::from_secs(2),
        },
        alternate: None,
        tls: TlsOptions::default(),
        primary_connections: 1,
        alternate_connections: 1,
        compressed_headers: false,
        use_alternate: false,
        alternate_article_fallback: false,
        fingerprint_breaker_seconds: 900,
        retries: 1,
    };
    NntpClient::new(Arc::new(config))
}

pub fn article(name: &str, payload: &[u8]) -> Vec<u8> {
    nntmux_yenc::encode(payload, name.as_bytes(), 128, true).expect("encodes")
}

pub const SEGMENT: usize = 20_000;

/// One article of a multi-part yEnc post.
pub fn part(name: &str, file: &[u8], number: usize) -> Vec<u8> {
    let begin = (number - 1) * SEGMENT;
    let data = &file[begin..file.len().min(begin + SEGMENT)];
    let single = nntmux_yenc::encode(data, name.as_bytes(), 128, false).unwrap();
    let body_start = single.windows(2).position(|w| w == b"\r\n").unwrap() + 2;
    let body_end = single.windows(7).rposition(|w| w == b"\r\n=yend").unwrap();
    let mut article = format!(
        "=ybegin part={number} line=128 size={} name={name}\r\n=ypart begin={} end={}\r\n",
        file.len(),
        begin + 1,
        begin + data.len()
    )
    .into_bytes();
    article.extend_from_slice(&single[body_start..body_end]);
    article.extend_from_slice(
        format!(
            "\r\n=yend size={} part={number} pcrc32={}",
            data.len(),
            nntmux_yenc::crc32(data)
        )
        .as_bytes(),
    );
    article
}

/// A posted file's NZB entry and its articles, as `prefix-N@x`.
pub fn post(
    prefix: &'static str,
    name: &str,
    file: &[u8],
) -> (NzbFile, Vec<(&'static str, Vec<u8>)>) {
    let count = file.len().div_ceil(SEGMENT);
    let ids: Vec<&'static str> = (1..=count)
        .map(|number| &*Box::leak(format!("{prefix}-{number}@x").into_boxed_str()))
        .collect();
    let articles = ids
        .iter()
        .enumerate()
        .map(|(index, id)| (*id, part(name, file, index + 1)))
        .collect();
    let nzb = NzbFile {
        title: format!("\"{name}\" yEnc (1/{count})"),
        segments: ids.iter().map(|id| (*id).to_owned()).collect(),
        size: (file.len() as f64 * 1.05) as i64,
        filecount: 1,
        firstsegment: true,
    };
    (nzb, articles)
}

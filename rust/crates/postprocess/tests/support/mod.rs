//! A scripted NNTP server and download services that use it.

use std::sync::{Arc, Mutex};
use std::time::Duration;

use nntmux_nntp::{NntpClient, NntpConfig, Provider, TlsOptions};
use nntmux_postprocess::additional::download::{DownloadService, MappingPolicy};
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
    DownloadService::new(
        NntpClient::new(Arc::new(config)),
        false,
        MappingPolicy::default(),
    )
}

pub fn article(name: &str, payload: &[u8]) -> Vec<u8> {
    nntmux_yenc::encode(payload, name.as_bytes(), 128, true).expect("encodes")
}

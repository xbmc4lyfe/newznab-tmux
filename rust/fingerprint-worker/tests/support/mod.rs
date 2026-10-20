//! Scripted NNTP server with injectable latency, shared by the integration tests and the
//! connection-reuse benchmark. It speaks just enough NNTP (greeting, AUTHINFO, CAPABILITIES,
//! LIST OVERVIEW.FMT, DATE, ARTICLE, QUIT) for the worker and counts what it observes.
#![allow(dead_code)]

use std::{
    sync::{
        Arc,
        atomic::{AtomicI64, AtomicU64, Ordering},
    },
    time::Duration,
};
use tokio::{
    io::{AsyncBufReadExt, AsyncWriteExt, BufReader},
    net::{TcpListener, TcpStream},
    sync::watch,
};

#[derive(Clone, Debug)]
pub struct Script {
    /// Added before every command response (one network round trip).
    pub rtt: Duration,
    /// Added before the greeting: models TCP connect, the TLS handshake and provider admission.
    pub handshake: Duration,
    /// Added to the AUTHINFO PASS response: models the provider's account lookup.
    pub auth_extra: Duration,
    /// Added to every ARTICLE response: models transfer time of the article body.
    pub article_extra: Duration,
    /// Decoded yEnc payload bytes per article.
    pub payload_len: usize,
    /// Greet with `502` and close once this many connections are open (provider account cap).
    pub max_connections: Option<i64>,
    /// Drop the connection halfway through every Nth article response (0 disables).
    pub drop_every: u64,
    /// Close a connection that sends nothing for this long, like a provider idle timeout.
    pub idle_close: Option<Duration>,
    /// Every injected delay is scaled by a uniform factor in `1 ± jitter`, so concurrent
    /// workers desynchronise like real network paths instead of moving in lock step.
    pub jitter: f64,
}

impl Default for Script {
    fn default() -> Self {
        Self {
            rtt: Duration::ZERO,
            handshake: Duration::ZERO,
            auth_extra: Duration::ZERO,
            article_extra: Duration::ZERO,
            payload_len: 2048,
            max_connections: None,
            drop_every: 0,
            idle_close: None,
            jitter: 0.0,
        }
    }
}

#[derive(Default)]
pub struct Stats {
    pub accepted: AtomicU64,
    pub refused: AtomicU64,
    pub open: AtomicI64,
    pub max_open: AtomicI64,
    pub articles: AtomicU64,
    pub capabilities: AtomicU64,
    pub auth_users: AtomicU64,
    pub dates: AtomicU64,
    pub dropped: AtomicU64,
}

impl Stats {
    pub fn accepted(&self) -> u64 {
        self.accepted.load(Ordering::SeqCst)
    }
    pub fn max_open(&self) -> i64 {
        self.max_open.load(Ordering::SeqCst)
    }
    pub fn open(&self) -> i64 {
        self.open.load(Ordering::SeqCst)
    }
    pub fn articles(&self) -> u64 {
        self.articles.load(Ordering::SeqCst)
    }
    pub fn capabilities(&self) -> u64 {
        self.capabilities.load(Ordering::SeqCst)
    }
    pub fn dates(&self) -> u64 {
        self.dates.load(Ordering::SeqCst)
    }
}

pub struct ScriptedNntp {
    pub port: u16,
    pub stats: Arc<Stats>,
    kill: watch::Sender<u64>,
    accept: tokio::task::JoinHandle<()>,
}

impl ScriptedNntp {
    pub async fn start(script: Script) -> Self {
        let listener = TcpListener::bind("127.0.0.1:0").await.unwrap();
        let port = listener.local_addr().unwrap().port();
        let stats = Arc::new(Stats::default());
        let (kill, kill_rx) = watch::channel(0u64);
        let accept = tokio::spawn({
            let stats = stats.clone();
            async move {
                loop {
                    let Ok((stream, _)) = listener.accept().await else {
                        return;
                    };
                    let _ = stream.set_nodelay(true);
                    tokio::spawn(connection(
                        stream,
                        script.clone(),
                        stats.clone(),
                        kill_rx.clone(),
                    ));
                }
            }
        });
        Self {
            port,
            stats,
            kill,
            accept,
        }
    }
    /// Close every connection that is currently open (a restart or silent idle disconnect).
    pub fn kill_connections(&self) {
        self.kill.send_modify(|generation| *generation += 1);
    }
}

impl Drop for ScriptedNntp {
    fn drop(&mut self) {
        self.accept.abort();
    }
}

async fn delay(base: Duration, jitter: f64) {
    if base.is_zero() {
        return;
    }
    let factor = if jitter > 0.0 {
        1.0 + jitter * (rand::random::<f64>() * 2.0 - 1.0)
    } else {
        1.0
    };
    tokio::time::sleep(base.mul_f64(factor)).await;
}

fn article(id: &str, payload_len: usize) -> Vec<u8> {
    let payload: Vec<u8> = (0..payload_len).map(|i| b'a' + (i % 26) as u8).collect();
    let mut out = format!(
        "220 0 <{id}> follows\r\nMessage-ID: <{id}>\r\n\r\n=ybegin line=128 size={payload_len} name=x.rar\r\n"
    )
    .into_bytes();
    for chunk in payload.chunks(128) {
        out.extend(chunk.iter().map(|b| b.wrapping_add(42)));
        out.extend(b"\r\n");
    }
    out.extend(
        format!(
            "=yend size={payload_len} crc32={:08x}\r\n.\r\n",
            crc32fast::hash(&payload)
        )
        .as_bytes(),
    );
    out
}

async fn connection(
    stream: TcpStream,
    script: Script,
    stats: Arc<Stats>,
    mut kill: watch::Receiver<u64>,
) {
    kill.borrow_and_update();
    let open = stats.open.fetch_add(1, Ordering::SeqCst) + 1;
    stats.accepted.fetch_add(1, Ordering::SeqCst);
    stats.max_open.fetch_max(open, Ordering::SeqCst);
    let refused = script.max_connections.is_some_and(|cap| open > cap);
    let outcome = serve(stream, &script, &stats, &mut kill, refused).await;
    let _ = outcome;
    stats.open.fetch_sub(1, Ordering::SeqCst);
}

async fn serve(
    stream: TcpStream,
    script: &Script,
    stats: &Stats,
    kill: &mut watch::Receiver<u64>,
    refused: bool,
) -> std::io::Result<()> {
    let mut io = BufReader::new(stream);
    delay(script.handshake, script.jitter).await;
    if refused {
        stats.refused.fetch_add(1, Ordering::SeqCst);
        io.write_all(b"502 too many connections\r\n").await?;
        return Ok(());
    }
    io.write_all(b"200 scripted ready\r\n").await?;
    loop {
        let mut command = String::new();
        let read = io.read_line(&mut command);
        let idle = async {
            match script.idle_close {
                Some(limit) => tokio::time::sleep(limit).await,
                None => std::future::pending().await,
            }
        };
        let n = tokio::select! {
            n = read => n?,
            _ = idle => {
                io.write_all(b"400 idle timeout\r\n").await?;
                return Ok(());
            }
            _ = kill.changed() => return Ok(()),
        };
        if n == 0 {
            return Ok(());
        }
        delay(script.rtt, script.jitter).await;
        let command = command.trim_end();
        if command.starts_with("AUTHINFO USER") {
            stats.auth_users.fetch_add(1, Ordering::SeqCst);
            io.write_all(b"381 password required\r\n").await?;
        } else if command.starts_with("AUTHINFO PASS") {
            delay(script.auth_extra, script.jitter).await;
            io.write_all(b"281 accepted\r\n").await?;
        } else if command == "CAPABILITIES" {
            stats.capabilities.fetch_add(1, Ordering::SeqCst);
            io.write_all(b"101 capabilities\r\nIMPLEMENTATION Scripted 1.0\r\nOVER\r\n.\r\n")
                .await?;
        } else if command == "LIST OVERVIEW.FMT" {
            io.write_all(b"215 order\r\nSubject:\r\nFrom:\r\n.\r\n")
                .await?;
        } else if command == "DATE" {
            stats.dates.fetch_add(1, Ordering::SeqCst);
            io.write_all(b"111 20260101000000\r\n").await?;
        } else if command == "QUIT" {
            io.write_all(b"205 bye\r\n").await?;
            return Ok(());
        } else if let Some(id) = command
            .strip_prefix("ARTICLE <")
            .and_then(|rest| rest.strip_suffix('>'))
        {
            let number = stats.articles.fetch_add(1, Ordering::SeqCst) + 1;
            delay(script.article_extra, script.jitter).await;
            let response = article(id, script.payload_len);
            if script.drop_every > 0 && number.is_multiple_of(script.drop_every) {
                stats.dropped.fetch_add(1, Ordering::SeqCst);
                io.write_all(&response[..response.len() / 2]).await?;
                return Ok(());
            }
            io.write_all(&response).await?;
        } else {
            io.write_all(b"500 unknown command\r\n").await?;
        }
    }
}

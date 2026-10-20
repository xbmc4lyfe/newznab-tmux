use anyhow::{Context, Result, bail};
use serde::{Deserialize, Serialize};
use std::{
    sync::{Arc, OnceLock},
    time::Duration,
};
use tokio::{
    io::{AsyncBufReadExt, AsyncRead, AsyncWrite, AsyncWriteExt, BufReader},
    net::TcpStream,
    time::timeout,
};
use tokio_rustls::{
    TlsConnector,
    rustls::{ClientConfig, RootCertStore, pki_types::ServerName},
};

pub const MAX_BODY: usize = 2_000_000;
const MAX_HEADERS: usize = 65_536;
/// One shared TLS configuration: building the root store per connection costs CPU, and a shared
/// configuration lets rustls resume sessions to a provider it already knows.
fn tls_config() -> Arc<ClientConfig> {
    static CONFIG: OnceLock<Arc<ClientConfig>> = OnceLock::new();
    CONFIG
        .get_or_init(|| {
            let roots = RootCertStore {
                roots: webpki_roots::TLS_SERVER_ROOTS.to_vec(),
            };
            Arc::new(
                ClientConfig::builder()
                    .with_root_certificates(roots)
                    .with_no_client_auth(),
            )
        })
        .clone()
}
pub trait Io: AsyncRead + AsyncWrite + Unpin + Send {}
impl<T: AsyncRead + AsyncWrite + Unpin + Send> Io for T {}
#[derive(Clone, Debug)]
pub struct Provider {
    pub account: String,
    pub host: String,
    pub port: u16,
    pub tls: bool,
    pub username: String,
    pub password: String,
}
#[derive(Clone, Debug, Serialize, Deserialize)]
pub struct Article {
    pub headers: Vec<String>,
    pub body: Vec<u8>,
    pub response: String,
    pub ms: u64,
}
pub struct Client {
    io: BufReader<Box<dyn Io>>,
    timeout: Duration,
    pub implementation: Option<String>,
    poisoned: bool,
    profiled: bool,
}
impl Client {
    pub async fn connect(p: &Provider, seconds: u64) -> Result<Self> {
        let duration = Duration::from_secs(seconds);
        let stream = timeout(duration, TcpStream::connect((p.host.as_str(), p.port)))
            .await
            .context("NNTP connect timeout")??;
        stream.set_nodelay(true)?;
        let io: Box<dyn Io> = if p.tls {
            let name = ServerName::try_from(p.host.clone()).context("NNTP TLS hostname")?;
            Box::new(
                timeout(
                    duration,
                    TlsConnector::from(tls_config()).connect(name, stream),
                )
                .await
                .context("NNTP TLS timeout")??,
            )
        } else {
            Box::new(stream)
        };
        let mut this = Self {
            io: BufReader::new(io),
            timeout: duration,
            implementation: None,
            poisoned: false,
            profiled: false,
        };
        let greeting = this.line(4096).await?;
        if !greeting.starts_with(b"200 ") && !greeting.starts_with(b"201 ") {
            bail!("NntpProviderUnavailableException");
        }
        if !p.username.is_empty() {
            let response = this
                .command(&format!("AUTHINFO USER {}", p.username))
                .await?;
            if response.starts_with("381 ") {
                let response = this
                    .command(&format!("AUTHINFO PASS {}", p.password))
                    .await?;
                if !response.starts_with("281 ") {
                    bail!("NntpProviderUnavailableException");
                }
            } else if !response.starts_with("281 ") {
                bail!("NntpProviderUnavailableException");
            }
        }
        Ok(this)
    }
    async fn line(&mut self, max: usize) -> Result<Vec<u8>> {
        let mut out = Vec::new();
        loop {
            let available = timeout(self.timeout, self.io.fill_buf())
                .await
                .context("NNTP read timeout")??;
            if available.is_empty() {
                bail!("NNTP truncated response");
            }
            let n = available
                .iter()
                .position(|b| *b == b'\n')
                .map_or(available.len(), |p| p + 1);
            if out.len() + n > max {
                bail!("NNTP line exceeds budget");
            }
            out.extend_from_slice(&available[..n]);
            self.io.consume(n);
            if out.ends_with(b"\n") {
                break;
            }
        }
        if !out.ends_with(b"\r\n") {
            bail!("NNTP malformed line ending");
        }
        Ok(out)
    }
    async fn command(&mut self, cmd: &str) -> Result<String> {
        if cmd.bytes().any(|b| b == b'\r' || b == b'\n') {
            bail!("Invalid NNTP command");
        }
        timeout(
            self.timeout,
            self.io.write_all(format!("{cmd}\r\n").as_bytes()),
        )
        .await
        .context("NNTP write timeout")??;
        Ok(String::from_utf8_lossy(&self.line(4096).await?)
            .trim_end()
            .to_string())
    }
    async fn multiline(&mut self, max: usize) -> Result<Vec<Vec<u8>>> {
        let mut out = vec![];
        let mut n = 0;
        loop {
            let mut line = self.line(max.saturating_sub(n).max(3)).await?;
            if line == b".\r\n" {
                break;
            }
            if line.starts_with(b"..") {
                line.remove(0);
            }
            n += line.len();
            if n > max {
                bail!("NNTP response exceeds budget");
            }
            out.push(line);
        }
        Ok(out)
    }
    pub fn poisoned(&self) -> bool {
        self.poisoned
    }
    /// True once CAPABILITIES/OVERVIEW.FMT succeeded on this exact connection.
    pub fn profiled(&self) -> bool {
        self.profiled
    }
    pub async fn profile(&mut self) -> Result<()> {
        let result = self.profile_inner().await;
        if result.is_err() {
            self.poisoned = true;
        } else {
            self.profiled = true;
        }
        result
    }
    /// Decide whether a connection that sat idle may carry the next release.
    ///
    /// A healthy idle NNTP connection is silent: any readable byte, EOF or error means the
    /// server closed it or desynchronised the stream, so it is poisoned instead of reused.
    /// `ping` additionally spends one round trip on `DATE` for connections idle long enough
    /// that a silent middlebox drop is plausible.
    pub async fn ready_for_reuse(&mut self, ping: bool) -> bool {
        if self.poisoned || !self.profiled {
            return false;
        }
        if !self.io.buffer().is_empty() || timeout(Duration::ZERO, self.io.fill_buf()).await.is_ok()
        {
            self.poisoned = true;
            return false;
        }
        if ping {
            match self.command("DATE").await {
                // A server without DATE answers 500; either proves the stream is in step.
                Ok(response)
                    if response.starts_with("111 ")
                        || response.starts_with("500 ")
                        || response.starts_with("501 ") => {}
                _ => {
                    self.poisoned = true;
                    return false;
                }
            }
        }
        true
    }
    async fn profile_inner(&mut self) -> Result<()> {
        let response = self.command("CAPABILITIES").await?;
        if response.starts_with("101 ") {
            let lines = self.multiline(65536).await?;
            let mut over = false;
            for line in lines {
                let s = String::from_utf8_lossy(&line);
                if s.to_uppercase().starts_with("IMPLEMENTATION ") {
                    self.implementation = Some(crate::manifest::bounded(s.trim()));
                }
                if s.to_uppercase().starts_with("OVER") {
                    over = true;
                }
            }
            if over {
                let response = self.command("LIST OVERVIEW.FMT").await?;
                if response.starts_with("215 ") {
                    self.multiline(32768).await?;
                } else if !response.starts_with("500 ") && !response.starts_with("501 ") {
                    bail!("ProfileResponseInvalid");
                }
            }
        } else if !response.starts_with("500 ") && !response.starts_with("501 ") {
            bail!("ProfileResponseInvalid");
        }
        Ok(())
    }
    pub async fn article(&mut self, id: &str) -> Result<Article> {
        if self.poisoned {
            bail!("NNTP poisoned connection");
        }
        let result = self.article_inner(id).await;
        if result
            .as_ref()
            .is_err_and(|error| !error.to_string().contains("NntpArticleMissingException"))
        {
            self.poisoned = true;
        }
        result
    }
    async fn article_inner(&mut self, id: &str) -> Result<Article> {
        if id.is_empty()
            || id.len() > 998
            || id
                .bytes()
                .any(|b| b <= 32 || b >= 127 || b == b'<' || b == b'>')
        {
            bail!("Invalid article identifier");
        }
        let start = std::time::Instant::now();
        let response = self.command(&format!("ARTICLE <{id}>")).await?;
        if response.starts_with("423 ") || response.starts_with("430 ") {
            bail!("NntpArticleMissingException");
        }
        if !response.starts_with("220 ") {
            bail!("RuntimeException");
        }
        let mut headers = vec![];
        let mut bytes = 0;
        loop {
            let mut line = self.line(MAX_HEADERS.saturating_sub(bytes).max(3)).await?;
            if line == b".\r\n" {
                bail!("NNTP truncated headers");
            }
            if line == b"\r\n" {
                break;
            }
            if line.starts_with(b"..") {
                line.remove(0);
            }
            bytes += line.len();
            if bytes > MAX_HEADERS {
                bail!("NNTP headers exceed budget");
            }
            headers.push(String::from_utf8_lossy(&line).into_owned());
        }
        let lines = self.multiline(MAX_BODY).await?;
        let body = lines.concat();
        Ok(Article {
            headers,
            body,
            response,
            ms: start.elapsed().as_millis().min(u64::MAX as u128) as u64,
        })
    }
}
#[cfg(test)]
mod tests {
    use super::*;
    use tokio::{io::AsyncWriteExt, net::TcpListener};
    #[tokio::test]
    async fn article_unstuffing_and_identity() {
        let listener = TcpListener::bind("127.0.0.1:0").await.unwrap();
        let port = listener.local_addr().unwrap().port();
        let server = tokio::spawn(async move {
            let (s, _) = listener.accept().await.unwrap();
            let mut s = BufReader::new(s);
            s.write_all(b"200 ready\r\n").await.unwrap();
            let mut line = String::new();
            s.read_line(&mut line).await.unwrap();
            assert_eq!(line, "ARTICLE <x@y>\r\n");
            s.write_all(b"220 0 <x@y> article\r\nMessage-ID: <x@y>\r\n\r\n..abc\r\n.\r\n")
                .await
                .unwrap();
        });
        let p = Provider {
            account: "primary".into(),
            host: "127.0.0.1".into(),
            port,
            tls: false,
            username: String::new(),
            password: String::new(),
        };
        let mut c = Client::connect(&p, 2).await.unwrap();
        assert_eq!(c.article("x@y").await.unwrap().body, b".abc\r\n");
        server.await.unwrap();
    }
    #[tokio::test]
    async fn missing_is_distinct() {
        let listener = TcpListener::bind("127.0.0.1:0").await.unwrap();
        let port = listener.local_addr().unwrap().port();
        let server = tokio::spawn(async move {
            let (mut s, _) = listener.accept().await.unwrap();
            s.write_all(b"200 ready\r\n").await.unwrap();
            let mut s = BufReader::new(s);
            let mut line = String::new();
            s.read_line(&mut line).await.unwrap();
            s.write_all(b"430 absent\r\n").await.unwrap();
        });
        let p = Provider {
            account: "primary".into(),
            host: "127.0.0.1".into(),
            port,
            tls: false,
            username: String::new(),
            password: String::new(),
        };
        let mut c = Client::connect(&p, 2).await.unwrap();
        assert!(
            c.article("x@y")
                .await
                .unwrap_err()
                .to_string()
                .contains("NntpArticleMissingException")
        );
        server.await.unwrap();
    }
    #[tokio::test]
    async fn oversized_response_poisons_and_prevents_a_second_command() {
        let listener = TcpListener::bind("127.0.0.1:0").await.unwrap();
        let port = listener.local_addr().unwrap().port();
        let server = tokio::spawn(async move {
            let (stream, _) = listener.accept().await.unwrap();
            let mut io = BufReader::new(stream);
            io.write_all(b"200 ready\r\n").await.unwrap();
            let mut command = String::new();
            io.read_line(&mut command).await.unwrap();
            io.write_all(b"220 0 <x@y> follows\r\n").await.unwrap();
            let _ = io.write_all(&vec![b'A'; MAX_HEADERS + 1]).await;
            command.clear();
            let next =
                tokio::time::timeout(Duration::from_secs(2), io.read_line(&mut command)).await;
            assert!(
                next.is_err() || command.is_empty(),
                "poisoned connection received another command"
            );
        });
        let p = Provider {
            account: "primary".into(),
            host: "127.0.0.1".into(),
            port,
            tls: false,
            username: String::new(),
            password: String::new(),
        };
        let mut c = Client::connect(&p, 1).await.unwrap();
        assert!(c.article("x@y").await.is_err());
        assert!(c.poisoned());
        assert!(
            c.article("second@y")
                .await
                .unwrap_err()
                .to_string()
                .contains("poisoned")
        );
        drop(c);
        server.await.unwrap();
    }
    #[tokio::test]
    async fn timeout_poisons_and_prevents_frame_reuse() {
        let listener = TcpListener::bind("127.0.0.1:0").await.unwrap();
        let port = listener.local_addr().unwrap().port();
        let server = tokio::spawn(async move {
            let (stream, _) = listener.accept().await.unwrap();
            let mut io = BufReader::new(stream);
            io.write_all(b"200 ready\r\n").await.unwrap();
            let mut command = String::new();
            io.read_line(&mut command).await.unwrap();
            io.write_all(b"220 0 <x@y> follows\r\nMessage-ID: <x@y>\r\n\r\npartial")
                .await
                .unwrap();
            command.clear();
            let _ = io.read_line(&mut command).await;
            assert!(command.is_empty());
        });
        let p = Provider {
            account: "primary".into(),
            host: "127.0.0.1".into(),
            port,
            tls: false,
            username: String::new(),
            password: String::new(),
        };
        let mut c = Client::connect(&p, 1).await.unwrap();
        assert!(c.article("x@y").await.is_err());
        assert!(c.poisoned());
        assert!(c.article("second@y").await.is_err());
        drop(c);
        server.await.unwrap();
    }
    #[tokio::test]
    async fn plain_missing_article_keeps_stream_reusable() {
        let listener = TcpListener::bind("127.0.0.1:0").await.unwrap();
        let port = listener.local_addr().unwrap().port();
        let server = tokio::spawn(async move {
            let (stream, _) = listener.accept().await.unwrap();
            let mut io = BufReader::new(stream);
            io.write_all(b"200 ready\r\n").await.unwrap();
            let mut command = String::new();
            io.read_line(&mut command).await.unwrap();
            io.write_all(b"430 absent\r\n").await.unwrap();
            command.clear();
            io.read_line(&mut command).await.unwrap();
            assert_eq!(command, "ARTICLE <second@y>\r\n");
            io.write_all(
                b"220 0 <second@y> follows\r\nMessage-ID: <second@y>\r\n\r\nbody\r\n.\r\n",
            )
            .await
            .unwrap();
        });
        let p = Provider {
            account: "primary".into(),
            host: "127.0.0.1".into(),
            port,
            tls: false,
            username: String::new(),
            password: String::new(),
        };
        let mut c = Client::connect(&p, 1).await.unwrap();
        assert!(c.article("x@y").await.is_err());
        assert!(!c.poisoned());
        assert_eq!(c.article("second@y").await.unwrap().body, b"body\r\n");
        server.await.unwrap();
    }
}

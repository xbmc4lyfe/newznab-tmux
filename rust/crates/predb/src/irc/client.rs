//! A small line-based IRC client over TCP or TLS.

use std::sync::Arc;
use std::time::Duration;

use anyhow::{Context, Result, anyhow, bail};
use rustls::client::WebPkiServerVerifier;
use rustls::client::danger::{HandshakeSignatureValid, ServerCertVerified, ServerCertVerifier};
use rustls::pki_types::{CertificateDer, ServerName, UnixTime};
use rustls::{
    ClientConfig, DigitallySignedStruct, Error as TlsError, RootCertStore, SignatureScheme,
};
use tokio::io::{
    AsyncBufReadExt, AsyncRead, AsyncWrite, AsyncWriteExt, BufReader, ReadHalf, WriteHalf,
};
use tokio::net::TcpStream;
use tokio_rustls::TlsConnector;

const CONNECTION_TIMEOUT: Duration = Duration::from_secs(30);
const READ_POLL: Duration = Duration::from_secs(5);
/// Bytes in one frame, including its line ending. Longer frames are dropped whole.
const MAX_FRAME: usize = 8703;

trait Stream: AsyncRead + AsyncWrite + Send + Unpin {}
impl<T: AsyncRead + AsyncWrite + Send + Unpin> Stream for T {}

fn roots() -> Result<RootCertStore> {
    let mut store = RootCertStore::empty();
    let certs = rustls_native_certs::load_native_certs();
    for cert in certs.certs {
        store.add(cert).ok();
    }
    if store.is_empty() {
        bail!("no TLS root certificates are available");
    }
    Ok(store)
}

/// Verifies the chain but not the name, for a server whose certificate doesn't name it.
#[derive(Debug)]
struct ChainOnly(Arc<WebPkiServerVerifier>);

impl ServerCertVerifier for ChainOnly {
    fn verify_server_cert(
        &self,
        end_entity: &CertificateDer<'_>,
        intermediates: &[CertificateDer<'_>],
        server_name: &ServerName<'_>,
        ocsp_response: &[u8],
        now: UnixTime,
    ) -> Result<ServerCertVerified, TlsError> {
        match self
            .0
            .verify_server_cert(end_entity, intermediates, server_name, ocsp_response, now)
        {
            Err(TlsError::InvalidCertificate(rustls::CertificateError::NotValidForName))
            | Err(TlsError::InvalidCertificate(
                rustls::CertificateError::NotValidForNameContext { .. },
            )) => Ok(ServerCertVerified::assertion()),
            other => other,
        }
    }

    fn verify_tls12_signature(
        &self,
        message: &[u8],
        cert: &CertificateDer<'_>,
        dss: &DigitallySignedStruct,
    ) -> Result<HandshakeSignatureValid, TlsError> {
        self.0.verify_tls12_signature(message, cert, dss)
    }

    fn verify_tls13_signature(
        &self,
        message: &[u8],
        cert: &CertificateDer<'_>,
        dss: &DigitallySignedStruct,
    ) -> Result<HandshakeSignatureValid, TlsError> {
        self.0.verify_tls13_signature(message, cert, dss)
    }

    fn supported_verify_schemes(&self) -> Vec<SignatureScheme> {
        self.0.supported_verify_schemes()
    }
}

fn tls_config(verify_peer_name: bool) -> Result<Arc<ClientConfig>> {
    let provider = Arc::new(rustls::crypto::ring::default_provider());
    let builder = ClientConfig::builder_with_provider(provider.clone())
        .with_safe_default_protocol_versions()?;
    let roots = Arc::new(roots()?);
    let config = if verify_peer_name {
        builder.with_root_certificates(roots).with_no_client_auth()
    } else {
        let verifier = WebPkiServerVerifier::builder_with_provider(roots, provider).build()?;
        builder
            .dangerous()
            .with_custom_certificate_verifier(Arc::new(ChainOnly(verifier)))
            .with_no_client_auth()
    };
    Ok(Arc::new(config))
}

pub struct IrcConnection {
    reader: BufReader<ReadHalf<Box<dyn Stream>>>,
    writer: WriteHalf<Box<dyn Stream>>,
    partial: Vec<u8>,
    discarding: bool,
}

/// What one read produced.
pub enum Read {
    Line(String),
    /// Nothing arrived within the poll interval.
    Idle,
    Closed,
}

impl IrcConnection {
    /// Connects, trying every address the host resolves to within one time budget.
    pub async fn connect(
        server: &str,
        port: u16,
        tls: bool,
        verify_peer_name: bool,
    ) -> Result<Self> {
        if server.is_empty() {
            bail!("IRC host name must not be empty");
        }
        let tcp = tokio::time::timeout(CONNECTION_TIMEOUT, TcpStream::connect((server, port)))
            .await
            .map_err(|_| anyhow!("timed out connecting to {server}:{port}"))?
            .with_context(|| format!("connecting to {server}:{port}"))?;
        let stream: Box<dyn Stream> = if tls {
            let name = ServerName::try_from(server.to_owned()).context("the IRC server name")?;
            let connector = TlsConnector::from(tls_config(verify_peer_name)?);
            let secured = tokio::time::timeout(CONNECTION_TIMEOUT, connector.connect(name, tcp))
                .await
                .map_err(|_| anyhow!("timed out in the TLS handshake with {server}:{port}"))?
                .with_context(|| format!("TLS handshake with {server}:{port}"))?;
            Box::new(secured)
        } else {
            Box::new(tcp)
        };
        let (reader, writer) = tokio::io::split(stream);
        Ok(Self {
            reader: BufReader::new(reader),
            writer,
            partial: Vec::new(),
            discarding: false,
        })
    }

    pub async fn send(&mut self, command: &str) -> Result<()> {
        self.writer
            .write_all(format!("{command}\r\n").as_bytes())
            .await?;
        self.writer.flush().await?;
        Ok(())
    }

    /// Reads one frame, keeping a partial line across idle polls.
    pub async fn read(&mut self) -> Result<Read> {
        let read =
            tokio::time::timeout(READ_POLL, self.reader.read_until(b'\n', &mut self.partial)).await;
        let count = match read {
            Err(_) => {
                self.guard_length();
                return Ok(Read::Idle);
            }
            Ok(result) => result?,
        };
        if count == 0 {
            return Ok(Read::Closed);
        }
        if self.partial.last() != Some(&b'\n') {
            self.guard_length();
            return Ok(Read::Idle);
        }
        let mut frame = std::mem::take(&mut self.partial);
        if self.discarding {
            self.discarding = false;
            return Ok(Read::Idle);
        }
        frame.pop();
        if frame.last() == Some(&b'\r') {
            frame.pop();
        }
        if frame.len() + 2 > MAX_FRAME + 1 {
            return Ok(Read::Idle);
        }
        Ok(Read::Line(String::from_utf8_lossy(&frame).into_owned()))
    }

    fn guard_length(&mut self) {
        if self.partial.len() > MAX_FRAME {
            self.partial.clear();
            self.discarding = true;
        }
    }

    /// Registers, answering pings until the server's 001. Returns the name it calls itself.
    pub async fn login(
        &mut self,
        nick: &str,
        user: &str,
        real: &str,
        password: Option<&str>,
    ) -> Result<String> {
        if nick.is_empty() || user.is_empty() || real.is_empty() {
            bail!("nick, user and real name must not be empty");
        }
        if let Some(password) = password {
            self.send(&format!("PASS {password}")).await?;
        }
        self.send(&format!("NICK {nick}")).await?;
        self.send(&format!("USER {user} 0 * :{real}")).await?;
        let started = std::time::Instant::now();
        loop {
            if started.elapsed() > Duration::from_secs(180) {
                bail!("timed out waiting for the server to accept the login");
            }
            let line = match self.read().await? {
                Read::Line(line) => line,
                Read::Idle => continue,
                Read::Closed => bail!("the server closed the connection during login"),
            };
            if let Some(token) = line
                .strip_prefix("PING")
                .map(str::trim_start)
                .and_then(|rest| rest.strip_prefix(':'))
            {
                self.send(&format!("PONG {token}")).await?;
                continue;
            }
            if line.starts_with("ERROR") {
                bail!("{line}");
            }
            let mut parts = line.splitn(3, char::is_whitespace);
            let (Some(origin), Some(code)) = (parts.next(), parts.next()) else {
                continue;
            };
            let Some(origin) = origin.strip_prefix(':') else {
                continue;
            };
            match code {
                "001" | "1" => return Ok(origin.to_owned()),
                "464" => {
                    let text = parts.next().unwrap_or("").to_ascii_lowercase();
                    if let Some(password) = password {
                        // ZNC wants user:password, or a user/server:password given whole.
                        let retry = if password.contains('/') && password.contains(':') {
                            password.to_owned()
                        } else {
                            format!("{user}:{password}")
                        };
                        self.send(&format!("PASS {retry}")).await?;
                    }
                    if text.contains("invalid password") {
                        bail!("invalid password or username");
                    }
                }
                _ => {}
            }
        }
    }

    pub async fn join(&mut self, channels: &[(String, Option<String>)]) -> Result<()> {
        for (channel, password) in channels {
            match password {
                Some(password) => self.send(&format!("JOIN {channel} {password}")).await?,
                None => self.send(&format!("JOIN {channel}")).await?,
            }
        }
        Ok(())
    }
}

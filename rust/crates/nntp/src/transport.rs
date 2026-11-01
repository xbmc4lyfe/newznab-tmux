//! One byte stream to a news server, read the way the rest of this crate needs it.

use std::fs;
use std::sync::Arc;
use std::time::Duration;

use rustls::client::danger::{HandshakeSignatureValid, ServerCertVerified, ServerCertVerifier};
use rustls::client::{WebPkiServerVerifier, verify_server_name};
use rustls::pki_types::pem::PemObject;
use rustls::pki_types::{CertificateDer, ServerName, UnixTime};
use rustls::server::ParsedCertificate;
use rustls::{
    CertificateError, ClientConfig, DigitallySignedStruct, RootCertStore, SignatureScheme,
};
use tokio::io::{AsyncBufReadExt, AsyncRead, AsyncReadExt, AsyncWrite, AsyncWriteExt, BufReader};
use tokio::net::TcpStream;
use tokio::time::timeout;
use tokio_rustls::TlsConnector;

use crate::config::{Provider, TlsOptions};

pub(crate) trait Io: AsyncRead + AsyncWrite + Unpin + Send + Sync {}
impl<T: AsyncRead + AsyncWrite + Unpin + Send + Sync> Io for T {}

pub(crate) struct Transport {
    io: BufReader<Box<dyn Io>>,
    pub(crate) read_timeout: Duration,
    pub(crate) timed_out: bool,
}

impl Transport {
    pub(crate) async fn connect(
        provider: &Provider,
        options: &TlsOptions,
        connect_timeout: Duration,
    ) -> Result<Self, String> {
        let address = format!("{}:{}", provider.host, provider.port);
        let stream = timeout(connect_timeout, TcpStream::connect(&address))
            .await
            .map_err(|_| "Connection timed out".to_string())?
            .map_err(|error| error.to_string())?;
        let _ = stream.set_nodelay(true);
        let io: Box<dyn Io> = if provider.tls {
            let connector = TlsConnector::from(tls_config(options)?);
            let name = ServerName::try_from(provider.host.clone())
                .map_err(|error| format!("invalid TLS hostname: {error}"))?;
            Box::new(
                timeout(connect_timeout, connector.connect(name, stream))
                    .await
                    .map_err(|_| "SSL: Handshake timed out".to_string())?
                    .map_err(|error| error.to_string())?,
            )
        } else {
            Box::new(stream)
        };
        Ok(Self {
            io: BufReader::with_capacity(65_536, io),
            read_timeout: provider.socket_timeout,
            timed_out: false,
        })
    }

    pub(crate) async fn write_all(&mut self, bytes: &[u8]) -> bool {
        matches!(
            timeout(self.read_timeout, async {
                self.io.write_all(bytes).await?;
                self.io.flush().await
            })
            .await,
            Ok(Ok(()))
        )
    }

    /// Appends one line, like PHP's `fgets`: stops after a newline or `limit - 1` bytes.
    /// Returns false when nothing could be read, either at end of stream or after a read
    /// failure. `timed_out` tells the two apart.
    pub(crate) async fn fgets_into(&mut self, line: &mut Vec<u8>, limit: usize) -> bool {
        let mut remaining = limit.saturating_sub(1);
        let mut read_any = false;
        while remaining > 0 {
            let available = match timeout(self.read_timeout, self.io.fill_buf()).await {
                Ok(Ok(available)) => available,
                Ok(Err(_)) => return read_any,
                Err(_) => {
                    self.timed_out = true;
                    return read_any;
                }
            };
            if available.is_empty() {
                return read_any;
            }
            let window = &available[..available.len().min(remaining)];
            let (take, finished) = match window.iter().position(|byte| *byte == b'\n') {
                Some(newline) => (newline + 1, true),
                None => (window.len(), false),
            };
            line.extend_from_slice(&window[..take]);
            self.io.consume(take);
            read_any = true;
            remaining -= take;
            if finished {
                break;
            }
        }
        read_any
    }

    pub(crate) async fn fgets(&mut self, limit: usize) -> Option<Vec<u8>> {
        let mut line = Vec::new();
        self.fgets_into(&mut line, limit).await.then_some(line)
    }

    /// Whatever arrives next, up to `maximum` bytes. `None` at end of stream or on failure.
    pub(crate) async fn read_some(&mut self, maximum: usize) -> Option<Vec<u8>> {
        let available = match timeout(self.read_timeout, self.io.fill_buf()).await {
            Ok(Ok(available)) => available,
            Ok(Err(_)) => return None,
            Err(_) => {
                self.timed_out = true;
                return None;
            }
        };
        if available.is_empty() {
            return None;
        }
        let take = available.len().min(maximum);
        let chunk = available[..take].to_vec();
        self.io.consume(take);
        Some(chunk)
    }

    /// Whether one more byte exists. Used only to prove an article is over budget.
    pub(crate) async fn has_more(&mut self) -> bool {
        let mut byte = [0];
        matches!(
            timeout(self.read_timeout, self.io.read(&mut byte)).await,
            Ok(Ok(1))
        )
    }

    /// Unread bytes already received, which a finished pipeline must not leave behind.
    pub(crate) fn buffered(&self) -> usize {
        self.io.buffer().len()
    }

    /// PHP's `feof` check: true until the peer has closed. Never waits for data.
    pub(crate) async fn is_open(&mut self) -> bool {
        match timeout(Duration::ZERO, self.io.fill_buf()).await {
            Err(_) => true,
            Ok(Ok(available)) => !available.is_empty(),
            Ok(Err(_)) => false,
        }
    }
}

pub(crate) fn tls_config(options: &TlsOptions) -> Result<Arc<ClientConfig>, String> {
    let provider = Arc::new(rustls::crypto::ring::default_provider());
    let builder = ClientConfig::builder_with_provider(Arc::clone(&provider))
        .with_safe_default_protocol_versions()
        .map_err(|error| error.to_string())?;
    let roots = root_store(options)?;
    let config = if !options.verify_peer || !options.verify_host || options.allow_self_signed {
        let chain =
            WebPkiServerVerifier::builder_with_provider(Arc::new(roots), Arc::clone(&provider))
                .build()
                .map_err(|error| error.to_string())?;
        builder
            .dangerous()
            .with_custom_certificate_verifier(Arc::new(RelaxedVerifier {
                chain,
                verify_peer: options.verify_peer,
                verify_host: options.verify_host,
                allow_self_signed: options.allow_self_signed,
                provider,
            }))
            .with_no_client_auth()
    } else {
        builder.with_root_certificates(roots).with_no_client_auth()
    };
    Ok(Arc::new(config))
}

fn root_store(options: &TlsOptions) -> Result<RootCertStore, String> {
    let mut roots = RootCertStore {
        roots: webpki_roots::TLS_SERVER_ROOTS.to_vec(),
    };
    let mut add_file = |path: &std::path::Path| -> Result<(), String> {
        let certificates = CertificateDer::pem_file_iter(path)
            .map_err(|error| format!("{}: {error}", path.display()))?;
        for certificate in certificates {
            let certificate =
                certificate.map_err(|error| format!("{}: {error}", path.display()))?;
            roots
                .add(certificate)
                .map_err(|error| format!("{}: {error}", path.display()))?;
        }
        Ok(())
    };
    if let Some(file) = &options.ca_file {
        add_file(file)?;
    }
    if let Some(directory) = &options.ca_path {
        let entries =
            fs::read_dir(directory).map_err(|error| format!("{}: {error}", directory.display()))?;
        for entry in entries.flatten() {
            let path = entry.path();
            if path.is_file() {
                // CA directories also hold hash symlinks and non-PEM files. Skip those.
                let _ = add_file(&path);
            }
        }
    }
    Ok(roots)
}

/// Used only when `SSL_VERIFY_PEER`, `SSL_VERIFY_HOST` or `SSL_ALLOW_SELF_SIGNED` relaxes
/// verification, and each one gives up only what it names, as PHP's stream options did.
/// Handshake signatures are always checked.
#[derive(Debug)]
struct RelaxedVerifier {
    chain: Arc<WebPkiServerVerifier>,
    verify_peer: bool,
    verify_host: bool,
    allow_self_signed: bool,
    provider: Arc<rustls::crypto::CryptoProvider>,
}

impl ServerCertVerifier for RelaxedVerifier {
    fn verify_server_cert(
        &self,
        end_entity: &CertificateDer<'_>,
        intermediates: &[CertificateDer<'_>],
        server_name: &ServerName<'_>,
        ocsp_response: &[u8],
        now: UnixTime,
    ) -> Result<ServerCertVerified, rustls::Error> {
        if self.verify_peer {
            let verdict = self.chain.verify_server_cert(
                end_entity,
                intermediates,
                server_name,
                ocsp_response,
                now,
            );
            match verdict {
                Ok(verified) => return Ok(verified),
                // The chain was checked first, so a name failure means the chain is good.
                Err(rustls::Error::InvalidCertificate(
                    CertificateError::NotValidForName
                    | CertificateError::NotValidForNameContext { .. },
                )) if !self.verify_host => return Ok(ServerCertVerified::assertion()),
                Err(rustls::Error::InvalidCertificate(CertificateError::UnknownIssuer))
                    if self.allow_self_signed
                        && intermediates.is_empty()
                        && is_self_issued(end_entity) => {}
                Err(failure) => return Err(failure),
            }
        }
        if self.verify_host {
            verify_server_name(&ParsedCertificate::try_from(end_entity)?, server_name)?;
        }
        Ok(ServerCertVerified::assertion())
    }

    fn verify_tls12_signature(
        &self,
        message: &[u8],
        certificate: &CertificateDer<'_>,
        signature: &DigitallySignedStruct,
    ) -> Result<HandshakeSignatureValid, rustls::Error> {
        rustls::crypto::verify_tls12_signature(
            message,
            certificate,
            signature,
            &self.provider.signature_verification_algorithms,
        )
    }

    fn verify_tls13_signature(
        &self,
        message: &[u8],
        certificate: &CertificateDer<'_>,
        signature: &DigitallySignedStruct,
    ) -> Result<HandshakeSignatureValid, rustls::Error> {
        rustls::crypto::verify_tls13_signature(
            message,
            certificate,
            signature,
            &self.provider.signature_verification_algorithms,
        )
    }

    fn supported_verify_schemes(&self) -> Vec<SignatureScheme> {
        self.provider
            .signature_verification_algorithms
            .supported_schemes()
    }
}

/// Whether a certificate names itself as its issuer. Reads just enough DER to compare the two
/// names, because that's all "self-signed" needs to mean here: anyone can sign their own.
fn is_self_issued(certificate: &[u8]) -> bool {
    self_issued(certificate).unwrap_or(false)
}

fn self_issued(certificate: &[u8]) -> Option<bool> {
    let (_, certificate, _) = der_element(certificate)?;
    let (_, mut fields, _) = der_element(certificate)?;
    let (tag, _, after) = der_element(fields)?;
    if tag == 0xa0 {
        // Optional explicit version.
        fields = after;
    }
    let (_, _, after_serial) = der_element(fields)?;
    let (_, _, after_algorithm) = der_element(after_serial)?;
    let (_, issuer, after_issuer) = der_element(after_algorithm)?;
    let (_, _, after_validity) = der_element(after_issuer)?;
    let (_, subject, _) = der_element(after_validity)?;
    Some(issuer == subject)
}

/// Tag, contents and the bytes after one DER element.
fn der_element(input: &[u8]) -> Option<(u8, &[u8], &[u8])> {
    let tag = *input.first()?;
    let first = *input.get(1)?;
    let (length, header) = if first < 0x80 {
        (usize::from(first), 2)
    } else {
        let count = usize::from(first & 0x7f);
        if count == 0 || count > 4 {
            return None;
        }
        let bytes = input.get(2..2 + count)?;
        let length = bytes
            .iter()
            .fold(0usize, |total, byte| (total << 8) | usize::from(*byte));
        (length, 2 + count)
    };
    let end = header.checked_add(length)?;
    Some((tag, input.get(header..end)?, input.get(end..)?))
}

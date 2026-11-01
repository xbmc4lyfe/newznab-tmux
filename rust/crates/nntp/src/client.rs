//! One session with a news server: connect, log in, select groups and read overviews.

use std::sync::Arc;
use std::time::Duration;

use flate2::{Decompress, FlushDecompress, Status};
use tracing::{error, warn};

use crate::config::{NntpConfig, Provider};
use crate::error::NntpError;
use crate::overview::{self, OverviewRow};
use crate::transport::Transport;

const CONNECT_TIMEOUT: Duration = Duration::from_secs(5);
/// A provider can't make us inflate more overview than this from one response.
const MAX_INFLATED_OVERVIEW_BYTES: usize = 1 << 30;

#[derive(Debug, Clone, PartialEq, Eq)]
pub struct GroupSummary {
    pub group: String,
    pub first: i64,
    pub last: i64,
    pub count: i64,
}

#[derive(Debug, Clone, PartialEq, Eq)]
pub struct GroupInfo {
    pub group: String,
    pub first: String,
    pub last: String,
    pub posting: Option<String>,
}

pub struct NntpClient {
    pub(crate) config: Arc<NntpConfig>,
    pub(crate) transport: Option<Transport>,
    pub(crate) using_alternate: bool,
    pub(crate) compression_enabled: bool,
    compression_supported: bool,
    current_group: String,
    selected_summary: Option<GroupSummary>,
    overview_format: Option<Vec<(String, bool)>>,
    posting_allowed: bool,
    authenticated: bool,
    /// A command went out and its whole response hasn't been read yet. Whatever is on the
    /// socket then belongs to that command, so nothing may reuse the session.
    pub(crate) response_pending: bool,
    pub(crate) status: (u16, Vec<u8>),
}

impl NntpClient {
    pub fn new(config: Arc<NntpConfig>) -> Self {
        Self {
            config,
            transport: None,
            using_alternate: false,
            compression_enabled: false,
            compression_supported: true,
            current_group: String::new(),
            selected_summary: None,
            overview_format: None,
            posting_allowed: false,
            authenticated: false,
            response_pending: false,
            status: (0, Vec::new()),
        }
    }

    pub fn from_env() -> Self {
        Self::new(Arc::new(NntpConfig::from_env()))
    }

    fn provider(&self, alternate: bool) -> Result<Provider, NntpError> {
        if alternate {
            self.config.alternate.clone().ok_or_else(|| {
                NntpError::ProviderUnavailable("No alternate NNTP provider is configured.".into())
            })
        } else {
            Ok(self.config.primary.clone())
        }
    }

    /// Connects to a provider, retrying connects and logins. A live session on the same
    /// provider is kept.
    pub async fn do_connect(
        &mut self,
        compression: bool,
        alternate: bool,
    ) -> Result<(), NntpError> {
        if self.using_alternate == alternate && self.session_usable().await {
            return Ok(());
        }
        self.do_quit();
        self.using_alternate = alternate;
        let provider = self.provider(alternate)?;
        let encryption = if provider.tls { " (ssl)" } else { " (non-ssl)" };
        let total = self.config.retries.max(1);
        let mut retries = total;
        let mut connect_error = None;
        let mut auth_error = None;
        loop {
            retries -= 1;
            if self.transport.is_none() {
                match self.connect(&provider).await {
                    Ok(posting) => self.posting_allowed = posting,
                    Err(failure) => {
                        connect_error.get_or_insert(failure.to_string());
                        if retries > 0 {
                            pause_before_retry(total - retries).await;
                            continue;
                        }
                        let message = format!(
                            "Cannot connect to server {}{encryption}: {}",
                            provider.host,
                            connect_error.unwrap_or_default()
                        );
                        error!("{message}");
                        return Err(NntpError::Transport(message));
                    }
                }
            }
            if provider.username.is_empty() {
                break;
            }
            match self.authenticate(&provider).await {
                Ok(()) => break,
                Err(failure) => {
                    auth_error.get_or_insert(failure.to_string());
                    if retries > 0 {
                        // A provider at its connection limit needs seconds to free a slot.
                        // A session it closed can't be logged into again, so reconnect then.
                        if !self.is_connected().await {
                            self.do_quit();
                        }
                        pause_before_retry(total - retries).await;
                        continue;
                    }
                    let message = format!(
                        "Cannot authenticate to server {}{encryption} - {} ({})",
                        provider.host,
                        provider.username,
                        auth_error.unwrap_or_default()
                    );
                    error!("{message}");
                    self.do_quit();
                    return Err(NntpError::Transport(message));
                }
            }
        }
        if !compression || !self.config.compressed_headers {
            self.compression_supported = false;
        }
        self.authenticated = true;
        Ok(())
    }

    async fn connect(&mut self, provider: &Provider) -> Result<bool, NntpError> {
        let scheme = if provider.tls { "tls" } else { "tcp" };
        let transport = Transport::connect(
            provider,
            &self.config.tls,
            CONNECT_TIMEOUT,
            self.using_alternate,
        )
        .await
        .map_err(|failure| {
            let hint = if provider.tls {
                " Try disabling SSL/TLS, and/or try a different port."
            } else {
                ""
            };
            NntpError::Transport(format!(
                "Connection to {scheme}://{}:{} failed.{hint} [{failure}]",
                provider.host, provider.port
            ))
        })?;
        self.transport = Some(transport);
        match self.read_status().await {
            Ok(200) => Ok(true),
            Ok(201) => Ok(false),
            Ok(code) => {
                let failure = self.response_error(code);
                self.do_quit();
                Err(failure)
            }
            Err(failure) => {
                self.do_quit();
                Err(failure)
            }
        }
    }

    async fn authenticate(&mut self, provider: &Provider) -> Result<(), NntpError> {
        let mut code = self
            .send_command(&format!("AUTHINFO user {}", provider.username))
            .await?;
        if code == 381 {
            code = self
                .send_command(&format!("AUTHINFO pass {}", provider.password))
                .await?;
        }
        match code {
            281 => Ok(()),
            381 => Err(self.server_error(code, "Authentication uncompleted")),
            482 | 502 => Err(self.server_error(code, "Authentication rejected")),
            _ => Err(self.unexpected(code)),
        }
    }

    /// Forgets the session and closes the socket without QUIT, so an unread response can
    /// never be taken for the next command's reply.
    pub fn do_quit(&mut self) {
        self.lose_connection();
        self.compression_supported = true;
        self.current_group.clear();
        self.posting_allowed = false;
    }

    /// Closes a session whose stream can't be trusted any more. The group is kept, so the next
    /// call that checks the connection can select it again.
    pub(crate) fn lose_connection(&mut self) {
        self.transport = None;
        self.authenticated = false;
        self.response_pending = false;
        self.compression_enabled = false;
        self.selected_summary = None;
        self.overview_format = None;
    }

    /// Reconnects to the same provider slot.
    pub async fn reconnect(&mut self, compression: bool) -> Result<(), NntpError> {
        self.do_quit();
        self.do_connect(compression, self.using_alternate).await
    }

    /// Whether the peer is still there. Doesn't wait for data.
    pub async fn is_connected(&mut self) -> bool {
        match &mut self.transport {
            Some(transport) => transport.is_open().await,
            None => false,
        }
    }

    /// Logged in, still open, and not owed a response by a cancelled caller.
    async fn session_usable(&mut self) -> bool {
        self.authenticated && !self.response_pending && self.is_connected().await
    }

    /// Whether this session can serve another caller: connected, logged in, and with no
    /// response left unread, which also covers a caller that was cancelled mid-response.
    pub(crate) fn reusable(&self) -> bool {
        self.transport.is_some() && self.authenticated && !self.response_pending
    }

    pub fn current_group(&self) -> &str {
        &self.current_group
    }

    pub fn using_alternate(&self) -> bool {
        self.using_alternate
    }

    pub fn is_posting_allowed(&self) -> bool {
        self.posting_allowed
    }

    pub fn is_compression_enabled(&self) -> bool {
        self.compression_enabled
    }

    pub fn is_compression_supported(&self) -> bool {
        self.compression_supported
    }

    pub fn current_server(&self) -> &str {
        match (&self.config.alternate, self.using_alternate) {
            (Some(alternate), true) => &alternate.host,
            _ => &self.config.primary.host,
        }
    }

    /// Text after the code on the latest status line.
    pub fn status_text(&self) -> String {
        String::from_utf8_lossy(&self.status.1).into_owned()
    }

    /// A second client for the other provider, unconnected.
    pub(crate) fn alternate_client(&self) -> Self {
        Self::new(Arc::clone(&self.config))
    }

    /// Connects `client` to whichever provider this client isn't using.
    pub(crate) async fn connect_other_provider(&self, client: &mut Self) -> Result<(), NntpError> {
        if self.using_alternate {
            client.do_connect(true, false).await
        } else {
            client
                .do_connect(self.config.compressed_headers, true)
                .await
        }
    }

    pub(crate) async fn send_command(&mut self, command: &str) -> Result<u16, NntpError> {
        self.write_command(command).await?;
        self.read_status().await
    }

    /// Any failure after the first byte may have left the stream mid-command, so it closes the
    /// session. A late reply must never be read as the answer to a later command.
    pub(crate) async fn write_command(&mut self, command: &str) -> Result<(), NntpError> {
        if command.len() > 510 {
            return Err(NntpError::transport(
                "Failed writing to socket! (Command too long - max 510 chars)",
            ));
        }
        if command.bytes().any(|byte| matches!(byte, b'\r' | b'\n')) {
            return Err(NntpError::transport(
                "Illegal character(s) in NNTP command!",
            ));
        }
        if !self.is_connected().await {
            self.lose_connection();
            return Err(NntpError::transport(
                "Failed to write to socket! (connection lost!)",
            ));
        }
        let stale = self.response_pending
            || self
                .transport
                .as_ref()
                .is_some_and(|transport| transport.buffered() > 0);
        if stale {
            self.lose_connection();
            return Err(NntpError::transport(
                "Failed to write to socket! (unread data on the connection)",
            ));
        }
        let mut line = Vec::with_capacity(command.len() + 2);
        line.extend_from_slice(command.as_bytes());
        line.extend_from_slice(b"\r\n");
        self.response_pending = true;
        let written = match &mut self.transport {
            Some(transport) => transport.write_all(&line).await,
            None => false,
        };
        if written {
            Ok(())
        } else {
            self.lose_connection();
            Err(NntpError::transport("Failed to write to socket!"))
        }
    }

    pub(crate) async fn read_status(&mut self) -> Result<u16, NntpError> {
        let transport = self
            .transport
            .as_mut()
            .ok_or_else(|| NntpError::transport("Failed to read from socket...!"))?;
        let Some(line) = transport.fgets(4096).await else {
            let timed_out = transport.timed_out;
            self.lose_connection();
            return Err(NntpError::transport(if timed_out {
                "Connection timed out"
            } else {
                "Failed to read from socket...!"
            }));
        };
        // A line cut off by a timeout or a hangup isn't a reply, whatever it starts with.
        if !line.ends_with(b"\n") {
            self.lose_connection();
            return Err(NntpError::transport("Failed to read from socket...!"));
        }
        let line = php_trim(&line);
        // A short or non-numeric reply reads as code 0, which no command accepts.
        let code = line
            .get(..3)
            .and_then(|digits| std::str::from_utf8(digits).ok())
            .and_then(|digits| digits.parse().ok())
            .unwrap_or(0);
        self.status = (code, line.get(4..).unwrap_or_default().to_vec());
        self.response_pending = has_data_block(code);
        Ok(code)
    }

    pub(crate) fn server_error(&self, code: u16, message: &str) -> NntpError {
        NntpError::Server {
            code,
            message: message.to_string(),
            text: self.status_text(),
        }
    }

    fn unexpected(&self, code: u16) -> NntpError {
        let text = self.status_text();
        NntpError::Server {
            code,
            message: format!("Unexpected response: '{text}'"),
            text,
        }
    }

    /// The error for a response code a command didn't want.
    pub(crate) fn response_error(&self, code: u16) -> NntpError {
        let message = match code {
            381 => "More authentication information required",
            400 => "Server refused connection",
            411 => "No such news group on server",
            412 => "No news group current selected",
            420 => "Current article number is invalid",
            421 => "No next article in this group",
            422 => "No previous article in this group",
            423 => "No such article number in this group",
            430 => "No such article found",
            435 => "Article not wanted",
            436 => "Transfer failed - try again later",
            437 => "Article rejected - do not try again",
            440 => "Posting not allowed",
            441 => "Posting failed",
            481 => "Groups and descriptions unavailable",
            482 => "Authentication rejected",
            500 => "Command not recognized",
            501 => "Command syntax error",
            502 => "No permission",
            503 => "Internal server error, function not performed",
            580 => "Can not initiate TLS negotiation",
            _ => return self.unexpected(code),
        };
        self.server_error(code, message)
    }

    /// Checks the session and reconnects if it was lost, optionally selecting the group again.
    pub(crate) async fn check_connection(&mut self, reselect_group: bool) -> Result<(), NntpError> {
        if self.session_usable().await {
            return Ok(());
        }
        let group = self.current_group.clone();
        self.reconnect(true).await?;
        if reselect_group && !group.is_empty() {
            self.send_group(&group).await?;
        }
        Ok(())
    }

    /// Selects a group. An unforced call for the current group returns its cached summary.
    pub async fn select_group(
        &mut self,
        group: &str,
        force: bool,
    ) -> Result<GroupSummary, NntpError> {
        self.check_connection(false).await?;
        if !force
            && self.current_group == group
            && let Some(summary) = &self.selected_summary
        {
            return Ok(summary.clone());
        }
        self.send_group(group).await
    }

    /// The recorded group only changes once the server has selected it, because a refused
    /// GROUP leaves the previous selection in force.
    async fn send_group(&mut self, group: &str) -> Result<GroupSummary, NntpError> {
        let code = self.send_command(&format!("GROUP {group}")).await?;
        match code {
            211 => {
                let summary = self.parse_group_summary()?;
                self.current_group = group.to_string();
                self.selected_summary = Some(summary.clone());
                Ok(summary)
            }
            411 => Err(self.server_error(code, "No such news group")),
            _ => Err(self.unexpected(code)),
        }
    }

    fn parse_group_summary(&self) -> Result<GroupSummary, NntpError> {
        let text = self.status_text();
        let mut parts = text.split_ascii_whitespace();
        let mut number = || parts.next().and_then(|part| part.parse::<i64>().ok());
        let (count, first, last) = (number(), number(), number());
        let group = text.split_ascii_whitespace().nth(3);
        match (count, first, last, group) {
            (Some(count), Some(first), Some(last), Some(group)) => Ok(GroupSummary {
                group: group.to_string(),
                first,
                last,
                count,
            }),
            _ => Err(NntpError::transport(format!(
                "Malformed GROUP response: '{text}'"
            ))),
        }
    }

    /// Reconnects after a group failure and selects the group again.
    pub async fn data_error(
        &mut self,
        group: &str,
        compression: bool,
    ) -> Result<GroupSummary, NntpError> {
        if self.reconnect(compression).await.is_err() {
            return Err(NntpError::transport("Unable to reconnect to usenet!"));
        }
        match self.select_group(group, false).await {
            Ok(summary) => Ok(summary),
            Err(failure) => {
                warn!(group, %failure, "skipping group");
                self.do_quit();
                Err(failure)
            }
        }
    }

    /// Turns on `XFEATURE COMPRESS GZIP` when configured and supported. A refusal doesn't fail
    /// the session; overviews then come uncompressed.
    pub async fn enable_compression(&mut self) {
        if self.config.compressed_headers {
            self.try_enable_compression().await;
        }
    }

    async fn try_enable_compression(&mut self) {
        if self.compression_enabled || !self.compression_supported {
            return;
        }
        let mut second_try = false;
        loop {
            match self.send_command("XFEATURE COMPRESS GZIP").await {
                Ok(290) => {
                    self.compression_enabled = true;
                    self.compression_supported = true;
                    return;
                }
                Err(_) => {
                    self.compression_supported = false;
                    return;
                }
                Ok(_) if second_try => {
                    self.compression_supported = false;
                    return;
                }
                Ok(_) => {
                    second_try = true;
                    let group = self.current_group.clone();
                    let using_alternate = self.using_alternate;
                    self.do_quit();
                    let restored = self.do_connect(true, using_alternate).await.is_ok()
                        && (group.is_empty() || self.send_group(&group).await.is_ok());
                    if !restored {
                        self.compression_supported = false;
                        return;
                    }
                }
            }
        }
    }

    /// Reads a multi-line response, inflating it if the server compressed it. A failure part
    /// way through leaves the stream unusable, so it closes the session.
    pub(crate) async fn read_text_response(&mut self) -> Result<Vec<Vec<u8>>, NntpError> {
        let compressed = self.compression_enabled
            && self
                .status
                .1
                .to_ascii_uppercase()
                .windows(13)
                .any(|window| window == b"COMPRESS=GZIP");
        let result = if compressed {
            self.read_compressed_lines().await
        } else {
            self.read_plain_lines().await
        };
        if result.is_err() {
            self.lose_connection();
        } else {
            self.response_pending = false;
        }
        result
    }

    /// Only a CRLF ends a line. A bare LF stays inside it.
    async fn read_plain_lines(&mut self) -> Result<Vec<Vec<u8>>, NntpError> {
        let transport = self
            .transport
            .as_mut()
            .ok_or_else(|| NntpError::transport("End of stream! Connection lost?"))?;
        let mut lines = Vec::new();
        let mut line = Vec::new();
        loop {
            if !transport.fgets_into(&mut line, 8192).await {
                return Err(NntpError::transport(if transport.timed_out {
                    "Connection timed out"
                } else {
                    "End of stream! Connection lost?"
                }));
            }
            if !line.ends_with(b"\r\n") {
                continue;
            }
            line.truncate(line.len() - 2);
            if line == b"." {
                return Ok(lines);
            }
            if line.starts_with(b"..") {
                line.remove(0);
            }
            lines.push(std::mem::take(&mut line));
        }
    }

    /// One zlib stream followed by `.\r\n`. The response ends when zlib reports the end of
    /// the stream, so a terminator inside the compressed bytes can't end it early.
    async fn read_compressed_lines(&mut self) -> Result<Vec<Vec<u8>>, NntpError> {
        const READ_FAILED: &str =
            "Error fetching data from usenet server while downloading OVER headers.";
        let transport = self
            .transport
            .as_mut()
            .ok_or_else(|| NntpError::transport(READ_FAILED))?;
        let mut inflate = Decompress::new(true);
        let mut output = Vec::with_capacity(1 << 20);
        let mut tail = Vec::new();
        let mut ended = false;
        loop {
            let Some(buffer) = transport.read_some(65_536).await else {
                return Err(NntpError::transport(READ_FAILED));
            };
            if ended {
                tail.extend_from_slice(&buffer);
            } else {
                let mut input = buffer.as_slice();
                loop {
                    if output.len() == output.capacity() {
                        output.reserve(output.capacity().max(1 << 16));
                    }
                    let before = inflate.total_in();
                    let status = inflate
                        .decompress_vec(input, &mut output, FlushDecompress::Sync)
                        .map_err(|_| {
                            NntpError::transport("Decompression of OVER headers failed.")
                        })?;
                    let consumed = usize::try_from(inflate.total_in() - before).unwrap_or(0);
                    input = &input[consumed..];
                    if output.len() > MAX_INFLATED_OVERVIEW_BYTES {
                        return Err(NntpError::transport(
                            "Decompression of OVER headers failed.",
                        ));
                    }
                    if matches!(status, Status::StreamEnd) {
                        ended = true;
                        tail.extend_from_slice(input);
                        break;
                    }
                    if input.is_empty() && output.len() < output.capacity() {
                        break;
                    }
                }
            }
            if ended && tail.windows(3).any(|window| window == b".\r\n") {
                break;
            }
        }
        let text = php_trim(&output);
        Ok(split_crlf(text))
    }

    /// `XOVER <range>`, with fields named by the server's overview format. A range is
    /// `679871775`, `679871775-679999999`, `679871775-`, or a bracketed Message-ID.
    pub async fn get_xover(&mut self, range: &str) -> Result<Vec<OverviewRow>, NntpError> {
        self.check_connection(true).await?;
        self.try_enable_compression().await;
        let code = self.send_command(&format!("XOVER {range}")).await?;
        if code != 224 {
            return Err(self.response_error(code));
        }
        let lines = self.read_text_response().await?;
        let cached = self
            .overview_format
            .as_ref()
            .filter(|format| format.iter().any(|(name, _)| name == "Xref"))
            .cloned();
        let format = match cached {
            Some(format) => format,
            None => {
                let format = self.read_overview_format().await?;
                self.overview_format = Some(format.clone());
                format
            }
        };
        let keys: Arc<[String]> = overview::xover_keys(&format).into();
        Ok(lines
            .iter()
            .map(|line| overview::xover_row(&keys, line))
            .collect())
    }

    /// Overview rows under the server's field names, with every field present. Part repair uses
    /// this, so a field such as `Bytes` keeps whatever name the server's format gives it.
    pub async fn get_overview(&mut self, range: &str) -> Result<Vec<OverviewRow>, NntpError> {
        self.check_connection(true).await?;
        self.try_enable_compression().await;
        let code = self.send_command(&format!("XOVER {range}")).await?;
        match code {
            224 => {}
            412 => return Err(self.server_error(code, "No news group current selected")),
            420 => return Err(self.server_error(code, "No article(s) selected")),
            502 => return Err(self.server_error(code, "No permission")),
            _ => return Err(self.unexpected(code)),
        }
        let lines = self.read_text_response().await?;
        let format = match self.overview_format.take() {
            Some(format) => overview::numbered_format(format),
            None => overview::numbered_format(self.read_overview_format().await?),
        };
        self.overview_format = Some(format.clone());
        let keys: Arc<[String]> = format
            .iter()
            .map(|(name, _)| name.clone())
            .collect::<Vec<_>>()
            .into();
        Ok(lines
            .iter()
            .map(|line| overview::named_row(&keys, &format, line))
            .collect())
    }

    async fn read_overview_format(&mut self) -> Result<Vec<(String, bool)>, NntpError> {
        let code = self.send_command("LIST OVERVIEW.FMT").await?;
        match code {
            215 => Ok(overview::parse_format(&self.read_text_response().await?)),
            503 => Err(self.server_error(code, "Internal server error, function not performed")),
            _ => Err(self.unexpected(code)),
        }
    }

    /// The groups the server offers, falling back to plain `LIST` where `LIST ACTIVE` is
    /// refused.
    pub async fn get_groups(&mut self, wildmat: Option<&str>) -> Result<Vec<GroupInfo>, NntpError> {
        self.try_enable_compression().await;
        let command = wildmat.map_or_else(
            || "LIST ACTIVE".to_string(),
            |wildmat| format!("LIST ACTIVE {wildmat}"),
        );
        let code = self.send_command(&command).await?;
        let (lines, with_posting) = if code == 215 {
            (self.read_text_response().await?, false)
        } else if matches!(code, 500 | 501) {
            let refused = self.unexpected(code);
            match self.send_command("LIST").await {
                Ok(215) => match self.read_text_response().await {
                    Ok(lines) => (lines, true),
                    Err(_) => return Err(refused),
                },
                _ => return Err(refused),
            }
        } else {
            return Err(self.unexpected(code));
        };
        Ok(lines
            .iter()
            .filter_map(|line| parse_group_line(line, with_posting))
            .collect())
    }
}

/// Codes followed by a dot-terminated block on the same response.
fn has_data_block(code: u16) -> bool {
    matches!(code, 100 | 101 | 215 | 220..=222 | 224 | 225 | 230 | 231)
}

fn parse_group_line(line: &[u8], with_posting: bool) -> Option<GroupInfo> {
    let line = String::from_utf8_lossy(php_trim(line)).into_owned();
    let mut parts = line.splitn(4, ' ');
    let group = parts.next()?.to_string();
    let last = parts.next()?.to_string();
    let first = parts.next()?.to_string();
    let posting = parts.next().map(str::to_string);
    if with_posting && posting.is_none() {
        return None;
    }
    Some(GroupInfo {
        group,
        first,
        last,
        posting: posting.filter(|_| with_posting),
    })
}

fn split_crlf(text: &[u8]) -> Vec<Vec<u8>> {
    let mut lines = Vec::new();
    let mut start = 0;
    let mut index = 0;
    while index + 1 < text.len() {
        if text[index] == b'\r' && text[index + 1] == b'\n' {
            lines.push(text[start..index].to_vec());
            index += 2;
            start = index;
        } else {
            index += 1;
        }
    }
    lines.push(text[start..].to_vec());
    lines
}

/// PHP's default `trim` set.
pub(crate) fn php_trim(value: &[u8]) -> &[u8] {
    let blank = |byte: &u8| matches!(byte, b' ' | b'\t' | b'\n' | b'\r' | 0 | 0x0b);
    let start = value
        .iter()
        .position(|byte| !blank(byte))
        .unwrap_or(value.len());
    let end = value
        .iter()
        .rposition(|byte| !blank(byte))
        .map_or(start, |last| last + 1);
    &value[start..end]
}

/// Up to three seconds, because a provider that rejects logins at its connection limit needs
/// seconds, not milliseconds, to free a slot.
async fn pause_before_retry(attempt: u32) {
    let milliseconds = u64::from(attempt.max(1)).saturating_mul(400).min(3000);
    tokio::time::sleep(Duration::from_millis(milliseconds)).await;
}

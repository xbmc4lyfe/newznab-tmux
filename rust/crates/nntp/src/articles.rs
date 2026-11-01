//! Fetching article bodies. Each entry point exists because a caller needs different
//! guarantees: tolerant reads for post-processing, all-or-nothing bounded reads, and raw
//! bounded reads for verification and fingerprinting.

use async_trait::async_trait;
use nntmux_yenc::{
    ArticleFrame, YencPart, decode, decode_lenient, decode_with_frame, has_validated_integrity,
};

use crate::client::NntpClient;
use crate::error::NntpError;

/// Asked before and during reads. Returning false means the caller's lock is gone: the read
/// stops and its bytes are thrown away.
pub type OnRead<'a> = &'a mut (dyn FnMut() -> bool + Send);

/// Largest concatenation before a multi-article read returns what it has.
const MAX_CONCATENATED_BYTES: u64 = 1_700_000_000;

#[derive(Debug, Clone, PartialEq, Eq)]
pub enum Identifier {
    MessageId(String),
    Number(i64),
}

impl Identifier {
    /// A number when the text is all digits, otherwise a Message-ID.
    pub fn parse(text: &str) -> Self {
        if !text.is_empty() && text.bytes().all(|byte| byte.is_ascii_digit()) {
            text.parse()
                .map_or_else(|_| Self::MessageId(text.to_string()), Self::Number)
        } else {
            Self::MessageId(text.to_string())
        }
    }

    fn wire_form(&self) -> String {
        match self {
            Self::MessageId(id) => format_message_id(id),
            Self::Number(number) => number.to_string(),
        }
    }
}

/// Adds the brackets a Message-ID needs on the wire. Empty stays empty.
fn format_message_id(id: &str) -> String {
    if id.is_empty() {
        return String::new();
    }
    let mut formatted = String::with_capacity(id.len() + 2);
    if !id.starts_with('<') {
        formatted.push('<');
    }
    formatted.push_str(id);
    if !id.ends_with('>') {
        formatted.push('>');
    }
    formatted
}

/// Validates a verification request's Message-ID and returns it without brackets.
pub fn verification_message_id(message_id: &str, maximum_bytes: i64) -> Result<&str, NntpError> {
    let id = message_id.trim_matches(['<', '>']);
    let valid = !id.is_empty()
        && id
            .bytes()
            .all(|byte| !matches!(byte, 0..=0x20 | b'<' | b'>' | 0x7f));
    if maximum_bytes < 1 || !valid {
        return Err(NntpError::Invalid(
            "Invalid verification article request.".into(),
        ));
    }
    Ok(id)
}

#[derive(Debug, Clone, PartialEq, Eq)]
pub struct ArticleData {
    pub data: Vec<u8>,
    pub part: Option<YencPart>,
}

/// One complete article or nothing: a failure never exposes a prefix.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct BoundedArticleResult {
    pub body: Option<Vec<u8>>,
    pub part: Option<YencPart>,
    pub error: Option<String>,
    pub oversized: bool,
}

impl BoundedArticleResult {
    fn success(body: Vec<u8>, part: Option<YencPart>) -> Self {
        Self {
            body: Some(body),
            part,
            error: None,
            oversized: false,
        }
    }

    fn failure(error: impl Into<String>, oversized: bool) -> Self {
        Self {
            body: None,
            part: None,
            error: Some(error.into()),
            oversized,
        }
    }
}

#[derive(Debug, Clone, PartialEq, Eq)]
pub struct BoundedPreview {
    pub data: Option<Vec<u8>>,
    pub bytes_downloaded: i64,
    pub error: Option<String>,
}

/// What a command's status line said, as fingerprints record it.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct ResponseMetadata {
    pub response_code: Option<u16>,
    pub response_text: Option<Vec<u8>>,
    pub response_text_basis: &'static str,
    pub error_type: Option<String>,
}

#[derive(Debug, Clone, PartialEq, Eq)]
pub struct CommandResult {
    pub command: &'static str,
    pub attempted: bool,
    pub response_code: Option<u16>,
    pub available: bool,
    pub error_type: Option<String>,
}

#[derive(Debug, Clone, PartialEq, Eq)]
pub struct FingerprintArticle {
    pub headers: Vec<Vec<u8>>,
    pub body: Vec<u8>,
    pub head_response: ResponseMetadata,
    pub body_response: ResponseMetadata,
    pub overview_response_code: Option<u16>,
    pub overview_raw: Option<Vec<u8>>,
    pub overview_rows: Vec<Vec<u8>>,
    pub overview_attempted: bool,
    pub command_results: Vec<CommandResult>,
}

#[derive(Debug, Clone, PartialEq, Eq)]
pub struct FingerprintFullArticle {
    pub headers: Vec<Vec<u8>>,
    pub body: Vec<u8>,
    pub article_response: ResponseMetadata,
}

#[derive(Debug, Clone, PartialEq, Eq)]
pub struct ServerProfile {
    pub capabilities_response_code: Option<u16>,
    pub capabilities_error_type: Option<String>,
    pub capabilities_raw: Vec<Vec<u8>>,
    pub implementation: Option<Vec<u8>>,
    pub overview_format_response_code: Option<u16>,
    pub overview_format_error_type: Option<String>,
    pub overview_format_raw: Vec<Vec<u8>>,
    pub profiled_at_utc: String,
}

/// Lets a multi-article read use an article the caller already holds, at its original
/// position in the sequence.
#[async_trait]
pub trait MessageReuse: Send {
    async fn lookup(&mut self, identifier: &str) -> Option<Vec<u8>>;
    async fn reused(&mut self, bytes: usize);
}

fn response_metadata(client: &NntpClient, response: &Result<u16, NntpError>) -> ResponseMetadata {
    let (response_code, response_text, error_type) = match response {
        Ok(code) => (Some(*code), Some(client.status.1.clone()), None),
        Err(_) => (None, None, Some("Error".to_string())),
    };
    ResponseMetadata {
        response_code,
        response_text,
        response_text_basis: "net_nntp_current_status_response_trimmed_remainder",
        error_type,
    }
}

impl NntpClient {
    /// Reads one dot-terminated body, undoing dot stuffing exactly once. Only `.\r\n` ends it.
    async fn read_raw_body(
        &mut self,
        mut on_read: Option<OnRead<'_>>,
    ) -> Result<Vec<u8>, NntpError> {
        let mut body = Vec::new();
        let mut line = Vec::new();
        loop {
            let Some(transport) = self.transport.as_mut() else {
                return Err(NntpError::transport("End of stream! Connection lost?"));
            };
            if !transport.fgets_into(&mut line, 8192).await {
                let message = if transport.timed_out {
                    "Failed to read line from socket."
                } else {
                    "End of stream! Connection lost?"
                };
                self.do_quit();
                return Err(NntpError::transport(message));
            }
            if let Some(on_read) = on_read.as_mut()
                && !on_read()
            {
                self.do_quit();
                return Err(NntpError::transport(
                    "NNTP article read stopped after processing lock ownership was lost.",
                ));
            }
            if !line.ends_with(b"\n") {
                continue;
            }
            if line == b".\r\n" {
                self.response_pending = false;
                return Ok(body);
            }
            let start = usize::from(line.starts_with(b".."));
            body.extend_from_slice(&line[start..]);
            line.clear();
        }
    }

    async fn message_in_group(
        &mut self,
        group: &str,
        identifier: &Identifier,
        on_read: Option<OnRead<'_>>,
    ) -> Result<Vec<u8>, NntpError> {
        // A failed article may have closed the session, with the group still recorded.
        self.check_connection(true).await?;
        if self.current_group() != group {
            self.select_group(group, false).await?;
        }
        let code = self
            .send_command(&format!("BODY {}", identifier.wire_form()))
            .await?;
        if code != 222 {
            return Err(self.response_error(code));
        }
        let body = self.read_raw_body(on_read).await?;
        Ok(decode_lenient(&body).into_owned())
    }

    /// Downloads article bodies in a group and joins them. When a later article fails, returns
    /// what it already has, falling back to the other provider first when `alternate` is set.
    pub async fn get_messages(
        &mut self,
        group: &str,
        identifiers: &[Identifier],
        alternate: bool,
        mut on_read: Option<OnRead<'_>>,
    ) -> Result<Vec<u8>, NntpError> {
        self.check_connection(true).await?;
        let mut ownership_lost = false;
        let mut body = Vec::new();
        let mut other = None;
        let mut message_size = 0u64;
        for (index, wanted) in identifiers.iter().enumerate() {
            if (index as u64 + 1).saturating_mul(message_size) >= MAX_CONCATENATED_BYTES {
                return Ok(body);
            }
            let mut heartbeat = guard(&mut on_read, &mut ownership_lost);
            let message = self
                .message_in_group(
                    group,
                    wanted,
                    heartbeat.as_mut().map(|read| read as OnRead<'_>),
                )
                .await;
            drop(heartbeat);
            if ownership_lost {
                return message;
            }
            match message {
                Ok(message) => {
                    if message_size == 0 {
                        message_size = message.len() as u64;
                    }
                    body.extend_from_slice(&message);
                }
                Err(failure) if alternate => {
                    if other.is_none() {
                        let mut client = self.alternate_client();
                        if self.connect_other_provider(&mut client).await.is_ok() {
                            other = Some(client);
                        }
                    }
                    let Some(client) = other.as_mut() else {
                        // PHP moved on to the next article when the alternate couldn't connect.
                        let _ = failure;
                        continue;
                    };
                    let mut heartbeat = guard(&mut on_read, &mut ownership_lost);
                    let retried = client
                        .message_in_group(
                            group,
                            wanted,
                            heartbeat.as_mut().map(|read| read as OnRead<'_>),
                        )
                        .await;
                    drop(heartbeat);
                    match retried {
                        Ok(message) => body.extend_from_slice(&message),
                        Err(failure) => {
                            if !ownership_lost && !body.is_empty() {
                                return Ok(body);
                            }
                            return Err(failure);
                        }
                    }
                }
                Err(failure) => {
                    return if body.is_empty() {
                        Err(failure)
                    } else {
                        Ok(body)
                    };
                }
            }
        }
        Ok(body)
    }

    async fn body_by_message_id(&mut self, identifier: &str) -> Result<Vec<u8>, NntpError> {
        if let Identifier::Number(_) = Identifier::parse(identifier) {
            return Err(NntpError::transport(
                "Numeric article number requires group selection",
            ));
        }
        let code = self
            .send_command(&format!("BODY {}", format_message_id(identifier)))
            .await?;
        if code != 222 {
            return Err(self.response_error(code));
        }
        self.read_raw_body(None).await
    }

    /// Downloads bodies by Message-ID, with no group selected, and joins them decoded.
    pub async fn get_messages_by_message_id(
        &mut self,
        identifiers: &[String],
        alternate: bool,
        mut reuse: Option<&mut dyn MessageReuse>,
    ) -> Result<Vec<u8>, NntpError> {
        self.check_connection(false).await?;
        let mut body = Vec::new();
        let mut other: Option<NntpClient> = None;
        let mut message_size = 0u64;
        for (index, id) in identifiers.iter().enumerate() {
            if (index as u64 + 1).saturating_mul(message_size) >= MAX_CONCATENATED_BYTES {
                return Ok(body);
            }
            let reused = match reuse.as_deref_mut() {
                Some(reuse) => reuse.lookup(id).await,
                None => None,
            };
            let message = if let Some(reused) = reused {
                if let Some(reuse) = reuse.as_deref_mut() {
                    reuse.reused(reused.len()).await;
                }
                Ok(reused)
            } else {
                self.body_by_message_id(id)
                    .await
                    .map(|raw| decode_lenient(&raw).into_owned())
            };
            let failure = match message {
                Ok(message) => {
                    if message_size == 0 {
                        message_size = message.len() as u64;
                    }
                    body.extend_from_slice(&message);
                    continue;
                }
                Err(failure) => failure,
            };
            if !alternate {
                return if body.is_empty() {
                    Err(failure)
                } else {
                    Ok(body)
                };
            }
            if other.is_none() {
                let mut client = self.alternate_client();
                if self.connect_other_provider(&mut client).await.is_err() {
                    return if body.is_empty() {
                        Err(failure)
                    } else {
                        Ok(body)
                    };
                }
                other = Some(client);
            }
            let Some(client) = other.as_mut() else {
                return if body.is_empty() {
                    Err(failure)
                } else {
                    Ok(body)
                };
            };
            match client.body_by_message_id(id).await {
                Ok(raw) => body.extend_from_slice(&decode_lenient(&raw)),
                Err(failure) => {
                    return if body.is_empty() {
                        Err(failure)
                    } else {
                        Ok(body)
                    };
                }
            }
        }
        Ok(body)
    }

    /// One article by Message-ID, decoded, with what its yEnc header says about its file.
    /// `integrity` reports whether the primary provider's copy passed strict validation, which
    /// is what archive mapping needs before it may keep the article.
    pub async fn get_article_by_message_id(
        &mut self,
        identifier: &str,
        alternate: bool,
    ) -> Result<(ArticleData, bool), NntpError> {
        self.check_connection(false)
            .await
            .map_err(|_| NntpError::transport("Unable to connect to usenet!"))?;
        let mut from_primary = !self.using_alternate;
        let raw = match self.body_by_message_id(identifier).await {
            Ok(raw) => raw,
            Err(failure) if alternate => {
                from_primary = false;
                let mut client = self.alternate_client();
                if self.connect_other_provider(&mut client).await.is_err() {
                    return Err(failure);
                }
                client.body_by_message_id(identifier).await?
            }
            Err(failure) => return Err(failure),
        };
        let (data, part) = decode_with_frame(&raw);
        let data = data.into_owned();
        let validated = from_primary && part.is_some() && has_validated_integrity(&raw, &data);
        Ok((ArticleData { data, part }, validated))
    }

    /// One complete article with a decoded byte ceiling that ignores the yEnc file size. The
    /// other provider is tried once, except for an oversized article or a lost lock.
    pub async fn get_message_bounded(
        &mut self,
        group: &str,
        message_id: &str,
        max_decoded_bytes: i64,
        alternate: bool,
        mut on_read: Option<OnRead<'_>>,
    ) -> Result<BoundedArticleResult, NntpError> {
        if !(1..=(i64::MAX - 65_538) / 5).contains(&max_decoded_bytes) {
            return Err(NntpError::Invalid(
                "The decoded article ceiling must be positive and permit a bounded wire ceiling."
                    .into(),
            ));
        }
        let identifier = format_message_id(message_id);
        if identifier.is_empty()
            || identifier.len() > 505
            || identifier
                .bytes()
                .any(|byte| matches!(byte, 0..=0x20 | 0x7f))
        {
            return Ok(BoundedArticleResult::failure(
                "Invalid article Message-ID.",
                false,
            ));
        }
        let mut ownership_lost = false;
        let mut heartbeat = guard(&mut on_read, &mut ownership_lost);
        let result = self
            .fetch_bounded(
                group,
                &identifier,
                max_decoded_bytes,
                heartbeat.as_mut().map(|read| read as OnRead<'_>),
            )
            .await;
        drop(heartbeat);
        if result.body.is_some() || result.oversized || ownership_lost || !alternate {
            return Ok(result);
        }
        let mut client = self.alternate_client();
        if self.connect_other_provider(&mut client).await.is_err() {
            return Ok(BoundedArticleResult::failure(
                "The alternate article provider could not connect.",
                false,
            ));
        }
        let mut heartbeat = guard(&mut on_read, &mut ownership_lost);
        Ok(client
            .fetch_bounded(
                group,
                &identifier,
                max_decoded_bytes,
                heartbeat.as_mut().map(|read| read as OnRead<'_>),
            )
            .await)
    }

    async fn fetch_bounded(
        &mut self,
        group: &str,
        identifier: &str,
        max_decoded_bytes: i64,
        on_read: Option<OnRead<'_>>,
    ) -> BoundedArticleResult {
        if self.check_connection(false).await.is_err() {
            self.do_quit();
            return BoundedArticleResult::failure("The article provider could not connect.", false);
        }
        if self.select_group(group, false).await.is_err() {
            self.do_quit();
            return BoundedArticleResult::failure(
                "The article group could not be selected.",
                false,
            );
        }
        let response = self
            .send_bounded_status(&format!("BODY {identifier}"))
            .await;
        if response != Some(222) {
            // A complete 430 has no BODY, so the session and group stay usable.
            if response != Some(430) {
                self.do_quit();
            }
            return BoundedArticleResult::failure(
                "The article provider did not return a BODY.",
                false,
            );
        }
        self.read_bounded_article(max_decoded_bytes, on_read).await
    }

    /// Validates the status line strictly before a missing article may reuse the socket.
    async fn send_bounded_status(&mut self, command: &str) -> Option<u16> {
        self.write_command(command).await.ok()?;
        let line = self.transport.as_mut()?.fgets(513).await?;
        let text = line.strip_suffix(b"\r\n")?;
        let valid = text.len() >= 3
            && matches!(text[0], b'1'..=b'5')
            && text[1..3].iter().all(u8::is_ascii_digit)
            && (text.len() == 3
                || (text[3] == b' '
                    && text[4..]
                        .iter()
                        .all(|byte| matches!(byte, 0x09 | 0x20..=0x7e | 0x80..=0xff))));
        if !valid {
            return None;
        }
        let code = std::str::from_utf8(&text[..3]).ok()?.parse().ok()?;
        self.status = (code, text.get(4..).unwrap_or_default().to_vec());
        self.response_pending = code == 222;
        Some(code)
    }

    /// The wire ceiling covers framing, line endings, stuffing and the terminator: five bytes
    /// per decoded byte covers escaped one-byte lines, and 64 KiB covers control lines.
    async fn read_bounded_article(
        &mut self,
        max_decoded_bytes: i64,
        mut on_read: Option<OnRead<'_>>,
    ) -> BoundedArticleResult {
        let max_wire = 5 * max_decoded_bytes + 65_536;
        let mut received = 0i64;
        let mut body = Vec::new();
        let mut line = Vec::new();
        loop {
            let limit = usize::try_from((max_wire - received + 2).min(8192)).unwrap_or(8192);
            let Some(fragment) = (match self.transport.as_mut() {
                Some(transport) => transport.fgets(limit).await,
                None => None,
            }) else {
                self.do_quit();
                return BoundedArticleResult::failure(
                    "Article ended before the BODY terminator.",
                    false,
                );
            };
            if let Some(on_read) = on_read.as_mut()
                && !on_read()
            {
                self.do_quit();
                return BoundedArticleResult::failure(
                    "Article read stopped after processing lock ownership was lost.",
                    false,
                );
            }
            received += fragment.len() as i64;
            if received > max_wire {
                self.do_quit();
                return BoundedArticleResult::failure("Article wire byte ceiling exceeded.", true);
            }
            line.extend_from_slice(&fragment);
            if !line.ends_with(b"\n") {
                continue;
            }
            if line == b".\r\n" {
                self.response_pending = false;
                return match decode_bounded_article(&body, max_decoded_bytes) {
                    Ok(result) => result,
                    Err(failure) => {
                        self.do_quit();
                        BoundedArticleResult::failure(
                            format!("The article could not be read: {failure}"),
                            false,
                        )
                    }
                };
            }
            let start = usize::from(line.starts_with(b".."));
            body.extend_from_slice(&line[start..]);
            line.clear();
        }
    }

    /// One bounded, undecoded article for integrity verification.
    pub async fn verification_article(
        &mut self,
        message_id: &str,
        maximum_bytes: i64,
    ) -> Result<(Vec<u8>, ResponseMetadata), NntpError> {
        let id = verification_message_id(message_id, maximum_bytes)?.to_string();
        let response = self.send_command(&format!("BODY <{id}>")).await;
        let metadata = response_metadata(self, &response);
        if response != Ok(222) {
            if response != Ok(430) {
                self.do_quit();
            }
            let message = "Article is unavailable; retry verification later.";
            return Err(if matches!(response, Ok(423 | 430)) {
                NntpError::ArticleMissing(message.into())
            } else {
                NntpError::Transport(message.into())
            });
        }
        Ok((self.read_verification_body(maximum_bytes).await?, metadata))
    }

    /// A bounded, dot-unstuffed body. A partial line proves the article too large when its
    /// bytes already exceed the budget, or when they exactly reach it after filling a
    /// budget-capped read and the line continues at all. Anything else partial is truncated.
    async fn read_verification_body(&mut self, maximum_bytes: i64) -> Result<Vec<u8>, NntpError> {
        let over_budget =
            || NntpError::BudgetExceeded("Article exceeds verification memory budget.".into());
        let mut raw: Vec<u8> = Vec::new();
        loop {
            let remaining = maximum_bytes - raw.len() as i64;
            let limit = (remaining.min(65_534) + 2).max(4);
            let Some(transport) = self.transport.as_mut() else {
                return Err(NntpError::transport("Truncated article response."));
            };
            let Some(mut line) = transport.fgets(usize::try_from(limit).unwrap_or(4)).await else {
                self.do_quit();
                return Err(NntpError::transport("Truncated article response."));
            };
            if line == b".\r\n" || line == b".\n" {
                self.response_pending = false;
                return Ok(raw);
            }
            if !line.ends_with(b"\n") {
                if remaining <= 65_534 && line != b"." && line != b".\r" {
                    let stuffed = i64::from(line.starts_with(b".."));
                    let unstuffed = raw.len() as i64 + line.len() as i64 - stuffed;
                    let fills_read = line.len() as i64 == limit - 1;
                    if unstuffed > maximum_bytes
                        || (unstuffed == maximum_bytes && fills_read && transport.has_more().await)
                    {
                        self.do_quit();
                        return Err(over_budget());
                    }
                }
                self.do_quit();
                return Err(NntpError::transport(
                    "Verification article line is truncated or oversized.",
                ));
            }
            if line.starts_with(b"..") {
                line.remove(0);
            }
            if raw.len() as i64 + line.len() as i64 > maximum_bytes {
                self.do_quit();
                return Err(over_budget());
            }
            raw.extend_from_slice(&line);
        }
    }

    /// Raw HEAD fields and a bounded raw BODY for a known article. With `alternate` the other
    /// provider is queried directly, not just as a fallback.
    pub async fn get_fingerprint_article(
        &mut self,
        identifier: &str,
        maximum_body_bytes: i64,
        maximum_header_bytes: usize,
        alternate: bool,
        include_overview: bool,
    ) -> Result<FingerprintArticle, NntpError> {
        let id = verification_message_id(identifier, maximum_body_bytes)?.to_string();
        if alternate {
            let mut client = self.alternate_client();
            if self.connect_other_provider(&mut client).await.is_err() {
                return Err(unavailable());
            }
            return client
                .fingerprint_article(
                    &id,
                    maximum_body_bytes,
                    maximum_header_bytes,
                    include_overview,
                )
                .await;
        }
        if self.check_connection(false).await.is_err() {
            return Err(unavailable());
        }
        self.fingerprint_article(
            &id,
            maximum_body_bytes,
            maximum_header_bytes,
            include_overview,
        )
        .await
    }

    async fn fingerprint_article(
        &mut self,
        id: &str,
        maximum_body_bytes: i64,
        maximum_header_bytes: usize,
        include_overview: bool,
    ) -> Result<FingerprintArticle, NntpError> {
        let mut overview_code = None;
        let mut overview_error = None;
        let mut overview_rows = Vec::new();
        if include_overview {
            match self.send_command(&format!("OVER <{id}>")).await {
                Ok(code) => {
                    overview_code = Some(code);
                    if code == 224 {
                        overview_rows = self.read_fingerprint_multiline(32_768).await?;
                    }
                }
                Err(_) => overview_error = Some("Error".to_string()),
            }
        }
        let response = self.send_command(&format!("HEAD <{id}>")).await;
        let head_response = response_metadata(self, &response);
        if response != Ok(221) {
            let message = "Article headers are unavailable.";
            return Err(if matches!(response, Ok(423 | 430)) {
                NntpError::ArticleMissing(message.into())
            } else {
                NntpError::Transport(message.into())
            });
        }
        let (headers, _) = self
            .read_fingerprint_header_lines(maximum_header_bytes, false)
            .await?;
        let (body, body_response) = self.verification_article(id, maximum_body_bytes).await?;
        let mut command_results = Vec::new();
        if include_overview {
            command_results.push(CommandResult {
                command: "OVER MSGID",
                attempted: true,
                response_code: overview_code,
                available: overview_code == Some(224),
                error_type: overview_error,
            });
        }
        command_results.push(CommandResult {
            command: "HEAD",
            attempted: true,
            response_code: head_response.response_code,
            available: head_response.response_code == Some(221),
            error_type: head_response.error_type.clone(),
        });
        command_results.push(CommandResult {
            command: "BODY",
            attempted: true,
            response_code: body_response.response_code,
            available: body_response.response_code == Some(222),
            error_type: body_response.error_type.clone(),
        });
        Ok(FingerprintArticle {
            headers,
            body,
            head_response,
            body_response,
            overview_response_code: overview_code,
            overview_raw: overview_rows.first().cloned(),
            overview_rows,
            overview_attempted: include_overview,
            command_results,
        })
    }

    /// The same bytes as HEAD plus BODY, in one ARTICLE request, saving a round trip.
    pub async fn get_fingerprint_full_article(
        &mut self,
        identifier: &str,
        maximum_body_bytes: i64,
        maximum_header_bytes: usize,
        alternate: bool,
    ) -> Result<FingerprintFullArticle, NntpError> {
        let id = verification_message_id(identifier, maximum_body_bytes)?.to_string();
        if alternate {
            let mut client = self.alternate_client();
            if self.connect_other_provider(&mut client).await.is_err() {
                return Err(unavailable());
            }
            return client
                .fingerprint_full_article(&id, maximum_body_bytes, maximum_header_bytes)
                .await;
        }
        if self.check_connection(false).await.is_err() {
            return Err(unavailable());
        }
        self.fingerprint_full_article(&id, maximum_body_bytes, maximum_header_bytes)
            .await
    }

    async fn fingerprint_full_article(
        &mut self,
        id: &str,
        maximum_body_bytes: i64,
        maximum_header_bytes: usize,
    ) -> Result<FingerprintFullArticle, NntpError> {
        let response = self.send_command(&format!("ARTICLE <{id}>")).await;
        let article_response = response_metadata(self, &response);
        if response != Ok(220) {
            // Only a plain "no such article" leaves the connection in a known state.
            if response != Ok(430) {
                self.do_quit();
            }
            return Err(if matches!(response, Ok(423 | 430)) {
                NntpError::ArticleMissing("Article is unavailable.".into())
            } else {
                NntpError::Transport("Article is unavailable.".into())
            });
        }
        let (headers, terminated) = self
            .read_fingerprint_header_lines(maximum_header_bytes, true)
            .await?;
        let body = if terminated {
            Vec::new()
        } else {
            self.read_verification_body(maximum_body_bytes).await?
        };
        Ok(FingerprintFullArticle {
            headers,
            body,
            article_response,
        })
    }

    /// The responding server's advertised capabilities and overview schema.
    pub async fn get_fingerprint_server_profile(
        &mut self,
        alternate: bool,
    ) -> Result<ServerProfile, NntpError> {
        if alternate {
            let mut client = self.alternate_client();
            if client
                .do_connect(self.config.compressed_headers, true)
                .await
                .is_err()
            {
                return Err(unavailable());
            }
            return client.server_profile().await;
        }
        if self.check_connection(false).await.is_err() {
            return Err(unavailable());
        }
        self.server_profile().await
    }

    async fn server_profile(&mut self) -> Result<ServerProfile, NntpError> {
        let capabilities_response = self.send_command("CAPABILITIES").await;
        let capabilities = if capabilities_response == Ok(101) {
            self.read_fingerprint_multiline(65_536).await?
        } else {
            Vec::new()
        };
        let starts_with = |line: &[u8], prefix: &[u8]| {
            line.len() >= prefix.len() && line[..prefix.len()].eq_ignore_ascii_case(prefix)
        };
        let mut overview_response = None;
        let mut overview_format = Vec::new();
        if capabilities.iter().any(|line| starts_with(line, b"OVER")) {
            let response = self.send_command("LIST OVERVIEW.FMT").await;
            if response == Ok(215) {
                overview_format = self.read_fingerprint_multiline(32_768).await?;
            }
            overview_response = Some(response);
        }
        Ok(ServerProfile {
            capabilities_response_code: capabilities_response.as_ref().ok().copied(),
            capabilities_error_type: capabilities_response
                .as_ref()
                .err()
                .map(|_| "Error".to_string()),
            implementation: capabilities
                .iter()
                .find(|line| starts_with(line, b"IMPLEMENTATION "))
                .cloned(),
            capabilities_raw: capabilities,
            overview_format_response_code: overview_response
                .as_ref()
                .and_then(|response| response.as_ref().ok().copied()),
            overview_format_error_type: overview_response
                .as_ref()
                .and_then(|response| response.as_ref().err().map(|_| "Error".to_string())),
            overview_format_raw: overview_format,
            profiled_at_utc: chrono::Utc::now()
                .format("%Y-%m-%dT%H:%M:%S+00:00")
                .to_string(),
        })
    }

    async fn read_fingerprint_multiline(
        &mut self,
        maximum_bytes: usize,
    ) -> Result<Vec<Vec<u8>>, NntpError> {
        let mut lines = Vec::new();
        let mut bytes = 0;
        loop {
            let Some(mut line) = (match self.transport.as_mut() {
                Some(transport) => transport.fgets(16_385).await,
                None => None,
            }) else {
                self.do_quit();
                return Err(NntpError::transport(
                    "NNTP server profile response was truncated.",
                ));
            };
            if line == b".\r\n" || line == b".\n" {
                self.response_pending = false;
                return Ok(lines);
            }
            if !line.ends_with(b"\n") {
                self.do_quit();
                return Err(NntpError::transport(
                    "NNTP server profile line is truncated or oversized.",
                ));
            }
            if line.starts_with(b"..") {
                line.remove(0);
            }
            bytes += line.len();
            if bytes > maximum_bytes {
                self.do_quit();
                return Err(NntpError::transport(
                    "NNTP server profile exceeds the metadata memory budget.",
                ));
            }
            while matches!(line.last(), Some(b'\r' | b'\n')) {
                line.pop();
            }
            lines.push(line);
        }
    }

    /// HEAD ends at its terminator, and an ARTICLE header section at the blank line. The flag
    /// says the terminator came first, so no body follows.
    async fn read_fingerprint_header_lines(
        &mut self,
        maximum_bytes: usize,
        until_separator: bool,
    ) -> Result<(Vec<Vec<u8>>, bool), NntpError> {
        let mut headers = Vec::new();
        let mut bytes = 0;
        loop {
            let Some(mut line) = (match self.transport.as_mut() {
                Some(transport) => transport.fgets(16_385).await,
                None => None,
            }) else {
                self.do_quit();
                return Err(NntpError::transport(
                    "Article header response was truncated.",
                ));
            };
            if line == b".\r\n" || line == b".\n" {
                self.response_pending = false;
                return Ok((headers, true));
            }
            if until_separator && (line == b"\r\n" || line == b"\n") {
                return Ok((headers, false));
            }
            if !line.ends_with(b"\n") {
                self.do_quit();
                return Err(NntpError::transport(
                    "Article header line is truncated or oversized.",
                ));
            }
            if line.starts_with(b"..") {
                line.remove(0);
            }
            bytes += line.len();
            if bytes > maximum_bytes {
                self.do_quit();
                return Err(NntpError::transport(
                    "Article headers exceed the metadata memory budget.",
                ));
            }
            headers.push(line);
        }
    }

    /// One complete preview article, counting every BODY byte read, failures included. The
    /// primary and optional alternate share the allowance.
    pub async fn get_bounded_article_by_message_id(
        &mut self,
        identifier: &str,
        maximum_bytes: i64,
        alternate: bool,
    ) -> BoundedPreview {
        let id = match verification_message_id(identifier, maximum_bytes) {
            Ok(id) => id.to_string(),
            Err(failure) => {
                return BoundedPreview {
                    data: None,
                    bytes_downloaded: 0,
                    error: Some(failure.to_string()),
                };
            }
        };
        let incomplete = "Preview article unavailable or incomplete.";
        let mut bytes = 0i64;
        let mut error = None;
        let mut raw = None;
        if self.check_connection(false).await.is_ok() {
            raw = self
                .read_bounded_preview(&id, maximum_bytes, &mut bytes)
                .await;
        }
        if raw.is_none() {
            error = Some(incomplete.to_string());
        }
        if raw.is_none() && alternate && bytes < maximum_bytes {
            let mut client = self.alternate_client();
            if self.connect_other_provider(&mut client).await.is_ok() {
                raw = client
                    .read_bounded_preview(&id, maximum_bytes, &mut bytes)
                    .await;
            }
        }
        match raw {
            Some(raw) => BoundedPreview {
                data: Some(decode_with_frame(&raw).0.into_owned()),
                bytes_downloaded: bytes,
                error: None,
            },
            None => BoundedPreview {
                data: None,
                bytes_downloaded: bytes,
                error: error.or_else(|| Some("Article unavailable.".to_string())),
            },
        }
    }

    async fn read_bounded_preview(
        &mut self,
        id: &str,
        maximum_bytes: i64,
        bytes: &mut i64,
    ) -> Option<Vec<u8>> {
        if self.send_command(&format!("BODY <{id}>")).await != Ok(222) {
            return None;
        }
        let mut raw = Vec::new();
        while *bytes < maximum_bytes {
            let limit = usize::try_from((maximum_bytes - *bytes + 1).min(65_536)).unwrap_or(65_536);
            let Some(line) = (match self.transport.as_mut() {
                Some(transport) => transport.fgets(limit).await,
                None => None,
            }) else {
                self.do_quit();
                return None;
            };
            *bytes += line.len() as i64;
            if line == b".\r\n" || line == b".\n" {
                self.response_pending = false;
                return Some(raw);
            }
            if !line.ends_with(b"\n") {
                self.do_quit();
                return None;
            }
            let start = usize::from(line.starts_with(b".."));
            raw.extend_from_slice(&line[start..]);
        }
        self.do_quit();
        None
    }
}

fn unavailable() -> NntpError {
    NntpError::ProviderUnavailable("Unable to connect to the requested NNTP provider.".into())
}

/// Wraps a caller's heartbeat so a lost lock stays lost, across providers.
fn guard<'a>(
    on_read: &'a mut Option<OnRead<'_>>,
    ownership_lost: &'a mut bool,
) -> Option<impl FnMut() -> bool + Send + 'a> {
    let on_read = on_read.as_mut()?;
    Some(move || {
        *ownership_lost = *ownership_lost || !on_read();
        !*ownership_lost
    })
}

/// Case-insensitive match for a yEnc control keyword at the start of a line, ending there.
fn control_line_positions<'a>(
    body: &'a [u8],
    keywords: &'a [&'a [u8]],
) -> impl Iterator<Item = usize> + 'a {
    let starts = std::iter::once(0).chain(
        body.iter()
            .enumerate()
            .filter(|(_, byte)| **byte == b'\n')
            .map(|(index, _)| index + 1),
    );
    starts.filter(move |start| {
        keywords.iter().any(|keyword| {
            let end = start + keyword.len();
            end <= body.len()
                && body[*start..end].eq_ignore_ascii_case(keyword)
                && body
                    .get(end)
                    .is_none_or(|byte| matches!(byte, b' ' | b'\t' | b'\r' | b'\n'))
        })
    })
}

fn php_int(value: &[u8]) -> i64 {
    let digits = value.iter().take_while(|byte| byte.is_ascii_digit());
    digits.fold(0i64, |total, byte| {
        total
            .saturating_mul(10)
            .saturating_add(i64::from(byte - b'0'))
    })
}

/// Counts actual decoded bytes before the decoder allocates, ignoring claimed sizes.
fn decode_bounded_article(
    body: &[u8],
    max_decoded_bytes: i64,
) -> Result<BoundedArticleResult, nntmux_yenc::YencError> {
    let Some(frame) = ArticleFrame::parse(body, true) else {
        if control_line_positions(body, &[b"=ybegin", b"=ypart", b"=yend"])
            .next()
            .is_some()
        {
            return Ok(BoundedArticleResult::failure(
                "Incomplete or invalid yEnc article framing.",
                false,
            ));
        }
        if body.len() as i64 > max_decoded_bytes {
            return Ok(BoundedArticleResult::failure(
                "Article decoded byte ceiling exceeded.",
                true,
            ));
        }
        return Ok(BoundedArticleResult::success(body.to_vec(), None));
    };
    if control_line_positions(body, &[b"=ybegin"]).count() != 1 {
        return Ok(BoundedArticleResult::failure(
            "Multiple yEnc frames in one article.",
            false,
        ));
    }
    let mut decoded_bytes = 0i64;
    let mut escaped = false;
    for byte in frame.payload(body) {
        if matches!(byte, b'\r' | b'\n') {
            continue;
        }
        if !escaped && *byte == b'=' {
            escaped = true;
            continue;
        }
        escaped = false;
        decoded_bytes += 1;
        if decoded_bytes > max_decoded_bytes {
            return Ok(BoundedArticleResult::failure(
                "Article decoded byte ceiling exceeded.",
                true,
            ));
        }
    }
    let decoded = match decode(body)? {
        Some(decoded) if decoded.len() as i64 == decoded_bytes => decoded,
        _ => {
            return Ok(BoundedArticleResult::failure(
                "Article yEnc decoding failed.",
                false,
            ));
        }
    };
    let range = frame.part.as_ref();
    let part = YencPart {
        name: frame.header.get("name").unwrap_or_default().to_vec(),
        size: php_int(frame.header.get("size").unwrap_or_default()),
        part: frame.header.get("part").map(php_int),
        begin: range.and_then(|fields| fields.get("begin")).map(php_int),
        end: range.and_then(|fields| fields.get("end")).map(php_int),
    };
    Ok(BoundedArticleResult::success(decoded, Some(part)))
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn message_ids_get_brackets_once() {
        assert_eq!(format_message_id("a@b"), "<a@b>");
        assert_eq!(format_message_id("<a@b>"), "<a@b>");
        assert_eq!(format_message_id("<a@b"), "<a@b>");
        assert_eq!(format_message_id(""), "");
    }

    #[test]
    fn verification_ids_reject_what_could_inject_a_command() {
        assert_eq!(verification_message_id("<<a@b>>", 1).unwrap(), "a@b");
        for bad in ["", "<>", "a b", "a\r\nQUIT", "a<b", "a\x7fb", "a\x01b"] {
            assert!(verification_message_id(bad, 1).is_err(), "{bad:?}");
        }
        assert!(verification_message_id("a@b", 0).is_err());
    }

    #[test]
    fn identifiers_are_numbers_only_when_all_digits() {
        assert_eq!(Identifier::parse("123"), Identifier::Number(123));
        assert_eq!(
            Identifier::parse("12a"),
            Identifier::MessageId("12a".into())
        );
    }

    #[test]
    fn plain_bodies_pass_through_up_to_the_ceiling() {
        let result = decode_bounded_article(b"plain", 5).unwrap();
        assert_eq!(result.body.as_deref(), Some(&b"plain"[..]));
        assert!(decode_bounded_article(b"plain", 4).unwrap().oversized);
    }

    #[test]
    fn broken_framing_returns_no_prefix() {
        let result = decode_bounded_article(b"text\n=ybegin line=128\nmore", 100).unwrap();
        assert_eq!(
            result.error.as_deref(),
            Some("Incomplete or invalid yEnc article framing.")
        );
        assert!(result.body.is_none());
    }

    #[test]
    fn a_second_frame_is_refused() {
        let article = b"=ybegin line=128 size=3 name=x\nklm\n=yend size=3\n=ybegin line=128 size=3 name=y\nklm\n=yend size=3\n";
        let result = decode_bounded_article(article, 100).unwrap();
        assert_eq!(
            result.error.as_deref(),
            Some("Multiple yEnc frames in one article.")
        );
    }

    #[test]
    fn the_decoded_ceiling_ignores_the_declared_size() {
        let article = b"=ybegin line=128 size=1 name=x\nklm\n=yend size=1\n";
        assert!(decode_bounded_article(article, 2).unwrap().oversized);
    }

    #[test]
    fn the_part_name_keeps_its_literal_bytes() {
        let article = b"=ybegin line=128 size=3 name= \xff name \r\nklm\r\n=yend size=3\r\n";
        let result = decode_bounded_article(article, 100).unwrap();
        assert_eq!(result.part.unwrap().name, b" \xff name ");
    }
}

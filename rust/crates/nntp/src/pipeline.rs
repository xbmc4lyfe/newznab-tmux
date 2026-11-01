//! Pipelined BODY or STAT requests drained from several connections at once. Every response
//! stays attached to its request position, and a damaged frame closes only its connection.

use std::collections::HashMap;
use std::sync::atomic::{AtomicUsize, Ordering};
use std::time::Duration;

use futures_util::future::join_all;
use tokio::time::{MissedTickBehavior, interval};

use crate::articles::verification_message_id;
use crate::client::NntpClient;
use crate::error::NntpError;
use crate::transport::Transport;

const MAX_LINE: usize = 65_536;
const MAX_CONNECTIONS: usize = 8;
const MAX_REQUESTS_PER_CONNECTION: usize = 16;

#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum Mode {
    Body,
    Stat,
}

pub struct Batch<'a> {
    pub client: &'a mut NntpClient,
    pub ids: Vec<String>,
}

pub type Results = HashMap<String, Result<Vec<u8>, NntpError>>;

enum Outcome {
    Article { index: usize, body: Vec<u8> },
    Missing { index: usize },
}

/// Response framing for one connection. Bytes go in as they arrive.
struct Framer<'a> {
    ids: &'a [String],
    mode: Mode,
    position: usize,
    in_body: bool,
    line: Vec<u8>,
    line_charged: usize,
    body: Vec<u8>,
    error: Option<&'static str>,
    maximum_bytes: usize,
    buffer_bytes: usize,
    used: &'a AtomicUsize,
}

impl Framer<'_> {
    fn done(&self) -> bool {
        self.error.is_some() || self.position == self.ids.len()
    }

    fn fail(&mut self, error: &'static str) {
        self.error = Some(error);
        self.line = Vec::new();
        self.body = Vec::new();
        self.line_charged = 0;
    }

    /// Charges accepted bytes against the budget all connections share. Returning a body
    /// never refunds it, and a failed partial body stays charged.
    fn charge(&self, amount: usize) -> bool {
        let mut used = self.used.load(Ordering::Relaxed);
        loop {
            let Some(total) = used
                .checked_add(amount)
                .filter(|total| *total <= self.buffer_bytes)
            else {
                return false;
            };
            match self
                .used
                .compare_exchange_weak(used, total, Ordering::Relaxed, Ordering::Relaxed)
            {
                Ok(_) => return true,
                Err(current) => used = current,
            }
        }
    }

    fn feed(&mut self, mut input: &[u8], outcomes: &mut Vec<Outcome>) {
        while !input.is_empty() && self.error.is_none() {
            if self.done() {
                self.fail("Unexpected data after verification pipeline");
                break;
            }
            let end = input.iter().position(|&byte| byte == b'\n');
            let take = end.map_or(input.len(), |end| end + 1);
            let length = self.line.len() + take;
            if length > MAX_LINE || (length == MAX_LINE && end.is_none()) {
                // A line this long can't be a dot terminator. Prefer proven article overflow,
                // so retry classification never depends on socket chunking.
                if self.in_body {
                    let mut prefix = self.line.iter().chain(input[..take].iter());
                    let stuffed = prefix.next() == Some(&b'.') && prefix.next() == Some(&b'.');
                    if self.body.len() + length - usize::from(stuffed) > self.maximum_bytes {
                        self.fail("Verification article exceeds byte budget");
                        break;
                    }
                }
                self.fail("Verification line exceeds limit");
                break;
            }
            self.line.extend_from_slice(&input[..take]);
            input = &input[take..];

            if self.in_body {
                // A pending '.' or '.\r' might still become the terminator. Every other byte
                // is already body data, so charge it before accepting another feed.
                let line = self.line.as_slice();
                let terminator = matches!(line, b"." | b".\r" | b".\n" | b".\r\n");
                let contribution = if terminator {
                    0
                } else {
                    line.len() - usize::from(line.starts_with(b".."))
                };
                if self.body.len() + contribution > self.maximum_bytes {
                    self.fail("Verification article exceeds byte budget");
                    break;
                }
                let Some(charge) = contribution.checked_sub(self.line_charged) else {
                    self.fail("Verification pipeline exceeds byte budget");
                    break;
                };
                if !self.charge(charge) {
                    self.fail("Verification pipeline exceeds byte budget");
                    break;
                }
                self.line_charged = contribution;
            }
            if end.is_none() {
                break;
            }
            let mut line = std::mem::take(&mut self.line);
            let outcome = self.process_line(&line, outcomes);
            line.clear();
            self.line = line;
            self.line_charged = 0;
            if let Err(error) = outcome {
                self.fail(error);
            }
        }
    }

    fn process_line(
        &mut self,
        line: &[u8],
        outcomes: &mut Vec<Outcome>,
    ) -> Result<(), &'static str> {
        if self.in_body {
            if !matches!(line, b".\r\n" | b".\n") {
                let payload = if line.starts_with(b"..") {
                    &line[1..]
                } else {
                    line
                };
                if self.body.len() + payload.len() > self.maximum_bytes {
                    return Err("Verification article exceeds byte budget");
                }
                self.body.extend_from_slice(payload);
                return Ok(());
            }
            outcomes.push(Outcome::Article {
                index: self.position,
                body: std::mem::take(&mut self.body),
            });
            self.in_body = false;
            self.position += 1;
            return Ok(());
        }
        let text = line.strip_suffix(b"\n").unwrap_or(line);
        let text = text.strip_suffix(b"\r").unwrap_or(text);
        if text.len() < 3
            || !text[..3].iter().all(u8::is_ascii_digit)
            || (text.len() > 3 && text[3] != b' ')
            || text.iter().any(|&byte| byte < 32 || byte == 127)
        {
            return Err("Malformed verification status");
        }
        match &text[..3] {
            b"430" | b"423" => {
                outcomes.push(Outcome::Missing {
                    index: self.position,
                });
            }
            code if matches!(
                (self.mode, code),
                (Mode::Body, b"222") | (Mode::Stat, b"223")
            ) =>
            {
                let mut fields = text.split(|&byte| byte == b' ').filter(|f| !f.is_empty());
                fields.next();
                let number = fields.next().ok_or("Malformed verification status")?;
                let identity = fields.next().ok_or("Malformed verification status")?;
                let expected = self
                    .ids
                    .get(self.position)
                    .ok_or("Unexpected verification response")?;
                if number.is_empty() || !number.iter().all(u8::is_ascii_digit) {
                    return Err("Malformed verification status");
                }
                if identity
                    .strip_prefix(b"<")
                    .and_then(|id| id.strip_suffix(b">"))
                    != Some(expected.as_bytes())
                {
                    return Err("Verification response identity differs from request");
                }
                if self.mode == Mode::Body {
                    self.in_body = true;
                    return Ok(());
                }
                outcomes.push(Outcome::Article {
                    index: self.position,
                    body: Vec::new(),
                });
            }
            _ => return Err("Unexpected verification status"),
        }
        self.position += 1;
        Ok(())
    }
}

/// The transport comes back when the connection is still in a known state.
async fn drain(
    mut transport: Transport,
    socket_timeout: Duration,
    ids: &[String],
    mode: Mode,
    maximum_bytes: usize,
    buffer_bytes: usize,
    used: &AtomicUsize,
) -> (Option<Transport>, Vec<(String, Result<Vec<u8>, NntpError>)>) {
    let mut results = Vec::with_capacity(ids.len());
    let command: String = ids
        .iter()
        .map(|id| {
            let verb = if mode == Mode::Body { "BODY" } else { "STAT" };
            format!("{verb} <{id}>\r\n")
        })
        .collect();
    let close = |results: &mut Vec<(String, Result<Vec<u8>, NntpError>)>,
                 position: usize,
                 failure: NntpError| {
        for (offset, id) in ids[position..].iter().enumerate() {
            let result = if offset == 0 {
                failure.clone()
            } else {
                NntpError::PipelineRetry(
                    "Verification pipeline closed before this response was read.".into(),
                )
            };
            results.push((id.clone(), Err(result)));
        }
    };
    if !transport.write_all(command.as_bytes()).await {
        close(
            &mut results,
            0,
            NntpError::transport("Cannot send verification pipeline."),
        );
        return (None, results);
    }
    transport.read_timeout = socket_timeout;
    let mut framer = Framer {
        ids,
        mode,
        position: 0,
        in_body: false,
        line: Vec::new(),
        line_charged: 0,
        body: Vec::new(),
        error: None,
        maximum_bytes,
        buffer_bytes,
        used,
    };
    let mut outcomes = Vec::new();
    while !framer.done() {
        let Some(chunk) = transport.read_some(MAX_LINE).await else {
            let message = if transport.timed_out {
                "Verification pipeline timed out."
            } else {
                "Truncated verification pipeline."
            };
            close(&mut results, framer.position, NntpError::transport(message));
            collect(ids, &mut outcomes, &mut results);
            return (None, results);
        };
        framer.feed(&chunk, &mut outcomes);
    }
    collect(ids, &mut outcomes, &mut results);
    if let Some(error) = framer.error {
        let failure = if error == "Verification article exceeds byte budget" {
            NntpError::BudgetExceeded("Article exceeds verification memory budget.".into())
        } else {
            NntpError::transport(error)
        };
        close(&mut results, framer.position, failure);
        return (None, results);
    }
    // Bytes after the last response mean the stream no longer lines up with our requests.
    let aligned = transport.buffered() == 0;
    (aligned.then_some(transport), results)
}

fn collect(
    ids: &[String],
    outcomes: &mut Vec<Outcome>,
    results: &mut Vec<(String, Result<Vec<u8>, NntpError>)>,
) {
    for outcome in outcomes.drain(..) {
        match outcome {
            Outcome::Article { index, body } => results.push((ids[index].clone(), Ok(body))),
            Outcome::Missing { index } => results.push((
                ids[index].clone(),
                Err(NntpError::ArticleMissing(
                    "Verification article is unavailable.".into(),
                )),
            )),
        }
    }
}

/// Sends every batch and drains all connections concurrently. Failures are per request in
/// the map. Only a heartbeat failure, or an invalid request, fails the whole call. A heartbeat
/// failure closes every connection, because their responses are left unread.
pub async fn verification_article_batch<E>(
    batches: Vec<Batch<'_>>,
    maximum_bytes: i64,
    buffer_bytes: i64,
    mode: Mode,
    heartbeat: &mut (dyn FnMut() -> Result<(), E> + Send),
) -> Result<Results, BatchError<E>> {
    if maximum_bytes < 1 || buffer_bytes < maximum_bytes || batches.len() > MAX_CONNECTIONS {
        return Err(BatchError::Invalid(NntpError::Invalid(
            "Invalid verification pipeline budget.".into(),
        )));
    }
    let mut count = 0i64;
    for batch in &batches {
        count += batch.ids.len() as i64;
        if batch.ids.len() > MAX_REQUESTS_PER_CONNECTION || count > buffer_bytes / maximum_bytes {
            return Err(BatchError::Invalid(NntpError::Invalid(
                "Verification pipeline exceeds its buffer budget.".into(),
            )));
        }
        for id in &batch.ids {
            verification_message_id(id, maximum_bytes).map_err(BatchError::Invalid)?;
        }
    }
    let maximum_bytes = usize::try_from(maximum_bytes).unwrap_or(usize::MAX);
    let buffer_bytes = usize::try_from(buffer_bytes).unwrap_or(usize::MAX);
    let used = AtomicUsize::new(0);
    let mut results = Results::new();
    let mut work = Vec::new();
    for batch in batches {
        let Batch { client, ids } = batch;
        let usable =
            !client.compression_enabled && !client.response_pending && client.is_connected().await;
        match client
            .transport
            .take()
            .filter(|transport| usable && transport.buffered() == 0)
        {
            Some(transport) if !ids.is_empty() => work.push((client, transport, ids)),
            Some(transport) => client.transport = Some(transport),
            None => {
                client.do_quit();
                for id in ids {
                    results.insert(
                        id,
                        Err(NntpError::PipelineRetry(
                            "Raw verification connection is unavailable.".into(),
                        )),
                    );
                }
            }
        }
    }
    if let Err(failure) = heartbeat() {
        // Nothing has been sent, but the responses these connections owe nobody don't matter:
        // what they lost is the caller's lock, so close them rather than reuse them.
        for (client, _, _) in work {
            client.do_quit();
        }
        return Err(BatchError::Heartbeat(failure));
    }
    let used = &used;
    let mut drains = Box::pin(join_all(work.into_iter().map(
        |(client, transport, ids)| async move {
            let timeout = transport.read_timeout;
            let (transport, drained) = drain(
                transport,
                timeout,
                &ids,
                mode,
                maximum_bytes,
                buffer_bytes,
                used,
            )
            .await;
            match transport {
                Some(transport) => client.transport = Some(transport),
                None => client.do_quit(),
            }
            drained
        },
    )));
    let mut ticks = interval(Duration::from_secs(1));
    ticks.set_missed_tick_behavior(MissedTickBehavior::Delay);
    ticks.tick().await;
    let finished = loop {
        tokio::select! {
            finished = &mut drains => break finished,
            _ = ticks.tick() => {
                if let Err(failure) = heartbeat() {
                    return Err(BatchError::Heartbeat(failure));
                }
            }
        }
    };
    results.extend(finished.into_iter().flatten());
    Ok(results)
}

#[derive(Debug)]
pub enum BatchError<E> {
    Invalid(NntpError),
    Heartbeat(E),
}

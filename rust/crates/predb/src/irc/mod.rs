//! `irc:scrape`: PRE announcements from IRC channels and WebSocket push feeds, one task for
//! each enabled network, restarted with backoff when it stops.

pub mod client;
pub mod networks;
pub mod parser;

use std::collections::HashMap;
use std::time::{Duration, Instant};

use anyhow::{Context, Result, bail};
use chrono::{NaiveDateTime, Utc};
use futures_util::{SinkExt, StreamExt};
use nntmux_runtime::{Ctx, Shutdown};
use serde_json::Value;
use sqlx::MySqlPool;
use tokio_tungstenite::tungstenite::Message;

use self::client::{IrcConnection, Read};
use self::networks::{Network, Transport};
use self::parser::{
    AnnounceParser, BotLine, parse_bot_line, parse_privmsg, strip_control_characters,
};
use crate::feeds::importer::{Importer, Totals};
use crate::feeds::sources::{PredbClubSource, PredbNetSource};
use crate::feeds::{Entry, PRE_MODNUKE, PRE_NUKED, PRE_OLDNUKE, PRE_RENUKED, PRE_UNNUKED};
use crate::filename::exact_value;
use crate::identity::{self, IrcRow, LegacyOutcome};
use crate::index::PredbIndex;

const RESTART_DELAY: u64 = 30;
const MAX_RESTART_DELAY: u64 = 3600;
/// A task that ran at least this long counts as healthy, which resets its backoff.
const HEALTHY_RUN: Duration = Duration::from_secs(600);
const IDLE_PING: Duration = Duration::from_secs(90);
const RECONNECT_DELAY: Duration = Duration::from_secs(5);
const RECONNECT_ATTEMPTS: u32 = 4;
const STREAM_IDLE_PING: Duration = Duration::from_secs(30);
const STREAM_STALE: Duration = Duration::from_secs(180);

/// Seconds before the next start after `failures` consecutive short runs (1 = first failure).
pub fn restart_delay(failures: u32) -> u64 {
    let exponent = failures.saturating_sub(1).min(16);
    (RESTART_DELAY * 2u64.pow(exponent)).min(MAX_RESTART_DELAY)
}

#[derive(Clone)]
struct Identity {
    nick: String,
    user: String,
    real: String,
    password: Option<String>,
}

impl Identity {
    fn from_env() -> Self {
        let name = std::env::var("SCRAPE_IRC_USERNAME").unwrap_or_default();
        let password = std::env::var("SCRAPE_IRC_PASSWORD")
            .ok()
            .filter(|p| !p.is_empty() && p != "false");
        Self {
            nick: name.clone(),
            user: name.clone(),
            real: name,
            password,
        }
    }
}

fn needs_identity(networks: &[String]) -> bool {
    networks.iter().any(|key| {
        !matches!(
            networks::resolve(key).map(|n| n.transport),
            Ok(Transport::WebSocket { .. })
        )
    })
}

/// The CLI entry point.
pub async fn scrape_command(ctx: Ctx, network: Option<String>) -> Result<()> {
    let list = match &network {
        Some(key) => vec![key.clone()],
        None => networks::enabled(),
    };
    if list.is_empty() {
        bail!("No IRC networks are enabled.");
    }
    if needs_identity(&list) && Identity::from_env().user.is_empty() {
        bail!("You must set SCRAPE_IRC_USERNAME.");
    }
    let stop = Shutdown::on_signals()?;
    match network {
        Some(key) => scrape_one(&ctx, &key, &stop).await,
        None => {
            supervise(ctx, list, stop).await;
            Ok(())
        }
    }
}

/// Runs one task for each network and restarts any that exits, until shutdown or a drain.
pub async fn supervise(ctx: Ctx, networks: Vec<String>, stop: Shutdown) {
    if needs_identity(&networks) && Identity::from_env().user.is_empty() {
        tracing::error!("SCRAPE_IRC_USERNAME is unset, so IRC networks can't connect");
        return;
    }
    let mut tasks = tokio::task::JoinSet::new();
    for network in networks {
        let ctx = ctx.clone();
        let stop = stop.clone();
        tasks.spawn(async move { supervise_one(ctx, network, stop).await });
    }
    while tasks.join_next().await.is_some() {}
}

async fn supervise_one(ctx: Ctx, network: String, stop: Shutdown) {
    let mut failures = 0;
    loop {
        if stop.requested() {
            return;
        }
        if ctx.paused().await.unwrap_or(false) {
            // A drain: stay down until it lifts. A deploy's stop signal ends the wait.
            tokio::select! {
                () = stop.token().cancelled_owned() => return,
                () = tokio::time::sleep(Duration::from_secs(5)) => continue,
            }
        }
        let started = Instant::now();
        let run = {
            let (ctx, network, stop) = (ctx.clone(), network.clone(), stop.clone());
            tokio::spawn(async move { scrape_one(&ctx, &network, &stop).await }).await
        };
        if stop.requested() {
            return;
        }
        match run {
            Ok(Ok(())) => tracing::warn!(network, "scraper stopped"),
            Ok(Err(error)) => {
                tracing::warn!(network, error = format!("{error:#}"), "scraper failed")
            }
            Err(error) => tracing::error!(network, %error, "scraper panicked"),
        }
        failures = if started.elapsed() >= HEALTHY_RUN {
            1
        } else {
            failures + 1
        };
        let delay = restart_delay(failures);
        tracing::warn!(network, delay, "restarting scraper");
        tokio::select! {
            () = stop.token().cancelled_owned() => return,
            () = tokio::time::sleep(Duration::from_secs(delay)) => {}
        }
    }
}

/// Runs one network until a drain, shutdown or failure.
pub async fn scrape_one(ctx: &Ctx, key: &str, stop: &Shutdown) -> Result<()> {
    let network = networks::resolve(key)?;
    let index = PredbIndex::new(&ctx.config.manticore);
    let importer = Importer::new(ctx.db.clone(), index.clone());
    match &network.transport {
        Transport::WebSocket { url } => run_stream(ctx, url, &network.format, importer, stop).await,
        Transport::Irc { .. } => {
            let handler = Handler::new(&network, ctx.db.clone(), index, importer)?;
            run_irc(ctx, &network, handler, stop).await
        }
    }
}

async fn open(network: &Network, identity: &Identity) -> Result<IrcConnection> {
    let Transport::Irc {
        server,
        port,
        tls,
        verify_peer_name,
        channels,
    } = &network.transport
    else {
        bail!("not an IRC network");
    };
    let mut connection = IrcConnection::connect(server, *port, *tls, *verify_peer_name).await?;
    // The server password belongs to the configured (synirc/ZNC) server only.
    let password = if network.name == networks::LEGACY {
        identity.password.as_deref()
    } else {
        None
    };
    connection
        .login(&identity.nick, &identity.user, &identity.real, password)
        .await
        .with_context(|| format!("logging in to {server}:{port} as {}", identity.nick))?;
    connection.join(channels).await?;
    tracing::info!(network = network.name, "scraping started");
    Ok(connection)
}

async fn reopen(
    ctx: &Ctx,
    network: &Network,
    identity: &Identity,
    stop: &Shutdown,
) -> Result<Option<IrcConnection>> {
    let mut last = None;
    for attempt in 0..RECONNECT_ATTEMPTS {
        if attempt > 0 {
            tokio::select! {
                () = stop.token().cancelled_owned() => return Ok(None),
                () = tokio::time::sleep(RECONNECT_DELAY) => {}
            }
        }
        if stop.requested() || ctx.paused().await.unwrap_or(false) {
            return Ok(None);
        }
        match open(network, identity).await {
            Ok(connection) => return Ok(Some(connection)),
            Err(error) => {
                tracing::warn!(
                    network = network.name,
                    error = format!("{error:#}"),
                    "reconnect failed"
                );
                last = Some(error);
            }
        }
    }
    Err(last.unwrap_or_else(|| anyhow::anyhow!("no reconnect attempt ran")))
}

async fn run_irc(
    ctx: &Ctx,
    network: &Network,
    mut handler: Handler,
    stop: &Shutdown,
) -> Result<()> {
    let identity = Identity::from_env();
    let Some(mut connection) = reopen(ctx, network, &identity, stop).await? else {
        return Ok(());
    };
    let mut last_ping = Instant::now();
    loop {
        if stop.requested() || ctx.paused().await.unwrap_or(false) {
            connection.send("QUIT :Processing drained").await.ok();
            return Ok(());
        }
        let read = tokio::select! {
            () = stop.token().cancelled_owned() => continue,
            read = connection.read() => read,
        };
        let lost = match read {
            Ok(Read::Closed) | Err(_) => true,
            Ok(Read::Idle) => false,
            Ok(Read::Line(line)) => {
                if let Some(rest) = line.strip_prefix("PING") {
                    let token = rest.trim_start().trim_start_matches(':');
                    last_ping = Instant::now();
                    connection.send(&format!("PONG {token}")).await.is_err()
                } else {
                    if let Some((_, _, message)) = parse_privmsg(&strip_control_characters(&line)) {
                        handler.message(&message).await?;
                    }
                    false
                }
            }
        };
        // Ping when the server hasn't, so a dead connection shows up as a failed write.
        let lost = lost
            || (last_ping.elapsed() > IDLE_PING && {
                last_ping = Instant::now();
                connection.send("PING keepalive").await.is_err()
            });
        if lost {
            tracing::warn!(network = network.name, "connection lost, reconnecting");
            let Some(fresh) = reopen(ctx, network, &identity, stop).await? else {
                return Ok(());
            };
            connection = fresh;
            last_ping = Instant::now();
        }
    }
}

/// Stores what channels announce: bot lines through the legacy writer, public channels through
/// the feed import rules.
struct Handler {
    db: MySqlPool,
    index: PredbIndex,
    importer: Importer,
    announce: Option<AnnounceParser>,
    groups: HashMap<String, Option<i64>>,
}

impl Handler {
    fn new(
        network: &Network,
        db: MySqlPool,
        index: PredbIndex,
        importer: Importer,
    ) -> Result<Self> {
        let announce = if network.format == "nntmux" {
            None
        } else {
            Some(AnnounceParser::new(
                &network.format,
                network.source.as_deref().unwrap_or(&network.name),
            )?)
        };
        Ok(Self {
            db,
            index,
            importer,
            announce,
            groups: HashMap::new(),
        })
    }

    async fn message(&mut self, message: &str) -> Result<()> {
        if let Some(parser) = &self.announce {
            let Some(entry) = parser.parse(message, Utc::now().naive_utc()) else {
                return Ok(());
            };
            let totals = self
                .importer
                .import(std::slice::from_ref(&entry), false)
                .await?;
            log_import(&entry, totals);
            return Ok(());
        }
        let Some(line) = parse_bot_line(message) else {
            return Ok(());
        };
        let row = self.row_from_bot_line(&line).await?;
        self.store(row).await
    }

    async fn row_from_bot_line(&mut self, line: &BotLine) -> Result<IrcRow> {
        let not_na = |value: &str| (value != "N/A").then(|| value.to_owned());
        let (request_id, group_id) = match line.req.split_once(':') {
            Some((req, group))
                if line.req != "N/A"
                    && !req.is_empty()
                    && req.bytes().all(|b| b.is_ascii_digit())
                    && !group.is_empty() =>
            {
                (req.parse().ok(), self.group_id(group).await?)
            }
            _ => (None, None),
        };
        let nuked = line.nuked.as_deref().and_then(|kind| match kind {
            "NUKED" => Some(PRE_NUKED),
            "UNNUKED" => Some(PRE_UNNUKED),
            "MODNUKED" => Some(PRE_MODNUKE),
            "RENUKED" => Some(PRE_RENUKED),
            "OLDNUKE" => Some(PRE_OLDNUKE),
            _ => None,
        });
        Ok(IrcRow {
            title: line.title.clone(),
            size: not_na(&line.size),
            category: not_na(&line.category),
            source: Some(line.source.clone()),
            reason: line
                .nuked
                .as_ref()
                .map(|_| floor_bytes(line.reason.as_deref().unwrap_or(""), 255)),
            files: not_na(&line.files).map(|files| floor_bytes(&files, 50)),
            request_id,
            group_id,
            nuked,
            filename: line.filename.as_deref().and_then(not_na),
            predate: parse_time(&line.time),
        })
    }

    async fn group_id(&mut self, name: &str) -> Result<Option<i64>> {
        if let Some(known) = self.groups.get(name) {
            return Ok(*known);
        }
        let id: Option<i64> =
            sqlx::query_scalar("SELECT CAST(id AS SIGNED) FROM usenet_groups WHERE name = ?")
                .bind(name)
                .fetch_optional(&self.db)
                .await?;
        self.groups.insert(name.to_owned(), id);
        Ok(id)
    }

    async fn store(&self, row: IrcRow) -> Result<()> {
        let old: Option<(u64, String)> =
            sqlx::query_as("SELECT id, title FROM predb WHERE title = ? LIMIT 1")
                .bind(&row.title)
                .fetch_optional(&self.db)
                .await?;
        let Some((id, title)) = old else {
            if let LegacyOutcome::Inserted(id) = identity::insert_irc(&self.db, &row).await? {
                let filename = row.filename.as_deref().unwrap_or("");
                let source = row.source.as_deref().unwrap_or("");
                self.index.replace(id, &row.title, filename, source).await;
                tracing::info!(title = row.title, source, "added pre");
            }
            return Ok(());
        };
        if exact_value(&title) != exact_value(&row.title) {
            return Ok(());
        }
        match identity::update_irc(&self.db, id, &title, &row).await? {
            LegacyOutcome::Updated | LegacyOutcome::Unchanged => {
                let current: Option<(String, String, String)> =
                    sqlx::query_as("SELECT title, filename, source FROM predb WHERE id = ?")
                        .bind(id)
                        .fetch_optional(&self.db)
                        .await?;
                if let Some((title, filename, source)) = current {
                    self.index.replace(id, &title, &filename, &source).await;
                }
            }
            _ => {}
        }
        Ok(())
    }
}

fn log_import(entry: &Entry, totals: Totals) {
    if totals.skipped == 0 {
        tracing::info!(
            title = entry.title,
            source = entry.source,
            inserted = totals.inserted,
            "stored pre"
        );
    }
}

/// PHP `substr`, without splitting a character.
fn floor_bytes(value: &str, limit: usize) -> String {
    if value.len() <= limit {
        return value.to_owned();
    }
    let mut end = limit;
    while !value.is_char_boundary(end) {
        end -= 1;
    }
    value[..end].to_owned()
}

/// The bot's `DT:` value, read as UTC.
fn parse_time(value: &str) -> Option<NaiveDateTime> {
    let value = value.trim();
    ["%Y-%m-%d %H:%M:%S", "%Y-%m-%dT%H:%M:%S", "%Y-%m-%d %H:%M"]
        .iter()
        .find_map(|format| NaiveDateTime::parse_from_str(value, format).ok())
}

/// Turns one pushed message into feed entries. Heartbeats and unknown messages yield none.
pub fn stream_entries(format: &str, message: &str) -> Vec<Entry> {
    let Ok(data) = serde_json::from_str::<Value>(message) else {
        return Vec::new();
    };
    if format == "predb_club" {
        let row = &data["row"];
        let action = data["action"].as_str();
        return if row.is_object() && matches!(action, Some("insert" | "update")) {
            PredbClubSource::parse_rows(std::slice::from_ref(row))
        } else {
            Vec::new()
        };
    }
    let row = &data["message"];
    if row.is_object() {
        PredbNetSource::parse_rows(std::slice::from_ref(row))
    } else {
        Vec::new()
    }
}

/// Stores PREs pushed over a WebSocket feed, by the same rules as the JSON feeds. Returns an
/// error when the connection drops or goes quiet, so the supervisor reconnects.
async fn run_stream(
    ctx: &Ctx,
    url: &str,
    format: &str,
    mut importer: Importer,
    stop: &Shutdown,
) -> Result<()> {
    if !matches!(format, "predb_club" | "predb_net") {
        bail!("Unknown PreDB stream format [{format}]. Available: predb_club, predb_net");
    }
    let (mut socket, _) = tokio::time::timeout(
        Duration::from_secs(15),
        tokio_tungstenite::connect_async(url),
    )
    .await
    .context("WebSocket connect timed out")?
    .with_context(|| format!("WebSocket connect to {url}"))?;
    tracing::info!(format, "stream connected");
    let mut last_frame = Instant::now();
    loop {
        if stop.requested() || ctx.paused().await.unwrap_or(false) {
            socket.close(None).await.ok();
            return Ok(());
        }
        let frame = tokio::select! {
            () = stop.token().cancelled_owned() => continue,
            frame = tokio::time::timeout(STREAM_IDLE_PING, socket.next()) => frame,
        };
        let frame = match frame {
            Err(_) => {
                if last_frame.elapsed() >= STREAM_STALE {
                    socket.close(None).await.ok();
                    bail!("No data for {}s; reconnecting.", STREAM_STALE.as_secs());
                }
                socket.send(Message::Ping(Vec::new().into())).await?;
                continue;
            }
            Ok(None) => bail!("the stream closed"),
            Ok(Some(frame)) => frame?,
        };
        last_frame = Instant::now();
        let text = match frame {
            Message::Text(text) => text.to_string(),
            Message::Binary(bytes) => String::from_utf8_lossy(&bytes).into_owned(),
            Message::Close(_) => bail!("the stream closed"),
            _ => continue,
        };
        for entry in stream_entries(format, &text) {
            let totals = importer.import(std::slice::from_ref(&entry), false).await?;
            log_import(&entry, totals);
        }
    }
}

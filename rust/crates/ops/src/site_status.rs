//! `nntmux:check-service-health` and `nntmux:check-service-uptime`: probes the services on the status
//! page, opens and resolves automatic incidents, and keeps the uptime figures.

use std::path::Path;
use std::time::{Duration, Instant};

use anyhow::Result;
use chrono::{DateTime, Duration as Days, Utc};
use nntmux_core::{optional, parsed_or, var_or};
use nntmux_nntp::NntpClient;
use nntmux_runtime::Ctx;
use nntmux_search::SearchClient;
use nntmux_webkit::mail::{self, OutgoingMail};
use sqlx::MySqlPool;
use tracing::warn;

const SLOW_MS: i64 = 5000;
const REQUEST_TIMEOUT: Duration = Duration::from_secs(15);
const CONNECT_TIMEOUT: Duration = Duration::from_secs(10);
const DEFAULT_ADMIN_EMAIL: &str = "admin@example.com";
const REASON_LIMIT: usize = 120;
pub const UPTIME_DAYS: i64 = 30;

#[derive(Clone, Debug, PartialEq, Eq)]
pub struct Health {
    pub ok: bool,
    pub status_code: u16,
    pub response_time_ms: i64,
    /// `none`, `minor`, `major` or `critical`.
    pub impact: Option<&'static str>,
    pub reason: String,
}

impl Health {
    fn fine(response_time_ms: i64, reason: impl Into<String>) -> Self {
        Self {
            ok: true,
            status_code: 0,
            response_time_ms,
            impact: None,
            reason: reason.into(),
        }
    }

    fn failed(impact: &'static str, reason: impl Into<String>) -> Self {
        Self {
            ok: false,
            status_code: 0,
            response_time_ms: 0,
            impact: Some(impact),
            reason: reason.into(),
        }
    }
}

fn limited(message: impl ToString) -> String {
    let message = message.to_string();
    if message.chars().count() > REASON_LIMIT {
        format!(
            "{}...",
            message.chars().take(REASON_LIMIT).collect::<String>()
        )
    } else {
        message
    }
}

fn since_ms(start: Instant) -> i64 {
    i64::try_from(start.elapsed().as_millis()).unwrap_or(i64::MAX)
}

/// Laravel's `(bool) env()`.
fn flag(key: &str, default: bool) -> bool {
    match std::env::var(key) {
        Ok(value) => !matches!(
            value.to_ascii_lowercase().as_str(),
            "" | "0" | "false" | "(false)" | "null" | "(null)" | "empty" | "(empty)"
        ),
        Err(_) => default,
    }
}

async fn probe_database(db: &MySqlPool) -> Health {
    let start = Instant::now();
    match sqlx::query("SELECT 1").execute(db).await {
        Ok(_) => Health::fine(since_ms(start), "Connected"),
        Err(failure) => Health::failed(
            "critical",
            format!("Database unreachable: {}", limited(failure)),
        ),
    }
}

async fn probe_redis(ctx: &Ctx) -> Health {
    if !flag("STATUS_PROBE_REDIS", true) {
        return Health::fine(0, "Redis probe disabled (STATUS_PROBE_REDIS=false)");
    }
    let start = Instant::now();
    let mut redis = ctx.redis.clone();
    let pong: redis::RedisResult<String> = redis::cmd("PING").query_async(&mut redis).await;
    match pong {
        Ok(pong) if pong.eq_ignore_ascii_case("PONG") => Health::fine(since_ms(start), "Connected"),
        Ok(_) => Health {
            response_time_ms: since_ms(start),
            ..Health::failed("major", "Redis ping returned unexpected response")
        },
        Err(failure) => Health::failed(
            "critical",
            format!("Redis unreachable: {}", limited(failure)),
        ),
    }
}

async fn probe_search(ctx: &Ctx) -> Health {
    let client = match SearchClient::from_ctx(ctx) {
        Ok(client) => client,
        Err(failure) => {
            return Health::failed(
                "critical",
                format!("Search check failed: {}", limited(failure)),
            );
        }
    };
    let start = Instant::now();
    if client.is_available().await {
        Health::fine(since_ms(start), "Search driver \"manticore\" available")
    } else {
        Health {
            response_time_ms: since_ms(start),
            ..Health::failed("critical", "Search driver \"manticore\" unavailable")
        }
    }
}

/// Rust's queue is the mail outbox in Redis.
async fn probe_queue(ctx: &Ctx) -> Health {
    let start = Instant::now();
    let mut redis = ctx.redis.clone();
    let size: redis::RedisResult<i64> = redis::cmd("LLEN")
        .arg(mail::outbox_key())
        .query_async(&mut redis)
        .await;
    match size {
        Ok(_) => Health::fine(since_ms(start), "Queue \"redis\" reachable"),
        Err(failure) => Health::failed(
            "critical",
            format!("Queue probe failed: {}", limited(failure)),
        ),
    }
}

async fn probe_nntp() -> Health {
    let limit =
        Duration::from_secs(parsed_or("STATUS_PROBE_NNTP_TIMEOUT_SECONDS", 10u64).unwrap_or(10));
    let check_alternate = flag("STATUS_PROBE_NNTP_CHECK_ALTERNATE", false);
    let mut client = NntpClient::from_env();
    let start = Instant::now();
    let primary = tokio::time::timeout(limit, client.do_connect(false, false)).await;
    let elapsed = since_ms(start);
    let result = match primary {
        Ok(Ok(())) => {
            if check_alternate {
                match tokio::time::timeout(limit, client.do_connect(false, true)).await {
                    Ok(Ok(())) => Health::fine(elapsed, "Primary and alternate NNTP connected"),
                    Ok(Err(failure)) => {
                        Health::failed("major", format!("Alternate NNTP failed: {failure}"))
                    }
                    Err(_) => Health::failed("major", "Alternate NNTP failed: timed out"),
                }
            } else {
                Health::fine(elapsed, "Primary NNTP connected")
            }
        }
        Ok(Err(failure)) => Health::failed("critical", format!("Primary NNTP failed: {failure}")),
        Err(_) => Health::failed("critical", "Primary NNTP failed: timed out"),
    };
    client.do_quit();
    result
}

/// Judges the free space on each mount by the lowest. `free` reads one mount's free bytes.
pub fn disk_health(
    mounts: &[String],
    warning_gb: f64,
    critical_gb: f64,
    free: impl Fn(&Path) -> Option<u64>,
) -> Health {
    let mut lowest: Option<(f64, &str)> = None;
    for mount in mounts {
        let Some(bytes) = free(Path::new(mount)) else {
            return Health::failed(
                "major",
                format!("Unable to read disk usage for mount \"{mount}\""),
            );
        };
        let gigabytes = (bytes as f64 / 1_073_741_824.0 * 100.0).round() / 100.0;
        if lowest.is_none_or(|(least, _)| gigabytes < least) {
            lowest = Some((gigabytes, mount));
        }
    }
    let Some((gigabytes, mount)) = lowest else {
        return Health::failed("major", "No disk mount points configured");
    };
    if gigabytes <= critical_gb {
        Health::failed(
            "critical",
            format!("Low disk space on {mount} ({gigabytes:.2} GB free)"),
        )
    } else if gigabytes <= warning_gb {
        Health::failed(
            "minor",
            format!("Disk space warning on {mount} ({gigabytes:.2} GB free)"),
        )
    } else {
        Health::fine(
            0,
            format!("Disk healthy (lowest: {mount} {gigabytes:.2} GB free)"),
        )
    }
}

fn probe_disk() -> Health {
    let mounts: Vec<String> = var_or("STATUS_PROBE_DISK_MOUNT_POINTS", "/")
        .split(',')
        .map(str::trim)
        .filter(|mount| !mount.is_empty())
        .map(str::to_owned)
        .collect();
    disk_health(
        &mounts,
        parsed_or("STATUS_PROBE_DISK_WARNING_THRESHOLD_GB", 5.0).unwrap_or(5.0),
        parsed_or("STATUS_PROBE_DISK_CRITICAL_THRESHOLD_GB", 1.0).unwrap_or(1.0),
        |path| {
            let stats = rustix::fs::statvfs(path).ok()?;
            Some(stats.f_bavail.saturating_mul(stats.f_frsize))
        },
    )
}

async fn run_probe(ctx: &Ctx, identifier: &str) -> Health {
    match identifier {
        "database" => probe_database(&ctx.db).await,
        "redis" => probe_redis(ctx).await,
        "search" => probe_search(ctx).await,
        "nntp" => probe_nntp().await,
        "queue" => probe_queue(ctx).await,
        "disk" => probe_disk(),
        other => Health::failed("major", format!("Unknown probe identifier \"{other}\"")),
    }
}

async fn ping(http: &reqwest::Client, url: &str, forbidden_is_healthy: bool) -> Health {
    let start = Instant::now();
    let response = match http.get(url).send().await {
        Ok(response) => response,
        Err(failure) => {
            warn!(url, error = %failure, "health check failed");
            return Health::failed(
                "critical",
                format!("Connection failed: {}", limited(failure)),
            );
        }
    };
    let elapsed = since_ms(start);
    let status = response.status();
    let code = status.as_u16();
    if status.is_success() || (forbidden_is_healthy && code == 403) {
        return if elapsed > SLOW_MS {
            Health {
                ok: false,
                status_code: code,
                response_time_ms: elapsed,
                impact: Some("minor"),
                reason: format!("Slow response ({elapsed}ms)"),
            }
        } else {
            Health {
                ok: true,
                status_code: code,
                response_time_ms: elapsed,
                impact: None,
                reason: "OK".to_owned(),
            }
        };
    }
    let (impact, reason) = if status.is_server_error() {
        ("critical", format!("Server error (HTTP {code})"))
    } else {
        ("major", format!("Unexpected response (HTTP {code})"))
    };
    Health {
        ok: false,
        status_code: code,
        response_time_ms: elapsed,
        impact: Some(impact),
        reason,
    }
}

/// Any healthy path makes the service healthy. Otherwise the worst stands, ordered as PHP compared
/// the impact values, as strings.
async fn check_http(http: &reqwest::Client, base: &str, endpoints: &str, slug: &str) -> Health {
    let mut worst: Option<Health> = None;
    for path in endpoints
        .split(',')
        .map(str::trim)
        .filter(|path| !path.is_empty())
    {
        let result = ping(http, &format!("{base}{path}"), slug == "rss").await;
        if result.ok {
            return result;
        }
        if worst
            .as_ref()
            .is_none_or(|worst| result.impact > worst.impact)
        {
            worst = Some(result);
        }
    }
    worst.unwrap_or_else(|| Health::failed("critical", "All endpoint checks failed"))
}

#[derive(Clone, Debug, sqlx::FromRow)]
struct Service {
    id: u64,
    name: String,
    slug: String,
    endpoint_url: Option<String>,
    check_type: String,
    probe_identifier: Option<String>,
}

async fn enabled_services(db: &MySqlPool) -> Result<Vec<Service>> {
    Ok(sqlx::query_as(
        "SELECT id, name, slug, endpoint_url, check_type, probe_identifier FROM service_statuses \
         WHERE is_enabled = 1 ORDER BY sort_order, id",
    )
    .fetch_all(db)
    .await?)
}

/// `None` means there's nothing to check: no endpoint and not a probe.
async fn check_service(
    ctx: &Ctx,
    http: &reqwest::Client,
    base: &str,
    service: &Service,
) -> Option<Health> {
    let endpoint = service.endpoint_url.as_deref().unwrap_or_default();
    if endpoint.is_empty() && service.check_type != "probe" {
        return None;
    }
    if service.check_type == "probe" {
        return Some(match service.probe_identifier.as_deref() {
            None | Some("") => Health::failed("major", "Probe identifier not configured"),
            Some(identifier) => run_probe(ctx, identifier).await,
        });
    }
    if base.is_empty() || !base.starts_with("http") {
        return Some(Health::fine(0, "APP_URL not configured"));
    }
    Some(check_http(http, base, endpoint, &service.slug).await)
}

fn service_status_for(impact: &str) -> &'static str {
    match impact {
        "major" => "partial_outage",
        "critical" => "major_outage",
        _ => "degraded",
    }
}

fn severity(status: &str) -> u8 {
    match status {
        "operational" => 0,
        "degraded" => 1,
        "maintenance" => 2,
        "partial_outage" => 3,
        _ => 4,
    }
}

/// The service's status is the worst of its open incidents.
async fn recompute_health(db: &MySqlPool, service_id: u64) -> Result<()> {
    let impacts: Vec<String> = sqlx::query_scalar(
        "SELECT i.impact FROM service_incidents i \
         JOIN service_incident_service_status p ON p.service_incident_id = i.id \
         WHERE p.service_status_id = ? AND i.status <> 'resolved'",
    )
    .bind(service_id)
    .fetch_all(db)
    .await?;
    let worst = impacts
        .iter()
        .map(|impact| service_status_for(impact))
        .max_by_key(|status| severity(status))
        .unwrap_or("operational");
    sqlx::query(
        "UPDATE service_statuses SET status = ?, updated_at = NOW() WHERE id = ? AND status <> ?",
    )
    .bind(worst)
    .bind(service_id)
    .bind(worst)
    .execute(db)
    .await?;
    Ok(())
}

#[derive(Clone, Debug, sqlx::FromRow)]
struct Incident {
    id: u64,
    title: String,
    description: String,
    status: String,
    impact: String,
    started_at: DateTime<Utc>,
    resolved_at: Option<DateTime<Utc>>,
}

async fn open_auto_incident(db: &MySqlPool, service_id: u64) -> Result<Option<Incident>> {
    Ok(sqlx::query_as(
        "SELECT i.id, i.title, i.description, i.status, i.impact, i.started_at, i.resolved_at \
         FROM service_incidents i JOIN service_incident_service_status p ON p.service_incident_id = i.id \
         WHERE p.service_status_id = ? AND i.is_auto = 1 AND i.status <> 'resolved' \
         ORDER BY i.started_at DESC LIMIT 1",
    )
    .bind(service_id)
    .fetch_optional(db)
    .await?)
}

async fn load_incident(db: &MySqlPool, id: u64) -> Result<Incident> {
    Ok(sqlx::query_as(
        "SELECT id, title, description, status, impact, started_at, resolved_at FROM service_incidents WHERE id = ?",
    )
    .bind(id)
    .fetch_one(db)
    .await?)
}

/// Opens an automatic incident on a new failure and resolves it on recovery. An open incident isn't
/// touched by further failures, so one outage sends one mail.
async fn handle_result(ctx: &Ctx, service: &Service, result: &Health) -> Result<()> {
    let db = &ctx.db;
    sqlx::query(
        "UPDATE service_statuses SET response_time_ms = ?, updated_at = NOW() WHERE id = ?",
    )
    .bind((result.response_time_ms > 0).then_some(result.response_time_ms))
    .bind(service.id)
    .execute(db)
    .await?;
    let existing = open_auto_incident(db, service.id).await?;
    if result.ok {
        if let Some(incident) = existing {
            sqlx::query(
                "UPDATE service_incidents SET status = 'resolved', resolved_at = COALESCE(resolved_at, NOW()), \
                 updated_at = NOW() WHERE id = ?",
            )
            .bind(incident.id)
            .execute(db)
            .await?;
            let services: Vec<u64> = sqlx::query_scalar(
                "SELECT service_status_id FROM service_incident_service_status WHERE service_incident_id = ?",
            )
            .bind(incident.id)
            .fetch_all(db)
            .await?;
            for id in services {
                recompute_health(db, id).await?;
            }
            let resolved = load_incident(db, incident.id).await?;
            send_incident_mail(ctx, &resolved, &service.name, true).await;
        }
        return Ok(());
    }
    if existing.is_some() {
        return Ok(());
    }
    let impact = result.impact.unwrap_or("major");
    let description = format!(
        "Automated health check detected an issue with {}.\n\nEndpoint: {}\nHTTP status: {}\nResponse time: {}ms\nReason: {}",
        service.name,
        service.endpoint_url.as_deref().unwrap_or_default(),
        result.status_code,
        result.response_time_ms,
        result.reason
    );
    let title = format!("[Auto] {} — {}", service.name, result.reason);
    let title: String = title.chars().take(255).collect();
    let mut tx = db.begin().await?;
    let id = sqlx::query(
        "INSERT INTO service_incidents (title, description, status, impact, started_at, is_auto, created_at, updated_at) \
         VALUES (?, ?, 'investigating', ?, NOW(), 1, NOW(), NOW())",
    )
    .bind(&title)
    .bind(&description)
    .bind(impact)
    .execute(&mut *tx)
    .await?
    .last_insert_id();
    sqlx::query(
        "INSERT INTO service_incident_service_status (service_incident_id, service_status_id) VALUES (?, ?)",
    )
    .bind(id)
    .bind(service.id)
    .execute(&mut *tx)
    .await?;
    tx.commit().await?;
    recompute_health(db, service.id).await?;
    let incident = load_incident(db, id).await?;
    send_incident_mail(ctx, &incident, &service.name, false).await;
    Ok(())
}

fn capitalized(value: &str) -> String {
    let mut chars = value.chars();
    chars.next().map_or_else(String::new, |first| {
        first.to_uppercase().chain(chars).collect()
    })
}

fn escape_html(value: &str) -> String {
    value
        .replace('&', "&amp;")
        .replace('<', "&lt;")
        .replace('>', "&gt;")
        .replace('"', "&quot;")
}

fn incident_mail(
    to: &str,
    incident: &Incident,
    services: &str,
    resolved: bool,
    status_url: &str,
) -> OutgoingMail {
    let headline = if resolved {
        "Incident Resolved"
    } else {
        "Incident Detected"
    };
    let impact = capitalized(&incident.impact);
    let summary = if resolved {
        "Resolved — The following incident has been automatically resolved.".to_owned()
    } else {
        format!("{impact} impact — An automated health check has detected a service issue.")
    };
    let date = |at: DateTime<Utc>| at.format("%b %-d, %Y %-I:%M %p UTC").to_string();
    let mut fields = vec![
        ("Incident", incident.title.clone()),
        ("Affected services", services.to_owned()),
        ("Impact", impact),
        ("Status", capitalized(&incident.status)),
        ("Started", date(incident.started_at)),
    ];
    if let (true, Some(at)) = (resolved, incident.resolved_at) {
        fields.push(("Resolved", date(at)));
    }
    let lines = fields
        .iter()
        .map(|(label, value)| format!("  {label}: {value}"))
        .collect::<Vec<_>>()
        .join("\n");
    let text = format!(
        "{headline} — {services}\n\n{summary}\n\n{lines}\n\nDetails:\n{}\n\nView status dashboard: {status_url}\n",
        incident.description
    );
    let rows = fields
        .iter()
        .map(|(label, value)| {
            format!(
                "<tr><th align=\"left\">{label}</th><td>{}</td></tr>",
                escape_html(value)
            )
        })
        .collect::<String>();
    let html = format!(
        "<h1>{headline} — {}</h1><p>{}</p><table>{rows}</table><p>{}</p><p><a href=\"{}\">View status dashboard</a></p>",
        escape_html(services),
        escape_html(&summary),
        escape_html(&incident.description).replace('\n', "<br>"),
        escape_html(status_url)
    );
    let prefix = optional("MAIL_SUBJECT_PREFIX")
        .unwrap_or_else(|| format!("[{}] ", var_or("APP_NAME", "NNTmux")));
    OutgoingMail {
        to: to.to_owned(),
        subject: format!(
            "{prefix}Service incident {}: {services}",
            if resolved { "Resolved" } else { "Detected" }
        ),
        html,
        text,
        reply_to: None,
    }
}

/// A mail failure only warns: the incident is already recorded.
async fn send_incident_mail(ctx: &Ctx, incident: &Incident, services: &str, resolved: bool) {
    let admin = var_or("ADMIN_EMAIL", DEFAULT_ADMIN_EMAIL);
    if admin == DEFAULT_ADMIN_EMAIL {
        return;
    }
    let status_url = format!(
        "{}/admin/status",
        var_or("APP_URL", "").trim_end_matches('/')
    );
    let message = incident_mail(&admin, incident, services, resolved, &status_url);
    if let Err(failure) = mail::enqueue(ctx, &message).await {
        warn!(
            error = format!("{failure:#}"),
            "failed to send incident email"
        );
    }
}

/// Downtime in a window: incidents clamped to it, with overlaps merged so nothing counts twice.
/// Brief outages stay visible, so any downtime reads at most 99.99.
pub fn uptime_percent(
    incidents: &[(DateTime<Utc>, Option<DateTime<Utc>>)],
    window_start: DateTime<Utc>,
    now: DateTime<Utc>,
) -> f64 {
    let total = (now - window_start).num_minutes() as f64;
    if total <= 0.0 {
        return 100.0;
    }
    let mut intervals: Vec<(i64, i64)> = incidents
        .iter()
        .filter_map(|(started, resolved)| {
            let start = (*started).max(window_start).timestamp();
            let end = resolved
                .map_or(now, |resolved| resolved.min(now))
                .timestamp();
            (start < end).then_some((start, end))
        })
        .collect();
    intervals.sort_unstable();
    let mut merged: Vec<(i64, i64)> = Vec::new();
    for (start, end) in intervals {
        match merged.last_mut() {
            Some(last) if start <= last.1 => last.1 = last.1.max(end),
            _ => merged.push((start, end)),
        }
    }
    let downtime: f64 = merged
        .iter()
        .map(|(start, end)| (end - start) as f64 / 60.0)
        .sum();
    let uptime = ((total - downtime) / total * 100.0).clamp(0.0, 100.0);
    let rounded = (uptime * 100.0).round() / 100.0;
    if downtime > 0.0 && rounded >= 100.0 {
        99.99
    } else {
        rounded
    }
}

/// `refreshAllServiceUptime`. Only major and critical incidents count as downtime.
pub async fn refresh_uptime(db: &MySqlPool, days: i64) -> Result<()> {
    let now = Utc::now();
    let window_start = now - Days::days(days);
    for service in enabled_services(db).await? {
        let incidents: Vec<(DateTime<Utc>, Option<DateTime<Utc>>)> = sqlx::query_as(
            "SELECT i.started_at, i.resolved_at FROM service_incidents i \
             JOIN service_incident_service_status p ON p.service_incident_id = i.id \
             WHERE p.service_status_id = ? AND i.impact IN ('major', 'critical') \
             AND (i.resolved_at IS NULL OR i.resolved_at >= ?) AND i.started_at <= ?",
        )
        .bind(service.id)
        .bind(window_start)
        .bind(now)
        .fetch_all(db)
        .await?;
        sqlx::query(
            "UPDATE service_statuses SET uptime_percentage = ?, last_checked_at = NOW(), updated_at = NOW() WHERE id = ?",
        )
        .bind(uptime_percent(&incidents, window_start, now))
        .bind(service.id)
        .execute(db)
        .await?;
        recompute_health(db, service.id).await?;
    }
    Ok(())
}

/// `nntmux:check-service-health`. Returns one `(service, result)` per service checked.
pub async fn check_all(ctx: &Ctx, days: i64) -> Result<Vec<(String, String)>> {
    let base = var_or("APP_URL", "");
    let base = base.trim_end_matches('/');
    if base.is_empty() || !base.starts_with("http") {
        warn!("APP_URL is not set, so endpoint health checks are skipped");
    }
    let http = reqwest::Client::builder()
        .timeout(REQUEST_TIMEOUT)
        .connect_timeout(CONNECT_TIMEOUT)
        .build()?;
    let mut report = Vec::new();
    for service in enabled_services(&ctx.db).await? {
        let Some(result) = check_service(ctx, &http, base, &service).await else {
            report.push((service.name, "Skipped (no endpoint)".to_owned()));
            continue;
        };
        handle_result(ctx, &service, &result).await?;
        let outcome = if result.ok {
            "Healthy".to_owned()
        } else {
            result.reason
        };
        report.push((service.name, outcome));
    }
    refresh_uptime(&ctx.db, days).await?;
    Ok(report)
}

#[cfg(test)]
mod tests {
    use super::*;
    use chrono::TimeZone;

    fn at(day: u32, hour: u32) -> DateTime<Utc> {
        Utc.with_ymd_and_hms(2026, 10, day, hour, 0, 0)
            .single()
            .expect("valid date")
    }

    #[test]
    fn uptime_merges_overlaps_and_clamps_to_the_window() {
        let (start, now) = (at(1, 0), at(11, 0));
        assert_eq!(uptime_percent(&[], start, now), 100.0);
        // Ten days is 14,400 minutes; one day down is 10%.
        assert_eq!(
            uptime_percent(&[(at(2, 0), Some(at(3, 0)))], start, now),
            90.0
        );
        let overlapping = [(at(2, 0), Some(at(3, 0))), (at(2, 12), Some(at(3, 0)))];
        assert_eq!(uptime_percent(&overlapping, start, now), 90.0);
        let before_window = [(
            Utc.with_ymd_and_hms(2026, 9, 1, 0, 0, 0).unwrap(),
            Some(at(2, 0)),
        )];
        assert_eq!(uptime_percent(&before_window, start, now), 90.0);
        assert_eq!(uptime_percent(&[(at(10, 0), None)], start, now), 90.0);
    }

    #[test]
    fn a_brief_outage_never_rounds_to_full_uptime() {
        let (start, now) = (at(1, 0), at(31, 0));
        let brief = [(at(2, 0), Some(at(2, 0) + Days::seconds(10)))];
        assert_eq!(uptime_percent(&brief, start, now), 99.99);
    }

    #[test]
    fn disk_health_judges_the_lowest_mount() {
        let gb = |gigabytes: u64| gigabytes * 1_073_741_824;
        let mounts = ["/".to_owned(), "/data".to_owned()];
        let health = disk_health(&mounts, 5.0, 1.0, |path| {
            Some(if path == Path::new("/") {
                gb(100)
            } else {
                gb(3)
            })
        });
        assert_eq!(health.impact, Some("minor"));
        assert_eq!(health.reason, "Disk space warning on /data (3.00 GB free)");
        let critical = disk_health(&mounts, 5.0, 1.0, |_| Some(gb(1)));
        assert_eq!(critical.impact, Some("critical"));
        assert!(disk_health(&mounts, 5.0, 1.0, |_| Some(gb(50))).ok);
        assert_eq!(
            disk_health(&mounts, 5.0, 1.0, |_| None).impact,
            Some("major")
        );
        assert_eq!(disk_health(&[], 5.0, 1.0, |_| None).impact, Some("major"));
    }

    #[test]
    fn long_reasons_are_cut_on_characters() {
        let long = "é".repeat(200);
        assert_eq!(limited(&long).chars().count(), REASON_LIMIT + 3);
        assert_eq!(limited("short"), "short");
    }
}

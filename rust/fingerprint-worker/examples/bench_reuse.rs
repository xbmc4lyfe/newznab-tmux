//! Paired benchmark of two worker binaries against a scripted NNTP server with injected latency.
//!
//! Both binaries process the same releases from a **disposable** migrated MariaDB database
//! (`FINGERPRINT_TEST_DATABASE_URL`-style URL) through the real claim, budget, collection and
//! finish paths. Runs alternate in random order; the report gives per-binary throughput,
//! per-job latency percentiles and the phase breakdown each binary logs.
//!
//! ```sh
//! cargo build --release --examples --locked --manifest-path rust/fingerprint-worker/Cargo.toml
//! rust/fingerprint-worker/target/release/examples/bench_reuse \
//!   --database-url mysql://root:pw@127.0.0.1:3306/fingerprint_test \
//!   --baseline /path/to/old-worker --candidate /path/to/new-worker --rtt-ms 75
//! ```
#[path = "../tests/support/mod.rs"]
mod support;

use clap::Parser;
use flate2::{Compression, write::GzEncoder};
use rand::{RngCore, seq::SliceRandom};
use serde_json::Value;
use sha2::{Digest, Sha256};
use sqlx::{MySqlPool, mysql::MySqlPoolOptions};
use std::{
    io::Write,
    path::PathBuf,
    process::Stdio,
    time::{Duration, Instant},
};
use support::{Script, ScriptedNntp};
use tokio::{
    io::{AsyncBufReadExt, BufReader},
    process::Command,
};

const FIRST_ID: u64 = 9_800_001;

#[derive(Parser, Clone)]
struct Args {
    #[arg(long)]
    database_url: String,
    #[arg(long)]
    baseline: PathBuf,
    #[arg(long)]
    candidate: PathBuf,
    /// Releases (jobs) per run.
    #[arg(long, default_value_t = 96)]
    releases: u64,
    #[arg(long, default_value_t = 16)]
    concurrency: u16,
    /// Shared primary provider budget while benchmarking (restored to 32 afterwards).
    #[arg(long, default_value_t = 16)]
    budget: u32,
    #[arg(long, default_value_t = 75)]
    rtt_ms: u64,
    /// TCP connect + TLS handshake + greeting, in round trips.
    #[arg(long, default_value_t = 2.5)]
    handshake_rtts: f64,
    /// Provider-side admission/account lookup added to AUTHINFO PASS.
    #[arg(long, default_value_t = 0)]
    auth_ms: u64,
    /// Transfer time added to each ARTICLE response.
    #[arg(long, default_value_t = 100)]
    article_ms: u64,
    /// Distinct segments (articles) sampled per release; at most 12.
    #[arg(long, default_value_t = 12)]
    samples: u64,
    /// Uniform relative jitter on every injected delay.
    #[arg(long, default_value_t = 0.25)]
    jitter: f64,
    /// Drop the connection mid-article on every Nth article (0 disables).
    #[arg(long, default_value_t = 0)]
    drop_every: u64,
    #[arg(long, default_value_t = 5)]
    repeats: u32,
    /// Extra environment for the candidate only, `KEY=VALUE` (repeatable).
    #[arg(long)]
    candidate_env: Vec<String>,
    /// Append one JSON line per run here.
    #[arg(long)]
    raw: Option<PathBuf>,
    #[arg(long, default_value = "")]
    label: String,
}

struct RunResult {
    wall: Duration,
    jobs: Vec<Job>,
    accepted: u64,
    max_open: i64,
}
#[derive(Default, Clone)]
struct Job {
    total: u64,
    claim: u64,
    slot: u64,
    connect: u64,
    profile: u64,
    articles: u64,
    finish: u64,
    opened: u64,
}

fn nzb(id: u64, samples: u64) -> String {
    let mut xml = String::from("<nzb>");
    for file in 0..samples {
        xml.push_str(&format!(
            "<file poster=\"bench\" date=\"1\" subject='\"f{file}.rar\"'><groups><group>a.b</group></groups><segments><segment bytes=\"2048\" number=\"1\">r{id}f{file}@bench</segment></segments></file>"
        ));
    }
    xml.push_str("</nzb>");
    xml
}

async fn reset(pool: &MySqlPool, args: &Args) {
    let last = FIRST_ID + args.releases;
    sqlx::query("DELETE FROM releases WHERE id BETWEEN ? AND ?")
        .bind(FIRST_ID)
        .bind(last)
        .execute(pool)
        .await
        .unwrap();
    sqlx::query("DELETE FROM release_fingerprint_connections WHERE provider='primary'")
        .execute(pool)
        .await
        .unwrap();
    for slot in 1..=args.budget {
        sqlx::query(
            "INSERT INTO release_fingerprint_connections(provider,slot) VALUES('primary',?)",
        )
        .bind(slot)
        .execute(pool)
        .await
        .unwrap();
    }
    for id in FIRST_ID..FIRST_ID + args.releases {
        let xml = nzb(id, args.samples);
        let mut gz = GzEncoder::new(vec![], Compression::fast());
        gz.write_all(xml.as_bytes()).unwrap();
        let body = gz.finish().unwrap();
        let mut sha = Sha256::new();
        sha.update(b"nntmux:nzb-archive-naming:1\0");
        sha.update(xml.as_bytes());
        let digest = hex::encode(sha.finalize());
        let guid = format!("{id:032x}");
        sqlx::query("INSERT INTO releases(id,guid) VALUES(?,?)")
            .bind(id)
            .bind(&guid)
            .execute(pool)
            .await
            .unwrap();
        let mut generation = [0u8; 16];
        rand::rng().fill_bytes(&mut generation);
        sqlx::query("INSERT INTO release_nzb_contents(guid,releases_id,body,source_digest,generation,decoded_bytes,created_at,updated_at) VALUES(?,?,?,?,?,?,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))")
            .bind(&guid)
            .bind(id)
            .bind(body)
            .bind(&digest)
            .bind(hex::encode(generation))
            .bind(xml.len() as u64)
            .execute(pool)
            .await
            .unwrap();
        sqlx::query("INSERT INTO release_fingerprint_jobs(release_id,source_digest,status,available_at,created_at,updated_at) VALUES(?,?,'pending','2000-01-01',UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))")
            .bind(id)
            .bind(&digest)
            .execute(pool)
            .await
            .unwrap();
    }
}

async fn run_once(
    pool: &MySqlPool,
    args: &Args,
    binary: &PathBuf,
    extra: &[(String, String)],
) -> RunResult {
    reset(pool, args).await;
    let rtt = Duration::from_millis(args.rtt_ms);
    let server = ScriptedNntp::start(Script {
        rtt,
        handshake: rtt.mul_f64(args.handshake_rtts),
        auth_extra: Duration::from_millis(args.auth_ms),
        article_extra: Duration::from_millis(args.article_ms),
        drop_every: args.drop_every,
        jitter: args.jitter,
        ..Script::default()
    })
    .await;
    let started = Instant::now();
    let mut command = Command::new(binary);
    command
        .args(["--max-jobs", &args.releases.to_string()])
        .env("DATABASE_URL", &args.database_url)
        .env("NNTP_SERVER", "127.0.0.1")
        .env("NNTP_PORT", server.port.to_string())
        .env("NNTP_SSL", "false")
        .env("NNTP_USERNAME", "bench")
        .env("NNTP_PASSWORD", "bench")
        .env("FINGERPRINT_CONCURRENCY", args.concurrency.to_string())
        .env("FINGERPRINT_ALTERNATE_PERCENT", "0")
        .env("FINGERPRINT_WORKER_ID", "bench")
        .env_remove("NNTP_SERVER_A")
        .stdout(Stdio::piped())
        .stderr(Stdio::null())
        .kill_on_drop(true);
    for (key, value) in extra {
        command.env(key, value);
    }
    let mut child = command.spawn().unwrap();
    let mut lines = BufReader::new(child.stdout.take().unwrap()).lines();
    let mut jobs = vec![];
    while let Some(line) = lines.next_line().await.unwrap() {
        let Ok(event) = serde_json::from_str::<Value>(&line) else {
            continue;
        };
        if event["event"] != "completed" {
            continue;
        }
        let t = &event["timings_ms"];
        let field = |name: &str| t[name].as_u64().unwrap_or(0);
        jobs.push(Job {
            total: field("total"),
            claim: field("claim"),
            slot: field("slot"),
            connect: field("connect"),
            profile: field("profile"),
            articles: field("articles"),
            finish: field("finish"),
            opened: event["connections_opened"].as_u64().unwrap_or(0),
        });
    }
    let status = child.wait().await.unwrap();
    let wall = started.elapsed();
    assert!(status.success(), "worker failed: {status}");
    RunResult {
        wall,
        jobs,
        accepted: server.stats.accepted(),
        max_open: server.stats.max_open(),
    }
}

fn percentile(sorted: &[u64], p: f64) -> u64 {
    if sorted.is_empty() {
        return 0;
    }
    let rank = ((sorted.len() as f64) * p).ceil() as usize;
    sorted[rank.clamp(1, sorted.len()) - 1]
}
fn median(values: &mut [f64]) -> f64 {
    values.sort_by(|a, b| a.total_cmp(b));
    values[values.len() / 2]
}
fn mean(jobs: &[Job], f: impl Fn(&Job) -> u64) -> f64 {
    jobs.iter().map(f).sum::<u64>() as f64 / jobs.len().max(1) as f64
}

#[tokio::main]
async fn main() {
    let args = Args::parse();
    let extra: Vec<(String, String)> = args
        .candidate_env
        .iter()
        .map(|pair| {
            let (k, v) = pair.split_once('=').expect("KEY=VALUE");
            (k.to_string(), v.to_string())
        })
        .collect();
    let pool = MySqlPoolOptions::new()
        .max_connections(4)
        .connect(&args.database_url)
        .await
        .unwrap();
    let names = ["baseline", "candidate"];
    let mut runs: [Vec<RunResult>; 2] = [vec![], vec![]];
    // One discarded warm-up of each binary (page cache, DB buffers).
    let warm = Args {
        releases: args.releases.min(args.concurrency as u64 * 2),
        ..args.clone()
    };
    run_once(&pool, &warm, &args.baseline, &[]).await;
    run_once(&pool, &warm, &args.candidate, &extra).await;
    for repeat in 0..args.repeats {
        let mut order = [0usize, 1];
        order.shuffle(&mut rand::rng());
        for which in order {
            let (binary, env) = if which == 0 {
                (&args.baseline, &[][..])
            } else {
                (&args.candidate, &extra[..])
            };
            let result = run_once(&pool, &args, binary, env).await;
            eprintln!(
                "repeat {repeat} {:<9} wall {:>7.2}s {:>6.2} jobs/s accepted {} max_open {}",
                names[which],
                result.wall.as_secs_f64(),
                result.jobs.len() as f64 / result.wall.as_secs_f64(),
                result.accepted,
                result.max_open
            );
            if let Some(path) = &args.raw {
                let line = serde_json::json!({
                    "label": args.label, "binary": names[which], "repeat": repeat,
                    "wall_s": result.wall.as_secs_f64(), "jobs": result.jobs.len(),
                    "accepted": result.accepted, "max_open": result.max_open,
                    "job_total_ms": result.jobs.iter().map(|j| j.total).collect::<Vec<_>>(),
                    "rtt_ms": args.rtt_ms, "article_ms": args.article_ms, "auth_ms": args.auth_ms,
                    "concurrency": args.concurrency, "budget": args.budget, "releases": args.releases,
                    "drop_every": args.drop_every,
                });
                let mut file = std::fs::OpenOptions::new()
                    .create(true)
                    .append(true)
                    .open(path)
                    .unwrap();
                writeln!(file, "{line}").unwrap();
            }
            runs[which].push(result);
        }
    }
    // Restore the production-sized shared primary budget of the disposable database.
    sqlx::query("DELETE FROM release_fingerprint_connections WHERE provider='primary'")
        .execute(&pool)
        .await
        .unwrap();
    for slot in 1..=32u32 {
        sqlx::query(
            "INSERT INTO release_fingerprint_connections(provider,slot) VALUES('primary',?)",
        )
        .bind(slot)
        .execute(&pool)
        .await
        .unwrap();
    }
    println!(
        "scenario {} rtt={}ms handshake={}rtt auth={}ms article={}ms samples={} concurrency={} budget={} releases={} repeats={} drop_every={}",
        args.label,
        args.rtt_ms,
        args.handshake_rtts,
        args.auth_ms,
        args.article_ms,
        args.samples,
        args.concurrency,
        args.budget,
        args.releases,
        args.repeats,
        args.drop_every
    );
    let mut throughput = [vec![], vec![]];
    let mut latency: [Vec<u64>; 2] = [vec![], vec![]];
    for which in 0..2 {
        for run in &runs[which] {
            throughput[which].push(run.jobs.len() as f64 / run.wall.as_secs_f64());
            latency[which].extend(run.jobs.iter().map(|j| j.total));
        }
        latency[which].sort_unstable();
        let all: Vec<Job> = runs[which].iter().flat_map(|r| r.jobs.clone()).collect();
        let mut t = throughput[which].clone();
        println!(
            "{:<9} jobs/s median {:>6.3} (min {:.3} max {:.3}) | job ms p50 {:>6} p99 {:>6} max {:>6} | mean ms claim {:>5.1} slot {:>6.1} connect {:>6.1} profile {:>6.1} articles {:>7.1} finish {:>5.1} | connections/job {:.2} | server accepted/run {:.0} max_open {}",
            names[which],
            median(&mut t),
            throughput[which].iter().cloned().fold(f64::MAX, f64::min),
            throughput[which].iter().cloned().fold(0.0, f64::max),
            percentile(&latency[which], 0.5),
            percentile(&latency[which], 0.99),
            latency[which].last().copied().unwrap_or(0),
            mean(&all, |j| j.claim),
            mean(&all, |j| j.slot),
            mean(&all, |j| j.connect),
            mean(&all, |j| j.profile),
            mean(&all, |j| j.articles),
            mean(&all, |j| j.finish),
            mean(&all, |j| j.opened),
            runs[which].iter().map(|r| r.accepted).sum::<u64>() as f64 / runs[which].len() as f64,
            runs[which].iter().map(|r| r.max_open).max().unwrap_or(0),
        );
    }
    let mut ratios: Vec<f64> = throughput[1]
        .iter()
        .zip(&throughput[0])
        .map(|(c, b)| c / b)
        .collect();
    let (p99_base, p99_cand) = (
        percentile(&latency[0], 0.99) as f64,
        percentile(&latency[1], 0.99) as f64,
    );
    println!(
        "throughput speedup (candidate/baseline) median-of-pairs {:.3} [pairs: {}] | p99 latency speedup (baseline/candidate) {:.3} | p50 {:.3}",
        median(&mut ratios),
        throughput[1]
            .iter()
            .zip(&throughput[0])
            .map(|(c, b)| format!("{:.3}", c / b))
            .collect::<Vec<_>>()
            .join(" "),
        p99_base / p99_cand,
        percentile(&latency[0], 0.5) as f64 / percentile(&latency[1], 0.5) as f64
    );
    pool.close().await;
}

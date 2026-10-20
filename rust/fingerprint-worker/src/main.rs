use anyhow::{Context, Result, bail};
use clap::Parser;
use nntmux_fingerprint_worker::{collect, database, manifest, nntp::Provider};
use sqlx::mysql::{MySqlConnectOptions, MySqlPoolOptions};
use std::{
    io::Read,
    str::FromStr,
    sync::{
        Arc,
        atomic::{AtomicU64, Ordering},
    },
    time::Duration,
};
use tokio::{sync::watch, task::JoinSet};

#[derive(Parser)]
#[command(
    version,
    about = "Standalone database-backed release fingerprint worker; all NZB/job/result data stays in the database"
)]
struct Args {
    #[arg(long, env = "DATABASE_URL", hide_env_values = true)]
    database_url: Option<String>,
    #[arg(long, env = "FINGERPRINT_WORKER_ID")]
    worker_id: Option<String>,
    #[arg(long,env="FINGERPRINT_CONCURRENCY",default_value_t=8,value_parser=clap::value_parser!(u16).range(1..=128))]
    concurrency: u16,
    #[arg(long, env = "NNTP_SERVER")]
    nntp_server: Option<String>,
    #[arg(long, env = "NNTP_PORT", default_value_t = 563)]
    nntp_port: u16,
    #[arg(long,env="NNTP_SSL",default_value_t=true,action=clap::ArgAction::Set)]
    nntp_tls: bool,
    #[arg(
        long,
        env = "NNTP_USERNAME",
        default_value = "",
        hide_env_values = true
    )]
    nntp_username: String,
    #[arg(
        long,
        env = "NNTP_PASSWORD",
        default_value = "",
        hide_env_values = true
    )]
    nntp_password: String,
    #[arg(long, env = "NNTP_SERVER_A")]
    alternate_server: Option<String>,
    #[arg(long, env = "NNTP_PORT_A", default_value_t = 563)]
    alternate_port: u16,
    #[arg(long,env="NNTP_SSL_A",default_value_t=true,action=clap::ArgAction::Set)]
    alternate_tls: bool,
    #[arg(
        long,
        env = "NNTP_USERNAME_A",
        default_value = "",
        hide_env_values = true
    )]
    alternate_username: String,
    #[arg(
        long,
        env = "NNTP_PASSWORD_A",
        default_value = "",
        hide_env_values = true
    )]
    alternate_password: String,
    #[arg(long,env="FINGERPRINT_ALTERNATE_PERCENT",default_value_t=10,value_parser=clap::value_parser!(u32).range(0..=100))]
    alternate_percent: u32,
    #[arg(long,env="NNTP_TIMEOUT",default_value_t=20,value_parser=clap::value_parser!(u64).range(1..=60))]
    timeout: u64,
    #[arg(long)]
    once: bool,
    #[arg(long, default_value_t = 0)]
    max_jobs: u64,
    /// Analyze an in-memory JSON fixture from stdin; no database/network access.
    #[arg(long)]
    analyze_stdin: bool,
}
#[tokio::main]
async fn main() -> Result<()> {
    let args = Args::parse();
    if args.analyze_stdin {
        let mut json = String::new();
        std::io::stdin()
            .take(40_000_001)
            .read_to_string(&mut json)?;
        if json.len() > 40_000_000 {
            bail!("FixtureBudgetExceeded");
        }
        let v: serde_json::Value = serde_json::from_str(&json)?;
        let xml = v["nzb"].as_str().context("Fixture NZB missing")?;
        let providers: Vec<collect::ProviderEvidence> =
            serde_json::from_value(v["providers"].clone())?;
        let files = manifest::parse(xml)?;
        println!(
            "{}",
            serde_json::to_string(&collect::analyze(&files, &providers))?
        );
        return Ok(());
    }
    let url = args
        .database_url
        .as_deref()
        .context("DATABASE_URL is required")?;
    let options = MySqlConnectOptions::from_str(url).context("Invalid database configuration")?;
    let pool = MySqlPoolOptions::new()
        .max_connections(u32::from(args.concurrency) + 4)
        .acquire_timeout(Duration::from_secs(5))
        .after_connect(|conn, _| {
            Box::pin(async move {
                sqlx::query("SET time_zone='+00:00'").execute(conn).await?;
                Ok(())
            })
        })
        .connect_with(options)
        .await
        .context("Database connection failed")?;
    let host = args
        .nntp_server
        .clone()
        .context("NNTP_SERVER is required")?;
    if host.trim().is_empty() {
        bail!("NNTP_SERVER is required");
    }
    let mut providers = vec![Provider {
        account: "primary".into(),
        host,
        port: args.nntp_port,
        tls: args.nntp_tls,
        username: args.nntp_username.clone(),
        password: args.nntp_password.clone(),
    }];
    if let Some(host) = args
        .alternate_server
        .as_ref()
        .filter(|host| !host.trim().is_empty())
    {
        providers.push(Provider {
            account: "alternate".into(),
            host: host.clone(),
            port: args.alternate_port,
            tls: args.alternate_tls,
            username: args.alternate_username.clone(),
            password: args.alternate_password.clone(),
        });
    }
    let worker = args.worker_id.clone().unwrap_or_else(|| {
        format!(
            "rust-{}-{}",
            std::env::var("HOSTNAME").unwrap_or_else(|_| "laptop".into()),
            std::process::id()
        )
    });
    if worker.len() > 120 {
        bail!("Worker id exceeds 120 bytes");
    }
    let maximum = if args.once { 1 } else { args.max_jobs };
    let next = Arc::new(AtomicU64::new(0));
    let (stop_tx, stop_rx) = watch::channel(false);
    let mut tasks = JoinSet::new();
    let providers = Arc::new(providers);
    let args = Arc::new(args);
    for _ in 0..args.concurrency {
        let pool = pool.clone();
        let worker = worker.clone();
        let next = next.clone();
        let providers = providers.clone();
        let args = args.clone();
        let mut stop = stop_rx.clone();
        tasks.spawn(async move {
            loop {
                if *stop.borrow() { return Ok::<(), anyhow::Error>(()); }
                let ticket = next.fetch_add(1, Ordering::SeqCst);
                if maximum > 0 && ticket >= maximum { return Ok(()); }
                let claim_started = std::time::Instant::now();
                let claim = match database::claim(&pool, &worker).await {
                    Ok(claim) => claim,
                    Err(error) => {
                        if maximum > 0 { return Err(error.context("Database claim failed")); }
                        eprintln!("{{\"event\":\"database_unavailable\"}}");
                        tokio::select! { _ = tokio::time::sleep(Duration::from_secs(2)) => {}, _ = stop.changed() => {} }
                        continue;
                    }
                };
                let claim_ms = elapsed_ms(claim_started);
                let Some(claim) = claim else {
                    if maximum > 0 { return Ok(()); }
                    tokio::select! { _ = tokio::time::sleep(Duration::from_secs(2)) => {}, _ = stop.changed() => {} }
                    continue;
                };
                let result = {
                    let work = tokio::time::timeout(Duration::from_secs(1800), collect::run(&pool, &claim, &worker, &providers, args.alternate_percent, args.timeout));
                    tokio::pin!(work);
                    loop {
                        tokio::select! {
                            result = &mut work => break result.context("Fingerprint job timed out").and_then(|result| result),
                            _ = tokio::time::sleep(Duration::from_secs(30)) => {
                                match tokio::time::timeout(Duration::from_secs(10), database::renew(&pool, &claim)).await {
                                    Ok(Ok(true)) => {},
                                    _ => break Err(anyhow::anyhow!("ClaimLeaseLost")),
                                }
                            }
                        }
                    }
                }; // Cancelling collection drops all sockets before releasing their shared slots.
                let mutation = async {
                    match result {
                        Ok(result) => {
                            let finish_started = std::time::Instant::now();
                            if database::finish(&pool, &claim, &result.status, &result.data).await? {
                                let t = result.timings;
                                println!(
                                    "{{\"event\":\"completed\",\"release_id\":{},\"status\":\"{}\",\"timings_ms\":{{\"claim\":{},\"slot\":{},\"connect\":{},\"profile\":{},\"articles\":{},\"finish\":{},\"total\":{}}},\"connections_opened\":{}}}",
                                    claim.release_id, result.status, claim_ms, t.slot_ms, t.connect_ms, t.profile_ms, t.articles_ms,
                                    elapsed_ms(finish_started), elapsed_ms(claim_started), t.connections_opened
                                );
                            } else {
                                println!("{{\"event\":\"stale_result_discarded\",\"release_id\":{}}}", claim.release_id);
                            }
                        }
                        Err(_) => {
                            database::fail(&pool, &claim, "FingerprintCollectionFailed").await?;
                            eprintln!("{{\"event\":\"retry_or_failed\",\"release_id\":{}}}", claim.release_id);
                        }
                    }
                    Ok::<(), anyhow::Error>(())
                };
                let mutation = tokio::time::timeout(Duration::from_secs(10), mutation).await;
                // Always attempt cleanup even when persistence failed; expired slots recover if DB is down.
                let cleanup = tokio::time::timeout(Duration::from_secs(10), database::release_slots(&pool, &claim)).await;
                if !matches!(mutation, Ok(Ok(()))) || !matches!(cleanup, Ok(Ok(()))) {
                    if maximum > 0 { bail!("Database result/cleanup failed; job leases recover automatically"); }
                    eprintln!("{{\"event\":\"database_write_unavailable\"}}");
                }
            }
        });
    }
    let shutdown = shutdown_signal();
    tokio::pin!(shutdown);
    let mut stopping = false;
    loop {
        tokio::select! {
            result=tasks.join_next()=>match result{Some(result)=>{result??;},None=>break},
            signal=&mut shutdown,if !stopping=>{
                signal?;
                stopping=true;
                let _=stop_tx.send(true);
                eprintln!("{{\"event\":\"draining\"}}");
            }
        }
    }
    pool.close().await;
    Ok(())
}

fn elapsed_ms(since: std::time::Instant) -> u64 {
    u64::try_from(since.elapsed().as_millis()).unwrap_or(u64::MAX)
}

async fn shutdown_signal() -> Result<()> {
    #[cfg(unix)]
    {
        let mut terminate =
            tokio::signal::unix::signal(tokio::signal::unix::SignalKind::terminate())?;
        tokio::select! {result=tokio::signal::ctrl_c()=>result?,_=terminate.recv()=>{}}
    }
    #[cfg(not(unix))]
    tokio::signal::ctrl_c().await?;
    Ok(())
}

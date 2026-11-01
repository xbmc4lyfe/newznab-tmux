//! Benchmark of free-text release-name search: Meilisearch, Tantivy and SQLite FTS5.

mod corpus;
mod engines;
mod queries;

use anyhow::{Context, Result, bail};
use clap::{Parser, Subcommand};
use corpus::{Doc, Rng};
use engines::{Engine, build_fts5, build_indexer, build_meili, build_tantivy, dir_size};
use hdrhistogram::Histogram;
use queries::{LIMIT, Q};
use serde_json::{Value, json};
use std::collections::{BTreeMap, HashSet};
use std::fs::{File, OpenOptions};
use std::io::{BufRead, BufReader, BufWriter, Write};
use std::path::{Path, PathBuf};
use std::sync::Mutex;
use std::sync::atomic::{AtomicBool, Ordering};
use std::time::{Duration, Instant};

#[derive(Parser)]
struct Cli {
    #[command(subcommand)]
    cmd: Cmd,
}

#[derive(Subcommand)]
enum Cmd {
    /// Writes a fake export-shaped corpus for smoke tests.
    Synth {
        #[arg(long)]
        n: usize,
        #[arg(long)]
        out: PathBuf,
    },
    /// Chooses a tier from the MariaDB export and orders it by postdate.
    Prep {
        #[arg(long)]
        export: PathBuf,
        #[arg(long, default_value_t = 0)]
        tier: usize,
        #[arg(long, default_value_t = 500_000_000)]
        min_size: u64,
        #[arg(long)]
        only_big: bool,
        #[arg(long)]
        out: PathBuf,
    },
    /// Builds the query set and its brute-force answers.
    Queries {
        #[arg(long)]
        corpus: PathBuf,
        #[arg(long, default_value_t = 1000)]
        per_class: usize,
        #[arg(long, default_value_t = 7)]
        seed: u64,
        #[arg(long)]
        out: PathBuf,
    },
    /// Builds one index and reports time and size.
    Build {
        #[arg(long)]
        engine: String,
        #[arg(long)]
        corpus: PathBuf,
        #[arg(long)]
        dir: PathBuf,
        #[arg(long, default_value = "http://127.0.0.1:7700")]
        url: String,
        #[arg(long)]
        label: String,
        #[arg(long)]
        out: PathBuf,
    },
    /// Measures recall once, then latency at each client count.
    Bench {
        #[arg(long)]
        engine: String,
        #[arg(long)]
        corpus: PathBuf,
        #[arg(long)]
        queries: PathBuf,
        #[arg(long)]
        dir: PathBuf,
        #[arg(long, default_value = "http://127.0.0.1:7700")]
        url: String,
        #[arg(long, value_delimiter = ',', default_value = "1,8")]
        clients: Vec<usize>,
        #[arg(long, default_value_t = 2)]
        passes: usize,
        #[arg(long, default_value_t = 90)]
        max_secs: u64,
        /// Threads spinning to imitate harvest CPU load.
        #[arg(long, default_value_t = 0)]
        burn: usize,
        /// Also measures fetching this offset plus the match total, on high-match classes.
        #[arg(long)]
        page_offset: Option<usize>,
        #[arg(long)]
        skip_quality: bool,
        #[arg(long)]
        label: String,
        #[arg(long)]
        out: PathBuf,
    },
    /// Publishes while searching, and measures how soon a published release can be found.
    Live {
        #[arg(long)]
        engine: String,
        #[arg(long)]
        corpus: PathBuf,
        #[arg(long)]
        queries: PathBuf,
        #[arg(long)]
        dir: PathBuf,
        #[arg(long, default_value = "http://127.0.0.1:7700")]
        url: String,
        #[arg(long, default_value_t = 2)]
        clients: usize,
        #[arg(long, default_value_t = 20)]
        batch: usize,
        #[arg(long, default_value_t = 1000)]
        every_ms: u64,
        #[arg(long, default_value_t = 90)]
        secs: u64,
        #[arg(long, default_value_t = 0)]
        burn: usize,
        #[arg(long)]
        label: String,
        #[arg(long)]
        out: PathBuf,
    },
}

fn main() -> Result<()> {
    match Cli::parse().cmd {
        Cmd::Synth { n, out } => {
            let docs = corpus::choose_tier(corpus::synthetic(n, 11), 0, 0, false);
            corpus::write_corpus(&out, &docs)
        }
        Cmd::Prep {
            export,
            tier,
            min_size,
            only_big,
            out,
        } => {
            let docs = corpus::read_export(&export)?;
            let docs = corpus::choose_tier(docs, tier, min_size, only_big);
            let big = docs.iter().filter(|d| d.size >= min_size).count();
            eprintln!(
                "tier: {} rows, {big} at or above {min_size} bytes",
                docs.len()
            );
            corpus::write_corpus(&out, &docs)
        }
        Cmd::Queries {
            corpus: c,
            per_class,
            seed,
            out,
        } => {
            let docs = corpus::read_corpus(&c)?;
            let qs = queries::generate(&docs, per_class, seed);
            let mut w = BufWriter::new(File::create(&out)?);
            for q in &qs {
                serde_json::to_writer(&mut w, q)?;
                w.write_all(b"\n")?;
            }
            let mut by: BTreeMap<&str, (u32, u64, u32)> = BTreeMap::new();
            for q in &qs {
                let e = by.entry(&q.class).or_default();
                e.0 += 1;
                e.1 += q.truth_count as u64;
                e.2 = e.2.max(q.terms.len() as u32);
            }
            for (k, (n, t, m)) in by {
                eprintln!(
                    "{k:14} {n:5} queries, mean {:9.1} true matches, up to {m} words",
                    t as f64 / n as f64
                );
            }
            Ok(())
        }
        Cmd::Build {
            engine,
            corpus: c,
            dir,
            url,
            label,
            out,
        } => {
            let docs = corpus::read_corpus(&c)?;
            let t = Instant::now();
            match engine.as_str() {
                "fts5" => build_fts5(&dir, &docs, "full")?,
                "fts5none" => build_fts5(&dir, &docs, "none")?,
                "indexer" => build_indexer(&dir, &docs)?,
                "tantivy" => build_tantivy(&dir, &docs)?,
                "meili-default" | "meili-tuned" | "meili-typo7" => {
                    build_meili(&url, &docs, &engine)?
                }
                other => bail!("unknown build {other}"),
            }
            let secs = t.elapsed().as_secs_f64();
            let size = dir_size(&dir);
            let rec = json!({"kind": "build", "label": label, "engine": engine, "docs": docs.len(), "secs": secs,
                "docs_per_sec": docs.len() as f64 / secs, "bytes": size, "gb": size as f64 / 1e9});
            append(&out, &rec)
        }
        Cmd::Bench {
            engine,
            corpus: c,
            queries: qf,
            dir,
            url,
            clients,
            passes,
            max_secs,
            burn,
            page_offset,
            skip_quality,
            label,
            out,
        } => {
            let docs = corpus::read_corpus(&c)?;
            let qs = read_queries(&qf)?;
            let e = engines::open(&engine, &dir, &url)?;
            let ctx = RunCtx {
                label: &label,
                engine: &engine,
                out: &out,
                burn,
            };
            let t = Instant::now();
            e.client()?.search(&qs[0], LIMIT)?;
            eprintln!(
                "{engine}: first query after open took {:.2} ms",
                t.elapsed().as_secs_f64() * 1000.0
            );
            if !skip_quality {
                evaluate(e.as_ref(), &qs, &docs, &ctx)?;
            }
            for &n in &clients {
                latency(
                    e.as_ref(),
                    &qs,
                    n,
                    passes,
                    Duration::from_secs(max_secs),
                    None,
                    &ctx,
                )?;
            }
            if let Some(offset) = page_offset {
                let deep: Vec<Q> = qs
                    .iter()
                    .filter(|q| matches!(q.class.as_str(), "common" | "group_res" | "tv_title"))
                    .cloned()
                    .collect();
                for &n in &clients {
                    latency(
                        e.as_ref(),
                        &deep,
                        n,
                        passes,
                        Duration::from_secs(max_secs),
                        Some(offset),
                        &ctx,
                    )?;
                }
            }
            Ok(())
        }
        Cmd::Live {
            engine,
            corpus: c,
            queries: qf,
            dir,
            url,
            clients,
            batch,
            every_ms,
            secs,
            burn,
            label,
            out,
        } => {
            let docs = corpus::read_corpus(&c)?;
            let qs = read_queries(&qf)?;
            let e = engines::open(&engine, &dir, &url)?;
            let ctx = RunCtx {
                label: &label,
                engine: &engine,
                out: &out,
                burn,
            };
            live(
                e.as_ref(),
                &docs,
                &qs,
                clients,
                batch,
                Duration::from_millis(every_ms),
                Duration::from_secs(secs),
                &ctx,
            )
        }
    }
}

struct RunCtx<'a> {
    label: &'a str,
    engine: &'a str,
    out: &'a Path,
    burn: usize,
}

fn read_queries(p: &Path) -> Result<Vec<Q>> {
    let mut v = Vec::new();
    for l in BufReader::new(File::open(p).context("open queries")?).lines() {
        v.push(serde_json::from_str(&l?)?);
    }
    Ok(v)
}

fn append(path: &Path, rec: &Value) -> Result<()> {
    println!("{rec}");
    let mut f = OpenOptions::new().create(true).append(true).open(path)?;
    writeln!(f, "{rec}")?;
    Ok(())
}

/// Spins threads that imitate harvest work while the closure runs.
fn with_burn<T>(n: usize, f: impl FnOnce() -> T) -> T {
    let stop = AtomicBool::new(false);
    std::thread::scope(|s| {
        for i in 0..n {
            let stop = &stop;
            s.spawn(move || {
                let mut x = i as u64 + 1;
                while !stop.load(Ordering::Relaxed) {
                    for _ in 0..4096 {
                        x = std::hint::black_box(
                            x.wrapping_mul(0x9E37_79B9_7F4A_7C15).rotate_left(31),
                        );
                    }
                }
            });
        }
        let r = f();
        stop.store(true, Ordering::Relaxed);
        r
    })
}

/// Result quality for each class, judged against the brute-force answers.
fn evaluate(e: &dyn Engine, qs: &[Q], docs: &[Doc], ctx: &RunCtx<'_>) -> Result<()> {
    #[derive(Default)]
    struct S {
        n: u64,
        with_truth: u64,
        false_zero: u64,
        returned: u64,
        correct: u64,
        recall_sum: f64,
        target_n: u64,
        hit1: u64,
        hit10: u64,
        hit100: u64,
        wrong_ep: u64,
        ep_returned: u64,
        errors: u64,
        misses: Vec<String>,
    }
    let mut stats: BTreeMap<String, S> = BTreeMap::new();
    let mut c = e.client()?;
    let is_ep = |t: &str| {
        let b = t.as_bytes();
        b.len() >= 4
            && b[0] == b's'
            && b[1].is_ascii_digit()
            && t[1..].contains('e')
            && t[1..].chars().all(|x| x.is_ascii_digit() || x == 'e')
    };
    for q in qs {
        let s = stats.entry(q.class.clone()).or_default();
        s.n += 1;
        let hits = match c.search(q, LIMIT) {
            Ok(h) => h,
            Err(err) => {
                s.errors += 1;
                if s.misses.len() < 3 {
                    s.misses.push(format!("error {err}: {}", q.terms.join(" ")));
                }
                continue;
            }
        };
        s.returned += hits.len() as u64;
        if q.target.is_none()
            && s.misses.len() < 6
            && let Some(d) = hits
                .iter()
                .filter_map(|&h| docs.get(h as usize))
                .find(|d| !q.matches(d))
        {
            s.misses.push(format!(
                "false positive for [{}]: {}",
                q.terms.join(" "),
                d.name
            ));
        }
        s.correct += hits
            .iter()
            .filter(|&&h| docs.get(h as usize).is_some_and(|d| q.matches(d)))
            .count() as u64;
        if q.truth_count > 0 {
            s.with_truth += 1;
            if hits.is_empty() {
                s.false_zero += 1;
                if s.misses.len() < 3 {
                    s.misses.push(format!("empty: {}", q.terms.join(" ")));
                }
            }
            let set: HashSet<u32> = hits.iter().copied().collect();
            let found = q.truth_newest.iter().filter(|t| set.contains(t)).count();
            s.recall_sum += found as f64 / q.truth_newest.len() as f64;
        }
        if let Some(t) = q.target {
            s.target_n += 1;
            let pos = hits.iter().position(|&h| h == t);
            s.hit1 += pos.is_some_and(|p| p < 1) as u64;
            s.hit10 += pos.is_some_and(|p| p < 10) as u64;
            s.hit100 += pos.is_some() as u64;
            if pos.is_none() && s.misses.len() < 3 {
                s.misses
                    .push(format!("target {t} missing: {}", q.terms.join(" ")));
            }
        }
        if let Some(want) = q.terms.iter().find(|t| is_ep(t)) {
            for &h in &hits {
                s.ep_returned += 1;
                let Some(d) = docs.get(h as usize) else {
                    continue;
                };
                let toks = corpus::tokens(&d.name);
                if !toks.iter().any(|t| t == want) && toks.iter().any(|t| is_ep(t)) {
                    s.wrong_ep += 1;
                }
            }
        }
    }
    for (class, s) in stats {
        let rate = |a: u64, b: u64| {
            if b == 0 {
                Value::Null
            } else {
                json!(a as f64 / b as f64)
            }
        };
        append(
            ctx.out,
            &json!({"kind": "quality", "label": ctx.label, "engine": ctx.engine, "class": class, "n": s.n,
            "errors": s.errors,
            "precision": rate(s.correct, s.returned),
            "false_zero_rate": rate(s.false_zero, s.with_truth),
            "recall_newest": if s.with_truth == 0 { Value::Null } else { json!(s.recall_sum / s.with_truth as f64) },
            "hit_at_1": rate(s.hit1, s.target_n), "hit_at_10": rate(s.hit10, s.target_n), "hit_at_100": rate(s.hit100, s.target_n),
            "wrong_episode_rate": rate(s.wrong_ep, s.ep_returned), "samples": s.misses}),
        )?;
    }
    Ok(())
}

fn new_hist() -> Histogram<u64> {
    Histogram::new_with_bounds(1, 120_000_000, 3).unwrap()
}

fn report(
    ctx: &RunCtx<'_>,
    mode: &str,
    clients: usize,
    wall: f64,
    total: u64,
    hists: BTreeMap<String, Histogram<u64>>,
) -> Result<()> {
    let mut all = new_hist();
    let row = |class: &str, h: &Histogram<u64>| {
        let ms = |q: f64| h.value_at_quantile(q) as f64 / 1000.0;
        json!({"kind": "latency", "mode": mode, "label": ctx.label, "engine": ctx.engine, "clients": clients, "burn": ctx.burn,
            "class": class, "n": h.len(), "p50_ms": ms(0.5), "p95_ms": ms(0.95), "p99_ms": ms(0.99), "max_ms": h.max() as f64 / 1000.0,
            "qps": total as f64 / wall})
    };
    for (class, h) in &hists {
        all.add(h).unwrap();
        append(ctx.out, &row(class, h))?;
    }
    append(ctx.out, &row("ALL", &all))
}

/// Closed-loop latency: each client sends its next query as soon as the last one returns.
fn latency(
    e: &dyn Engine,
    qs: &[Q],
    clients: usize,
    passes: usize,
    max: Duration,
    page: Option<usize>,
    ctx: &RunCtx<'_>,
) -> Result<()> {
    let merged: Mutex<BTreeMap<String, Histogram<u64>>> = Mutex::new(BTreeMap::new());
    let total = Mutex::new(0u64);
    let start = Instant::now();
    with_burn(ctx.burn, || {
        std::thread::scope(|s| -> Result<()> {
            let mut handles = Vec::new();
            for i in 0..clients {
                let (merged, total) = (&merged, &total);
                let mut c = e.client()?;
                handles.push(s.spawn(move || -> Result<()> {
                    let mut order: Vec<usize> = (0..qs.len()).collect();
                    Rng::new(1000 + i as u64).shuffle(&mut order);
                    let mut local: BTreeMap<String, Histogram<u64>> = BTreeMap::new();
                    let mut count = 0u64;
                    'run: for _ in 0..passes {
                        for &j in &order {
                            if start.elapsed() > max {
                                break 'run;
                            }
                            let t = Instant::now();
                            match page {
                                Some(offset) => drop(c.page(&qs[j], offset, LIMIT)?),
                                None => drop(c.search(&qs[j], LIMIT)?),
                            }
                            let us = t.elapsed().as_micros() as u64;
                            local
                                .entry(qs[j].class.clone())
                                .or_insert_with(new_hist)
                                .saturating_record(us.max(1));
                            count += 1;
                        }
                    }
                    *total.lock().unwrap() += count;
                    let mut m = merged.lock().unwrap();
                    for (k, h) in local {
                        m.entry(k).or_insert_with(new_hist).add(h).unwrap();
                    }
                    Ok(())
                }));
            }
            for h in handles {
                h.join().unwrap()?;
            }
            Ok(())
        })
    })?;
    let wall = start.elapsed().as_secs_f64();
    let mode = if page.is_some() { "paged" } else { "steady" };
    report(
        ctx,
        mode,
        clients,
        wall,
        *total.lock().unwrap(),
        merged.into_inner().unwrap(),
    )
}

/// Publishes batches on a timer while clients search. Each batch carries one uniquely named
/// release, and a prober times how long after publish starts it can be found.
#[allow(clippy::too_many_arguments)]
fn live(
    e: &dyn Engine,
    docs: &[Doc],
    qs: &[Q],
    clients: usize,
    batch: usize,
    every: Duration,
    secs: Duration,
    ctx: &RunCtx<'_>,
) -> Result<()> {
    let merged: Mutex<BTreeMap<String, Histogram<u64>>> = Mutex::new(BTreeMap::new());
    let total = Mutex::new(0u64);
    let stop = AtomicBool::new(false);
    let mut visible = new_hist();
    let mut publish = new_hist();
    let mut timeouts = 0u64;
    let start = Instant::now();
    with_burn(ctx.burn, || {
        std::thread::scope(|s| -> Result<()> {
            let mut handles = Vec::new();
            for i in 0..clients {
                let (merged, total, stop) = (&merged, &total, &stop);
                let mut c = e.client()?;
                handles.push(s.spawn(move || -> Result<()> {
                    let mut order: Vec<usize> = (0..qs.len()).collect();
                    Rng::new(2000 + i as u64).shuffle(&mut order);
                    let mut local: BTreeMap<String, Histogram<u64>> = BTreeMap::new();
                    let mut count = 0u64;
                    for &j in order.iter().cycle() {
                        if stop.load(Ordering::Relaxed) {
                            break;
                        }
                        let t = Instant::now();
                        c.search(&qs[j], LIMIT)?;
                        local
                            .entry(qs[j].class.clone())
                            .or_insert_with(new_hist)
                            .saturating_record((t.elapsed().as_micros() as u64).max(1));
                        count += 1;
                    }
                    *total.lock().unwrap() += count;
                    let mut m = merged.lock().unwrap();
                    for (k, h) in local {
                        m.entry(k).or_insert_with(new_hist).add(h).unwrap();
                    }
                    Ok(())
                }));
            }
            let mut w = e.writer()?;
            let mut probe_client = e.client()?;
            let mut rng = Rng::new(3);
            let mut next_seq =
                docs.len() as u32 + 1_000_000 * (start.elapsed().subsec_nanos() % 1000 + 1);
            let run = (|| -> Result<()> {
                while start.elapsed() < secs {
                    let tick = Instant::now();
                    let mut b: Vec<Doc> = (0..batch)
                        .map(|_| {
                            let mut d = docs[rng.below(docs.len())].clone();
                            d.seq = next_seq;
                            next_seq += 1;
                            d
                        })
                        .collect();
                    let token = format!("zzprobe{}", b[0].seq);
                    b[0].name = format!("{}.{token}", b[0].name);
                    let want = b[0].seq;
                    let t0 = Instant::now();
                    w.publish(&b)?;
                    publish.saturating_record((t0.elapsed().as_micros() as u64).max(1));
                    let probe = Q {
                        class: "probe".into(),
                        terms: vec![token],
                        neg: vec![],
                        cat: None,
                        min_size: None,
                        target: None,
                        truth_count: 0,
                        truth_newest: vec![],
                    };
                    loop {
                        if probe_client.search(&probe, 1)?.contains(&want) {
                            visible.saturating_record((t0.elapsed().as_micros() as u64).max(1));
                            break;
                        }
                        if t0.elapsed() > Duration::from_secs(60) {
                            timeouts += 1;
                            break;
                        }
                        std::thread::sleep(Duration::from_millis(5));
                    }
                    if let Some(rest) = every.checked_sub(tick.elapsed()) {
                        std::thread::sleep(rest);
                    }
                }
                Ok(())
            })();
            stop.store(true, Ordering::Relaxed);
            for h in handles {
                h.join().unwrap()?;
            }
            run
        })
    })?;
    let wall = start.elapsed().as_secs_f64();
    let ms = |h: &Histogram<u64>, q: f64| h.value_at_quantile(q) as f64 / 1000.0;
    append(
        ctx.out,
        &json!({"kind": "live", "label": ctx.label, "engine": ctx.engine, "batch": batch, "every_ms": every.as_millis() as u64, "burn": ctx.burn,
        "batches": visible.len(), "timeouts": timeouts,
        "visible_p50_ms": ms(&visible, 0.5), "visible_p95_ms": ms(&visible, 0.95), "visible_max_ms": visible.max() as f64 / 1000.0,
        "publish_p50_ms": ms(&publish, 0.5), "publish_p95_ms": ms(&publish, 0.95)}),
    )?;
    report(
        ctx,
        "live",
        clients,
        wall,
        *total.lock().unwrap(),
        merged.into_inner().unwrap(),
    )
}

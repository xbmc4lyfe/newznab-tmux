//! Measures API latency end to end over a synthetic catalog: query parsing, the index or the ID
//! indexes, SQLite hydration and the XML page, which is everything but the HTTP hop.
//!
//! ```bash
//! cargo run --release -p indexer-serve --example api_latency -- 1000000 2000
//! ```
//!
//! The arguments are the number of releases and the requests per kind. Names are generated, so
//! word frequencies differ from real data; use it to compare builds, not to promise a number.

use std::sync::Arc;
use std::time::Instant;

use anyhow::Result;
use indexer_config::{ApiConfig, Secret};
use indexer_serve::{Api, open_index};
use indexer_store::rusqlite::params;
use indexer_store::{Catalog, NzbStore, StorePaths};

const RESOLUTIONS: [&str; 3] = ["1080p", "2160p", "720p"];
const SOURCES: [&str; 4] = ["WEB-DL", "BluRay.REMUX", "BluRay", "WEBRip"];
const GROUPS: [&str; 6] = ["FLUX", "FraMeSToR", "CiNEPHiLES", "NTb", "HONE", "EPSiLON"];
const WORDS: [&str; 12] = [
    "silo", "house", "dragon", "night", "black", "city", "lost", "star", "river", "king", "blue",
    "last",
];

/// A small deterministic generator, so runs compare.
struct Rng(u64);

impl Rng {
    fn next(&mut self, below: u64) -> u64 {
        self.0 ^= self.0 << 13;
        self.0 ^= self.0 >> 7;
        self.0 ^= self.0 << 17;
        self.0 % below
    }

    fn pick<'a>(&mut self, items: &[&'a str]) -> &'a str {
        items[self.next(items.len() as u64) as usize]
    }
}

fn show_title(show: u64) -> String {
    let a = WORDS[(show % 12) as usize];
    let b = WORDS[(show / 12 % 12) as usize];
    format!("{a}.{b}.{show}")
}

fn populate(catalog: &mut Catalog, releases: u64) -> Result<()> {
    let mut rng = Rng(0x9e37_79b9_7f4a_7c15);
    let tx = catalog.conn_mut().transaction()?;
    {
        let mut insert = tx.prepare(
            "INSERT INTO releases (id, guid, name, search_name, size_bytes, total_parts,
                 completion, group_id, posted_at, added_at, legacy_category, password_status,
                 is_renamed, grabs, inner_file_count, imdb_id, tmdb_id, tvdb_id, season, episode)
             VALUES (?1, ?2, ?3, ?3, ?4, 50, 100, 1, ?5, ?5, ?6, 0, 1, 0, 1, ?7, ?8, ?9, ?10, ?11)",
        )?;
        for id in 1..=releases as i64 {
            let posted = 1_600_000_000 + id * 30;
            let res = rng.pick(&RESOLUTIONS);
            let source = rng.pick(&SOURCES);
            let group = rng.pick(&GROUPS);
            let size = 1_000_000_000 + rng.next(60_000_000_000) as i64;
            if id % 4 == 0 {
                let movie = rng.next(50_000);
                let name = format!(
                    "{}.{}.{res}.{source}-{group}",
                    show_title(movie),
                    1980 + movie % 45
                );
                let category = if res == "2160p" { 2045 } else { 2040 };
                insert.execute(params![
                    id,
                    format!("{id:040x}"),
                    name,
                    size,
                    posted,
                    category,
                    format!("{:07}", movie + 1),
                    (movie + 1) as i64,
                    None::<i64>,
                    None::<i64>,
                    None::<i64>
                ])?;
            } else {
                let show = rng.next(20_000);
                let season = 1 + rng.next(8);
                let episode = 1 + rng.next(12);
                let name = format!(
                    "{}.S{season:02}E{episode:02}.{res}.{source}-{group}",
                    show_title(show)
                );
                let category = if res == "2160p" { 5045 } else { 5040 };
                insert.execute(params![
                    id,
                    format!("{id:040x}"),
                    name,
                    size,
                    posted,
                    category,
                    None::<String>,
                    None::<i64>,
                    (show + 1) as i64,
                    season as i64,
                    episode as i64
                ])?;
            }
        }
    }
    tx.commit()?;
    Ok(())
}

fn percentile(sorted: &[f64], p: f64) -> f64 {
    let rank = ((sorted.len() as f64 - 1.0) * p).round() as usize;
    sorted[rank]
}

fn main() -> Result<()> {
    let mut args = std::env::args().skip(1);
    let releases: u64 = args.next().map_or(Ok(1_000_000), |a| a.parse())?;
    let requests: usize = args.next().map_or(Ok(2000), |a| a.parse())?;
    let dir = tempfile::tempdir()?;
    let paths = StorePaths::under(dir.path());

    let started = Instant::now();
    let mut catalog = Catalog::open(&paths.catalog)?;
    populate(&mut catalog, releases)?;
    drop(catalog);
    drop(NzbStore::open(&paths.nzbs)?);
    println!("catalog: {releases} releases in {:.1?}", started.elapsed());

    let started = Instant::now();
    let index = open_index(&Catalog::open_read_only(&paths.catalog)?, &paths.search)?;
    println!("index build: {:.1?}", started.elapsed());

    let config = ApiConfig {
        api_keys: vec![Secret::new("k".into())],
        ..ApiConfig::default()
    };
    let api = Api::new(&config, &paths, Arc::new(index))?;

    let mut rng = Rng(42);
    let kinds: [(&str, Box<dyn FnMut(&mut Rng) -> String>); 6] = [
        (
            "search q=two words",
            Box::new(|r| format!("t=search&q={}+{}", r.pick(&WORDS), r.pick(&WORDS))),
        ),
        (
            "search q=title s01e02 2160p",
            Box::new(|r| {
                let show = r.next(20_000);
                format!(
                    "t=search&q={}+s0{}e0{}+2160p",
                    show_title(show).replace('.', "+"),
                    1 + r.next(8),
                    1 + r.next(9)
                )
            }),
        ),
        (
            "tvsearch q+season+ep",
            Box::new(|r| {
                let show = r.next(20_000);
                format!(
                    "t=tvsearch&q={}&season={}&ep={}",
                    show_title(show).replace('.', "+"),
                    1 + r.next(8),
                    1 + r.next(12)
                )
            }),
        ),
        (
            "tvsearch tvdbid+season+ep",
            Box::new(|r| {
                format!(
                    "t=tvsearch&tvdbid={}&season={}&ep={}",
                    1 + r.next(20_000),
                    1 + r.next(8),
                    1 + r.next(12)
                )
            }),
        ),
        (
            "tvsearch tvdbid (whole show)",
            Box::new(|r| format!("t=tvsearch&tvdbid={}", 1 + r.next(20_000))),
        ),
        (
            "movie imdbid",
            Box::new(|r| format!("t=movie&imdbid=tt{:07}", 1 + r.next(50_000))),
        ),
    ];
    println!(
        "\n{:<30} {:>9} {:>9} {:>9} {:>9}",
        "request", "p50 ms", "p90 ms", "p99 ms", "max ms"
    );
    for (name, mut make) in kinds {
        let queries: Vec<String> = (0..requests)
            .map(|_| format!("{}&apikey=k&limit=100", make(&mut rng)))
            .collect();
        // Warm the page cache and the connection pool.
        for query in queries.iter().take(50) {
            api.respond(query, Some("bench"));
        }
        let mut times: Vec<f64> = queries
            .iter()
            .map(|query| {
                let started = Instant::now();
                let reply = api.respond(query, Some("bench"));
                assert!(reply.text().contains("<rss"), "{query}: {}", reply.text());
                started.elapsed().as_secs_f64() * 1000.0
            })
            .collect();
        times.sort_by(f64::total_cmp);
        println!(
            "{name:<30} {:>9.3} {:>9.3} {:>9.3} {:>9.3}",
            percentile(&times, 0.5),
            percentile(&times, 0.9),
            percentile(&times, 0.99),
            times[times.len() - 1]
        );
    }
    Ok(())
}

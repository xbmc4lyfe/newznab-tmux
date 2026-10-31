//! Release corpus: loading the MariaDB export, choosing a tier, and synthetic names for smoke tests.

use anyhow::{Context, Result};
use std::fs::File;
use std::io::{BufRead, BufReader, BufWriter, Write};
use std::path::Path;

#[derive(Clone, Debug)]
pub struct Doc {
    /// Position in postdate order, so a larger seq is always a newer release.
    pub seq: u32,
    pub id: u64,
    pub cat: u32,
    pub size: u64,
    pub ts: i64,
    pub name: String,
}

/// Lowercase and split on anything not alphanumeric, which matches FTS5 `unicode61` and Tantivy's
/// `SimpleTokenizer` plus `LowerCaser`.
pub fn tokens(s: &str) -> Vec<String> {
    s.split(|c: char| !c.is_alphanumeric())
        .filter(|t| !t.is_empty())
        .map(str::to_lowercase)
        .collect()
}

/// Reads the export: id, categories_id, size, postdate, videos_id, imdbid, searchname, name.
pub fn read_export(path: &Path) -> Result<Vec<Doc>> {
    let reader = BufReader::with_capacity(1 << 20, File::open(path).context("open export")?);
    let mut docs = Vec::new();
    let mut malformed = 0u64;
    for line in reader.split(b'\n') {
        let line = String::from_utf8_lossy(&line?).into_owned();
        let cols: Vec<&str> = line.split('\t').collect();
        if cols.len() != 8 || cols[6].is_empty() {
            malformed += 1;
            continue;
        }
        docs.push(Doc {
            seq: 0,
            id: cols[0].parse().unwrap_or(0),
            cat: cols[1].parse().unwrap_or(0),
            size: cols[2].parse().unwrap_or(0),
            ts: cols[3].parse().unwrap_or(0),
            name: cols[6].replace("\\t", " ").replace("\\n", " "),
        });
    }
    eprintln!("export: {} rows, {malformed} skipped", docs.len());
    Ok(docs)
}

/// Keeps every release at or above `min_size`, then fills up to `tier` with a stable pseudorandom
/// sample of the rest. `tier == 0` keeps everything; `only_big` keeps just the large releases.
pub fn choose_tier(mut docs: Vec<Doc>, tier: usize, min_size: u64, only_big: bool) -> Vec<Doc> {
    let mix = |id: u64| id.wrapping_mul(0x9E37_79B9_7F4A_7C15).rotate_left(23);
    docs.sort_by_key(|d| (d.size < min_size, mix(d.id)));
    if only_big {
        docs.retain(|d| d.size >= min_size);
    } else if tier > 0 {
        docs.truncate(tier);
    }
    docs.sort_by_key(|d| (d.ts, d.id));
    for (i, d) in docs.iter_mut().enumerate() {
        d.seq = i as u32;
    }
    docs
}

pub fn write_corpus(path: &Path, docs: &[Doc]) -> Result<()> {
    let mut w = BufWriter::new(File::create(path)?);
    for d in docs {
        writeln!(
            w,
            "{}\t{}\t{}\t{}\t{}\t{}",
            d.seq, d.id, d.cat, d.size, d.ts, d.name
        )?;
    }
    w.flush()?;
    Ok(())
}

pub fn read_corpus(path: &Path) -> Result<Vec<Doc>> {
    let reader = BufReader::with_capacity(1 << 20, File::open(path).context("open corpus")?);
    let mut docs = Vec::new();
    for line in reader.lines() {
        let line = line?;
        let mut c = line.splitn(6, '\t');
        let mut next = || c.next().context("short corpus row");
        docs.push(Doc {
            seq: next()?.parse()?,
            id: next()?.parse()?,
            cat: next()?.parse()?,
            size: next()?.parse()?,
            ts: next()?.parse()?,
            name: next()?.to_string(),
        });
    }
    Ok(docs)
}

/// Small deterministic PRNG, so query sets and client orders reproduce between runs.
pub struct Rng(u64);

impl Rng {
    pub fn new(seed: u64) -> Self {
        Rng(seed.wrapping_mul(0x9E37_79B9_7F4A_7C15) | 1)
    }
    pub fn next(&mut self) -> u64 {
        self.0 ^= self.0 << 13;
        self.0 ^= self.0 >> 7;
        self.0 ^= self.0 << 17;
        self.0
    }
    pub fn below(&mut self, n: usize) -> usize {
        (self.next() % n as u64) as usize
    }
    pub fn shuffle<T>(&mut self, v: &mut [T]) {
        for i in (1..v.len()).rev() {
            v.swap(i, self.below(i + 1));
        }
    }
}

/// Fake scene-style names, only for checking the harness. Never used for reported numbers.
pub fn synthetic(n: usize, seed: u64) -> Vec<Doc> {
    let mut r = Rng::new(seed);
    let syl = [
        "ka", "lo", "mi", "ra", "ten", "sil", "vor", "bel", "dan", "qu", "ex", "pho", "rin", "sta",
        "tor", "ul",
    ];
    let word = |r: &mut Rng| -> String {
        let mut w = String::new();
        for _ in 0..(2 + r.below(3)) {
            w.push_str(syl[r.below(syl.len())]);
        }
        let mut c = w.chars();
        let first = c.next().unwrap().to_uppercase().to_string();
        first + c.as_str()
    };
    let shows: Vec<String> = (0..n / 40 + 10)
        .map(|_| {
            (0..1 + r.below(4))
                .map(|_| word(&mut r))
                .collect::<Vec<_>>()
                .join(".")
        })
        .collect();
    let res = ["720p", "1080p", "1080p", "2160p"];
    let src = [
        "WEB-DL.DDP5.1.H.264",
        "WEBRip.x265",
        "BluRay.x264",
        "BluRay.REMUX.HEVC.TrueHD.7.1",
        "HDTV.x264",
    ];
    let grp = [
        "FraMeSToR",
        "CiNEPHiLES",
        "NTb",
        "BLURANiUM",
        "EPSiLON",
        "playBD",
        "FLUX",
        "SMURF",
    ];
    (0..n)
        .map(|i| {
            let k = r.below(10);
            let t = &shows[r.below(shows.len())];
            let tail = format!(
                "{}.{}-{}",
                res[r.below(4)],
                src[r.below(5)],
                grp[r.below(8)]
            );
            let (name, cat) = match k {
                0..=1 => (format!("{:032x}", r.next() as u128 * r.next() as u128), 10),
                2..=6 => (
                    format!("{t}.S{:02}E{:02}.{tail}", 1 + r.below(12), 1 + r.below(24)),
                    5040,
                ),
                _ => (format!("{t}.{}.{tail}", 1960 + r.below(66),), 2040),
            };
            Doc {
                seq: 0,
                id: i as u64 + 1,
                cat,
                size: 200_000_000 + r.next() % 60_000_000_000,
                ts: 1_500_000_000 + (r.next() % 300_000_000) as i64,
                name,
            }
        })
        .collect()
}

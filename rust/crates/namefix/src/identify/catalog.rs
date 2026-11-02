//! A whole run over the SQLite import (`catalog.db` and `nzbs.db`): load the evidence, climb the
//! ladder on every worker, then report, and write only when asked. A dry run opens both files
//! read-only, and nothing here connects to MariaDB.

use std::collections::{BTreeMap, HashMap};
use std::fmt::Write as _;
use std::io::Read as _;
use std::path::{Path, PathBuf};
use std::sync::Mutex;
use std::sync::atomic::{AtomicUsize, Ordering};
use std::thread;
use std::time::{Instant, SystemTime, UNIX_EPOCH};

use anyhow::{Context as _, Result};
use base64::Engine;
use flate2::read::GzDecoder;
use rusqlite::types::ValueRef;
use rusqlite::{Connection, OpenFlags, OptionalExtension, params};

use super::donor::{DonorIndex, DonorKey, DonorMatch, MIN_DONOR_HASHED_SIZE, size_can_identify};
use super::ladder::{Context, Evidence, Flags, Hooks, Identification, Outcome, identify};
use super::local_predb::{LocalPredb, PredbRow, parse_size_mib};
use super::name::{self, Scope};
use super::nzb;

/// The releases this stage exists for: uncategorized, with no PreDB link and never renamed. The
/// import already left out everything below 500 MB.
const TARGET: &str = "legacy_category IN (10, 20) AND predb_id IS NULL AND is_renamed = 0";
const SAMPLES_PER_METHOD: usize = 4;
const WORKER_CHUNK: usize = 64;
const MAX_NZB_BYTES: u64 = 268_435_456;

/// The table a real run writes, created on first use. It doesn't touch the import's own schema
/// version, because this is not one of the importer's migrations.
const SCHEMA: &str = "CREATE TABLE IF NOT EXISTS release_identifications (
    release_id       INTEGER PRIMARY KEY,
    name             TEXT NOT NULL,
    method           TEXT NOT NULL,
    evidence_source  TEXT NOT NULL,
    confidence       REAL NOT NULL,
    scope            TEXT NOT NULL,
    kind             TEXT NOT NULL,
    title            TEXT NOT NULL,
    year             INTEGER,
    season           INTEGER,
    episode          INTEGER,
    resolution       TEXT,
    source           TEXT,
    codec            TEXT,
    release_group    TEXT,
    predb_id         INTEGER,
    donor_release_id INTEGER,
    note             TEXT,
    identified_at    INTEGER NOT NULL
);
CREATE INDEX IF NOT EXISTS release_identifications_method
    ON release_identifications (method, scope);";

/// Opens without touching the disk where it can. The import is in WAL mode, where a plain
/// read-only open still creates side files. With nothing pending in the WAL, `immutable` needs
/// none. With something pending, it has to be read the ordinary way or those rows go unseen.
fn open_read_only(path: &Path) -> Result<Connection> {
    let wal = format!("{}-wal", path.display());
    let wal_pending = std::fs::metadata(wal).is_ok_and(|wal| wal.len() > 0);
    let uri = format!(
        "file:{}{}",
        path.display()
            .to_string()
            .replace('%', "%25")
            .replace('?', "%3f")
            .replace('#', "%23"),
        if wal_pending {
            "?mode=ro"
        } else {
            "?immutable=1"
        }
    );
    let conn = Connection::open_with_flags(
        &uri,
        OpenFlags::SQLITE_OPEN_READ_ONLY
            | OpenFlags::SQLITE_OPEN_NO_MUTEX
            | OpenFlags::SQLITE_OPEN_URI,
    )
    .with_context(|| format!("opening {}", path.display()))?;
    conn.execute_batch("PRAGMA busy_timeout = 10000; PRAGMA mmap_size = 4294967296;")?;
    Ok(conn)
}

#[derive(Clone, Debug)]
pub struct Options {
    pub data_dir: PathBuf,
    pub threads: usize,
    pub limit: Option<usize>,
    /// Writes `release_identifications`, which also migrates the catalog.
    pub apply: bool,
}

#[derive(Debug, Default)]
pub struct Report {
    pub targets: usize,
    pub identified: Vec<Identification>,
    pub ambiguous: BTreeMap<&'static str, usize>,
    pub unidentified: usize,
    pub evidence: BTreeMap<&'static str, usize>,
    pub payload_ratios: Vec<f64>,
    pub elapsed_seconds: f64,
    pub applied: bool,
}

fn text(value: ValueRef<'_>) -> String {
    match value {
        ValueRef::Text(bytes) | ValueRef::Blob(bytes) => {
            String::from_utf8_lossy(bytes).into_owned()
        }
        ValueRef::Integer(number) => number.to_string(),
        ValueRef::Real(number) => number.to_string(),
        ValueRef::Null => String::new(),
    }
}

fn load_predb(catalog: &Connection) -> Result<LocalPredb> {
    let mut index = LocalPredb::default();
    let mut statement = catalog
        .prepare("SELECT id, title, category, size, files, pre_at FROM predb ORDER BY id")?;
    let mut rows = statement.query([])?;
    while let Some(row) = rows.next()? {
        index.insert(PredbRow {
            id: row.get(0)?,
            title: text(row.get_ref(1)?),
            category: text(row.get_ref(2)?),
            size_mib: parse_size_mib(&text(row.get_ref(3)?)),
            files: text(row.get_ref(4)?).trim().parse().unwrap_or(0),
            pre_at: row.get(5)?,
        });
    }
    index.finish();
    Ok(index)
}

fn load_targets(
    catalog: &Connection,
    filter: &str,
    limit: Option<usize>,
) -> Result<HashMap<i64, Evidence>> {
    let limit = limit.map_or(-1, |limit| limit as i64);
    let sql = format!(
        "SELECT r.id, r.name, r.search_name, r.size_bytes, COALESCE(r.posted_at, 0), m.height
         FROM releases r LEFT JOIN media_video m ON m.release_id = r.id
         WHERE {filter} ORDER BY r.id LIMIT ?1"
    );
    let mut statement = catalog.prepare(&sql)?;
    let mut rows = statement.query([limit])?;
    let mut targets = HashMap::new();
    while let Some(row) = rows.next()? {
        let id: i64 = row.get(0)?;
        targets.insert(
            id,
            Evidence {
                id,
                name: text(row.get_ref(1)?),
                search_name: text(row.get_ref(2)?),
                size_bytes: row.get(3)?,
                posted_at: row.get(4)?,
                height: row.get(5)?,
                ..Evidence::default()
            },
        );
    }
    Ok(targets)
}

/// Every other release with a usable name can donate it.
fn load_donor_names(catalog: &Connection, donors: &mut DonorIndex) -> Result<()> {
    let mut statement = catalog.prepare(
        "SELECT r.id, r.search_name, p.title, r.size_bytes FROM releases r
         LEFT JOIN predb p ON p.id = r.predb_id
         WHERE r.predb_id IS NOT NULL OR r.is_renamed = 1
            OR r.legacy_category NOT IN (10, 20)",
    )?;
    let mut rows = statement.query([])?;
    while let Some(row) = rows.next()? {
        let id: i64 = row.get(0)?;
        let predb_title = text(row.get_ref(2)?);
        let name = if predb_title.is_empty() {
            name::stem(&text(row.get_ref(1)?))
        } else {
            predb_title
        };
        if name::usable(&name) {
            donors.add_name(id, name, row.get(3)?);
        }
    }
    Ok(())
}

/// Puts a row's fact on the release it belongs to and in the donor index.
fn add_fact(
    donors: &mut DonorIndex,
    targets: &mut HashMap<i64, Evidence>,
    release_id: i64,
    key: DonorKey,
    value: String,
) {
    if donors.is_donor(release_id) {
        donors.add_key(key, value.clone(), release_id);
    }
    if let Some(target) = targets.get_mut(&release_id) {
        target.facts.push((key, value));
    }
}

fn load_files(
    catalog: &Connection,
    donors: &mut DonorIndex,
    targets: &mut HashMap<i64, Evidence>,
) -> Result<()> {
    let mut statement =
        catalog.prepare("SELECT release_id, name, size_bytes, crc32 FROM release_files")?;
    let mut rows = statement.query([])?;
    while let Some(row) = rows.next()? {
        let id: i64 = row.get(0)?;
        let size: i64 = row.get(2)?;
        let crc = text(row.get_ref(3)?);
        let file_name = text(row.get_ref(1)?);
        if !crc.is_empty() && size >= MIN_DONOR_HASHED_SIZE {
            add_fact(
                donors,
                targets,
                id,
                DonorKey::Crc32,
                format!("{crc}:{size}"),
            );
        }
        if size_can_identify(&file_name, size) {
            add_fact(donors, targets, id, DonorKey::FileSize, size.to_string());
        }
        if let Some(target) = targets.get_mut(&id) {
            target.listing.push((file_name, size));
        }
    }
    for target in targets.values_mut() {
        target.listing.sort_by_key(|file| std::cmp::Reverse(file.1));
    }
    Ok(())
}

fn load_hashes(
    catalog: &Connection,
    donors: &mut DonorIndex,
    targets: &mut HashMap<i64, Evidence>,
) -> Result<()> {
    let mut statement =
        catalog.prepare("SELECT release_id, full_md5, hash16k, file_size FROM par_file_hashes")?;
    let mut rows = statement.query([])?;
    while let Some(row) = rows.next()? {
        let (md5, hash16k, size) = (
            text(row.get_ref(1)?),
            text(row.get_ref(2)?),
            text(row.get_ref(3)?),
        );
        // As with release_files. par_hashes has no size to hold to this, so it isn't read.
        if !size
            .parse::<i64>()
            .is_ok_and(|size| size >= MIN_DONOR_HASHED_SIZE)
        {
            continue;
        }
        let id = row.get(0)?;
        if !md5.is_empty() {
            add_fact(
                donors,
                targets,
                id,
                DonorKey::FullMd5,
                format!("{md5}:{size}"),
            );
        }
        if !hash16k.is_empty() {
            add_fact(
                donors,
                targets,
                id,
                DonorKey::Hash16k,
                format!("{hash16k}:{size}"),
            );
        }
    }
    let mut statement = catalog.prepare("SELECT release_id, unique_id FROM media_unique_ids")?;
    let mut rows = statement.query([])?;
    while let Some(row) = rows.next()? {
        let uid = text(row.get_ref(1)?);
        if !uid.is_empty() && uid.bytes().any(|byte| byte != b'0') {
            add_fact(donors, targets, row.get(0)?, DonorKey::MkvUid, uid);
        }
    }
    Ok(())
}

/// Filenames from PAR2 packets, media info, archive naming and the fingerprint collector.
fn load_names(catalog: &Connection, targets: &mut HashMap<i64, Evidence>) -> Result<()> {
    let mut statement =
        catalog.prepare("SELECT release_id, raw_filename_base64 FROM par2_descriptors")?;
    let mut rows = statement.query([])?;
    while let Some(row) = rows.next()? {
        if let Some(target) = targets.get_mut(&row.get::<_, i64>(0)?)
            && let Ok(bytes) =
                base64::engine::general_purpose::STANDARD.decode(text(row.get_ref(1)?))
        {
            let name = String::from_utf8_lossy(&bytes);
            target
                .par2_names
                .push(name.trim_end_matches('\0').to_owned());
        }
    }

    let mut statement =
        catalog.prepare("SELECT release_id, movie_name, file_name FROM media_infos")?;
    let mut rows = statement.query([])?;
    while let Some(row) = rows.next()? {
        if let Some(target) = targets.get_mut(&row.get::<_, i64>(0)?) {
            for column in [1, 2] {
                let value = text(row.get_ref(column)?);
                if !value.is_empty() {
                    target.media_names.push(value);
                }
            }
        }
    }

    let mut statement = catalog
        .prepare("SELECT release_id, episode_files FROM archive_naming_evidence WHERE length(episode_files) > 2")?;
    let mut rows = statement.query([])?;
    while let Some(row) = rows.next()? {
        if let Some(target) = targets.get_mut(&row.get::<_, i64>(0)?) {
            let files: Vec<String> =
                serde_json::from_str(&text(row.get_ref(1)?)).unwrap_or_default();
            target
                .listing
                .extend(files.into_iter().map(|file| (file, 0)));
        }
    }

    let mut statement = catalog.prepare(
        "SELECT release_id, metadata FROM fingerprint_metadata WHERE metadata IS NOT NULL",
    )?;
    let mut rows = statement.query([])?;
    while let Some(row) = rows.next()? {
        if let Some(target) = targets.get_mut(&row.get::<_, i64>(0)?) {
            let metadata: serde_json::Value =
                serde_json::from_str(&text(row.get_ref(1)?)).unwrap_or_default();
            let names = |path: &str| -> Vec<String> {
                metadata
                    .pointer(path)
                    .and_then(serde_json::Value::as_array)
                    .into_iter()
                    .flatten()
                    .filter_map(|file| file.get("name")?.as_str().map(str::to_owned))
                    .collect()
            };
            target.par2_names.extend(names("/par2/files"));
        }
    }
    Ok(())
}

struct Loaded {
    predb: LocalPredb,
    donors: DonorIndex,
    targets: Vec<Evidence>,
}

fn load(catalog: &Connection, filter: &str, limit: Option<usize>) -> Result<Loaded> {
    tracing::info!("loading PreDB");
    let predb = load_predb(catalog).context("loading PreDB")?;
    tracing::info!(rows = predb.len(), "loading the evidence");
    let mut targets = load_targets(catalog, filter, limit).context("loading the targets")?;
    let mut donors = DonorIndex::default();
    load_donor_names(catalog, &mut donors).context("loading donor names")?;
    load_files(catalog, &mut donors, &mut targets).context("loading release files")?;
    load_hashes(catalog, &mut donors, &mut targets).context("loading hashes")?;
    load_names(catalog, &mut targets).context("loading names")?;
    let mut targets: Vec<Evidence> = targets.into_values().collect();
    targets.sort_by_key(|target| target.id);
    Ok(Loaded {
        predb,
        donors,
        targets,
    })
}

fn read_nzb(store: &Connection, target: &mut Evidence) -> Result<()> {
    let row = store
        .prepare_cached("SELECT encoding, body FROM nzbs WHERE release_id = ?1")?
        .query_row([target.id], |row| {
            Ok((row.get::<_, String>(0)?, row.get::<_, Vec<u8>>(1)?))
        })
        .optional()?;
    let Some((encoding, body)) = row else {
        return Ok(());
    };
    let xml = if encoding == "gzip" {
        let mut decoded = Vec::new();
        GzDecoder::new(body.as_slice())
            .take(MAX_NZB_BYTES)
            .read_to_end(&mut decoded)?;
        decoded
    } else {
        body
    };
    target.nzb = Some(nzb::summarize(&String::from_utf8_lossy(&xml)));
    Ok(())
}

/// One worker's answers, per target.
struct Answer {
    outcome: Outcome,
    flags: Flags,
    payload_bytes: Option<u64>,
}

/// Runs `visit` over the targets on every thread, with the NZB of each read.
fn for_each_target<T: Send>(
    nzbs_path: &Path,
    threads: usize,
    targets: Vec<Evidence>,
    visit: impl Fn(&Evidence) -> T + Sync,
) -> Result<Vec<T>> {
    let total = targets.len();
    let queue = Mutex::new(targets.into_iter());
    let done = AtomicUsize::new(0);
    let answers: Vec<Result<Vec<T>>> = thread::scope(|scope| {
        let workers: Vec<_> = (0..threads.max(1))
            .map(|_| {
                scope.spawn(|| -> Result<Vec<T>> {
                    let store = open_read_only(nzbs_path)?;
                    let mut mine = Vec::new();
                    loop {
                        let batch: Vec<Evidence> = queue
                            .lock()
                            .expect("no worker panics while holding this")
                            .by_ref()
                            .take(WORKER_CHUNK)
                            .collect();
                        if batch.is_empty() {
                            return Ok(mine);
                        }
                        for mut target in batch {
                            // A broken NZB is one release short of evidence, not a failed run.
                            if let Err(error) = read_nzb(&store, &mut target) {
                                tracing::warn!(release = target.id, %error, "skipping unreadable NZB");
                            }
                            mine.push(visit(&target));
                            let finished =
                                done.fetch_add(1, Ordering::Relaxed) + 1;
                            if finished.is_multiple_of(25_000) {
                                tracing::info!(finished, total, "identify progress");
                            }
                        }
                    }
                })
            })
            .collect();
        workers
            .into_iter()
            .map(|worker| worker.join().expect("identify worker panicked"))
            .collect()
    });
    let mut all = Vec::with_capacity(total);
    for answer in answers {
        all.extend(answer?);
    }
    Ok(all)
}

pub fn run(options: &Options, hooks: Hooks<'_>) -> Result<Report> {
    let started = Instant::now();
    let catalog_path = options.data_dir.join("catalog.db");
    let nzbs_path = options.data_dir.join("nzbs.db");
    let catalog = open_read_only(&catalog_path)?;
    let loaded = load(&catalog, TARGET, options.limit)?;
    drop(catalog);
    tracing::info!(targets = loaded.targets.len(), "climbing the ladder");

    let context = Context {
        predb: &loaded.predb,
        donors: &loaded.donors,
        hooks,
    };
    let targets = loaded.targets;
    let target_count = targets.len();
    let answers = for_each_target(&nzbs_path, options.threads, targets, |target| {
        let (outcome, flags) = identify(target, &context);
        Answer {
            outcome,
            flags,
            payload_bytes: target.nzb.as_ref().map(|nzb| nzb.payload_bytes),
        }
    })?;

    let mut report = Report {
        targets: target_count,
        ..Report::default()
    };
    for answer in answers {
        tally_evidence(
            &mut report.evidence,
            &answer.flags,
            answer.payload_bytes.is_some(),
        );
        match answer.outcome {
            Outcome::Identified(found) => report.identified.push(*found),
            Outcome::Ambiguous(reason) => *report.ambiguous.entry(reason).or_default() += 1,
            Outcome::Unidentified => report.unidentified += 1,
        }
    }
    report.identified.sort_by_key(|found| found.release_id);
    if options.apply {
        apply(&catalog_path, &report.identified, options.limit.is_none())?;
        report.applied = true;
    }
    report.elapsed_seconds = started.elapsed().as_secs_f64();
    Ok(report)
}

fn tally_evidence(counts: &mut BTreeMap<&'static str, usize>, flags: &Flags, has_nzb: bool) {
    for (label, present) in [
        ("has stored NZB", has_nzb),
        ("NZB lists PAR2 files", flags.nzb_par2),
        ("readable name in release subject", flags.subject_named),
        ("readable name in NZB file subjects", flags.nzb_named),
        ("has PAR2 filenames", flags.par2),
        ("readable PAR2 filename", flags.par2_named),
        ("has RAR/archive listing", flags.listing),
        ("readable archive listing name", flags.listing_named),
        ("has media info name", flags.media_info),
        ("readable media info name", flags.media_named),
        ("PreDB match by identity-key name", flags.predb_exact),
        ("PreDB match by normalized name", flags.predb_normalized),
        ("PreDB size+time: any in-scope row", flags.predb_size_any),
        ("PreDB size+time: exactly one row", flags.predb_size_unique),
        ("file shared with a named release", flags.donor),
        ("obfuscated only", flags.obfuscated_only()),
    ] {
        if present {
            *counts.entry(label).or_default() += 1;
        }
    }
}

fn apply(catalog_path: &Path, identified: &[Identification], complete: bool) -> Result<()> {
    let mut catalog = Connection::open(catalog_path)
        .with_context(|| format!("opening {}", catalog_path.display()))?;
    catalog.execute_batch("PRAGMA busy_timeout = 10000;")?;
    catalog.execute_batch(SCHEMA)?;
    let now = SystemTime::now().duration_since(UNIX_EPOCH)?.as_secs() as i64;
    let tx = catalog.transaction()?;
    // A full run replaces the table, so a release that no longer identifies loses its old answer.
    if complete {
        tx.execute("DELETE FROM release_identifications", [])?;
    }
    {
        let mut insert = tx.prepare(
            "INSERT INTO release_identifications (release_id, name, method, evidence_source,
                 confidence, scope, kind, title, year, season, episode, resolution, source, codec,
                 release_group, predb_id, donor_release_id, note, identified_at)
             VALUES (?1, ?2, ?3, ?4, ?5, ?6, ?7, ?8, ?9, ?10, ?11, ?12, ?13, ?14, ?15, ?16, ?17, ?18, ?19)
             ON CONFLICT (release_id) DO UPDATE SET
                 name = excluded.name, method = excluded.method,
                 evidence_source = excluded.evidence_source, confidence = excluded.confidence,
                 scope = excluded.scope, kind = excluded.kind, title = excluded.title,
                 year = excluded.year, season = excluded.season, episode = excluded.episode,
                 resolution = excluded.resolution, source = excluded.source, codec = excluded.codec,
                 release_group = excluded.release_group, predb_id = excluded.predb_id,
                 donor_release_id = excluded.donor_release_id, note = excluded.note,
                 identified_at = excluded.identified_at",
        )?;
        for found in identified {
            insert.execute(params![
                found.release_id,
                found.name,
                found.method,
                found.evidence_source,
                found.confidence,
                found.scope.as_str(),
                found.parsed.kind.as_str(),
                found.parsed.title,
                found.parsed.year,
                found.parsed.season,
                found.parsed.episode,
                found.parsed.resolution,
                found.parsed.source,
                found.parsed.codec,
                found.parsed.group,
                found.predb_id,
                found.donor_release_id,
                found.note,
                now,
            ])?;
        }
    }
    tx.commit()?;
    Ok(())
}

/// How often the weak rungs get a release right, tried on releases whose PreDB row is known.
#[derive(Debug, Default)]
pub struct Holdout {
    pub releases: usize,
    pub size_answers: usize,
    pub size_right: usize,
    pub size_ambiguous: usize,
    pub payload_ratios: Vec<f64>,
    pub donor: BTreeMap<&'static str, (usize, usize)>,
}

pub fn holdout(options: &Options) -> Result<Holdout> {
    let nzbs_path = options.data_dir.join("nzbs.db");
    let catalog = open_read_only(&options.data_dir.join("catalog.db"))?;
    let loaded = load(&catalog, "r.predb_id IS NOT NULL", options.limit)?;
    let mut truth: HashMap<i64, (i64, String)> = HashMap::new();
    let mut statement = catalog.prepare(
        "SELECT r.id, r.predb_id, p.title FROM releases r JOIN predb p ON p.id = r.predb_id",
    )?;
    let mut rows = statement.query([])?;
    while let Some(row) = rows.next()? {
        truth.insert(row.get(0)?, (row.get(1)?, text(row.get_ref(2)?)));
    }
    let predb = &loaded.predb;
    let donors = &loaded.donors;

    let results = for_each_target(&nzbs_path, options.threads, loaded.targets, |target| {
        let (right_id, right_title) = truth.get(&target.id).cloned().unwrap_or_default();
        let mut size = None;
        let mut ratio = None;
        if let Some(nzb) = &target.nzb {
            let rows = predb.by_size(nzb.payload_bytes, target.posted_at);
            size = Some(rows.iter().map(|r| r.id).collect::<Vec<_>>());
            if let Some((row, _)) = predb.by_name(&right_title)
                && row.size_mib > 0
            {
                ratio = Some(nzb.payload_bytes as f64 / 1_048_576.0 / f64::from(row.size_mib));
            }
        }
        let donor = match donors.find(&target.facts, target.id, target.size_bytes) {
            DonorMatch::Found { key, name, .. } => Some((
                key.method(),
                name::folded(name) == name::folded(&right_title),
            )),
            _ => None,
        };
        (right_id, size, ratio, donor)
    })?;

    let mut holdout = Holdout::default();
    for (right_id, size, ratio, donor) in results {
        holdout.releases += 1;
        holdout.payload_ratios.extend(ratio);
        match size.as_deref() {
            Some([one]) => {
                holdout.size_answers += 1;
                if *one == right_id {
                    holdout.size_right += 1;
                }
            }
            Some([_, _, ..]) => holdout.size_ambiguous += 1,
            _ => {}
        }
        if let Some((method, right)) = donor {
            let entry = holdout.donor.entry(method).or_default();
            entry.0 += 1;
            if right {
                entry.1 += 1;
            }
        }
    }
    holdout.payload_ratios.sort_by(f64::total_cmp);
    Ok(holdout)
}

pub fn render_holdout(holdout: &Holdout) -> String {
    let mut out = String::new();
    let _ = writeln!(out, "# Hold-out on releases with a known PreDB row\n");
    let _ = writeln!(out, "Releases: {}\n", holdout.releases);
    let _ = writeln!(
        out,
        "predb_size: {} single answers, {} right, {} with several rows",
        holdout.size_answers, holdout.size_right, holdout.size_ambiguous
    );
    let ratios = &holdout.payload_ratios;
    if !ratios.is_empty() {
        let at = |share: f64| ratios[((ratios.len() - 1) as f64 * share) as usize];
        let _ = writeln!(
            out,
            "payload / PreDB size: p5 {:.3}, p25 {:.3}, p50 {:.3}, p75 {:.3}, p95 {:.3}",
            at(0.05),
            at(0.25),
            at(0.5),
            at(0.75),
            at(0.95)
        );
    }
    let _ = writeln!(out, "\n| Donor key | Answers | Right |\n|---|---|---|");
    for (method, (answers, right)) in &holdout.donor {
        let _ = writeln!(out, "| {method} | {answers} | {right} |");
    }
    out
}

/// A fixed shuffle, so the samples are spread out but the same on every run.
fn sample_order(id: i64) -> u64 {
    (id as u64)
        .wrapping_mul(0x9E37_79B9_7F4A_7C15)
        .rotate_left(29)
}

pub fn render_report(report: &Report) -> String {
    let mut out = String::new();
    let _ = writeln!(out, "# Identify run\n");
    let _ = writeln!(
        out,
        "Targets: {}. Identified: {}. Ambiguous: {}. Unidentified: {}. {:.0} s. Written to the catalog: {}.\n",
        report.targets,
        report.identified.len(),
        report.ambiguous.values().sum::<usize>(),
        report.unidentified,
        report.elapsed_seconds,
        if report.applied {
            "yes"
        } else {
            "no (dry run)"
        },
    );

    let _ = writeln!(out, "## Evidence\n\n| Evidence | Releases |\n|---|---|");
    for (label, count) in &report.evidence {
        let _ = writeln!(out, "| {label} | {count} |");
    }

    let _ = writeln!(
        out,
        "\n## Matches\n\n| Method | Total | in_scope | anime | not_tv_movie | low_resolution | low_source | unknown_quality |\n|---|---|---|---|---|---|---|---|"
    );
    let mut methods: BTreeMap<&str, BTreeMap<&str, usize>> = BTreeMap::new();
    for found in &report.identified {
        *methods
            .entry(found.method)
            .or_default()
            .entry(found.scope.as_str())
            .or_default() += 1;
    }
    let scopes = [
        Scope::InScope,
        Scope::Anime,
        Scope::NotTvOrMovie,
        Scope::LowResolution,
        Scope::LowSource,
        Scope::UnknownQuality,
    ];
    let mut totals = [0; 6];
    for (method, counts) in &methods {
        let cells: Vec<usize> = scopes
            .iter()
            .map(|scope| counts.get(scope.as_str()).copied().unwrap_or(0))
            .collect();
        for (total, cell) in totals.iter_mut().zip(&cells) {
            *total += cell;
        }
        let line: Vec<String> = cells.iter().map(ToString::to_string).collect();
        let _ = writeln!(
            out,
            "| {method} | {} | {} |",
            cells.iter().sum::<usize>(),
            line.join(" | ")
        );
    }
    let line: Vec<String> = totals.iter().map(ToString::to_string).collect();
    let _ = writeln!(
        out,
        "| **all** | **{}** | {} |",
        totals.iter().sum::<usize>(),
        line.join(" | ")
    );

    let _ = writeln!(out, "\n## Ambiguous\n");
    for (reason, count) in &report.ambiguous {
        let _ = writeln!(out, "- {reason}: {count}");
    }

    let _ = writeln!(
        out,
        "\n## Samples\n\n| Release | Method | Scope | Conf. | Name | Note |\n|---|---|---|---|---|---|"
    );
    let mut by_method: BTreeMap<&str, Vec<&Identification>> = BTreeMap::new();
    for found in &report.identified {
        by_method.entry(found.method).or_default().push(found);
    }
    for found_list in by_method.values_mut() {
        // In-scope matches first, since those are the ones that matter.
        found_list.sort_by_key(|found| {
            (
                found.scope != Scope::InScope,
                sample_order(found.release_id),
            )
        });
        for found in found_list.iter().take(SAMPLES_PER_METHOD) {
            let _ = writeln!(
                out,
                "| {} | {} | {} | {:.2} | {} | {} |",
                found.release_id,
                found.method,
                found.scope.as_str(),
                found.confidence,
                found.name.replace('|', "/"),
                found.note.as_deref().unwrap_or("")
            );
        }
    }
    out
}

/// Every identification as tab-separated lines, with the original subject beside it for
/// spot checks.
pub fn render_tsv(report: &Report) -> String {
    let mut out = String::from(
        "release_id\tmethod\tevidence_source\tscope\tconfidence\tkind\tname\tpredb_id\tdonor_release_id\tnote\n",
    );
    for found in &report.identified {
        let _ = writeln!(
            out,
            "{}\t{}\t{}\t{}\t{:.2}\t{}\t{}\t{}\t{}\t{}",
            found.release_id,
            found.method,
            found.evidence_source,
            found.scope.as_str(),
            found.confidence,
            found.parsed.kind.as_str(),
            found.name.replace(['\t', '\n'], " "),
            found.predb_id.map_or(String::new(), |id| id.to_string()),
            found
                .donor_release_id
                .map_or(String::new(), |id| id.to_string()),
            found.note.as_deref().unwrap_or(""),
        );
    }
    out
}

#[cfg(test)]
mod tests {
    use std::io::Write as _;

    use flate2::Compression;
    use flate2::write::GzEncoder;

    use super::*;

    /// The columns of the import's schema this module reads.
    const FIXTURE: &str = "
        CREATE TABLE releases (id INTEGER PRIMARY KEY, name TEXT, search_name TEXT, size_bytes INTEGER,
            posted_at INTEGER, legacy_category INTEGER, predb_id INTEGER, is_renamed INTEGER);
        CREATE TABLE predb (id INTEGER PRIMARY KEY, title TEXT, category TEXT, size TEXT, files TEXT, pre_at INTEGER);
        CREATE TABLE media_video (release_id INTEGER, height INTEGER);
        CREATE TABLE release_files (release_id INTEGER, name TEXT, size_bytes INTEGER, crc32 TEXT);
        CREATE TABLE par_file_hashes (release_id INTEGER, full_md5 TEXT, hash16k TEXT, file_size TEXT);
        CREATE TABLE media_unique_ids (release_id INTEGER, unique_id TEXT);
        CREATE TABLE par2_descriptors (release_id INTEGER, raw_filename_base64 TEXT);
        CREATE TABLE media_infos (release_id INTEGER, movie_name TEXT, file_name TEXT);
        CREATE TABLE archive_naming_evidence (release_id INTEGER, episode_files TEXT);
        CREATE TABLE fingerprint_metadata (release_id INTEGER, metadata TEXT);";

    fn fixture(dir: &Path) {
        let catalog = Connection::open(dir.join("catalog.db")).expect("catalog");
        // WAL, like the real import.
        catalog
            .execute_batch("PRAGMA journal_mode = WAL;")
            .expect("wal");
        catalog.execute_batch(FIXTURE).expect("schema");
        catalog
            .execute(
                "INSERT INTO predb VALUES (7, 'Zmora.S01E05.1080p.AMZN.WEB-DL.DDP5.1.H.264-RAWR', 'TV', '2468MB', '20', 1000000)",
                [],
            )
            .expect("predb");
        let release = |id: i64, name: &str, category: i64, predb: Option<i64>, renamed: i64| {
            catalog
                .execute(
                    "INSERT INTO releases VALUES (?1, ?2, ?2, 6000000000, 1000000, ?3, ?4, ?5)",
                    params![id, name, category, predb, renamed],
                )
                .expect("release");
        };
        // Named in its subject.
        release(
            1,
            r#""Zmora.S01E05.1080p.AMZN.WEB-DL.DDP5.1.H.264-RAWR.part1.rar" yEnc"#,
            20,
            None,
            0,
        );
        // Named only by its NZB, as one huge MKV.
        release(2, r#""GPInOt3kYN.mkv" yEnc"#, 10, None, 0);
        // Obfuscated, with a file a named release also has.
        release(3, r#""kGb4MmDz8va2XUhd.mkv" yEnc"#, 20, None, 0);
        // Nothing at all.
        release(4, r#""hrdvTRGDFSztvQwEr.part01.rar" yEnc"#, 20, None, 0);
        // Not targets: already renamed and categorized, or already linked.
        release(
            5,
            "Harry.Potter.2001.2160p.BluRay.x265-MarkII.mkv",
            2040,
            None,
            1,
        );
        release(6, r#""x.mkv" yEnc"#, 20, Some(7), 0);
        for id in [3, 5] {
            catalog
                .execute(
                    "INSERT INTO release_files VALUES (?1, 'x.part01.rar', 1048576000, 'aabbccdd')",
                    [id],
                )
                .expect("file");
        }

        let nzbs = Connection::open(dir.join("nzbs.db")).expect("nzbs");
        nzbs.execute_batch("PRAGMA journal_mode = WAL;")
            .expect("wal");
        nzbs.execute_batch(
            "CREATE TABLE nzbs (release_id INTEGER PRIMARY KEY, encoding TEXT, body BLOB)",
        )
        .expect("schema");
        let mut gzip = GzEncoder::new(Vec::new(), Compression::fast());
        gzip.write_all(br#"<file subject="&quot;Huge.Film.2023.2160p.UHD.BluRay.REMUX.HEVC-GRP.mkv&quot; yEnc"><segments><segment bytes="64000000000">a</segment></segments></file>"#)
            .expect("gzip");
        nzbs.execute(
            "INSERT INTO nzbs VALUES (2, 'gzip', ?1)",
            [gzip.finish().expect("gzip")],
        )
        .expect("nzb");
    }

    fn files(dir: &Path) -> Vec<(String, Vec<u8>)> {
        let mut files: Vec<_> = std::fs::read_dir(dir)
            .expect("listing")
            .map(|entry| {
                let path = entry.expect("entry").path();
                (
                    path.display().to_string(),
                    std::fs::read(&path).expect("reading"),
                )
            })
            .collect();
        files.sort();
        files
    }

    fn options(dir: &Path, apply: bool) -> Options {
        Options {
            data_dir: dir.to_owned(),
            threads: 2,
            limit: None,
            apply,
        }
    }

    #[test]
    fn a_dry_run_identifies_and_writes_nothing() {
        let dir = tempfile::tempdir().expect("tempdir");
        fixture(dir.path());
        let before = files(dir.path());
        let report = run(&options(dir.path(), false), Hooks::default()).expect("run");
        assert_eq!(files(dir.path()), before, "a dry run changes no file");
        assert_eq!(report.targets, 4);
        assert!(!report.applied);
        let methods: Vec<(i64, &str)> = report
            .identified
            .iter()
            .map(|found| (found.release_id, found.method))
            .collect();
        assert_eq!(
            methods,
            [
                (1, "predb_exact"),
                (2, "nzb_filename_regex"),
                (3, "donor_crc32")
            ]
        );
        assert_eq!(report.unidentified, 1);
        assert_eq!(report.evidence.get("has stored NZB"), Some(&1));
        assert_eq!(report.evidence.get("obfuscated only"), Some(&1));

        let catalog = Connection::open(dir.path().join("catalog.db")).expect("catalog");
        let tables: i64 = catalog
            .query_row(
                "SELECT count(*) FROM sqlite_master WHERE name = 'release_identifications'",
                [],
                |row| row.get(0),
            )
            .expect("count");
        assert_eq!(tables, 0);
    }

    #[test]
    fn apply_writes_identifications_and_repeats_cleanly() {
        let dir = tempfile::tempdir().expect("tempdir");
        fixture(dir.path());
        for _ in 0..2 {
            assert!(
                run(&options(dir.path(), true), Hooks::default())
                    .expect("run")
                    .applied
            );
        }
        let catalog = Connection::open(dir.path().join("catalog.db")).expect("catalog");
        let row: (i64, String, String, Option<i64>) = catalog
            .query_row(
                "SELECT count(*), method, scope, predb_id FROM release_identifications WHERE release_id = 1",
                [],
                |row| Ok((row.get(0)?, row.get(1)?, row.get(2)?, row.get(3)?)),
            )
            .expect("row");
        assert_eq!(
            row,
            (1, "predb_exact".to_owned(), "in_scope".to_owned(), Some(7))
        );
        let renamed: i64 = catalog
            .query_row("SELECT is_renamed FROM releases WHERE id = 1", [], |row| {
                row.get(0)
            })
            .expect("release");
        assert_eq!(renamed, 0, "the imported row is left as it was");
    }

    #[test]
    fn a_small_shared_file_identifies_nothing() {
        let dir = tempfile::tempdir().expect("tempdir");
        fixture(dir.path());
        let catalog = Connection::open(dir.path().join("catalog.db")).expect("catalog");
        catalog
            .execute_batch(
                "INSERT INTO release_files VALUES (4, 'advert.nfo', 900, 'ffffffff'), (5, 'advert.nfo', 900, 'ffffffff');
                 INSERT INTO par_file_hashes VALUES (4, 'aa', 'bb', '900'), (5, 'aa', 'bb', '900');
                 INSERT INTO media_unique_ids VALUES (4, ''), (5, ''), (4, '000'), (5, '000');",
            )
            .expect("rows");
        drop(catalog);
        let report = run(&options(dir.path(), false), Hooks::default()).expect("run");
        assert!(report.identified.iter().all(|found| found.release_id != 4));
    }

    #[test]
    fn a_full_apply_drops_answers_that_went_away() {
        let dir = tempfile::tempdir().expect("tempdir");
        fixture(dir.path());
        run(&options(dir.path(), true), Hooks::default()).expect("run");
        let catalog = Connection::open(dir.path().join("catalog.db")).expect("catalog");
        catalog
            .execute(
                "UPDATE releases SET name = 'kGb4MmDz8va2XUhdLx', search_name = 'kGb4MmDz8va2XUhdLx' WHERE id = 1",
                [],
            )
            .expect("update");
        run(&options(dir.path(), true), Hooks::default()).expect("run");
        let rows: i64 = catalog
            .query_row(
                "SELECT count(*) FROM release_identifications WHERE release_id = 1",
                [],
                |row| row.get(0),
            )
            .expect("count");
        assert_eq!(rows, 0);
    }

    #[test]
    fn the_report_names_every_method() {
        let dir = tempfile::tempdir().expect("tempdir");
        fixture(dir.path());
        let report = run(&options(dir.path(), false), Hooks::default()).expect("run");
        let text = render_report(&report);
        assert!(text.contains("predb_exact"));
        assert!(text.contains("| **all** | **3** |"));
        assert_eq!(render_tsv(&report).lines().count(), 4);
    }
}

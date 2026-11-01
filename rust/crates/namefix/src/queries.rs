//! Reads for name fixing: candidate batches, each source's rows and donor releases.

use std::collections::HashMap;

use anyhow::{Result, bail};
use nntmux_categorize::cat;
use sqlx::{MySql, MySqlConnection, MySqlPool, QueryBuilder, Row};

use crate::release::Release;

pub const BATCH_SIZE: usize = 1000;
const NZB_SETTLED: &str = "r.late_article_candidate_id IS NULL";
pub const INVENTORY_PROOF_VERSION: i64 = 1;

#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub enum Source {
    Nfo,
    Files,
    Srr,
    Crc,
    Uid,
    Hash,
    Par2,
    Xxx,
    MediaMovie,
}

impl Source {
    fn status_column(self) -> &'static str {
        match self {
            Self::Nfo => "proc_nfo",
            Self::Files | Self::Xxx => "proc_files",
            Self::Srr => "proc_srr",
            Self::Crc => "proc_crc32",
            Self::Uid | Self::MediaMovie => "proc_uid",
            Self::Hash => "proc_hash16k",
            Self::Par2 => "proc_par2",
        }
    }

    fn exists(self) -> &'static str {
        match self {
            Self::Nfo => "EXISTS (SELECT 1 FROM release_nfos source_nfo WHERE source_nfo.releases_id = r.id)",
            Self::Files => "EXISTS (SELECT 1 FROM release_files source_file WHERE source_file.releases_id = r.id)",
            Self::Srr => "EXISTS (SELECT 1 FROM release_files source_srr WHERE source_srr.releases_id = r.id AND (source_srr.name LIKE '%.srr' OR source_srr.name LIKE '%.srs'))",
            Self::Crc => "EXISTS (SELECT 1 FROM release_files source_crc WHERE source_crc.releases_id = r.id AND source_crc.crc32 IS NOT NULL AND source_crc.crc32 != '')",
            Self::Uid => "EXISTS (SELECT 1 FROM media_infos source_media WHERE source_media.releases_id = r.id AND source_media.unique_id IS NOT NULL AND source_media.unique_id != '')",
            Self::Hash => "EXISTS (SELECT 1 FROM par_hashes source_hash WHERE source_hash.releases_id = r.id AND source_hash.hash != '')",
            Self::Par2 => "r.nzbstatus = 1",
            Self::Xxx => "EXISTS (SELECT 1 FROM release_files source_xxx WHERE source_xxx.releases_id = r.id AND source_xxx.name LIKE '%SDPORN%')",
            Self::MediaMovie => "EXISTS (SELECT 1 FROM media_infos source_movie WHERE source_movie.releases_id = r.id AND source_movie.movie_name IS NOT NULL AND source_movie.movie_name != '')",
        }
    }
}

fn others_group() -> String {
    cat::OTHERS_GROUP.iter().map(i32::to_string).collect::<Vec<_>>().join(",")
}

/// `TMUX_FIX_NAMES_*` settings.
#[derive(Clone, Debug)]
pub struct FixNamesConfig {
    /// Overrides the default batch size, as `--limit` and `TMUX_FIX_NAMES_BATCH_LIMIT` do.
    pub batch_limit: Option<usize>,
    /// `other` or `all`.
    pub category: String,
    pub full_backlog: bool,
    pub par2_budget_seconds: u64,
    pub par2_max_releases: usize,
    pub par2_max_local_skips: usize,
    pub par2_max_probes: usize,
    pub par2_max_probe_bytes: usize,
    pub par2_max_state_bytes: usize,
}

impl FixNamesConfig {
    pub fn from_env() -> Self {
        fn parsed<T: std::str::FromStr>(name: &str, default: T) -> T {
            std::env::var(name).ok().and_then(|v| v.parse().ok()).unwrap_or(default)
        }
        Self {
            batch_limit: std::env::var("TMUX_FIX_NAMES_BATCH_LIMIT").ok().and_then(|v| v.parse().ok()).filter(|v| *v > 0),
            category: std::env::var("TMUX_FIX_NAMES_CATEGORY").unwrap_or_else(|_| "other".to_owned()),
            full_backlog: !matches!(
                std::env::var("TMUX_FIX_NAMES_FULL_BACKLOG").unwrap_or_default().to_ascii_lowercase().as_str(),
                "0" | "false" | "off" | "no"
            ),
            par2_budget_seconds: parsed("TMUX_FIX_NAMES_PAR2_BUDGET_SECONDS", 60u64).max(1),
            par2_max_releases: parsed("TMUX_FIX_NAMES_PAR2_MAX_RELEASES", 300usize).max(1),
            par2_max_local_skips: parsed("TMUX_FIX_NAMES_PAR2_MAX_LOCAL_SKIPS", 3000usize),
            par2_max_probes: parsed("TMUX_FIX_NAMES_PAR2_MAX_PROBES", 4usize).max(1),
            par2_max_probe_bytes: parsed("TMUX_FIX_NAMES_PAR2_MAX_PROBE_BYTES", 2_097_152usize).max(1),
            par2_max_state_bytes: parsed("TMUX_FIX_NAMES_PAR2_MAX_STATE_BYTES", 8_388_608usize).max(1),
        }
    }
}

/// `time` is 1 for the past six hours. `categories` is 1 for misc, 2 for all, 3 for PreDB
/// linking only, which ignores processing status.
fn push_candidate_where(query: &mut QueryBuilder<'_, MySql>, source: Source, time: i32, categories: i32) {
    query.push(" r.predb_id = 0 AND (").push(source.exists()).push(")");
    if categories != 3 {
        query.push(" AND (r.isrenamed = 0 OR r.categories_id IN (")
            .push_bind(cat::OTHER_MISC)
            .push(", ")
            .push_bind(cat::OTHER_HASHED)
            .push(")) AND r.")
            .push(source.status_column())
            .push(" = 0");
    }
    if time == 1 {
        query.push(" AND r.adddate > NOW() - INTERVAL 6 HOUR");
    }
    if categories == 1 {
        query.push(" AND r.categories_id IN (").push(others_group()).push(")");
    }
    // An in-place upgrade resets each source's status while its rows still describe the
    // smaller NZB, so a partial awaiting late articles is renamed once settled.
    query.push(" AND ").push(NZB_SETTLED);
    if source == Source::Hash {
        query.push(
            " AND NOT EXISTS (SELECT 1 FROM par2_hash_retry hr WHERE hr.releases_id = r.id \
             AND HEX(hr.release_guid) = HEX(r.guid) AND hr.retry_at > NOW())",
        );
    }
}

pub async fn candidate_batch(
    db: &MySqlPool,
    config: &FixNamesConfig,
    source: Source,
    time: i32,
    categories: i32,
    after_id: i64,
    limit: usize,
    through_id: Option<i64>,
) -> Result<Vec<Release>> {
    let batch = if limit == BATCH_SIZE { config.batch_limit.unwrap_or(limit) } else { limit };
    let mut query = QueryBuilder::<MySql>::new("SELECT ");
    query.push(Release::CANDIDATE_COLUMNS).push(" FROM releases r WHERE");
    push_candidate_where(&mut query, source, time, categories);
    query.push(" AND r.id > ").push_bind(after_id);
    if let Some(through) = through_id {
        query.push(" AND r.id <= ").push_bind(through);
    }
    query.push(" ORDER BY r.id ASC LIMIT ").push_bind(batch.max(1) as i64);
    rows(query, db).await
}

pub async fn count_candidates(db: &MySqlPool, source: Source, time: i32, categories: i32) -> Result<i64> {
    let mut query = QueryBuilder::<MySql>::new("SELECT COUNT(*) FROM releases r WHERE");
    push_candidate_where(&mut query, source, time, categories);
    Ok(query.build_query_scalar().fetch_one(db).await?)
}

async fn rows(mut query: QueryBuilder<'_, MySql>, db: &MySqlPool) -> Result<Vec<Release>> {
    query.build().fetch_all(db).await?.iter().map(|row| Ok(Release::from_row(row)?)).collect()
}

pub async fn high_watermark(db: &MySqlPool) -> Result<i64> {
    let highest: Option<u64> = sqlx::query_scalar("SELECT MAX(id) FROM releases").fetch_one(db).await?;
    Ok(highest.unwrap_or(0) as i64)
}

pub async fn standard_candidate_batch(
    db: &MySqlPool,
    config: &FixNamesConfig,
    left_guid: &str,
    limit: usize,
    before_id: Option<i64>,
) -> Result<Vec<Release>> {
    let mut query = QueryBuilder::<MySql>::new("SELECT ");
    query
        .push(Release::CANDIDATE_COLUMNS)
        .push(" FROM releases r WHERE r.leftguid = ")
        .push_bind(left_guid.to_owned())
        .push(
            " AND r.isrenamed = 0 AND r.predb_id = 0 AND r.passwordstatus >= 0 AND r.nfostatus > -1 AND ",
        )
        .push(NZB_SETTLED)
        .push(
            " AND ((r.nfostatus = 1 AND r.proc_nfo = 0) OR r.proc_files = 0 OR r.proc_uid = 0 OR r.proc_par2 = 0 \
             OR r.proc_srr = 0 OR (r.proc_hash16k = 0 AND NOT EXISTS (SELECT 1 FROM par2_hash_retry hr \
             WHERE hr.releases_id = r.id AND HEX(hr.release_guid) = HEX(r.guid) AND hr.retry_at > NOW())) \
             OR r.proc_crc32 = 0)",
        );
    if config.category != "all" {
        query.push(" AND r.categories_id IN (").push(others_group()).push(")");
    }
    if let Some(before) = before_id {
        query.push(" AND r.id < ").push_bind(before);
    }
    query.push(" ORDER BY r.id DESC LIMIT ").push_bind(limit.max(1) as i64);
    rows(query, db).await
}

/// One `release_files` row.
#[derive(Clone, Debug, sqlx::FromRow)]
pub struct FileRow {
    pub releases_id: u64,
    pub name: String,
    pub crc32: Option<String>,
}

/// One `media_infos` row.
#[derive(Clone, Debug, sqlx::FromRow)]
pub struct MediaRow {
    pub releases_id: u64,
    pub uid: Option<String>,
    pub movie_name: Option<String>,
    pub file_name: Option<String>,
}

fn id_list(query: &mut QueryBuilder<'_, MySql>, ids: &[i64]) {
    let mut separated = query.separated(", ");
    for id in ids {
        separated.push_bind(*id);
    }
    separated.push_unseparated(")");
}

/// The uncompressed NFO of each release.
pub async fn nfo_rows(db: &MySqlPool, ids: &[i64]) -> Result<HashMap<i64, String>> {
    if ids.is_empty() {
        return Ok(HashMap::new());
    }
    let mut query = QueryBuilder::<MySql>::new(
        "SELECT rn.releases_id, UNCOMPRESS(rn.nfo) AS textstring FROM release_nfos rn WHERE rn.releases_id IN (",
    );
    id_list(&mut query, ids);
    query.push(" ORDER BY rn.releases_id");
    let mut nfos = HashMap::new();
    for row in query.build().fetch_all(db).await? {
        let id: u64 = row.try_get("releases_id")?;
        let text: Option<Vec<u8>> = row.try_get("textstring")?;
        nfos.entry(id as i64)
            .or_insert_with(|| String::from_utf8_lossy(&text.unwrap_or_default()).into_owned());
    }
    Ok(nfos)
}

pub async fn file_rows(db: &MySqlPool, ids: &[i64], source: Source) -> Result<HashMap<i64, Vec<FileRow>>> {
    if ids.is_empty() {
        return Ok(HashMap::new());
    }
    let filter = match source {
        Source::Files => "",
        Source::Srr => " AND (rf.name LIKE '%.srr' OR rf.name LIKE '%.srs')",
        Source::Crc => " AND rf.crc32 IS NOT NULL AND rf.crc32 != ''",
        Source::Xxx => " AND rf.name LIKE '%SDPORN%'",
        other => bail!("Unsupported release-file source [{other:?}]."),
    };
    let mut query = QueryBuilder::<MySql>::new("SELECT rf.releases_id, rf.name, rf.crc32 FROM release_files rf WHERE rf.releases_id IN (");
    id_list(&mut query, ids);
    query.push(filter).push(" ORDER BY rf.releases_id, rf.name");
    let rows: Vec<FileRow> = query.build_query_as().fetch_all(db).await?;
    let mut grouped: HashMap<i64, Vec<FileRow>> = HashMap::new();
    for row in rows {
        grouped.entry(row.releases_id as i64).or_default().push(row);
    }
    Ok(grouped)
}

pub async fn media_rows(db: &MySqlPool, ids: &[i64]) -> Result<HashMap<i64, Vec<MediaRow>>> {
    if ids.is_empty() {
        return Ok(HashMap::new());
    }
    let mut query = QueryBuilder::<MySql>::new(
        "SELECT mi.releases_id, mi.unique_id AS uid, mi.movie_name, mi.file_name FROM media_infos mi WHERE mi.releases_id IN (",
    );
    id_list(&mut query, ids);
    query.push(" ORDER BY mi.releases_id");
    let rows: Vec<MediaRow> = query.build_query_as().fetch_all(db).await?;
    let mut grouped: HashMap<i64, Vec<MediaRow>> = HashMap::new();
    for row in rows {
        grouped.entry(row.releases_id as i64).or_default().push(row);
    }
    Ok(grouped)
}

pub async fn legacy_hash_rows(db: &MySqlPool, ids: &[i64]) -> Result<HashMap<i64, Vec<String>>> {
    if ids.is_empty() {
        return Ok(HashMap::new());
    }
    let mut query = QueryBuilder::<MySql>::new("SELECT ph.releases_id, ph.hash FROM par_hashes ph WHERE ph.releases_id IN (");
    id_list(&mut query, ids);
    query.push(
        " AND NOT EXISTS (SELECT 1 FROM par_file_hashes strong WHERE strong.releases_id = ph.releases_id) \
         ORDER BY ph.releases_id, ph.hash",
    );
    let rows: Vec<(u64, String)> = query.build_query_as().fetch_all(db).await?;
    let mut grouped: HashMap<i64, Vec<String>> = HashMap::new();
    for (id, hash) in rows {
        grouped.entry(id as i64).or_default().push(hash);
    }
    Ok(grouped)
}

/// A release already linked, whose name a matching file can borrow.
#[derive(Clone, Debug, Default, sqlx::FromRow)]
pub struct Donor {
    pub releases_id: u64,
    pub relsize: i64,
    pub searchname: String,
    pub predb_id: u64,
    #[sqlx(default)]
    pub match_key: String,
    #[sqlx(default)]
    pub canonical_title: String,
}

async fn donors(
    db: &MySqlPool,
    prefix: &str,
    suffix: &str,
    values: &[String],
    extra: Option<&str>,
) -> Result<HashMap<String, Vec<Donor>>> {
    if values.is_empty() {
        return Ok(HashMap::new());
    }
    let mut query = QueryBuilder::<MySql>::new(prefix);
    {
        let mut separated = query.separated(", ");
        for value in values {
            separated.push_bind(value.clone());
        }
        separated.push_unseparated(")");
    }
    query.push(suffix);
    if let Some(extra) = extra {
        query.push_bind(extra.to_owned()).push(")");
    }
    let rows: Vec<Donor> = query.build_query_as().fetch_all(db).await?;
    let mut grouped: HashMap<String, Vec<Donor>> = HashMap::new();
    for row in rows {
        grouped.entry(row.match_key.clone()).or_default().push(row);
    }
    Ok(grouped)
}

const DONOR_COLUMNS: &str = "r.id AS releases_id, CAST(r.size AS SIGNED) AS relsize, r.searchname, CAST(r.predb_id AS UNSIGNED) AS predb_id";

pub async fn uid_donors(db: &MySqlPool, uids: &[String]) -> Result<HashMap<String, Vec<Donor>>> {
    donors(
        db,
        &format!("SELECT mi.unique_id AS match_key, {DONOR_COLUMNS} FROM media_infos mi INNER JOIN releases r ON r.id = mi.releases_id WHERE mi.unique_id IN ("),
        " AND (r.predb_id > 0 OR r.anidbid > 0 OR r.fromname = ",
        uids,
        Some("nonscene@Ef.net (EF)"),
    )
    .await
}

pub async fn hash_donors(db: &MySqlPool, hashes: &[String]) -> Result<HashMap<String, Vec<Donor>>> {
    donors(
        db,
        &format!("SELECT ph.hash AS match_key, {DONOR_COLUMNS} FROM par_hashes ph INNER JOIN releases r ON r.id = ph.releases_id WHERE ph.hash IN ("),
        " AND (r.predb_id > 0 OR r.anidbid > 0)",
        hashes,
        None,
    )
    .await
}

pub async fn crc_donors(db: &MySqlPool, crcs: &[String]) -> Result<HashMap<String, Vec<Donor>>> {
    donors(
        db,
        &format!("SELECT rf.crc32 AS match_key, {DONOR_COLUMNS} FROM release_files rf INNER JOIN releases r ON r.id = rf.releases_id WHERE rf.crc32 IN ("),
        " AND r.predb_id > 0",
        crcs,
        None,
    )
    .await
}

pub async fn hash_evidence_generation(conn: &mut MySqlConnection, release_id: i64) -> Result<String> {
    let (last, total): (Option<i64>, i64) = sqlx::query_as(
        "SELECT CAST(MAX(id) AS SIGNED), COUNT(*) FROM par_file_hashes WHERE releases_id = ?",
    )
    .bind(release_id)
    .fetch_one(conn)
    .await?;
    Ok(format!("{}:{total}", last.unwrap_or(0)))
}

/// Full-hash donors for one target.
#[derive(Clone, Debug, Default)]
pub struct FullHashDonors {
    pub known: bool,
    pub usable: bool,
    pub overflow: bool,
    pub donors: Vec<Donor>,
    pub generation: String,
}

/// A valid `file_size`: canonical decimal that fits an unsigned 64-bit integer.
fn is_size_decimal(value: &str) -> bool {
    !value.is_empty()
        && value.len() <= 20
        && value.bytes().all(|b| b.is_ascii_digit())
        && (value == "0" || !value.starts_with('0'))
        && (value.len() < 20 || value <= "18446744073709551615")
}

fn is_md5(value: &str) -> bool {
    value.len() == 32 && value.bytes().all(|b| matches!(b, b'0'..=b'9' | b'a'..=b'f'))
}

/// All target keys take part in one catalogue snapshot; the 257th distinct donor is a flood
/// sentinel. Needs at least two distinct tuples and symmetric inventory coverage, because a lone
/// shared support file or a partial inventory can't identify a donor.
pub async fn full_hash_donors_for_release(
    conn: &mut MySqlConnection,
    release_id: i64,
    expected_guid: &str,
) -> Result<FullHashDonors> {
    let (mut known, mut usable, mut multiple) = (false, false, false);
    let mut first_tuple: Option<(String, String)> = None;
    let mut cursor = 0i64;
    let mut count = 0i64;
    loop {
        let rows: Vec<(i64, String, String, String, String)> = sqlx::query_as(
            "SELECT CAST(id AS SIGNED), release_guid, hash16k, full_md5, file_size FROM par_file_hashes \
             WHERE releases_id = ? AND id > ? ORDER BY id LIMIT 128 FOR UPDATE",
        )
        .bind(release_id)
        .bind(cursor)
        .fetch_all(&mut *conn)
        .await?;
        let page = rows.len();
        for (id, guid, hash16k, full_md5, size) in rows {
            known = true;
            count += 1;
            cursor = id;
            if guid != expected_guid || !is_md5(&hash16k) || !is_md5(&full_md5) || !is_size_decimal(&size) {
                let generation = hash_evidence_generation(conn, release_id).await?;
                return Ok(FullHashDonors { known: true, generation, ..FullHashDonors::default() });
            }
            if size != "0" {
                usable = true;
                let tuple = (full_md5, size);
                match &first_tuple {
                    Some(first) if *first != tuple => multiple = true,
                    Some(_) => {}
                    None => first_tuple = Some(tuple),
                }
            }
        }
        if page != 128 {
            break;
        }
    }
    let generation = format!("{cursor}:{count}");
    if !usable {
        return Ok(FullHashDonors { known, generation, ..FullHashDonors::default() });
    }
    if !multiple {
        return Ok(FullHashDonors { known: true, usable: true, generation, ..FullHashDonors::default() });
    }
    let inventory: Option<(i64, i64)> = sqlx::query_as(
        "SELECT CAST(evidence_last_id AS SIGNED), CAST(evidence_count AS SIGNED) FROM par_file_hash_inventories \
         WHERE releases_id = ? AND proof_version = ? AND HEX(release_guid) = HEX(?) LIMIT 1",
    )
    .bind(release_id)
    .bind(INVENTORY_PROOF_VERSION)
    .bind(expected_guid)
    .fetch_optional(&mut *conn)
    .await?;
    if inventory != Some((cursor, count)) {
        return Ok(FullHashDonors { known: true, usable: true, generation, ..FullHashDonors::default() });
    }
    let donors: Vec<Donor> = sqlx::query_as(
        "SELECT DISTINCT r.id AS releases_id, CAST(0 AS SIGNED) AS relsize, r.searchname, \
                CAST(r.predb_id AS UNSIGNED) AS predb_id, p.title AS canonical_title \
         FROM par_file_hashes t \
         INNER JOIN releases target ON target.id = t.releases_id AND HEX(target.guid) = HEX(t.release_guid) \
         INNER JOIN par_file_hash_inventories target_inventory ON target_inventory.releases_id = target.id \
            AND target_inventory.proof_version = ? \
            AND HEX(target_inventory.release_guid) = HEX(target.guid) \
            AND target_inventory.evidence_last_id = (SELECT COALESCE(MAX(target_generation.id), 0) FROM par_file_hashes target_generation \
                WHERE target_generation.releases_id = target.id AND HEX(target_generation.release_guid) = HEX(target.guid)) \
            AND target_inventory.evidence_count = (SELECT COUNT(*) FROM par_file_hashes target_generation \
                WHERE target_generation.releases_id = target.id AND HEX(target_generation.release_guid) = HEX(target.guid)) \
         INNER JOIN par_file_hashes d ON d.full_md5 = t.full_md5 AND d.file_size = t.file_size \
            AND HEX(d.full_md5) = HEX(t.full_md5) AND HEX(d.file_size) = HEX(t.file_size) \
         INNER JOIN releases r ON r.id = d.releases_id AND HEX(r.guid) = HEX(d.release_guid) \
         INNER JOIN par_file_hash_inventories donor_inventory ON donor_inventory.releases_id = r.id \
            AND donor_inventory.proof_version = ? \
            AND HEX(donor_inventory.release_guid) = HEX(r.guid) \
            AND donor_inventory.evidence_last_id = (SELECT COALESCE(MAX(donor_generation.id), 0) FROM par_file_hashes donor_generation \
                WHERE donor_generation.releases_id = r.id AND HEX(donor_generation.release_guid) = HEX(r.guid)) \
            AND donor_inventory.evidence_count = (SELECT COUNT(*) FROM par_file_hashes donor_generation \
                WHERE donor_generation.releases_id = r.id AND HEX(donor_generation.release_guid) = HEX(r.guid)) \
         INNER JOIN predb p ON p.id = r.predb_id \
         WHERE t.releases_id = ? AND HEX(t.release_guid) = HEX(?) AND r.id <> ? \
            AND t.id = (SELECT MIN(anchor.id) FROM par_file_hashes anchor \
                WHERE anchor.releases_id = t.releases_id AND HEX(anchor.release_guid) = HEX(t.release_guid) \
                    AND anchor.file_size NOT IN ('', '0')) \
            AND t.file_size NOT IN ('', '0') \
            AND NOT EXISTS (SELECT 1 FROM par_file_hashes wanted \
                WHERE wanted.releases_id = t.releases_id AND HEX(wanted.release_guid) = HEX(t.release_guid) \
                    AND wanted.file_size <> '0' \
                    AND NOT EXISTS (SELECT 1 FROM par_file_hashes provided \
                        WHERE provided.releases_id = r.id AND HEX(provided.release_guid) = HEX(r.guid) \
                            AND provided.full_md5 = wanted.full_md5 AND provided.file_size = wanted.file_size \
                            AND HEX(provided.full_md5) = HEX(wanted.full_md5) \
                            AND HEX(provided.file_size) = HEX(wanted.file_size))) \
            AND NOT EXISTS (SELECT 1 FROM par_file_hashes extra \
                WHERE extra.releases_id = r.id AND (HEX(extra.release_guid) <> HEX(r.guid) OR extra.file_size = '' \
                    OR (extra.file_size <> '0' AND NOT EXISTS (SELECT 1 FROM par_file_hashes present \
                        WHERE present.releases_id = t.releases_id AND HEX(present.release_guid) = HEX(t.release_guid) \
                            AND present.full_md5 = extra.full_md5 AND present.file_size = extra.file_size \
                            AND HEX(present.full_md5) = HEX(extra.full_md5) \
                            AND HEX(present.file_size) = HEX(extra.file_size))))) \
         LIMIT 257",
    )
    .bind(INVENTORY_PROOF_VERSION)
    .bind(INVENTORY_PROOF_VERSION)
    .bind(release_id)
    .bind(expected_guid)
    .bind(release_id)
    .fetch_all(&mut *conn)
    .await?;
    Ok(FullHashDonors {
        known: true,
        usable: true,
        overflow: donors.len() == 257,
        donors,
        generation,
    })
}

/// A PreDB title awaiting fulltext matching.
#[derive(Clone, Debug, sqlx::FromRow)]
pub struct PredbTitle {
    pub predb_id: u64,
    pub title: String,
    pub source: String,
    pub searched: i32,
}

/// Counts only enough eligible titles to fill the launcher's worker capacity.
pub async fn count_predb_launcher_candidates(db: &MySqlPool, per_run: i64, workers: i64) -> Result<i64> {
    if per_run <= 0 || workers <= 0 {
        return Ok(0);
    }
    let limit = per_run.saturating_mul(workers);
    Ok(sqlx::query_scalar(
        "SELECT COUNT(*) FROM (SELECT p.id FROM predb p WHERE LENGTH(p.title) >= 15 \
         AND p.title NOT REGEXP '[\"<> ]' AND p.searched = 0 \
         AND (p.predate < NOW() - INTERVAL 1 DAY OR (p.predate IS NULL AND p.imported_at < NOW() - INTERVAL 1 DAY)) \
         LIMIT ?) AS eligible",
    )
    .bind(limit)
    .fetch_one(db)
    .await?)
}

pub async fn predb_batch(
    db: &MySqlPool,
    worker: i64,
    workers: i64,
    limit: i64,
    after_id: i64,
    max_id: i64,
) -> Result<Vec<PredbTitle>> {
    let count = workers.clamp(1, 16);
    let slot = worker.clamp(1, count) - 1;
    Ok(sqlx::query_as(
        "SELECT p.id AS predb_id, p.title, p.source, CAST(p.searched AS SIGNED) AS searched FROM predb p \
         WHERE LENGTH(p.title) >= 15 AND p.title NOT REGEXP '[\"<> ]' AND p.searched = 0 \
         AND (p.predate < NOW() - INTERVAL 1 DAY OR (p.predate IS NULL AND p.imported_at < NOW() - INTERVAL 1 DAY)) \
         AND MOD(p.id, ?) = ? AND p.id > ? AND p.id <= ? ORDER BY p.id ASC LIMIT ?",
    )
    .bind(count)
    .bind(slot)
    .bind(after_id.max(0))
    .bind(max_id.max(0))
    .bind(limit.max(1))
    .fetch_all(db)
    .await?)
}

fn escape_like(value: &str) -> String {
    value.replace('\\', "\\\\").replace('%', "\\%").replace('_', "\\_")
}

/// SQL LIKE only discovers candidates; the caller confirms a literal title.
pub async fn confirm_predb_candidates(db: &MySqlPool, ids: &[i64], title: &str) -> Result<Vec<Release>> {
    if ids.is_empty() {
        return Ok(Vec::new());
    }
    let like = format!("%{}%", escape_like(title));
    let mut query = QueryBuilder::<MySql>::new(
        "SELECT r.id AS releases_id, r.guid, r.name, r.fromname, r.searchname, r.groups_id, r.categories_id \
         FROM releases r WHERE r.id IN (",
    );
    id_list(&mut query, ids);
    query
        .push(" AND r.predb_id = 0 AND (r.name LIKE ")
        .push_bind(like.clone())
        .push(" ESCAPE '\\\\' OR r.searchname LIKE ")
        .push_bind(like)
        .push(" ESCAPE '\\\\') ORDER BY r.id ASC LIMIT 21");
    rows(query, db).await
}

/// Only enumerated candidates, for a concurrent attachment to the intended row.
pub async fn linked_predb_candidates(db: &MySqlPool, ids: &[i64], predb_id: i64) -> Result<Vec<Option<String>>> {
    if ids.is_empty() || predb_id <= 0 {
        return Ok(Vec::new());
    }
    let mut query = QueryBuilder::<MySql>::new("SELECT r.searchname FROM releases r WHERE r.id IN (");
    id_list(&mut query, ids);
    query.push(" AND r.predb_id = ").push_bind(predb_id);
    Ok(query.build_query_scalar().fetch_all(db).await?)
}

/// A strictly capped indexed range, for when discovery omitted an existing link.
pub async fn existing_predb_links(db: &MySqlPool, predb_id: i64) -> Result<Vec<Option<String>>> {
    if predb_id <= 0 {
        return Ok(Vec::new());
    }
    Ok(
        sqlx::query_scalar("SELECT r.searchname FROM releases r WHERE r.predb_id = ? ORDER BY r.id ASC LIMIT 17")
            .bind(predb_id)
            .fetch_all(db)
            .await?,
    )
}

const PREFILE_WHERE: &str = "r.predb_id = 0 AND r.isrenamed = 0";
const PREFILE_FILES: &str = " AND EXISTS (SELECT 1 FROM release_files rf WHERE rf.releases_id = r.id AND rf.name IS NOT NULL)";

pub async fn count_prefile_candidates(db: &MySqlPool) -> Result<i64> {
    let sql = format!(
        "SELECT COUNT(*) FROM releases r WHERE {PREFILE_WHERE} AND r.categories_id IN ({}){PREFILE_FILES}",
        others_group()
    );
    Ok(sqlx::query_scalar(sqlx::AssertSqlSafe(sql)).fetch_one(db).await?)
}

pub async fn prefile_candidate_batch(db: &MySqlPool, cursor: i64, limit: usize, descending: bool) -> Result<Vec<Release>> {
    let (operator, direction) = if descending { ("<", "DESC") } else { (">", "ASC") };
    let sql = format!(
        "SELECT r.id AS releases_id, r.guid, r.name, r.searchname, r.fromname, r.groups_id, r.categories_id \
         FROM releases r WHERE {PREFILE_WHERE} AND r.categories_id IN ({}) AND r.id {operator} ?{PREFILE_FILES} \
         ORDER BY r.id {direction} LIMIT ?",
        others_group()
    );
    let rows = sqlx::query(sqlx::AssertSqlSafe(sql))
        .bind(cursor)
        .bind(limit.max(1) as i64)
        .fetch_all(db)
        .await?;
    rows.iter().map(|row| Ok(Release::from_row(row)?)).collect()
}

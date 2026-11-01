//! The release row name fixing works on, and the compare-and-swap predicate that keeps a slow
//! decision from overwriting a release that changed underneath it.

use sqlx::mysql::MySqlRow;
use sqlx::{MySql, QueryBuilder, Row};

/// A release as a candidate query read it. `guid`, `name` and `searchname` are the identity.
#[derive(Clone, Debug, Default, PartialEq)]
pub struct Release {
    pub id: i64,
    pub guid: Option<String>,
    pub name: Option<String>,
    pub searchname: Option<String>,
    pub fromname: Option<String>,
    pub groups_id: i64,
    pub categories_id: i32,
    pub relsize: i64,
    pub predb_id: i64,
    pub isrenamed: i32,
    pub nfostatus: i32,
    pub proc_nfo: i32,
    pub proc_files: i32,
    pub proc_par2: i32,
    pub proc_uid: i32,
    pub proc_srr: i32,
    pub proc_hash16k: i32,
    pub proc_crc32: i32,
    /// Extra conditions on the write, each set by the flow that observed them.
    pub observed: Observed,
}

#[derive(Clone, Debug, Default, PartialEq)]
pub struct Observed {
    pub isrenamed: Option<i32>,
    /// `last id:count` of `par_file_hashes`.
    pub par2_hash_generation: Option<String>,
    pub archive_source_digest: Option<String>,
    pub additional_claim: Option<AdditionalClaim>,
}

/// A worker's claim on additional processing, which a rename must not outlive.
#[derive(Clone, Debug, PartialEq)]
pub struct AdditionalClaim {
    pub token: Option<String>,
    pub claimed_at: Option<String>,
    /// Oldest `claimed_at` that still counts, as the claim's owner defines staleness.
    pub stale_before: String,
}

/// `id`, `guid`, `name` and `searchname`, compared by bytes.
#[derive(Clone, Debug, PartialEq)]
pub struct Snapshot {
    pub id: i64,
    pub guid: String,
    pub name: Option<String>,
    pub searchname: Option<String>,
}

impl Release {
    /// The identity, when the query that read this row selected it.
    pub fn snapshot(&self) -> Option<Snapshot> {
        Some(Snapshot {
            id: self.id,
            guid: self.guid.clone()?,
            name: self.name.clone(),
            searchname: self.searchname.clone(),
        })
    }

    pub fn from_row(row: &MySqlRow) -> Result<Self, sqlx::Error> {
        let int = |name: &str| -> Result<i32, sqlx::Error> {
            match row.try_get::<Option<i64>, _>(name) {
                Ok(value) => Ok(value.unwrap_or(0) as i32),
                Err(sqlx::Error::ColumnNotFound(_)) => Ok(0),
                Err(_) => Ok(row.try_get::<Option<i32>, _>(name).ok().flatten().unwrap_or(0)),
            }
        };
        let text = |name: &str| -> Result<Option<String>, sqlx::Error> {
            match row.try_get(name) {
                Err(sqlx::Error::ColumnNotFound(_)) => Ok(None),
                other => other,
            }
        };
        let wide = |name: &str| -> Result<i64, sqlx::Error> {
            match row.try_get::<Option<i64>, _>(name) {
                Ok(value) => Ok(value.unwrap_or(0)),
                Err(sqlx::Error::ColumnNotFound(_)) => Ok(0),
                Err(_) => Ok(row.try_get::<Option<u64>, _>(name)?.unwrap_or(0) as i64),
            }
        };
        Ok(Self {
            id: wide("releases_id")?,
            guid: text("guid")?,
            name: text("name")?,
            searchname: text("searchname")?,
            fromname: text("fromname")?,
            groups_id: wide("groups_id")?,
            categories_id: int("categories_id")?,
            relsize: wide("relsize")?,
            predb_id: wide("predb_id")?,
            isrenamed: int("isrenamed")?,
            nfostatus: int("nfostatus")?,
            proc_nfo: int("proc_nfo")?,
            proc_files: int("proc_files")?,
            proc_par2: int("proc_par2")?,
            proc_uid: int("proc_uid")?,
            proc_srr: int("proc_srr")?,
            proc_hash16k: int("proc_hash16k")?,
            proc_crc32: int("proc_crc32")?,
            observed: Observed::default(),
        })
    }

    /// The columns every candidate query reads, aliased as `from_row` expects.
    pub const CANDIDATE_COLUMNS: &str = "r.id AS releases_id, r.name, r.searchname, r.fromname, \
         r.groups_id, r.categories_id, r.size AS relsize, r.guid, r.predb_id, r.nfostatus, r.proc_nfo, \
         r.proc_files, r.proc_par2, r.proc_uid, r.proc_srr, r.proc_hash16k, r.proc_crc32, r.isrenamed";

    /// Appends the identity and every observed condition to a `WHERE` on `releases`. An observed
    /// value that's malformed matches nothing, rather than widening the write.
    pub fn push_snapshot(&self, query: &mut QueryBuilder<'_, MySql>) {
        query.push(" id = ").push_bind(self.id);
        if let Some(claim) = &self.observed.additional_claim {
            match (&claim.token, &claim.claimed_at) {
                (None, None) => {
                    query.push(" AND additional_pp_claimed_at IS NULL AND additional_pp_claim_token IS NULL");
                }
                (Some(token), None) if token.is_empty() => query.push(" AND 1 = 0").into(),
                (Some(token), Some(stamp)) if !token.is_empty() && is_datetime(stamp) => {
                    query.push(" AND additional_pp_claimed_at = ").push_bind(stamp.clone());
                    query.push(" AND additional_pp_claimed_at >= ").push_bind(claim.stale_before.clone());
                    query.push(" AND HEX(additional_pp_claim_token) = HEX(").push_bind(token.clone()).push(")");
                }
                _ => query.push(" AND 1 = 0").into(),
            }
        }
        if let Some(isrenamed) = self.observed.isrenamed {
            query.push(" AND isrenamed = ").push_bind(isrenamed);
        }
        if let Some(generation) = &self.observed.par2_hash_generation {
            match parse_generation(generation) {
                Some((last, count)) => {
                    query.push(" AND (SELECT COALESCE(MAX(pfh.id), 0) FROM par_file_hashes pfh WHERE pfh.releases_id = releases.id) = ")
                        .push_bind(last);
                    query.push(" AND (SELECT COUNT(*) FROM par_file_hashes pfh WHERE pfh.releases_id = releases.id) = ")
                        .push_bind(count);
                }
                None => query.push(" AND 1 = 0").into(),
            }
        }
        if let Some(digest) = &self.observed.archive_source_digest {
            if digest.len() == 64 && digest.bytes().all(|b| matches!(b, b'0'..=b'9' | b'a'..=b'f')) {
                query
                    .push(" AND EXISTS (SELECT 1 FROM release_archive_naming_evidence e WHERE e.releases_id = releases.id \
                           AND CAST(e.release_guid AS BINARY) = CAST(releases.guid AS BINARY) AND e.source_sha256 = ")
                    .push_bind(digest.clone())
                    .push(")");
            } else {
                query.push(" AND 1 = 0");
            }
        }
        for (column, value) in [("guid", &self.guid), ("name", &self.name), ("searchname", &self.searchname)] {
            match value {
                None => {
                    query.push(format_args!(" AND {column} IS NULL"));
                }
                Some(value) => {
                    query
                        .push(format_args!(" AND CAST({column} AS BINARY) = CAST("))
                        .push_bind(value.clone())
                        .push(" AS BINARY)");
                }
            }
        }
    }
}

fn is_datetime(value: &str) -> bool {
    chrono::NaiveDateTime::parse_from_str(value, "%Y-%m-%d %H:%M:%S")
        .is_ok_and(|parsed| parsed.format("%Y-%m-%d %H:%M:%S").to_string() == value)
}

/// `last id:count`, canonical decimal only.
pub fn parse_generation(value: &str) -> Option<(i64, i64)> {
    let (last, count) = value.split_once(':')?;
    let canonical = |part: &str| {
        !part.is_empty()
            && part.bytes().all(|b| b.is_ascii_digit())
            && (part == "0" || !part.starts_with('0'))
    };
    if !canonical(last) || !canonical(count) {
        return None;
    }
    Some((last.parse().ok()?, count.parse().ok()?))
}

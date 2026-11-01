//! The ladder for a caller holding a MariaDB pool. It only reads, and never renames or links.

use anyhow::Result;
use sqlx::{MySqlPool, QueryBuilder};

use super::donor::DonorIndex;
use super::ladder::{Context, Evidence, Flags, Hooks, Outcome, candidate_names, identify};
use super::local_predb::{
    LocalPredb, POSTED_AFTER_PRE_SECONDS, POSTED_BEFORE_PRE_SECONDS, PredbRow, parse_size_mib,
    size_range_mib,
};
use super::name::parse;

type Row = (
    i64,
    String,
    Option<String>,
    Option<String>,
    Option<String>,
    Option<i64>,
);

const MAX_ANIME_TITLES: usize = 8;

const COLUMNS: &str = "SELECT CAST(id AS SIGNED), title, category, size, files, CAST(UNIX_TIMESTAMP(predate) AS SIGNED) FROM predb";

/// Climbs the ladder on one release. The caller gathers `evidence`, and `donors` can be empty,
/// which turns those rungs off. PreDB rows come from `pool`: those titled like the release's
/// candidate names, and those from around the time of its posting for the size candidates.
/// A configured hook runs synchronously, so give one that blocks its own thread.
pub async fn identify_release(
    pool: &MySqlPool,
    evidence: &Evidence,
    donors: &DonorIndex,
    hooks: Hooks<'_>,
) -> Result<(Outcome, Flags)> {
    let predb = load_rows(pool, evidence).await?;
    let context = Context {
        predb: &predb,
        donors,
        hooks,
    };
    Ok(identify(evidence, &context))
}

async fn load_rows(pool: &MySqlPool, evidence: &Evidence) -> Result<LocalPredb> {
    let mut rows: Vec<Row> = Vec::new();
    // The title index compares case but not separators, so ask for each spelling.
    let mut titles: Vec<String> = candidate_names(evidence)
        .into_iter()
        .flat_map(|name| {
            [
                name.replace([' ', '_'], "."),
                name.replace(['.', ' '], "_"),
                name.replace(['.', '_'], " "),
                name,
            ]
        })
        .collect();
    titles.sort();
    titles.dedup();
    if !titles.is_empty() {
        let mut query = QueryBuilder::new(COLUMNS);
        query.push(" WHERE title IN (");
        let mut list = query.separated(", ");
        for title in &titles {
            list.push_bind(title.clone());
        }
        list.push_unseparated(")");
        rows.extend(query.build_query_as::<Row>().fetch_all(pool).await?);
    }
    if let Some(nzb) = &evidence.nzb {
        // The size rung's window, read the other way: rows from before and after this posting.
        let (low, high) = size_range_mib(nzb.payload_bytes);
        let mut query = QueryBuilder::new(COLUMNS);
        query.push(" WHERE predate BETWEEN FROM_UNIXTIME(");
        query.push_bind(evidence.posted_at - POSTED_AFTER_PRE_SECONDS);
        query.push(") AND FROM_UNIXTIME(");
        query.push_bind(evidence.posted_at + POSTED_BEFORE_PRE_SECONDS);
        query.push(") AND CAST(size AS UNSIGNED) BETWEEN ");
        query.push_bind(low);
        query.push(" AND ");
        query.push_bind(high);
        rows.extend(query.build_query_as::<Row>().fetch_all(pool).await?);
    }
    // One row per title settles whether PreDB has it as anime.
    let mut seen: Vec<String> = Vec::new();
    for name in candidate_names(evidence) {
        let title = parse(&name).title;
        if title.is_empty() || seen.contains(&title) || seen.len() == MAX_ANIME_TITLES {
            continue;
        }
        let like = title
            .replace('\\', r"\\")
            .replace('%', r"\%")
            .replace('_', r"\_")
            .replace(' ', ".");
        let mut query = QueryBuilder::new(COLUMNS);
        query.push(" WHERE title LIKE ");
        query.push_bind(format!("{like}.%"));
        query.push(" AND (category LIKE 'ANIME%' OR title LIKE '%.ANiME.%') LIMIT 1");
        rows.extend(query.build_query_as::<Row>().fetch_all(pool).await?);
        seen.push(title);
    }
    rows.sort_by_key(|row| row.0);
    rows.dedup_by_key(|row| row.0);
    let mut predb = LocalPredb::default();
    for (id, title, category, size, files, pre_at) in rows {
        predb.insert(PredbRow {
            id,
            title,
            category: category.unwrap_or_default(),
            size_mib: parse_size_mib(size.as_deref().unwrap_or_default()),
            files: files
                .and_then(|files| files.trim().parse().ok())
                .unwrap_or(0),
            pre_at,
        });
    }
    predb.finish();
    Ok(predb)
}

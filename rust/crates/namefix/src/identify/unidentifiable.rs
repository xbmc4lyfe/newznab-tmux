//! Hides releases that can't be identified because they're encrypted, without deleting them.
//!
//! `passwordstatus = 3` is the flag. Search, RSS and the NZB routes already leave it out, and
//! nothing deletes it, unlike 1, so a release can come back if a name turns up. [`plan`] only
//! reads. [`apply`] writes to the releases table, and each change queues its search update in
//! the same transaction, so the search role rewrites the document.

use anyhow::Result;
use nntmux_verify::releases::request_search_update;
use sqlx::{MySqlPool, QueryBuilder};

/// The same value as `UNIDENTIFIABLE` in the API crate, which a test there holds it to.
pub const PASSWD_UNIDENTIFIABLE: i32 = 3;

const BATCH: usize = 1000;

/// What a list of releases would come to.
#[derive(Clone, Debug, Default, PartialEq, Eq)]
pub struct Plan {
    /// Would change, because nothing has named them.
    pub eligible: Vec<i64>,
    pub already_hidden: usize,
    /// Left alone because a name was found since.
    pub named: usize,
    /// Left alone for now because a postprocess worker holds it.
    pub claimed: usize,
    pub missing: usize,
}

/// Works out what [`apply`] would do, reading only.
pub async fn plan(pool: &MySqlPool, ids: &[i64]) -> Result<Plan> {
    let mut plan = Plan::default();
    for batch in ids.chunks(BATCH) {
        let mut query = QueryBuilder::new(
            "SELECT CAST(id AS SIGNED), CAST(passwordstatus AS SIGNED),
                    CAST(isrenamed AS SIGNED), CAST(predb_id AS SIGNED),
                    CAST(additional_pp_claim_token IS NOT NULL AS SIGNED)
             FROM releases WHERE id IN (",
        );
        let mut list = query.separated(", ");
        for id in batch {
            list.push_bind(*id);
        }
        list.push_unseparated(")");
        let rows: Vec<(i64, i64, i64, i64, i64)> = query.build_query_as().fetch_all(pool).await?;
        plan.missing += batch.len() - rows.len();
        for (id, status, renamed, predb, claimed) in rows {
            if status == i64::from(PASSWD_UNIDENTIFIABLE) {
                plan.already_hidden += 1;
            } else if renamed != 0 || predb != 0 {
                plan.named += 1;
            } else if claimed != 0 {
                plan.claimed += 1;
            } else {
                plan.eligible.push(id);
            }
        }
    }
    Ok(plan)
}

/// Sets the flag, checking again in the write that nothing has named a release since the plan,
/// and leaving alone any release a postprocess worker holds, since it would write its own status
/// over this one. A later run picks those up. Returns how many changed.
pub async fn apply(pool: &MySqlPool, ids: &[i64]) -> Result<u64> {
    let mut changed = 0;
    for batch in ids.chunks(BATCH) {
        let mut tx = pool.begin().await?;
        for id in batch {
            let result = sqlx::query(
                "UPDATE releases SET passwordstatus = ?
                 WHERE id = ? AND passwordstatus IN (-1, 0, 1) AND isrenamed = 0 AND predb_id = 0
                   AND additional_pp_claim_token IS NULL",
            )
            .bind(PASSWD_UNIDENTIFIABLE)
            .bind(id)
            .execute(&mut *tx)
            .await?;
            if result.rows_affected() == 1 {
                request_search_update(&mut tx, *id).await?;
                changed += 1;
            }
        }
        tx.commit().await?;
    }
    Ok(changed)
}

/// Release IDs from a file, one a line. A line's first tab-separated field is its ID, so the
/// stream sample's output works directly. Anything else is refused.
pub fn parse_ids(text: &str) -> Result<Vec<i64>> {
    let mut ids = Vec::new();
    for (index, line) in text.lines().enumerate() {
        let line = line.trim();
        if line.is_empty() || line.starts_with('#') {
            continue;
        }
        let field = line.split('\t').next().unwrap_or(line);
        let id: i64 = field
            .parse()
            .map_err(|_| anyhow::anyhow!("line {}: {field:?} isn't a release ID", index + 1))?;
        if id <= 0 {
            anyhow::bail!("line {}: {id} isn't a release ID", index + 1);
        }
        ids.push(id);
    }
    ids.sort_unstable();
    ids.dedup();
    Ok(ids)
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn reads_ids_and_refuses_anything_else() {
        assert_eq!(
            parse_ids("# comment\n7\ttitle\n\n3\n7\n").expect("ids"),
            [3, 7]
        );
        assert!(parse_ids("abc\n").is_err());
        assert!(parse_ids("-4\n").is_err());
        assert!(parse_ids("0\n").is_err());
    }
}

//! `Category::getCategorySearch`: category filters, expanded from roots to their children.

use std::collections::HashSet;

use anyhow::Result;
use nntmux_runtime::Ctx;
use sqlx::{AssertSqlSafe, Row};

use crate::input::{is_numeric, php_int};

/// More than any site has; a request can't make the filter grow past it.
const MAX_REQUESTED: usize = 256;

/// The category ids a request names. Roots expand to their children; -1 and junk contribute nothing.
/// Repeats collapse and the list is capped, so its length can't multiply the work.
pub async fn expand(ctx: &Ctx, categories: &[String]) -> Result<Vec<i64>> {
    let mut seen = HashSet::new();
    let requested: Vec<i64> = categories
        .iter()
        .filter(|category| is_numeric(category))
        .map(|category| php_int(category))
        .filter(|id| *id != -1 && seen.insert(*id))
        .take(MAX_REQUESTED)
        .collect();
    if requested.is_empty() {
        return Ok(Vec::new());
    }
    let list = requested
        .iter()
        .map(i64::to_string)
        .collect::<Vec<_>>()
        .join(",");
    let roots: Vec<u64> = sqlx::query_scalar(AssertSqlSafe(format!(
        "SELECT id FROM root_categories WHERE id IN ({list})"
    )))
    .fetch_all(&ctx.db)
    .await?;
    let children = sqlx::query(AssertSqlSafe(format!(
        "SELECT id, root_categories_id FROM categories WHERE root_categories_id IN ({list})"
    )))
    .fetch_all(&ctx.db)
    .await?;
    let mut out = Vec::new();
    for id in requested {
        if u64::try_from(id).is_ok_and(|root| roots.contains(&root)) {
            for row in &children {
                if row.try_get::<Option<u64>, _>("root_categories_id")? == u64::try_from(id).ok() {
                    out.push(i64::from(row.try_get::<u32, _>("id")?));
                }
            }
        } else if id > 0 {
            out.push(id);
        }
    }
    Ok(out)
}

/// The SQL form: only integers go in, so it's safe to splice.
pub fn sql_filter(ids: &[i64]) -> String {
    match ids {
        [] => "AND 1=1".to_owned(),
        [id] => format!(" AND r.categories_id = {id}"),
        many => {
            let list: Vec<String> = many.iter().map(i64::to_string).collect();
            format!(" AND r.categories_id IN ({}) ", list.join(", "))
        }
    }
}

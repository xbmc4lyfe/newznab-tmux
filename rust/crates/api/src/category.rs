//! `Category::getCategorySearch`: category filters, expanded from roots to their children.

use anyhow::Result;
use nntmux_runtime::Ctx;
use sqlx::Row;

use crate::input::{is_numeric, php_int};

/// The category ids a request names. Roots expand to their children; -1 and junk contribute nothing.
pub async fn expand(ctx: &Ctx, categories: &[String]) -> Result<Vec<i64>> {
    let mut out = Vec::new();
    for category in categories {
        if !is_numeric(category) {
            continue;
        }
        let id = php_int(category);
        if id == -1 {
            continue;
        }
        let is_root: Option<u64> = sqlx::query_scalar("SELECT id FROM root_categories WHERE id = ?")
            .bind(id)
            .fetch_optional(&ctx.db)
            .await?;
        if is_root.is_some() {
            let rows = sqlx::query("SELECT id FROM categories WHERE root_categories_id = ?")
                .bind(id)
                .fetch_all(&ctx.db)
                .await?;
            for row in rows {
                out.push(i64::from(row.try_get::<u32, _>("id")?));
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

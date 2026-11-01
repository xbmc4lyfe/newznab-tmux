//! Resolves cross-post group names to `usenet_groups` ids, adding inactive rows for names not seen yet.

use std::collections::HashMap;
use std::sync::LazyLock;

use anyhow::Result;
use nntmux_core::pcre::Pcre;
use sqlx::{MySql, MySqlConnection, QueryBuilder};

static VALID: LazyLock<Pcre> =
    LazyLock::new(|| Pcre::new(r"/^([\w\-]+\.)+[\w\-]+$/i").expect("valid"));

/// A group name in its stored form, or `None` when it isn't one.
pub fn valid_group(name: &str) -> Option<String> {
    if !VALID.is_match(name.as_bytes()) {
        return None;
    }
    match name.get(..4) {
        Some(prefix) if prefix.eq_ignore_ascii_case("a.b.") => {
            Some(format!("alt.binaries.{}", &name[4..]))
        }
        _ => Some(name.to_owned()),
    }
}

/// Ids keyed by the original name, including normalized aliases. Names that aren't valid groups are
/// left out.
pub async fn ids_by_name(
    conn: &mut MySqlConnection,
    names: &[String],
) -> Result<HashMap<String, i64>> {
    let mut normalized: Vec<(String, String)> = Vec::new();
    for name in names {
        if let Some(valid) = valid_group(name)
            && valid.len() <= 255
            && !normalized.iter().any(|(original, _)| original == name)
        {
            normalized.push((name.clone(), valid));
        }
    }
    if normalized.is_empty() {
        return Ok(HashMap::new());
    }
    let mut query =
        QueryBuilder::<MySql>::new("SELECT name, id FROM usenet_groups WHERE name IN (");
    let mut separated = query.separated(",");
    for (_, valid) in &normalized {
        separated.push_bind(valid);
    }
    query.push(")");
    let rows: Vec<(String, u32)> = query.build_query_as().fetch_all(&mut *conn).await?;
    let mut ids: HashMap<String, i64> = rows
        .into_iter()
        .map(|(name, id)| (name, i64::from(id)))
        .collect();
    let mut resolved = HashMap::with_capacity(normalized.len());
    for (original, valid) in normalized {
        let id = match ids.get(&valid) {
            Some(id) => *id,
            None => {
                let id = add_group(conn, &valid).await?;
                ids.insert(valid, id);
                id
            }
        };
        resolved.insert(original, id);
    }
    Ok(resolved)
}

/// Adds a group release processing found, inactive, or returns the row a concurrent writer added.
async fn add_group(conn: &mut MySqlConnection, name: &str) -> Result<i64> {
    sqlx::query(
        "INSERT IGNORE INTO usenet_groups (name, description, backfill_target, first_record, last_record, \
         active, backfill, minfilestoformrelease, minsizetoformrelease) \
         VALUES (?, 'Added by Release processing', 1, 0, 0, 0, 0, NULL, NULL)",
    )
    .bind(name)
    .execute(&mut *conn)
    .await?;
    let id: u32 = sqlx::query_scalar("SELECT id FROM usenet_groups WHERE name = ?")
        .bind(name)
        .fetch_one(&mut *conn)
        .await?;
    Ok(i64::from(id))
}

/// Records the groups a release was seen in.
pub async fn record_groups(
    conn: &mut MySqlConnection,
    release: i64,
    names: &[String],
) -> Result<()> {
    let mut ids: Vec<i64> = ids_by_name(conn, names).await?.into_values().collect();
    ids.sort_unstable();
    ids.dedup();
    for group in ids {
        sqlx::query("INSERT IGNORE INTO releases_groups (releases_id, groups_id) VALUES (?, ?)")
            .bind(release)
            .bind(group)
            .execute(&mut *conn)
            .await?;
    }
    Ok(())
}

#[cfg(test)]
mod tests {
    use super::valid_group;

    #[test]
    fn names_normalize_as_php_did() {
        assert_eq!(
            valid_group("a.b.teevee").as_deref(),
            Some("alt.binaries.teevee")
        );
        assert_eq!(
            valid_group("A.B.teevee").as_deref(),
            Some("alt.binaries.teevee")
        );
        assert_eq!(
            valid_group("alt.binaries.x-y").as_deref(),
            Some("alt.binaries.x-y")
        );
        assert_eq!(valid_group("nodots"), None);
        assert_eq!(valid_group("bad name.x"), None);
        assert_eq!(valid_group("caf\u{e9}.x"), None);
    }
}

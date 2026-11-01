//! What every database file here shares: pragmas and ordered migrations.

use anyhow::{Context, Result, bail};
use rusqlite::Connection;

pub(crate) fn set_wal(conn: &Connection) -> Result<()> {
    let mode: String = conn.query_row("PRAGMA journal_mode = WAL", [], |row| row.get(0))?;
    if !mode.eq_ignore_ascii_case("wal") {
        bail!("could not enable WAL, journal mode is {mode}");
    }
    Ok(())
}

/// Brings the file to the newest schema. `user_version` counts applied scripts.
pub(crate) fn migrate(conn: &mut Connection, scripts: &[&str]) -> Result<()> {
    let applied: i64 = conn.query_row("PRAGMA user_version", [], |row| row.get(0))?;
    let applied = usize::try_from(applied).context("negative schema version")?;
    if applied > scripts.len() {
        bail!(
            "database schema {applied} is newer than this build's {}",
            scripts.len()
        );
    }
    for (index, script) in scripts.iter().enumerate().skip(applied) {
        let tx = conn.transaction()?;
        tx.execute_batch(script)
            .with_context(|| format!("applying migration {}", index + 1))?;
        tx.pragma_update(None, "user_version", (index + 1) as i64)?;
        tx.commit()?;
    }
    Ok(())
}

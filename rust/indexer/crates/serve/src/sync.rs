//! Keeps the text index in step with the catalog. The index's watermark is the highest release ID
//! it holds, so catching up reads only releases added since.

use std::cell::Cell;
use std::fs;
use std::path::Path;
use std::time::Instant;

use anyhow::{Context, Result};
use indexer_search::{IndexedRelease, TantivyIndex, TextIndex, TextIndexWriter};
use indexer_store::Catalog;
use indexer_store::rusqlite::{self, Row};
use tracing::info;

const RELEASES_SQL: &str = "SELECT id, search_name, legacy_category, size_bytes, posted_at
     FROM releases WHERE id > ?1 AND id <= ?2 ORDER BY id";
const BATCH: usize = 10_000;

fn indexed(row: &Row<'_>) -> rusqlite::Result<IndexedRelease> {
    let name = match row.get_ref(1)? {
        rusqlite::types::ValueRef::Text(b) | rusqlite::types::ValueRef::Blob(b) => {
            String::from_utf8_lossy(b).into_owned()
        }
        _ => String::new(),
    };
    Ok(IndexedRelease {
        id: row.get(0)?,
        name,
        category: u32::try_from(row.get::<_, i64>(2)?).unwrap_or(0),
        size_bytes: u64::try_from(row.get::<_, i64>(3)?).unwrap_or(0),
        posted_at: row.get(4)?,
    })
}

fn max_id(catalog: &Catalog) -> Result<u64> {
    let max: Option<i64> = catalog
        .conn()
        .query_row("SELECT MAX(id) FROM releases", [], |row| row.get(0))?;
    Ok(max.map_or(0, |id| u64::try_from(id).unwrap_or(0)))
}

/// Streams the releases in `(after, through]` to `f`, inside one read transaction.
fn each_release(
    catalog: &Catalog,
    after: u64,
    through: u64,
    f: impl FnOnce(&mut dyn Iterator<Item = IndexedRelease>) -> Result<()>,
) -> Result<()> {
    let mut statement = catalog.conn().prepare(RELEASES_SQL)?;
    let mut rows = statement.query([after as i64, through as i64])?;
    let failure = Cell::new(None);
    let mut releases = std::iter::from_fn(|| match rows.next() {
        Ok(Some(row)) => match indexed(row) {
            Ok(release) => Some(release),
            Err(error) => {
                failure.set(Some(error));
                None
            }
        },
        Ok(None) => None,
        Err(error) => {
            failure.set(Some(error));
            None
        }
    });
    f(&mut releases)?;
    match failure.take() {
        Some(error) => Err(error).context("reading releases from the catalog"),
        None => Ok(()),
    }
}

/// Opens the index in `dir`, building it from the catalog if it was never built, and adds any
/// releases the catalog gained since.
pub fn open_index(catalog: &Catalog, dir: &Path) -> Result<TantivyIndex> {
    let index = TantivyIndex::open(dir)?;
    match index.watermark()? {
        None => {
            drop(index);
            build(catalog, dir)
        }
        Some(watermark) => {
            catch_up(catalog, &index, watermark)?;
            Ok(index)
        }
    }
}

/// Builds the index again from the whole catalog, then swaps it in place of `dir`.
pub fn rebuild_index(catalog: &Catalog, dir: &Path) -> Result<TantivyIndex> {
    let fresh = dir.with_extension("new");
    if fresh.exists() {
        fs::remove_dir_all(&fresh)
            .with_context(|| format!("removing an unfinished build in {}", fresh.display()))?;
    }
    drop(build(catalog, &fresh)?);
    if dir.exists() {
        fs::remove_dir_all(dir).with_context(|| format!("removing {}", dir.display()))?;
    }
    fs::rename(&fresh, dir)
        .with_context(|| format!("moving the new index to {}", dir.display()))?;
    TantivyIndex::open(dir)
}

fn build(catalog: &Catalog, dir: &Path) -> Result<TantivyIndex> {
    let started = Instant::now();
    let tx = catalog.conn().unchecked_transaction()?;
    let through = max_id(catalog)?;
    let mut built = None;
    each_release(catalog, 0, through, |releases| {
        built = Some(TantivyIndex::build(dir, releases, through)?);
        Ok(())
    })?;
    tx.finish()?;
    let index = built.context("the index build did not run")?;
    info!(
        watermark = through,
        elapsed_ms = started.elapsed().as_millis() as u64,
        "built the search index"
    );
    Ok(index)
}

fn catch_up(catalog: &Catalog, index: &TantivyIndex, watermark: u64) -> Result<()> {
    let tx = catalog.conn().unchecked_transaction()?;
    let through = max_id(catalog)?;
    if through <= watermark {
        return Ok(());
    }
    let mut writer = index.writer()?;
    let mut added = 0usize;
    each_release(catalog, watermark, through, |releases| {
        let mut batch = Vec::with_capacity(BATCH);
        for release in releases {
            batch.push(release);
            if batch.len() == BATCH {
                writer.upsert(&batch)?;
                added += batch.len();
                batch.clear();
            }
        }
        writer.upsert(&batch)?;
        added += batch.len();
        Ok(())
    })?;
    writer.commit(through)?;
    tx.finish()?;
    info!(added, watermark = through, "caught the search index up");
    Ok(())
}

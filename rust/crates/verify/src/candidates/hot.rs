//! Fences candidates when ingestion stores more articles for them.

use anyhow::Result;
use sqlx::MySqlConnection;

use super::archive::{
    COLLECTION_COLUMNS, CollectionRow, MetadataBudgetExceeded, late_article_owner,
    legacy_release_id, lock_partial_holders, merge, snapshot_headers, spoils,
};
use super::{Candidate, now, release_late_article_fence};
use crate::context::Verify;
use crate::ledger;
use crate::manifest;

/// Marks the candidates these collections' new headers belong to, so no worker publishes a result
/// read before them. Call it inside the transaction that stored the headers, which holds the
/// collection rows. Returns manifests no longer referenced: pass each to
/// [`manifest::delete_if_unreferenced`] after that transaction commits.
pub async fn invalidate_hot_collections(
    verify: &Verify,
    tx: &mut MySqlConnection,
    ids: &[i64],
) -> Result<Vec<String>> {
    let mut sorted = ids.to_vec();
    sorted.sort_unstable();
    // Capacity released by settled holders is returned once every collection's candidates are locked:
    // the ledger row is the last lock, as for archive and publication.
    let mut released = 0;
    let mut unreferenced = Vec::new();
    let config = &verify.config;
    for id in sorted {
        let sql = format!("SELECT {COLLECTION_COLUMNS} FROM collections WHERE id = ?");
        let collection: Option<CollectionRow> = sqlx::query_as(sqlx::AssertSqlSafe(sql))
            .bind(id)
            .fetch_optional(&mut *tx)
            .await?;
        let Some(collection) = collection else {
            continue;
        };
        let identity = hex::encode(&collection.collectionhash);
        let mut candidates: Vec<Candidate> = sqlx::query_as(
            "SELECT * FROM release_verification_candidates WHERE source_identity = ? \
             AND (release_id IS NULL OR manifest_path <> '') AND status NOT IN ('expired') ORDER BY id FOR UPDATE",
        )
        .bind(&identity)
        .fetch_all(&mut *tx)
        .await?;
        candidates.reverse();
        if candidates.iter().all(|row| row.manifest_path.is_empty()) {
            continue;
        }
        let snapshot = match snapshot_headers(verify, tx, &collection, false).await {
            Ok(mut snapshot) => {
                snapshot.legacy_release_id = legacy_release_id(tx, &collection).await?;
                snapshot
            }
            Err(error) if error.is::<MetadataBudgetExceeded>() => {
                // Too large to compare (or for archive to merge). A partial under the hash can never
                // take these late headers, and its retained inventory would otherwise wait on this
                // collection forever.
                let held: Vec<i64> = lock_partial_holders(tx, &identity, true)
                    .await?
                    .iter()
                    .map(|row| row.id)
                    .collect();
                released +=
                    fence_over_budget(tx, &collection, &candidates, &held, &mut unreferenced)
                        .await?;
                continue;
            }
            Err(error) => return Err(error),
        };
        let held: Vec<i64> = lock_partial_holders(tx, &identity, true)
            .await?
            .iter()
            .map(|row| row.id)
            .collect();
        let holders: Vec<Candidate> = candidates
            .iter()
            .filter(|row| held.contains(&row.id))
            .cloned()
            .collect();
        let owner = if holders.is_empty() {
            None
        } else {
            late_article_owner(
                verify,
                tx,
                &collection,
                &identity,
                &holders,
                &snapshot,
                true,
            )
            .await?
        };
        if let Some(owner) = owner {
            // A partial's late articles: refresh its holder and archive them now, which merges them
            // into the generation that upgrades it. Other posts under the hash are left alone.
            let old = manifest::read_current(tx, config, &owner.manifest_path).await?;
            let merged = merge(&old, &snapshot);
            if merged.metadata_bytes() > config.metadata_budget() {
                // Archive keeps these headers for a later pass (or a raised budget) instead of
                // merging them, so the holder keeps its inventory and its partial: only a pending
                // upgrade under way is fenced.
                fence_over_budget(
                    tx,
                    &collection,
                    std::slice::from_ref(&owner),
                    &[],
                    &mut unreferenced,
                )
                .await?;
                continue;
            }
            if old.article_identity() != merged.article_identity() {
                if owner.release_id.is_none() {
                    sqlx::query(
                        "UPDATE release_verification_candidates SET status = 'dirty', lease_token = NULL, \
                         lease_until = NULL, version = ?, last_unique_at = ? WHERE id = ?",
                    )
                    .bind(owner.version + 1)
                    .bind(now())
                    .bind(owner.id)
                    .execute(&mut *tx)
                    .await?;
                } else {
                    sqlx::query(
                        "UPDATE release_verification_candidates SET last_unique_at = ?, \
                         retained_last_unique_at = IF(retained_for_release_id IS NULL, retained_last_unique_at, ?) WHERE id = ?",
                    )
                    .bind(now())
                    .bind(now())
                    .bind(owner.id)
                    .execute(&mut *tx)
                    .await?;
                }
                mark_sized(tx, collection.id).await?;
            }
            continue;
        }
        for candidate in &candidates {
            // A partial's holder these headers don't extend is another post's; archive keeps that
            // apart. An unheld generation is orphaned; archive retires it.
            if candidate.manifest_path.is_empty()
                || candidate.release_id.is_some()
                || held.contains(&candidate.id)
                || candidate.upgrades_release_id.is_some()
            {
                continue;
            }
            let old = manifest::read_current(tx, config, &candidate.manifest_path).await?;
            let merged = merge(&old, &snapshot);
            // Archive keeps spoiling headers apart from this post wherever it shares the hash: with
            // partials, or behind a newer row (another post, or an orphaned generation it retires).
            if spoils(&old, &merged) {
                let newest: Option<u64> = sqlx::query_scalar(
                    "SELECT id FROM release_verification_candidates WHERE source_identity = ? \
                     ORDER BY id DESC LIMIT 1 FOR UPDATE",
                )
                .bind(&identity)
                .fetch_optional(&mut *tx)
                .await?;
                if !held.is_empty() || newest != Some(candidate.id as u64) {
                    break;
                }
            }
            if merged.metadata_bytes() > config.metadata_budget() {
                // Archive can't merge these headers into it; it's verified again on what it has.
                released += fence_over_budget(
                    tx,
                    &collection,
                    std::slice::from_ref(candidate),
                    &[],
                    &mut unreferenced,
                )
                .await?;
                break;
            }
            let new_articles = old.article_identity() != merged.article_identity();
            if new_articles || merged.conflict {
                sqlx::query(
                    "UPDATE release_verification_candidates SET status = 'dirty', lease_token = NULL, \
                     lease_until = NULL, version = ?, last_unique_at = ? WHERE id = ?",
                )
                .bind(candidate.version + 1)
                .bind(if new_articles { now() } else { candidate.last_unique_at })
                .bind(candidate.id)
                .execute(&mut *tx)
                .await?;
                mark_sized(tx, collection.id).await?;
            }
            // Only the newest pending candidate receives these headers in archive; leave older ones.
            break;
        }
    }
    if released > 0 {
        ledger::adjust(tx, config, -released).await?;
    }
    Ok(unreferenced)
}

async fn mark_sized(tx: &mut MySqlConnection, collection: i64) -> Result<()> {
    sqlx::query("UPDATE collections SET filecheck = 3 WHERE id = ? AND filecheck != 4")
        .bind(collection)
        .execute(&mut *tx)
        .await?;
    Ok(())
}

/// Fences verification already under way for these candidates when late headers exceed the metadata
/// budget: it must not publish a result read before these headers. Each post stays claimable and is
/// verified again on what it has. A held partial inventory is settled as it stands. Returns the
/// capacity released, for the caller to adjust.
async fn fence_over_budget(
    tx: &mut MySqlConnection,
    collection: &CollectionRow,
    candidates: &[Candidate],
    held: &[i64],
    unreferenced: &mut Vec<String>,
) -> Result<i64> {
    let mut released = 0;
    for candidate in candidates {
        if candidate.manifest_path.is_empty() {
            continue;
        }
        if held.contains(&candidate.id) {
            settle_over_budget_holder(tx, candidate, unreferenced).await?;
            released += candidate.manifest_bytes;
        } else if candidate.release_id.is_none() {
            sqlx::query(
                "UPDATE release_verification_candidates SET lease_token = NULL, lease_until = NULL, version = ? WHERE id = ?",
            )
            .bind(candidate.version + 1)
            .bind(candidate.id)
            .execute(&mut *tx)
            .await?;
        }
    }
    mark_sized(tx, collection.id).await?;
    Ok(released)
}

/// Releases a partial's retained inventory when late headers under its hash exceed the metadata
/// budget: they can never be merged, so the partial stays as published and workers may process it.
/// The caller adjusts the ledger.
async fn settle_over_budget_holder(
    tx: &mut MySqlConnection,
    holder: &Candidate,
    unreferenced: &mut Vec<String>,
) -> Result<()> {
    if holder.release_id.is_some() {
        sqlx::query(
            "UPDATE release_verification_candidates SET manifest_path = '', manifest_bytes = 0, \
             retained_for_release_id = NULL, retained_last_unique_at = NULL WHERE id = ?",
        )
        .bind(holder.id)
        .execute(&mut *tx)
        .await?;
    } else {
        sqlx::query(
            "UPDATE release_verification_candidates SET status = 'expired', \
             reason = 'Late headers under this upload exceed the metadata budget; the partial stays as published.', \
             manifest_path = '', manifest_bytes = 0, lease_token = NULL, lease_until = NULL, version = ?, \
             upgrades_release_id = NULL, retained_for_release_id = NULL, retained_last_unique_at = NULL WHERE id = ?",
        )
        .bind(holder.version + 1)
        .bind(holder.id)
        .execute(&mut *tx)
        .await?;
    }
    if let Some(release) = holder.release_id.or(holder.upgrades_release_id) {
        release_late_article_fence(tx, &[release as i64], Some(holder.id)).await?;
    }
    unreferenced.push(holder.manifest_path.clone());
    Ok(())
}

//! Whether a verified collection may become a release under the size and file-count settings.

use std::sync::LazyLock;

use anyhow::{Result, bail};
use nntmux_core::pcre::Pcre;
use sqlx::MySqlPool;

use crate::config::VerificationConfig;
use crate::identity::{is_par2_filename, proves_payload};
use crate::inventory;
use crate::manifest::Manifest;

const DECLARED_OVERSIZE: &str = "Declared collection size exceeds the configured maximum.";

static PAR2_SUBJECT: LazyLock<Pcre> =
    LazyLock::new(|| Pcre::new(r#"/\.par2(?:["'\s]|$)/i"#).expect("valid"));

/// A rejection more articles can't cure: a collection's size only grows as they arrive.
pub fn irrecoverable(reason: &str) -> bool {
    reason == DECLARED_OVERSIZE
}

#[derive(Clone, Copy, Debug)]
struct Limits {
    min_size: i64,
    max_size: i64,
    min_files: i64,
}

async fn limits(pool: &MySqlPool, group: i64) -> Result<Limits> {
    let settings: Option<(i64, i64, i64)> = sqlx::query_as(
        "SELECT CAST(min_size_to_form_release AS SIGNED), CAST(max_size_to_form_release AS SIGNED), \
         CAST(min_files_to_form_release AS SIGNED) FROM ingestion_configurations ORDER BY id LIMIT 1",
    )
    .fetch_optional(pool)
    .await?;
    let (min_size, max_size, min_files) = settings.unwrap_or_default();
    let row: Option<(Option<i64>, Option<i64>)> = sqlx::query_as(
        "SELECT CAST(minsizetoformrelease AS SIGNED), CAST(minfilestoformrelease AS SIGNED) \
         FROM usenet_groups WHERE id = ?",
    )
    .bind(group)
    .fetch_optional(pool)
    .await?;
    let (group_size, group_files) = row.unwrap_or_default();
    Ok(Limits {
        min_size: group_size.unwrap_or(0).max(min_size).max(0),
        max_size: max_size.max(0),
        min_files: group_files.unwrap_or(0).max(min_files).max(0),
    })
}

/// The reason a verified manifest mustn't publish, or `None`. Imports aren't filtered. Invalid headers
/// and a missing spot check are errors, since no setting explains them.
pub async fn verified_rejection(
    pool: &MySqlPool,
    config: &VerificationConfig,
    manifest: &Manifest,
) -> Result<Option<String>> {
    if manifest.metadata_source.as_deref() == Some("import") {
        return Ok(None);
    }
    if manifest.verification_mode.as_deref() != Some("spot_check") {
        bail!("Authoritative verified size and inventory are required for collection publication.");
    }
    let rejection = if manifest.partial.is_some() {
        inventory::partial_rejection(manifest)
    } else {
        inventory::assess(manifest).reason
    };
    if let Some(rejection) = rejection {
        bail!("{rejection}");
    }
    let Some(sample) = manifest.spot_check.as_ref() else {
        return Ok(Some(
            "A bounded article spot check did not complete.".to_owned(),
        ));
    };
    if sample.articles < 1 || sample.bytes > config.spot_check_bytes {
        return Ok(Some(
            "A bounded article spot check did not complete.".to_owned(),
        ));
    }
    let bytes = inventory::bytes(manifest);
    // Only files with articles reach the NZB. A partial upload can declare files it never posted.
    let emitted: Vec<_> = manifest
        .files
        .iter()
        .filter(|file| !file.segments.is_empty())
        .collect();
    let limits = limits(pool, manifest.groups_id).await?;
    if bytes < limits.min_size {
        return Ok(Some(
            "Declared collection size is below the configured minimum.".to_owned(),
        ));
    }
    if limits.max_size > 0 && bytes > limits.max_size {
        return Ok(Some(DECLARED_OVERSIZE.to_owned()));
    }
    if (emitted.len() as i64) < limits.min_files {
        return Ok(Some(
            "Declared file count is below the configured minimum.".to_owned(),
        ));
    }
    let names = manifest.verified_file_names.as_deref().unwrap_or_default();
    let all_sampled_are_par2 = !names.is_empty()
        && emitted.len() as i64 <= sample.sampled_files
        && names.iter().all(|name| is_par2_filename(name));
    let mut non_parity: Vec<_> = emitted
        .iter()
        .filter(|file| !sample.parity_file_keys.contains(&file.key))
        .collect();
    if all_sampled_are_par2 {
        non_parity.clear();
    }
    let has_payload = non_parity
        .iter()
        .any(|file| !PAR2_SUBJECT.is_match(file.subject.as_bytes()));
    // An obfuscated, extensionless body name isn't evidence of a source payload.
    let sampled_payload = !non_parity.is_empty() && names.iter().any(|name| proves_payload(name));
    if !has_payload && !sampled_payload {
        return Ok(Some(
            "Header inventory contains only PAR2 files without a declared source payload."
                .to_owned(),
        ));
    }
    Ok(None)
}

//! `releases:additional`, `postprocess:guid` and `releases:fetch-samples`.

use std::sync::Arc;
use std::time::Instant;

use anyhow::{Result, bail};
use nntmux_namefix::update::ReleaseSearch;
use nntmux_runtime::Ctx;
use nntmux_search::SearchClient;
use sqlx::{AssertSqlSafe, MySqlPool};

use crate::additional::orchestrator::{self, Services};
use crate::additional::processor::ReleaseResult;
use crate::config::Config;
use crate::{nfo, tv_pipeline};

/// Search updates through the search service; a failed update leaves a retry row.
struct SearchBackend {
    client: SearchClient,
    db: MySqlPool,
}

#[async_trait::async_trait]
impl ReleaseSearch for SearchBackend {
    async fn update_release(&self, release_id: i64) {
        self.client.update_release(&self.db, release_id).await;
    }
}

fn search(ctx: &Ctx) -> Result<Arc<dyn ReleaseSearch>> {
    Ok(Arc::new(SearchBackend {
        client: SearchClient::from_ctx(ctx)?,
        db: ctx.db.clone(),
    }))
}

async fn services(ctx: &Ctx) -> Result<Services> {
    let config = Config::load(&ctx.db).await?;
    Services::build(ctx.db.clone(), config, search(ctx)?).await
}

/// How `releases:additional` names its release.
#[derive(Clone, Debug)]
pub enum Target {
    Guid(String),
    Id(String),
}

/// `releases:additional`: one release, whatever its processing state.
pub async fn additional(ctx: &Ctx, target: Target, reset: bool, profile: bool) -> Result<()> {
    let row: Option<(i64, String)> = match &target {
        Target::Id(id) => {
            if id.is_empty() || !id.bytes().all(|byte| byte.is_ascii_digit()) {
                bail!("Release ID must be numeric.");
            }
            sqlx::query_as("SELECT CAST(id AS SIGNED), guid FROM releases WHERE id = ?")
                .bind(id.parse::<u64>()?)
                .fetch_optional(&ctx.db)
                .await?
        }
        Target::Guid(guid) => {
            sqlx::query_as("SELECT CAST(id AS SIGNED), guid FROM releases WHERE guid = ?")
                .bind(guid)
                .fetch_optional(&ctx.db)
                .await?
        }
    };
    let identifier = match &target {
        Target::Id(id) => format!("ID {id}"),
        Target::Guid(guid) => format!("GUID {guid}"),
    };
    let Some((id, guid)) = row else {
        bail!("Release not found for {identifier}");
    };
    let mut services = services(ctx).await?;
    let result = run_one(&mut services, id, &guid, reset).await?;
    print_result(&result, &identifier);
    if profile {
        print_profile(&result);
    }
    if !result.is_successful() {
        bail!(
            "Additional postprocessing ended with outcome {} for {identifier}",
            result.outcome.as_str()
        );
    }
    Ok(())
}

/// One release, after resetting its additional-processing flags when asked.
async fn run_one(
    services: &mut Services,
    id: i64,
    guid: &str,
    reset: bool,
) -> Result<ReleaseResult> {
    if reset {
        sqlx::query(
            "UPDATE releases SET passwordstatus = -1, haspreview = -1, archive_retry_at = NULL, \
             jpgstatus = 0, videostatus = 0, nfostatus = -1 WHERE id = ?",
        )
        .bind(id)
        .execute(&services.pool)
        .await?;
        services.search.update_release(id).await;
        println!("Reset postprocessing flags for release ID {id} (GUID {guid})");
    }
    Ok(orchestrator::process_single_guid(services, guid).await)
}

fn print_result(result: &ReleaseResult, identifier: &str) {
    println!("Release: {identifier}");
    println!("Pipeline: v2");
    println!("Outcome: {}", result.outcome.as_str());
    println!(
        "Artifacts created: {}",
        if result.artifacts_created {
            "yes"
        } else {
            "no"
        }
    );
    println!("Release files added: {}", result.release_files_added);
    println!("Duration: {:.3} seconds", result.elapsed_seconds);
    if !result.reason.is_empty() {
        println!("Reason: {}", result.reason);
    }
}

fn print_profile(result: &ReleaseResult) {
    if !result.stage_durations.is_empty() {
        println!("\nStage profile");
        for (stage, seconds) in &result.stage_durations {
            println!("  {:<24} {seconds:.3}", stage.as_str());
        }
    }
    let downloads = &result.download_metrics;
    println!("\nDownload profile");
    println!("  Logical requests   {}", downloads.logical_requests);
    println!("  Network requests   {}", downloads.network_requests);
    println!("  Cache hits         {}", downloads.cache_hits);
    println!("  Bytes downloaded   {}", downloads.bytes_downloaded);
    println!("  Bytes reused       {}", downloads.bytes_reused);
    println!("  Bytes for previews {}", downloads.media_bytes_downloaded);
}

/// The options of `postprocess:guid`.
#[derive(Clone, Debug)]
pub struct GuidOptions {
    pub kind: String,
    pub guid: String,
    pub renamed: Option<String>,
    pub worker: bool,
    pub max_batches: i64,
    pub profile: bool,
}

/// `postprocess:guid`: one kind of post-processing for one GUID bucket.
pub async fn guid(ctx: &Ctx, options: GuidOptions) -> Result<()> {
    tv_pipeline::check_guid_char(&options.guid)?;
    let guid = options.guid.as_str();
    let renamed = || tv_pipeline::renamed_argument(options.renamed.as_deref());
    match options.kind.as_str() {
        "additional" => additional_bucket(ctx, &options).await,
        "nfo" => {
            let config = Config::load(&ctx.db).await?;
            let nntp = nntmux_nntp::NntpConfig::from_env();
            let settings = nfo::Settings::from_config(&config, nntp.alternate_article_fallback);
            let client = nntmux_nntp::NntpClient::new(Arc::new(nntp));
            let mut service = nfo::NfoService::new(ctx.db.clone(), settings, client);
            service.process_nfo_files(None, guid).await?;
            Ok(())
        }
        "movie" => match renamed() {
            Some(renamed) => nntmux_metadata::process_movies(ctx, 0, guid, renamed).await,
            None => Ok(()),
        },
        "tv" => match renamed() {
            Some(renamed) => nntmux_metadata::process_tv(ctx, 0, guid, renamed).await,
            None => Ok(()),
        },
        "anime" => {
            let renamed = nntmux_metadata::Renamed::Configured;
            nntmux_metadata::process_anime(ctx, 0, guid, renamed).await
        }
        "books" => {
            nntmux_metadata::process_books(ctx, 0, guid, nntmux_metadata::Renamed::Configured).await
        }
        "music" => {
            nntmux_metadata::process_music(ctx, 0, guid, nntmux_metadata::Renamed::Configured).await
        }
        "console" => {
            nntmux_metadata::process_consoles(ctx, 0, guid, nntmux_metadata::Renamed::Configured)
                .await
        }
        "games" => {
            nntmux_metadata::process_games(ctx, 0, guid, nntmux_metadata::Renamed::Configured).await
        }
        _ => bail!(
            "Invalid type. Must be: additional, nfo, movie, tv, anime, books, music, console, or games."
        ),
    }
}

/// `processAdditional`: claim batches of the bucket until none is left, the batch limit or the
/// child time limit.
async fn additional_bucket(ctx: &Ctx, options: &GuidOptions) -> Result<()> {
    let mut services = services(ctx).await?;
    let max_batches = if options.worker {
        options.max_batches.max(1)
    } else {
        1
    };
    let worker_token = crate::additional::candidates::new_token();
    let child_time = services.config.worker_child_time_seconds;
    let deadline = Instant::now()
        + std::time::Duration::from_secs(u64::try_from((child_time - 30).max(1)).unwrap_or(1));
    let mut excluded: Vec<i64> = Vec::new();
    let mut batch = 0;
    while batch < max_batches && Instant::now() < deadline {
        let result =
            orchestrator::start(&mut services, &options.guid, 0, &worker_token, &excluded).await?;
        batch += 1;
        if options.profile {
            println!(
                "{}",
                result.profile(&options.guid, usize::try_from(batch).unwrap_or(0))
            );
        }
        if let Some(error) = &result.setup_error {
            bail!("Additional post-processing skipped: {error}");
        }
        if result.claimed_count() == 0 {
            break;
        }
        for id in result.claimed_ids {
            if !excluded.contains(&id) {
                excluded.push(id);
            }
        }
    }
    Ok(())
}

/// The options of `releases:fetch-samples`.
#[derive(Clone, Debug)]
pub struct FetchSamplesOptions {
    pub category: Option<String>,
    pub limit: i64,
    pub chunk: i64,
    pub dry_run: bool,
    pub show_output: bool,
}

/// The category IDs of `--category`: numbers separated by commas or whitespace, deduplicated in
/// order.
fn category_ids(option: &str) -> Vec<i64> {
    let mut ids = Vec::new();
    for part in option.split(|c: char| c.is_ascii_whitespace() || c == ',') {
        if part.is_empty() || !part.bytes().all(|byte| byte.is_ascii_digit()) {
            continue;
        }
        if let Ok(id) = part.parse::<i64>()
            && !ids.contains(&id)
        {
            ids.push(id);
        }
    }
    ids
}

/// `releases:fetch-samples`: reset and reprocess releases of the categories without a sample
/// picture, newest first.
pub async fn fetch_samples(ctx: &Ctx, options: FetchSamplesOptions) -> Result<()> {
    let Some(category) = options
        .category
        .as_deref()
        .filter(|value| !value.trim().is_empty())
    else {
        println!(
            "Category option is empty. Provide --category with one or more numeric category IDs. Command will not run."
        );
        return Ok(());
    };
    let ids = category_ids(category);
    if ids.is_empty() {
        println!("Category option provided but no valid numeric IDs parsed. Command will not run.");
        return Ok(());
    }
    if options.limit < 0 {
        bail!("Limit must be >= 0");
    }
    if options.chunk < 1 {
        bail!("Chunk size must be >= 1");
    }
    let list = ids.iter().map(i64::to_string).collect::<Vec<_>>().join(",");
    // Only parsed integers go into the filter.
    let filter = format!("categories_id IN ({list}) AND jpgstatus = 0");
    let total: i64 = sqlx::query_scalar(AssertSqlSafe(format!(
        "SELECT COUNT(*) FROM releases WHERE {filter}"
    )))
    .fetch_one(&ctx.db)
    .await?;
    if total == 0 {
        println!("No matching releases found (categories_id IN [{list}] AND jpgstatus = 0).");
        return Ok(());
    }
    let effective = if options.limit > 0 {
        options.limit.min(total)
    } else {
        total
    };
    println!("Categories: [{list}]");
    println!(
        "Found {total} matching release(s). Processing {effective}.{}",
        if options.dry_run { " (dry-run)" } else { "" }
    );
    if options.dry_run {
        let guids: Vec<String> = sqlx::query_scalar(AssertSqlSafe(format!(
            "SELECT guid FROM releases WHERE {filter} ORDER BY id DESC LIMIT ?"
        )))
        .bind(effective)
        .fetch_all(&ctx.db)
        .await?;
        println!("Dry run: GUIDs to process (with --reset):");
        for guid in guids {
            println!("{guid}");
        }
        println!("Dry run complete.");
        return Ok(());
    }

    let mut services = services(ctx).await?;
    let (mut processed, mut failed, mut remaining) = (0u64, 0u64, effective);
    let mut before = i64::MAX;
    while remaining > 0 {
        let chunk: Vec<(i64, String)> = sqlx::query_as(AssertSqlSafe(format!(
            "SELECT CAST(id AS SIGNED), guid FROM releases WHERE {filter} AND id < ? \
             ORDER BY id DESC LIMIT ?"
        )))
        .bind(before)
        .bind(options.chunk)
        .fetch_all(&ctx.db)
        .await?;
        let Some((last, _)) = chunk.last() else {
            break;
        };
        before = *last;
        for (id, guid) in chunk {
            if remaining <= 0 {
                break;
            }
            match run_one(&mut services, id, &guid, true).await {
                Ok(result) if result.is_successful() => {
                    processed += 1;
                    if options.show_output {
                        println!("{guid} -> {}", result.outcome.as_str());
                    }
                }
                Ok(result) => {
                    failed += 1;
                    eprintln!(
                        "Outcome {} for GUID {guid}{}",
                        result.outcome.as_str(),
                        if options.show_output && !result.reason.is_empty() {
                            format!(": {}", result.reason)
                        } else {
                            String::new()
                        }
                    );
                }
                Err(error) => {
                    failed += 1;
                    eprintln!("Error processing GUID {guid}: {error:#}");
                }
            }
            remaining -= 1;
        }
    }
    println!("Processing complete. Success: {processed}, Failed: {failed}.");
    if failed > 0 {
        bail!("{failed} release(s) failed");
    }
    Ok(())
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn category_ids_are_parsed_as_in_php() {
        assert_eq!(
            category_ids(" 2040, 2045 2040,,x1, 5000 "),
            vec![2040, 2045, 5000]
        );
        assert_eq!(category_ids("abc"), Vec::<i64>::new());
        assert_eq!(category_ids("-5,7"), vec![7]);
    }
}

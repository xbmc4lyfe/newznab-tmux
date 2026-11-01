//! `nntmux:recategorize-releases` and `releases:recategorize-onlyfans`.

use std::io::BufRead;

use anyhow::{Result, bail};
use nntmux_runtime::Ctx;
use nntmux_search::SearchClient;
use sqlx::{MySql, MySqlPool, QueryBuilder};

use crate::{Categorizer, cat};

const CHUNK: i64 = 250;

#[derive(Clone, Debug, Default, clap::Args)]
pub struct RecategorizeArgs {
    /// Every release in misc categories.
    #[arg(long)]
    pub misc: bool,
    /// Resets categorization on every release and starts over. Asks first.
    #[arg(long)]
    pub all: bool,
    /// Report only, with no updates.
    #[arg(long)]
    pub test: bool,
    #[arg(long)]
    pub group: Option<i64>,
    /// A comma-separated list of group ids.
    #[arg(long)]
    pub groups: Option<String>,
    #[arg(long)]
    pub category: Option<i32>,
    /// A comma-separated list of category ids.
    #[arg(long)]
    pub categories: Option<String>,
}

/// The first selector given, in the order the PHP checked them.
fn selector(args: &RecategorizeArgs) -> Result<String> {
    let list = |value: &str| -> Result<String> {
        let ids: Vec<i64> = value
            .split(',')
            .map(|id| id.trim().parse())
            .collect::<Result<_, _>>()?;
        Ok(ids.iter().map(i64::to_string).collect::<Vec<_>>().join(","))
    };
    let others = || {
        cat::OTHERS_GROUP
            .iter()
            .map(i32::to_string)
            .collect::<Vec<_>>()
            .join(",")
    };
    Ok(if args.misc {
        format!("categories_id IN ({})", others())
    } else if args.all {
        "iscategorized = 0".to_owned()
    } else if let Some(group) = args.group {
        format!("groups_id = {group}")
    } else if let Some(groups) = &args.groups {
        format!("groups_id IN ({})", list(groups)?)
    } else if let Some(category) = args.category {
        format!("categories_id = {category}")
    } else if let Some(categories) = &args.categories {
        format!("categories_id IN ({})", list(categories)?)
    } else if args.test {
        "iscategorized = 0".to_owned()
    } else {
        bail!("You must specify at least one option. See: --help")
    })
}

type Row = (i64, Option<String>, Option<String>, i64, i32);

/// A page of matching releases, by id so that updates can't shift it.
async fn page(db: &MySqlPool, filter: &str, after: i64) -> Result<Vec<Row>> {
    let sql = format!(
        "SELECT CAST(id AS SIGNED), searchname, fromname, CAST(groups_id AS SIGNED), categories_id \
         FROM releases WHERE {filter} AND id > ? ORDER BY id LIMIT ?"
    );
    Ok(sqlx::query_as(sqlx::AssertSqlSafe(sql))
        .bind(after)
        .bind(CHUNK)
        .fetch_all(db)
        .await?)
}

async fn category_title(db: &MySqlPool, id: i32) -> Result<String> {
    let row: Option<(String, Option<String>)> = sqlx::query_as(
        "SELECT c.title, p.title FROM categories c LEFT JOIN root_categories p ON p.id = c.root_categories_id WHERE c.id = ?",
    )
    .bind(id)
    .fetch_optional(db)
    .await?;
    Ok(match row {
        Some((title, Some(parent))) => format!("{parent} -> {title}"),
        Some((title, None)) => title,
        None => "N/A".to_owned(),
    })
}

pub async fn recategorize(ctx: &Ctx, args: RecategorizeArgs) -> Result<()> {
    let filter = selector(&args)?;
    if args.all {
        println!(
            "This will reset categorization on all releases and re-categorize them all from scratch. Are you sure? (y/n)"
        );
        let mut answer = String::new();
        std::io::stdin().lock().read_line(&mut answer)?;
        if !matches!(answer.trim().to_ascii_lowercase().as_str(), "y" | "yes") {
            println!("Reset script stopped.");
            return Ok(());
        }
        sqlx::query("UPDATE releases SET iscategorized = 0 WHERE iscategorized = 1")
            .execute(&ctx.db)
            .await?;
    }
    let categorizer = Categorizer::load(&ctx.db).await?;
    let search = SearchClient::from_ctx(ctx)?;
    // The filter can stop matching as rows change, so walk a fixed high-water mark by id.
    let mut after = 0;
    let (mut seen, mut changed) = (0u64, 0u64);
    loop {
        let rows = page(&ctx.db, &filter, after).await?;
        let Some(last) = rows.last().map(|row| row.0) else {
            break;
        };
        for (id, searchname, fromname, group, category) in rows {
            seen += 1;
            let name = searchname.unwrap_or_default();
            let decision = categorizer
                .determine(&ctx.db, group, &name, fromname.as_deref().unwrap_or(""))
                .await?;
            if category == decision.category {
                continue;
            }
            if args.test {
                println!(
                    "Would have changed {name} from {category} to {}",
                    decision.category
                );
                continue;
            }
            sqlx::query(
                "UPDATE releases SET iscategorized = 1, videos_id = 0, tv_episodes_id = 0, tv_lookup_attempts = 0, \
                 tv_lookup_retry_at = NULL, imdbid = NULL, musicinfo_id = NULL, consoleinfo_id = NULL, gamesinfo_id = 0, \
                 bookinfo_id = 0, anidbid = NULL, categories_id = ? WHERE id = ?",
            )
            .bind(decision.category)
            .bind(id)
            .execute(&ctx.db)
            .await?;
            search.update_release(&ctx.db, id).await;
            changed += 1;
            println!(
                "#{id} {name}: {} -> {}",
                category_title(&ctx.db, category).await?,
                category_title(&ctx.db, decision.category).await?
            );
        }
        after = last;
    }
    println!("Inspected {seen} releases; changed {changed}.");
    Ok(())
}

/// What an OnlyFans repair did.
#[derive(Clone, Copy, Debug, Default, PartialEq)]
pub struct OnlyFansTotals {
    pub inspected: u64,
    pub eligible: u64,
    pub updated: u64,
    pub refreshed: u64,
    pub skipped: u64,
    pub failed: u64,
}

/// `OF.` releases in XXX categories that belong in OnlyFans. `refresh` confirms a search update;
/// a category saved without one counts as failed, so the release gets reindexed.
pub async fn repair_onlyfans<F, Fut>(
    db: &MySqlPool,
    apply: bool,
    refresh: F,
) -> Result<OnlyFansTotals>
where
    F: Fn(i64) -> Fut,
    Fut: Future<Output = bool>,
{
    let ids: Vec<i64> = sqlx::query_scalar(
        "SELECT CAST(id AS SIGNED) FROM categories WHERE root_categories_id = ?",
    )
    .bind(cat::XXX_ROOT)
    .fetch_all(db)
    .await?;
    if !ids.contains(&i64::from(cat::XXX_ONLYFANS)) {
        bail!("XXX > OnlyFans category (6047) is missing. No releases were changed.");
    }
    let categorizer = Categorizer::load(db).await?;
    let mut totals = OnlyFansTotals::default();
    let mut after = 0i64;
    loop {
        let mut query = QueryBuilder::<MySql>::new(
            "SELECT CAST(id AS SIGNED), searchname, fromname, CAST(groups_id AS SIGNED), categories_id FROM releases WHERE categories_id IN (",
        );
        let mut separated = query.separated(", ");
        for id in &ids {
            separated.push_bind(*id);
        }
        separated.push_unseparated(")");
        query
            .push(" AND categories_id != ")
            .push_bind(cat::XXX_ONLYFANS)
            .push(" AND LOWER(searchname) LIKE 'of.%' AND id > ")
            .push_bind(after)
            .push(" ORDER BY id LIMIT ")
            .push_bind(CHUNK);
        let rows: Vec<Row> = query.build_query_as().fetch_all(db).await?;
        let Some(last) = rows.last().map(|row| row.0) else {
            break;
        };
        for (id, searchname, fromname, group, category) in rows {
            totals.inspected += 1;
            let name = searchname.unwrap_or_default();
            let decision = match categorizer
                .determine(db, group, &name, fromname.as_deref().unwrap_or(""))
                .await
            {
                Ok(decision) => decision,
                Err(error) => {
                    totals.failed += 1;
                    eprintln!("#{id} category correction failed: {error}");
                    continue;
                }
            };
            if decision.category != cat::XXX_ONLYFANS {
                totals.skipped += 1;
                continue;
            }
            totals.eligible += 1;
            if !apply {
                println!("#{id} {name}: {category} -> {}", cat::XXX_ONLYFANS);
                continue;
            }
            match update_if_unchanged(db, id, &name, category).await {
                Ok(true) => totals.updated += 1,
                Ok(false) => {
                    totals.skipped += 1;
                    println!("#{id} changed during repair; skipped.");
                    continue;
                }
                Err(error) => {
                    totals.failed += 1;
                    eprintln!("#{id} category correction failed: {error}");
                    continue;
                }
            }
            if refresh(id).await {
                totals.refreshed += 1;
            } else {
                totals.failed += 1;
                eprintln!(
                    "#{id} category saved, but the search refresh failed. Reindex this release before considering its repair complete."
                );
            }
        }
        after = last;
    }
    Ok(totals)
}

async fn update_if_unchanged(
    db: &MySqlPool,
    id: i64,
    searchname: &str,
    category: i32,
) -> Result<bool> {
    let mut tx = db.begin().await?;
    let current: Option<(Option<String>, i32)> =
        sqlx::query_as("SELECT searchname, categories_id FROM releases WHERE id = ? FOR UPDATE")
            .bind(id)
            .fetch_optional(&mut *tx)
            .await?;
    if current != Some((Some(searchname.to_owned()), category)) {
        return Ok(false);
    }
    let updated = sqlx::query("UPDATE releases SET categories_id = ? WHERE id = ?")
        .bind(cat::XXX_ONLYFANS)
        .bind(id)
        .execute(&mut *tx)
        .await?
        .rows_affected();
    tx.commit().await?;
    Ok(updated == 1)
}

pub async fn recategorize_onlyfans(ctx: &Ctx, apply: bool) -> Result<()> {
    if !apply {
        println!("Preview only. Run with --apply to save these category corrections.");
    }
    let search = SearchClient::from_ctx(ctx)?;
    let totals = repair_onlyfans(&ctx.db, apply, |id| {
        let (search, db) = (&search, &ctx.db);
        async move { search.update_release(db, id).await }
    })
    .await?;
    println!(
        "Inspected: {}; Eligible: {}; Skipped: {}; Failed: {}.",
        totals.inspected, totals.eligible, totals.skipped, totals.failed
    );
    if apply {
        println!(
            "Database categories updated: {}; Search refreshes confirmed: {}.",
            totals.updated, totals.refreshed
        );
    } else {
        println!(
            "Would update: {}. No releases were changed.",
            totals.eligible
        );
    }
    if totals.failed > 0 {
        bail!("{} releases need attention", totals.failed);
    }
    Ok(())
}

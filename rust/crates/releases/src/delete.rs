//! `nntmux-ctl releases delete`: deleting releases matching operator criteria.
//!
//! Deliberately stricter than PHP's `DeleteReleases`, which dropped any criterion it couldn't
//! translate: a mistyped column, or a group that didn't exist, widened the delete, up to every
//! release. Here that is an error, or for an unknown group a filter that matches nothing. Numbers
//! are checked rather than interpolated, `--size=0` and friends count, and `rageid` is refused
//! because that column is gone. A pattern with nothing literal in it is refused, because it would
//! match every release. Deleting also removes the NZB and preview files, which PHP left.

use std::io::Write;

use anyhow::{Context, Result, bail};
use clap::Args;
use nntmux_verify::releases::delete_single_on;
use sqlx::mysql::MySqlArguments;
use sqlx::query::QueryAs;
use sqlx::{AssertSqlSafe, MySql, MySqlPool};
use tracing::warn;

const PAGE: usize = 500;

#[derive(Debug, Default, Args)]
pub struct DeleteArgs {
    /// Advanced criteria in the original format, such as groupname=equals="alt.binaries.teevee".
    pub criteria: Vec<String>,
    /// Group name to filter by.
    #[arg(long)]
    pub group: Option<String>,
    /// Group name pattern to filter by (partial match).
    #[arg(long)]
    pub group_like: Option<String>,
    /// Poster name (fromname) to filter by.
    #[arg(long)]
    pub poster: Option<String>,
    /// Poster name pattern to filter by (partial match).
    #[arg(long)]
    pub poster_like: Option<String>,
    /// Release name to filter by.
    #[arg(long)]
    pub name: Option<String>,
    /// Release name pattern to filter by (partial match).
    #[arg(long)]
    pub name_like: Option<String>,
    /// Search name to filter by.
    #[arg(long)]
    pub search: Option<String>,
    /// Search name pattern to filter by (partial match).
    #[arg(long)]
    pub search_like: Option<String>,
    /// Category ID to filter by.
    #[arg(long)]
    pub category: Option<String>,
    /// Specific GUID to filter by.
    #[arg(long)]
    pub guid: Option<String>,
    /// Exact size in bytes.
    #[arg(long)]
    pub size: Option<String>,
    /// Minimum size in bytes.
    #[arg(long)]
    pub size_min: Option<String>,
    /// Maximum size in bytes.
    #[arg(long)]
    pub size_max: Option<String>,
    /// Delete releases older than X hours.
    #[arg(long)]
    pub hours_old: Option<String>,
    /// Delete releases newer than X hours.
    #[arg(long)]
    pub hours_new: Option<String>,
    /// Exact number of parts.
    #[arg(long)]
    pub parts: Option<String>,
    /// Minimum number of parts.
    #[arg(long)]
    pub parts_min: Option<String>,
    /// Maximum number of parts.
    #[arg(long)]
    pub parts_max: Option<String>,
    /// Maximum completion percentage.
    #[arg(long)]
    pub completion_max: Option<String>,
    /// NZB status to filter by.
    #[arg(long)]
    pub nzb_status: Option<String>,
    /// IMDB ID to filter by (use NULL for no IMDB).
    #[arg(long)]
    pub imdb: Option<String>,
    /// Show what would be deleted without deleting.
    #[arg(long)]
    pub dry_run: bool,
    /// Skip the confirmation prompt.
    #[arg(long)]
    pub force: bool,
}

/// One filter in the original `column=modifier=value` vocabulary.
#[derive(Clone, Debug, PartialEq, Eq)]
pub struct Criterion {
    pub column: String,
    pub modifier: String,
    pub value: String,
}

impl Criterion {
    fn new(column: &str, modifier: &str, value: &str) -> Self {
        Self {
            column: column.to_owned(),
            modifier: modifier.to_owned(),
            value: value.to_owned(),
        }
    }

    /// `ignore` is a placeholder the old scripts passed. Everything else must have three parts.
    pub fn parse(text: &str) -> Result<Option<Self>> {
        if text == "ignore" {
            return Ok(None);
        }
        let parts: Vec<&str> = text.split('=').collect();
        let [column, modifier, value] = parts[..] else {
            bail!("Invalid criterion: {text}");
        };
        Ok(Some(Self::new(
            column.trim(),
            modifier.trim(),
            value.trim().trim_matches(['"', '\'']),
        )))
    }
}

impl DeleteArgs {
    pub fn criteria(&self) -> Result<Vec<Criterion>> {
        let options = [
            ("groupname", "equals", &self.group),
            ("groupname", "like", &self.group_like),
            ("fromname", "equals", &self.poster),
            ("fromname", "like", &self.poster_like),
            ("name", "equals", &self.name),
            ("name", "like", &self.name_like),
            ("searchname", "equals", &self.search),
            ("searchname", "like", &self.search_like),
            ("categories_id", "equals", &self.category),
            ("guid", "equals", &self.guid),
            ("size", "equals", &self.size),
            ("size", "bigger", &self.size_min),
            ("size", "smaller", &self.size_max),
            ("adddate", "bigger", &self.hours_old),
            ("adddate", "smaller", &self.hours_new),
            ("totalpart", "equals", &self.parts),
            ("totalpart", "bigger", &self.parts_min),
            ("totalpart", "smaller", &self.parts_max),
            ("completion", "smaller", &self.completion_max),
            ("nzbstatus", "equals", &self.nzb_status),
            ("imdbid", "equals", &self.imdb),
        ];
        let mut criteria: Vec<Criterion> = options
            .into_iter()
            .filter_map(|(column, modifier, value)| {
                value
                    .as_deref()
                    .map(|value| Criterion::new(column, modifier, value))
            })
            .collect();
        for text in &self.criteria {
            criteria.extend(Criterion::parse(text)?);
        }
        Ok(criteria)
    }
}

/// A value bound into the query.
#[derive(Clone, Debug, PartialEq)]
enum Bind {
    Int(i64),
    Float(f64),
    Text(String),
}

/// `WHERE` fragments with their values, rendered once and reused for each page.
#[derive(Clone, Debug, Default)]
pub struct Filter {
    sql: String,
    binds: Vec<Bind>,
}

fn int(criterion: &Criterion) -> Result<Bind> {
    criterion.value.parse().map(Bind::Int).with_context(|| {
        format!(
            "{} needs a whole number, not {}",
            criterion.column, criterion.value
        )
    })
}

fn number(criterion: &Criterion) -> Result<Bind> {
    match criterion.value.parse::<f64>() {
        Ok(value) if value.is_finite() => Ok(Bind::Float(value)),
        _ => bail!(
            "{} needs a number, not {}",
            criterion.column,
            criterion.value
        ),
    }
}

fn like(value: &str) -> Result<String> {
    if value.chars().all(|c| matches!(c, ' ' | '%' | '_')) {
        bail!("A partial match needs some text to match, not \"{value}\".");
    }
    Ok(format!("%{}%", value.replace(' ', "%")))
}

/// Hours for an interval, written into the statement. A bound double rounds half to even there, so
/// half an hour would become none and a delete would reach newer releases than PHP's did.
fn hours(criterion: &Criterion) -> Result<f64> {
    match number(criterion)? {
        Bind::Float(hours) => Ok(hours),
        _ => bail!("{} needs a number", criterion.column),
    }
}

/// Translates criteria, looking group names up. Anything untranslatable is an error.
pub async fn build_filter(pool: &MySqlPool, criteria: &[Criterion]) -> Result<Filter> {
    let mut filter = Filter::default();
    for criterion in criteria {
        let column = criterion.column.as_str();
        let value = criterion.value.as_str();
        let (fragment, bind): (String, Option<Bind>) = match (column, criterion.modifier.as_str()) {
            ("categories_id" | "nzbstatus", "equals") => {
                (format!(" AND {column} = ?"), Some(int(criterion)?))
            }
            ("imdbid", "equals") if value == "NULL" => (" AND imdbid IS NULL".to_owned(), None),
            ("imdbid" | "guid", "equals") => (
                format!(" AND {column} = ?"),
                Some(Bind::Text(value.to_owned())),
            ),
            ("totalpart" | "size", "equals") => {
                (format!(" AND {column} = ?"), Some(int(criterion)?))
            }
            ("totalpart" | "size", "bigger") => {
                (format!(" AND {column} > ?"), Some(int(criterion)?))
            }
            ("totalpart" | "size", "smaller") => {
                (format!(" AND {column} < ?"), Some(int(criterion)?))
            }
            ("completion", "smaller") => {
                (" AND completion < ?".to_owned(), Some(number(criterion)?))
            }
            ("adddate" | "postdate", "bigger") => (
                format!(
                    " AND {column} < (NOW() - INTERVAL {} HOUR)",
                    hours(criterion)?
                ),
                None,
            ),
            ("adddate" | "postdate", "smaller") => (
                format!(
                    " AND {column} > (NOW() - INTERVAL {} HOUR)",
                    hours(criterion)?
                ),
                None,
            ),
            ("fromname" | "name" | "searchname", "equals") => (
                format!(" AND {column} = ?"),
                Some(Bind::Text(value.to_owned())),
            ),
            ("fromname" | "name" | "searchname", "like") => (
                format!(" AND {column} LIKE ?"),
                Some(Bind::Text(like(value)?)),
            ),
            ("groupname", "equals" | "like") => {
                let (sql, name) = if criterion.modifier == "equals" {
                    (
                        "SELECT CAST(id AS SIGNED) FROM usenet_groups WHERE name = ?",
                        value.to_owned(),
                    )
                } else {
                    (
                        "SELECT CAST(id AS SIGNED) FROM usenet_groups WHERE name LIKE ?",
                        like(value)?,
                    )
                };
                let ids: Vec<i64> = sqlx::query_scalar(sql).bind(name).fetch_all(pool).await?;
                if ids.is_empty() {
                    (" AND 1=0".to_owned(), None)
                } else {
                    let ids = ids.iter().map(i64::to_string).collect::<Vec<_>>().join(",");
                    (format!(" AND groups_id IN ({ids})"), None)
                }
            }
            (other, modifier) => {
                bail!("Unsupported criterion: {other}={modifier}={value}");
            }
        };
        filter.sql.push_str(&fragment);
        filter.binds.extend(bind);
    }
    Ok(filter)
}

type Row = (i64, String, Option<String>, Option<String>);

impl Filter {
    fn bind<'q, O>(
        &'q self,
        mut query: QueryAs<'q, MySql, O, MySqlArguments>,
    ) -> QueryAs<'q, MySql, O, MySqlArguments> {
        for bind in &self.binds {
            query = match bind {
                Bind::Int(value) => query.bind(*value),
                Bind::Float(value) => query.bind(*value),
                Bind::Text(value) => query.bind(value.as_str()),
            };
        }
        query
    }

    pub async fn count(&self, pool: &MySqlPool) -> Result<i64> {
        let sql = format!("SELECT COUNT(*) FROM releases WHERE 1=1{}", self.sql);
        let (count,): (i64,) = self
            .bind(sqlx::query_as(AssertSqlSafe(sql)))
            .fetch_one(pool)
            .await?;
        Ok(count)
    }

    async fn page(&self, pool: &MySqlPool, after: i64, limit: usize) -> Result<Vec<Row>> {
        let sql = format!(
            "SELECT CAST(id AS SIGNED), guid, searchname, name FROM releases WHERE 1=1{} AND id > ? ORDER BY id LIMIT {limit}",
            self.sql
        );
        Ok(self
            .bind(sqlx::query_as(AssertSqlSafe(sql)))
            .bind(after)
            .fetch_all(pool)
            .await?)
    }
}

fn label(row: &Row) -> &str {
    match (row.2.as_deref(), row.3.as_deref()) {
        (Some(search), _) if !search.is_empty() => search,
        (_, Some(name)) if !name.is_empty() => name,
        _ => "Unknown",
    }
}

fn confirmed() -> Result<bool> {
    print!("Are you sure you want to proceed with the deletion? (yes/no) [no]: ");
    std::io::stdout().flush()?;
    let mut answer = String::new();
    std::io::stdin().read_line(&mut answer)?;
    Ok(matches!(
        answer.trim().to_ascii_lowercase().as_str(),
        "y" | "yes"
    ))
}

/// Deletes every release the filter matches. A release that fails is reported and skipped.
pub async fn delete_matching(pool: &MySqlPool, filter: &Filter) -> Result<u64> {
    let total = filter.count(pool).await?;
    println!("Found {total} release(s) to delete...");
    let mut deleted = 0;
    let mut after = 0;
    loop {
        let rows = filter.page(pool, after, PAGE).await?;
        let Some(tail) = rows.last() else {
            break;
        };
        after = tail.0;
        for row in &rows {
            println!("Deleting: {}", label(row));
            match delete_single_on(pool, row.0, &row.1).await {
                Ok(()) => {
                    deleted += 1;
                    if deleted % 100 == 0 {
                        println!("Deleted {deleted}/{total} releases...");
                    }
                }
                Err(failure) => warn!(
                    release = row.0,
                    error = format!("{failure:#}"),
                    "failed to delete a release"
                ),
            }
        }
    }
    println!("Successfully deleted {deleted} release(s).");
    Ok(deleted)
}

pub async fn run(pool: &MySqlPool, args: &DeleteArgs) -> Result<()> {
    let criteria = args.criteria()?;
    if criteria.is_empty() {
        bail!("Supply at least one criterion. See --help.");
    }
    let filter = build_filter(pool, &criteria).await?;
    for criterion in &criteria {
        println!(
            "  - {}={}={}",
            criterion.column, criterion.modifier, criterion.value
        );
    }
    if args.dry_run {
        let count = filter.count(pool).await?;
        if count == 0 {
            println!("No releases found matching the specified criteria.");
            return Ok(());
        }
        println!("Found {count} release(s) that would be deleted:");
        for row in filter.page(pool, 0, 10).await? {
            println!("  [{}] {}", row.1, label(&row));
        }
        if count > 10 {
            println!("  ... and {} more releases", count - 10);
        }
        println!("Use without --dry-run to actually delete these releases");
        return Ok(());
    }
    if !args.force && !confirmed()? {
        println!("Operation cancelled.");
        return Ok(());
    }
    delete_matching(pool, &filter).await?;
    Ok(())
}

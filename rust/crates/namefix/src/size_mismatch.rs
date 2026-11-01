//! `nntmux:find-size-mismatches`: episode-named releases much bigger or smaller than their
//! files add up to, which usually means a season pack named after one episode.

use anyhow::Result;
use sqlx::{MySql, MySqlPool, QueryBuilder, Row};

use crate::release::Release;
use crate::update::{ReleaseUpdater, predb_regex};

#[derive(Clone, Debug, clap::Args)]
pub struct Args {
    /// Percentage difference to report above.
    #[arg(long, default_value_t = 20.0)]
    pub threshold: f64,
    /// Zero or less means no limit.
    #[arg(long, default_value_t = 100)]
    pub limit: i64,
    /// Only releases whose name is a season pack.
    #[arg(long)]
    pub season_pack: bool,
    /// `bigger`, `smaller` or `any`.
    #[arg(long, default_value = "any")]
    pub direction: String,
    /// Rename them from their `name` instead of listing them.
    #[arg(long)]
    pub rename: bool,
}

#[derive(Clone, Debug)]
pub struct Mismatch {
    pub id: i64,
    pub searchname: Option<String>,
    pub name: Option<String>,
    pub release_size: f64,
    pub files_total_size: f64,
    pub size_diff: f64,
    pub diff_percent: f64,
}

pub async fn find(db: &MySqlPool, args: &Args) -> Result<Vec<Mismatch>> {
    let mut query = QueryBuilder::<MySql>::new(
        "SELECT CAST(releases.id AS SIGNED) AS id, releases.searchname, releases.name, \
         CAST(releases.size / POW(1024, 3) AS DOUBLE) AS release_size, \
         CAST(SUM(release_files.size) / POW(1024, 3) AS DOUBLE) AS files_total_size, \
         CAST((releases.size - SUM(release_files.size)) / POW(1024, 3) AS DOUBLE) AS size_diff, \
         CAST((releases.size - SUM(release_files.size)) / releases.size * 100 AS DOUBLE) AS diff_percent \
         FROM releases JOIN release_files ON releases.id = release_files.releases_id \
         WHERE releases.searchname REGEXP 'S[0-9]{1,3}E[0-9]{1,3}' GROUP BY releases.id",
    );
    match args.direction.as_str() {
        "bigger" => {
            query
                .push(" HAVING size_diff > 0 AND diff_percent > ")
                .push_bind(args.threshold);
        }
        "smaller" => {
            query
                .push(" HAVING size_diff < 0 AND diff_percent < ")
                .push_bind(-args.threshold);
        }
        _ => {
            query
                .push(" HAVING ABS(diff_percent) > ")
                .push_bind(args.threshold);
        }
    }
    query.push(if args.rename {
        " ORDER BY releases.id ASC"
    } else {
        " ORDER BY diff_percent DESC"
    });
    if args.limit > 0 {
        query.push(" LIMIT ").push_bind(args.limit);
    }
    let rows = query.build().fetch_all(db).await?;
    let mut found = Vec::with_capacity(rows.len());
    for row in rows {
        let mismatch = Mismatch {
            id: row.try_get("id")?,
            searchname: row.try_get("searchname")?,
            name: row.try_get("name")?,
            release_size: row
                .try_get::<Option<f64>, _>("release_size")?
                .unwrap_or(0.0),
            files_total_size: row
                .try_get::<Option<f64>, _>("files_total_size")?
                .unwrap_or(0.0),
            size_diff: row.try_get::<Option<f64>, _>("size_diff")?.unwrap_or(0.0),
            diff_percent: row
                .try_get::<Option<f64>, _>("diff_percent")?
                .unwrap_or(0.0),
        };
        if !args.season_pack
            || nntmux_predb::season::is_season_pack(mismatch.name.as_deref().unwrap_or(""))
        {
            found.push(mismatch);
        }
    }
    Ok(found)
}

/// The name a rename would take: the first scene name in `name`, once any domain is gone.
pub fn proposed_name(name: &str) -> Option<String> {
    static DOMAIN: std::sync::LazyLock<regex::Regex> = std::sync::LazyLock::new(|| {
        regex::Regex::new(r"(?i)www\.[^ \t\n\r\f\x0b]+\.[a-z]{2,4}").expect("static pattern")
    });
    let stripped = DOMAIN.replace_all(name, "");
    predb_regex()
        .find(&stripped)
        .map(|hit| hit.as_str().to_owned())
}

/// Renames one release from its `name`. Returns the name if there was one to try.
pub async fn rename(
    db: &MySqlPool,
    updater: &mut ReleaseUpdater,
    mismatch: &Mismatch,
) -> Result<Option<String>> {
    let Some(name) = mismatch.name.as_deref().and_then(proposed_name) else {
        return Ok(None);
    };
    let sql = format!(
        "SELECT {} FROM releases r WHERE r.id = ?",
        Release::CANDIDATE_COLUMNS
    );
    let Some(row) = sqlx::query(sqlx::AssertSqlSafe(sql))
        .bind(mismatch.id)
        .fetch_optional(db)
        .await?
    else {
        return Ok(None);
    };
    let release = Release::from_row(&row)?;
    updater
        .update_release(
            &release,
            &name,
            "size-mismatch / season pack",
            true,
            "",
            true,
            0,
            None,
            None,
        )
        .await?;
    Ok(Some(name))
}

/// `number_format($value, 2)`.
pub fn number_format(value: f64) -> String {
    let text = format!("{:.2}", value.abs());
    let (whole, fraction) = text.split_once('.').unwrap_or((&text, "00"));
    let mut grouped = String::with_capacity(whole.len() + whole.len() / 3);
    for (index, digit) in whole.chars().enumerate() {
        if index > 0 && (whole.len() - index).is_multiple_of(3) {
            grouped.push(',');
        }
        grouped.push(digit);
    }
    let sign = if value < 0.0 && text != "0.00" {
        "-"
    } else {
        ""
    };
    format!("{sign}{grouped}.{fraction}")
}

#[cfg(test)]
mod tests {
    use super::{number_format, proposed_name};

    #[test]
    fn number_format_groups_and_rounds() {
        assert_eq!(number_format(1234567.891), "1,234,567.89");
        assert_eq!(number_format(-12.5), "-12.50");
        assert_eq!(number_format(-0.001), "0.00");
        assert_eq!(number_format(999.0), "999.00");
    }

    #[test]
    fn domains_are_dropped_before_the_name_is_taken() {
        assert_eq!(
            proposed_name("www.example.com Show.Name.S01.1080p.WEB-DL.x264-GRP").as_deref(),
            Some("Show.Name.S01.1080p.WEB-DL.x264-GRP")
        );
        assert_eq!(proposed_name("nothing"), None);
    }
}

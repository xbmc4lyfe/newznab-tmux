//! `binaryblacklist`: the per-group black and white lists applied to overview fields.

use std::collections::{BTreeSet, HashMap};
use std::time::{Duration, Instant};

use anyhow::Result;
use nntmux_core::pcre::Pcre;
use sqlx::{AssertSqlSafe, MySqlPool};
use tracing::warn;

use crate::header::Overview;

const OPTYPE_BLACKLIST: u32 = 1;
const OPTYPE_WHITELIST: u32 = 2;
const FIELD_SUBJECT: u32 = 1;
const FIELD_FROM: u32 = 2;
const FIELD_MESSAGE_ID: u32 = 3;
/// PHP read these once per short-lived process. A long-running role rereads them this often.
const REFRESH: Duration = Duration::from_secs(60);

#[derive(Clone, Debug)]
struct Rule {
    id: u32,
    field: u32,
    regex: Pcre,
}

#[derive(Clone, Debug, Default)]
pub struct Lists {
    black: Vec<Rule>,
    white: Vec<Rule>,
}

impl Lists {
    /// A regex PHP couldn't compile never matched, because `@preg_match` returned false, so a
    /// bad rule doesn't stop the rest.
    fn rules(rows: Vec<(u32, String, u32)>) -> Vec<Rule> {
        rows.into_iter()
            .filter_map(
                |(id, regex, field)| match Pcre::new(&format!("/{regex}/i")) {
                    Ok(regex) => Some(Rule { id, field, regex }),
                    Err(failure) => {
                        warn!(
                            id,
                            error = format!("{failure:#}"),
                            "skipping a blacklist regex"
                        );
                        None
                    }
                },
            )
            .collect()
    }

    /// Whitelists first: with any set, an article must match one. Then the blacklist.
    /// `matched` collects the rule ids that took effect.
    pub fn is_blacklisted(&self, header: &Overview, matched: &mut BTreeSet<u32>) -> bool {
        let mut blacklisted = false;
        if !self.white.is_empty() {
            blacklisted = true;
            if let Some(rule) = self.white.iter().find(|rule| rule.matches(header)) {
                blacklisted = false;
                matched.insert(rule.id);
            }
        }
        if !blacklisted {
            if let Some(rule) = self.black.iter().find(|rule| rule.matches(header)) {
                blacklisted = true;
                matched.insert(rule.id);
            }
        }
        blacklisted
    }
}

impl Rule {
    fn matches(&self, header: &Overview) -> bool {
        let field: &[u8] = match self.field {
            FIELD_SUBJECT => &header.subject,
            FIELD_FROM => &header.from,
            FIELD_MESSAGE_ID => &header.message_id,
            _ => return false,
        };
        self.regex.is_match(field)
    }
}

/// Reads and remembers the lists for each group.
#[derive(Default)]
pub struct Blacklist {
    cached: HashMap<String, (Instant, Lists)>,
}

impl Blacklist {
    pub async fn lists(&mut self, pool: &MySqlPool, group: &str) -> Result<&Lists> {
        let stale = self
            .cached
            .get(group)
            .is_none_or(|(read, _)| read.elapsed() >= REFRESH);
        if stale {
            let lists = Lists {
                black: Lists::rules(rows(pool, OPTYPE_BLACKLIST, group).await?),
                white: Lists::rules(rows(pool, OPTYPE_WHITELIST, group).await?),
            };
            self.cached
                .insert(group.to_owned(), (Instant::now(), lists));
        }
        Ok(&self.cached[group].1)
    }
}

/// The group name is itself a regex here, as it was in PHP, so `alt.binaries.mp3` also brings in
/// the rules of `alt.binaries.mp3.complete`.
async fn rows(pool: &MySqlPool, optype: u32, group: &str) -> Result<Vec<(u32, String, u32)>> {
    Ok(sqlx::query_as(
        "SELECT bb.id, bb.regex, bb.msgcol
         FROM binaryblacklist bb
         LEFT OUTER JOIN usenet_groups g ON g.name REGEXP bb.groupname
         WHERE bb.status = 1 AND bb.optype = ? AND g.name REGEXP ?
         ORDER BY COALESCE(groupname, 'zzz')",
    )
    .bind(optype)
    .bind(group)
    .fetch_all(pool)
    .await?)
}

/// Records when the rules last matched.
pub async fn record_usage(pool: &MySqlPool, ids: &BTreeSet<u32>) -> Result<()> {
    if ids.is_empty() {
        return Ok(());
    }
    let marks = vec!["?"; ids.len()].join(",");
    let sql = format!("UPDATE binaryblacklist SET last_activity = NOW() WHERE id IN ({marks})");
    let mut query = sqlx::query(AssertSqlSafe(sql));
    for id in ids {
        query = query.bind(id);
    }
    query.execute(pool).await?;
    Ok(())
}

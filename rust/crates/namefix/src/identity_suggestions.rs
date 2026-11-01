//! `releases:identity-suggestions`: what `predb_constrained` would suggest for a name, as one
//! line of JSON. It only ever reads.

use std::future::Future;

use anyhow::{Context, Result, bail};
use nntmux_categorize::pcre::{PHP_WS, replace_all, trim_chars};
use nntmux_categorize::rx;
use nntmux_core::pcre::Pcre;
use nntmux_runtime::Ctx;
use nntmux_search::SearchClient;
use serde::Serialize;
use sqlx::{MySqlPool, QueryBuilder};

use crate::identity_key;
use crate::predb_constrained::{Hit, complete_evidence, suggest};

const MAX_CANDIDATES: usize = 50;
const MAX_QUERY_BYTES: usize = 512;

#[derive(Clone, Debug, Default)]
pub struct Request {
    pub query: Option<String>,
    pub release_id: Option<String>,
    pub candidate_ids: Vec<String>,
}

#[derive(Serialize)]
struct Report<'a> {
    query: &'a str,
    candidate_predb_id: Option<i64>,
    known_title: Option<&'a str>,
    #[serde(serialize_with = "php_float")]
    score: Option<f64>,
    reason: &'a str,
    auto_link: bool,
    marker_evidence: &'a str,
    canonical_name_agreement: bool,
    candidate_scope: &'a str,
    requires: &'a str,
}

#[derive(Serialize)]
struct Overflow {
    candidate_predb_id: Option<i64>,
    reason: &'static str,
    auto_link: bool,
}

/// `json_encode` drops a whole float's fraction unless told not to.
fn php_float<S: serde::Serializer>(value: &Option<f64>, serializer: S) -> Result<S::Ok, S::Error> {
    match value {
        Some(number) if number.fract() == 0.0 => serializer.serialize_i64(*number as i64),
        Some(number) => serializer.serialize_f64(*number),
        None => serializer.serialize_none(),
    }
}

/// PHP's `json_encode` with `JSON_UNESCAPED_UNICODE`, which still escapes slashes and U+2028/9.
fn php_json(value: &impl Serialize) -> Result<String> {
    Ok(serde_json::to_string(value)?
        .replace('/', r"\/")
        .replace('\u{2028}', r"\u2028")
        .replace('\u{2029}', r"\u2029"))
}

/// `NameFixingService::predbSearchText`: only letters, digits, whitespace, dots, underscores and
/// inner dashes survive, so Manticore's query syntax can't misread the name.
pub fn predb_search_text(file_name: &str) -> String {
    static UNSAFE: std::sync::LazyLock<Pcre> =
        std::sync::LazyLock::new(|| Pcre::new(r"/[^\p{L}\p{N}\s._-]+/u").expect("static pattern"));
    static LEADING: std::sync::LazyLock<Pcre> =
        std::sync::LazyLock::new(|| Pcre::new(r"/(?<=^|\s)-+/").expect("static pattern"));
    let text = UNSAFE.replace_all(file_name.as_bytes(), b" ");
    let text = LEADING.replace_all(&text, b"");
    let text = String::from_utf8_lossy(&text);
    let text = replace_all(rx!(r"\s+"), &text, " ");
    trim_chars(&text, PHP_WS).to_owned()
}

/// The query, the rule failures with PHP's wording, and the candidate IDs as numbers.
fn validated(query: String, ids: &[String]) -> Result<(String, Vec<i64>)> {
    if query.is_empty() || query.len() > MAX_QUERY_BYTES {
        bail!("Provide a release ID or a valid query of at most 512 bytes.");
    }
    if ids.len() > MAX_CANDIDATES {
        bail!("At most 50 candidate IDs may be supplied.");
    }
    let mut numbers = Vec::with_capacity(ids.len());
    for id in ids {
        // `(int)` saturates in PHP, so a huge ID is a valid one that finds nothing.
        let number = (!id.is_empty() && id.bytes().all(|byte| byte.is_ascii_digit()))
            .then(|| id.parse::<i64>().unwrap_or(i64::MAX))
            .filter(|number| *number >= 1);
        match number {
            Some(number) => numbers.push(number),
            None => bail!("Candidate IDs must be positive integers."),
        }
    }
    Ok((query, numbers))
}

/// The report line, given how to find candidates when the caller named none.
pub async fn report<F, Fut>(pool: &MySqlPool, request: Request, search: F) -> Result<String>
where
    F: FnOnce(String) -> Fut,
    Fut: Future<Output = Result<Vec<i64>>>,
{
    let mut query = request.query.unwrap_or_default();
    if query.is_empty()
        && let Some(release_id) = request
            .release_id
            .as_deref()
            .filter(|id| !id.is_empty() && id.bytes().all(|byte| byte.is_ascii_digit()))
    {
        let id: i64 = release_id.parse().unwrap_or(0);
        let name: Option<String> =
            sqlx::query_scalar("SELECT searchname FROM releases WHERE id = ?")
                .bind(id)
                .fetch_optional(pool)
                .await
                .context("Identity suggestion unavailable")?;
        query = name.unwrap_or_default();
    }
    let (query, mut ids) = validated(query, &request.candidate_ids)?;

    if ids.is_empty() {
        ids = search(predb_search_text(&query))
            .await
            .context("Identity suggestion unavailable")?;
        if ids.len() > MAX_CANDIDATES {
            return php_json(&Overflow {
                candidate_predb_id: None,
                reason: "candidate_overflow",
                auto_link: false,
            });
        }
    }
    let hits = rows(pool, &ids)
        .await
        .context("Identity suggestion unavailable")?;
    let suggestion = suggest(&query, &hits);
    let complete = complete_evidence(&query, &hits);
    let agreement = suggestion
        .title
        .as_deref()
        .is_some_and(|title| identity_key::normalize(&query) == identity_key::normalize(title));
    php_json(&Report {
        query: &query,
        candidate_predb_id: suggestion.id,
        known_title: suggestion.title.as_deref(),
        score: suggestion.score,
        reason: suggestion.reason,
        auto_link: false,
        marker_evidence: if complete.id.is_some() {
            "complete"
        } else {
            "unresolved_variant"
        },
        canonical_name_agreement: agreement,
        candidate_scope: "bounded_database_revalidated_candidates",
        requires: "independent exact filename or registered alias corroboration",
    })
}

async fn rows(pool: &MySqlPool, ids: &[i64]) -> Result<Vec<Hit>> {
    if ids.is_empty() {
        return Ok(Vec::new());
    }
    let mut query = QueryBuilder::new("SELECT CAST(id AS SIGNED), title FROM predb WHERE id IN (");
    let mut list = query.separated(", ");
    for id in ids {
        list.push_bind(*id);
    }
    list.push_unseparated(")");
    let rows: Vec<(i64, String)> = query.build_query_as().fetch_all(pool).await?;
    Ok(rows
        .into_iter()
        .map(|(id, title)| Hit { id, title })
        .collect())
}

/// [`report`] with Manticore finding the candidates. As in PHP, a search that fails finds nothing,
/// and the client is only built when a search is needed.
pub async fn run(ctx: &Ctx, request: Request) -> Result<String> {
    report(&ctx.db, request, |text| async move {
        let search = SearchClient::from_ctx(ctx).context("Identity suggestion unavailable")?;
        let hits = match search.search_predb(&text).await {
            Ok(hits) => hits,
            Err(error) => {
                tracing::warn!(%error, "PreDB search failed");
                Vec::new()
            }
        };
        Ok(hits.into_iter().map(|hit| hit.id).collect())
    })
    .await
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn search_text_is_safe_for_manticore() {
        assert_eq!(
            predb_search_text("-Some (Film) [2021]  x264-GRP! --flag"),
            "Some Film 2021 x264-GRP flag"
        );
    }

    #[test]
    fn checks_its_input_in_php_order_with_php_wording() {
        let long = "a".repeat(513);
        for (query, ids, message) in [
            ("", vec![], "Provide a release ID"),
            (long.as_str(), vec![], "Provide a release ID"),
            ("x", vec!["1".to_owned(); 51], "At most 50"),
            ("x", vec!["0".to_owned()], "positive integers"),
            ("x", vec!["-1".to_owned()], "positive integers"),
            ("x", vec!["abc".to_owned()], "positive integers"),
        ] {
            let error = validated(query.to_owned(), &ids).expect_err("should fail");
            assert!(error.to_string().contains(message), "{error}");
        }
        assert_eq!(
            validated("x".to_owned(), &["7".to_owned()]).expect("ok").1,
            [7]
        );
    }

    #[test]
    fn slashes_are_escaped_as_php_does() {
        assert_eq!(php_json(&"a/b").expect("json"), r#""a\/b""#);
        assert_eq!(php_json(&"a\u{2028}é").expect("json"), r#""a\u2028é""#);
    }
}

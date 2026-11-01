//! `ApiV2Presenter`, `ReleaseData` and `DetailsData`: release rows as the v2 JSON.

use chrono::Utc;

use crate::auth::User;
use crate::json::{J, Style};
use crate::reply::Reply;
use crate::rows::{Row, is_movie, is_tv, parse_time, rss_date, scalar_int};
use crate::usage::{Statistics, rfc2822};

/// `Carbon::parse($value)->toRssString()`: a missing date reads as now, as Carbon does.
fn added(row: &Row, key: &str) -> J {
    J::Str(rss_date(
        parse_time(&row.text(key)).unwrap_or_else(Utc::now),
    ))
}

fn null_if_zero(value: J) -> J {
    match &value {
        J::Int(0) => J::Null,
        J::Str(text) if text.is_empty() || text == "0" => J::Null,
        _ => value,
    }
}

fn partial_counts(row: &Row, out: &mut Vec<(String, J)>) {
    if row.get("articles_expected").is_none() {
        return;
    }
    for key in [
        "articles_found",
        "articles_expected",
        "files_found",
        "files_expected",
    ] {
        out.push((key.to_owned(), J::Int(row.int(key))));
    }
}

fn push(out: &mut Vec<(String, J)>, key: &str, value: J) {
    out.push((key.to_owned(), value));
}

/// `ReleaseData::toArrayFromRelease`: one search result.
pub fn release(row: &Row, user: &User, url: &str) -> J {
    let category = row.int("categories_id");
    let guid = row.text("guid");
    let mut out = Vec::with_capacity(24);
    push(&mut out, "title", J::Str(row.text("searchname")));
    push(&mut out, "guid", J::Str(guid.clone()));
    push(&mut out, "details", J::Str(format!("{url}/details/{guid}")));
    push(
        &mut out,
        "url",
        J::Str(format!("{url}/getnzb?id={guid}.nzb&r={}", user.api_token)),
    );
    push(&mut out, "category", J::Int(category));
    push(&mut out, "category_name", row.or_null("category_name"));
    push(&mut out, "added", added(row, "adddate"));
    push(&mut out, "size", row.or_null("size"));
    push(&mut out, "files", row.or_null("totalpart"));
    push(&mut out, "grabs", null_if_zero(row.or_null("grabs")));
    push(&mut out, "comments", null_if_zero(row.or_null("comments")));
    push(&mut out, "password", row.or_null("passwordstatus"));
    push(&mut out, "usenetdate", added(row, "postdate"));
    partial_counts(row, &mut out);
    if is_movie(category) {
        push(&mut out, "imdbid", null_if_zero(row.or_null("imdbid")));
        push(&mut out, "tmdbid", null_if_zero(row.or_null("tmdbid")));
        push(&mut out, "traktid", null_if_zero(row.or_null("traktid")));
    } else if is_tv(category) {
        push(&mut out, "imdbid", null_if_zero(row.or_null("imdb")));
        push(&mut out, "tmdbid", null_if_zero(row.or_null("tmdb")));
        push(&mut out, "traktid", null_if_zero(row.or_null("trakt")));
        push(&mut out, "episode_title", row.or_null("title"));
        push(&mut out, "season", row.or_null("series"));
        push(&mut out, "episode", row.or_null("episode"));
        push(&mut out, "tvairdate", row.or_null("firstaired"));
        push(&mut out, "tvdbid", null_if_zero(row.or_null("tvdb")));
        push(&mut out, "tvrageid", null_if_zero(row.or_null("tvrage")));
        push(&mut out, "tvmazeid", null_if_zero(row.or_null("tvmaze")));
    }
    J::Obj(out)
}

/// `DetailsData::toArrayFromRelease`, the `/api/v2/details` body.
pub fn details(row: &Row, user: &User, url: &str) -> Reply {
    let category = row.int("categories_id");
    let guid = row.text("guid");
    let mut out = Vec::with_capacity(24);
    push(&mut out, "title", J::Str(row.text("searchname")));
    push(&mut out, "details", J::Str(format!("{url}/details/{guid}")));
    push(
        &mut out,
        "link",
        J::Str(format!("{url}/getnzb?id={guid}.nzb&r={}", user.api_token)),
    );
    push(&mut out, "category", J::Int(category));
    push(&mut out, "category_name", row.or_null("category_name"));
    push(&mut out, "added", added(row, "adddate"));
    push(&mut out, "size", row.or_null("size"));
    push(&mut out, "files", row.or_null("totalpart"));
    push(&mut out, "grabs", row.or_null("grabs"));
    push(&mut out, "comments", row.or_null("comments"));
    push(&mut out, "password", row.or_null("passwordstatus"));
    push(&mut out, "usenetdate", added(row, "postdate"));
    partial_counts(row, &mut out);
    if is_movie(category) {
        push(&mut out, "imdbid", row.or_null("imdbid"));
    } else if is_tv(category) {
        push(&mut out, "imdbid", row.or_null("imdb"));
        push(&mut out, "tmdbid", row.or_null("tmdb"));
        push(&mut out, "traktid", row.or_null("trakt"));
        push(&mut out, "tvairdate", row.or_null("firstaired"));
        push(&mut out, "tvdbid", row.or_null("tvdb"));
        push(&mut out, "tvrageid", row.or_null("tvrage"));
        push(&mut out, "tvmazeid", row.or_null("tvmaze"));
    }
    Reply::json(200, &J::Obj(out), Style::Unescaped)
}

/// The search envelope: total and usage inline, results beside them.
pub fn search(
    rows: &[Row],
    user: &User,
    stats: &Statistics,
    server_url: &str,
    pagination: Option<(Option<String>, bool)>,
) -> Reply {
    let total = rows
        .first()
        .and_then(|row| row.get("_totalrows"))
        .map_or(0, scalar_int);
    let mut out = vec![
        ("Total".to_owned(), J::Int(total)),
        ("apiCurrent".to_owned(), J::Int(stats.api_count)),
        ("apiMax".to_owned(), J::Int(user.api_requests)),
        ("grabCurrent".to_owned(), J::Int(stats.grab_count)),
        ("grabMax".to_owned(), J::Int(user.download_requests)),
        (
            "apiOldestTime".to_owned(),
            J::Str(stats.api_time.map(rfc2822).unwrap_or_default()),
        ),
        (
            "grabOldestTime".to_owned(),
            J::Str(stats.grab_time.map(rfc2822).unwrap_or_default()),
        ),
        (
            "results".to_owned(),
            J::Arr(
                rows.iter()
                    .map(|row| release(row, user, server_url))
                    .collect(),
            ),
        ),
    ];
    if let Some((next_cursor, has_more)) = pagination {
        out.push((
            "pagination".to_owned(),
            J::obj([
                ("next_cursor", J::opt_str(next_cursor)),
                ("has_more", J::Bool(has_more)),
            ]),
        ));
    }
    Reply::json(200, &J::Obj(out), Style::Unescaped)
}

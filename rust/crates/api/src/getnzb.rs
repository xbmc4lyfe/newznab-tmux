//! `GetNzbController`: NZB downloads for `/getnzb` and both API versions.

use std::collections::HashSet;
use std::io::{Cursor, Write};

use anyhow::Result;
use chrono::{Duration, Utc};
use nntmux_runtime::Ctx;
use sqlx::{AssertSqlSafe, QueryBuilder};
use zip::write::SimpleFileOptions;

use crate::auth::{self, User};
use crate::filename;
use crate::input::{Input, Value, php_int};
use crate::nzb;
use crate::reply::{Reply, json_error, xml_error};
use crate::request::ApiRequest;
use crate::rows::imdb_id_is_valid;
use crate::usage::raw_url_encode;

const SUFFIX: &str = ".nzb";

/// How a caller wants errors rendered: newznab XML by default, JSON for API v2.
#[derive(Clone, Copy, PartialEq, Eq)]
pub enum Errors {
    Xml,
    Json,
}

impl Errors {
    fn reply(self, code: u16, text: &str) -> Reply {
        match self {
            Self::Xml => xml_error(code, text),
            Self::Json => json_error(code, text),
        }
    }
}

/// `str_ireplace('.nzb', '', $id)`.
pub fn strip_suffix(id: &str) -> String {
    let lower = id.to_ascii_lowercase();
    let mut out = String::with_capacity(id.len());
    let mut at = 0;
    while let Some(found) = lower[at..].find(SUFFIX) {
        out.push_str(&id[at..at + found]);
        at += found + SUFFIX.len();
    }
    out.push_str(&id[at..]);
    out
}

/// `scalarInput($request, 'id')`.
pub fn scalar_id(input: &Input) -> String {
    match input.get("id") {
        Some(Value::Str(id)) => id.clone(),
        _ => String::new(),
    }
}

pub async fn guid_exists(ctx: &Ctx, guid: &str) -> Result<bool> {
    let found: Option<u32> = sqlx::query_scalar("SELECT id FROM releases WHERE guid = ? LIMIT 1")
        .bind(guid)
        .fetch_optional(&ctx.db)
        .await?;
    Ok(found.is_some())
}

async fn downloads_today(ctx: &Ctx, user_id: i64) -> Result<i64> {
    let since = (Utc::now() - Duration::days(1))
        .format("%Y-%m-%d %H:%M:%S")
        .to_string();
    Ok(sqlx::query_scalar(
        "SELECT COUNT(*) FROM user_downloads WHERE users_id = ? AND timestamp > ?",
    )
    .bind(user_id)
    .bind(since)
    .fetch_one(&ctx.db)
    .await?)
}

/// The `/getnzb` route: `r` carries the token. Session sign-in arrives with the site's `webkit`.
pub async fn web(ctx: &Ctx, request: &ApiRequest, path_guid: Option<&str>) -> Result<Reply> {
    if request.method == "POST" {
        // The web group's CSRF check stops a POST with no session token before the controller.
        return Ok(Reply::new(419, "text/html; charset=utf-8", "Page Expired"));
    }
    let input = &request.input;
    let Some(token) = input.get("r") else {
        return Ok(web_headers(xml_error(200, "")));
    };
    let Some(user) = auth::find_verified(ctx, token.as_str().unwrap_or_default()).await? else {
        return Ok(web_headers(xml_error(100, "")));
    };
    let id = match path_guid {
        Some(guid) if !input.has("id") => guid.to_owned(),
        _ => scalar_id(input),
    };
    Ok(web_headers(
        download(ctx, request, &user, &id, Errors::Xml, false).await?,
    ))
}

/// What the web middleware group adds to every response it lets through.
fn web_headers(reply: Reply) -> Reply {
    reply
        .header("referrer-policy", "strict-origin-when-cross-origin")
        .header("x-content-type-options", "nosniff")
        .header("x-frame-options", "SAMEORIGIN")
}

/// The controller from `authenticateUser` on, with the user already resolved.
pub async fn download(
    ctx: &Ctx,
    request: &ApiRequest,
    user: &User,
    id: &str,
    errors: Errors,
    signed_in: bool,
) -> Result<Reply> {
    if !user.verified {
        return Ok(errors.reply(100, ""));
    }
    if user.disabled() {
        return Ok(errors.reply(101, ""));
    }
    let used = downloads_today(ctx, user.id).await?;
    if used >= user.download_requests {
        return Ok(errors.reply(501, ""));
    }
    if id.is_empty() {
        return Ok(errors.reply(200, "Parameter id is required"));
    }
    let guid = strip_suffix(id);
    let delete_from_cart = request
        .input
        .str("del")
        .is_some_and(|value| php_int(value) == 1);
    if request.input.str("zip") == Some("1") {
        return zip_download(ctx, user, &guid, used, delete_from_cart, errors).await;
    }
    single(
        ctx,
        request,
        user,
        &guid,
        delete_from_cart,
        errors,
        signed_in,
    )
    .await
}

async fn grab_status(ctx: &Ctx) -> Result<bool> {
    let status: Option<bool> =
        sqlx::query_scalar("SELECT grab_status FROM ingestion_configurations LIMIT 1")
            .fetch_optional(&ctx.db)
            .await?;
    Ok(status.unwrap_or(true))
}

#[derive(sqlx::FromRow)]
struct ReleaseInfo {
    id: u32,
    searchname: String,
    imdbid: Option<String>,
    nfostatus: bool,
    tvdb: Option<u32>,
    category: String,
}

async fn release_info(ctx: &Ctx, guid: &str) -> Result<Option<ReleaseInfo>> {
    Ok(sqlx::query_as(
        "SELECT r.id, r.searchname, r.imdbid, r.nfostatus, v.tvdb,
                CONCAT(COALESCE(cp.title, ''), ' > ', COALESCE(c.title, '')) AS category
         FROM releases r
         LEFT JOIN videos v ON v.id = r.videos_id
         LEFT JOIN categories c ON c.id = r.categories_id
         LEFT JOIN root_categories cp ON cp.id = c.root_categories_id
         WHERE r.guid = ? LIMIT 1",
    )
    .bind(guid)
    .fetch_optional(&ctx.db)
    .await?)
}

/// Laravel's `e()`.
fn html_escape(text: &str) -> String {
    text.replace('&', "&amp;")
        .replace('<', "&lt;")
        .replace('>', "&gt;")
        .replace('"', "&quot;")
        .replace('\'', "&#039;")
}

async fn single(
    ctx: &Ctx,
    request: &ApiRequest,
    user: &User,
    guid: &str,
    delete_from_cart: bool,
    errors: Errors,
    signed_in: bool,
) -> Result<Reply> {
    let Some(xml) = nzb::read(ctx, guid).await? else {
        return Ok(errors.reply(300, "NZB file not found!"));
    };
    let Some(release) = release_info(ctx, guid).await? else {
        return Ok(errors.reply(300, "Release not found!"));
    };

    if grab_status(ctx).await? {
        sqlx::query("UPDATE releases SET grabs = grabs + 1 WHERE guid = ?")
            .bind(guid)
            .execute(&ctx.db)
            .await?;
    }
    let now = crate::usage::now_string();
    sqlx::query("INSERT INTO user_downloads (users_id, releases_id, timestamp) VALUES (?, ?, ?)")
        .bind(user.id)
        .bind(release.id)
        .bind(&now)
        .execute(&ctx.db)
        .await?;
    sqlx::query(
        "UPDATE users SET grabs = grabs + 1, lastdownload = ?, updated_at = ? WHERE id = ?",
    )
    .bind(&now)
    .bind(&now)
    .bind(user.id)
    .execute(&ctx.db)
    .await?;
    if delete_from_cart {
        sqlx::query("DELETE FROM users_releases WHERE users_id = ? AND releases_id = ?")
            .bind(user.id)
            .bind(release.id)
            .execute(&ctx.db)
            .await?;
    }

    let url = request.base_url();
    let mut failure = format!(
        "{url}/failed?guid={}&userid={}",
        raw_url_encode(guid),
        user.id
    );
    if !signed_in && !user.api_token.is_empty() {
        failure.push_str(&format!("&api_token={}", raw_url_encode(&user.api_token)));
    }
    let expires = (Utc::now() + Duration::days(365))
        .format("%a, %d %b %Y %H:%M:%S GMT")
        .to_string();
    let fallback_stem = format!("release-{guid}");
    let clean = filename::sanitize(&release.searchname, &fallback_stem);
    let ascii = filename::ascii_fallback(&clean, &fallback_stem);
    let mut reply = Reply::new(200, "application/x-nzb", nzb::tag_posters(&xml, user.id))
        .header("cache-control", "private, must-revalidate")
        .header("expires", expires)
        .header("x-dnzb-failure", failure)
        .header("x-dnzb-category", html_escape(&release.category))
        .header("x-dnzb-details", format!("{url}/details/{guid}"))
        .header("x-dnzb-rcode", "200")
        .header("x-dnzb-rtext", "OK, NZB content follows.")
        .header(
            "content-disposition",
            filename::attachment(&format!("{clean}{SUFFIX}"), &format!("{ascii}{SUFFIX}")),
        );
    match (&release.imdbid, release.tvdb) {
        (Some(imdb), _) if imdb != "0" && !imdb.is_empty() && imdb_id_is_valid(imdb) => {
            reply.set_header(
                "x-dnzb-moreinfo",
                format!("http://www.imdb.com/title/tt{imdb}"),
            );
        }
        (_, Some(tvdb)) if tvdb > 0 => {
            reply.set_header(
                "x-dnzb-moreinfo",
                format!("http://www.thetvdb.com/?tab=series&id={tvdb}"),
            );
        }
        _ => {}
    }
    if release.nfostatus {
        reply.set_header("x-dnzb-nfo", format!("{url}/nfo/{guid}"));
    }
    Ok(reply)
}

/// `zip=1`: several comma-separated GUIDs in one archive, each counted as a download.
async fn zip_download(
    ctx: &Ctx,
    user: &User,
    ids: &str,
    used: i64,
    delete_from_cart: bool,
    errors: Errors,
) -> Result<Reply> {
    let guids: Vec<&str> = ids.split(',').collect();
    let count = i64::try_from(guids.len())?;
    if used + count > user.download_requests {
        return Ok(errors.reply(501, ""));
    }

    let mut archive = zip::ZipWriter::new(Cursor::new(Vec::new()));
    // Reposts can share a name. ZipStream skips later entries, comparing case-insensitively.
    let mut names = HashSet::new();
    for guid in &guids {
        let Some(xml) = nzb::read(ctx, guid).await? else {
            continue;
        };
        if xml.is_empty() {
            continue;
        }
        let stored: Option<String> =
            sqlx::query_scalar("SELECT searchname FROM releases WHERE guid = ? LIMIT 1")
                .bind(guid)
                .fetch_optional(&ctx.db)
                .await?;
        let name = stored.unwrap_or_else(|| (*guid).to_owned());
        let entry = format!("{name}{SUFFIX}");
        if !names.insert(entry.to_lowercase()) {
            continue;
        }
        archive.start_file(entry, SimpleFileOptions::default())?;
        archive.write_all(&xml)?;
    }
    let bytes = archive.finish()?.into_inner();

    let now = crate::usage::now_string();
    sqlx::query(
        "UPDATE users SET grabs = grabs + ?, lastdownload = ?, updated_at = ? WHERE id = ?",
    )
    .bind(count)
    .bind(&now)
    .bind(&now)
    .bind(user.id)
    .execute(&ctx.db)
    .await?;
    let mut lookup = QueryBuilder::new("SELECT id FROM releases WHERE guid IN (");
    let mut list = lookup.separated(", ");
    for guid in &guids {
        list.push_bind(*guid);
    }
    lookup.push(")");
    let release_ids: Vec<u32> = lookup.build_query_scalar().fetch_all(&ctx.db).await?;
    if !release_ids.is_empty() {
        let joined = release_ids
            .iter()
            .map(u32::to_string)
            .collect::<Vec<_>>()
            .join(",");
        if grab_status(ctx).await? {
            sqlx::query(AssertSqlSafe(format!(
                "UPDATE releases SET grabs = grabs + 1 WHERE id IN ({joined})"
            )))
            .execute(&ctx.db)
            .await?;
        }
        let mut insert =
            QueryBuilder::new("INSERT INTO user_downloads (users_id, releases_id, timestamp) ");
        insert.push_values(&release_ids, |mut row, id| {
            row.push_bind(user.id).push_bind(*id).push_bind(&now);
        });
        insert.build().execute(&ctx.db).await?;
        if delete_from_cart {
            sqlx::query(AssertSqlSafe(format!(
                "DELETE FROM users_releases WHERE users_id = {} AND releases_id IN ({joined})",
                user.id
            )))
            .execute(&ctx.db)
            .await?;
        }
    }

    let name = format!("{}.zip", Utc::now().format("%Y%m%d%I%M%S"));
    Ok(Reply::new(200, "application/x-zip", bytes)
        .header(
            "content-disposition",
            format!("attachment; filename*=UTF-8''{name}"),
        )
        .header("pragma", "public")
        .header("cache-control", "public, must-revalidate")
        .header("content-transfer-encoding", "binary")
        .header("x-accel-buffering", "no"))
}

//! The small endpoints: health, media info and the release inform hook.

use anyhow::Result;
use nntmux_runtime::Ctx;

use crate::auth;
use crate::db::row_json;
use crate::json::{J, Style};
use crate::reply::{Reply, json_error};
use crate::request::ApiRequest;

pub fn rss_health() -> Reply {
    Reply::json(
        200,
        &J::obj([("status", J::str("ok")), ("service", J::str("rss"))]),
        Style::Plain,
    )
}

pub fn fallback() -> Reply {
    Reply::message(404, "Not Found!")
}

/// `GET /api/release/{id}/mediainfo`: no auth, for internal use. PHP's int coercion refuses a
/// non-numeric id with a 500, which clients of this route can't otherwise tell from a bad row.
pub async fn media_info(ctx: &Ctx, id: &str) -> Result<Reply> {
    if !crate::input::is_numeric(id) {
        return Ok(Reply::server_error());
    }
    let id = crate::input::php_int(id);
    let video = sqlx::query("SELECT * FROM video_data WHERE releases_id = ? LIMIT 1")
        .bind(id)
        .fetch_optional(&ctx.db)
        .await?;
    let audio = sqlx::query("SELECT * FROM audio_data WHERE releases_id = ? ORDER BY audioid")
        .bind(id)
        .fetch_all(&ctx.db)
        .await?;
    let subs: Option<String> = sqlx::query_scalar(
        "SELECT GROUP_CONCAT(subslanguage SEPARATOR ', ') FROM release_subtitles WHERE releases_id = ? ORDER BY subsid",
    )
    .bind(id)
    .fetch_one(&ctx.db)
    .await?;
    let video = video.as_ref().map(row_json).transpose()?.unwrap_or(J::Null);
    let audio = if audio.is_empty() {
        J::Null
    } else {
        J::Arr(audio.iter().map(row_json).collect::<Result<_>>()?)
    };
    Ok(Reply::json(
        200,
        &J::obj([
            ("video", video),
            ("audio", audio),
            ("subs", J::opt_str(subs)),
        ]),
        Style::Plain,
    ))
}

/// `GET /api/inform/release`: a client reports the proper name for an obfuscated one.
pub async fn inform(ctx: &Ctx, request: &ApiRequest) -> Result<Reply> {
    let input = &request.input;
    let token = input.string("api_token");
    let Some(user) = auth::find_verified(ctx, &token).await? else {
        return Ok(json_error(100, ""));
    };
    if user.disabled() {
        return Ok(json_error(101, ""));
    }
    // PHP's `empty` also rejects "0".
    let given = |key: &str| {
        input
            .str(key)
            .filter(|value| *value != "0")
            .map(str::to_owned)
    };
    let (Some(original), Some(proper)) = (given("relo"), given("relp")) else {
        return Ok(Reply::message(
            404,
            "Indexer inform error, wrong data supplied!",
        ));
    };
    if token == "0" {
        return Ok(Reply::message(
            404,
            "Indexer inform error, wrong data supplied!",
        ));
    }
    // A known release must be renamed too, which needs the name fixer. Until that's ported, say so
    // rather than report a rename that never happens.
    let known: Option<u32> =
        sqlx::query_scalar("SELECT id FROM releases WHERE searchname = ? LIMIT 1")
            .bind(&original)
            .fetch_optional(&ctx.db)
            .await?;
    if known.is_some() {
        return Ok(Reply::message(501, "Not ported yet"));
    }
    let now = crate::usage::now_string();
    sqlx::query(
        "INSERT IGNORE INTO release_informs (relOName, relPName, api_token, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?)",
    )
    .bind(&original)
    .bind(&proper)
    .bind(&token)
    .bind(&now)
    .bind(&now)
    .execute(&ctx.db)
    .await?;
    Ok(Reply::message(200, "Release Information Added!"))
}

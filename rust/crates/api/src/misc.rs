//! The small endpoints: health, media info and the release inform hook.

use std::sync::Arc;

use anyhow::Result;
use async_trait::async_trait;
use nntmux_categorize::Categorizer;
use nntmux_namefix::release::Release;
use nntmux_namefix::update::{ReleaseSearch, ReleaseUpdater};
use nntmux_predb::filename::fixer_cleaner;
use nntmux_runtime::Ctx;
use tracing::warn;

use crate::auth;
use crate::db::row_json;
use crate::json::{J, Style};
use crate::reply::{Reply, json_error};
use crate::request::ApiRequest;
use crate::settings::Settings;

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

/// Refreshes a renamed release's search document. Failures record their own retry.
struct IndexRefresh {
    ctx: Ctx,
    settings: Arc<Settings>,
}

#[async_trait]
impl ReleaseSearch for IndexRefresh {
    async fn update_release(&self, release_id: i64) {
        match self.settings.search(&self.ctx) {
            Ok(client) => {
                client.update_release(&self.ctx.db, release_id).await;
            }
            Err(failure) => warn!(
                error = format!("{failure:#}"),
                "no search client for a rename"
            ),
        }
    }
}

/// `GET /api/inform/release`: a client reports the proper name for an obfuscated one.
pub async fn inform(ctx: &Ctx, settings: &Arc<Settings>, request: &ApiRequest) -> Result<Reply> {
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
    // `whereSearchname()->first()`. Cast, since the updater's reader takes signed integers.
    let found = sqlx::query(
        "SELECT CAST(id AS SIGNED) AS releases_id, guid, name, searchname, fromname,
                CAST(groups_id AS SIGNED) AS groups_id, CAST(categories_id AS SIGNED) AS categories_id,
                CAST(size AS SIGNED) AS relsize, CAST(predb_id AS SIGNED) AS predb_id,
                CAST(isrenamed AS SIGNED) AS isrenamed, CAST(nfostatus AS SIGNED) AS nfostatus,
                CAST(proc_nfo AS SIGNED) AS proc_nfo, CAST(proc_files AS SIGNED) AS proc_files,
                CAST(proc_par2 AS SIGNED) AS proc_par2, CAST(proc_uid AS SIGNED) AS proc_uid,
                CAST(proc_srr AS SIGNED) AS proc_srr, CAST(proc_hash16k AS SIGNED) AS proc_hash16k,
                CAST(proc_crc32 AS SIGNED) AS proc_crc32
         FROM releases WHERE searchname = ? LIMIT 1",
    )
    .bind(&original)
    .fetch_optional(&ctx.db)
    .await?;
    if let Some(found) = found {
        let found = Release::from_row(&found)?;
        let categorizer = Categorizer::load(&ctx.db).await?;
        let search = Arc::new(IndexRefresh {
            ctx: ctx.clone(),
            settings: settings.clone(),
        });
        let mut updater = ReleaseUpdater::new(ctx.db.clone(), categorizer, search, fixer_cleaner);
        updater
            .update_release(
                &found,
                &proper,
                "Release Inform API",
                true,
                "Filenames, ",
                true,
                0,
                None,
                None,
            )
            .await?;
    }
    Ok(Reply::message(200, "Release Information Added!"))
}

/// `iconv('CP437', 'UTF-8//IGNORE//TRANSLIT', ...)` for bytes above ASCII.
const CP437_HIGH: &str = "ÇüéâäàåçêëèïîìÄÅÉæÆôöòûùÿÖÜ¢£¥₧ƒáíóúñÑªº¿⌐¬½¼¡«»░▒▓│┤╡╢╖╕╣║╗╝╜╛┐└┴┬├─┼╞╟╚╔╩╦╠═╬╧╨╤╥╙╘╒╓╫╪┘┌█▄▌▐▀αßΓπΣσµτΦΘΩδ∞φε∩≡±≥≤⌠⌡÷≈°∙·√ⁿ²■\u{a0}";

pub fn cp437_to_utf8(bytes: &[u8]) -> String {
    let high: Vec<char> = CP437_HIGH.chars().collect();
    bytes
        .iter()
        .map(|&byte| {
            if byte < 0x80 {
                byte as char
            } else {
                high[usize::from(byte - 0x80)]
            }
        })
        .collect()
}

/// PHP's `nl2br`: `<br />` before each line break, treating CRLF and LFCR as one.
pub fn nl2br(text: &str) -> String {
    let mut out = String::with_capacity(text.len() + 64);
    let mut chars = text.chars().peekable();
    while let Some(ch) = chars.next() {
        match ch {
            '\n' | '\r' => {
                out.push_str("<br />");
                out.push(ch);
                let pair = if ch == '\n' { '\r' } else { '\n' };
                if chars.peek() == Some(&pair) {
                    out.push(pair);
                    chars.next();
                }
            }
            c => out.push(c),
        }
    }
    out
}

//! The newznab API at `/api/v1/api`, dispatched on `t`.

use std::sync::Arc;

use anyhow::Result;
use nntmux_runtime::Ctx;

use crate::auth::{self, User};
use crate::caps::{self, Server};
use crate::filename;
use crate::getnzb::{self, Errors, html_escape};
use crate::input::{Input, php_int};
use crate::misc::{cp437_to_utf8, nl2br};
use crate::params;
use crate::present_v1::{Params, api_output};
use crate::reply::{Reply, xml_error};
use crate::request::ApiRequest;
use crate::rows;
use crate::settings::Settings;
use crate::upload;
use crate::usage::{self, Statistics, rfc2822};

#[derive(Clone, Copy, Debug, PartialEq, Eq)]
enum Function {
    Search,
    Details,
    Get,
    Caps,
    Tv,
    Movie,
    Music,
    Book,
    Anime,
    Nfo,
    NzbAdd,
}

impl Function {
    fn parse(name: &str) -> Option<Self> {
        Some(match name {
            "d" | "details" => Self::Details,
            "g" | "get" => Self::Get,
            "s" | "search" => Self::Search,
            "c" | "caps" => Self::Caps,
            "tv" | "tvsearch" => Self::Tv,
            "m" | "movie" => Self::Movie,
            "music" | "audio" => Self::Music,
            "b" | "book" => Self::Book,
            "anime" => Self::Anime,
            "gn" | "n" | "nfo" | "info" => Self::Nfo,
            "nzbadd" => Self::NzbAdd,
            _ => return None,
        })
    }

    /// QUERY is safe by RFC 10008. `g` counts downloads and can empty a cart; `nzbAdd` writes.
    fn query_safe(self) -> bool {
        !matches!(self, Self::Get | Self::NzbAdd)
    }
}

/// The account values every v1 response reports.
pub struct Account {
    pub token: String,
    pub user: User,
    pub stats: Statistics,
    pub oldest_api: String,
    pub oldest_grab: String,
}

fn empty_error(parameter: &str) -> Reply {
    xml_error(
        201,
        &format!("Incorrect parameter ({parameter} must not be empty)"),
    )
}

/// `verifyEmptyParameter`: present but blank is an error, absent is fine.
fn require_filled(input: &Input, parameter: &str) -> Option<Reply> {
    (input.has(parameter) && !input.filled(parameter)).then(|| empty_error(parameter))
}

pub async fn api(ctx: &Ctx, settings: &Arc<Settings>, request: &ApiRequest) -> Result<Reply> {
    let input = &request.input;
    if !input.has("t") {
        return Ok(xml_error(200, "Missing parameter (t)"));
    }
    let name = input.string("t");
    let Some(function) = Function::parse(&name) else {
        return Ok(xml_error(202, &format!("No such function ({name})")));
    };
    if request.is_query_method() && !function.query_safe() {
        return Ok(xml_error(203, "Function not available via QUERY"));
    }

    let xml = input.str("o") != Some("json");
    if function == Function::Caps {
        return caps::v1(ctx, settings, request, xml).await;
    }

    // PHP's `empty` also refuses "0".
    if input.str("apikey").is_none_or(|key| key == "0") {
        return Ok(xml_error(200, "Missing parameter (apikey)"));
    }
    let token = input.string("apikey");
    let Some(user) = auth::resolve_verified(ctx, settings, &token).await? else {
        return Ok(xml_error(100, "Incorrect user credentials (wrong API key)"));
    };
    if user.disabled() {
        return Ok(xml_error(101, ""));
    }

    let stats = usage::statistics(ctx, settings, user.id).await?;
    if stats.api_count >= user.api_requests {
        let (used, max) = (stats.api_count, user.api_requests);
        return Ok(xml_error(
            500,
            &format!("Request limit reached ({used}/{max})"),
        ));
    }

    let account = Account {
        token,
        oldest_api: stats.api_time.map(rfc2822).unwrap_or_default(),
        oldest_grab: stats.grab_time.map(rfc2822).unwrap_or_default(),
        stats,
        user,
    };
    match function {
        Function::Search => search(ctx, settings, request, &account).await,
        Function::Tv => tv(ctx, settings, request, &account).await,
        Function::Movie => movie(ctx, settings, request, &account).await,
        Function::Music | Function::Book => media(ctx, settings, request, &account).await,
        Function::Anime => anime(ctx, settings, request, &account).await,
        Function::Get => get(ctx, settings, request, &account).await,
        Function::Details => details(ctx, settings, request, &account).await,
        Function::Nfo => nfo(ctx, settings, request, &account).await,
        Function::NzbAdd => nzb_add(ctx, settings, request, &account).await,
        Function::Caps => unreachable!("caps returned above"),
    }
}

async fn audit(
    ctx: &Ctx,
    settings: &Arc<Settings>,
    request: &ApiRequest,
    account: &Account,
) -> Result<()> {
    let entry = usage::audit_entry(request.laravel_path(), &request.input);
    usage::record(
        ctx,
        settings,
        account.user.id,
        &entry,
        request.client_ip().as_deref(),
    )
    .await
}

/// Shared request validation for the release-listing functions.
fn sort_and_age(input: &Input) -> Result<(i64, String), Reply> {
    let age = params::max_age(input).map_err(|text| xml_error(201, text))?;
    let sort = params::sort(input).map_err(|text| xml_error(201, text))?;
    Ok((age, sort))
}

fn not_ported() -> Reply {
    Reply::message(501, "Not ported yet")
}

async fn search(
    ctx: &Ctx,
    settings: &Arc<Settings>,
    request: &ApiRequest,
    account: &Account,
) -> Result<Reply> {
    let input = &request.input;
    if let Some(error) = require_filled(input, "q") {
        return Ok(error);
    }
    if let Err(error) = sort_and_age(input) {
        return Ok(error);
    }
    audit(ctx, settings, request, account).await?;
    Ok(not_ported())
}

async fn tv(
    ctx: &Ctx,
    settings: &Arc<Settings>,
    request: &ApiRequest,
    account: &Account,
) -> Result<Reply> {
    let input = &request.input;
    for parameter in [
        "q", "vid", "tvdbid", "traktid", "rid", "tvmazeid", "imdbid", "tmdbid", "season", "ep",
    ] {
        if let Some(error) = require_filled(input, parameter) {
            return Ok(error);
        }
    }
    if let Err(error) = sort_and_age(input) {
        return Ok(error);
    }
    audit(ctx, settings, request, account).await?;
    Ok(not_ported())
}

async fn movie(
    ctx: &Ctx,
    settings: &Arc<Settings>,
    request: &ApiRequest,
    account: &Account,
) -> Result<Reply> {
    let input = &request.input;
    for parameter in ["q", "imdbid", "tmdbid", "traktid"] {
        if let Some(error) = require_filled(input, parameter) {
            return Ok(error);
        }
    }
    if let Err(error) = sort_and_age(input) {
        return Ok(error);
    }
    audit(ctx, settings, request, account).await?;
    Ok(not_ported())
}

async fn media(
    ctx: &Ctx,
    settings: &Arc<Settings>,
    request: &ApiRequest,
    account: &Account,
) -> Result<Reply> {
    let input = &request.input;
    if let Some(error) = require_filled(input, "q") {
        return Ok(error);
    }
    if let Err(error) = sort_and_age(input) {
        return Ok(error);
    }
    audit(ctx, settings, request, account).await?;
    Ok(not_ported())
}

async fn anime(
    ctx: &Ctx,
    settings: &Arc<Settings>,
    request: &ApiRequest,
    account: &Account,
) -> Result<Reply> {
    let input = &request.input;
    let filled_int = |key: &str| {
        if input.filled(key) {
            php_int(input.str(key).unwrap_or_default())
        } else {
            -1
        }
    };
    if input.string("q").is_empty() && filled_int("anidbid") <= 0 && filled_int("anilistid") <= 0 {
        return Ok(xml_error(
            200,
            "Missing parameter (specify q, anidbid, or anilistid)",
        ));
    }
    if let Err(error) = sort_and_age(input) {
        return Ok(error);
    }
    audit(ctx, settings, request, account).await?;
    Ok(not_ported())
}

async fn get(
    ctx: &Ctx,
    settings: &Arc<Settings>,
    request: &ApiRequest,
    account: &Account,
) -> Result<Reply> {
    if let Some(error) = require_filled(&request.input, "g") {
        return Ok(error);
    }
    audit(ctx, settings, request, account).await?;
    // Unlike the v2 path, the suffix isn't stripped before this check.
    let id = request.input.string("id");
    if !getnzb::guid_exists(ctx, &id).await? {
        return Ok(xml_error(
            300,
            "No such item (the guid you provided has no release in our database)",
        ));
    }
    getnzb::download(ctx, request, &account.user, &id, Errors::Xml, false).await
}

/// `$params` for the release output.
fn output_params(input: &Input, account: &Account) -> Params {
    let flag = |key: &str| input.str(key).is_some_and(|value| php_int(value) == 1);
    Params {
        uid: account.user.id,
        token: account.token.clone(),
        extended: flag("extended"),
        delete_from_cart: flag("del"),
        include_enclosure: true,
        api_limit: account.user.api_requests,
        requests: account.stats.api_count,
        download_limit: account.user.download_requests,
        grabs: account.stats.grab_count,
        oldest_api: account.oldest_api.clone(),
        oldest_grab: account.oldest_grab.clone(),
    }
}

async fn details(
    ctx: &Ctx,
    settings: &Arc<Settings>,
    request: &ApiRequest,
    account: &Account,
) -> Result<Reply> {
    let input = &request.input;
    if !input.has("id") {
        return Ok(xml_error(
            200,
            "Missing parameter (guid is required for single release details)",
        ));
    }
    audit(ctx, settings, request, account).await?;
    let found = rows::by_guid_for_api(ctx, &input.string("id"), true).await?;
    let rows: Vec<rows::Row> = found.into_iter().collect();
    let server = Server::load(ctx, settings, request.base_url()).await?;
    let xml = input.str("o") != Some("json");
    Ok(api_output(
        &rows,
        &output_params(input, account),
        &server,
        params::offset(input),
        xml,
    ))
}

async fn nfo(
    ctx: &Ctx,
    settings: &Arc<Settings>,
    request: &ApiRequest,
    account: &Account,
) -> Result<Reply> {
    let input = &request.input;
    if !input.has("id") {
        return Ok(xml_error(
            200,
            "Missing parameter (id is required for retrieving an NFO)",
        ));
    }
    audit(ctx, settings, request, account).await?;
    let release: Option<(u32, String)> =
        sqlx::query_as("SELECT id, searchname FROM releases WHERE guid = ? LIMIT 1")
            .bind(input.string("id"))
            .fetch_optional(&ctx.db)
            .await?;
    let Some((id, searchname)) = release else {
        return Ok(xml_error(300, "Release does not exist."));
    };
    let nfo: Option<Option<Vec<u8>>> =
        sqlx::query_scalar("SELECT UNCOMPRESS(nfo) FROM release_nfos WHERE releases_id = ? AND nfo IS NOT NULL LIMIT 1")
            .bind(id)
            .fetch_optional(&ctx.db)
            .await?;
    let Some(Some(nfo)) = nfo else {
        return Ok(xml_error(
            300,
            "Release does not have an NFO file associated.",
        ));
    };
    if input.str("o") == Some("file") {
        let stem = format!("nfo-{id}");
        let clean = filename::sanitize(&searchname, &stem);
        let ascii = filename::ascii_fallback(&clean, &stem);
        return Ok(Reply::new(200, "application/octet-stream", nfo).header(
            "content-disposition",
            filename::attachment(&format!("{clean}.nfo"), &format!("{ascii}.nfo")),
        ));
    }
    Ok(Reply::new(
        200,
        "text/html; charset=utf-8",
        nl2br(&cp437_to_utf8(&nfo)),
    ))
}

async fn nzb_add(
    ctx: &Ctx,
    settings: &Arc<Settings>,
    request: &ApiRequest,
    account: &Account,
) -> Result<Reply> {
    if !account.user.can_post {
        return Ok(xml_error(102, "Insufficient privileges/not authorized"));
    }
    let legacy = request.has_field("file");
    let modern = request.has_field("nzb") || request.has_field("nfo");
    if legacy && modern {
        return Ok(xml_error(
            201,
            "Use either file or nzb/nfo upload fields, not both",
        ));
    }
    if !legacy && !modern {
        return Ok(xml_error(200, "Missing parameter (nzb file is required)"));
    }
    audit(ctx, settings, request, account).await?;

    let folder = settings.upload_folder.clone();
    let name = if modern {
        let Some(nzb) = request.file("nzb").cloned() else {
            return Ok(xml_error(200, "Missing parameter (nzb file is required)"));
        };
        let nfo = request.file("nfo").cloned();
        tokio::task::spawn_blocking(move || {
            upload::stage(folder.as_deref(), &nzb, nfo.as_ref()).map(|staged| staged.name)
        })
        .await?
    } else {
        let Some(file) = request.file("file").cloned() else {
            return Ok(xml_error(600, "Failed to load upload (no file)"));
        };
        tokio::task::spawn_blocking(move || upload::stage_legacy(folder.as_deref(), &file)).await?
    };
    match name {
        Err(failure) => Ok(xml_error(600, &failure.message)),
        Ok(name) => Ok(Reply::new(
            200,
            "text/xml; charset=utf-8",
            format!(
                "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<success id=\"0\" guid=\"\" categoryid=\"{}\" name=\"{}\" />\n",
                request.input.string("cat"),
                html_escape(&name),
            ),
        )),
    }
}

//! The JSON API at `/api/v2`.

use anyhow::Result;
use nntmux_runtime::Ctx;

use crate::auth::{self, User};
use crate::input::{Input, Value, is_numeric, php_int};
use crate::json::J;
use crate::params;
use crate::reply::{Reply, json_error};
use crate::request::ApiRequest;
use crate::settings::Settings;
use crate::usage::{self, Reservation, Statistics};

/// `resolveUser`: token checks, then the atomic quota reservation.
async fn resolve_user(
    ctx: &Ctx,
    settings: &Settings,
    request: &ApiRequest,
    resolved: Option<User>,
    enforce_limit: bool,
) -> Result<std::result::Result<(User, Option<Statistics>), Reply>> {
    let input = &request.input;
    if !input.filled("api_token") {
        return Ok(Err(json_error(200, "Missing parameter (api_token)")));
    }
    let user = match resolved {
        Some(user) => Some(user),
        None => auth::resolve(ctx, settings, &input.string("api_token")).await?,
    };
    let Some(user) = user.filter(|user| user.verified) else {
        return Ok(Err(json_error(100, "")));
    };
    if user.disabled() {
        return Ok(Err(json_error(101, "")));
    }
    if !enforce_limit {
        return Ok(Ok((user, None)));
    }
    let audit = usage::audit_entry(request.laravel_path(), input);
    match usage::reserve(ctx, settings, &user, &audit, request.client_ip().as_deref()).await? {
        Reservation::Busy => Ok(Err(json_error(500, "Too many concurrent requests, retry shortly"))),
        Reservation::Exhausted => Ok(Err(json_error(500, "Request limit reached"))),
        Reservation::Used(used) => {
            // The display statistics are cached; report the count enforcement just saw.
            let mut stats = usage::statistics(ctx, settings, user.id).await?;
            stats.api_count = used;
            Ok(Ok((user, Some(stats))))
        }
    }
}

macro_rules! authed {
    ($ctx:expr, $settings:expr, $request:expr, $resolved:expr) => {
        match resolve_user($ctx, $settings, $request, $resolved, true).await? {
            Ok(found) => found,
            Err(reply) => return Ok(reply),
        }
    };
}

fn is_non_negative_integer(value: &str) -> bool {
    let value = value.trim();
    (1..=9).contains(&value.len()) && value.bytes().all(|byte| byte.is_ascii_digit())
}

/// `parseLimit`: a positive integer, capped at the advertised maximum.
fn parse_limit(input: &Input, settings: &Settings) -> std::result::Result<i64, Reply> {
    if input.has("limit")
        && !input
            .str("limit")
            .is_some_and(|value| is_non_negative_integer(value) && php_int(value) >= 1)
    {
        return Err(Reply::v2_error(
            400,
            &format!(
                "Incorrect parameter (limit must be a positive integer, at most {} results are returned)",
                settings.limit_max()
            ),
        ));
    }
    Ok(params::limit(input, settings))
}

/// `resolvePaginationOffset`, up to the cursor decoding that arrives with search.
fn parse_offset(input: &Input) -> std::result::Result<i64, Reply> {
    if input.has("offset") && !input.str("offset").is_some_and(is_non_negative_integer) {
        return Err(Reply::v2_error(400, "Incorrect parameter (offset must be a non-negative integer)"));
    }
    let offset = params::offset(input);
    if input.has("cursor") {
        if offset > 0 {
            return Err(Reply::v2_error(400, "cursor cannot be combined with a nonzero offset"));
        }
        return Err(Reply::v2_error(400, "Invalid search cursor."));
    }
    Ok(offset)
}

/// `(int) $request->input($key, $default)`, where a present null casts to 0.
fn int_or(input: &Input, key: &str, default: i64) -> i64 {
    match input.get(key) {
        None => default,
        Some(Value::Str(text)) => php_int(text),
        Some(Value::Null) => 0,
        Some(_) => 1,
    }
}

fn imdb_id_is_valid(value: &str) -> bool {
    let digits: String = value.trim().chars().filter(char::is_ascii_digit).collect();
    !digits.is_empty() && digits.trim_start_matches('0') != ""
}

fn not_ported() -> Reply {
    Reply::message(501, "Not ported yet")
}

pub enum Media {
    Audio,
    Books,
}

fn check_age_sort(input: &Input) -> std::result::Result<(), Reply> {
    params::max_age(input).map_err(|text| Reply::v2_error(400, text))?;
    params::sort(input).map_err(|text| Reply::v2_error(400, text))?;
    Ok(())
}

pub async fn movies(ctx: &Ctx, settings: &Settings, request: &ApiRequest, resolved: Option<User>) -> Result<Reply> {
    let _account = authed!(ctx, settings, request, resolved);
    let input = &request.input;
    let imdb = input.string("imdbid").replace("tt", "");
    if input.string("id").is_empty()
        && !imdb_id_is_valid(&imdb)
        && int_or(input, "tmdbid", -1) <= 0
        && int_or(input, "traktid", -1) <= 0
    {
        return Ok(Reply::v2_error(400, "Specify id (query), imdbid, tmdbid, or traktid"));
    }
    if let Err(reply) = parse_limit(input, settings).and_then(|_| parse_offset(input)).and_then(|_| check_age_sort(input)) {
        return Ok(reply);
    }
    Ok(not_ported())
}

pub async fn music_or_books(
    ctx: &Ctx,
    settings: &Settings,
    request: &ApiRequest,
    resolved: Option<User>,
    _media: Media,
) -> Result<Reply> {
    let _account = authed!(ctx, settings, request, resolved);
    let input = &request.input;
    if input.has("id") && !input.filled("id") {
        return Ok(Reply::v2_error(400, "Incorrect parameter (id must not be empty)"));
    }
    if let Err(reply) = parse_limit(input, settings).and_then(|_| parse_offset(input)).and_then(|_| check_age_sort(input)) {
        return Ok(reply);
    }
    Ok(not_ported())
}

pub async fn anime(ctx: &Ctx, settings: &Settings, request: &ApiRequest, resolved: Option<User>) -> Result<Reply> {
    let _account = authed!(ctx, settings, request, resolved);
    let input = &request.input;
    if input.string("id").is_empty() && int_or(input, "anidbid", -1) <= 0 && int_or(input, "anilistid", -1) <= 0 {
        return Ok(Reply::v2_error(400, "Specify id (query), anidbid, or anilistid"));
    }
    if let Err(reply) = parse_limit(input, settings).and_then(|_| parse_offset(input)).and_then(|_| check_age_sort(input)) {
        return Ok(reply);
    }
    Ok(not_ported())
}

pub async fn search(ctx: &Ctx, settings: &Settings, request: &ApiRequest, resolved: Option<User>) -> Result<Reply> {
    let _account = authed!(ctx, settings, request, resolved);
    let input = &request.input;
    if let Err(reply) = check_age_sort(input).and_then(|()| parse_limit(input, settings)).and_then(|_| parse_offset(input)) {
        return Ok(reply);
    }
    Ok(not_ported())
}

pub async fn tv(ctx: &Ctx, settings: &Settings, request: &ApiRequest, resolved: Option<User>) -> Result<Reply> {
    let _account = authed!(ctx, settings, request, resolved);
    let input = &request.input;
    let wanted = ["id", "vid", "tvdbid", "traktid", "rid", "tvmazeid", "imdbid", "tmdbid"];
    if !wanted.iter().any(|key| input.filled(key)) {
        return Ok(Reply::v2_error(400, "Specify id (query), vid, tvdbid, traktid, rid, tvmazeid, imdbid, or tmdbid"));
    }
    if let Err(reply) = check_age_sort(input).and_then(|()| parse_limit(input, settings)).and_then(|_| parse_offset(input)) {
        return Ok(reply);
    }
    Ok(not_ported())
}

pub async fn details(ctx: &Ctx, settings: &Settings, request: &ApiRequest, resolved: Option<User>) -> Result<Reply> {
    let _account = authed!(ctx, settings, request, resolved);
    if !request.input.has("id") {
        return Ok(Reply::v2_error(400, "Missing parameter (guid is required for single release details)"));
    }
    Ok(not_ported())
}

pub async fn get_nzb(ctx: &Ctx, settings: &Settings, request: &ApiRequest, resolved: Option<User>) -> Result<Reply> {
    let _account = authed!(ctx, settings, request, resolved);
    if request.input.string("id").is_empty() {
        return Ok(json_error(200, "Missing parameter (id)"));
    }
    Ok(not_ported())
}

pub async fn nzb_add(ctx: &Ctx, settings: &Settings, request: &ApiRequest, resolved: Option<User>) -> Result<Reply> {
    let (user, _) = match resolve_user(ctx, settings, request, resolved, false).await? {
        Ok(found) => found,
        Err(reply) => return Ok(reply),
    };
    if !user.can_post {
        return Ok(Reply::v2_error(403, "Insufficient privileges/not authorized"));
    }
    let _ = (J::Null, is_numeric("1"));
    Ok(Reply::v2_error(400, "Missing parameter (nzb file is required)"))
}

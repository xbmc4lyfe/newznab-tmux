//! The JSON API at `/api/v2`.

use std::sync::Arc;

use anyhow::Result;
use nntmux_runtime::Ctx;
use nntmux_search::{SearchCursor, SearchError, SearchPage, SecondaryIndex};
use serde_json::json;
use sha2::{Digest, Sha256};

use crate::auth::{self, User};
use crate::getnzb::{self, Errors};
use crate::input::{Input, Value, canonicalize, php_int};
use crate::json::{J, Style};
use crate::params;
use crate::present_v2;
use crate::release_cache::remember;
use crate::reply::{Reply, json_error};
use crate::request::ApiRequest;
use crate::rows;
use crate::rows::Row;
use crate::search::{Categories, Filters, Found, Searcher, TvIds};
use crate::settings::Settings;
use crate::upload;
use crate::usage::{self, Reservation, Statistics};

/// `resolveUser`: token checks, then the atomic quota reservation.
async fn resolve_user(
    ctx: &Ctx,
    settings: &Arc<Settings>,
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
        Reservation::Busy => Ok(Err(json_error(
            500,
            "Too many concurrent requests, retry shortly",
        ))),
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
fn parse_limit(input: &Input, settings: &Arc<Settings>) -> std::result::Result<i64, Reply> {
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

/// Paging state: a request carrying `cursor`, even empty, gets a `pagination` object back.
struct Paging {
    enabled: bool,
    offset: i64,
    limit: i64,
    query_hash: String,
    cursor: Option<SearchCursor>,
}

/// Binds cursors to the endpoint and the effective filters, so GET and QUERY with the same filters
/// share cursors and any other filter set or sort is refused.
fn cursor_hash(request: &ApiRequest) -> String {
    let filters: Vec<_> = request
        .input
        .all()
        .into_iter()
        .filter(|(key, _)| !matches!(key.as_str(), "api_token" | "apikey" | "cursor" | "offset"))
        .collect();
    let text = format!("{}|{:?}", request.laravel_path(), canonicalize(&filters));
    hex::encode(Sha256::digest(text.as_bytes()))
}

/// `resolvePaginationOffset`.
fn parse_offset(
    ctx: &Ctx,
    settings: &Arc<Settings>,
    request: &ApiRequest,
    limit: i64,
) -> std::result::Result<Paging, Reply> {
    let input = &request.input;
    if input.has("offset") && !input.str("offset").is_some_and(is_non_negative_integer) {
        return Err(Reply::v2_error(
            400,
            "Incorrect parameter (offset must be a non-negative integer)",
        ));
    }
    let mut offset = params::offset(input);
    if !input.has("cursor") {
        return Ok(Paging {
            enabled: false,
            offset,
            limit,
            query_hash: String::new(),
            cursor: None,
        });
    }
    if offset > 0 {
        return Err(Reply::v2_error(
            400,
            "cursor cannot be combined with a nonzero offset",
        ));
    }
    let query_hash = cursor_hash(request);
    let token = input.string("cursor");
    let token = token.trim();
    let mut cursor = None;
    if !token.is_empty() {
        let codec = settings
            .search(ctx)
            .and_then(|client| settings.cursors(client))
            .map_err(|_| Reply::v2_error(400, "Invalid search cursor."))?;
        let decoded = codec
            .decode(token, &query_hash)
            .map_err(|failure| match failure {
                SearchError::CursorMismatch => Reply::v2_error(
                    400,
                    "Search cursor does not match this query or index generation.",
                ),
                _ => Reply::v2_error(400, "Invalid search cursor."),
            })?;
        offset = i64::try_from(decoded.offset).unwrap_or(0);
        cursor = Some(decoded);
    }
    Ok(Paging {
        enabled: true,
        offset,
        limit,
        query_hash,
        cursor,
    })
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
    !digits.is_empty() && !digits.trim_start_matches('0').is_empty()
}

pub enum Media {
    Audio,
    Books,
}

fn check_age(input: &Input) -> std::result::Result<i64, Reply> {
    params::max_age(input).map_err(|text| Reply::v2_error(400, text))
}

fn check_sort(input: &Input) -> std::result::Result<String, Reply> {
    params::sort(input).map_err(|text| Reply::v2_error(400, text))
}

macro_rules! ok_or_reply {
    ($value:expr) => {
        match $value {
            Ok(value) => value,
            Err(reply) => return Ok(reply),
        }
    };
}

#[allow(clippy::too_many_arguments)]
async fn filters(
    ctx: &Ctx,
    settings: &Arc<Settings>,
    request: &ApiRequest,
    user: &User,
    paging: &Paging,
    age: i64,
    sort: String,
    group: Option<String>,
) -> Result<Filters> {
    let input = &request.input;
    Ok(Filters {
        categories: params::categories(ctx, input).await?,
        excluded: auth::category_exclusions(ctx, settings, user.id).await?,
        max_age: age,
        min_size: params::minimum_size(input),
        group,
        offset: paging.offset,
        limit: paging.limit,
        sort,
    })
}

fn describe(filters: &Filters, request: &ApiRequest) -> serde_json::Value {
    let mut categories = filters.categories.0.clone();
    categories.sort();
    categories.dedup();
    let mut excluded = filters.excluded.clone();
    excluded.sort_unstable();
    serde_json::json!({
        "categories": categories, "excluded": excluded, "max_age": filters.max_age, "min_size": filters.min_size,
        "group": filters.group, "offset": filters.offset, "limit": filters.limit, "sort": filters.sort,
        "cursor": request.input.str("cursor"),
    })
}

/// `buildSearchResponse`.
async fn search_reply(
    ctx: &Ctx,
    settings: &Arc<Settings>,
    request: &ApiRequest,
    user: &User,
    stats: Option<Statistics>,
    paging: &Paging,
    mut rows: Vec<Row>,
) -> Result<Reply> {
    rows::attach_partial_counts(ctx, &mut rows).await;
    let mut pagination = None;
    if paging.enabled {
        let total = rows.first().map_or(0, |row| row.int("_totalrows"));
        let shown = i64::try_from(rows.len()).unwrap_or(i64::MAX);
        let next_offset = paging.offset + shown;
        let has_more = match rows.first().and_then(|row| row.get("_search_has_more")) {
            Some(J::Bool(flag)) => *flag,
            _ => shown == paging.limit && next_offset < total,
        };
        let client = settings.search(ctx)?;
        let codec = settings.cursors(client)?;
        let next = codec.next(
            &SearchPage {
                ids: vec![0; rows.len()],
                total: u64::try_from(total).unwrap_or(0),
                has_more,
                ..SearchPage::default()
            },
            u64::try_from(paging.offset).unwrap_or(0),
            &paging.query_hash,
        );
        pagination = Some((next, has_more));
    }
    let stats = stats.unwrap_or_default();
    Ok(present_v2::search(
        &rows,
        user,
        &stats,
        &request.base_url(),
        pagination,
    ))
}

pub async fn movies(
    ctx: &Ctx,
    settings: &Arc<Settings>,
    request: &ApiRequest,
    resolved: Option<User>,
) -> Result<Reply> {
    let (user, stats) = authed!(ctx, settings, request, resolved);
    let input = &request.input;
    let imdb = input.string("imdbid").replace("tt", "");
    let tmdb = int_or(input, "tmdbid", -1);
    let trakt = int_or(input, "traktid", -1);
    let name = input.string("id");
    if name.is_empty() && !imdb_id_is_valid(&imdb) && tmdb <= 0 && trakt <= 0 {
        return Ok(Reply::v2_error(
            400,
            "Specify id (query), imdbid, tmdbid, or traktid",
        ));
    }
    let limit = ok_or_reply!(parse_limit(input, settings));
    let paging = ok_or_reply!(parse_offset(ctx, settings, request, limit));
    let age = ok_or_reply!(check_age(input));
    let sort = ok_or_reply!(check_sort(input));
    let filters = filters(ctx, settings, request, &user, &paging, age, sort, None).await?;
    let client = settings.search(ctx)?;
    let searcher = Searcher::new(ctx, client).await?;
    let key = json!({"imdb": imdb, "tmdb": tmdb, "trakt": trakt, "id": name, "filters": describe(&filters, request)}).to_string();
    let rows = remember(ctx, settings, "v2", "movie", &key, || {
        searcher.movies(&imdb, tmdb, trakt, &name, &filters)
    })
    .await?;
    search_reply(ctx, settings, request, &user, stats, &paging, rows).await
}

pub async fn music_or_books(
    ctx: &Ctx,
    settings: &Arc<Settings>,
    request: &ApiRequest,
    resolved: Option<User>,
    media: Media,
) -> Result<Reply> {
    let (user, stats) = authed!(ctx, settings, request, resolved);
    let input = &request.input;
    if input.has("id") && !input.filled("id") {
        return Ok(Reply::v2_error(
            400,
            "Incorrect parameter (id must not be empty)",
        ));
    }
    let limit = ok_or_reply!(parse_limit(input, settings));
    let paging = ok_or_reply!(parse_offset(ctx, settings, request, limit));
    let age = ok_or_reply!(check_age(input));
    let sort = ok_or_reply!(check_sort(input));
    let group = params::group(input);
    let mut filters = filters(ctx, settings, request, &user, &paging, age, sort, group).await?;
    let (scope, root, index, column) = match media {
        Media::Audio => ("audio", 3000, SecondaryIndex::Music, "musicinfo_id"),
        Media::Books => ("books", 7000, SecondaryIndex::Books, "bookinfo_id"),
    };
    let text = input.string("id");
    if text.is_empty() && filters.categories.is_unfiltered() {
        filters.categories = Categories::ids(&[root]);
    }
    let client = settings.search(ctx)?;
    let searcher = Searcher::new(ctx, client).await?;
    let key = json!({"id": text, "filters": describe(&filters, request)}).to_string();
    let rows = remember(ctx, settings, "v2", scope, &key, || async {
        if text.is_empty() {
            searcher.browse(&filters).await
        } else {
            searcher.by_metadata(index, column, &text, &filters).await
        }
    })
    .await?;
    search_reply(ctx, settings, request, &user, stats, &paging, rows).await
}

pub async fn anime(
    ctx: &Ctx,
    settings: &Arc<Settings>,
    request: &ApiRequest,
    resolved: Option<User>,
) -> Result<Reply> {
    let (user, stats) = authed!(ctx, settings, request, resolved);
    let input = &request.input;
    let name = input.string("id");
    let anidb = int_or(input, "anidbid", -1);
    let anilist = int_or(input, "anilistid", -1);
    if name.is_empty() && anidb <= 0 && anilist <= 0 {
        return Ok(Reply::v2_error(
            400,
            "Specify id (query), anidbid, or anilistid",
        ));
    }
    let limit = ok_or_reply!(parse_limit(input, settings));
    let paging = ok_or_reply!(parse_offset(ctx, settings, request, limit));
    let age = ok_or_reply!(check_age(input));
    let sort = ok_or_reply!(check_sort(input));
    let filters = filters(ctx, settings, request, &user, &paging, age, sort, None).await?;
    let client = settings.search(ctx)?;
    let searcher = Searcher::new(ctx, client).await?;
    let key = json!({"id": name, "anidb": anidb, "anilist": anilist, "filters": describe(&filters, request)}).to_string();
    let rows = remember(ctx, settings, "v2", "anime", &key, || {
        searcher.anime(anidb, anilist, &name, &filters)
    })
    .await?;
    search_reply(ctx, settings, request, &user, stats, &paging, rows).await
}

pub async fn search(
    ctx: &Ctx,
    settings: &Arc<Settings>,
    request: &ApiRequest,
    resolved: Option<User>,
) -> Result<Reply> {
    let (user, stats) = authed!(ctx, settings, request, resolved);
    let input = &request.input;
    let age = ok_or_reply!(check_age(input));
    let sort = ok_or_reply!(check_sort(input));
    let group = params::group(input);
    let limit = ok_or_reply!(parse_limit(input, settings));
    let paging = ok_or_reply!(parse_offset(ctx, settings, request, limit));
    let filters = filters(ctx, settings, request, &user, &paging, age, sort, group).await?;
    let client = settings.search(ctx)?;
    let searcher = Searcher::new(ctx, client).await?;
    let text = input.str("id").map(str::to_owned);
    let cursor = paging.cursor.clone();
    let key = json!({"id": text, "filters": describe(&filters, request)}).to_string();
    let rows = remember(ctx, settings, "v2", "search", &key, || async {
        if input.has("id") {
            searcher.api_search(text.as_deref(), &filters, cursor).await
        } else {
            searcher.browse(&filters).await
        }
    })
    .await?;
    search_reply(ctx, settings, request, &user, stats, &paging, rows).await
}

pub async fn tv(
    ctx: &Ctx,
    settings: &Arc<Settings>,
    request: &ApiRequest,
    resolved: Option<User>,
) -> Result<Reply> {
    let (user, stats) = authed!(ctx, settings, request, resolved);
    let input = &request.input;
    let wanted = [
        "id", "vid", "tvdbid", "traktid", "rid", "tvmazeid", "imdbid", "tmdbid",
    ];
    if !wanted.iter().any(|key| input.filled(key)) {
        return Ok(Reply::v2_error(
            400,
            "Specify id (query), vid, tvdbid, traktid, rid, tvmazeid, imdbid, or tmdbid",
        ));
    }
    let age = ok_or_reply!(check_age(input));
    let sort = ok_or_reply!(check_sort(input));
    let ids = TvIds {
        id: input.string("vid"),
        tvdb: input.string("tvdbid"),
        trakt: input.string("traktid"),
        tvrage: input.string("rid"),
        tvmaze: input.string("tvmazeid"),
        imdb: input.string("imdbid"),
        tmdb: input.string("tmdbid"),
    };
    let series = input.string("season");
    let episode = input.string("ep");
    let air = {
        let year = series.len() == 4
            && (series.starts_with("19") || series.starts_with("20"))
            && series.bytes().all(|byte| byte.is_ascii_digit());
        if year && episode.contains('/') {
            format!("{series}-{episode}").replace('/', "-")
        } else {
            String::new()
        }
    };
    let limit = ok_or_reply!(parse_limit(input, settings));
    let paging = ok_or_reply!(parse_offset(ctx, settings, request, limit));
    let filters = filters(ctx, settings, request, &user, &paging, age, sort, None).await?;
    let name = input.string("id");
    let client = settings.search(ctx)?;
    let searcher = Searcher::new(ctx, client).await?;
    let key = json!({
        "ids": [ids.id, ids.tvdb, ids.trakt, ids.tvrage, ids.tvmaze, ids.imdb, ids.tmdb],
        "season": series, "episode": episode, "air": air, "id": name, "filters": describe(&filters, request),
    })
    .to_string();
    let rows = remember(ctx, settings, "v2", "tv", &key, || {
        searcher.api_tv(&ids, &series, &episode, &air, &name, &filters)
    })
    .await?;
    search_reply(ctx, settings, request, &user, stats, &paging, rows).await
}

pub async fn details(
    ctx: &Ctx,
    settings: &Arc<Settings>,
    request: &ApiRequest,
    resolved: Option<User>,
) -> Result<Reply> {
    let (user, _) = authed!(ctx, settings, request, resolved);
    if !request.input.has("id") {
        return Ok(Reply::v2_error(
            400,
            "Missing parameter (guid is required for single release details)",
        ));
    }
    let guid = request.input.string("id");
    let key = json!({"guid": guid}).to_string();
    let rows = remember(ctx, settings, "v2", "details", &key, || async {
        Ok(Found::from_rows(
            rows::by_guid_for_api(ctx, &guid, false)
                .await?
                .into_iter()
                .collect(),
        ))
    })
    .await?;
    match rows.first() {
        Some(row) => Ok(present_v2::details(row, &user, &request.base_url())),
        None => Ok(Reply::v2_error(404, "No such item")),
    }
}

pub async fn get_nzb(
    ctx: &Ctx,
    settings: &Arc<Settings>,
    request: &ApiRequest,
    resolved: Option<User>,
) -> Result<Reply> {
    let (user, _) = authed!(ctx, settings, request, resolved);
    let id = getnzb::scalar_id(&request.input);
    if id.is_empty() {
        return Ok(json_error(200, "Missing parameter (id)"));
    }
    if !getnzb::guid_exists(ctx, &getnzb::strip_suffix(&id)).await? {
        return Ok(Reply::v2_error(
            404,
            "No such item (the guid you provided has no release in our database)",
        ));
    }
    getnzb::download(ctx, settings, request, &user, &id, Errors::Json, false).await
}

pub async fn nzb_add(
    ctx: &Ctx,
    settings: &Arc<Settings>,
    request: &ApiRequest,
    resolved: Option<User>,
) -> Result<Reply> {
    let (user, _) = match resolve_user(ctx, settings, request, resolved, false).await? {
        Ok(found) => found,
        Err(reply) => return Ok(reply),
    };
    if !user.can_post {
        return Ok(Reply::v2_error(
            403,
            "Insufficient privileges/not authorized",
        ));
    }
    let Some(nzb) = request.file("nzb").cloned() else {
        return Ok(Reply::v2_error(
            400,
            "Missing parameter (nzb file is required)",
        ));
    };
    let nfo = request.file("nfo").cloned();
    let folder = settings.upload_folder.clone();
    let staged =
        tokio::task::spawn_blocking(move || upload::stage(folder.as_deref(), &nzb, nfo.as_ref()))
            .await?;
    let staged = match staged {
        Ok(staged) => staged,
        Err(failure) => return Ok(Reply::v2_error(failure.status, &failure.message)),
    };
    let described = |filename: String, kind: &str| {
        J::obj([("filename", J::Str(filename)), ("type", J::str(kind))])
    };
    let body = J::obj([
        ("success", J::Bool(true)),
        ("status", J::str("staged")),
        ("name", J::Str(staged.name)),
        ("category", request.input.str("cat").map_or(J::Null, J::str)),
        (
            "files",
            J::obj([
                ("nzb", described(staged.nzb_filename, "nzb")),
                (
                    "nfo",
                    staged
                        .nfo_filename
                        .map_or(J::Null, |name| described(name, "nfo")),
                ),
            ]),
        ),
    ]);
    Ok(Reply::json(201, &body, Style::Unescaped))
}

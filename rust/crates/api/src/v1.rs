//! The newznab API at `/api/v1/api`, dispatched on `t`.

use std::sync::Arc;

use anyhow::Result;
use nntmux_runtime::Ctx;
use nntmux_search::SecondaryIndex;
use serde_json::json;

use crate::auth::{self, User};
use crate::caps::{self, Server};
use crate::filename;
use crate::getnzb::{self, Errors, html_escape};
use crate::input::{Input, php_int};
use crate::json::J;
use crate::misc::{cp437_to_utf8, nl2br};
use crate::params;
use crate::present_v1::{Params, api_output};
use crate::release_cache::remember;
use crate::reply::{Reply, xml_error};
use crate::request::ApiRequest;
use crate::rows;
use crate::rss;
use crate::search::{Categories, Filters, Found, MOVIES_GROUP, Searcher, TV_GROUP, TvIds};
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
        Function::Music | Function::Book => media(ctx, settings, request, &account, function).await,
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

/// v1's minsize: any positive cast, where v2 wants a number.
fn minimum_size(input: &Input) -> i64 {
    input
        .str("minsize")
        .map_or(0, |value| php_int(value).max(0))
}

async fn filters(
    ctx: &Ctx,
    settings: &Arc<Settings>,
    input: &Input,
    account: &Account,
    age: i64,
    sort: String,
    group: Option<String>,
) -> Result<Filters> {
    Ok(Filters {
        categories: params::categories(ctx, input).await?,
        excluded: auth::category_exclusions(ctx, settings, account.user.id).await?,
        max_age: age,
        min_size: minimum_size(input),
        group,
        offset: params::offset(input),
        limit: params::limit(input, settings),
        sort,
    })
}

fn describe(filters: &Filters) -> serde_json::Value {
    let mut categories = filters.categories.0.clone();
    categories.sort();
    categories.dedup();
    let mut excluded = filters.excluded.clone();
    excluded.sort_unstable();
    serde_json::json!({
        "categories": categories, "excluded": excluded, "max_age": filters.max_age, "min_size": filters.min_size,
        "group": filters.group, "offset": filters.offset, "limit": filters.limit, "sort": filters.sort,
    })
}

async fn respond(
    ctx: &Ctx,
    settings: &Arc<Settings>,
    request: &ApiRequest,
    account: &Account,
    mut rows: Vec<rows::Row>,
) -> Result<Reply> {
    rows::attach_partial_counts(ctx, &mut rows).await;
    let input = &request.input;
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
    let (age, sort) = match sort_and_age(input) {
        Ok(found) => found,
        Err(error) => return Ok(error),
    };
    let group = params::group(input);
    audit(ctx, settings, request, account).await?;
    let filters = filters(ctx, settings, input, account, age, sort, group).await?;
    let client = settings.search(ctx)?;
    let searcher = Searcher::new(ctx, client).await?;
    let text = input.str("q").map(str::to_owned);
    let key = json!({"q": text, "filters": describe(&filters)}).to_string();
    let rows = remember(ctx, settings, "v1", "search", &key, || async {
        if input.has("q") {
            searcher.api_search(text.as_deref(), &filters, None).await
        } else {
            searcher.browse(&filters).await
        }
    })
    .await?;
    respond(ctx, settings, request, account, rows).await
}

fn optional_string(input: &Input, key: &str) -> String {
    input.string(key)
}

fn air_date(series: &str, episode: &str) -> String {
    let year = series.len() == 4
        && (series.starts_with("19") || series.starts_with("20"))
        && series.bytes().all(|byte| byte.is_ascii_digit());
    if year && episode.contains('/') {
        format!("{series}-{episode}").replace('/', "-")
    } else {
        String::new()
    }
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
    let (age, sort) = match sort_and_age(input) {
        Ok(found) => found,
        Err(error) => return Ok(error),
    };
    audit(ctx, settings, request, account).await?;
    let mut filters = filters(ctx, settings, input, account, age, sort, None).await?;
    let client = settings.search(ctx)?;
    let searcher = Searcher::new(ctx, client).await?;
    let wanted = [
        "q", "vid", "tvdbid", "traktid", "rid", "tvmazeid", "imdbid", "tmdbid",
    ];
    if !wanted.iter().any(|key| input.filled(key)) {
        if filters.categories.is_unfiltered() {
            filters.categories = Categories::ids(&TV_GROUP);
        }
        let key = json!({"has_search": false, "filters": describe(&filters)}).to_string();
        let rows = remember(ctx, settings, "v1", "tv", &key, || {
            searcher.browse(&filters)
        })
        .await?;
        return respond(ctx, settings, request, account, rows).await;
    }
    let or_zero = |name: &str| {
        input
            .str(name)
            .map_or_else(|| "0".to_owned(), str::to_owned)
    };
    let ids = TvIds {
        id: or_zero("vid"),
        tvdb: or_zero("tvdbid"),
        trakt: or_zero("traktid"),
        tvrage: or_zero("rid"),
        tvmaze: or_zero("tvmazeid"),
        imdb: input.string("imdbid").replace("tt", ""),
        tmdb: or_zero("tmdbid"),
    };
    let series = optional_string(input, "season");
    let episode = optional_string(input, "ep");
    let air = air_date(&series, &episode);
    let name = optional_string(input, "q");
    let key = json!({
        "has_search": true, "ids": [ids.id, ids.tvdb, ids.trakt, ids.tvrage, ids.tvmaze, ids.imdb, ids.tmdb],
        "season": series, "episode": episode, "air": air, "q": name, "filters": describe(&filters),
    })
    .to_string();
    let rows = remember(ctx, settings, "v1", "tv", &key, || {
        searcher.tv(&ids, &series, &episode, &air, &name, &filters)
    })
    .await?;
    respond(ctx, settings, request, account, rows).await
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
    let (age, sort) = match sort_and_age(input) {
        Ok(found) => found,
        Err(error) => return Ok(error),
    };
    audit(ctx, settings, request, account).await?;
    let mut filters = filters(ctx, settings, input, account, age, sort, None).await?;
    let client = settings.search(ctx)?;
    let searcher = Searcher::new(ctx, client).await?;
    if !["q", "imdbid", "tmdbid", "traktid"]
        .iter()
        .any(|key| input.filled(key))
    {
        if filters.categories.is_unfiltered() {
            filters.categories = Categories::ids(&MOVIES_GROUP);
        }
        let key = json!({"has_search": false, "filters": describe(&filters)}).to_string();
        let rows = remember(ctx, settings, "v1", "movie", &key, || {
            searcher.browse(&filters)
        })
        .await?;
        return respond(ctx, settings, request, account, rows).await;
    }
    let imdb = input.string("imdbid").replace("tt", "");
    let number = |name: &str| {
        if input.filled(name) {
            php_int(&input.string(name))
        } else {
            -1
        }
    };
    let (tmdb, trakt) = (number("tmdbid"), number("traktid"));
    let name = optional_string(input, "q");
    let key = json!({
        "has_search": true, "imdb": imdb, "tmdb": tmdb, "trakt": trakt, "q": name, "filters": describe(&filters),
    })
    .to_string();
    let mut rows = remember(ctx, settings, "v1", "movie", &key, || {
        searcher.movies(&imdb, tmdb, trakt, &name, &filters)
    })
    .await?;
    for row in &mut rows {
        if row.get("id").is_some() {
            let id = row.text("imdbid");
            if !id.is_empty() && id != "0" {
                let file =
                    rss::resolve_cover(&settings.cover_roots, "movies", &format!("{id}-cover"))
                        .unwrap_or_else(|| "no-cover.jpg".to_owned());
                row.set("coverurl", J::Str(format!("movies/{file}")));
            }
        }
    }
    respond(ctx, settings, request, account, rows).await
}

async fn media(
    ctx: &Ctx,
    settings: &Arc<Settings>,
    request: &ApiRequest,
    account: &Account,
    function: Function,
) -> Result<Reply> {
    let input = &request.input;
    if input.has("q") && !input.filled("q") {
        return Ok(empty_error("q"));
    }
    let (age, sort) = match sort_and_age(input) {
        Ok(found) => found,
        Err(error) => return Ok(error),
    };
    let group = params::group(input);
    audit(ctx, settings, request, account).await?;
    let mut filters = filters(ctx, settings, input, account, age, sort, group).await?;
    let client = settings.search(ctx)?;
    let searcher = Searcher::new(ctx, client).await?;
    let text = input.string("q");
    let (scope, root, index, column) = if function == Function::Music {
        ("music", 3000, SecondaryIndex::Music, "musicinfo_id")
    } else {
        ("book", 7000, SecondaryIndex::Books, "bookinfo_id")
    };
    if text.is_empty() && filters.categories.is_unfiltered() {
        filters.categories = Categories::ids(&[root]);
    }
    let key = json!({"q": text, "filters": describe(&filters)}).to_string();
    let rows = remember(ctx, settings, "v1", scope, &key, || async {
        if text.is_empty() {
            searcher.browse(&filters).await
        } else {
            searcher.by_metadata(index, column, &text, &filters).await
        }
    })
    .await?;
    respond(ctx, settings, request, account, rows).await
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
    let (anidb, anilist) = (filled_int("anidbid"), filled_int("anilistid"));
    let name = input.string("q");
    if name.is_empty() && anidb <= 0 && anilist <= 0 {
        return Ok(xml_error(
            200,
            "Missing parameter (specify q, anidbid, or anilistid)",
        ));
    }
    let (age, sort) = match sort_and_age(input) {
        Ok(found) => found,
        Err(error) => return Ok(error),
    };
    audit(ctx, settings, request, account).await?;
    let mut filters = filters(ctx, settings, input, account, age, sort, None).await?;
    // v1 never passed its minsize here.
    filters.min_size = 0;
    let client = settings.search(ctx)?;
    let searcher = Searcher::new(ctx, client).await?;
    let key = json!({"q": name, "anidb": anidb, "anilist": anilist, "filters": describe(&filters)})
        .to_string();
    let rows = remember(ctx, settings, "v1", "anime", &key, || {
        searcher.anime(anidb, anilist, &name, &filters)
    })
    .await?;
    respond(ctx, settings, request, account, rows).await
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
    getnzb::download(
        ctx,
        settings,
        request,
        &account.user,
        &id,
        Errors::Xml,
        false,
    )
    .await
}

/// `$params` for the release output.
fn output_params(input: &Input, account: &Account) -> Params {
    let flag = |key: &str| input.str(key).is_some_and(|value| php_int(value) == 1);
    Params {
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
    let guid = input.string("id");
    let key = json!({"guid": guid}).to_string();
    let rows = remember(ctx, settings, "v1", "details", &key, || async {
        Ok(Found::from_rows(
            rows::by_guid_for_api(ctx, &guid, true)
                .await?
                .into_iter()
                .collect(),
        ))
    })
    .await?;
    respond(ctx, settings, request, account, rows).await
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

//! What wraps every endpoint: method rules, CORS, rate limits and response decoration, in the
//! order Laravel's middleware stack applied them.

use std::future::Future;
use std::pin::Pin;
use std::sync::Arc;

use axum::extract::Request;
use axum::handler::Handler;
use axum::response::Response;
use nntmux_runtime::Ctx;
use sha1::{Digest, Sha1};
use tracing::error;

use crate::auth::{self, User};
use crate::cache;
use crate::reply::{Reply, json_error, xml_error};
use crate::request::ApiRequest;
use crate::rss::{self, Feed};
use crate::settings::Settings;
use crate::{abuse, caps, getnzb, misc, v1, v2};

const DEFAULT_RATE_LIMIT: i64 = 60;

#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub enum Endpoint {
    V1,
    V2Capabilities,
    V2NzbAdd,
    V2Movies,
    V2Audio,
    V2Books,
    V2Anime,
    V2Search,
    V2Tv,
    V2Details,
    V2GetNzb,
    Inform,
    MediaInfo,
    Fallback,
    RssHealth,
    GetNzb,
    Rss(Feed),
}

/// What a route accepts. `allowed` is the `Allow` of a 405 and `options` the explicit handler's list.
struct RouteSpec {
    methods: &'static [&'static str],
    allowed: &'static str,
    options: Option<&'static str>,
    implicit_options: &'static str,
    query: QueryRule,
    token_limit: bool,
}

#[derive(Clone, Copy, PartialEq, Eq)]
enum QueryRule {
    None,
    V1,
    V2,
}

const SEARCH_ROUTE: RouteSpec = RouteSpec {
    methods: &["GET", "HEAD", "QUERY"],
    allowed: "GET, HEAD, OPTIONS, QUERY",
    options: Some("GET, QUERY, HEAD, OPTIONS"),
    implicit_options: "",
    query: QueryRule::V2,
    token_limit: true,
};

const READ_ROUTE: RouteSpec = RouteSpec {
    methods: &["GET", "HEAD"],
    allowed: "GET, HEAD",
    options: None,
    implicit_options: "GET,HEAD",
    query: QueryRule::None,
    token_limit: false,
};

impl Endpoint {
    fn spec(self) -> RouteSpec {
        match self {
            Self::V1 => RouteSpec {
                methods: &["GET", "HEAD", "POST", "QUERY"],
                allowed: "GET, HEAD, POST, OPTIONS, QUERY",
                options: Some("POST, GET, QUERY, HEAD, OPTIONS"),
                implicit_options: "",
                query: QueryRule::V1,
                token_limit: false,
            },
            Self::V2Movies
            | Self::V2Audio
            | Self::V2Books
            | Self::V2Anime
            | Self::V2Search
            | Self::V2Tv
            | Self::V2Details => SEARCH_ROUTE,
            Self::V2GetNzb => RouteSpec {
                query: QueryRule::V2,
                token_limit: true,
                ..READ_ROUTE
            },
            // GET falls through to the catch-all route, which owns this path for reads.
            Self::V2NzbAdd => RouteSpec {
                methods: &["POST"],
                allowed: "GET, HEAD, POST",
                options: None,
                implicit_options: "GET,HEAD,POST",
                query: QueryRule::None,
                token_limit: false,
            },
            Self::Rss(_) => RouteSpec {
                methods: &["GET", "HEAD", "POST"],
                allowed: "GET, HEAD, POST",
                implicit_options: "GET,HEAD,POST",
                token_limit: true,
                ..READ_ROUTE
            },
            Self::GetNzb => RouteSpec {
                methods: &["GET", "HEAD", "POST"],
                allowed: "GET, HEAD, POST",
                implicit_options: "GET,HEAD,POST",
                ..READ_ROUTE
            },
            Self::V2Capabilities
            | Self::Inform
            | Self::MediaInfo
            | Self::Fallback
            | Self::RssHealth => READ_ROUTE,
        }
    }

    fn accepts_query(self) -> bool {
        self.spec().methods.contains(&"QUERY")
    }
}

type Boxed = Pin<Box<dyn Future<Output = Response> + Send>>;

/// The axum handler for an endpoint.
pub fn dispatch(endpoint: Endpoint) -> impl Handler<(), Ctx> {
    #[derive(Clone)]
    struct Route(Endpoint);

    impl Handler<(), Ctx> for Route {
        type Future = Boxed;

        fn call(self, request: Request, state: Ctx) -> Self::Future {
            let endpoint = self.0;
            Box::pin(async move { run(endpoint, request, state).await })
        }
    }

    Route(endpoint)
}

async fn run(endpoint: Endpoint, request: Request, ctx: Ctx) -> Response {
    let Some(settings) = request.extensions().get::<Arc<Settings>>().cloned() else {
        return Reply::server_error().into_response();
    };
    let is_api = request.uri().path().starts_with("/api/");
    let route_id = route_param(&request, endpoint);
    let reply = respond(endpoint, request, &ctx, &settings, route_id).await;
    let reply = match reply {
        Ok(reply) => reply,
        Err(failure) => {
            error!(error = format!("{failure:#}"), "API request failed");
            Reply::server_error()
        }
    };
    finish(reply, is_api).into_response()
}

fn route_param(request: &Request, endpoint: Endpoint) -> Option<String> {
    let path = request.uri().path();
    match endpoint {
        Endpoint::MediaInfo => path
            .strip_prefix("/api/release/")
            .and_then(|rest| rest.strip_suffix("/mediainfo"))
            .map(str::to_owned),
        Endpoint::GetNzb => path.strip_prefix("/getnzb/").map(str::to_owned),
        _ => None,
    }
}

async fn respond(
    mut endpoint: Endpoint,
    request: Request,
    ctx: &Ctx,
    settings: &Arc<Settings>,
    route_id: Option<String>,
) -> anyhow::Result<Reply> {
    let method = request.method().as_str().to_owned();
    let path = request.uri().path().to_owned();
    let is_api = path.starts_with("/api/");

    // `HandleCors` answers preflights before routing.
    if is_api
        && method == "OPTIONS"
        && request
            .headers()
            .contains_key("access-control-request-method")
    {
        return Ok(preflight(&request));
    }

    let mut api = ApiRequest::read(request).await?;
    // Global middleware runs before routing, so it also sees requests that end in a 405.
    if let Some(reply) = abuse::check(ctx, settings, &api).await {
        return Ok(reply);
    }

    let mut spec = endpoint.spec();
    // The nzbadd path is POST only; reads belong to the catch-all route.
    if endpoint == Endpoint::V2NzbAdd && matches!(method.as_str(), "GET" | "HEAD") {
        endpoint = Endpoint::Fallback;
        spec = endpoint.spec();
    }

    if method == "OPTIONS" {
        match spec.options {
            None => {
                return Ok(Reply::new(200, "text/html; charset=utf-8", "")
                    .header("allow", spec.implicit_options)
                    .header_if(is_api, "vary", "Access-Control-Request-Method"));
            }
            Some(allow) => {
                let mut reply = Reply::empty(204);
                reply.set_header("allow", allow);
                reply.set_header("accept-query", "application/json");
                reply.set_header("vary", "Access-Control-Request-Method");
                return throttle_group(ctx, settings, &api, reply).await;
            }
        }
    }

    if !spec.methods.contains(&method.as_str()) {
        let message = format!(
            "The {method} method is not supported for route {}. Supported methods: {}.",
            path.trim_start_matches('/'),
            spec.allowed,
        );
        return Ok(Reply::exception(405, &message).header("allow", spec.allowed));
    }

    // Only the web group's /getnzb runs outside the API throttle.
    if endpoint == Endpoint::GetNzb {
        return getnzb::web(ctx, &api, route_id.as_deref()).await;
    }
    let group = match hit_group(ctx, settings, &api).await {
        Ok(group) => group,
        Err(reply) => return Ok(reply),
    };

    let mut token_headers = None;
    let handled = 'handled: {
        if spec.query != QueryRule::None {
            let strict = spec.query == QueryRule::V2;
            if let Err((status, message)) =
                api.validate_query(settings.query_max_body_bytes, strict)
            {
                let mut reply = if spec.query == QueryRule::V1 {
                    xml_error(201, &message)
                } else {
                    json_error(201, &message)
                };
                reply.status = status;
                break 'handled reply;
            }
        }
        let mut resolved = None;
        if spec.token_limit {
            resolved = resolve_for_limit(ctx, settings, &api).await?;
            if let Some(user) = &resolved {
                let max = if user.rate_limit == 0 {
                    DEFAULT_RATE_LIMIT
                } else {
                    user.rate_limit.max(1)
                };
                let name = format!("api-rate-limit:user:{}", user.id);
                let attempt = cache::hit(ctx, settings, &name, max, 60).await;
                if !attempt.allowed {
                    break 'handled too_many_for_token(attempt.retry_after, max);
                }
                token_headers = Some((max, (max - attempt.attempts).max(0)));
            }
        }
        handle(endpoint, ctx, settings, &api, resolved, route_id).await?
    };

    let mut reply = handled;
    // Inner limits win: Laravel's group throttle leaves headers already set alone.
    let (limit, remaining) = token_headers.unwrap_or(group);
    if !reply.has_header("x-ratelimit-limit") {
        reply.set_header("x-ratelimit-limit", limit.to_string());
        reply.set_header("x-ratelimit-remaining", remaining.to_string());
    }
    if endpoint.accepts_query() {
        reply.set_header("accept-query", "application/json");
    }
    if api.is_query_method() && is_api {
        reply.set_header("cache-control", "no-store, private");
    }
    Ok(reply)
}

async fn handle(
    endpoint: Endpoint,
    ctx: &Ctx,
    settings: &Arc<Settings>,
    api: &ApiRequest,
    resolved: Option<User>,
    route_id: Option<String>,
) -> anyhow::Result<Reply> {
    match endpoint {
        Endpoint::V1 => v1::api(ctx, settings, api).await,
        Endpoint::V2Capabilities => caps::v2(ctx, settings, api).await,
        Endpoint::V2NzbAdd => v2::nzb_add(ctx, settings, api, resolved).await,
        Endpoint::V2Movies => v2::movies(ctx, settings, api, resolved).await,
        Endpoint::V2Audio => {
            v2::music_or_books(ctx, settings, api, resolved, v2::Media::Audio).await
        }
        Endpoint::V2Books => {
            v2::music_or_books(ctx, settings, api, resolved, v2::Media::Books).await
        }
        Endpoint::V2Anime => v2::anime(ctx, settings, api, resolved).await,
        Endpoint::V2Search => v2::search(ctx, settings, api, resolved).await,
        Endpoint::V2Tv => v2::tv(ctx, settings, api, resolved).await,
        Endpoint::V2Details => v2::details(ctx, settings, api, resolved).await,
        Endpoint::V2GetNzb => v2::get_nzb(ctx, settings, api, resolved).await,
        Endpoint::Inform => misc::inform(ctx, api).await,
        Endpoint::MediaInfo => misc::media_info(ctx, route_id.as_deref().unwrap_or_default()).await,
        Endpoint::Fallback => Ok(misc::fallback()),
        Endpoint::RssHealth => Ok(misc::rss_health()),
        Endpoint::Rss(feed) => rss::feed(ctx, settings, api, feed).await,
        Endpoint::GetNzb => unreachable!("handled before the API throttle"),
    }
}

/// `ThrottleApiRequestsByToken::resolveUser`.
async fn resolve_for_limit(
    ctx: &Ctx,
    settings: &Settings,
    api: &ApiRequest,
) -> anyhow::Result<Option<User>> {
    let input = &api.input;
    let (token, verified_only) = match input.str("api_token") {
        Some(token) if !token.is_empty() => (token, false),
        _ => match input.str("apikey") {
            Some(token) if !token.is_empty() => (token, true),
            _ => return Ok(None),
        },
    };
    let user = if verified_only {
        auth::resolve_verified(ctx, settings, token).await?
    } else {
        auth::resolve(ctx, settings, token).await?
    };
    Ok(user.filter(|user| !user.disabled_by_role_id()))
}

fn too_many_for_token(retry_after: i64, limit: i64) -> Reply {
    let body = crate::json::J::obj([
        ("error", crate::json::J::str("Request limit reached")),
        ("retry_after", crate::json::J::Int(retry_after)),
    ]);
    Reply::json(429, &body, crate::json::Style::Plain)
        .header("retry-after", retry_after.to_string())
        .header("x-ratelimit-limit", limit.to_string())
        .header("x-ratelimit-remaining", "0")
        .header("x-nntmux", "API ERROR [500] Request limit reached")
}

/// `throttle:60,1` on the `api` group, keyed by client address.
async fn hit_group(ctx: &Ctx, settings: &Settings, api: &ApiRequest) -> Result<(i64, i64), Reply> {
    const LIMIT: i64 = 60;
    // The routes declare no domain, so Laravel's signature is the address alone. Keying on the Host
    // header would let a client mint a fresh bucket per request.
    let ip = api.client_ip().unwrap_or_default();
    let name = format!("throttle:{}", hex::encode(Sha1::digest(format!("|{ip}"))));
    let attempt = cache::hit(ctx, settings, &name, LIMIT, 60).await;
    if !attempt.allowed {
        let reset = chrono::Utc::now().timestamp() + attempt.retry_after;
        return Err(Reply::exception(429, "Too Many Attempts.")
            .header("retry-after", attempt.retry_after.to_string())
            .header("x-ratelimit-limit", LIMIT.to_string())
            .header("x-ratelimit-remaining", "0")
            .header("x-ratelimit-reset", reset.to_string()));
    }
    Ok((LIMIT, (LIMIT - attempt.attempts).max(0)))
}

async fn throttle_group(
    ctx: &Ctx,
    settings: &Settings,
    api: &ApiRequest,
    mut reply: Reply,
) -> anyhow::Result<Reply> {
    match hit_group(ctx, settings, api).await {
        Ok((limit, remaining)) => {
            reply.set_header("x-ratelimit-limit", limit.to_string());
            reply.set_header("x-ratelimit-remaining", remaining.to_string());
            Ok(reply)
        }
        Err(limited) => Ok(limited),
    }
}

fn preflight(request: &Request) -> Reply {
    let echo = |name: &str| {
        request
            .headers()
            .get(name)
            .and_then(|value| value.to_str().ok())
            .unwrap_or_default()
            .to_owned()
    };
    Reply::empty(204)
        .header("access-control-allow-origin", "*")
        .header(
            "vary",
            "Access-Control-Request-Method, Access-Control-Request-Headers",
        )
        .header(
            "access-control-allow-methods",
            echo("access-control-request-method"),
        )
        .header(
            "access-control-allow-headers",
            echo("access-control-request-headers"),
        )
        .header("access-control-max-age", "0")
}

/// What's added to every response on the way out.
fn finish(mut reply: Reply, is_api: bool) -> Reply {
    if !reply.has_header("cache-control") {
        reply.set_header("cache-control", "no-cache, private");
    }
    if is_api && !reply.has_header("access-control-allow-methods") {
        reply.set_header("access-control-allow-origin", "*");
        reply.set_header("access-control-expose-headers", "Accept-Query, Allow");
    }
    reply
}

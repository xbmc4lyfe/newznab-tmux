//! Laravel's `throttle:N,M`, a fixed window in Redis keyed by account or address. Redis trouble
//! lets the request through, the way Laravel degraded when Redis was away.

use axum::extract::Request;
use axum::http::HeaderValue;
use axum::http::header::RETRY_AFTER;
use axum::middleware::Next;
use axum::response::{IntoResponse, Response};
use nntmux_runtime::Ctx;
use redis::{AsyncCommands, Script};
use tracing::warn;

use crate::auth::Auth;
use crate::client_ip;
use crate::error::WebError;
use crate::guard::expects_json;
use crate::session::key_prefix;

/// Counts one hit in a window, and returns the count and the seconds left in the window.
pub async fn hit(ctx: &Ctx, key: &str, window_seconds: u64) -> redis::RedisResult<(u64, i64)> {
    let script = Script::new(
        "local count = redis.call('INCR', KEYS[1])
         if count == 1 then redis.call('EXPIRE', KEYS[1], ARGV[1]) end
         return {count, redis.call('TTL', KEYS[1])}",
    );
    let mut redis = ctx.redis.clone();
    script
        .key(key)
        .arg(window_seconds)
        .invoke_async(&mut redis)
        .await
}

/// How many hits a key has in its current window. Redis trouble reads as none.
pub async fn attempts(ctx: &Ctx, key: &str) -> u64 {
    let mut redis = ctx.redis.clone();
    redis
        .get::<_, Option<u64>>(key)
        .await
        .ok()
        .flatten()
        .unwrap_or(0)
}

/// Starts a key over.
pub async fn clear(ctx: &Ctx, key: &str) {
    let mut redis = ctx.redis.clone();
    if let Err(failure) = redis.del::<_, ()>(key).await {
        warn!(error = %failure, "clearing a throttle key failed");
    }
}

/// `from_fn(|r, n| throttle::limit(r, n, 6, 60))` is `throttle:6,1`.
pub async fn limit(request: Request, next: Next, max: u64, window_seconds: u64) -> Response {
    let Some(ctx) = request.extensions().get::<Ctx>().cloned() else {
        return next.run(request).await;
    };
    let who = match request.extensions().get::<Auth>() {
        Some(auth) => format!("u{}", auth.user.id),
        None => {
            let (parts, body) = request.into_parts();
            let ip = client_ip::from_parts(&parts).to_string();
            let request = Request::from_parts(parts, body);
            return check(&ctx, request, next, &format!("ip{ip}"), max, window_seconds).await;
        }
    };
    check(&ctx, request, next, &who, max, window_seconds).await
}

async fn check(
    ctx: &Ctx,
    request: Request,
    next: Next,
    who: &str,
    max: u64,
    window_seconds: u64,
) -> Response {
    let key = format!(
        "{}throttle:{}:{max}:{window_seconds}:{who}",
        key_prefix(),
        request.uri().path()
    );
    let json = expects_json(request.headers());
    match hit(ctx, &key, window_seconds).await {
        Ok((count, ttl)) if count > max => {
            let mut response = if json {
                WebError::TooManyRequests.json()
            } else {
                WebError::TooManyRequests.into_response()
            };
            if let Ok(value) = HeaderValue::from_str(&ttl.max(1).to_string()) {
                response.headers_mut().insert(RETRY_AFTER, value);
            }
            response
        }
        Ok(_) => next.run(request).await,
        Err(failure) => {
            warn!(error = %failure, "throttle check failed, allowing the request");
            next.run(request).await
        }
    }
}

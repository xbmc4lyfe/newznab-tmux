//! Request metrics for `nntmux-web`: a count and a latency histogram per route, method and
//! status. Apply [`track`] with `Router::route_layer`, after every route is added, so the route
//! label is the matched pattern (`/api/v1/api`, `/details/{guid}`) and never a raw path.

use std::time::Instant;

use axum::extract::{MatchedPath, Request};
use axum::http::Method;
use axum::middleware::Next;
use axum::response::Response;

pub const REQUESTS: &str = "nntmux_http_requests_total";
pub const DURATION: &str = "nntmux_http_request_duration_seconds";

/// Middleware that records every routed request.
pub async fn track(request: Request, next: Next) -> Response {
    let started = Instant::now();
    let method = method_label(request.method());
    let route = request
        .extensions()
        .get::<MatchedPath>()
        .map_or_else(|| "unmatched".to_owned(), |path| path.as_str().to_owned());
    let response = next.run(request).await;
    let status = response.status().as_u16().to_string();
    metrics::counter!(REQUESTS, "method" => method, "route" => route.clone(), "status" => status)
        .increment(1);
    metrics::histogram!(DURATION, "method" => method, "route" => route)
        .record(started.elapsed().as_secs_f64());
    response
}

/// Standard methods by name; anything else shares one label so a client can't add series.
fn method_label(method: &Method) -> &'static str {
    match *method {
        Method::GET => "GET",
        Method::HEAD => "HEAD",
        Method::POST => "POST",
        Method::PUT => "PUT",
        Method::PATCH => "PATCH",
        Method::DELETE => "DELETE",
        Method::OPTIONS => "OPTIONS",
        _ => "other",
    }
}

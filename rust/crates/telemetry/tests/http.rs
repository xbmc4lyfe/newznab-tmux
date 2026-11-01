//! The request metrics carry the matched route, never the raw path.

use axum::Router;
use axum::body::Body;
use axum::http::{Request, StatusCode};
use axum::middleware::from_fn;
use axum::routing::get;
use metrics_exporter_prometheus::PrometheusBuilder;
use nntmux_telemetry::http::track;
use tower::ServiceExt;

#[tokio::test]
async fn records_route_pattern_method_and_status() {
    let recorder = PrometheusBuilder::new()
        .set_buckets(&[0.1, 1.0])
        .unwrap()
        .build_recorder();
    let handle = recorder.handle();
    let _guard = metrics::set_default_local_recorder(&recorder);

    let app = Router::new()
        .route("/details/{guid}", get(|| async { "ok" }))
        .route(
            "/broken",
            get(|| async { StatusCode::INTERNAL_SERVER_ERROR }),
        )
        .route_layer(from_fn(track));

    for uri in ["/details/abc", "/details/def", "/broken", "/nowhere"] {
        let request = Request::builder().uri(uri).body(Body::empty()).unwrap();
        app.clone().oneshot(request).await.unwrap();
    }
    let request = Request::builder()
        .method("BREW")
        .uri("/details/x")
        .body(Body::empty())
        .unwrap();
    app.clone().oneshot(request).await.unwrap();

    let rendered = handle.render();
    assert!(
        rendered.contains(
            r#"nntmux_http_requests_total{method="GET",route="/details/{guid}",status="200"} 2"#
        ),
        "{rendered}"
    );
    assert!(
        rendered
            .contains(r#"nntmux_http_requests_total{method="GET",route="/broken",status="500"} 1"#),
        "{rendered}"
    );
    assert!(
        rendered.contains(r#"method="other",route="/details/{guid}",status="405""#),
        "{rendered}"
    );
    assert!(
        rendered.contains(
            r#"nntmux_http_request_duration_seconds_count{method="GET",route="/details/{guid}"} 2"#
        ),
        "{rendered}"
    );
    // An unrouted path never reaches a route layer, so it can't mint a series per URL.
    assert!(!rendered.contains("nowhere"), "{rendered}");
}

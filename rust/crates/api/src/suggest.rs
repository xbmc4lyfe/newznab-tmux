//! `SearchSuggestController`: search-box autocomplete and "did you mean".

use std::sync::Arc;

use axum::Extension;
use axum::extract::{Query, State};
use axum::http::HeaderMap;
use axum::response::Response;
use nntmux_runtime::Ctx;
use nntmux_search::Suggestion;
use nntmux_webkit::ClientIp;
use serde::Deserialize;

use crate::cache;
use crate::json::{J, Style};
use crate::reply::Reply;
use crate::settings::Settings;

#[derive(Deserialize)]
pub struct Params {
    #[serde(default)]
    q: String,
    index: Option<String>,
}

/// These live under `/api/`, so `HandleCors` applies, though they run in the web stack.
fn finished(reply: Reply) -> Response {
    reply
        .header("cache-control", "no-cache, private")
        .header("access-control-allow-origin", "*")
        .header("access-control-expose-headers", "Accept-Query, Allow")
        .into_response()
}

fn reply(value: &J) -> Response {
    finished(Reply::json(200, value, Style::Plain))
}

fn no_suggestions() -> Response {
    reply(&J::obj([
        ("success", J::Bool(true)),
        ("suggestions", J::Arr(Vec::new())),
    ]))
}

fn names(found: &[Suggestion]) -> J {
    J::Arr(
        found
            .iter()
            .map(|hit| J::str(hit.suggest.clone()))
            .collect(),
    )
}

/// Over the limit is true. `tooManyAttempts` then `hit`, as the controller does.
async fn limited(ctx: &Ctx, settings: &Settings, name: &str, ip: &ClientIp, max: i64) -> bool {
    !cache::hit(ctx, settings, &format!("{name}:{ip}"), max, 60)
        .await
        .allowed
}

fn too_many() -> Response {
    Reply::json(
        429,
        &J::obj([
            ("success", J::Bool(false)),
            ("error", J::str("Too many requests")),
        ]),
        Style::Plain,
    )
    .into_response()
}

fn trimmed(params: &Params) -> String {
    crate::input::trim(&params.q).to_owned()
}

pub async fn autocomplete(
    State(ctx): State<Ctx>,
    Extension(settings): Extension<Arc<Settings>>,
    ip: ClientIp,
    Query(params): Query<Params>,
    _: HeaderMap,
) -> Response {
    if limited(&ctx, &settings, "autocomplete", &ip, 60).await {
        return too_many();
    }
    let query = trimmed(&params);
    let Ok(client) = settings.search(&ctx) else {
        return no_suggestions();
    };
    if !client.config().autocomplete_enabled || query.len() < 2 {
        return no_suggestions();
    }
    // Failures read as nothing to suggest: a search box should stay quiet.
    let found = client
        .autocomplete(&query, params.index.as_deref())
        .await
        .unwrap_or_default();
    reply(&J::obj([
        ("success", J::Bool(true)),
        ("query", J::Str(query)),
        ("suggestions", names(&found)),
    ]))
}

fn best(found: &[Suggestion]) -> J {
    found
        .iter()
        .max_by_key(|hit| hit.docs)
        .map_or(J::Null, |hit| J::str(hit.suggest.clone()))
}

fn sorted_by_docs(mut found: Vec<Suggestion>) -> Vec<Suggestion> {
    found.sort_by(|a, b| b.docs.cmp(&a.docs));
    found
}

pub async fn suggest(
    State(ctx): State<Ctx>,
    Extension(settings): Extension<Arc<Settings>>,
    ip: ClientIp,
    Query(params): Query<Params>,
) -> Response {
    if limited(&ctx, &settings, "suggest", &ip, 30).await {
        return too_many();
    }
    let query = trimmed(&params);
    let Ok(client) = settings.search(&ctx) else {
        return no_suggestions();
    };
    if !client.config().suggest_enabled || query.is_empty() || query == "0" {
        return no_suggestions();
    }
    match client.suggest(&query, params.index.as_deref()).await {
        Ok(found) => {
            let found = sorted_by_docs(found);
            reply(&J::obj([
                ("success", J::Bool(true)),
                ("query", J::Str(query)),
                ("suggestions", names(&found)),
                ("best", best(&found)),
            ]))
        }
        Err(_) => reply(&J::obj([
            ("success", J::Bool(true)),
            ("query", J::Str(query)),
            ("suggestions", J::Arr(Vec::new())),
        ])),
    }
}

pub async fn assist(
    State(ctx): State<Ctx>,
    Extension(settings): Extension<Arc<Settings>>,
    ip: ClientIp,
    Query(params): Query<Params>,
) -> Response {
    if limited(&ctx, &settings, "searchassist", &ip, 60).await {
        return too_many();
    }
    let query = trimmed(&params);
    let mut completions = J::Arr(Vec::new());
    let mut suggestion = J::Null;
    if let Ok(client) = settings.search(&ctx) {
        let index = params.index.as_deref();
        if client.config().autocomplete_enabled
            && query.len() >= 2
            && let Ok(found) = client.autocomplete(&query, index).await
        {
            completions = names(&found);
        }
        if client.config().suggest_enabled
            && !query.is_empty()
            && query != "0"
            && let Ok(found) = client.suggest(&query, index).await
        {
            suggestion = best(&sorted_by_docs(found));
        }
    }
    reply(&J::obj([
        ("success", J::Bool(true)),
        ("query", J::Str(query)),
        ("autocomplete", completions),
        ("suggest", suggestion),
    ]))
}

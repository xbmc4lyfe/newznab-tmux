//! A parsed request and the HTTP QUERY (RFC 10008) input rules.

use axum::body::to_bytes;
use axum::extract::Request;
use axum::http::HeaderMap;
use serde_json::Value as Json;

use crate::input::{Bag, Input, Value, canonicalize, from_json, parse_urlencoded};

/// Largest body read: `post_max_size` in the image's PHP configuration.
const MAX_BODY_BYTES: usize = 64 * 1024 * 1024;
const MAX_QUERY_PARAMETERS: usize = 64;

pub struct ApiRequest {
    pub method: String,
    pub path: String,
    pub input: Input,
    pub headers: HeaderMap,
    pub raw_body: bytes::Bytes,
}

impl ApiRequest {
    pub async fn read(request: Request) -> anyhow::Result<Self> {
        let (parts, body) = request.into_parts();
        let raw_body = to_bytes(body, MAX_BODY_BYTES).await?;
        let method = parts.method.as_str().to_owned();
        let query = parse_urlencoded(parts.uri.query().unwrap_or_default());
        let mut this = Self {
            method,
            path: parts.uri.path().to_owned(),
            input: Input {
                query,
                body: Bag::new(),
            },
            headers: parts.headers,
            raw_body,
        };
        // HTTP QUERY's body waits for `validate_query`, the only reader allowed to accept it.
        if this.method != "QUERY" && this.method != "GET" && this.method != "HEAD" {
            this.input.body = this.parse_body();
        }
        Ok(this)
    }

    fn parse_body(&self) -> Bag {
        let content_type = self
            .header("content-type")
            .unwrap_or_default()
            .to_ascii_lowercase();
        if content_type.contains("json") {
            return match serde_json::from_slice::<Json>(&self.raw_body) {
                Ok(json) => match from_json(&json) {
                    Value::Map(entries) => entries,
                    _ => Bag::new(),
                },
                Err(_) => Bag::new(),
            };
        }
        if content_type.starts_with("application/x-www-form-urlencoded") {
            return parse_urlencoded(&String::from_utf8_lossy(&self.raw_body));
        }
        Bag::new()
    }

    pub fn header(&self, name: &str) -> Option<&str> {
        self.headers.get(name).and_then(|value| value.to_str().ok())
    }

    /// `url('/')`: the request's scheme and host, with no trailing slash. Laravel trusts forwarded
    /// headers only from known proxies; the Rust web role always sits behind one.
    pub fn base_url(&self) -> String {
        let scheme = self
            .header("x-forwarded-proto")
            .and_then(|value| value.split(',').next())
            .map_or("http", str::trim);
        let host = self
            .header("x-forwarded-host")
            .and_then(|value| value.split(',').next())
            .map(str::trim)
            .or_else(|| self.header("host"))
            .unwrap_or("localhost");
        format!("{scheme}://{host}")
    }

    /// The client address for accounting and per-IP limits: the nearest forwarded address that
    /// isn't a private proxy hop.
    pub fn client_ip(&self) -> Option<String> {
        let forwarded = self.header("x-forwarded-for").unwrap_or_default();
        let hops: Vec<&str> = forwarded
            .split(',')
            .map(str::trim)
            .filter(|hop| !hop.is_empty())
            .collect();
        let parsed = |hop: &&str| hop.parse::<std::net::IpAddr>().ok();
        hops.iter()
            .rev()
            .filter_map(parsed)
            .find(|ip| !is_private(ip))
            .or_else(|| hops.first().and_then(parsed))
            .map(|ip| ip.to_string())
    }

    pub fn is_query_method(&self) -> bool {
        self.method == "QUERY"
    }

    /// `$request->path()`: no leading slash.
    pub fn laravel_path(&self) -> &str {
        self.path.trim_start_matches('/')
    }

    /// `ValidateHttpQueryInput`: `Ok` when the request may continue. An `Err` carries the HTTP status
    /// and message. A valid QUERY request's body is canonicalized into the input.
    pub fn validate_query(
        &mut self,
        max_body: usize,
        strict_url_values: bool,
    ) -> Result<(), (u16, String)> {
        if !self.is_query_method() {
            return if strict_url_values {
                validate_url_values(&self.input.query)
            } else {
                Ok(())
            };
        }
        if !is_json_media_type(self.header("content-type")) {
            return Err((
                415,
                "QUERY requests must use Content-Type: application/json".to_owned(),
            ));
        }
        if self.raw_body.len() > max_body {
            return Err((413, "QUERY body exceeds the maximum size".to_owned()));
        }
        let invalid = || {
            (
                400,
                "QUERY body must be valid JSON without nested values".to_owned(),
            )
        };
        let decoded: Json = serde_json::from_slice(&self.raw_body).map_err(|_| invalid())?;
        if depth(&decoded) >= 3 {
            return Err(invalid());
        }
        let Json::Object(body) = decoded else {
            return Err((400, "QUERY body must be a JSON object".to_owned()));
        };

        let mut keys: Vec<&str> = self
            .input
            .query
            .iter()
            .map(|(key, _)| key.as_str())
            .collect();
        for key in body.keys() {
            if !keys.contains(&key.as_str()) {
                keys.push(key);
            }
        }
        if keys.iter().any(|key| !valid_key(key)) {
            return Err((
                400,
                "Parameter names may only contain letters, digits and underscores (max 32)"
                    .to_owned(),
            ));
        }
        if keys.len() > MAX_QUERY_PARAMETERS {
            return Err((
                400,
                format!("QUERY requests accept at most {MAX_QUERY_PARAMETERS} parameters"),
            ));
        }
        if let Some((key, _)) = self
            .input
            .query
            .iter()
            .find(|(key, _)| body.contains_key(key))
        {
            return Err((
                400,
                format!("Parameter {key} must not be sent in both the URL and the body"),
            ));
        }
        if let Some((key, _)) = body
            .iter()
            .find(|(key, value)| !valid_body_value(key, value))
        {
            return Err((400, format!("Parameter {key} has an unsupported type")));
        }
        validate_url_values(&self.input.query)?;

        let parsed: Bag = body
            .iter()
            .map(|(key, value)| (key.clone(), from_json(value)))
            .collect();
        self.input.body = canonicalize(&parsed);
        self.input.query = canonicalize(&self.input.query);
        Ok(())
    }
}

fn is_private(ip: &std::net::IpAddr) -> bool {
    match ip {
        std::net::IpAddr::V4(v4) => v4.is_private() || v4.is_loopback() || v4.is_link_local(),
        std::net::IpAddr::V6(v6) => {
            v6.is_loopback() || v6.is_unique_local() || v6.is_unicast_link_local()
        }
    }
}

/// Container nesting. `json_decode($raw, false, 3)` accepts an object holding a list of scalars and
/// refuses anything deeper, which probing PHP shows means fewer than three levels.
fn depth(value: &Json) -> usize {
    match value {
        Json::Array(items) => 1 + items.iter().map(depth).max().unwrap_or(0),
        Json::Object(entries) => 1 + entries.values().map(depth).max().unwrap_or(0),
        _ => 0,
    }
}

fn valid_key(key: &str) -> bool {
    !key.is_empty()
        && key.len() <= 32
        && key
            .bytes()
            .all(|byte| byte.is_ascii_alphanumeric() || byte == b'_')
}

fn valid_body_value(key: &str, value: &Json) -> bool {
    match value {
        Json::String(_) | Json::Number(_) => true,
        Json::Array(items) => {
            key == "cat"
                && items
                    .iter()
                    .all(|item| matches!(item, Json::String(_) | Json::Number(_)))
        }
        _ => false,
    }
}

/// URL parameters sent as arrays (`?id[]=x`) are refused unless they're list parameters, so scalar
/// filters never reach the handlers as arrays.
fn validate_url_values(query: &Bag) -> Result<(), (u16, String)> {
    for (key, value) in query {
        let valid = match value {
            Value::Null | Value::Str(_) => true,
            Value::List(items) => {
                key == "cat"
                    && items
                        .iter()
                        .all(|item| matches!(item, Value::Null | Value::Str(_)))
            }
            Value::Map(_) => false,
        };
        if !valid {
            let name = if valid_key(key) {
                key.as_str()
            } else {
                "value"
            };
            return Err((400, format!("Parameter {name} has an unsupported type")));
        }
    }
    Ok(())
}

fn is_json_media_type(content_type: Option<&str>) -> bool {
    let Some(content_type) = content_type else {
        return false;
    };
    let mut parts = content_type.split(';').map(str::trim);
    if !parts
        .next()
        .unwrap_or_default()
        .eq_ignore_ascii_case("application/json")
    {
        return false;
    }
    parts
        .filter(|parameter| !parameter.is_empty())
        .all(|parameter| {
            let (name, value) = parameter.split_once('=').unwrap_or((parameter, ""));
            name.trim().eq_ignore_ascii_case("charset")
                && value.trim().trim_matches('"').eq_ignore_ascii_case("utf-8")
        })
}

//! Request input with Laravel's rules: body over query string, `name[]` lists, values trimmed
//! except passwords and exact poster searches, empty strings treated as absent.

use std::collections::HashSet;

use axum::extract::FromRequestParts;
use axum::http::request::Parts;
use serde_json::Value;
use std::convert::Infallible;

/// These keep their surrounding whitespace, as Laravel's `TrimStrings` left them.
const UNTRIMMED: [&str; 5] = [
    "current_password",
    "password",
    "password_confirmation",
    "poster",
    "searchadvposter",
];

#[derive(Clone, Debug, Default)]
pub struct Input {
    body: Vec<(String, String)>,
    query: Vec<(String, String)>,
    json: Option<Value>,
}

pub(crate) fn parse_pairs(raw: &[u8]) -> Vec<(String, String)> {
    form_urlencoded::parse(raw).into_owned().collect()
}

impl Input {
    pub fn from_query(query: &str) -> Self {
        Self {
            query: parse_pairs(query.as_bytes()),
            ..Self::default()
        }
    }

    pub(crate) fn with_form(mut self, body: &[u8]) -> Self {
        self.body = parse_pairs(body);
        self
    }

    pub(crate) fn with_json(mut self, body: &[u8]) -> Self {
        self.json = serde_json::from_slice(body).ok();
        self
    }

    pub(crate) fn with_field(mut self, key: &str, value: String) -> Self {
        self.body.push((key.to_owned(), value));
        self
    }

    fn scalar(value: &Value) -> Option<String> {
        match value {
            Value::String(text) => Some(text.clone()),
            Value::Number(number) => Some(number.to_string()),
            Value::Bool(true) => Some("1".to_owned()),
            Value::Bool(false) => Some("0".to_owned()),
            _ => None,
        }
    }

    fn raw(&self, key: &str) -> Option<String> {
        if let Some(value) = self.json.as_ref().and_then(|json| json.get(key)) {
            return Self::scalar(value);
        }
        self.body
            .iter()
            .chain(&self.query)
            .find(|(name, _)| name == key)
            .map(|(_, value)| value.clone())
    }

    /// The value, trimmed unless the field is exempt. Empty counts as absent.
    pub fn get(&self, key: &str) -> Option<String> {
        let value = self.raw(key)?;
        let value = if UNTRIMMED.contains(&key) {
            value
        } else {
            value.trim().to_owned()
        };
        (!value.is_empty()).then_some(value)
    }

    pub fn str(&self, key: &str) -> String {
        self.get(key).unwrap_or_default()
    }

    pub fn filled(&self, key: &str) -> bool {
        self.get(key).is_some()
    }

    /// True if the field arrived at all, even empty.
    pub fn has(&self, key: &str) -> bool {
        self.raw(key).is_some() || !self.list(key).is_empty()
    }

    /// Laravel's `$request->boolean()`.
    pub fn boolean(&self, key: &str) -> bool {
        matches!(
            self.str(key).to_ascii_lowercase().as_str(),
            "1" | "true" | "on" | "yes"
        )
    }

    /// An integer, or the default when absent or not a whole number.
    pub fn int(&self, key: &str, default: i64) -> i64 {
        self.get(key)
            .filter(|value| {
                let digits = value.strip_prefix('-').unwrap_or(value);
                !digits.is_empty() && digits.bytes().all(|byte| byte.is_ascii_digit())
            })
            .and_then(|value| value.parse().ok())
            .unwrap_or(default)
    }

    /// `page`, at least 1.
    pub fn page(&self) -> u64 {
        self.get("page")
            .filter(|value| value.bytes().all(|byte| byte.is_ascii_digit()))
            .and_then(|value| value.parse().ok())
            .unwrap_or(1u64)
            .max(1)
    }

    /// Every value of a list field, sent as `key[]=` or `key[0]=`, a repeated `key=`, or a
    /// JSON array. Keeps first-seen order and drops repeats.
    pub fn list(&self, key: &str) -> Vec<String> {
        if let Some(Value::Array(items)) = self.json.as_ref().and_then(|json| json.get(key)) {
            return items.iter().filter_map(Self::scalar).collect();
        }
        let prefix = format!("{key}[");
        let mut seen = HashSet::new();
        self.body
            .iter()
            .chain(&self.query)
            .filter(|(name, _)| name == key || (name.starts_with(&prefix) && name.ends_with(']')))
            .filter(|(_, value)| seen.insert(value.clone()))
            .map(|(_, value)| value.clone())
            .collect()
    }

    /// The query string pairs, for rebuilding links. Excludes `page`.
    pub fn query_pairs(&self) -> Vec<(String, String)> {
        self.query
            .iter()
            .filter(|(name, _)| name != "page")
            .cloned()
            .collect()
    }
}

impl<S: Sync> FromRequestParts<S> for Input {
    type Rejection = Infallible;

    async fn from_request_parts(parts: &mut Parts, _: &S) -> Result<Self, Self::Rejection> {
        Ok(parts
            .extensions
            .get::<Input>()
            .cloned()
            .unwrap_or_else(|| Input::from_query(parts.uri.query().unwrap_or_default())))
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn the_body_wins_over_the_query() {
        let input = Input::from_query("a=query&b=only").with_form(b"a=body");
        assert_eq!(input.str("a"), "body");
        assert_eq!(input.str("b"), "only");
    }

    #[test]
    fn trimming_follows_laravel() {
        let input = Input::default().with_form(b"name=+x+&password=+x+&poster=+x+&empty=+");
        assert_eq!(input.str("name"), "x");
        assert_eq!(input.str("password"), " x ");
        assert_eq!(input.str("poster"), " x ");
        assert_eq!(input.get("empty"), None);
        assert!(input.has("empty"));
    }

    #[test]
    fn lists_accept_every_form_laravel_did() {
        let input = Input::from_query("ids%5B%5D=1&ids%5B0%5D=2&ids=3&ids%5B%5D=1");
        assert_eq!(input.list("ids"), vec!["1", "2", "3"]);
        let json = Input::default().with_json(br#"{"ids":[4,"5"],"on":true}"#);
        assert_eq!(json.list("ids"), vec!["4", "5"]);
        assert!(json.boolean("on"));
    }

    #[test]
    fn numbers_reject_junk() {
        let input = Input::from_query("page=0&offset=x&n=-4");
        assert_eq!(input.page(), 1);
        assert_eq!(Input::from_query("page=12").page(), 12);
        assert_eq!(Input::from_query("page=x").page(), 1);
        assert_eq!(input.int("offset", 9), 9);
        assert_eq!(input.int("n", 0), -4);
    }
}

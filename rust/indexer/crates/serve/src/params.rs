//! The query string of a Newznab request.

/// Decoded `key=value` pairs. Keys are lowercased, because clients spell them either way.
#[derive(Debug, Default)]
pub(crate) struct Params(Vec<(String, String)>);

impl Params {
    pub(crate) fn parse(query: &str) -> Self {
        let pairs = query
            .split('&')
            .filter(|pair| !pair.is_empty())
            .map(|pair| {
                let (key, value) = pair.split_once('=').unwrap_or((pair, ""));
                (decode(key).to_ascii_lowercase(), decode(value))
            })
            .collect();
        Self(pairs)
    }

    /// The first non-empty value for `key`, trimmed. An empty value counts as absent.
    pub(crate) fn get(&self, key: &str) -> Option<&str> {
        self.0
            .iter()
            .filter(|(k, _)| k == key)
            .map(|(_, v)| v.trim())
            .find(|v| !v.is_empty())
    }
}

/// Percent-decoding with `+` as a space. A stray `%` stays as it is.
fn decode(text: &str) -> String {
    let bytes = text.as_bytes();
    let mut out = Vec::with_capacity(bytes.len());
    let mut i = 0;
    while i < bytes.len() {
        match bytes[i] {
            b'+' => out.push(b' '),
            b'%' => match (hex(bytes.get(i + 1)), hex(bytes.get(i + 2))) {
                (Some(high), Some(low)) => {
                    out.push(high << 4 | low);
                    i += 2;
                }
                _ => out.push(b'%'),
            },
            byte => out.push(byte),
        }
        i += 1;
    }
    String::from_utf8_lossy(&out).into_owned()
}

fn hex(byte: Option<&u8>) -> Option<u8> {
    let byte = *byte?;
    (byte as char).to_digit(16).map(|d| d as u8)
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn decodes_values_and_lowercases_keys() {
        let params = Params::parse("T=tvsearch&q=It%27s+Okay%20S01&APIKEY=k&cat=5040%2C5045");
        assert_eq!(params.get("t"), Some("tvsearch"));
        assert_eq!(params.get("q"), Some("It's Okay S01"));
        assert_eq!(params.get("apikey"), Some("k"));
        assert_eq!(params.get("cat"), Some("5040,5045"));
        assert_eq!(params.get("missing"), None);
    }

    #[test]
    fn empty_values_count_as_absent_and_the_first_value_wins() {
        let params = Params::parse("q=&season=&season=3&ep=2&ep=5&flag");
        assert_eq!(params.get("q"), None);
        assert_eq!(params.get("season"), Some("3"));
        assert_eq!(params.get("ep"), Some("2"));
        assert_eq!(params.get("flag"), None);
    }

    #[test]
    fn bad_escapes_stay_literal() {
        let params = Params::parse("q=100%&r=%zz&s=%e2%82%ac&u=%ff");
        assert_eq!(params.get("q"), Some("100%"));
        assert_eq!(params.get("r"), Some("%zz"));
        assert_eq!(params.get("s"), Some("\u{20ac}"));
        assert_eq!(params.get("u"), Some("\u{fffd}"));
    }
}

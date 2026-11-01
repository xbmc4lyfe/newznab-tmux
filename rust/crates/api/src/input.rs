//! Request parameters as Laravel's `Request` presents them: PHP's query-string rules, then
//! `TrimStrings` and `ConvertEmptyStringsToNull`.

use serde_json::Value as Json;

#[derive(Clone, Debug, PartialEq)]
pub enum Value {
    Null,
    Str(String),
    /// `a[]=1`, or a JSON array in a body.
    List(Vec<Value>),
    /// `a[k]=1`, or a JSON object in a body.
    Map(Vec<(String, Value)>),
}

impl Value {
    pub fn as_str(&self) -> Option<&str> {
        match self {
            Self::Str(value) => Some(value),
            _ => None,
        }
    }

    /// `$request->filled()`'s test on a value: arrays count, blank strings and null don't.
    pub fn is_filled(&self) -> bool {
        match self {
            Self::Null => false,
            Self::Str(value) => !value.trim().is_empty(),
            Self::List(_) | Self::Map(_) => true,
        }
    }
}

pub type Bag = Vec<(String, Value)>;

#[derive(Clone, Debug, Default)]
pub struct Input {
    pub query: Bag,
    pub body: Bag,
}

impl Input {
    /// `$request->input($key)`: the body wins over the query string.
    pub fn get(&self, key: &str) -> Option<&Value> {
        find(&self.body, key).or_else(|| find(&self.query, key))
    }

    pub fn has(&self, key: &str) -> bool {
        self.get(key).is_some()
    }

    pub fn filled(&self, key: &str) -> bool {
        self.get(key).is_some_and(Value::is_filled)
    }

    /// The string form when the parameter is a scalar. Null and arrays give `None`.
    pub fn str(&self, key: &str) -> Option<&str> {
        self.get(key).and_then(Value::as_str)
    }

    /// `(string) $request->input($key, '')`.
    pub fn string(&self, key: &str) -> String {
        self.str(key).unwrap_or_default().to_owned()
    }

    /// `$request->input()`: every parameter, body entries replacing query ones.
    pub fn all(&self) -> Bag {
        let mut merged = self.body.clone();
        for (key, value) in &self.query {
            if find(&self.body, key).is_none() {
                merged.push((key.clone(), value.clone()));
            }
        }
        merged
    }
}

fn find<'a>(bag: &'a Bag, key: &str) -> Option<&'a Value> {
    bag.iter()
        .find(|(name, _)| name == key)
        .map(|(_, value)| value)
}

fn set(bag: &mut Bag, key: String, value: Value) {
    match bag.iter_mut().find(|(name, _)| *name == key) {
        Some(entry) => entry.1 = value,
        None => bag.push((key, value)),
    }
}

/// PHP's `parse_str`: `+` and percent decoding, `.` and space in names become `_`, a later
/// duplicate wins, `a[]` and `a[k]` build arrays.
pub fn parse_urlencoded(raw: &str) -> Bag {
    let mut bag = Bag::new();
    for pair in raw.split('&') {
        if pair.is_empty() {
            continue;
        }
        let (name, value) = pair.split_once('=').unwrap_or((pair, ""));
        let name = decode(name);
        let value = clean_value(&decode(value));
        let (base, sub) = match name.find('[') {
            Some(open) if open > 0 => {
                let rest = &name[open + 1..];
                (&name[..open], rest.find(']').map(|close| &rest[..close]))
            }
            _ => (name.as_str(), None),
        };
        let base: String = base
            .chars()
            .map(|c| if c == '.' || c == ' ' { '_' } else { c })
            .collect();
        match sub {
            None => set(&mut bag, base, value),
            Some("") => match bag.iter_mut().find(|(key, _)| *key == base) {
                Some((_, Value::List(items))) => items.push(value),
                Some((_, slot)) => *slot = Value::List(vec![value]),
                None => bag.push((base, Value::List(vec![value]))),
            },
            Some(key) => match bag.iter_mut().find(|(name, _)| *name == base) {
                Some((_, Value::Map(entries))) => set(entries, key.to_owned(), value),
                Some((_, slot)) => *slot = Value::Map(vec![(key.to_owned(), value)]),
                None => bag.push((base, Value::Map(vec![(key.to_owned(), value)]))),
            },
        }
    }
    bag
}

fn decode(raw: &str) -> String {
    let bytes = raw.as_bytes();
    let mut out = Vec::with_capacity(bytes.len());
    let mut index = 0;
    while index < bytes.len() {
        match bytes[index] {
            b'+' => out.push(b' '),
            b'%' if index + 2 < bytes.len()
                && hex(bytes[index + 1]).is_some()
                && hex(bytes[index + 2]).is_some() =>
            {
                out.push(
                    hex(bytes[index + 1]).unwrap_or(0) * 16 + hex(bytes[index + 2]).unwrap_or(0),
                );
                index += 2;
            }
            byte => out.push(byte),
        }
        index += 1;
    }
    String::from_utf8_lossy(&out).into_owned()
}

fn hex(byte: u8) -> Option<u8> {
    (byte as char)
        .to_digit(16)
        .and_then(|digit| u8::try_from(digit).ok())
}

/// `Str::trim` strips Unicode whitespace plus BOM, ZWSP and LRM.
pub fn trim(value: &str) -> &str {
    value.trim_matches(|c: char| {
        c.is_whitespace() || matches!(c, '\u{FEFF}' | '\u{200B}' | '\u{200E}')
    })
}

pub fn clean_value(value: &str) -> Value {
    let trimmed = trim(value);
    if trimmed.is_empty() {
        Value::Null
    } else {
        Value::Str(trimmed.to_owned())
    }
}

/// A JSON body as parameters, the way Laravel reads `$request->json()`.
pub fn from_json(json: &Json) -> Value {
    match json {
        Json::Null => Value::Null,
        Json::Bool(true) => Value::Str("1".to_owned()),
        Json::Bool(false) => Value::Null,
        Json::Number(number) => Value::Str(number.to_string()),
        Json::String(text) => clean_value(text),
        Json::Array(items) => Value::List(items.iter().map(from_json).collect()),
        Json::Object(entries) => Value::Map(
            entries
                .iter()
                .map(|(key, value)| (key.clone(), from_json(value)))
                .collect(),
        ),
    }
}

/// `ApiInputCanonicalizer`: list parameters collapse to a comma string and everything sorts by
/// name, so equal GET and QUERY filters compare equal.
pub fn canonicalize(bag: &Bag) -> Bag {
    let mut canonical: Bag = bag
        .iter()
        .map(|(key, value)| {
            let value = if key == "cat" {
                list_value(value)
            } else {
                canonical_value(value)
            };
            (key.clone(), value)
        })
        .collect();
    canonical.sort_by(|a, b| a.0.cmp(&b.0));
    canonical
}

fn list_value(value: &Value) -> Value {
    let items: Vec<String> = match value {
        Value::List(items) => items
            .iter()
            .map(|item| item.as_str().unwrap_or_default().to_owned())
            .collect(),
        Value::Str(text) => text.split(',').map(str::to_owned).collect(),
        _ => return Value::Null,
    };
    let kept: Vec<&str> = items
        .iter()
        .map(|item| item.trim())
        .filter(|item| !item.is_empty())
        .collect();
    if kept.is_empty() {
        Value::Null
    } else {
        Value::Str(kept.join(","))
    }
}

fn canonical_value(value: &Value) -> Value {
    match value {
        Value::Str(text) => {
            let text = text.trim();
            if text.is_empty() {
                Value::Null
            } else {
                Value::Str(text.to_owned())
            }
        }
        Value::Null => Value::Null,
        Value::List(items) => Value::List(items.iter().map(canonical_value).collect()),
        Value::Map(entries) => {
            let mut entries: Vec<(String, Value)> = entries
                .iter()
                .map(|(key, value)| (key.clone(), canonical_value(value)))
                .collect();
            entries.sort_by(|a, b| a.0.cmp(&b.0));
            Value::Map(entries)
        }
    }
}

/// PHP's `is_numeric`: optional surrounding whitespace, sign, digits, fraction, exponent.
pub fn is_numeric(value: &str) -> bool {
    numeric_prefix(value.trim_start_matches([' ', '\t', '\n', '\r', '\u{b}', '\u{c}'])).is_some_and(
        |(_, rest)| {
            rest.trim_end_matches([' ', '\t', '\n', '\r', '\u{b}', '\u{c}'])
                .is_empty()
        },
    )
}

/// The numeric prefix of a string and what follows it. `None` when it has no digits.
fn numeric_prefix(value: &str) -> Option<(&str, &str)> {
    let bytes = value.as_bytes();
    let mut end = usize::from(matches!(bytes.first(), Some(b'+' | b'-')));
    let int_start = end;
    while bytes.get(end).is_some_and(u8::is_ascii_digit) {
        end += 1;
    }
    let mut digits = end - int_start;
    if bytes.get(end) == Some(&b'.') {
        let fraction = end + 1;
        let mut stop = fraction;
        while bytes.get(stop).is_some_and(u8::is_ascii_digit) {
            stop += 1;
        }
        digits += stop - fraction;
        if digits > 0 {
            end = stop;
        }
    }
    if digits == 0 {
        return None;
    }
    if matches!(bytes.get(end), Some(b'e' | b'E')) {
        let mut stop = end + 1;
        if matches!(bytes.get(stop), Some(b'+' | b'-')) {
            stop += 1;
        }
        let exponent = stop;
        while bytes.get(stop).is_some_and(u8::is_ascii_digit) {
            stop += 1;
        }
        if stop > exponent {
            end = stop;
        }
    }
    Some(value.split_at(end))
}

/// PHP's `(int) $string`: the numeric prefix, truncated and saturated, or 0.
pub fn php_int(value: &str) -> i64 {
    let value = value.trim_start_matches([' ', '\t', '\n', '\r', '\u{b}', '\u{c}']);
    let Some((number, _)) = numeric_prefix(value) else {
        return 0;
    };
    if let Ok(parsed) = number.parse::<i64>() {
        return parsed;
    }
    number.parse::<f64>().map_or(0, |parsed| parsed as i64)
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn query_strings_parse_like_php() {
        let bag = parse_urlencoded("a=1&a=2&b.c=x+y&d[]=1&d[]=&e[k]=v&f=%20&g&=skip&h=%zz");
        assert_eq!(
            bag,
            vec![
                ("a".to_owned(), Value::Str("2".to_owned())),
                ("b_c".to_owned(), Value::Str("x y".to_owned())),
                (
                    "d".to_owned(),
                    Value::List(vec![Value::Str("1".to_owned()), Value::Null])
                ),
                (
                    "e".to_owned(),
                    Value::Map(vec![("k".to_owned(), Value::Str("v".to_owned()))])
                ),
                ("f".to_owned(), Value::Null),
                ("g".to_owned(), Value::Null),
                ("".to_owned(), Value::Str("skip".to_owned())),
                ("h".to_owned(), Value::Str("%zz".to_owned())),
            ]
        );
    }

    #[test]
    fn numbers_cast_like_php() {
        for (text, expected) in [
            ("12", 12),
            ("-3", -3),
            ("1.9", 1),
            ("1e3", 1000),
            ("5x", 5),
            ("abc", 0),
            ("", 0),
            (" 7", 7),
            (".5", 0),
            ("+4", 4),
        ] {
            assert_eq!(php_int(text), expected, "{text}");
        }
        for text in ["1", "-1", "1.5", ".5", "1e3", " 1", "1 "] {
            assert!(is_numeric(text), "{text}");
        }
        for text in ["", "abc", "1x", "0x1A", "."] {
            assert!(!is_numeric(text), "{text}");
        }
    }

    #[test]
    fn canonical_form_sorts_and_collapses_lists() {
        let bag = vec![
            ("q".to_owned(), Value::Str(" a ".to_owned())),
            ("cat".to_owned(), Value::Str("2000, 5030,".to_owned())),
            ("id".to_owned(), Value::Null),
        ];
        assert_eq!(
            canonicalize(&bag),
            vec![
                ("cat".to_owned(), Value::Str("2000,5030".to_owned())),
                ("id".to_owned(), Value::Null),
                ("q".to_owned(), Value::Str("a".to_owned())),
            ]
        );
    }
}

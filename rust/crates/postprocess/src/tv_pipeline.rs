//! The arguments `postprocess:guid` and `postprocess:tv-pipeline` share with PHP.

use anyhow::{Result, bail};
use nntmux_metadata::Renamed;

/// One GUID bucket: a single character, a-f or 0-9. Unlike the metadata crate's check, the
/// commands take no empty bucket.
pub fn check_guid_char(guid: &str) -> Result<()> {
    match guid.as_bytes() {
        [byte] if byte.is_ascii_digit() || (b'a'..=b'f').contains(byte) => Ok(()),
        _ => bail!("GUID character must be a-f or 0-9."),
    }
}

/// PHP's optional `renamed` argument. A numeric value picks the lookup mode: 0 or less skips the
/// pass (`None`), 2 is renamed releases only, and any other number takes every release. Anything
/// else, including no argument, uses the configured mode.
pub fn renamed_argument(argument: Option<&str>) -> Option<Renamed> {
    let Some(mode) = argument.and_then(php_numeric_int) else {
        return Some(Renamed::Configured);
    };
    match mode {
        ..=0 => None,
        2 => Some(Renamed::Only),
        _ => Some(Renamed::All),
    }
}

/// `(int)` of a string `is_numeric` accepts: optional surrounding whitespace, a sign, digits with
/// an optional fraction and exponent. The fraction truncates.
fn php_numeric_int(value: &str) -> Option<i64> {
    let trimmed = value.trim_matches([' ', '\t', '\n', '\r', '\x0B', '\x0C']);
    let number: f64 = trimmed.parse().ok()?;
    let plain = trimmed
        .trim_start_matches(['+', '-'])
        .chars()
        .all(|c| c.is_ascii_digit() || matches!(c, '.' | 'e' | 'E' | '+' | '-'));
    if !plain || !number.is_finite() {
        return None;
    }
    Some(number as i64)
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn buckets_are_one_hex_character() {
        for guid in ["a", "f", "0", "9"] {
            assert!(check_guid_char(guid).is_ok(), "{guid}");
        }
        for guid in ["", "g", "A", "ab", "-"] {
            assert!(check_guid_char(guid).is_err(), "{guid}");
        }
    }

    #[test]
    fn the_renamed_argument_reads_as_php_did() {
        assert_eq!(renamed_argument(None), Some(Renamed::Configured));
        assert_eq!(renamed_argument(Some("")), Some(Renamed::Configured));
        assert_eq!(renamed_argument(Some("true")), Some(Renamed::Configured));
        assert_eq!(renamed_argument(Some("0")), None);
        assert_eq!(renamed_argument(Some("-1")), None);
        assert_eq!(renamed_argument(Some("1")), Some(Renamed::All));
        assert_eq!(renamed_argument(Some("2")), Some(Renamed::Only));
        assert_eq!(renamed_argument(Some(" 2 ")), Some(Renamed::Only));
        assert_eq!(renamed_argument(Some("2.0")), Some(Renamed::Only));
        assert_eq!(renamed_argument(Some("3")), Some(Renamed::All));
        assert_eq!(renamed_argument(Some("inf")), Some(Renamed::Configured));
        assert_eq!(renamed_argument(Some("0x2")), Some(Renamed::Configured));
    }
}

//! From MariaDB values to SQLite values, plus the exact form rows are compared in.

use anyhow::{Result, anyhow, bail};
use indexer_store::rusqlite::types::Value as SqlValue;
use mysql_async::Value;

use crate::spec::{Column, Kind};

pub type SqlRow = Vec<SqlValue>;

/// A row or NZB that was kept but can't be vouched for.
#[derive(Clone, Debug, PartialEq, Eq)]
pub struct Anomaly {
    pub release_id: Option<i64>,
    pub kind: &'static str,
    pub detail: String,
}

fn parse_int(bytes: &[u8]) -> Result<i64> {
    let text = std::str::from_utf8(bytes)?;
    // A DECIMAL with an empty fraction, which is how a timestamp can arrive.
    let whole = text.split_once('.').map_or(text, |(whole, fraction)| {
        if fraction.bytes().all(|b| b == b'0') {
            whole
        } else {
            text
        }
    });
    whole
        .parse()
        .map_err(|_| anyhow!("{text:?} is not an integer"))
}

/// Converts one value. The flag is set when text had to stay bytes.
pub fn to_sql(value: Value, kind: Kind) -> Result<(SqlValue, bool)> {
    let converted = match (value, kind) {
        (Value::NULL, _) => SqlValue::Null,
        (Value::Int(v), Kind::Int) => SqlValue::Integer(v),
        (Value::UInt(v), Kind::Int) => SqlValue::Integer(i64::try_from(v)?),
        (Value::Bytes(v), Kind::Int) => SqlValue::Integer(parse_int(&v)?),
        (Value::Double(v), Kind::Real) => SqlValue::Real(v),
        (Value::Float(v), Kind::Real) => SqlValue::Real(f64::from(v)),
        (Value::Int(v), Kind::Real) => SqlValue::Real(v as f64),
        (Value::UInt(v), Kind::Real) => SqlValue::Real(v as f64),
        (Value::Bytes(v), Kind::Real) => SqlValue::Real(std::str::from_utf8(&v)?.parse()?),
        (Value::Bytes(v), Kind::Text) => match String::from_utf8(v) {
            Ok(text) => SqlValue::Text(text),
            Err(error) => return Ok((SqlValue::Blob(error.into_bytes()), true)),
        },
        (Value::Bytes(v), Kind::Blob) => SqlValue::Blob(v),
        (value, kind) => bail!("can't store {value:?} as {kind:?}"),
    };
    Ok((converted, false))
}

/// Converts a row, noting any text that had to stay bytes.
pub fn row_to_sql(
    dest: &str,
    columns: &[Column],
    values: Vec<Value>,
    anomalies: &mut Vec<Anomaly>,
) -> Result<SqlRow> {
    if values.len() != columns.len() {
        bail!(
            "{dest}: got {} columns, expected {}",
            values.len(),
            columns.len()
        );
    }
    let mut row = Vec::with_capacity(columns.len());
    let mut not_utf8 = Vec::new();
    for (value, column) in values.into_iter().zip(columns) {
        let (converted, kept_bytes) = to_sql(value, column.kind)
            .map_err(|error| error.context(format!("{dest}.{}", column.dest)))?;
        if kept_bytes {
            not_utf8.push(column.dest);
        }
        row.push(converted);
    }
    if !not_utf8.is_empty() {
        let release_id = match row.first() {
            Some(SqlValue::Integer(id)) => Some(*id),
            _ => None,
        };
        anomalies.push(Anomaly {
            release_id,
            kind: "text_not_utf8",
            detail: format!("{dest}: {}", not_utf8.join(", ")),
        });
    }
    Ok(row)
}

/// Encodes a row so two rows are equal only when every value is exactly equal.
pub fn canonical(row: &[SqlValue]) -> Vec<u8> {
    let mut out = Vec::new();
    for value in row {
        match value {
            SqlValue::Null => out.push(0),
            SqlValue::Integer(v) => {
                out.push(1);
                out.extend_from_slice(&v.to_be_bytes());
            }
            SqlValue::Real(v) => {
                out.push(2);
                out.extend_from_slice(&v.to_bits().to_be_bytes());
            }
            SqlValue::Text(v) => {
                out.push(3);
                out.extend_from_slice(&(v.len() as u64).to_be_bytes());
                out.extend_from_slice(v.as_bytes());
            }
            SqlValue::Blob(v) => {
                out.push(4);
                out.extend_from_slice(&(v.len() as u64).to_be_bytes());
                out.extend_from_slice(v);
            }
        }
    }
    out
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn integers_survive_every_wire_form() {
        for value in [
            Value::Int(42),
            Value::UInt(42),
            Value::Bytes(b"42".to_vec()),
        ] {
            assert_eq!(to_sql(value, Kind::Int).unwrap().0, SqlValue::Integer(42));
        }
        let timestamp = Value::Bytes(b"1793475000.000000".to_vec());
        assert_eq!(
            to_sql(timestamp, Kind::Int).unwrap().0,
            SqlValue::Integer(1_793_475_000)
        );
        assert!(to_sql(Value::Bytes(b"1.5".to_vec()), Kind::Int).is_err());
        assert!(to_sql(Value::UInt(u64::MAX), Kind::Int).is_err());
    }

    #[test]
    fn nulls_stay_null_and_dates_are_refused() {
        assert_eq!(to_sql(Value::NULL, Kind::Text).unwrap().0, SqlValue::Null);
        assert!(to_sql(Value::Date(2026, 1, 1, 0, 0, 0, 0), Kind::Int).is_err());
    }

    #[test]
    fn a_60_gb_size_fits() {
        let size = 64_424_509_440_u64;
        assert_eq!(
            to_sql(Value::UInt(size), Kind::Int).unwrap().0,
            SqlValue::Integer(size as i64)
        );
    }

    #[test]
    fn invalid_utf8_keeps_its_exact_bytes_and_is_reported() {
        let columns = [
            Column {
                source: "a",
                dest: "release_id",
                kind: Kind::Int,
            },
            Column {
                source: "b",
                dest: "name",
                kind: Kind::Text,
            },
        ];
        let mut anomalies = Vec::new();
        let row = row_to_sql(
            "release_files",
            &columns,
            vec![Value::Int(9), Value::Bytes(vec![0xff, b'a'])],
            &mut anomalies,
        )
        .unwrap();
        assert_eq!(row[1], SqlValue::Blob(vec![0xff, b'a']));
        assert_eq!(anomalies[0].release_id, Some(9));
        assert_eq!(anomalies[0].kind, "text_not_utf8");
    }

    #[test]
    fn a_short_row_is_an_error() {
        let columns = [Column {
            source: "a",
            dest: "x",
            kind: Kind::Int,
        }];
        assert!(row_to_sql("t", &columns, Vec::new(), &mut Vec::new()).is_err());
    }

    #[test]
    fn canonical_tells_apart_what_looks_alike() {
        let text = |s: &str| SqlValue::Text(s.to_owned());
        assert_ne!(
            canonical(&[text("ab"), text("c")]),
            canonical(&[text("a"), text("bc")])
        );
        assert_ne!(canonical(&[text("1")]), canonical(&[SqlValue::Integer(1)]));
        assert_ne!(canonical(&[text("")]), canonical(&[SqlValue::Null]));
        assert_ne!(
            canonical(&[text("a")]),
            canonical(&[SqlValue::Blob(b"a".to_vec())])
        );
        assert_eq!(
            canonical(&[SqlValue::Real(1.5)]),
            canonical(&[SqlValue::Real(1.5)])
        );
    }
}

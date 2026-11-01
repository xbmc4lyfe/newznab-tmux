//! Generic row reading, for models PHP serializes column by column.

use anyhow::{Result, bail};
use chrono::{NaiveDate, NaiveDateTime};
use sqlx::mysql::MySqlRow;
use sqlx::{Column, Row, TypeInfo, ValueRef};

use crate::json::J;

/// A column as `json_encode` would see it after PDO: integers and floats typed, the rest strings.
pub fn column_json(row: &MySqlRow, index: usize) -> Result<J> {
    if row.try_get_raw(index)?.is_null() {
        return Ok(J::Null);
    }
    let kind = row.column(index).type_info().name().to_owned();
    Ok(match kind.as_str() {
        "BOOLEAN" => J::Int(i64::from(row.try_get::<bool, _>(index)?)),
        "TINYINT" | "SMALLINT" | "MEDIUMINT" | "INT" | "BIGINT" => J::Int(row.try_get(index)?),
        "TINYINT UNSIGNED" | "SMALLINT UNSIGNED" | "MEDIUMINT UNSIGNED" | "INT UNSIGNED"
        | "BIGINT UNSIGNED" => J::Int(i64::try_from(row.try_get::<u64, _>(index)?)?),
        "FLOAT" => J::Float(f64::from(row.try_get::<f32, _>(index)?)),
        "DOUBLE" => J::Float(row.try_get(index)?),
        "DATETIME" | "TIMESTAMP" => J::Str(
            row.try_get::<NaiveDateTime, _>(index)?
                .format("%Y-%m-%d %H:%M:%S")
                .to_string(),
        ),
        "DATE" => J::Str(
            row.try_get::<NaiveDate, _>(index)?
                .format("%Y-%m-%d")
                .to_string(),
        ),
        "VARCHAR" | "CHAR" | "TEXT" | "TINYTEXT" | "MEDIUMTEXT" | "LONGTEXT" | "ENUM" => {
            J::Str(row.try_get(index)?)
        }
        other => bail!("no JSON form for column type {other}"),
    })
}

/// Every column of a row, in select order.
pub fn row_json(row: &MySqlRow) -> Result<J> {
    let mut entries = Vec::with_capacity(row.columns().len());
    for (index, column) in row.columns().iter().enumerate() {
        entries.push((column.name().to_owned(), column_json(row, index)?));
    }
    Ok(J::Obj(entries))
}

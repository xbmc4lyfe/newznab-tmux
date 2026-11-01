//! Comparing live tables with the registry, from `manticore:inspect`, and creating them, from
//! `manticore:create-indexes` and `nntmux:create-media-indexes`.

use std::collections::{BTreeMap, HashMap};
use std::sync::LazyLock;

use regex::Regex;
use serde::Serialize;
use serde_json::{Map, Value};

use crate::config::LOGICAL_INDEXES;
use crate::registry::{columns, create_table_sql};
use crate::{SearchClient, SearchError};

/// Manticore omits defaults and no-op settings from `SHOW TABLE ... SETTINGS`, so these two are
/// the ones that can be checked.
const INSPECTABLE_SETTINGS: [(&str, &str); 2] =
    [("min_infix_len", "2"), ("index_field_lengths", "1")];

#[derive(Clone, Debug, Default, PartialEq, Eq, Serialize)]
pub struct Incompatible {
    pub expected: String,
    pub actual: String,
}

#[derive(Clone, Debug, Default, PartialEq, Eq, Serialize)]
pub struct TableReport {
    pub missing: Vec<String>,
    pub incompatible: BTreeMap<String, Incompatible>,
    pub missing_settings: Vec<String>,
    pub needs_rebuild: bool,
    #[serde(skip_serializing_if = "Option::is_none")]
    pub error: Option<String>,
}

#[derive(Clone, Debug, Default, PartialEq, Eq, Serialize)]
pub struct InspectReport {
    pub version: String,
    pub compatible: bool,
    pub tables: BTreeMap<String, TableReport>,
}

fn safe_name(table: &str) -> bool {
    !table.is_empty()
        && table
            .bytes()
            .all(|byte| byte.is_ascii_alphanumeric() || byte == b'_')
}

fn text<'a>(row: &'a Map<String, Value>, names: &[&str]) -> Option<&'a str> {
    names
        .iter()
        .find_map(|name| row.get(*name).and_then(Value::as_str))
}

fn types_compatible(actual: &str, expected: &str) -> bool {
    if expected == "integer" {
        actual.contains("integer") || actual.contains("uint")
    } else {
        actual.contains(expected)
    }
}

fn compare_columns(actual: &[Map<String, Value>], logical: &str, report: &mut TableReport) {
    let types: HashMap<String, String> = actual
        .iter()
        .filter_map(|row| {
            Some((
                text(row, &["Field", "field"])?.to_owned(),
                text(row, &["Type", "type"])
                    .unwrap_or_default()
                    .to_lowercase(),
            ))
        })
        .collect();
    for (name, expected) in columns(logical) {
        match types.get(*name) {
            None => report.missing.push((*name).to_owned()),
            Some(actual) if !types_compatible(actual, expected) => {
                report.incompatible.insert(
                    (*name).to_owned(),
                    Incompatible {
                        expected: (*expected).to_owned(),
                        actual: actual.clone(),
                    },
                );
            }
            Some(_) => {}
        }
    }
}

/// Reads settings from the `settings` row's text, or from name and value rows.
fn missing_settings(rows: &[Map<String, Value>]) -> Vec<String> {
    static LINE: LazyLock<Regex> = LazyLock::new(|| {
        Regex::new(r#"(?i)^\s*([a-z0-9_]+)\s*=\s*['"]?([^'"]+?)['"]?\s*$"#).expect("valid pattern")
    });
    let mut settings = HashMap::new();
    for row in rows {
        let name = text(
            row,
            &[
                "Variable_name",
                "variable_name",
                "Setting_name",
                "Name",
                "name",
            ],
        );
        let value = row.get("Value").or_else(|| row.get("value"));
        let (Some(name), Some(value)) = (name, value) else {
            continue;
        };
        let value = value
            .as_str()
            .map_or_else(|| value.to_string(), str::to_owned);
        if name.eq_ignore_ascii_case("settings") {
            for line in value.lines() {
                if let Some(found) = LINE.captures(line) {
                    settings.insert(found[1].to_lowercase(), found[2].trim().to_lowercase());
                }
            }
        } else {
            settings.insert(name.to_lowercase(), value.to_lowercase());
        }
    }
    INSPECTABLE_SETTINGS
        .iter()
        .filter(|(name, expected)| settings.get(*name).map(String::as_str) != Some(*expected))
        .map(|(name, _)| (*name).to_owned())
        .collect()
}

fn extract_version(rows: &[Map<String, Value>]) -> String {
    static VERSION: LazyLock<Regex> =
        LazyLock::new(|| Regex::new(r"\d+\.\d+(?:\.\d+)?").expect("valid pattern"));
    let encoded = serde_json::to_string(rows).unwrap_or_default();
    VERSION
        .find(&encoded)
        .map_or_else(|| "unknown".to_owned(), |found| found.as_str().to_owned())
}

impl SearchClient {
    pub async fn inspect(&self) -> Result<InspectReport, SearchError> {
        let version = self.sql("SELECT VERSION() AS version").await?;
        let mut report = InspectReport {
            version: extract_version(&version),
            compatible: true,
            tables: BTreeMap::new(),
        };
        for logical in LOGICAL_INDEXES {
            let table = self.config.indexes.table(logical).to_owned();
            let inspected = self.inspect_table(&table, logical).await;
            let status = inspected.unwrap_or_else(|error| TableReport {
                needs_rebuild: true,
                error: Some(error.to_string()),
                ..TableReport::default()
            });
            report.compatible &= !status.needs_rebuild;
            report.tables.insert(table, status);
        }
        Ok(report)
    }

    async fn inspect_table(&self, table: &str, logical: &str) -> Result<TableReport, SearchError> {
        if !safe_name(table) {
            return Err(SearchError::InvalidArgument("unsafe configured table name"));
        }
        let mut status = TableReport::default();
        compare_columns(
            &self.sql(&format!("DESCRIBE {table}")).await?,
            logical,
            &mut status,
        );
        status.missing_settings =
            missing_settings(&self.sql(&format!("SHOW TABLE {table} SETTINGS")).await?);
        status.needs_rebuild = !status.missing.is_empty()
            || !status.incompatible.is_empty()
            || !status.missing_settings.is_empty();
        Ok(status)
    }

    /// Creates the named logical indexes, or every index when `selected` is empty. An index that
    /// exists stays as it is unless `drop_existing` is set. Returns one line per table, and an
    /// error once all have been tried if any failed.
    pub async fn create_indexes(
        &self,
        selected: &[String],
        drop_existing: bool,
    ) -> Result<Vec<String>, SearchError> {
        let unknown: Vec<&str> = selected
            .iter()
            .map(String::as_str)
            .filter(|name| !LOGICAL_INDEXES.contains(name))
            .collect();
        if !unknown.is_empty() {
            return Err(SearchError::Query(format!(
                "unknown logical index: {}",
                unknown.join(", ")
            )));
        }
        let mut lines = Vec::new();
        let mut failed = false;
        for logical in LOGICAL_INDEXES {
            if !selected.is_empty() && !selected.iter().any(|name| name == logical) {
                continue;
            }
            let table = self.config.indexes.table(logical);
            if !safe_name(table) {
                return Err(SearchError::InvalidArgument("unsafe configured table name"));
            }
            if drop_existing {
                self.sql(&format!("DROP TABLE IF EXISTS {table}")).await?;
                lines.push(format!("Dropped {table}."));
            }
            match self.sql(&create_table_sql(table, logical)).await {
                Ok(_) => lines.push(format!("Created {table}.")),
                Err(error) if error.to_string().contains("already exists") => lines.push(format!(
                    "{table} already exists; use --drop during a maintenance rebuild."
                )),
                Err(error) if error.to_string().contains("data_dir") => {
                    failed = true;
                    lines.push(format!(
                        "Failed to create {table}: configure a writable Manticore data_dir."
                    ));
                }
                Err(error) => {
                    failed = true;
                    lines.push(format!("Failed to create {table}: {error}"));
                }
            }
        }
        if failed {
            return Err(SearchError::Query(lines.join("\n")));
        }
        Ok(lines)
    }
}

#[cfg(test)]
mod tests {
    use serde_json::json;

    use super::*;

    fn rows(value: Value) -> Vec<Map<String, Value>> {
        value
            .as_array()
            .unwrap()
            .iter()
            .map(|row| row.as_object().unwrap().clone())
            .collect()
    }

    #[test]
    fn settings_read_from_text_or_rows() {
        let text = rows(json!([{"Variable_name": "settings",
            "Value": "min_infix_len = 2\nindex_field_lengths = 1\nrt_mem_limit = 1"}]));
        assert!(missing_settings(&text).is_empty());
        let wrong = rows(json!([{"Variable_name": "settings", "Value": "min_infix_len = '3'"}]));
        assert_eq!(
            missing_settings(&wrong),
            ["min_infix_len", "index_field_lengths"]
        );
        let named = rows(json!([{"Name": "min_infix_len", "Value": 2},
            {"Name": "index_field_lengths", "Value": "1"}]));
        assert!(missing_settings(&named).is_empty());
    }

    #[test]
    fn integers_accept_either_spelling() {
        assert!(types_compatible("uint", "integer"));
        assert!(types_compatible("integer", "integer"));
        assert!(!types_compatible("string", "integer"));
        assert!(types_compatible("text indexed stored", "text"));
    }

    #[test]
    fn versions_come_out_of_the_row() {
        let found = rows(json!([{"version": "28.4.4 abc@260101 dev"}]));
        assert_eq!(extract_version(&found), "28.4.4");
        assert_eq!(extract_version(&[]), "unknown");
    }
}

//! Request parameters both API versions parse the same way.

use crate::input::{Input, Value, is_numeric, php_int};
use crate::settings::Settings;

/// `ApiQueryParameters::limit`: clamped to the advertised range, the default when absent.
pub fn limit(input: &Input, settings: &Settings) -> i64 {
    match input.str("limit") {
        Some(value) if is_numeric(value) => php_int(value).clamp(1, settings.limit_max()),
        _ => settings.limit_default(),
    }
}

/// `ApiQueryParameters::offset`.
pub fn offset(input: &Input) -> i64 {
    match input.str("offset") {
        Some(value) if is_numeric(value) => php_int(value).max(0),
        _ => 0,
    }
}

/// `ApiQueryParameters::minimumSize`.
pub fn minimum_size(input: &Input) -> i64 {
    match input.str("minsize") {
        Some(value) if is_numeric(value) => php_int(value).max(0),
        _ => 0,
    }
}

/// The sort parameter: `Ok` holds the normalized order, `Err` the error text.
pub fn sort(input: &Input) -> Result<String, &'static str> {
    if !input.has("sort") {
        return Ok("posted_desc".to_owned());
    }
    let sort = input.string("sort").trim().to_lowercase();
    if sort.is_empty() {
        return Err("Incorrect parameter (sort must not be empty)");
    }
    let valid = sort.split_once('_').is_some_and(|(field, direction)| {
        matches!(
            field,
            "cat" | "name" | "size" | "files" | "stats" | "posted"
        ) && matches!(direction, "asc" | "desc")
    });
    if !valid {
        return Err(
            "Incorrect parameter (sort must be one of: cat_asc/desc, name_asc/desc, size_asc/desc, files_asc/desc, stats_asc/desc, posted_asc/desc)",
        );
    }
    Ok(sort)
}

/// The maxage parameter: `Ok` holds the age or -1, `Err` the error text.
pub fn max_age(input: &Input) -> Result<i64, &'static str> {
    let Some(value) = input.get("maxage") else {
        return Ok(-1);
    };
    if !value.is_filled() {
        return Err("Incorrect parameter (maxage must not be empty)");
    }
    match value {
        Value::Str(text) if is_numeric(text) => Ok(php_int(text)),
        _ => Err("Incorrect parameter (maxage must be numeric)"),
    }
}

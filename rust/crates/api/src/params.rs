//! Request parameters both API versions parse the same way.

use anyhow::Result;
use nntmux_runtime::Ctx;

use crate::input::{Input, Value, decode, is_numeric, php_int};
use crate::search::Categories;
use crate::settings::Settings;

/// `ApiQueryParameters::limit`: clamped to the advertised range, the default when absent.
pub fn limit(input: &Input, settings: &Settings) -> i64 {
    match input.str("limit") {
        Some(value) if is_numeric(value) => php_int(value).clamp(1, settings.limit_max()),
        _ => settings.limit_default(),
    }
}

/// `ApiQueryParameters::offset`, capped where Manticore would truncate it to 32 bits.
pub fn offset(input: &Input) -> i64 {
    match input.str("offset") {
        Some(value) if is_numeric(value) => php_int(value).clamp(0, i64::from(i32::MAX)),
        _ => 0,
    }
}

/// `ApiQueryParameters::categories`: `-1` when absent, and HD TV brings WEB-DL along unless the
/// site files that separately.
pub async fn categories(ctx: &Ctx, input: &Input) -> Result<Categories> {
    let value = match input.get("cat") {
        Some(Value::Str(text)) => decode(text.trim()),
        Some(Value::List(items)) => items
            .iter()
            .filter_map(Value::as_str)
            .map(|item| decode(item.trim()))
            .filter(|item| !item.is_empty())
            .collect::<Vec<_>>()
            .join(","),
        _ => return Ok(Categories::unfiltered()),
    };
    if value.is_empty() {
        return Ok(Categories::unfiltered());
    }
    let mut value = value;
    if value.contains("5040") && !value.contains("5010") {
        let separate: Option<bool> =
            sqlx::query_scalar("SELECT categorize_web_dl FROM ingestion_configurations LIMIT 1")
                .fetch_optional(&ctx.db)
                .await?;
        if !separate.unwrap_or(false) {
            value.push_str(",5010");
        }
    }
    Ok(Categories(
        value
            .split(',')
            .map(str::trim)
            .filter(|id| !id.is_empty())
            .map(str::to_owned)
            .collect(),
    ))
}

/// `ApiQueryParameters::group`: a valid name with `a.b.` expanded, or nothing.
pub fn group(input: &Input) -> Option<String> {
    let name = input.str("group")?;
    let mut parts = name.split('.');
    let valid = name.contains('.')
        && parts.all(|part| {
            !part.is_empty()
                && part
                    .chars()
                    .all(|c| c.is_ascii_alphanumeric() || c == '_' || c == '-')
        });
    if !valid {
        return None;
    }
    let lower = name.to_ascii_lowercase();
    Some(if lower.starts_with("a.b.") {
        format!("alt.binaries.{}", &name[4..])
    } else {
        name.to_owned()
    })
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

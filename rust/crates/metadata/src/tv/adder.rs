//! Adds a show by its ID on another site. Port of `TvShowAdder`, for the admin's add-show page.

use std::sync::Arc;

use anyhow::{Result, anyhow, bail};
use nntmux_runtime::Ctx;
use serde_json::{Value, json};
use sqlx::MySqlPool;
use tracing::{info, warn};

use super::providers::{TmdbProvider, TraktProvider, TvMazeProvider, TvdbProvider};
use super::store::{self, Show};
use crate::php;
use crate::services::Services;

/// What adding a show did.
#[derive(Clone, Debug, PartialEq, Eq)]
pub struct AddedShow {
    pub video_id: i64,
    pub existed: bool,
    pub source: String,
    pub external_id: String,
    pub title: String,
}

fn normalize_id(source: &str, id: &str) -> Result<String> {
    let id = php::trim(id);
    let digits_only = !id.is_empty() && id.bytes().all(|byte| byte.is_ascii_digit());
    if source == "imdb" {
        let lower = id.to_ascii_lowercase();
        let stripped = lower
            .strip_prefix("tt")
            .or_else(|| lower.strip_prefix('t'))
            .filter(|rest| {
                (5..=10).contains(&rest.len()) && rest.bytes().all(|byte| byte.is_ascii_digit())
            });
        if let Some(stripped) = stripped {
            return Ok(stripped.to_owned());
        }
        if digits_only {
            return Ok(id.to_owned());
        }
        bail!("IMDB ID must look like \"tt1234567\" or its numeric part.");
    }
    if !digits_only || id.parse::<i64>().map_or(true, |id| id <= 0) {
        bail!("{} ID must be a positive integer.", source.to_uppercase());
    }
    Ok(id.to_owned())
}

fn column_for(source: &str) -> Result<&'static str> {
    Ok(match source {
        "tvdb" => "tvdb",
        "tvmaze" => "tvmaze",
        "tmdb" => "tmdb",
        "trakt" => "trakt",
        "imdb" => "imdb",
        _ => bail!("Unsupported external source: {source}"),
    })
}

async fn existing(db: &MySqlPool, column: &str, id: &str) -> Result<Option<(i64, String)>> {
    let statement =
        format!("SELECT CAST(id AS SIGNED), title FROM videos WHERE {column} = ? LIMIT 1");
    Ok(sqlx::query_as(sqlx::AssertSqlSafe(statement))
        .bind(id)
        .fetch_optional(db)
        .await?)
}

async fn from_tvdb(services: &Arc<Services>, id: i64) -> Result<Option<Show>> {
    let extended = match services.tvdb.series_extended(id).await {
        Ok(extended) => extended,
        Err(failure) => {
            warn!(id, error = %failure, "TVDB lookup failed");
            None
        }
    };
    let Some(extended) = extended else {
        return Ok(None);
    };
    // The show formatter expects the search result's fields, so adapt the extended record.
    let aliases: Vec<String> = php::items(extended.get("aliases"))
        .iter()
        .filter_map(|alias| match alias {
            Value::String(alias) => Some(alias.clone()),
            other => other.get("name").and_then(Value::as_str).map(str::to_owned),
        })
        .collect();
    let adapted = json!({
        "tvdb_id": extended.get("id").cloned().unwrap_or_else(|| json!(id)),
        "name": php::text(extended.get("name")),
        "overview": php::text(extended.get("overview")),
        "first_air_time": php::text(extended.get("firstAired")),
        "aliases": aliases,
    });
    Ok(Some(
        TvdbProvider(services.clone()).format_show(&adapted).await?,
    ))
}

async fn from_tvmaze(
    services: &Arc<Services>,
    id: Option<i64>,
    imdb: Option<&str>,
) -> Option<Show> {
    let found = match (id, imdb) {
        (Some(id), _) => services.tvmaze.show_by_id(id).await,
        (None, Some(imdb)) => services.tvmaze.lookup("imdb", imdb).await,
        (None, None) => return None,
    };
    let show = match found {
        Ok(show) => show?,
        Err(failure) => {
            warn!(error = %failure, "TVMaze lookup failed");
            return None;
        }
    };
    if !php::present(Some(&show.id)) {
        return None;
    }
    Some(TvMazeProvider(services.clone()).format_show(&show).await)
}

async fn from_tmdb(services: &Arc<Services>, id: i64) -> Result<Option<Show>> {
    if !services.tmdb.is_configured() {
        bail!("TMDB API is not configured.");
    }
    let Some(details) = services
        .tmdb
        .tv_show(id, &["external_ids", "alternative_titles"])
        .await?
    else {
        return Ok(None);
    };
    let provider = TmdbProvider::new(services.clone());
    Ok(Some(provider.format_show(&details, &details).await))
}

async fn from_trakt(services: &Arc<Services>, id: &str) -> Result<Option<Show>> {
    if !services.trakt.is_configured() {
        bail!("Trakt API is not configured.");
    }
    let show = services.trakt.show_summary(id).await?;
    let Some(show) = show.filter(|show| php::present(show.get("ids"))) else {
        return Ok(None);
    };
    Ok(Some(
        TraktProvider::new(services.clone())
            .format_show(&show)
            .await,
    ))
}

/// IMDb has no API here, so the chain is TMDB, then Trakt, then TVMaze.
async fn from_imdb(services: &Arc<Services>, imdb: &str) -> Option<Show> {
    if services.tmdb.is_configured() {
        match services.tmdb.find_tv(&format!("tt{imdb}"), "imdb_id").await {
            Ok(Some(found)) => {
                let id = php::int(found.get("id"));
                if id > 0 {
                    match from_tmdb(services, id).await {
                        Ok(Some(show)) => return Some(show),
                        Ok(None) => {}
                        Err(failure) => info!(imdb, error = %failure, "IMDB to TMDB failed"),
                    }
                }
            }
            Ok(None) => {}
            Err(failure) => info!(imdb, error = %failure, "IMDB to TMDB failed"),
        }
    }
    if services.trakt.is_configured() {
        match services.trakt.lookup_show_ids(imdb, "imdb").await {
            Ok(Some(ids)) if ids.trakt != 0 => {
                match from_trakt(services, &ids.trakt.to_string()).await {
                    Ok(Some(show)) => return Some(show),
                    Ok(None) => {}
                    Err(failure) => info!(imdb, error = %failure, "IMDB to Trakt failed"),
                }
            }
            Ok(_) => {}
            Err(failure) => info!(imdb, error = %failure, "IMDB to Trakt failed"),
        }
    }
    from_tvmaze(services, None, Some(&format!("tt{imdb}"))).await
}

async fn resolve(services: &Arc<Services>, source: &str, id: &str) -> Result<Option<Show>> {
    let number = || id.parse::<i64>().unwrap_or(0);
    match source {
        "tvdb" => from_tvdb(services, number()).await,
        "tvmaze" => Ok(from_tvmaze(services, Some(number()), None).await),
        "tmdb" => from_tmdb(services, number()).await,
        "trakt" => from_trakt(services, id).await,
        "imdb" => Ok(from_imdb(services, id).await),
        _ => bail!("Unsupported external source: {source}"),
    }
}

/// The show an ID resolves to, without storing it, for a preview.
pub async fn preview_show(ctx: &Ctx, source: &str, id: &str) -> Result<Option<Show>> {
    let source = php::trim(source).to_lowercase();
    let id = normalize_id(&source, id)?;
    resolve(&Services::shared(ctx)?, &source, &id).await
}

/// Resolves an ID on `tvdb`, `tvmaze`, `tmdb`, `trakt` or `imdb`, and stores the show. `kind` is the
/// `videos.type`: 0 for TV, 1 for film, 2 for anime.
pub async fn add_show_by_external_id(
    ctx: &Ctx,
    source: &str,
    id: &str,
    kind: i64,
) -> Result<AddedShow> {
    add_with_services(&Services::shared(ctx)?, source, id, kind).await
}

pub async fn add_with_services(
    services: &Arc<Services>,
    source: &str,
    id: &str,
    kind: i64,
) -> Result<AddedShow> {
    let source = php::trim(source).to_lowercase();
    let id = normalize_id(&source, id)?;
    let column = column_for(&source)?;
    if let Some((video_id, title)) = existing(&services.db, column, &id).await? {
        return Ok(AddedShow {
            video_id,
            existed: true,
            source,
            external_id: id,
            title,
        });
    }
    let mut show = resolve(services, &source, &id).await?.ok_or_else(|| {
        if source == "imdb" {
            anyhow!("No show found for IMDB ID tt{id} via TMDB, Trakt, or TVMaze.")
        } else {
            anyhow!("No {} show found for ID {id}.", source.to_uppercase())
        }
    })?;
    if php::is_empty(&show.title) {
        bail!("Provider returned a show without a title.");
    }
    show.kind = kind;
    let video_id = store::add_show(services, &show).await?;
    if video_id > 0 {
        for url in [&show.poster, &show.fallback_poster] {
            match store::save_poster(services, video_id, url).await {
                Ok(true) => break,
                Ok(false) => {}
                Err(failure) => {
                    info!(
                        video_id,
                        error = format!("{failure:#}"),
                        "poster fetch failed"
                    );
                    break;
                }
            }
        }
    }
    Ok(AddedShow {
        video_id,
        existed: false,
        source,
        external_id: id,
        title: show.title,
    })
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn ids_normalize() {
        assert_eq!(normalize_id("imdb", "tt0944947").unwrap(), "0944947");
        assert_eq!(normalize_id("imdb", "t0944947").unwrap(), "0944947");
        assert_eq!(normalize_id("imdb", " 0944947 ").unwrap(), "0944947");
        assert!(normalize_id("imdb", "abc").is_err());
        assert_eq!(normalize_id("tvdb", "12").unwrap(), "12");
        assert!(normalize_id("tvdb", "0").is_err());
        assert!(normalize_id("tvdb", "tt12").is_err());
    }
}

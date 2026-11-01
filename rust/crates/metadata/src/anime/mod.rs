//! Matches anime releases to AniList. Port of `AnimeProcessor` and `PopulateAniListService`.

pub mod commands;
pub mod title;

use std::collections::HashMap;
use std::sync::Arc;

use anyhow::Result;
use nntmux_runtime::Ctx;
use serde_json::Value;
use sqlx::Row;
use tracing::{error, info, warn};

use crate::candidates::{SETTLED, release_scope};
use crate::images::Profile;
use crate::php;
use crate::services::Services;
use crate::settings::MetadataSettings;
use crate::tv::check_guid_char;
use title::extract_title;

/// The title or episode couldn't be extracted from the searchname.
const PROC_EXTFAIL: i64 = -1;
/// No AniList id was found for the extracted title.
const PROC_NOMATCH: i64 = -2;
const TV_ANIME: i64 = 5070;
const KIND: &str = "anime";

fn date_of(value: Option<&Value>) -> Option<String> {
    let value = value?;
    let part = |key: &str| value.get(key).and_then(Value::as_i64);
    Some(format!(
        "{:04}-{:02}-{:02}",
        part("year")?,
        part("month")?,
        part("day")?
    ))
}

fn joined_names<'a>(names: impl Iterator<Item = &'a Value>) -> String {
    names
        .map(|name| php::text(Some(name)))
        .filter(|name| !php::is_empty(name))
        .collect::<Vec<_>>()
        .join(", ")
}

/// The file name in a URL's path, which `anidb_info.picture` stores.
fn picture_name(url: &str) -> String {
    let path = reqwest::Url::parse(url)
        .map(|url| url.path().to_owned())
        .unwrap_or_default();
    path.rsplit('/').next().unwrap_or_default().to_owned()
}

fn relation_lists(media: &Value) -> (String, String) {
    let (mut related, mut similar) = (Vec::new(), Vec::new());
    for edge in php::items(
        media
            .get("relations")
            .and_then(|relations| relations.get("edges")),
    ) {
        let kind = php::text(edge.get("relationType"));
        let node = edge.get("node").unwrap_or(&Value::Null);
        let title = node.get("title").unwrap_or(&Value::Null);
        let name = title
            .get("english")
            .filter(|name| !name.is_null())
            .or_else(|| title.get("romaji"));
        let item = format!("{} ({})", php::text(name), php::text(node.get("id")));
        match kind.as_str() {
            "SEQUEL" | "PREQUEL" | "SIDE_STORY" | "PARENT" | "SPIN_OFF" => related.push(item),
            "ALTERNATIVE" | "CHARACTER" => similar.push(item),
            _ => {}
        }
    }
    related.truncate(20);
    similar.truncate(20);
    (related.join(", "), similar.join(", "))
}

fn opt_text(media: &Value, key: &str) -> Option<String> {
    media
        .get(key)
        .filter(|value| !value.is_null())
        .map(|value| php::text(Some(value)))
}

fn opt_int(media: &Value, key: &str) -> Option<i64> {
    media
        .get(key)
        .filter(|value| !value.is_null())
        .map(|value| php::int(Some(value)))
}

/// `insertAniListInfo`: an existing row keeps its value wherever AniList sent nothing.
pub async fn store_info(services: &Services, anidbid: i64, media: &Value) -> Result<()> {
    let description = php::entity_decode(&php::strip_tags(&php::text(media.get("description"))));
    let cover = media
        .get("coverImage")
        .and_then(|cover| cover.get("large"))
        .and_then(Value::as_str);
    let picture = cover.map(picture_name);
    let genres = joined_names(php::items(media.get("genres")).iter());
    let creators = joined_names(
        php::items(
            media
                .get("studios")
                .and_then(|studios| studios.get("nodes")),
        )
        .iter()
        .filter_map(|studio| studio.get("name")),
    );
    let characters = joined_names(
        php::items(
            media
                .get("characters")
                .and_then(|characters| characters.get("nodes")),
        )
        .iter()
        .take(10)
        .filter_map(|character| character.get("name").and_then(|name| name.get("full"))),
    );
    let (related, similar) = relation_lists(media);
    let format = opt_text(media, "format");
    let media_type = opt_text(media, "type");
    sqlx::query(
        "INSERT INTO anidb_info (anidbid, anilist_id, mal_id, country, media_type, type, episodes, \
         duration, status, source, hashtag, startdate, enddate, description, rating, picture, \
         categories, characters, creators, related, similar, updated) \
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW()) \
         ON DUPLICATE KEY UPDATE \
         anilist_id = COALESCE(VALUES(anilist_id), anilist_id), \
         mal_id = COALESCE(VALUES(mal_id), mal_id), \
         country = COALESCE(VALUES(country), country), \
         media_type = COALESCE(VALUES(media_type), media_type), \
         type = COALESCE(?, type), \
         episodes = COALESCE(VALUES(episodes), episodes), \
         duration = COALESCE(VALUES(duration), duration), \
         status = COALESCE(VALUES(status), status), \
         source = COALESCE(VALUES(source), source), \
         hashtag = COALESCE(VALUES(hashtag), hashtag), \
         startdate = COALESCE(VALUES(startdate), startdate), \
         enddate = COALESCE(VALUES(enddate), enddate), \
         description = IF(VALUES(description) IN ('', '0'), description, VALUES(description)), \
         rating = COALESCE(VALUES(rating), rating), \
         picture = COALESCE(VALUES(picture), picture), \
         categories = IF(VALUES(categories) IN ('', '0'), categories, VALUES(categories)), \
         characters = IF(VALUES(characters) IN ('', '0'), characters, VALUES(characters)), \
         creators = IF(VALUES(creators) IN ('', '0'), creators, VALUES(creators)), \
         related = IF(VALUES(related) IN ('', '0'), related, VALUES(related)), \
         similar = IF(VALUES(similar) IN ('', '0'), similar, VALUES(similar)), \
         updated = NOW()",
    )
    .bind(anidbid)
    .bind(opt_int(media, "id"))
    .bind(opt_int(media, "idMal"))
    .bind(opt_text(media, "countryOfOrigin"))
    .bind(&media_type)
    .bind(&format)
    .bind(opt_int(media, "episodes"))
    .bind(opt_int(media, "duration"))
    .bind(opt_text(media, "status"))
    .bind(opt_text(media, "source"))
    .bind(opt_text(media, "hashtag"))
    .bind(date_of(media.get("startDate")))
    .bind(date_of(media.get("endDate")))
    .bind(&description)
    .bind(opt_text(media, "averageScore"))
    .bind(&picture)
    .bind(&genres)
    .bind(&characters)
    .bind(&creators)
    .bind(&related)
    .bind(&similar)
    .bind(format.as_ref().or(media_type.as_ref()))
    .execute(&services.db)
    .await?;

    let titles = media.get("title").unwrap_or(&Value::Null);
    for (key, kind, lang) in [
        ("romaji", "main", "x-jat"),
        ("english", "official", "en"),
        ("native", "main", "ja"),
    ] {
        let title = php::text(titles.get(key));
        if !php::is_empty(&title) {
            sqlx::query(
                "INSERT IGNORE INTO anidb_titles (anidbid, type, lang, title) VALUES (?, ?, ?, ?)",
            )
            .bind(anidbid)
            .bind(kind)
            .bind(lang)
            .bind(title)
            .execute(&services.db)
            .await?;
        }
    }

    if let (Some(url), Some(picture)) = (cover, picture.as_deref())
        && !php::is_empty(picture)
    {
        let name = format!("{anidbid}-cover");
        if !services.images.exists(KIND, &name)
            && !services
                .images
                .save_remote(&name, url, KIND, Profile::Original)
                .await
        {
            warn!(anidbid, "couldn't save an anime cover");
        }
    }
    Ok(())
}

async fn anidbid_of(services: &Services, anilist_id: i64) -> Result<Option<i64>> {
    Ok(sqlx::query_scalar(
        "SELECT CAST(anidbid AS SIGNED) FROM anidb_info WHERE anilist_id = ? LIMIT 1",
    )
    .bind(anilist_id)
    .fetch_optional(&services.db)
    .await?)
}

/// `populateTable('info', $id)`: fetches one anime and stores it.
pub async fn populate_by_id(services: &Services, anilist_id: i64) -> Result<()> {
    let Some(media) = services.anilist.anime_by_id(anilist_id).await? else {
        info!(anilist_id, "anime not found on AniList");
        return Ok(());
    };
    let anidbid = anidbid_of(services, anilist_id)
        .await?
        .unwrap_or(anilist_id);
    store_info(services, anidbid, &media).await
}

/// `populateTable('info')`: the next titles with no AniList id, or stale ones.
pub async fn populate_batch(services: &Services) -> Result<()> {
    let ids: Vec<i64> = sqlx::query_scalar(
        "SELECT DISTINCT CAST(t.anidbid AS SIGNED) FROM anidb_titles t \
         LEFT JOIN anidb_info i ON i.anidbid = t.anidbid \
         WHERE i.anilist_id IS NULL OR i.updated < NOW() - INTERVAL 1 WEEK LIMIT 100",
    )
    .fetch_all(&services.db)
    .await?;
    for anidbid in ids {
        let title: Option<String> = sqlx::query_scalar(
            "SELECT title FROM anidb_titles WHERE anidbid = ? AND lang = 'en' LIMIT 1",
        )
        .bind(anidbid)
        .fetch_optional(&services.db)
        .await?;
        let Some(title) = title.filter(|title| !php::is_empty(title)) else {
            continue;
        };
        if let Some(media) = services.anilist.search_anime(&title, 1).await?.first() {
            store_info(services, anidbid, media).await?;
        }
    }
    Ok(())
}

pub struct AnimeLookup {
    services: Arc<Services>,
    title_cache: HashMap<String, i64>,
}

/// A title row: its id and text.
type TitleRow = (i64, String);

impl AnimeLookup {
    pub fn new(services: Arc<Services>) -> Self {
        Self {
            services,
            title_cache: HashMap::new(),
        }
    }

    async fn title_row(&self, condition: &str, bind: &str) -> Result<Option<TitleRow>> {
        let statement = format!(
            "SELECT CAST(anidbid AS SIGNED) AS anidbid, title FROM anidb_titles WHERE {condition} LIMIT 1"
        );
        let row = sqlx::query(sqlx::AssertSqlSafe(statement))
            .bind(bind)
            .fetch_optional(&self.services.db)
            .await?;
        row.map(|row| Ok((row.try_get("anidbid")?, row.try_get("title")?)))
            .transpose()
    }

    /// `getAnidbByName`: the local table, then the AniList API.
    async fn by_name(&mut self, name: &str) -> Result<Option<TitleRow>> {
        if name.is_empty() {
            return Ok(None);
        }
        let key = name.to_lowercase();
        if let Some(id) = self.title_cache.get(&key) {
            return self.title_row("anidbid = ?", &id.to_string()).await;
        }
        if let Some(row) = self.title_row("LOWER(title) = ?", &key).await? {
            self.title_cache.insert(key, row.0);
            return Ok(Some(row));
        }
        if let Some(row) = self.title_row("title LIKE ?", &format!("%{name}%")).await? {
            self.title_cache.insert(key, row.0);
            return Ok(Some(row));
        }
        match self.search_remote(name).await {
            Ok(Some(row)) => {
                self.title_cache.insert(key, row.0);
                Ok(Some(row))
            }
            Ok(None) => Ok(None),
            Err(failure) => {
                error!(error = format!("{failure:#}"), "AniList search failed");
                Ok(None)
            }
        }
    }

    async fn search_remote(&self, name: &str) -> Result<Option<TitleRow>> {
        let services = &self.services;
        let results = services.anilist.search_anime(name, 1).await?;
        let anilist_id = results.first().map_or(0, |media| php::int(media.get("id")));
        if anilist_id == 0 {
            return Ok(None);
        }
        let anidbid = match anidbid_of(services, anilist_id).await? {
            Some(anidbid) if anidbid != 0 => anidbid,
            _ => {
                populate_by_id(services, anilist_id).await?;
                anilist_id
            }
        };
        let title: Option<String> = sqlx::query_scalar(
            "SELECT title FROM anidb_titles WHERE anidbid = ? AND lang = 'en' LIMIT 1",
        )
        .bind(anidbid)
        .fetch_optional(&services.db)
        .await?;
        Ok(title
            .filter(|title| !php::is_empty(title))
            .map(|title| (anidbid, title)))
    }

    async fn should_update_info(&self, anidbid: i64) -> Result<(bool, Option<i64>)> {
        let row = sqlx::query(
            "SELECT CAST(anilist_id AS SIGNED) AS anilist_id, \
             updated < NOW() - INTERVAL 1 WEEK AS stale FROM anidb_info WHERE anidbid = ?",
        )
        .bind(anidbid)
        .fetch_optional(&self.services.db)
        .await?;
        let Some(row) = row else {
            return Ok((true, None));
        };
        Ok((
            row.try_get::<Option<i32>, _>("stale")? == Some(1),
            row.try_get("anilist_id")?,
        ))
    }

    /// `matchAnimeRelease`: the id for the release, or the status to store instead.
    async fn match_release(&mut self, searchname: &str) -> Result<i64> {
        let Some(title) = extract_title(searchname) else {
            return Ok(PROC_EXTFAIL);
        };
        let mut found = self.by_name(&title).await?;
        if found.is_none() {
            // Spaces become wildcards for a broader match.
            let broader = title.split_whitespace().collect::<Vec<_>>().join("%");
            found = self.by_name(&broader).await?;
        }
        let Some((anidbid, _)) = found.filter(|(anidbid, _)| *anidbid > 0) else {
            return Ok(PROC_NOMATCH);
        };
        let (stale, mut anilist_id) = self.should_update_info(anidbid).await?;
        if stale {
            if anilist_id.is_none_or(|id| id == 0) {
                match self.services.anilist.search_anime(&title, 1).await {
                    Ok(results) => {
                        anilist_id = results.first().map(|media| php::int(media.get("id")));
                    }
                    Err(failure) => warn!(error = %failure, "AniList search failed"),
                }
            }
            if let Some(id) = anilist_id.filter(|id| *id != 0) {
                populate_by_id(&self.services, id).await?;
            }
        }
        Ok(anidbid)
    }

    /// `processAnimeReleases`.
    pub async fn process(
        &mut self,
        settings: &MetadataSettings,
        group_id: i64,
        guid_char: &str,
    ) -> Result<()> {
        let limit = if settings.max_anime_processed > 0 {
            settings.max_anime_processed
        } else {
            100
        };
        let statement = format!(
            "SELECT CAST(id AS SIGNED) AS id, searchname FROM releases \
             WHERE anidbid IS NULL AND categories_id = {TV_ANIME} AND {SETTLED}{} \
             ORDER BY postdate DESC LIMIT {limit}",
            release_scope(group_id, guid_char),
        );
        let rows = sqlx::query(sqlx::AssertSqlSafe(statement))
            .fetch_all(&self.services.db)
            .await?;
        for row in rows {
            let id: i64 = row.try_get("id")?;
            let searchname: String = row.try_get("searchname")?;
            let result = self.match_release(&searchname).await?;
            set_release(&self.services, id, &searchname, result).await?;
        }
        Ok(())
    }
}

/// Only for the title it was matched on: an upgrade may have renamed it meanwhile.
async fn set_release(services: &Services, id: i64, searchname: &str, anidbid: i64) -> Result<()> {
    sqlx::query("UPDATE releases SET anidbid = ? WHERE id = ? AND searchname = ?")
        .bind(anidbid)
        .bind(id)
        .bind(searchname)
        .execute(&services.db)
        .await?;
    Ok(())
}

/// [`crate::process_anime`] starts here.
pub async fn run(ctx: &Ctx, group_id: i64, guid_char: &str) -> Result<()> {
    check_guid_char(guid_char)?;
    let services = Services::shared(ctx)?;
    let settings = MetadataSettings::load(&services.db).await?;
    if !settings.anime_lookup.enabled() {
        return Ok(());
    }
    AnimeLookup::new(services)
        .process(&settings, group_id, guid_char)
        .await
}

#[cfg(test)]
mod tests {
    use super::*;
    use serde_json::json;

    #[test]
    fn dates_need_all_three_parts() {
        assert_eq!(
            date_of(Some(&json!({"year": 2020, "month": 1, "day": 2}))).as_deref(),
            Some("2020-01-02")
        );
        assert_eq!(
            date_of(Some(&json!({"year": 2020, "month": null, "day": 2}))),
            None
        );
    }

    #[test]
    fn relations_split_into_related_and_similar() {
        let media = json!({"relations": {"edges": [
            {"relationType": "SEQUEL", "node": {"id": 2, "title": {"english": null, "romaji": "Two"}}},
            {"relationType": "ALTERNATIVE", "node": {"id": 3, "title": {"english": "Three"}}},
            {"relationType": "ADAPTATION", "node": {"id": 4, "title": {"english": "Four"}}},
        ]}});
        assert_eq!(
            relation_lists(&media),
            ("Two (2)".to_owned(), "Three (3)".to_owned())
        );
    }

    #[test]
    fn picture_is_the_file_name() {
        assert_eq!(
            picture_name("https://s4.anilist.co/file/medium/bx1-abc.png"),
            "bx1-abc.png"
        );
    }
}

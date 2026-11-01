//! The release document stored in Manticore, from `ReleaseSearchIndexDocument`. Only fields
//! that may reach browse and search consumers belong here.

use chrono::{DateTime, NaiveDate, NaiveDateTime};
use serde::{Deserialize, Serialize};

use crate::query::php_trim;

#[derive(Clone, Debug, Default, PartialEq, Serialize, Deserialize)]
#[serde(default)]
pub struct ReleaseDocument {
    /// The document id, which Manticore keeps apart from the fields.
    #[serde(skip_serializing)]
    pub id: i64,
    pub guid: String,
    pub name: String,
    pub searchname: String,
    pub plainsearchname: String,
    pub fromname: String,
    pub categories_id: i64,
    pub category_name: String,
    pub parent_category: String,
    pub sub_category: String,
    pub group_name: String,
    pub filename: String,
    pub imdbid: String,
    pub tmdbid: i64,
    pub traktid: i64,
    pub tvdb: i64,
    pub tvmaze: i64,
    pub tvrage: i64,
    pub trakt: i64,
    pub imdb: String,
    pub tmdb: i64,
    pub videos_id: i64,
    pub tv_episodes_id: i64,
    pub movieinfo_id: i64,
    pub anidbid: i64,
    pub parentid: i64,
    pub episode_title: String,
    pub series: i64,
    pub episode: i64,
    pub firstaired_ts: i64,
    pub size: i64,
    pub postdate_ts: i64,
    pub adddate_ts: i64,
    pub totalpart: i64,
    pub grabs: i64,
    pub comments: i64,
    pub passwordstatus: i64,
    pub groups_id: i64,
    pub nzbstatus: i64,
    pub nfostatus: i64,
    pub haspreview: i64,
    pub jpgstatus: i64,
    pub nfoid: i64,
    pub reid: i64,
    pub media_movie_name: String,
    pub media_file_name: String,
    pub media_unique_id: String,
    pub media_container_format: String,
    pub media_video_format: String,
    pub media_video_codec: String,
    pub media_video_width: i64,
    pub media_video_height: i64,
    pub media_audio_format: String,
    pub media_audio_channels: String,
    pub media_audio_language: String,
    pub media_subtitle_language: String,
    pub has_media_info: i64,
}

impl ReleaseDocument {
    /// When it was posted, or None if the row had no date.
    pub fn postdate(&self) -> Option<NaiveDateTime> {
        from_unix(self.postdate_ts)
    }

    pub fn adddate(&self) -> Option<NaiveDateTime> {
        from_unix(self.adddate_ts)
    }

    pub fn firstaired(&self) -> Option<NaiveDateTime> {
        from_unix(self.firstaired_ts)
    }
}

fn from_unix(timestamp: i64) -> Option<NaiveDateTime> {
    (timestamp > 0)
        .then(|| DateTime::from_timestamp(timestamp, 0))
        .flatten()
        .map(|date| date.naive_utc())
}

/// One release as the indexing projection reads it, with dates as the database's text.
#[derive(Clone, Debug, Default, Deserialize, sqlx::FromRow)]
#[serde(default)]
pub struct ReleaseRow {
    pub id: i64,
    pub guid: Option<String>,
    pub name: Option<String>,
    pub searchname: Option<String>,
    pub fromname: Option<String>,
    pub categories_id: Option<i64>,
    pub category_name: Option<String>,
    pub parent_category: Option<String>,
    pub sub_category: Option<String>,
    pub group_name: Option<String>,
    pub filename: Option<String>,
    pub imdbid: Option<String>,
    pub tmdbid: Option<i64>,
    pub traktid: Option<i64>,
    pub tvdb: Option<i64>,
    pub tvmaze: Option<i64>,
    pub tvrage: Option<i64>,
    pub trakt: Option<i64>,
    pub imdb: Option<String>,
    pub tmdb: Option<i64>,
    pub videos_id: Option<i64>,
    pub tv_episodes_id: Option<i64>,
    pub movieinfo_id: Option<i64>,
    pub anidbid: Option<i64>,
    pub parentid: Option<i64>,
    pub episode_title: Option<String>,
    pub series: Option<i64>,
    pub episode: Option<i64>,
    pub firstaired: Option<String>,
    pub size: Option<i64>,
    pub postdate: Option<String>,
    pub adddate: Option<String>,
    pub totalpart: Option<i64>,
    pub grabs: Option<i64>,
    pub comments: Option<i64>,
    pub passwordstatus: Option<i64>,
    pub groups_id: Option<i64>,
    pub nzbstatus: Option<i64>,
    pub nfostatus: Option<i64>,
    pub haspreview: Option<i64>,
    pub jpgstatus: Option<i64>,
    pub nfoid: Option<i64>,
    pub reid: Option<i64>,
    pub media_movie_name: Option<String>,
    pub media_file_name: Option<String>,
    pub media_unique_id: Option<String>,
    pub media_container_format: Option<String>,
    pub media_video_format: Option<String>,
    pub media_video_codec: Option<String>,
    pub media_video_width: Option<i64>,
    pub media_video_height: Option<i64>,
    pub media_audio_format: Option<String>,
    pub media_audio_channels: Option<String>,
    pub media_audio_language: Option<String>,
    pub media_subtitle_language: Option<String>,
    pub has_media_info: Option<i64>,
}

impl From<ReleaseRow> for ReleaseDocument {
    fn from(row: ReleaseRow) -> Self {
        let searchname = row.searchname.unwrap_or_default();
        Self {
            id: row.id,
            guid: row.guid.unwrap_or_default(),
            name: row.name.unwrap_or_default(),
            plainsearchname: plain_search_name(&searchname),
            searchname,
            fromname: row.fromname.unwrap_or_default(),
            categories_id: row.categories_id.unwrap_or_default(),
            category_name: row.category_name.unwrap_or_default(),
            parent_category: row.parent_category.unwrap_or_default(),
            sub_category: row.sub_category.unwrap_or_default(),
            group_name: row.group_name.unwrap_or_default(),
            filename: row.filename.unwrap_or_default(),
            imdbid: row.imdbid.unwrap_or_default(),
            tmdbid: row.tmdbid.unwrap_or_default(),
            traktid: row.traktid.unwrap_or_default(),
            tvdb: row.tvdb.unwrap_or_default(),
            tvmaze: row.tvmaze.unwrap_or_default(),
            tvrage: row.tvrage.unwrap_or_default(),
            trakt: row.trakt.unwrap_or_default(),
            imdb: row.imdb.unwrap_or_default(),
            tmdb: row.tmdb.unwrap_or_default(),
            videos_id: row.videos_id.unwrap_or_default(),
            tv_episodes_id: row.tv_episodes_id.unwrap_or_default(),
            movieinfo_id: row.movieinfo_id.unwrap_or_default(),
            anidbid: row.anidbid.unwrap_or_default(),
            parentid: row.parentid.unwrap_or_default(),
            episode_title: row.episode_title.unwrap_or_default(),
            series: row.series.unwrap_or_default(),
            episode: row.episode.unwrap_or_default(),
            firstaired_ts: to_unix(row.firstaired.as_deref()),
            size: row.size.unwrap_or_default(),
            postdate_ts: to_unix(row.postdate.as_deref()),
            adddate_ts: to_unix(row.adddate.as_deref()),
            totalpart: row.totalpart.unwrap_or_default(),
            grabs: row.grabs.unwrap_or_default(),
            comments: row.comments.unwrap_or_default(),
            passwordstatus: row.passwordstatus.unwrap_or_default(),
            groups_id: row.groups_id.unwrap_or_default(),
            nzbstatus: row.nzbstatus.unwrap_or_default(),
            nfostatus: row.nfostatus.unwrap_or_default(),
            haspreview: row.haspreview.unwrap_or_default(),
            jpgstatus: row.jpgstatus.unwrap_or_default(),
            nfoid: row.nfoid.unwrap_or_default(),
            reid: row.reid.unwrap_or_default(),
            media_movie_name: row.media_movie_name.unwrap_or_default(),
            media_file_name: row.media_file_name.unwrap_or_default(),
            media_unique_id: row.media_unique_id.unwrap_or_default(),
            media_container_format: row.media_container_format.unwrap_or_default(),
            media_video_format: row.media_video_format.unwrap_or_default(),
            media_video_codec: row.media_video_codec.unwrap_or_default(),
            media_video_width: row.media_video_width.unwrap_or_default(),
            media_video_height: row.media_video_height.unwrap_or_default(),
            media_audio_format: row.media_audio_format.unwrap_or_default(),
            media_audio_channels: row.media_audio_channels.unwrap_or_default(),
            media_audio_language: row.media_audio_language.unwrap_or_default(),
            media_subtitle_language: row.media_subtitle_language.unwrap_or_default(),
            has_media_info: row.has_media_info.unwrap_or_default(),
        }
    }
}

/// PHP read these as UTC, the app's timezone. A zero date stores 0, where PHP stored a large
/// negative number that sorted the same way and rendered no differently.
fn to_unix(value: Option<&str>) -> i64 {
    let Some(value) = value.filter(|value| !value.is_empty()) else {
        return 0;
    };
    NaiveDateTime::parse_from_str(value, "%Y-%m-%d %H:%M:%S")
        .ok()
        .or_else(|| {
            NaiveDate::parse_from_str(value, "%Y-%m-%d")
                .ok()
                .and_then(|date| date.and_hms_opt(0, 0, 0))
        })
        .map_or(0, |date| date.and_utc().timestamp())
}

fn plain_search_name(searchname: &str) -> String {
    let mut plain = String::with_capacity(searchname.len());
    let mut in_separator = false;
    for character in searchname.chars() {
        if matches!(character, '.' | '_' | '-') {
            if !in_separator {
                plain.push(' ');
            }
            in_separator = true;
        } else {
            plain.push(character);
            in_separator = false;
        }
    }
    php_trim(&plain).to_owned()
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn plain_names_collapse_separator_runs() {
        assert_eq!(plain_search_name("._A.b__c-.d-"), "A b c d");
    }

    #[test]
    fn dates_parse_as_utc_and_bad_ones_are_zero() {
        assert_eq!(to_unix(Some("2024-03-01 10:00:00")), 1_709_287_200);
        assert_eq!(to_unix(Some("2024-04-30")), 1_714_435_200);
        assert_eq!(to_unix(Some("0000-00-00 00:00:00")), 0);
        assert_eq!(to_unix(Some("")), 0);
        assert_eq!(to_unix(None), 0);
    }

    #[test]
    fn dates_come_back_from_the_timestamps() {
        let document = ReleaseDocument {
            postdate_ts: 1_709_287_200,
            ..ReleaseDocument::default()
        };
        assert_eq!(
            document.postdate().unwrap().to_string(),
            "2024-03-01 10:00:00"
        );
        assert_eq!(document.adddate(), None);
    }
}

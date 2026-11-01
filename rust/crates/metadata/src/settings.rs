//! `metadata_configurations`, row 1: which lookups run, and how many releases a pass takes.

use anyhow::Result;
use sqlx::MySqlPool;

/// A `*_lookup` setting.
#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub enum LookupMode {
    Disabled,
    All,
    RenamedOnly,
}

impl LookupMode {
    pub fn from_value(value: i64) -> Self {
        match value {
            1 => Self::All,
            2 => Self::RenamedOnly,
            _ => Self::Disabled,
        }
    }

    pub fn value(self) -> i64 {
        match self {
            Self::Disabled => 0,
            Self::All => 1,
            Self::RenamedOnly => 2,
        }
    }

    pub fn enabled(self) -> bool {
        self != Self::Disabled
    }

    /// The `AND isrenamed = 1` filter this mode adds, or nothing.
    pub fn renamed_filter(self) -> &'static str {
        match self {
            Self::RenamedOnly => " AND isrenamed = 1",
            _ => "",
        }
    }
}

/// What a caller asks of a pass, on top of the configured [`LookupMode`]. PHP passed this as the
/// optional `renamed` argument of `postprocess:guid` and `postprocess:tv-pipeline`.
#[derive(Clone, Copy, Debug, Default, PartialEq, Eq)]
pub enum Renamed {
    /// Use the setting. This is the empty argument in PHP.
    #[default]
    Configured,
    /// Take every release, even if the setting says renamed only. This is the argument 1.
    All,
    /// Take renamed releases only. This is the argument 2.
    Only,
}

impl Renamed {
    /// The mode for a pass. An explicit argument runs even with the lookup disabled, as a numeric
    /// argument did in PHP.
    pub fn resolve(self, configured: LookupMode) -> LookupMode {
        match self {
            Self::Configured => configured,
            Self::All => LookupMode::All,
            Self::Only => LookupMode::RenamedOnly,
        }
    }
}

/// `metadata_configurations`, row 1.
#[derive(Clone, Debug, PartialEq)]
pub struct MetadataSettings {
    pub anime_lookup: LookupMode,
    pub book_lookup: LookupMode,
    pub game_lookup: LookupMode,
    pub movie_lookup: LookupMode,
    pub music_lookup: LookupMode,
    pub tv_lookup: LookupMode,
    pub movie_language: String,
    pub imdb_alternate_url: bool,
    pub max_anime_processed: i64,
    pub max_books_processed: i64,
    pub max_games_processed: i64,
    pub max_movies_processed: i64,
    pub max_music_processed: i64,
    pub max_tv_processed: i64,
    pub amazon_sleep_milliseconds: i64,
}

impl Default for MetadataSettings {
    /// The app's defaults, for a database with no row.
    fn default() -> Self {
        Self {
            anime_lookup: LookupMode::Disabled,
            book_lookup: LookupMode::All,
            game_lookup: LookupMode::All,
            movie_lookup: LookupMode::All,
            music_lookup: LookupMode::All,
            tv_lookup: LookupMode::All,
            movie_language: "en".to_owned(),
            imdb_alternate_url: false,
            max_anime_processed: 100,
            max_books_processed: 300,
            max_games_processed: 150,
            max_movies_processed: 100,
            max_music_processed: 150,
            max_tv_processed: 75,
            amazon_sleep_milliseconds: 1000,
        }
    }
}

impl MetadataSettings {
    pub async fn load(db: &MySqlPool) -> Result<Self> {
        let row = sqlx::query_as::<
            _,
            (
                i64,
                i64,
                i64,
                i64,
                i64,
                i64,
                String,
                bool,
                i64,
                i64,
                i64,
                i64,
                i64,
                i64,
                i64,
            ),
        >(
            "SELECT CAST(anime_lookup AS SIGNED), CAST(book_lookup AS SIGNED), \
                    CAST(game_lookup AS SIGNED), CAST(movie_lookup AS SIGNED), \
                    CAST(music_lookup AS SIGNED), CAST(tv_lookup AS SIGNED), movie_language, \
                    imdb_alternate_url, CAST(max_anime_processed AS SIGNED), \
                    CAST(max_books_processed AS SIGNED), CAST(max_games_processed AS SIGNED), \
                    CAST(max_movies_processed AS SIGNED), CAST(max_music_processed AS SIGNED), \
                    CAST(max_tv_processed AS SIGNED), CAST(amazon_sleep_milliseconds AS SIGNED) \
             FROM metadata_configurations WHERE id = 1",
        )
        .fetch_optional(db)
        .await?;
        let Some(row) = row else {
            return Ok(Self::default());
        };
        Ok(Self {
            anime_lookup: LookupMode::from_value(row.0),
            book_lookup: LookupMode::from_value(row.1),
            game_lookup: LookupMode::from_value(row.2),
            movie_lookup: LookupMode::from_value(row.3),
            music_lookup: LookupMode::from_value(row.4),
            tv_lookup: LookupMode::from_value(row.5),
            movie_language: row.6,
            imdb_alternate_url: row.7,
            max_anime_processed: row.8,
            max_books_processed: row.9,
            max_games_processed: row.10,
            max_movies_processed: row.11,
            max_music_processed: row.12,
            max_tv_processed: row.13,
            amazon_sleep_milliseconds: row.14,
        })
    }
}

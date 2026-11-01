//! Table definitions and ranking profiles, from `ManticoreIndexRegistry` and
//! `SecondarySearchIndex`.

use serde_json::{Map, Value, json};

/// The metadata indexes that have one search shape.
#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub enum SecondaryIndex {
    Music,
    Books,
    Games,
    Console,
    Steam,
    Anime,
}

impl SecondaryIndex {
    pub fn logical(self) -> &'static str {
        match self {
            Self::Music => "music",
            Self::Books => "books",
            Self::Games => "games",
            Self::Console => "console",
            Self::Steam => "steam",
            Self::Anime => "anime",
        }
    }

    pub fn parse(logical: &str) -> Option<Self> {
        [
            Self::Music,
            Self::Books,
            Self::Games,
            Self::Console,
            Self::Steam,
            Self::Anime,
        ]
        .into_iter()
        .find(|index| index.logical() == logical)
    }

    pub(crate) fn fulltext_fields(self) -> &'static [&'static str] {
        match self {
            Self::Music => &["artist", "title"],
            Self::Books => &["author", "title"],
            Self::Games | Self::Anime => &["title"],
            Self::Console => &["title", "platform"],
            Self::Steam => &["name"],
        }
    }
}

const RELEASE_COLUMNS: &[(&str, &str)] = &[
    ("guid", "string"),
    ("name", "text"),
    ("searchname", "text"),
    ("plainsearchname", "text"),
    ("fromname", "text"),
    ("filename", "text"),
    ("category_name", "string"),
    ("parent_category", "string"),
    ("sub_category", "string"),
    ("group_name", "string"),
    ("categories_id", "integer"),
    ("imdbid", "string"),
    ("tmdbid", "integer"),
    ("traktid", "integer"),
    ("tvdb", "integer"),
    ("tvmaze", "integer"),
    ("tvrage", "integer"),
    ("trakt", "integer"),
    ("imdb", "string"),
    ("tmdb", "integer"),
    ("videos_id", "integer"),
    ("tv_episodes_id", "integer"),
    ("movieinfo_id", "integer"),
    ("anidbid", "integer"),
    ("parentid", "integer"),
    ("episode_title", "text"),
    ("series", "integer"),
    ("episode", "integer"),
    ("firstaired_ts", "bigint"),
    ("size", "bigint"),
    ("postdate_ts", "bigint"),
    ("adddate_ts", "bigint"),
    ("totalpart", "integer"),
    ("grabs", "integer"),
    ("comments", "integer"),
    ("nfostatus", "integer"),
    ("jpgstatus", "integer"),
    ("nfoid", "integer"),
    ("reid", "integer"),
    ("passwordstatus", "bigint"),
    ("groups_id", "integer"),
    ("nzbstatus", "integer"),
    ("haspreview", "bigint"),
    ("media_movie_name", "text"),
    ("media_file_name", "text"),
    ("media_unique_id", "string"),
    ("media_container_format", "text"),
    ("media_video_format", "text"),
    ("media_video_codec", "text"),
    ("media_video_width", "integer"),
    ("media_video_height", "integer"),
    ("media_audio_format", "text"),
    ("media_audio_channels", "text"),
    ("media_audio_language", "text"),
    ("media_subtitle_language", "text"),
    ("has_media_info", "integer"),
];

pub(crate) fn columns(logical: &str) -> &'static [(&'static str, &'static str)] {
    match logical {
        "releases" => RELEASE_COLUMNS,
        "predb" => &[
            ("title", "text"),
            ("filename", "text"),
            ("source", "string"),
        ],
        "movies" => &[
            ("imdbid", "string"),
            ("tmdbid", "integer"),
            ("traktid", "integer"),
            ("title", "text"),
            ("year", "text"),
            ("genre", "text"),
            ("actors", "text"),
            ("director", "text"),
            ("rating", "text"),
            ("plot", "text"),
        ],
        "tvshows" => &[
            ("title", "text"),
            ("tvdb", "integer"),
            ("trakt", "integer"),
            ("tvmaze", "integer"),
            ("tvrage", "integer"),
            ("imdb", "string"),
            ("tmdb", "integer"),
            ("started", "text"),
            ("type", "integer"),
        ],
        "music" => &[
            ("title", "text"),
            ("artist", "text"),
            ("year", "string"),
            ("genres_id", "integer"),
            ("cover", "integer"),
        ],
        "books" => &[
            ("title", "text"),
            ("author", "text"),
            ("publishdate", "string"),
            ("cover", "integer"),
        ],
        "games" => &[
            ("title", "text"),
            ("genres_id", "integer"),
            ("releasedate_ts", "bigint"),
            ("cover", "integer"),
        ],
        "console" => &[
            ("title", "text"),
            ("platform", "string"),
            ("genres_id", "integer"),
            ("releasedate_ts", "bigint"),
            ("cover", "integer"),
        ],
        "steam" => &[("name", "text"), ("appid", "integer")],
        "anime" => &[
            ("title", "text"),
            ("anidbid", "integer"),
            ("anilist_id", "integer"),
            ("mal_id", "integer"),
            ("lang", "string"),
            ("title_type", "string"),
            ("media_type", "string"),
            ("status", "string"),
        ],
        _ => &[],
    }
}

/// The statement the PHP client built from the registry's definition of `logical`.
pub fn create_table_sql(table: &str, logical: &str) -> String {
    let columns = columns(logical)
        .iter()
        .map(|(name, kind)| format!("`{name}` {kind}"))
        .collect::<Vec<_>>()
        .join(",");
    format!(
        "CREATE TABLE {table}({columns}) min_prefix_len = '0' min_infix_len = '2' \
         exact_words = '1' index_field_lengths = '1'"
    )
}

pub(crate) const RANKER: &str = "sph04";
pub(crate) const PROFILE_FUZZY_DISTANCE: u32 = 2;

pub(crate) fn field_weights(logical: &str) -> Value {
    match logical {
        "releases" => {
            json!({"searchname": 12, "plainsearchname": 10, "name": 8, "filename": 5, "fromname": 1})
        }
        "predb" => json!({"title": 12, "filename": 5}),
        "movies" => json!({"title": 12, "director": 5, "actors": 3, "genre": 2, "plot": 1}),
        "tvshows" | "console" | "games" | "anime" => json!({"title": 12}),
        "music" => json!({"title": 12, "artist": 9}),
        "books" => json!({"title": 12, "author": 9}),
        "steam" => json!({"name": 12}),
        _ => Value::Object(Map::new()),
    }
}

pub(crate) fn autocomplete_fields(logical: &str) -> &'static [&'static str] {
    match logical {
        "releases" => &["searchname", "name"],
        "predb" => &["title", "filename"],
        "movies" | "tvshows" | "music" | "books" | "games" | "console" | "anime" => &["title"],
        "steam" => &["name"],
        _ => &[],
    }
}

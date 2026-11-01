//! `nntmux:populate`: rebuilds Manticore indexes from the database. Each index truncates first, then
//! walks the id range in windows, fetching and inserting up to `parallel` windows at a time.

use std::future::Future;

use anyhow::{Context, Result, bail};
use futures_util::{StreamExt, stream};
use nntmux_search::{
    BulkResult, MovieDocument, PredbHit, SearchClient, SecondaryIndex, TvShowDocument,
};
use serde_json::{Value, json};
use sqlx::{AssertSqlSafe, MySqlPool};

pub const INDEXES: [&str; 10] = [
    "releases", "predb", "movies", "tvshows", "music", "books", "games", "console", "steam",
    "anime",
];

#[derive(Clone, Copy, Debug)]
pub struct Options {
    /// Ids per window.
    pub count: i64,
    /// Windows in flight.
    pub parallel: usize,
}

/// The windows covering `low..=high`, inclusive at both ends.
fn windows(low: i64, high: i64, count: i64) -> impl Iterator<Item = (i64, i64)> {
    let count = count.max(1);
    (low..=high)
        .step_by(usize::try_from(count).unwrap_or(1))
        .map(move |start| (start, start.saturating_add(count - 1).min(high)))
}

fn text(column: &str) -> String {
    format!("COALESCE(CAST({column} AS CHAR), '')")
}

fn number(column: &str) -> String {
    format!("CAST(COALESCE({column}, 0) AS SIGNED)")
}

async fn insert_windows<F, Fut>(
    low: i64,
    high: i64,
    options: Options,
    window: F,
) -> Result<BulkResult>
where
    F: Fn(i64, i64) -> Fut,
    Fut: Future<Output = Result<BulkResult>>,
{
    let mut results = stream::iter(windows(low, high, options.count))
        .map(|(start, end)| window(start, end))
        .buffer_unordered(options.parallel.max(1));
    let mut total = BulkResult::default();
    while let Some(result) = results.next().await {
        let result = result?;
        total.success += result.success;
        total.errors += result.errors;
    }
    Ok(total)
}

/// Fetches `(id, fields...)` rows for a secondary index and maps them to documents.
async fn secondary_window(
    client: &SearchClient,
    db: &MySqlPool,
    index: SecondaryIndex,
    select: &str,
    id_column: &str,
    low: i64,
    high: i64,
) -> Result<BulkResult> {
    let rows: Vec<(String,)> = sqlx::query_as(AssertSqlSafe(format!(
        "{select} WHERE {id_column} BETWEEN ? AND ?"
    )))
    .bind(low)
    .bind(high)
    .fetch_all(db)
    .await?;
    let mut documents = Vec::with_capacity(rows.len());
    for (row,) in rows {
        documents.push(serde_json::from_str::<Value>(&row).context("reading a document row")?);
    }
    Ok(client.bulk_insert_secondary(index, &documents).await)
}

/// A `SELECT` producing each document as one JSON object, so the mapping is one list per index.
fn json_select(fields: &[(&str, String)], from: &str) -> String {
    let pairs = fields
        .iter()
        .map(|(name, value)| format!("'{name}', {value}"))
        .collect::<Vec<_>>()
        .join(", ");
    format!("SELECT JSON_OBJECT({pairs}) FROM {from}")
}

async fn bounds(db: &MySqlPool, column: &str, from: &str) -> Result<Option<(i64, i64)>> {
    let (low, high): (Option<i64>, Option<i64>) = sqlx::query_as(AssertSqlSafe(format!(
        "SELECT CAST(MIN({column}) AS SIGNED), CAST(MAX({column}) AS SIGNED) FROM {from}"
    )))
    .fetch_one(db)
    .await?;
    Ok(low.zip(high))
}

/// The document id PHP gave an anime title, kept so documents line up with single-title updates.
pub fn anime_document_id(anidbid: i64, kind: &str, lang: &str, title: &str) -> i64 {
    i64::from(crc32fast::hash(
        format!("{anidbid}|{kind}|{lang}|{title}").as_bytes(),
    ))
}

async fn fill_releases(
    client: &SearchClient,
    db: &MySqlPool,
    options: Options,
) -> Result<BulkResult> {
    let Some((low, high)) = bounds(db, "id", "releases").await? else {
        return Ok(BulkResult::default());
    };
    insert_windows(low, high, options, |start, end| async move {
        let documents = client.release_documents_between(db, start, end).await?;
        Ok(client.bulk_insert_releases(&documents).await)
    })
    .await
}

async fn fill_predb(client: &SearchClient, db: &MySqlPool, options: Options) -> Result<BulkResult> {
    let Some((low, high)) = bounds(db, "id", "predb").await? else {
        return Ok(BulkResult::default());
    };
    insert_windows(low, high, options, |start, end| async move {
        let rows: Vec<(i64, String, String, String)> = sqlx::query_as(
            "SELECT CAST(id AS SIGNED), title, COALESCE(filename, ''), COALESCE(source, '') \
             FROM predb WHERE id BETWEEN ? AND ?",
        )
        .bind(start)
        .bind(end)
        .fetch_all(db)
        .await?;
        let hits: Vec<PredbHit> = rows
            .into_iter()
            .map(|(id, title, filename, source)| PredbHit {
                id,
                title,
                filename,
                source,
            })
            .collect();
        // Replace, so a document the search role wrote since the truncate can't fail the insert.
        Ok(client.bulk_insert_predb(&hits, true).await)
    })
    .await
}

async fn fill_movies(
    client: &SearchClient,
    db: &MySqlPool,
    options: Options,
) -> Result<BulkResult> {
    let Some((low, high)) = bounds(db, "id", "movieinfo").await? else {
        return Ok(BulkResult::default());
    };
    let select = format!(
        "SELECT CAST(id AS SIGNED), {}, {}, {}, {}, {}, {}, {}, {}, {}, {} FROM movieinfo \
         WHERE id BETWEEN ? AND ?",
        text("imdbid"),
        number("tmdbid"),
        number("traktid"),
        text("title"),
        text("year"),
        text("genre"),
        text("actors"),
        text("director"),
        text("rating"),
        text("plot"),
    );
    let select = select.as_str();
    insert_windows(low, high, options, |start, end| async move {
        type Row = (
            i64,
            String,
            i64,
            i64,
            String,
            String,
            String,
            String,
            String,
            String,
            String,
        );
        let rows: Vec<Row> = sqlx::query_as(AssertSqlSafe(select.to_owned()))
            .bind(start)
            .bind(end)
            .fetch_all(db)
            .await?;
        let movies: Vec<MovieDocument> = rows
            .into_iter()
            .map(
                |(
                    id,
                    imdbid,
                    tmdbid,
                    traktid,
                    title,
                    year,
                    genre,
                    actors,
                    director,
                    rating,
                    plot,
                )| {
                    MovieDocument {
                        id,
                        imdbid,
                        tmdbid,
                        traktid,
                        title,
                        year,
                        genre,
                        actors,
                        director,
                        rating,
                        plot,
                    }
                },
            )
            .collect();
        Ok(client.bulk_insert_movies(&movies).await)
    })
    .await
}

async fn fill_tv_shows(
    client: &SearchClient,
    db: &MySqlPool,
    options: Options,
) -> Result<BulkResult> {
    let Some((low, high)) = bounds(db, "id", "videos").await? else {
        return Ok(BulkResult::default());
    };
    let select = format!(
        "SELECT CAST(id AS SIGNED), {}, {}, {}, {}, {}, {}, {}, {}, {} FROM videos \
         WHERE id BETWEEN ? AND ?",
        text("title"),
        number("tvdb"),
        number("trakt"),
        number("tvmaze"),
        number("tvrage"),
        text("imdb"),
        number("tmdb"),
        text("started"),
        number("type"),
    );
    let select = select.as_str();
    insert_windows(low, high, options, |start, end| async move {
        type Row = (i64, String, i64, i64, i64, i64, String, i64, String, i64);
        let rows: Vec<Row> = sqlx::query_as(AssertSqlSafe(select.to_owned()))
            .bind(start)
            .bind(end)
            .fetch_all(db)
            .await?;
        let shows: Vec<TvShowDocument> = rows
            .into_iter()
            .map(
                |(id, title, tvdb, trakt, tvmaze, tvrage, imdb, tmdb, started, kind)| {
                    TvShowDocument {
                        id,
                        title,
                        tvdb,
                        trakt,
                        tvmaze,
                        tvrage,
                        imdb,
                        tmdb,
                        started,
                        kind,
                    }
                },
            )
            .collect();
        Ok(client.bulk_insert_tv_shows(&shows).await)
    })
    .await
}

async fn fill_secondary(
    client: &SearchClient,
    db: &MySqlPool,
    options: Options,
    index: SecondaryIndex,
    table: &str,
    fields: &[(&str, String)],
) -> Result<BulkResult> {
    let Some((low, high)) = bounds(db, "id", table).await? else {
        return Ok(BulkResult::default());
    };
    let mut all = vec![("id", number("id"))];
    all.extend(fields.iter().cloned());
    let select = json_select(&all, table);
    let select = select.as_str();
    insert_windows(low, high, options, |start, end| async move {
        secondary_window(client, db, index, select, "id", start, end).await
    })
    .await
}

async fn fill_anime(client: &SearchClient, db: &MySqlPool, options: Options) -> Result<BulkResult> {
    let Some((low, high)) = bounds(db, "anidbid", "anidb_titles").await? else {
        return Ok(BulkResult::default());
    };
    insert_windows(low, high, options, |start, end| async move {
        type Row = (i64, String, String, String, i64, i64, String, String);
        let rows: Vec<Row> = sqlx::query_as(
            "SELECT CAST(t.anidbid AS SIGNED), t.type, t.lang, t.title, \
             CAST(COALESCE(i.anilist_id, 0) AS SIGNED), CAST(COALESCE(i.mal_id, 0) AS SIGNED), \
             COALESCE(i.media_type, ''), COALESCE(i.status, '') \
             FROM anidb_titles t LEFT JOIN anidb_info i ON t.anidbid = i.anidbid \
             WHERE t.anidbid BETWEEN ? AND ?",
        )
        .bind(start)
        .bind(end)
        .fetch_all(db)
        .await?;
        let documents: Vec<Value> = rows
            .into_iter()
            .map(
                |(anidbid, kind, lang, title, anilist_id, mal_id, media_type, status)| {
                    json!({
                        "id": anime_document_id(anidbid, &kind, &lang, &title),
                        "title": title,
                        "anidbid": anidbid,
                        "anilist_id": anilist_id,
                        "mal_id": mal_id,
                        "lang": lang,
                        "title_type": kind,
                        "media_type": media_type,
                        "status": status,
                    })
                },
            )
            .collect();
        Ok(client
            .bulk_insert_secondary(SecondaryIndex::Anime, &documents)
            .await)
    })
    .await
}

fn release_timestamp() -> String {
    number("UNIX_TIMESTAMP(releasedate)")
}

/// Rows in the source table, and the same count PHP verified the index against.
async fn source_rows(db: &MySqlPool, logical: &str) -> Result<i64> {
    let table = match logical {
        "movies" => "movieinfo",
        "tvshows" => "videos",
        "music" => "musicinfo",
        "books" => "bookinfo",
        "games" => "gamesinfo",
        "console" => "consoleinfo",
        "steam" => "steam_apps",
        "anime" => "anidb_titles",
        other => other,
    };
    Ok(
        sqlx::query_scalar(AssertSqlSafe(format!("SELECT COUNT(*) FROM {table}")))
            .fetch_one(db)
            .await?,
    )
}

/// Rebuilds one index and returns the documents written. Fails if any document was refused, or the
/// index ends up short of the source table.
pub async fn populate(
    client: &SearchClient,
    db: &MySqlPool,
    logical: &str,
    options: Options,
) -> Result<u64> {
    if !INDEXES.contains(&logical) {
        bail!("Unknown index: {logical}");
    }
    let table = client.config().indexes.table(logical).to_owned();
    client.truncate_indexes(&[table.as_str()]).await?;
    let total = source_rows(db, logical).await?;
    if total == 0 {
        return Ok(0);
    }
    let result = match logical {
        "releases" => fill_releases(client, db, options).await?,
        "predb" => fill_predb(client, db, options).await?,
        "movies" => fill_movies(client, db, options).await?,
        "tvshows" => fill_tv_shows(client, db, options).await?,
        "music" => {
            let fields = [
                ("title", text("title")),
                ("artist", text("artist")),
                ("year", text("year")),
                ("genres_id", number("genres_id")),
                ("cover", number("cover")),
            ];
            fill_secondary(
                client,
                db,
                options,
                SecondaryIndex::Music,
                "musicinfo",
                &fields,
            )
            .await?
        }
        "books" => {
            let fields = [
                ("title", text("title")),
                ("author", text("author")),
                ("publishdate", text("publishdate")),
                ("cover", number("cover")),
            ];
            fill_secondary(
                client,
                db,
                options,
                SecondaryIndex::Books,
                "bookinfo",
                &fields,
            )
            .await?
        }
        "games" => {
            let fields = [
                ("title", text("title")),
                ("genres_id", number("genres_id")),
                ("releasedate_ts", release_timestamp()),
                ("cover", number("cover")),
            ];
            fill_secondary(
                client,
                db,
                options,
                SecondaryIndex::Games,
                "gamesinfo",
                &fields,
            )
            .await?
        }
        "console" => {
            let fields = [
                ("title", text("title")),
                ("platform", text("platform")),
                ("genres_id", number("genres_id")),
                ("releasedate_ts", release_timestamp()),
                ("cover", number("cover")),
            ];
            fill_secondary(
                client,
                db,
                options,
                SecondaryIndex::Console,
                "consoleinfo",
                &fields,
            )
            .await?
        }
        "steam" => {
            let fields = [("name", text("name")), ("appid", number("appid"))];
            fill_secondary(
                client,
                db,
                options,
                SecondaryIndex::Steam,
                "steam_apps",
                &fields,
            )
            .await?
        }
        "anime" => fill_anime(client, db, options).await?,
        _ => unreachable!("checked above"),
    };
    if result.errors > 0 {
        bail!(
            "{table}: {} of {} documents failed.",
            result.errors,
            result.errors + result.success
        );
    }
    let indexed = client.count_index_documents(&table).await?;
    if indexed < u64::try_from(total).unwrap_or(0) {
        bail!("Manticore verification failed for {table}: expected {total}, found {indexed}.");
    }
    Ok(indexed)
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn windows_cover_the_range_exactly() {
        let all: Vec<_> = windows(1, 10, 4).collect();
        assert_eq!(all, [(1, 4), (5, 8), (9, 10)]);
        assert_eq!(windows(7, 7, 5000).collect::<Vec<_>>(), [(7, 7)]);
        assert_eq!(
            windows(1, 3, 0).collect::<Vec<_>>(),
            [(1, 1), (2, 2), (3, 3)]
        );
    }

    #[test]
    fn anime_ids_are_php_crc32() {
        // php -r 'echo sprintf("%u", crc32("1|main|en|Title"));'
        assert_eq!(anime_document_id(1, "main", "en", "Title"), PHP_CRC32);
    }

    const PHP_CRC32: i64 = 2041223649;
}

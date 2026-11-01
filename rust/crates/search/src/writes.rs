//! Writes to the other indexes and table maintenance, from `ManticoreSearchDriver`. Unlike
//! release writes, nothing retries these later, so they return their failures.

use serde::{Deserialize, Serialize};
use serde_json::{Value, json};
use sqlx::MySqlPool;
use tracing::{debug, info};

use crate::index::BulkResult;
use crate::read::PredbHit;
use crate::registry::{SecondaryIndex, create_table_sql};
use crate::{SearchClient, SearchError};

/// A `movies_rt` document.
#[derive(Clone, Debug, Default, PartialEq, Eq, Serialize, Deserialize, sqlx::FromRow)]
pub struct MovieDocument {
    #[serde(skip_serializing)]
    pub id: i64,
    pub imdbid: String,
    pub tmdbid: i64,
    pub traktid: i64,
    pub title: String,
    pub year: String,
    pub genre: String,
    pub actors: String,
    pub director: String,
    pub rating: String,
    pub plot: String,
}

/// A `tvshows_rt` document.
#[derive(Clone, Debug, Default, PartialEq, Eq, Serialize, Deserialize)]
pub struct TvShowDocument {
    #[serde(skip_serializing)]
    pub id: i64,
    pub title: String,
    pub tvdb: i64,
    pub trakt: i64,
    pub tvmaze: i64,
    pub tvrage: i64,
    pub imdb: String,
    pub tmdb: i64,
    pub started: String,
    #[serde(rename = "type")]
    pub kind: i64,
}

fn predb_fields(hit: &PredbHit) -> Value {
    json!({"title": hit.title, "filename": hit.filename, "source": hit.source})
}

fn require_id(id: i64) -> Result<(), SearchError> {
    if id > 0 {
        Ok(())
    } else {
        Err(SearchError::InvalidArgument(
            "a document needs a positive id",
        ))
    }
}

/// Table names go into SQL as written, so only plain names get that far.
fn safe_table(table: &str) -> Result<&str, SearchError> {
    let safe = !table.is_empty()
        && table
            .bytes()
            .all(|byte| byte.is_ascii_alphanumeric() || byte == b'_');
    if safe {
        Ok(table)
    } else {
        Err(SearchError::InvalidArgument("unsafe Manticore table name"))
    }
}

fn bulk_outcome(
    outcome: Result<(), SearchError>,
    count: usize,
    invalid: usize,
    what: &str,
) -> BulkResult {
    match outcome {
        Ok(()) => BulkResult {
            success: count,
            errors: invalid,
        },
        Err(error) => {
            tracing::error!(%error, what, "bulk indexing failed");
            BulkResult {
                success: 0,
                errors: invalid + count,
            }
        }
    }
}

impl SearchClient {
    pub async fn insert_predb(&self, hit: &PredbHit) -> Result<(), SearchError> {
        require_id(hit.id)?;
        let table = self.config.indexes.table("predb");
        self.replace_document(table, hit.id, &predb_fields(hit))
            .await
    }

    /// Writes many PreDB rows. With `replace_existing` false this inserts instead, so a row a
    /// producer indexed since the caller looked keeps its fresher document, and the whole batch
    /// fails on a conflict. Reconcile uses that, and checks the acknowledgement closely because
    /// Manticore doesn't report every bulk error as an error.
    pub async fn bulk_insert_predb(&self, hits: &[PredbHit], replace_existing: bool) -> BulkResult {
        let rows: Vec<(i64, Value)> = hits
            .iter()
            .filter(|hit| hit.id > 0)
            .map(|hit| (hit.id, predb_fields(hit)))
            .collect();
        if rows.is_empty() {
            return BulkResult::default();
        }
        let table = self.config.indexes.table("predb");
        let outcome = if replace_existing {
            self.replace_documents(table, &rows).await
        } else {
            self.insert_documents(table, &rows)
                .await
                .and_then(|response| {
                    if insert_acknowledged(&response, table, rows.len()) {
                        Ok(())
                    } else {
                        Err(SearchError::Malformed(format!(
                            "unexpected bulk acknowledgement: {response}"
                        )))
                    }
                })
        };
        bulk_outcome(outcome, rows.len(), 0, "predb")
    }

    pub async fn insert_movie(&self, movie: &MovieDocument) -> Result<(), SearchError> {
        require_id(movie.id)?;
        let table = self.config.indexes.table("movies");
        self.replace_document(table, movie.id, movie).await
    }

    /// Rewrites a movie's document from its row. A row that's gone is left alone, as in PHP, and
    /// the result is false.
    pub async fn update_movie(&self, db: &MySqlPool, movie_id: i64) -> Result<bool, SearchError> {
        require_id(movie_id)?;
        let movie = sqlx::query_as::<_, MovieDocument>(
            "SELECT CAST(id AS SIGNED) AS id, imdbid, CAST(tmdbid AS SIGNED) AS tmdbid, \
             CAST(traktid AS SIGNED) AS traktid, title, year, genre, actors, director, rating, plot \
             FROM movieinfo WHERE id = ?",
        )
        .bind(movie_id)
        .fetch_optional(db)
        .await
        .map_err(|error| SearchError::Database(error.to_string()))?;
        let Some(movie) = movie else {
            return Ok(false);
        };
        self.insert_movie(&movie).await?;
        Ok(true)
    }

    pub async fn delete_movie(&self, id: i64) -> Result<(), SearchError> {
        require_id(id)?;
        let table = self.config.indexes.table("movies");
        self.delete_documents(table, &[id]).await
    }

    pub async fn bulk_insert_movies(&self, movies: &[MovieDocument]) -> BulkResult {
        let rows: Vec<(i64, Value)> = movies
            .iter()
            .filter(|movie| movie.id > 0)
            .map(|movie| (movie.id, serde_json::to_value(movie).unwrap_or(Value::Null)))
            .collect();
        let invalid = movies.len() - rows.len();
        if rows.is_empty() {
            return BulkResult {
                success: 0,
                errors: invalid,
            };
        }
        let table = self.config.indexes.table("movies");
        bulk_outcome(
            self.replace_documents(table, &rows).await,
            rows.len(),
            invalid,
            "movies",
        )
    }

    pub async fn insert_tv_show(&self, show: &TvShowDocument) -> Result<(), SearchError> {
        require_id(show.id)?;
        let table = self.config.indexes.table("tvshows");
        self.replace_document(table, show.id, show).await
    }

    pub async fn delete_tv_show(&self, id: i64) -> Result<(), SearchError> {
        require_id(id)?;
        let table = self.config.indexes.table("tvshows");
        self.delete_documents(table, &[id]).await
    }

    pub async fn bulk_insert_tv_shows(&self, shows: &[TvShowDocument]) -> BulkResult {
        let rows: Vec<(i64, Value)> = shows
            .iter()
            .filter(|show| show.id > 0)
            .map(|show| (show.id, serde_json::to_value(show).unwrap_or(Value::Null)))
            .collect();
        let invalid = shows.len() - rows.len();
        if rows.is_empty() {
            return BulkResult {
                success: 0,
                errors: invalid,
            };
        }
        let table = self.config.indexes.table("tvshows");
        bulk_outcome(
            self.replace_documents(table, &rows).await,
            rows.len(),
            invalid,
            "tvshows",
        )
    }

    /// Upserts a metadata document, creating the table the first time this client needs it.
    /// `document` holds the fields, without the id.
    pub async fn insert_secondary(
        &self,
        index: SecondaryIndex,
        id: i64,
        document: &Value,
    ) -> Result<(), SearchError> {
        require_id(id)?;
        let table = self.config.indexes.table(index.logical());
        self.create_index_if_missing(table).await;
        self.replace_document(table, id, document).await
    }

    pub async fn delete_secondary(
        &self,
        index: SecondaryIndex,
        id: i64,
    ) -> Result<(), SearchError> {
        require_id(id)?;
        let table = self.config.indexes.table(index.logical());
        self.delete_documents(table, &[id]).await
    }

    /// Upserts metadata documents. Each holds its fields plus an `id`, and one without a positive
    /// id counts as an error.
    pub async fn bulk_insert_secondary(
        &self,
        index: SecondaryIndex,
        documents: &[Value],
    ) -> BulkResult {
        let mut rows = Vec::with_capacity(documents.len());
        let mut invalid = 0;
        for document in documents {
            let mut fields = document.as_object().cloned().unwrap_or_default();
            match fields
                .remove("id")
                .and_then(|id| id.as_i64())
                .filter(|id| *id > 0)
            {
                Some(id) => rows.push((id, Value::Object(fields))),
                None => invalid += 1,
            }
        }
        if rows.is_empty() {
            return BulkResult {
                success: 0,
                errors: invalid,
            };
        }
        let table = self.config.indexes.table(index.logical());
        self.create_index_if_missing(table).await;
        bulk_outcome(
            self.replace_documents(table, &rows).await,
            rows.len(),
            invalid,
            index.logical(),
        )
    }

    /// Creates a registered table once per client. A failure here surfaces on the write after it.
    async fn create_index_if_missing(&self, table: &str) {
        if self.table_verified(table) {
            return;
        }
        let Some(logical) = self.config.indexes.logical(table) else {
            return;
        };
        match self.sql(&create_table_sql(table, logical)).await {
            Ok(_) => info!(table, "created the Manticore table"),
            Err(error) if error.to_string().contains("already exists") => {}
            Err(error) => {
                tracing::error!(table, %error, "creating the Manticore table failed");
                return;
            }
        }
        self.mark_table_verified(table);
    }

    /// Empties configured tables, creating any that doesn't exist. Every table is tried, and the
    /// result is the first error.
    pub async fn truncate_indexes(&self, tables: &[&str]) -> Result<(), SearchError> {
        let mut first_error = None;
        for table in tables {
            if self.config.indexes.configured(table).is_none() {
                first_error.get_or_insert(SearchError::InvalidArgument("unsupported index"));
                continue;
            }
            let statement = format!("TRUNCATE TABLE {}", safe_table(table)?);
            match self.sql(&statement).await {
                Ok(_) => info!(table, "truncated the index"),
                Err(error) if error.to_string().contains("does not exist") => {
                    self.create_index_if_missing(table).await;
                }
                Err(error) => {
                    first_error.get_or_insert(error);
                }
            }
        }
        first_error.map_or(Ok(()), Err)
    }

    /// Flushes and optimizes every configured table.
    pub async fn optimize_indexes(&self) -> Result<(), SearchError> {
        let mut first_error = None;
        for logical in crate::config::LOGICAL_INDEXES {
            let table = safe_table(self.config.indexes.table(logical))?;
            for statement in [
                format!("FLUSH TABLE {table}"),
                format!("OPTIMIZE TABLE {table}"),
            ] {
                if let Err(error) = self.sql(&statement).await {
                    tracing::error!(table, %error, "optimizing the index failed");
                    first_error.get_or_insert(error);
                    break;
                }
            }
        }
        first_error.map_or(Ok(()), Err)
    }

    pub async fn count_index_documents(&self, table: &str) -> Result<u64, SearchError> {
        let table = safe_table(table)?;
        let rows = self
            .sql(&format!("SELECT COUNT(*) AS total FROM `{table}`"))
            .await?;
        rows.first()
            .and_then(|row| row.get("total"))
            .and_then(|total| {
                total
                    .as_u64()
                    .or_else(|| total.as_str().and_then(|text| text.parse().ok()))
            })
            .ok_or_else(|| SearchError::Malformed("the count has no total".to_owned()))
    }

    /// A stable page of indexed release ids, for finding drift and orphans.
    pub async fn indexed_release_ids(
        &self,
        limit: u64,
        offset: u64,
    ) -> Result<Vec<i64>, SearchError> {
        let limit = limit.clamp(1, 10_000);
        let table = safe_table(self.config.indexes.table("releases"))?;
        let rows = self
            .sql(&format!(
                "SELECT id FROM {table} ORDER BY id ASC LIMIT {offset},{limit} OPTION max_matches={}",
                offset + limit
            ))
            .await?;
        debug!(rows = rows.len(), "read a page of indexed ids");
        rows.iter()
            .map(|row| crate::read::parse_id(row.get("id").unwrap_or(&Value::Null)))
            .collect()
    }
}

/// One table and no empty lines produce one transaction acknowledgement.
fn insert_acknowledged(response: &Value, table: &str, count: usize) -> bool {
    let blank = |field: &str| match response.get(field) {
        None | Some(Value::Null) => true,
        Some(Value::String(text)) => text.is_empty(),
        Some(_) => false,
    };
    let Some(items) = response.get("items").and_then(Value::as_array) else {
        return false;
    };
    let Some(ack) = items.first().and_then(|item| item.get("bulk")) else {
        return false;
    };
    response.get("errors") == Some(&Value::Bool(false))
        && blank("error")
        && blank("warning")
        && response
            .get("skipped_lines")
            .is_none_or(|lines| lines == &json!(0))
        && items.len() == 1
        && ack.get("table").and_then(Value::as_str) == Some(table)
        && ack.get("created").and_then(Value::as_u64) == Some(count as u64)
        && ack.get("deleted") == Some(&json!(0))
        && ack.get("updated") == Some(&json!(0))
        && ack.get("result").and_then(Value::as_str) == Some("created")
        && ack.get("status") == Some(&json!(201))
}

#[cfg(test)]
mod tests {
    use serde_json::json;

    use super::{insert_acknowledged, safe_table};

    #[test]
    fn only_a_complete_acknowledgement_counts() {
        let good = json!({"errors": false, "items": [{"bulk": {
            "table": "t", "created": 2, "deleted": 0, "updated": 0, "result": "created", "status": 201
        }}]});
        assert!(insert_acknowledged(&good, "t", 2));
        assert!(!insert_acknowledged(&good, "t", 3));
        assert!(!insert_acknowledged(&good, "other", 2));
        let mut warned = good.clone();
        warned["warning"] = json!("partial");
        assert!(!insert_acknowledged(&warned, "t", 2));
        let mut skipped = good.clone();
        skipped["skipped_lines"] = json!(1);
        assert!(!insert_acknowledged(&skipped, "t", 2));
        assert!(!insert_acknowledged(
            &json!({"errors": false, "items": []}),
            "t",
            2
        ));
    }

    #[test]
    fn table_names_stay_plain() {
        assert!(safe_table("releases_rt").is_ok());
        assert!(safe_table("a; DROP TABLE b").is_err());
        assert!(safe_table("").is_err());
    }
}

//! The Tantivy engine. Holds release IDs and just enough to filter and sort, so results come back
//! as IDs for the catalog to fill in.

use crate::normalize::{index_words, parse_query};
use crate::{IndexedRelease, Page, Query, TextIndex, TextIndexWriter};
use anyhow::{Context, Result, bail};
use indexer_telemetry::catalog;
use std::ops::Bound::{Included, Unbounded};
use std::path::Path;
use std::thread;
use std::time::Instant;
use tantivy::collector::{Count, TopDocs};
use tantivy::columnar::Column;
use tantivy::directory::MmapDirectory;
use tantivy::indexer::NoMergePolicy;
use tantivy::query::{AllQuery, BooleanQuery, Occur, RangeQuery, TermQuery};
use tantivy::schema::{
    FAST, Field, INDEXED, IndexRecordOption, Schema, TextFieldIndexing, TextOptions,
};
use tantivy::tokenizer::{TextAnalyzer, WhitespaceTokenizer};
use tantivy::{
    Index, IndexReader, IndexWriter, Order, ReloadPolicy, Searcher, TantivyDocument, Term, doc,
};

const WORDS: &str = "words";
const ID: &str = "id";
const SORT_KEY: &str = "sort_key";
const CATEGORY: &str = "category";
const SIZE: &str = "size";
/// The words arrive already split by `normalize`, so Tantivy only has to find the spaces.
const WORDS_TOKENIZER: &str = "release_words";

const LIVE_WRITER_THREADS: usize = 2;
const LIVE_WRITER_MEMORY_BYTES: usize = 200_000_000;
const BUILD_MAX_THREADS: usize = 8;
const BUILD_WRITER_MEMORY_BYTES: usize = 2_000_000_000;

#[derive(Clone, Copy)]
struct Fields {
    words: Field,
    id: Field,
    sort_key: Field,
    category: Field,
    size: Field,
}

fn schema() -> (Schema, Fields) {
    let mut b = Schema::builder();
    let indexing = TextFieldIndexing::default()
        .set_tokenizer(WORDS_TOKENIZER)
        .set_index_option(IndexRecordOption::Basic);
    let fields = Fields {
        words: b.add_text_field(WORDS, TextOptions::default().set_indexing_options(indexing)),
        id: b.add_i64_field(ID, INDEXED | FAST),
        sort_key: b.add_u64_field(SORT_KEY, FAST),
        category: b.add_u64_field(CATEGORY, FAST),
        size: b.add_u64_field(SIZE, FAST),
    };
    (b.build(), fields)
}

/// Newest first, with the ID breaking ties so that pages stay stable when segments merge.
fn sort_key(release: &IndexedRelease) -> u64 {
    let posted = release.posted_at.unwrap_or(0).clamp(0, u32::MAX as i64) as u64;
    posted << 32 | u64::from(release.id as u32)
}

fn to_document(f: Fields, release: &IndexedRelease) -> TantivyDocument {
    doc!(
        f.words => index_words(&release.name).join(" "),
        f.id => release.id,
        f.sort_key => sort_key(release),
        f.category => u64::from(release.category),
        f.size => release.size_bytes,
    )
}

/// An index on disk, ready to search.
pub struct TantivyIndex {
    index: Index,
    reader: IndexReader,
    fields: Fields,
}

impl TantivyIndex {
    /// Opens the index in `dir`, creating it if needed.
    pub fn open(dir: &Path) -> Result<Self> {
        std::fs::create_dir_all(dir)
            .with_context(|| format!("creating search index directory {}", dir.display()))?;
        let (schema, fields) = schema();
        let directory = MmapDirectory::open(dir)
            .with_context(|| format!("opening search index in {}", dir.display()))?;
        let index = Index::open_or_create(directory, schema)
            .with_context(|| format!("opening search index in {}", dir.display()))?;
        let words = TextAnalyzer::builder(WhitespaceTokenizer::default()).build();
        index.tokenizers().register(WORDS_TOKENIZER, words);
        let reader = index
            .reader_builder()
            .reload_policy(ReloadPolicy::Manual)
            .try_into()?;
        Ok(Self {
            index,
            reader,
            fields,
        })
    }

    /// Builds a new index in `dir` in one go, merged into a single segment for the fastest reads.
    /// Refuses a directory that already holds releases. Swap the result in place of a damaged
    /// index, since all 3M names rebuild in seconds.
    pub fn build(
        dir: &Path,
        releases: impl IntoIterator<Item = IndexedRelease>,
        watermark: u64,
    ) -> Result<Self> {
        let index = Self::open(dir)?;
        if index.reader.searcher().num_docs() > 0 {
            bail!("search index in {} already holds releases", dir.display());
        }
        let threads = thread::available_parallelism()
            .map_or(1, |n| n.get())
            .min(BUILD_MAX_THREADS);
        let mut writer = index
            .index
            .writer_with_num_threads::<TantivyDocument>(threads, BUILD_WRITER_MEMORY_BYTES)?;
        writer.set_merge_policy(Box::new(NoMergePolicy));
        for release in releases {
            writer.add_document(to_document(index.fields, &release))?;
        }
        let mut prepared = writer.prepare_commit()?;
        prepared.set_payload(&watermark.to_string());
        prepared.commit()?;
        let segments = index.index.searchable_segment_ids()?;
        if segments.len() > 1 {
            writer.merge(&segments).wait()?;
        }
        writer.garbage_collect_files().wait()?;
        writer.wait_merging_threads()?;
        index.reader.reload()?;
        Ok(index)
    }

    /// Starts the one writer this index allows.
    pub fn writer(&self) -> Result<TantivyWriter> {
        let writer = self
            .index
            .writer_with_num_threads(LIVE_WRITER_THREADS, LIVE_WRITER_MEMORY_BYTES)
            .context("starting the search index writer")?;
        Ok(TantivyWriter {
            writer,
            reader: self.reader.clone(),
            fields: self.fields,
        })
    }

    fn query(&self, q: &Query) -> BooleanQuery {
        let f = self.fields;
        let word = |w: &String| -> Box<dyn tantivy::query::Query> {
            Box::new(TermQuery::new(
                Term::from_field_text(f.words, w),
                IndexRecordOption::Basic,
            ))
        };
        let parsed = parse_query(&q.text);
        let mut clauses: Vec<(Occur, Box<dyn tantivy::query::Query>)> =
            parsed.must.iter().map(|w| (Occur::Must, word(w))).collect();
        for group in &parsed.must_not {
            let all = group.iter().map(word).collect();
            clauses.push((Occur::MustNot, Box::new(BooleanQuery::intersection(all))));
        }
        if parsed.must.is_empty() {
            clauses.push((Occur::Must, Box::new(AllQuery)));
        }
        if !q.categories.is_empty() {
            let ranges = q.categories.iter().map(|&(low, high)| {
                let range = RangeQuery::new(
                    Included(Term::from_field_u64(f.category, u64::from(low))),
                    Included(Term::from_field_u64(f.category, u64::from(high))),
                );
                Box::new(range) as Box<dyn tantivy::query::Query>
            });
            clauses.push((Occur::Must, Box::new(BooleanQuery::union(ranges.collect()))));
        }
        if let Some(min) = q.min_size_bytes {
            let range = RangeQuery::new(Included(Term::from_field_u64(f.size, min)), Unbounded);
            clauses.push((Occur::Must, Box::new(range)));
        }
        BooleanQuery::new(clauses)
    }
}

fn release_ids(
    searcher: &Searcher,
    hits: Vec<(Option<u64>, tantivy::DocAddress)>,
) -> Result<Vec<i64>> {
    let mut columns: Vec<Option<Column<i64>>> = vec![None; searcher.segment_readers().len()];
    let mut ids = Vec::with_capacity(hits.len());
    for (_, address) in hits {
        let slot = &mut columns[address.segment_ord as usize];
        if slot.is_none() {
            let reader = searcher.segment_reader(address.segment_ord);
            *slot = Some(reader.fast_fields().i64(ID)?);
        }
        let id = slot.as_ref().and_then(|c| c.first(address.doc_id));
        ids.push(id.context("indexed release has no ID")?);
    }
    Ok(ids)
}

impl TextIndex for TantivyIndex {
    fn search(&self, q: &Query) -> Result<Page> {
        let started = Instant::now();
        let query = self.query(q);
        let searcher = self.reader.searcher();
        let page = if q.limit == 0 {
            Page {
                ids: Vec::new(),
                total: searcher.search(&query, &Count)? as u64,
            }
        } else {
            let top = TopDocs::with_limit(q.limit)
                .and_offset(q.offset)
                .order_by_u64_field(SORT_KEY, Order::Desc);
            let (total, hits) = searcher.search(&query, &(Count, top))?;
            Page {
                ids: release_ids(&searcher, hits)?,
                total: total as u64,
            }
        };
        metrics::histogram!(catalog::SEARCH_QUERY_DURATION, "engine" => "tantivy")
            .record(started.elapsed().as_secs_f64());
        Ok(page)
    }

    fn watermark(&self) -> Result<Option<u64>> {
        let Some(payload) = self.index.load_metas()?.payload else {
            return Ok(None);
        };
        let watermark = payload
            .parse()
            .with_context(|| format!("reading search index watermark {payload:?}"))?;
        Ok(Some(watermark))
    }
}

/// The index's one writer. Searches carry on during writes and commits.
pub struct TantivyWriter {
    writer: IndexWriter<TantivyDocument>,
    reader: IndexReader,
    fields: Fields,
}

impl TextIndexWriter for TantivyWriter {
    fn upsert(&mut self, releases: &[IndexedRelease]) -> Result<()> {
        for release in releases {
            self.writer
                .delete_term(Term::from_field_i64(self.fields.id, release.id));
            self.writer
                .add_document(to_document(self.fields, release))?;
        }
        Ok(())
    }

    fn remove(&mut self, ids: &[i64]) -> Result<()> {
        for &id in ids {
            self.writer
                .delete_term(Term::from_field_i64(self.fields.id, id));
        }
        Ok(())
    }

    fn commit(&mut self, watermark: u64) -> Result<()> {
        let mut prepared = self.writer.prepare_commit()?;
        prepared.set_payload(&watermark.to_string());
        prepared.commit()?;
        self.reader.reload()?;
        Ok(())
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    fn release(id: i64, name: &str, posted_at: i64) -> IndexedRelease {
        IndexedRelease {
            id,
            name: name.into(),
            category: 5040,
            size_bytes: 2_000_000_000,
            posted_at: Some(posted_at),
        }
    }

    fn query(text: &str) -> Query {
        Query {
            text: text.into(),
            limit: 100,
            ..Query::default()
        }
    }

    fn ids(index: &TantivyIndex, text: &str) -> Vec<i64> {
        index.search(&query(text)).unwrap().ids
    }

    fn indexed(releases: &[IndexedRelease]) -> (tempfile::TempDir, TantivyIndex) {
        let dir = tempfile::tempdir().unwrap();
        let index = TantivyIndex::build(dir.path(), releases.to_vec(), 1).unwrap();
        (dir, index)
    }

    #[test]
    fn every_word_is_required() {
        let (_dir, index) = indexed(&[
            release(1, "Silo.S03E02.1080p.WEB-DL", 100),
            release(2, "Silo.S03E02.720p.WEB-DL", 200),
            release(3, "Other.S03E02.1080p.WEB-DL", 300),
        ]);
        assert_eq!(ids(&index, "silo s03e02 1080p"), [1]);
        assert_eq!(ids(&index, "nothing here"), Vec::<i64>::new());
    }

    #[test]
    fn a_neighbouring_episode_never_matches() {
        let (_dir, index) = indexed(&[
            release(1, "Silo.S03E02.1080p", 100),
            release(2, "Silo.S03E03.1080p", 200),
        ]);
        assert_eq!(ids(&index, "silo s03e02"), [1]);
    }

    #[test]
    fn other_spellings_of_a_name_are_found() {
        let (_dir, index) = indexed(&[release(1, "It's.Okay.S01.E19.1080p.H.264-GRP", 100)]);
        assert_eq!(ids(&index, "its okay s01e19 h264"), [1]);
        assert_eq!(ids(&index, "it's okay s1e19 H.264"), [1]);
    }

    #[test]
    fn long_names_use_every_word() {
        let (_dir, index) = indexed(&[
            release(1, "A.B.C.D.E.F.G.H.I.J.K.L.1080p-ONE", 100),
            release(2, "A.B.C.D.E.F.G.H.I.J.K.L.1080p-TWO", 200),
        ]);
        assert_eq!(ids(&index, "a b c d e f g h i j k l 1080p one"), [1]);
    }

    #[test]
    fn results_are_newest_first_with_ties_broken_by_id() {
        let (_dir, index) = indexed(&[
            release(1, "Show.One", 100),
            release(2, "Show.Two", 300),
            release(3, "Show.Three", 200),
            release(4, "Show.Four", 200),
        ]);
        assert_eq!(ids(&index, "show"), [2, 4, 3, 1]);
    }

    #[test]
    fn a_release_with_no_time_sorts_last() {
        let mut undated = release(1, "Show.One", 0);
        undated.posted_at = None;
        let mut ancient = release(2, "Show.Two", 0);
        ancient.posted_at = Some(-5);
        let (_dir, index) = indexed(&[undated, ancient, release(3, "Show.Three", 100)]);
        assert_eq!(ids(&index, "show")[0], 3);
    }

    #[test]
    fn pages_slice_the_results_and_report_the_total() {
        let all: Vec<_> = (1..=10)
            .map(|i| release(i, &format!("Show.Part{i}"), i * 100))
            .collect();
        let (_dir, index) = indexed(&all);
        let page = |offset, limit| {
            index
                .search(&Query {
                    text: "show".into(),
                    offset,
                    limit,
                    ..Query::default()
                })
                .unwrap()
        };
        assert_eq!(
            page(2, 3),
            Page {
                ids: vec![8, 7, 6],
                total: 10
            }
        );
        assert_eq!(
            page(9, 5),
            Page {
                ids: vec![1],
                total: 10
            }
        );
        assert_eq!(
            page(0, 0),
            Page {
                ids: vec![],
                total: 10
            }
        );
        assert_eq!(
            page(50, 5),
            Page {
                ids: vec![],
                total: 10
            }
        );
    }

    #[test]
    fn no_words_means_every_release() {
        let (_dir, index) = indexed(&[release(1, "One", 100), release(2, "Two", 200)]);
        assert_eq!(ids(&index, ""), [2, 1]);
    }

    #[test]
    fn exclusions_remove_matches() {
        let (_dir, index) = indexed(&[
            release(1, "Show.1080p.WEB-DL", 100),
            release(2, "Show.1080p.CAM", 200),
        ]);
        assert_eq!(ids(&index, "show -cam"), [1]);
        assert_eq!(ids(&index, "show --web-dl"), [2]);
        assert_eq!(ids(&index, "!cam"), [1]);
    }

    #[test]
    fn filters_apply_categories_and_size() {
        let mut movie = release(1, "Show.Movie", 100);
        movie.category = 2040;
        let mut small = release(2, "Show.Small", 200);
        small.size_bytes = 100;
        let (_dir, index) = indexed(&[movie, small, release(3, "Show.Tv", 300)]);
        let find = |categories: Vec<(u32, u32)>, min_size_bytes| {
            index
                .search(&Query {
                    text: "show".into(),
                    categories,
                    min_size_bytes,
                    limit: 100,
                    ..Query::default()
                })
                .unwrap()
                .ids
        };
        assert_eq!(find(vec![(2000, 2999)], None), [1]);
        assert_eq!(find(vec![(5000, 5999)], None), [3, 2]);
        assert_eq!(find(vec![(2000, 2999), (5040, 5040)], None), [3, 2, 1]);
        assert_eq!(find(vec![], Some(500)), [3, 1]);
        assert_eq!(find(vec![(5000, 5999)], Some(500)), [3]);
    }

    #[test]
    fn a_single_huge_release_passes_the_size_filter() {
        let mut remux = release(1, "Movie.2160p.Remux", 100);
        remux.size_bytes = 60_000_000_000;
        let (_dir, index) = indexed(&[remux]);
        let page = index
            .search(&Query {
                text: "movie remux".into(),
                min_size_bytes: Some(500_000_000),
                limit: 1,
                ..Query::default()
            })
            .unwrap();
        assert_eq!(page.ids, [1]);
    }

    #[test]
    fn changes_become_searchable_on_commit() {
        let (_dir, index) = indexed(&[release(1, "Old.Name", 100)]);
        let mut writer = index.writer().unwrap();
        writer.upsert(&[release(2, "Fresh.Name", 200)]).unwrap();
        assert_eq!(ids(&index, "fresh"), Vec::<i64>::new());
        writer.commit(2).unwrap();
        assert_eq!(ids(&index, "fresh"), [2]);
    }

    #[test]
    fn upsert_replaces_a_renamed_release() {
        let (_dir, index) = indexed(&[release(1, "a1b2c3d4e5f6", 100)]);
        let mut writer = index.writer().unwrap();
        writer.upsert(&[release(1, "Silo.S03E02", 100)]).unwrap();
        writer.commit(2).unwrap();
        assert_eq!(ids(&index, "a1b2c3d4e5f6"), Vec::<i64>::new());
        assert_eq!(ids(&index, "silo"), [1]);
        assert_eq!(index.search(&query("")).unwrap().total, 1);
    }

    #[test]
    fn remove_takes_a_release_out() {
        let (_dir, index) = indexed(&[release(1, "Show.One", 100), release(2, "Show.Two", 200)]);
        let mut writer = index.writer().unwrap();
        writer.remove(&[2]).unwrap();
        writer.commit(2).unwrap();
        assert_eq!(ids(&index, "show"), [1]);
    }

    #[test]
    fn the_watermark_survives_a_reopen() {
        let dir = tempfile::tempdir().unwrap();
        {
            let index = TantivyIndex::open(dir.path()).unwrap();
            assert_eq!(index.watermark().unwrap(), None);
            let mut writer = index.writer().unwrap();
            writer.upsert(&[release(1, "Show.One", 100)]).unwrap();
            writer.commit(42).unwrap();
        }
        let index = TantivyIndex::open(dir.path()).unwrap();
        assert_eq!(index.watermark().unwrap(), Some(42));
        assert_eq!(ids(&index, "show"), [1]);
    }

    #[test]
    fn build_records_the_watermark_and_merges_to_one_segment() {
        let dir = tempfile::tempdir().unwrap();
        let all = (1..=2000).map(|i| release(i, &format!("Show.Part{i}"), i));
        let index = TantivyIndex::build(dir.path(), all, 7).unwrap();
        assert_eq!(index.watermark().unwrap(), Some(7));
        assert_eq!(index.reader.searcher().segment_readers().len(), 1);
        assert_eq!(index.search(&query("show")).unwrap().total, 2000);
    }

    #[test]
    fn build_refuses_an_index_with_releases() {
        let (dir, _index) = indexed(&[release(1, "Show.One", 100)]);
        let again = TantivyIndex::build(dir.path(), vec![release(2, "Show.Two", 200)], 2);
        assert!(again.is_err());
    }
}

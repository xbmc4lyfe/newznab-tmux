//! The engines under test, all answering the same `Q`: every term required, newest first unless a
//! variant says otherwise.

use crate::corpus::{Doc, tokens};
use crate::queries::Q;
use anyhow::{Context, Result, bail};
use indexer_search::{IndexedRelease, Query as IndexerQuery, TextIndex, TextIndexWriter};
use rusqlite::{Connection, OpenFlags, params};
use serde_json::{Value, json};
use std::ops::Bound::{Included, Unbounded};
use std::path::{Path, PathBuf};
use std::sync::Arc;
use std::time::Duration;
use tantivy::collector::{Count, TopDocs};
use tantivy::indexer::NoMergePolicy;
use tantivy::query::{BooleanQuery, Occur, Query, RangeQuery, TermQuery};
use tantivy::schema::{FAST, Field, IndexRecordOption, Schema, TextFieldIndexing, TextOptions};
use tantivy::tokenizer::{LowerCaser, SimpleTokenizer, TextAnalyzer};
use tantivy::{Index, IndexReader, IndexWriter, Order, ReloadPolicy, TantivyDocument, Term, doc};

pub trait Client: Send {
    fn search(&mut self, q: &Q, limit: usize) -> Result<Vec<u32>>;
    /// A later page plus the match total, which a Newznab response carries. Only unfiltered queries.
    fn page(&mut self, q: &Q, offset: usize, limit: usize) -> Result<(Vec<u32>, u64)>;
}

pub trait LiveWriter: Send {
    /// Makes `docs` durable and hands them to the engine. They may become searchable later.
    fn publish(&mut self, docs: &[Doc]) -> Result<()>;
}

pub trait Engine: Send + Sync {
    fn client(&self) -> Result<Box<dyn Client>>;
    fn writer(&self) -> Result<Box<dyn LiveWriter>>;
}

/// `dir` holds the index for local engines. `url` is the server for Meilisearch.
pub fn open(kind: &str, dir: &Path, url: &str) -> Result<Box<dyn Engine>> {
    Ok(match kind {
        "fts5-date" | "fts5none-date" => Box::new(Fts5 {
            path: dir.join("idx.db"),
            bm25: false,
        }),
        "fts5-bm25" => Box::new(Fts5 {
            path: dir.join("idx.db"),
            bm25: true,
        }),
        "indexer" => Box::new(IndexerEngine(Arc::new(indexer_search::TantivyIndex::open(
            dir,
        )?))),
        "tantivy-date" => Box::new(Tantivy::open(dir, false)?),
        "tantivy-bm25" => Box::new(Tantivy::open(dir, true)?),
        "meili-default" => Box::new(Meili {
            url: url.into(),
            mode: MeiliMode::Default,
        }),
        "meili-all" => Box::new(Meili {
            url: url.into(),
            mode: MeiliMode::All,
        }),
        "meili-tuned" => Box::new(Meili {
            url: url.into(),
            mode: MeiliMode::Tuned,
        }),
        "meili-typo7" => Box::new(Meili {
            url: url.into(),
            mode: MeiliMode::Typo7,
        }),
        other => bail!("unknown engine {other}"),
    })
}

// ---------------------------------------------------------------- The indexer's engine

fn indexed_release(d: &Doc) -> IndexedRelease {
    IndexedRelease {
        id: d.seq.into(),
        name: d.name.clone(),
        category: d.cat,
        size_bytes: d.size,
        posted_at: Some(d.ts),
    }
}

pub fn build_indexer(dir: &Path, corpus: &[Doc]) -> Result<()> {
    indexer_search::TantivyIndex::build(dir, corpus.iter().map(indexed_release), 0)?;
    Ok(())
}

struct IndexerEngine(Arc<indexer_search::TantivyIndex>);

struct IndexerClient(Arc<indexer_search::TantivyIndex>);

impl IndexerClient {
    fn run(&self, q: &Q, offset: usize, limit: usize) -> Result<(Vec<u32>, u64)> {
        let mut text = q.terms.join(" ");
        for n in &q.neg {
            text.push_str(" -");
            text.push_str(n);
        }
        let query = IndexerQuery {
            text,
            categories: q.cat.into_iter().collect(),
            min_size_bytes: q.min_size,
            offset,
            limit,
            ..IndexerQuery::default()
        };
        let page = self.0.search(&query)?;
        Ok((page.ids.into_iter().map(|i| i as u32).collect(), page.total))
    }
}

impl Engine for IndexerEngine {
    fn client(&self) -> Result<Box<dyn Client>> {
        Ok(Box::new(IndexerClient(self.0.clone())))
    }
    fn writer(&self) -> Result<Box<dyn LiveWriter>> {
        Ok(Box::new(IndexerWriter(self.0.writer()?)))
    }
}

impl Client for IndexerClient {
    fn search(&mut self, q: &Q, limit: usize) -> Result<Vec<u32>> {
        Ok(self.run(q, 0, limit)?.0)
    }
    fn page(&mut self, q: &Q, offset: usize, limit: usize) -> Result<(Vec<u32>, u64)> {
        self.run(q, offset, limit)
    }
}

struct IndexerWriter(indexer_search::TantivyWriter);

impl LiveWriter for IndexerWriter {
    fn publish(&mut self, docs: &[Doc]) -> Result<()> {
        let releases: Vec<IndexedRelease> = docs.iter().map(indexed_release).collect();
        self.0.upsert(&releases)?;
        self.0.commit(u64::from(docs[0].seq))
    }
}

// ---------------------------------------------------------------- FTS5

pub struct Fts5 {
    path: PathBuf,
    bm25: bool,
}

pub fn build_fts5(dir: &Path, corpus: &[Doc], detail: &str) -> Result<()> {
    std::fs::create_dir_all(dir)?;
    let mut c = Connection::open(dir.join("idx.db"))?;
    c.execute_batch(&format!(
        "PRAGMA journal_mode=OFF; PRAGMA synchronous=OFF; PRAGMA cache_size=-2000000;
         CREATE TABLE r(seq INTEGER PRIMARY KEY, cat INTEGER NOT NULL, size INTEGER NOT NULL, ts INTEGER NOT NULL);
         CREATE VIRTUAL TABLE f USING fts5(n, content='', detail={detail}, tokenize=\"unicode61 remove_diacritics 0\");"
    ))?;
    for chunk in corpus.chunks(200_000) {
        let tx = c.transaction()?;
        {
            let mut a = tx.prepare_cached("INSERT INTO r VALUES (?1,?2,?3,?4)")?;
            let mut b = tx.prepare_cached("INSERT INTO f(rowid,n) VALUES (?1,?2)")?;
            for d in chunk {
                a.execute(params![d.seq, d.cat, d.size as i64, d.ts])?;
                b.execute(params![d.seq, d.name])?;
            }
        }
        tx.commit()?;
    }
    c.execute_batch("INSERT INTO f(f) VALUES('optimize'); PRAGMA journal_mode=WAL; PRAGMA wal_checkpoint(TRUNCATE);")?;
    Ok(())
}

struct Fts5Client {
    c: Connection,
    bm25: bool,
}

fn fts5_match(q: &Q) -> String {
    let quote = |t: &String| format!("\"{t}\"");
    let pos = q.terms.iter().map(quote).collect::<Vec<_>>().join(" AND ");
    if q.neg.is_empty() {
        return pos;
    }
    format!(
        "({pos}) NOT ({})",
        q.neg.iter().map(quote).collect::<Vec<_>>().join(" OR ")
    )
}

impl Engine for Fts5 {
    fn client(&self) -> Result<Box<dyn Client>> {
        let c = Connection::open_with_flags(
            &self.path,
            OpenFlags::SQLITE_OPEN_READ_WRITE | OpenFlags::SQLITE_OPEN_NO_MUTEX,
        )?;
        c.execute_batch("PRAGMA query_only=ON; PRAGMA mmap_size=34359738368; PRAGMA cache_size=-65536; PRAGMA temp_store=MEMORY; PRAGMA busy_timeout=5000;")?;
        Ok(Box::new(Fts5Client { c, bm25: self.bm25 }))
    }
    fn writer(&self) -> Result<Box<dyn LiveWriter>> {
        let c = Connection::open(&self.path)?;
        c.execute_batch(
            "PRAGMA journal_mode=WAL; PRAGMA synchronous=NORMAL; PRAGMA busy_timeout=5000;",
        )?;
        Ok(Box::new(Fts5Writer { c }))
    }
}

impl Client for Fts5Client {
    fn search(&mut self, q: &Q, limit: usize) -> Result<Vec<u32>> {
        let m = fts5_match(q);
        let order = if self.bm25 { "f.rank" } else { "f.rowid DESC" };
        let map = |r: &rusqlite::Row<'_>| r.get::<_, u32>(0);
        if q.cat.is_some() || q.min_size.is_some() {
            let (lo, hi) = q.cat.unwrap_or((0, u32::MAX));
            // CROSS JOIN keeps FTS5 as the driving table, so newest-first stops early.
            let sql = format!(
                "SELECT f.rowid FROM f CROSS JOIN r ON r.seq=f.rowid WHERE f MATCH ?1 AND r.cat BETWEEN ?2 AND ?3 AND r.size>=?4 ORDER BY {order} LIMIT ?5"
            );
            let mut s = self.c.prepare_cached(&sql)?;
            let rows = s.query_map(
                params![m, lo, hi, q.min_size.unwrap_or(0) as i64, limit as i64],
                map,
            )?;
            return Ok(rows.collect::<Result<_, _>>()?);
        }
        let sql = format!("SELECT f.rowid FROM f WHERE f MATCH ?1 ORDER BY {order} LIMIT ?2");
        let mut s = self.c.prepare_cached(&sql)?;
        let rows = s.query_map(params![m, limit as i64], map)?;
        Ok(rows.collect::<Result<_, _>>()?)
    }

    fn page(&mut self, q: &Q, offset: usize, limit: usize) -> Result<(Vec<u32>, u64)> {
        let m = fts5_match(q);
        let mut s = self.c.prepare_cached(
            "SELECT f.rowid FROM f WHERE f MATCH ?1 ORDER BY f.rowid DESC LIMIT ?2 OFFSET ?3",
        )?;
        let rows = s.query_map(params![m, limit as i64, offset as i64], |r| {
            r.get::<_, u32>(0)
        })?;
        let ids: Vec<u32> = rows.collect::<Result<_, _>>()?;
        let mut c = self
            .c
            .prepare_cached("SELECT count(*) FROM f WHERE f MATCH ?1")?;
        let total: i64 = c.query_row(params![m], |r| r.get(0))?;
        Ok((ids, total as u64))
    }
}

struct Fts5Writer {
    c: Connection,
}

impl LiveWriter for Fts5Writer {
    fn publish(&mut self, docs: &[Doc]) -> Result<()> {
        let tx = self.c.transaction()?;
        {
            let mut a = tx.prepare_cached("INSERT INTO r VALUES (?1,?2,?3,?4)")?;
            let mut b = tx.prepare_cached("INSERT INTO f(rowid,n) VALUES (?1,?2)")?;
            for d in docs {
                a.execute(params![d.seq, d.cat, d.size as i64, d.ts])?;
                b.execute(params![d.seq, d.name])?;
            }
        }
        tx.commit()?;
        Ok(())
    }
}

// ---------------------------------------------------------------- Tantivy

#[derive(Clone, Copy)]
struct TFields {
    n: Field,
    seq: Field,
    cat: Field,
    size: Field,
}

fn tantivy_schema() -> (Schema, TFields) {
    let mut b = Schema::builder();
    let idx = TextFieldIndexing::default()
        .set_tokenizer("rel")
        .set_index_option(IndexRecordOption::WithFreqs);
    let n = b.add_text_field("n", TextOptions::default().set_indexing_options(idx));
    let seq = b.add_u64_field("seq", FAST);
    let cat = b.add_u64_field("cat", FAST);
    let size = b.add_u64_field("size", FAST);
    (b.build(), TFields { n, seq, cat, size })
}

fn register_tokenizer(index: &Index) {
    let a = TextAnalyzer::builder(SimpleTokenizer::default())
        .filter(LowerCaser)
        .build();
    index.tokenizers().register("rel", a);
}

pub fn build_tantivy(dir: &Path, corpus: &[Doc]) -> Result<()> {
    std::fs::create_dir_all(dir)?;
    let (schema, f) = tantivy_schema();
    let index = Index::create_in_dir(dir, schema)?;
    register_tokenizer(&index);
    let mut w: IndexWriter<TantivyDocument> = index.writer_with_num_threads(8, 4_000_000_000)?;
    w.set_merge_policy(Box::new(NoMergePolicy));
    for d in corpus {
        w.add_document(doc!(f.n => d.name.as_str(), f.seq => d.seq as u64, f.cat => d.cat as u64, f.size => d.size))?;
    }
    w.commit()?;
    let ids = index.searchable_segment_ids()?;
    if ids.len() > 1 {
        w.merge(&ids).wait()?;
    }
    w.garbage_collect_files().wait()?;
    w.wait_merging_threads()?;
    Ok(())
}

pub struct Tantivy {
    index: Index,
    reader: IndexReader,
    f: TFields,
    bm25: bool,
}

impl Tantivy {
    fn open(dir: &Path, bm25: bool) -> Result<Self> {
        let index = Index::open_in_dir(dir)?;
        register_tokenizer(&index);
        let reader = index
            .reader_builder()
            .reload_policy(ReloadPolicy::Manual)
            .try_into()?;
        let (_, f) = tantivy_schema();
        Ok(Tantivy {
            index,
            reader,
            f,
            bm25,
        })
    }
}

struct TantivyClient {
    reader: IndexReader,
    f: TFields,
    bm25: bool,
}

impl Engine for Tantivy {
    fn client(&self) -> Result<Box<dyn Client>> {
        Ok(Box::new(TantivyClient {
            reader: self.reader.clone(),
            f: self.f,
            bm25: self.bm25,
        }))
    }
    fn writer(&self) -> Result<Box<dyn LiveWriter>> {
        let w: IndexWriter<TantivyDocument> = self.index.writer_with_num_threads(2, 200_000_000)?;
        Ok(Box::new(TantivyWriter {
            w,
            reader: self.reader.clone(),
            f: self.f,
        }))
    }
}

impl TantivyClient {
    fn query(&self, q: &Q) -> BooleanQuery {
        let f = self.f;
        let term = |t: &String, o: IndexRecordOption| -> Box<dyn Query> {
            Box::new(TermQuery::new(Term::from_field_text(f.n, t), o))
        };
        let opt = if self.bm25 {
            IndexRecordOption::WithFreqs
        } else {
            IndexRecordOption::Basic
        };
        let mut clauses: Vec<(Occur, Box<dyn Query>)> = q
            .terms
            .iter()
            .map(|t| (Occur::Must, term(t, opt)))
            .collect();
        clauses.extend(
            q.neg
                .iter()
                .map(|t| (Occur::MustNot, term(t, IndexRecordOption::Basic))),
        );
        if let Some((lo, hi)) = q.cat {
            let r = RangeQuery::new(
                Included(Term::from_field_u64(f.cat, lo as u64)),
                Included(Term::from_field_u64(f.cat, hi as u64)),
            );
            clauses.push((Occur::Must, Box::new(r)));
        }
        if let Some(m) = q.min_size {
            let r = RangeQuery::new(Included(Term::from_field_u64(f.size, m)), Unbounded);
            clauses.push((Occur::Must, Box::new(r)));
        }
        BooleanQuery::new(clauses)
    }
}

impl Client for TantivyClient {
    fn search(&mut self, q: &Q, limit: usize) -> Result<Vec<u32>> {
        let query = self.query(q);
        let searcher = self.reader.searcher();
        if self.bm25 {
            let hits = searcher.search(&query, &TopDocs::with_limit(limit).order_by_score())?;
            let mut out = Vec::with_capacity(hits.len());
            for (_, a) in hits {
                let col = searcher
                    .segment_reader(a.segment_ord)
                    .fast_fields()
                    .u64("seq")?;
                out.push(col.first(a.doc_id).context("seq")? as u32);
            }
            return Ok(out);
        }
        let top = TopDocs::with_limit(limit).order_by_u64_field("seq", Order::Desc);
        let hits = searcher.search(&query, &top)?;
        Ok(hits
            .into_iter()
            .filter_map(|(s, _)| s.map(|v| v as u32))
            .collect())
    }

    fn page(&mut self, q: &Q, offset: usize, limit: usize) -> Result<(Vec<u32>, u64)> {
        let query = self.query(q);
        let searcher = self.reader.searcher();
        let top = TopDocs::with_limit(limit)
            .and_offset(offset)
            .order_by_u64_field("seq", Order::Desc);
        let (total, hits) = searcher.search(&query, &(Count, top))?;
        Ok((
            hits.into_iter()
                .filter_map(|(s, _)| s.map(|v| v as u32))
                .collect(),
            total as u64,
        ))
    }
}

struct TantivyWriter {
    w: IndexWriter<TantivyDocument>,
    reader: IndexReader,
    f: TFields,
}

impl LiveWriter for TantivyWriter {
    fn publish(&mut self, docs: &[Doc]) -> Result<()> {
        let f = self.f;
        for d in docs {
            self.w.add_document(doc!(f.n => d.name.as_str(), f.seq => d.seq as u64, f.cat => d.cat as u64, f.size => d.size))?;
        }
        self.w.commit()?;
        self.reader.reload()?;
        Ok(())
    }
}

// ---------------------------------------------------------------- Meilisearch

#[derive(Clone, Copy, PartialEq)]
enum MeiliMode {
    /// Out of the box, apart from declaring what is filterable and sortable.
    Default,
    /// Defaults, but every query word is required.
    All,
    /// Built for this job: words required, no typos, newest first, separators normalised to spaces.
    Tuned,
    /// Tuned, but typos allowed on words of 7 letters or more, so `S01E02` stays exact. Exact matches
    /// rank above typos, and newest first within each.
    Typo7,
}

pub struct Meili {
    url: String,
    mode: MeiliMode,
}

fn agent() -> ureq::Agent {
    let cfg = ureq::Agent::config_builder()
        .timeout_global(Some(Duration::from_secs(120)))
        .build();
    cfg.into()
}

fn wait_tasks(a: &ureq::Agent, url: &str) -> Result<()> {
    loop {
        let v: Value = a
            .get(format!("{url}/tasks?statuses=enqueued,processing&limit=1"))
            .call()?
            .body_mut()
            .read_json()?;
        if v["total"].as_u64().unwrap_or(1) == 0 {
            break;
        }
        std::thread::sleep(Duration::from_millis(250));
    }
    let v: Value = a
        .get(format!("{url}/tasks?statuses=failed&limit=3"))
        .call()?
        .body_mut()
        .read_json()?;
    if v["total"].as_u64().unwrap_or(0) > 0 {
        bail!("meilisearch tasks failed: {}", v["results"]);
    }
    Ok(())
}

fn meili_doc(d: &Doc, normalise: bool) -> Value {
    let n = if normalise {
        tokens(&d.name).join(" ")
    } else {
        d.name.clone()
    };
    json!({"id": d.seq, "n": n, "c": d.cat, "s": d.size, "p": d.ts})
}

pub fn build_meili(url: &str, corpus: &[Doc], mode: &str) -> Result<()> {
    let tuned = mode != "meili-default";
    let a = agent();
    a.post(format!("{url}/indexes"))
        .send_json(json!({"uid": "r", "primaryKey": "id"}))?;
    let mut settings = json!({
        "searchableAttributes": ["n"],
        "filterableAttributes": ["c", "s"],
        "sortableAttributes": ["p"],
        "displayedAttributes": ["id"]
    });
    if tuned {
        let o = settings.as_object_mut().unwrap();
        if mode == "meili-typo7" {
            o.insert("rankingRules".into(), json!(["words", "typo", "sort"]));
            o.insert(
                "typoTolerance".into(),
                json!({"minWordSizeForTypos": {"oneTypo": 7, "twoTypos": 12}}),
            );
        } else {
            o.insert("rankingRules".into(), json!(["sort", "words"]));
            o.insert("typoTolerance".into(), json!({"enabled": false}));
        }
        o.insert("proximityPrecision".into(), json!("byAttribute"));
        o.insert("prefixSearch".into(), json!("disabled"));
        o.insert("facetSearch".into(), json!(false));
        // A Newznab total must stay honest past the default cap of 1,000.
        o.insert("pagination".into(), json!({"maxTotalHits": 100000}));
    }
    a.patch(format!("{url}/indexes/r/settings"))
        .send_json(&settings)?;
    wait_tasks(&a, url)?;
    for chunk in corpus.chunks(200_000) {
        let mut body = String::with_capacity(chunk.len() * 160);
        for d in chunk {
            body.push_str(&meili_doc(d, tuned).to_string());
            body.push('\n');
        }
        a.post(format!("{url}/indexes/r/documents"))
            .content_type("application/x-ndjson")
            .send(body.as_str())?;
    }
    wait_tasks(&a, url)
}

struct MeiliClient {
    a: ureq::Agent,
    url: String,
    mode: MeiliMode,
}

impl Engine for Meili {
    fn client(&self) -> Result<Box<dyn Client>> {
        Ok(Box::new(MeiliClient {
            a: agent(),
            url: format!("{}/indexes/r/search", self.url),
            mode: self.mode,
        }))
    }
    fn writer(&self) -> Result<Box<dyn LiveWriter>> {
        Ok(Box::new(MeiliWriter {
            a: agent(),
            url: format!("{}/indexes/r/documents", self.url),
            tuned: self.mode != MeiliMode::Default && self.mode != MeiliMode::All,
        }))
    }
}

impl MeiliClient {
    fn body(&self, q: &Q, limit: usize) -> Value {
        let mut text = q.terms.join(" ");
        for n in &q.neg {
            text.push_str(" -");
            text.push_str(n);
        }
        let mut body = json!({"q": text, "limit": limit, "attributesToRetrieve": ["id"]});
        let o = body.as_object_mut().unwrap();
        if self.mode != MeiliMode::Default {
            o.insert("matchingStrategy".into(), json!("all"));
        }
        if matches!(self.mode, MeiliMode::Tuned | MeiliMode::Typo7) {
            o.insert("sort".into(), json!(["p:desc"]));
        }
        let mut filter = Vec::new();
        if let Some((lo, hi)) = q.cat {
            filter.push(format!("c >= {lo} AND c <= {hi}"));
        }
        if let Some(m) = q.min_size {
            filter.push(format!("s >= {m}"));
        }
        if !filter.is_empty() {
            o.insert("filter".into(), json!(filter.join(" AND ")));
        }
        body
    }
}

fn meili_ids(v: &Value) -> Result<Vec<u32>> {
    let hits = v["hits"].as_array().context("no hits")?;
    Ok(hits
        .iter()
        .filter_map(|h| h["id"].as_u64().map(|i| i as u32))
        .collect())
}

impl Client for MeiliClient {
    fn search(&mut self, q: &Q, limit: usize) -> Result<Vec<u32>> {
        let body = self.body(q, limit);
        let v: Value = self
            .a
            .post(&self.url)
            .send_json(&body)?
            .body_mut()
            .read_json()?;
        meili_ids(&v)
    }

    fn page(&mut self, q: &Q, offset: usize, limit: usize) -> Result<(Vec<u32>, u64)> {
        let mut body = self.body(q, limit);
        body["offset"] = json!(offset);
        let v: Value = self
            .a
            .post(&self.url)
            .send_json(&body)?
            .body_mut()
            .read_json()?;
        Ok((
            meili_ids(&v)?,
            v["estimatedTotalHits"].as_u64().unwrap_or(0),
        ))
    }
}

struct MeiliWriter {
    a: ureq::Agent,
    url: String,
    tuned: bool,
}

impl LiveWriter for MeiliWriter {
    fn publish(&mut self, docs: &[Doc]) -> Result<()> {
        let body: Vec<Value> = docs.iter().map(|d| meili_doc(d, self.tuned)).collect();
        self.a.post(&self.url).send_json(&body)?;
        Ok(())
    }
}

pub fn dir_size(p: &Path) -> u64 {
    let Ok(rd) = std::fs::read_dir(p) else {
        return 0;
    };
    rd.flatten()
        .map(|e| match e.metadata() {
            Ok(m) if m.is_dir() => dir_size(&e.path()),
            Ok(m) => m.len(),
            Err(_) => 0,
        })
        .sum()
}

//! Checks the port against `fixtures/golden.json`, which `fixtures/generate.php` records from
//! the PHP reference: the pure helpers, the exact request bodies, and live results from
//! Manticore over the same documents. Live checks skip unless `TEST_MANTICORE_HTTP` is set.

use nntmux_search::query::{
    build_field_search_expression, escape_string, extract_suggestion, fuzzy_clean, has_negation,
    prepare_poster_query, prepare_user_search_query, scope_release_search_query,
};
use nntmux_search::{
    Bounds, CursorCodec, ExternalId, MovieExternalIds, Phrases, ReleaseDocument, ReleaseRow,
    ReleaseSearchQuery, SearchClient, SearchConfig, SearchError, SecondaryIndex, SortDirection,
    SortField, config::IndexNames, create_table_sql,
};
use serde_json::{Value, json};
use wiremock::matchers::{method, path};
use wiremock::{Mock, MockServer, ResponseTemplate};

fn golden() -> Value {
    serde_json::from_str(include_str!("fixtures/golden.json")).unwrap()
}

fn documents() -> Value {
    serde_json::from_str(include_str!("fixtures/documents.json")).unwrap()
}

fn s(value: &Value) -> &str {
    value.as_str().unwrap()
}

fn cases<'a>(golden: &'a Value, name: &str) -> &'a Vec<Value> {
    golden["pure"][name].as_array().unwrap()
}

#[test]
fn prepared_queries_match_php() {
    let golden = golden();
    for case in cases(&golden, "prepare") {
        assert_eq!(
            prepare_user_search_query(s(&case[0])),
            s(&case[1]),
            "{case}"
        );
    }
}

#[test]
fn escaped_strings_match_php() {
    let golden = golden();
    for case in cases(&golden, "escape") {
        assert_eq!(escape_string(s(&case[0])), s(&case[1]), "{case}");
    }
}

#[test]
fn negation_detection_matches_php() {
    let golden = golden();
    for case in cases(&golden, "negation") {
        assert_eq!(
            has_negation(s(&case[0])),
            case[1].as_bool().unwrap(),
            "{case}"
        );
    }
}

#[test]
fn poster_queries_match_php() {
    let golden = golden();
    for case in cases(&golden, "fromname") {
        assert_eq!(prepare_poster_query(s(&case[0])), s(&case[1]), "{case}");
    }
}

#[test]
fn fuzzy_text_matches_php() {
    let golden = golden();
    for case in cases(&golden, "fuzzy") {
        let raw = s(&case[0]);
        let expected = if raw.is_empty() || raw == "0" {
            String::new()
        } else {
            fuzzy_clean(raw)
        };
        assert_eq!(expected, s(&case[1]), "{case}");
    }
}

#[test]
fn field_scopes_match_php() {
    let golden = golden();
    for case in cases(&golden, "scope") {
        let raw = s(&case[0]);
        let prepared = prepare_user_search_query(raw);
        assert_eq!(
            scope_release_search_query(raw, &prepared, s(&case[1])),
            s(&case[2]),
            "{case}"
        );
    }
}

fn pairs(value: &Value) -> Vec<(String, String)> {
    value["__fields"]
        .as_array()
        .unwrap()
        .iter()
        .map(|pair| (s(&pair[0]).to_owned(), s(&pair[1]).to_owned()))
        .collect()
}

#[test]
fn field_expressions_match_php() {
    let golden = golden();
    for case in cases(&golden, "field_expressions") {
        let allowed: Option<Vec<&str>> = case[1]
            .as_array()
            .map(|allowed| allowed.iter().map(s).collect());
        assert_eq!(
            build_field_search_expression(&pairs(&case[0]), allowed.as_deref()),
            case[2].as_str().map(str::to_owned),
            "{case}"
        );
    }
}

#[test]
fn suggestions_match_php() {
    let golden = golden();
    for case in cases(&golden, "suggestions") {
        assert_eq!(
            extract_suggestion(s(&case[0]), s(&case[1])),
            case[2].as_str().map(str::to_owned),
            "{case}"
        );
    }
}

fn int(value: &Value) -> Option<i64> {
    value
        .as_i64()
        .or_else(|| value.as_str().and_then(|text| text.parse().ok()))
}

fn int_or_zero(criteria: &Value, field: &str) -> i64 {
    int(&criteria[field]).unwrap_or(0)
}

fn ints(value: &Value) -> Vec<i64> {
    value
        .as_array()
        .map_or_else(Vec::new, |values| values.iter().filter_map(int).collect())
}

fn phrases(value: &Value) -> Option<Phrases> {
    match value {
        Value::String(text) => Some(Phrases::Text(text.clone())),
        Value::Array(terms) => Some(Phrases::Terms(
            terms.iter().map(|term| s(term).to_owned()).collect(),
        )),
        Value::Object(_) => Some(Phrases::Fields(pairs(value))),
        _ => None,
    }
}

fn bounds(ranges: &Value, field: &str) -> Bounds {
    Bounds {
        min: int(&ranges[field]["min"]),
        max: int(&ranges[field]["max"]),
    }
}

/// `ReleaseSearchQuery::fromCriteria`, for the criteria the fixtures use.
fn query_from(criteria: &Value, limit: i64, offset: i64) -> ReleaseSearchQuery {
    let ranges = &criteria["ranges"];
    ReleaseSearchQuery {
        phrases: phrases(&criteria["phrases"]),
        category_ids: ints(&criteria["category_ids"]),
        excluded_category_ids: ints(&criteria["excluded_category_ids"]),
        release_ids: ints(&criteria["release_ids"]),
        min_size: int_or_zero(criteria, "min_size"),
        max_size: int_or_zero(criteria, "max_size"),
        min_date: int_or_zero(criteria, "min_date"),
        max_date: int_or_zero(criteria, "max_date"),
        group_id: int(&criteria["groups_id"]),
        password_allow_rar: criteria["password_allow_rar"].as_bool().unwrap_or(false),
        password_status_min: int(&criteria["password_status_min"]),
        has_media_info: criteria["has_media_info"].as_bool(),
        media_unique_id: criteria["media_unique_id"].as_str().map(str::to_owned),
        min_video_width: int_or_zero(criteria, "min_video_width"),
        max_video_width: int_or_zero(criteria, "max_video_width"),
        min_video_height: int_or_zero(criteria, "min_video_height"),
        max_video_height: int_or_zero(criteria, "max_video_height"),
        sort_field: criteria["sort_field"]
            .as_str()
            .map_or(SortField::PostDate, SortField::parse),
        sort_direction: criteria["sort_dir"]
            .as_str()
            .map_or(SortDirection::Desc, SortDirection::parse),
        try_fuzzy: criteria["try_fuzzy"].as_bool().unwrap_or(true),
        fuzzy_only: criteria["fuzzy_only"].as_bool().unwrap_or(false),
        limit: limit.max(1) as u64,
        offset: offset.max(0) as u64,
        include_documents: criteria["include_documents"].as_bool().unwrap_or(false),
        ranges: nntmux_search::Ranges {
            size: bounds(ranges, "size"),
            totalpart: bounds(ranges, "totalpart"),
            grabs: bounds(ranges, "grabs"),
            comments: bounds(ranges, "comments"),
            adddate_ts: bounds(ranges, "adddate_ts"),
        },
        min_id: int(&criteria["min_id"]),
        max_id: int(&criteria["max_id"]),
        ..ReleaseSearchQuery::default()
    }
}

async fn recording_server() -> MockServer {
    let server = MockServer::start().await;
    Mock::given(method("POST"))
        .and(path("/sql"))
        .respond_with(ResponseTemplate::new(200).set_body_json(json!([{
            "columns": [{"Counter": {"type": "string"}}],
            "data": [{"Counter": "uptime", "Value": "1"}],
            "total": 1, "error": "", "warning": ""
        }])))
        .mount(&server)
        .await;
    Mock::given(method("POST"))
        .and(path("/search"))
        .respond_with(ResponseTemplate::new(200).set_body_json(json!({
            "took": 0, "timed_out": false,
            "hits": {"total": 0, "total_relation": "eq", "hits": []}
        })))
        .mount(&server)
        .await;
    server
}

async fn search_bodies(server: &MockServer) -> Vec<Value> {
    server
        .received_requests()
        .await
        .unwrap()
        .iter()
        .filter(|request| request.url.path() == "/search")
        .map(|request| serde_json::from_slice(&request.body).unwrap())
        .collect()
}

#[tokio::test]
async fn release_requests_match_php() {
    let golden = golden();
    for (name, case) in golden["requests"].as_object().unwrap() {
        let server = recording_server().await;
        let client = SearchClient::new(SearchConfig::new(server.uri()), None).unwrap();
        let query = query_from(
            &case["criteria"],
            case["limit"].as_i64().unwrap(),
            case["offset"].as_i64().unwrap(),
        );
        client.search_releases(&query).await.unwrap();
        assert_eq!(
            search_bodies(&server).await,
            *case["bodies"].as_array().unwrap(),
            "{name}"
        );
    }
}

fn external_ids(set: &Value) -> Vec<ExternalId> {
    set.as_object()
        .unwrap()
        .iter()
        .filter_map(|(field, value)| match field.as_str() {
            "imdbid" => Some(ExternalId::ImdbId(s(value).to_owned())),
            "tmdbid" => Some(ExternalId::TmdbId(int(value).unwrap())),
            "traktid" => Some(ExternalId::TraktId(int(value).unwrap())),
            "tvdb" => Some(ExternalId::Tvdb(int(value).unwrap())),
            "tvmaze" => Some(ExternalId::Tvmaze(int(value).unwrap())),
            "tvrage" => Some(ExternalId::Tvrage(int(value).unwrap())),
            _ => None,
        })
        .collect()
}

fn external_sets(sets: &Value) -> Vec<ExternalId> {
    sets.as_array()
        .unwrap()
        .iter()
        .flat_map(external_ids)
        .collect()
}

#[tokio::test]
async fn external_id_requests_match_php() {
    let golden = golden();
    for (name, case) in golden["external_requests"].as_object().unwrap() {
        let server = recording_server().await;
        let client = SearchClient::new(SearchConfig::new(server.uri()), None).unwrap();
        client
            .search_by_external_id(
                &external_sets(&case["sets"]),
                case["limit"].as_u64().unwrap(),
            )
            .await
            .unwrap();
        // PHP sorted fields by insertion; the JSON fixture sorts them, so compare as sets.
        let mut actual: Vec<String> = search_bodies(&server)
            .await
            .iter()
            .map(Value::to_string)
            .collect();
        let mut expected: Vec<String> = case["bodies"]
            .as_array()
            .unwrap()
            .iter()
            .map(Value::to_string)
            .collect();
        actual.sort();
        expected.sort();
        assert_eq!(actual, expected, "{name}");
    }
}

#[tokio::test]
async fn a_failed_search_is_an_error_not_an_empty_page() {
    let server = MockServer::start().await;
    Mock::given(method("POST"))
        .and(path("/sql"))
        .respond_with(
            ResponseTemplate::new(200)
                .set_body_json(json!([{"data": [{"Counter": "x"}], "error": ""}])),
        )
        .mount(&server)
        .await;
    Mock::given(method("POST"))
        .and(path("/search"))
        .respond_with(ResponseTemplate::new(500).set_body_json(json!({"error": "boom"})))
        .mount(&server)
        .await;
    let client = SearchClient::new(SearchConfig::new(server.uri()), None).unwrap();
    let query = ReleaseSearchQuery {
        phrases: Some(Phrases::Text("x".to_owned())),
        ..ReleaseSearchQuery::default()
    };
    assert!(matches!(
        client.search_releases(&query).await,
        Err(SearchError::Query(message)) if message == "boom"
    ));
    assert!(client.autocomplete("abc", None).await.is_err());
    assert!(client.suggest("abc", None).await.is_err());
    assert!(
        client
            .search_secondary(SecondaryIndex::Music, "abc", 10)
            .await
            .is_err()
    );
    assert!(
        client
            .search_by_external_id(&[ExternalId::Tvdb(1)], 10)
            .await
            .is_err()
    );
}

async fn server_answering_search_with(response: ResponseTemplate) -> SearchClient {
    let server = MockServer::start().await;
    Mock::given(method("POST"))
        .and(path("/sql"))
        .respond_with(
            ResponseTemplate::new(200)
                .set_body_json(json!([{"data": [{"Counter": "x"}], "error": ""}])),
        )
        .mount(&server)
        .await;
    Mock::given(method("POST"))
        .and(path("/search"))
        .respond_with(response)
        .mount(&server)
        .await;
    let client = SearchClient::new(SearchConfig::new(server.uri()), None).unwrap();
    // Keeps the server alive for the rest of the test.
    std::mem::forget(server);
    client
}

fn deep_query(offset: u64) -> ReleaseSearchQuery {
    ReleaseSearchQuery {
        phrases: Some(Phrases::Text("x".to_owned())),
        offset,
        ..ReleaseSearchQuery::default()
    }
}

#[tokio::test]
async fn a_missing_table_is_not_a_rejected_query() {
    let client =
        server_answering_search_with(ResponseTemplate::new(500).set_body_json(
            json!({"error": "unknown local table(s) 'releases_rt' in search request"}),
        ))
        .await;
    assert!(matches!(
        client.search_releases(&deep_query(0)).await,
        Err(SearchError::Query(_))
    ));
}

#[tokio::test]
async fn a_proxy_failure_is_unavailable() {
    let client = server_answering_search_with(
        ResponseTemplate::new(503).set_body_string("<html>Bad gateway</html>"),
    )
    .await;
    assert!(matches!(
        client.search_releases(&deep_query(0)).await,
        Err(SearchError::Unavailable(_))
    ));
}

fn empty_hits(total: u64) -> ResponseTemplate {
    ResponseTemplate::new(200).set_body_json(json!({
        "timed_out": false,
        "hits": {"total": total, "total_relation": "eq", "hits": []}
    }))
}

#[tokio::test]
async fn paging_past_the_window_is_an_error_and_ends_the_cursor() {
    let client = server_answering_search_with(empty_hits(50_000)).await;
    assert!(matches!(
        client.search_releases(&deep_query(10_000)).await,
        Err(SearchError::BeyondWindow {
            offset: 10_000,
            max_matches: 10_000
        })
    ));

    // The last page inside the window has no next page, however many documents match.
    let hits: Vec<Value> = (1..=100)
        .map(|id| json!({"_id": id, "_source": {}}))
        .collect();
    let client = server_answering_search_with(ResponseTemplate::new(200).set_body_json(json!({
        "timed_out": false,
        "hits": {"total": 50_000, "total_relation": "eq", "hits": hits}
    })))
    .await;
    let page = client.search_releases(&deep_query(9_900)).await.unwrap();
    assert!(!page.has_more);
    let codec = CursorCodec::new("key", "1", 15);
    assert_eq!(codec.next(&page, 9_900, "q"), None);
}

#[tokio::test]
async fn a_page_past_the_last_match_is_empty_without_a_fuzzy_retry() {
    let server = recording_server().await;
    server.reset().await;
    Mock::given(method("POST"))
        .and(path("/sql"))
        .respond_with(
            ResponseTemplate::new(200)
                .set_body_json(json!([{"data": [{"Counter": "x"}], "error": ""}])),
        )
        .mount(&server)
        .await;
    Mock::given(method("POST"))
        .and(path("/search"))
        .respond_with(empty_hits(4))
        .mount(&server)
        .await;
    let client = SearchClient::new(SearchConfig::new(server.uri()), None).unwrap();
    let page = client.search_releases(&deep_query(500)).await.unwrap();
    assert!(page.ids.is_empty() && !page.has_more && !page.fuzzy);
    assert_eq!(search_bodies(&server).await.len(), 1);
}

#[tokio::test]
async fn an_unreachable_server_is_an_error_and_is_remembered() {
    let client = SearchClient::new(SearchConfig::new("http://127.0.0.1:9"), None).unwrap();
    assert!(!client.is_available().await);
    assert!(matches!(
        client.search_releases(&ReleaseSearchQuery::default()).await,
        Err(SearchError::Unavailable(_))
    ));
}

#[tokio::test]
async fn unported_calls_say_so() {
    let client = SearchClient::new(SearchConfig::new("http://127.0.0.1:9"), None).unwrap();
    assert!(matches!(
        client.match_predb_exact("x").await,
        Err(SearchError::NotPorted(_))
    ));
    assert!(matches!(
        client.search_predb_filename_candidates("x").await,
        Err(SearchError::NotPorted(_))
    ));
    assert!(matches!(
        client.search_release_candidates_page("x", 1, 0).await,
        Err(SearchError::NotPorted(_))
    ));
}

#[tokio::test]
async fn hosts_fail_over_to_the_next_endpoint() {
    let server = recording_server().await;
    let mut config = SearchConfig::new("http://127.0.0.1:9");
    config.endpoints.push(server.uri());
    let client = SearchClient::new(config, None).unwrap();
    let page = client
        .search_releases(&ReleaseSearchQuery::default())
        .await
        .unwrap();
    assert!(page.ids.is_empty());
    assert!(page.total_exact);
}

struct LiveTables {
    client: SearchClient,
}

async fn live_tables() -> Option<LiveTables> {
    let Ok(address) = std::env::var("TEST_MANTICORE_HTTP") else {
        eprintln!("TEST_MANTICORE_HTTP is unset, skipping");
        return None;
    };
    let prefix = format!("t{:016x}_", rand::random::<u64>());
    let mut config = SearchConfig::new(format!("http://{address}"));
    config.indexes = IndexNames::prefixed(&prefix);
    let client = SearchClient::new(config, None).unwrap();
    for logical in nntmux_search::config::LOGICAL_INDEXES {
        let table = client.config().indexes.table(logical).to_owned();
        client
            .sql(&create_table_sql(&table, logical))
            .await
            .unwrap();
    }
    let documents = documents();
    let releases: Vec<(i64, Value)> = documents["releases"]
        .as_array()
        .unwrap()
        .iter()
        .map(|row| {
            let row: ReleaseRow = serde_json::from_value(row.clone()).unwrap();
            let document = ReleaseDocument::from(row);
            (document.id, serde_json::to_value(&document).unwrap())
        })
        .collect();
    let table = client.config().indexes.table("releases").to_owned();
    client.replace_documents(&table, &releases).await.unwrap();
    for logical in ["music", "anime", "tvshows", "movies", "predb"] {
        let rows: Vec<(i64, Value)> = documents[logical]
            .as_array()
            .unwrap()
            .iter()
            .map(|row| {
                let mut fields = row.as_object().unwrap().clone();
                let id = fields.remove("id").unwrap().as_i64().unwrap();
                (id, Value::Object(fields))
            })
            .collect();
        let table = client.config().indexes.table(logical).to_owned();
        client.replace_documents(&table, &rows).await.unwrap();
    }
    Some(LiveTables { client })
}

impl LiveTables {
    async fn drop(self) {
        for logical in nntmux_search::config::LOGICAL_INDEXES {
            let table = self.client.config().indexes.table(logical).to_owned();
            self.client
                .sql(&format!("DROP TABLE {table}"))
                .await
                .unwrap();
        }
    }
}

#[tokio::test]
async fn live_results_match_php() {
    let Some(tables) = live_tables().await else {
        return;
    };
    let client = &tables.client;
    let golden = golden();
    let results = &golden["results"];

    for (name, case) in results["pages"].as_object().unwrap() {
        let query = query_from(
            &case["criteria"],
            case["limit"].as_i64().unwrap(),
            case["offset"].as_i64().unwrap(),
        );
        let outcome = client.search_releases(&query).await;
        if case["available"] == json!(false) {
            assert!(outcome.is_err(), "{name}: PHP reported a failed query");
            continue;
        }
        let page = outcome.unwrap_or_else(|error| panic!("{name}: {error}"));
        assert_eq!(json!(page.ids), case["ids"], "{name} ids");
        assert_eq!(json!(page.total), case["total"], "{name} total");
        assert_eq!(json!(page.fuzzy), case["fuzzy"], "{name} fuzzy");
        assert_eq!(json!(page.has_more), case["has_more"], "{name} has_more");
        for (document, expected) in page
            .documents
            .iter()
            .zip(case["documents"].as_array().unwrap())
        {
            assert_eq!(json!(document.id), expected["id"], "{name} document id");
            for (field, value) in serde_json::to_value(document).unwrap().as_object().unwrap() {
                assert_eq!(*value, expected[field], "{name} document field {field}");
            }
        }
        assert_eq!(
            page.documents.len(),
            case["documents"].as_array().unwrap().len(),
            "{name} document count"
        );
    }

    for case in results["autocomplete"].as_array().unwrap() {
        let actual = client.autocomplete(s(&case[0]), None).await.unwrap();
        assert_eq!(json!(actual), case[1], "autocomplete {}", case[0]);
    }
    for case in results["suggest"].as_array().unwrap() {
        let actual = client.suggest(s(&case[0]), None).await.unwrap();
        assert_eq!(json!(actual), case[1], "suggest {}", case[0]);
    }
    for case in results["external"].as_array().unwrap() {
        let actual = client
            .search_by_external_id(&external_sets(&case[0]), 100)
            .await
            .unwrap();
        assert_eq!(json!(actual), case[1], "external {}", case[0]);
    }
    for case in results["secondary"].as_array().unwrap() {
        let index = SecondaryIndex::parse(s(&case[0])).unwrap();
        let actual = client
            .search_secondary(index, s(&case[1]), 100)
            .await
            .unwrap();
        assert_eq!(json!(actual), case[2], "secondary {} {}", case[0], case[1]);
    }
    for case in results["anime"].as_array().unwrap() {
        let actual = client.search_anime_title(s(&case[0]), 100).await.unwrap();
        assert_eq!(json!(actual), case[1], "anime {}", case[0]);
    }
    for case in results["ids"].as_array().unwrap() {
        let actual = client
            .search_release_ids(&phrases(&case[0]).unwrap(), case[1].as_u64().unwrap())
            .await
            .unwrap();
        assert_eq!(json!(actual), case[2], "ids {}", case[0]);
    }
    for case in results["fuzzy_ids"].as_array().unwrap() {
        let (limit, expected) = (case[1].as_u64().unwrap(), &case[2]);
        let outcome = client
            .search_release_ids_with_fuzzy(&phrases(&case[0]).unwrap(), limit, false)
            .await;
        // Manticore refuses a query that is only a negation. PHP swallowed that as no results.
        if case[0] == json!("!circus") {
            assert!(
                matches!(&outcome, Err(SearchError::RejectedQuery(_))),
                "{outcome:?}"
            );
            assert_eq!(expected["ids"], json!([]));
            continue;
        }
        let actual = outcome.unwrap();
        assert_eq!(json!(actual.ids), expected["ids"], "fuzzy ids {}", case[0]);
        assert_eq!(
            json!(actual.fuzzy),
            expected["fuzzy"],
            "fuzzy flag {}",
            case[0]
        );
    }
    for case in results["predb"].as_array().unwrap() {
        let actual = client.search_predb(s(&case[0])).await.unwrap();
        assert_eq!(json!(actual), case[1], "predb {}", case[0]);
    }
    for case in results["tv"].as_array().unwrap() {
        let actual = client.search_tv_shows(s(&case[0])).await.unwrap();
        assert_eq!(json!(actual.id), case[1]["id"], "tv {}", case[0]);
    }
    for case in results["movies"].as_array().unwrap() {
        let actual = client.search_movies(s(&case[0])).await.unwrap();
        assert_eq!(json!(actual), case[1], "movies {}", case[0]);
    }
    for case in results["movies_by_fields"].as_array().unwrap() {
        let actual = client
            .search_movies_by_fields(&pairs(&case[0]), 5000)
            .await
            .unwrap();
        assert_eq!(json!(actual.imdbids), case[1]["imdbids"]);
        assert_eq!(json!(actual.movieinfo_ids), case[1]["movieinfo_ids"]);
        assert_eq!(json!(actual.data), case[1]["data"]);
    }
    for case in results["movie_external"].as_array().unwrap() {
        let ids = MovieExternalIds {
            imdbid: case[0]["imdbid"].as_str().map(str::to_owned),
            tmdbid: case[0]["tmdbid"].as_i64(),
            traktid: case[0]["traktid"].as_i64(),
        };
        let actual = client.search_movie_by_external_ids(&ids).await.unwrap();
        assert_eq!(json!(actual), case[1], "movie external {}", case[0]);
    }

    tables.drop().await;
}

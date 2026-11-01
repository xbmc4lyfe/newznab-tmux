use nntmux_fingerprint_worker::{
    collect::{self, ProviderEvidence},
    manifest,
    nntp::Article,
};
use serde_json::json;

#[test]
fn failed_fetches_count_toward_latency_without_fabricating_article_ms() {
    let xml = format!("<nzb>{}</nzb>", (0..12).map(|n| format!(r#"<file subject='"file{n}.rar"' poster="fixture" date="1"><groups><group>a.b</group></groups><segments><segment bytes="3" number="1">f{n}@fixture</segment></segments></file>"#)).collect::<String>());
    let files = manifest::parse(&xml).unwrap();
    let (samples, _) = manifest::samples(&files);
    assert_eq!(samples.len(), 12);
    let mut provider: ProviderEvidence = serde_json::from_value(json!({
        "account":"primary", "host":"fixture.invalid", "port":"119", "tls":false,
        "implementation":null, "profile":"ok", "articles":{}
    }))
    .unwrap();
    for (index, sample) in samples.iter().enumerate() {
        if index == 11 {
            provider.articles.insert(
                sample.messageid.clone(),
                Ok(Article {
                    headers: vec![format!("Message-ID: <{}>", sample.messageid)],
                    body: vec![],
                    response: format!("220 0 <{}> follows", sample.messageid),
                    ms: 1,
                }),
            );
        } else {
            provider.articles.insert(
                sample.messageid.clone(),
                Err(if index == 0 {
                    "NntpArticleMissingException"
                } else {
                    "RuntimeException"
                }
                .into()),
            );
            provider
                .failed_retrieval_ms
                .insert(sample.messageid.clone(), 20_000);
        }
    }
    let output = collect::analyze(&files, &[provider]);
    let result = &output.data["providers"][0];
    assert_eq!(
        result["retrieval_ms"],
        json!({"p50":20_000,"max":20_000,"total":220_001})
    );
    assert_eq!(
        result["articles"],
        json!({"requested":12,"found":1,"missing":1,"errors":10})
    );
    for article in output.data["articles"].as_array().unwrap().iter().take(11) {
        assert!(article["provider_results"]["primary"]["ms"].is_null());
    }
}

#[test]
fn skipped_provider_does_not_fabricate_fetch_latency() {
    let files = manifest::parse(r#"<nzb><file subject='"file.rar"'><groups><group>a.b</group></groups><segments><segment bytes="3" number="1">f@fixture</segment></segments></file></nzb>"#).unwrap();
    let provider: ProviderEvidence = serde_json::from_value(json!({
        "account":"alternate", "host":"fixture.invalid", "port":"119", "tls":false,
        "implementation":null, "profile":"NntpProviderUnavailableException", "skipped_reason":"recent_provider_failure",
        "articles":{"f@fixture":{"Err":"NntpProviderUnavailableException"}}
    })).unwrap();
    let output = collect::analyze(&files, &[provider]);
    assert_eq!(
        output.data["providers"][0]["retrieval_ms"],
        json!({"p50":null,"max":null,"total":0})
    );
}

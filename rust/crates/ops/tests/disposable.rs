//! The refresh swaps the list in only after a good download.

use anyhow::Result;
use nntmux_ops::disposable::refresh;
use wiremock::matchers::any;
use wiremock::{Mock, MockServer, ResponseTemplate};

async fn serving(status: u16, body: &str) -> MockServer {
    let server = MockServer::start().await;
    Mock::given(any())
        .respond_with(ResponseTemplate::new(status).set_body_string(body))
        .mount(&server)
        .await;
    server
}

#[tokio::test]
async fn a_good_download_replaces_the_list_and_a_bad_one_keeps_it() -> Result<()> {
    let dir = tempfile::tempdir()?;
    let path = dir.path().join("framework/disposable_domains.json");

    let good = serving(200, "B.example\na.example\n").await;
    assert_eq!(refresh(&good.uri(), &path).await?, 2);
    let written = std::fs::read_to_string(&path)?;
    assert_eq!(written, r#"["a.example","b.example"]"#);

    for server in [
        serving(500, "a.example").await,
        serving(200, "<html>oops</html>").await,
    ] {
        assert!(refresh(&server.uri(), &path).await.is_err());
        assert_eq!(std::fs::read_to_string(&path)?, written);
    }
    let leftovers = std::fs::read_dir(path.parent().expect("a parent"))?.count();
    assert_eq!(leftovers, 1);
    Ok(())
}

//! The per-worker download service against a scripted NNTP server.

mod support;

use md5::{Digest, Md5};
use nntmux_postprocess::additional::download::{DownloadKind, Par2FileEntry};

use support::{article, serve, service};

fn ids(list: &[&str]) -> Vec<String> {
    list.iter().map(|id| (*id).to_owned()).collect()
}

#[tokio::test]
async fn a_release_scope_reuses_what_it_already_downloaded() {
    let server = serve(vec![
        ("a@x", article("Movie.nfo", b"first payload")),
        ("b@x", article("Movie.nfo", b"second payload")),
    ])
    .await;
    let mut downloads = service(server.port);
    downloads.begin_release_scope();
    let wanted = ids(&["a@x", "b@x"]);
    let first = downloads
        .download(DownloadKind::Jpg, &wanted, "alt.binaries.test", true)
        .await;
    assert_eq!(
        first.data.as_deref(),
        Some(&b"first payloadsecond payload"[..]),
        "{:?}",
        first.error
    );
    let again = downloads
        .download(DownloadKind::Jpg, &wanted, "alt.binaries.test", true)
        .await;
    assert_eq!(again, first);
    assert_eq!(server.bodies(), 2, "the repeat came from the cache");
    let metrics = downloads.finish_release_scope();
    assert_eq!(metrics.logical_requests, 2);
    assert_eq!(metrics.network_requests, 1);
    assert_eq!(metrics.cache_hits, 1);
    assert_eq!(metrics.bytes_downloaded, 27);
    assert_eq!(metrics.bytes_reused, 27);
    assert_eq!(metrics.media_bytes_downloaded, 27);

    // A new scope starts with an empty cache.
    downloads.begin_release_scope();
    downloads
        .download(DownloadKind::Jpg, &wanted, "alt.binaries.test", true)
        .await;
    assert_eq!(server.bodies(), 4);
}

#[tokio::test]
async fn a_missing_article_is_a_failure_not_data() {
    let server = serve(Vec::new()).await;
    let mut downloads = service(server.port);
    downloads.begin_release_scope();
    let missing = downloads
        .download(
            DownloadKind::Sample,
            &ids(&["gone@x"]),
            "alt.binaries.test",
            true,
        )
        .await;
    assert!(!missing.success());
    assert!(missing.error.is_some());
    assert!(!missing.group_unavailable);
    let empty = downloads
        .download(DownloadKind::Sample, &[], "alt.binaries.test", true)
        .await;
    assert_eq!(empty.error.as_deref(), Some("No message IDs provided"));
}

#[tokio::test]
async fn a_mapped_archive_article_is_not_downloaded_twice() {
    let payload = b"Rar!\x1a\x07\x01\x00 archive start".to_vec();
    let server = serve(vec![("rar@x", article("Movie.part01.rar", &payload))]).await;
    let mut downloads = service(server.port);
    downloads.begin_release_scope();
    let mapped = downloads
        .download_mapping_article("rar@x", "alt.binaries.test")
        .await;
    assert_eq!(mapped.download.data.as_deref(), Some(&payload[..]));
    downloads.retain_mapped_archive(&Par2FileEntry {
        name: "Movie.part01.rar".to_owned(),
        size: payload.len() as i64,
        hash16k: hex::encode(Md5::digest(&payload)),
    });
    let archive = downloads
        .download(
            DownloadKind::Compressed,
            &ids(&["rar@x"]),
            "alt.binaries.test",
            false,
        )
        .await;
    assert_eq!(archive.data.as_deref(), Some(&payload[..]));
    assert_eq!(
        server.bodies(),
        1,
        "the archive read reused the mapped article"
    );
    let metrics = downloads.finish_release_scope();
    assert_eq!(metrics.network_requests, 1);
    assert_eq!(metrics.cache_hits, 1);
}

#[tokio::test]
async fn an_unmatched_mapping_capture_is_dropped() {
    let payload = b"not what the index described".to_vec();
    let server = serve(vec![("rar@x", article("Other.part01.rar", &payload))]).await;
    let mut downloads = service(server.port);
    downloads.begin_release_scope();
    downloads
        .download_mapping_article("rar@x", "alt.binaries.test")
        .await;
    downloads.retain_mapped_archive(&Par2FileEntry {
        name: "Movie.part01.rar".to_owned(),
        size: 99,
        hash16k: "00".repeat(16),
    });
    downloads
        .download(
            DownloadKind::Compressed,
            &ids(&["rar@x"]),
            "alt.binaries.test",
            false,
        )
        .await;
    assert_eq!(server.bodies(), 2);
}

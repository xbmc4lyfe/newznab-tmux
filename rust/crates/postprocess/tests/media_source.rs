//! Mapping a release's files through its PAR2 index, against a scripted NNTP server.

#[allow(dead_code)]
mod support;

use md5::{Digest, Md5};
use nntmux_postprocess::additional::media_source::{
    self, MediaByteBudget, Release, ResolverConfig,
};
use nntmux_postprocess::additional::par2_mapping::MappingConfidence;
use nntmux_postprocess::additional::planner::{ArchiveCandidate, NzbFile, WorkPlan};
use support::{article, serve, service};

fn packet(set: &[u8; 16], kind: &[u8; 16], body: &[u8]) -> Vec<u8> {
    let mut tail = set.to_vec();
    tail.extend_from_slice(kind);
    tail.extend_from_slice(body);
    let mut out = b"PAR2\0PKT".to_vec();
    out.extend_from_slice(&(64 + body.len() as u64).to_le_bytes());
    out.extend_from_slice(&Md5::digest(&tail));
    out.extend_from_slice(&tail);
    out
}

/// A PAR2 index naming each file with its size and the MD5 of its first 16 KiB.
fn par2_index(files: &[(&str, &[u8])]) -> Vec<u8> {
    let set = [7u8; 16];
    let ids: Vec<[u8; 16]> = (0..files.len())
        .map(|index| [index as u8 + 1; 16])
        .collect();
    let mut main = 16384u64.to_le_bytes().to_vec();
    main.extend_from_slice(&(ids.len() as u32).to_le_bytes());
    for id in &ids {
        main.extend_from_slice(id);
    }
    let mut stream = packet(&set, b"PAR 2.0\0Main\0\0\0\0", &main);
    for ((name, data), id) in files.iter().zip(&ids) {
        let mut body = id.to_vec();
        body.extend_from_slice(&Md5::digest(data));
        body.extend_from_slice(&Md5::digest(&data[..data.len().min(16384)]));
        body.extend_from_slice(&(data.len() as u64).to_le_bytes());
        let mut padded = name.as_bytes().to_vec();
        while !padded.len().is_multiple_of(4) {
            padded.push(0);
        }
        body.extend_from_slice(&padded);
        stream.extend(packet(&set, b"PAR 2.0\0FileDesc", &body));
    }
    stream
}

fn nzb_file(segment: &str, size: i64) -> NzbFile {
    NzbFile {
        title: format!("\"{segment}\" yEnc (1/1)"),
        segments: vec![segment.to_owned()],
        size,
        filecount: 1,
        firstsegment: true,
    }
}

#[tokio::test]
async fn equal_sized_volumes_are_told_apart_by_their_first_article() {
    let first = vec![b'1'; 3000];
    let second = vec![b'2'; 3000];
    let index = par2_index(&[("Movie.part01.rar", &first), ("Movie.part02.rar", &second)]);
    // Obfuscated posts: the first NZB file is really the second volume.
    let server = serve(vec![
        ("v1@x", article("a1b2c3", &second)),
        ("v2@x", article("d4e5f6", &first)),
        ("index@x", article("q9w8e7", &index)),
    ])
    .await;
    let nzb = vec![
        nzb_file("v1@x", 3080),
        nzb_file("v2@x", 3080),
        nzb_file("index@x", index.len() as i64 + 40),
    ];
    let plan = WorkPlan {
        par2_index_candidates: vec![ArchiveCandidate {
            title: nzb[2].title.clone(),
            message_ids: vec!["index@x".to_owned()],
            source_index: 2,
            ..ArchiveCandidate::default()
        }],
        ..WorkPlan::default()
    };
    let mut downloads = service(server.port);
    downloads.begin_release_scope();
    let mut budget = MediaByteBudget::new(1 << 20);
    let mut group_unavailable = false;
    let config = ResolverConfig {
        par2_media_mapping: true,
        par2_verify_files: 2,
    };
    let mapped = media_source::resolve(
        &mut downloads,
        config,
        &plan,
        &mut Release {
            nzb: &nzb,
            group: "alt.binaries.test",
            budget: &mut budget,
            group_unavailable: &mut group_unavailable,
        },
    )
    .await
    .expect("a mapping");
    let names: Vec<(usize, &str, MappingConfidence)> = mapped
        .mapped
        .values()
        .map(|file| (file.source_index, file.file.name.as_str(), file.confidence))
        .collect();
    assert_eq!(
        names,
        vec![
            (0, "Movie.part02.rar", MappingConfidence::Verified),
            (1, "Movie.part01.rar", MappingConfidence::Verified),
        ]
    );
    assert_eq!(server.bodies(), 3);
    assert!(!group_unavailable);
    assert_eq!(
        budget.remaining(),
        (1 << 20) - 6000 - index.len() as i64,
        "every downloaded byte counts"
    );
}

#[tokio::test]
async fn verification_stops_at_its_attempt_limit() {
    let first = vec![b'1'; 3000];
    let second = vec![b'2'; 3000];
    let index = par2_index(&[("Movie.part01.rar", &first), ("Movie.part02.rar", &second)]);
    let server = serve(vec![
        ("v1@x", article("a1b2c3", &second)),
        ("v2@x", article("d4e5f6", &first)),
        ("index@x", article("q9w8e7", &index)),
    ])
    .await;
    let nzb = vec![nzb_file("v1@x", 3080), nzb_file("v2@x", 3080)];
    let plan = WorkPlan {
        par2_index_candidates: vec![ArchiveCandidate {
            message_ids: vec!["index@x".to_owned()],
            source_index: 9,
            ..ArchiveCandidate::default()
        }],
        ..WorkPlan::default()
    };
    let mut downloads = service(server.port);
    downloads.begin_release_scope();
    let mut budget = MediaByteBudget::new(1 << 20);
    let mut group_unavailable = false;
    let mapped = media_source::resolve(
        &mut downloads,
        ResolverConfig {
            par2_media_mapping: true,
            par2_verify_files: 1,
        },
        &plan,
        &mut Release {
            nzb: &nzb,
            group: "alt.binaries.test",
            budget: &mut budget,
            group_unavailable: &mut group_unavailable,
        },
    )
    .await
    .expect("a mapping");
    // One attempt identified the second volume, which leaves the first as the only fit.
    let names: Vec<(usize, &str, MappingConfidence)> = mapped
        .mapped
        .values()
        .map(|file| (file.source_index, file.file.name.as_str(), file.confidence))
        .collect();
    assert_eq!(
        names,
        vec![
            (0, "Movie.part02.rar", MappingConfidence::Verified),
            (1, "Movie.part01.rar", MappingConfidence::UniqueSize),
        ]
    );
    assert_eq!(server.bodies(), 2);
}

#[tokio::test]
async fn nothing_is_downloaded_when_mapping_is_off_or_the_index_is_not_par2() {
    let server = serve(vec![("index@x", article("x", b"not a par2 file"))]).await;
    let nzb = vec![nzb_file("v1@x", 3080)];
    let plan = WorkPlan {
        par2_index_candidates: vec![ArchiveCandidate {
            message_ids: vec!["index@x".to_owned()],
            ..ArchiveCandidate::default()
        }],
        ..WorkPlan::default()
    };
    let mut downloads = service(server.port);
    let mut budget = MediaByteBudget::new(1 << 20);
    let mut group_unavailable = false;
    for enabled in [false, true] {
        let mapped = media_source::resolve(
            &mut downloads,
            ResolverConfig {
                par2_media_mapping: enabled,
                par2_verify_files: 2,
            },
            &plan,
            &mut Release {
                nzb: &nzb,
                group: "alt.binaries.test",
                budget: &mut budget,
                group_unavailable: &mut group_unavailable,
            },
        )
        .await;
        assert!(mapped.is_none());
    }
    assert_eq!(server.bodies(), 1, "only the enabled run read the index");
}

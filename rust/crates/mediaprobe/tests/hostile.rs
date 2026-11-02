//! Regression tests for hostile input: each of these panicked or overflowed the stack before.

use nntmux_mediaprobe::{Limits, Probe, matroska, probe_prefix};

fn size8(len: u64) -> Vec<u8> {
    let mut out = vec![0x01];
    out.extend_from_slice(&len.to_be_bytes()[1..]);
    out
}

fn el(id: u32, body: &[u8]) -> Vec<u8> {
    let id_bytes = id.to_be_bytes();
    let skip = id_bytes.iter().position(|&b| b != 0).unwrap_or(3);
    let mut out = id_bytes[skip..].to_vec();
    out.extend(size8(body.len() as u64));
    out.extend_from_slice(body);
    out
}

fn uint(id: u32, value: u64) -> Vec<u8> {
    el(id, &value.to_be_bytes())
}

fn header_with(info: Vec<u8>, extra: Vec<u8>) -> Vec<u8> {
    let track = el(
        0xAE,
        &[uint(0xD7, 1), uint(0x83, 1), el(0x86, b"V_UNKNOWN")].concat(),
    );
    let tracks = el(0x1654_AE6B, &track);
    let segment = el(0x1853_8067, &[info, tracks, extra].concat());
    [el(0x1A45_DFA3, &el(0x4282, b"matroska")), segment].concat()
}

#[test]
fn nested_unknown_size_clusters_dont_overflow_the_stack() {
    let buf: Vec<u8> = [0x1F, 0x43, 0xB6, 0x75, 0xFF].repeat(400_000);
    assert!(matroska::blocks(&buf).is_empty());
}

#[test]
fn huge_cluster_timestamp_saturates() {
    let mut block = vec![0x81, 0x7F, 0xFF, 0x80];
    block.extend_from_slice(b"frame");
    let cluster = el(
        0x1F43_B675,
        &[uint(0xE7, u64::MAX), el(0xA3, &block)].concat(),
    );
    let blocks = matroska::blocks(&cluster);
    assert_eq!(blocks.len(), 1);
    assert_eq!(blocks[0].timestamp, i64::MAX);
}

#[test]
fn absurd_duration_is_dropped() {
    let info = el(0x1549_A966, &el(0x4489, &1e300f64.to_be_bytes()));
    let Probe::Read { summary, .. } = probe_prefix(&header_with(info, vec![]), Limits::default())
    else {
        panic!("header not read");
    };
    assert_eq!(summary.duration_ms, None);
    let _ = summary.attrs();
}

#[test]
fn huge_chapter_starts_saturate() {
    let atom = el(0xB6, &uint(0x91, u64::MAX));
    let edition = el(0x45B9, &[atom.clone(), atom.clone(), atom].concat());
    let chapters = el(0x1043_A770, &edition);
    let info = el(0x1549_A966, &uint(0x2A_D7B1, 1_000_000));
    let Probe::Read { summary, .. } = probe_prefix(&header_with(info, chapters), Limits::default())
    else {
        panic!("header not read");
    };
    assert!(summary.chapter_signature.is_some());
}

#[test]
fn hostile_lacing_is_rejected() {
    // EBML lacing, 256 frames, each size a huge 8-byte vint.
    let mut body = vec![0x81, 0, 0, 0x86, 255];
    for _ in 0..255 {
        body.extend_from_slice(&[0x01, 0xFF, 0xFF, 0xFF, 0xFF, 0xFF, 0xFF, 0xFE]);
    }
    let cluster = el(0x1F43_B675, &[uint(0xE7, 0), el(0xA3, &body)].concat());
    assert!(matroska::blocks(&cluster).is_empty());
    // Xiph lacing whose sizes run past the payload.
    let mut body = vec![0x81, 0, 0, 0x82, 3];
    body.extend(std::iter::repeat_n(255u8, 64));
    let cluster = el(0x1F43_B675, &[uint(0xE7, 0), el(0xA3, &body)].concat());
    assert!(matroska::blocks(&cluster).is_empty());
}

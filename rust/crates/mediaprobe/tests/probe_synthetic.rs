//! A hand-built Matroska file with a profile 7.6 FEL Dolby Vision track, a TrueHD Atmos track and
//! an E-AC-3 JOC track, for the paths ffmpeg can't make: the `dvcC` BlockAdditionMapping, an
//! in-band RPU and EL NAL, and object audio. The RPU is dovi_tool's `fel_orig.bin` test asset.

use nntmux_mediaprobe::dovi::ElType;
use nntmux_mediaprobe::{Limits, Probe, probe_prefix};

fn size(len: usize) -> Vec<u8> {
    // Always an 8-byte size, which is valid EBML and keeps the builder simple.
    let mut out = vec![0x01];
    out.extend_from_slice(&(len as u64).to_be_bytes()[1..]);
    out
}

fn el(id: u32, body: &[u8]) -> Vec<u8> {
    let id_bytes = id.to_be_bytes();
    let skip = id_bytes.iter().position(|&b| b != 0).unwrap_or(3);
    let mut out = id_bytes[skip..].to_vec();
    out.extend(size(body.len()));
    out.extend_from_slice(body);
    out
}

fn uint(id: u32, value: u64) -> Vec<u8> {
    el(id, &value.to_be_bytes())
}

fn cat(parts: &[Vec<u8>]) -> Vec<u8> {
    parts.concat()
}

fn rpu_nal() -> Vec<u8> {
    let annex_b = std::fs::read(format!(
        "{}/tests/fixtures/dovi/fel_orig.bin",
        env!("CARGO_MANIFEST_DIR")
    ))
    .unwrap();
    let mut nal = vec![0x7C, 0x01];
    nal.extend_from_slice(&annex_b[4..]);
    nal
}

fn length_prefixed(nals: &[&[u8]]) -> Vec<u8> {
    nals.iter()
        .flat_map(|nal| {
            let mut v = (nal.len() as u32).to_be_bytes().to_vec();
            v.extend_from_slice(nal);
            v
        })
        .collect()
}

fn simple_block(track: u8, keyframe: bool, payload: &[u8]) -> Vec<u8> {
    let mut body = vec![0x80 | track, 0, 0, if keyframe { 0x80 } else { 0 }];
    body.extend_from_slice(payload);
    el(0xA3, &body)
}

fn truehd_atmos_au() -> Vec<u8> {
    let mut au = vec![0u8; 4];
    let mut sync = vec![0xF8, 0x72, 0x6F, 0xBA];
    sync.resize(28, 0);
    sync[16] = 0x40;
    sync[17] = 0x80;
    au.extend(sync);
    au
}

fn eac3_joc_frame() -> Vec<u8> {
    // Same layout as the unit test's frame: 5.1, 6 blocks, addbsi with the JOC flag.
    let bits: &[(usize, u32)] = &[
        (16, 0x0B77),
        (2, 0),
        (3, 0),
        (11, 383),
        (2, 0),
        (2, 3),
        (3, 7),
        (1, 1),
        (5, 16),
        (5, 27),
        (1, 0),
        (1, 0),
        (1, 0),
        (1, 1),
        (6, 1),
        (7, 0),
        (1, 1),
        (8, 16),
    ];
    let mut out = Vec::new();
    let mut n = 0usize;
    for &(count, value) in bits {
        for i in (0..count).rev() {
            if n.is_multiple_of(8) {
                out.push(0u8);
            }
            let last = out.len() - 1;
            out[last] |= (((value >> i) & 1) as u8) << (7 - n % 8);
            n += 1;
        }
    }
    out.resize(768, 0);
    out
}

fn file() -> Vec<u8> {
    let mut hvcc = vec![0u8; 23];
    hvcc[17] = 2; // 10-bit
    hvcc[21] = 3; // 4-byte NAL lengths
    let dvcc_bits: u16 = (7 << 9) | (6 << 3) | 0b111;
    let mut dvcc = vec![1, 0];
    dvcc.extend(dvcc_bits.to_be_bytes());
    dvcc.push(0x60);
    dvcc.resize(24, 0);
    let video = el(
        0xAE,
        &cat(&[
            uint(0xD7, 1),
            uint(0x83, 1),
            el(0x86, b"V_MPEGH/ISO/HEVC"),
            el(0x63A2, &hvcc),
            el(
                0xE0,
                &cat(&[
                    uint(0xB0, 3840),
                    uint(0xBA, 2160),
                    el(0x55B0, &cat(&[uint(0x55BA, 16), uint(0x55BC, 1000)])),
                ]),
            ),
            el(
                0x41E4,
                &cat(&[uint(0x41E7, 0x6476_6343), el(0x41ED, &dvcc)]),
            ),
        ]),
    );
    let truehd = el(
        0xAE,
        &cat(&[
            uint(0xD7, 2),
            uint(0x83, 2),
            el(0x86, b"A_TRUEHD"),
            el(0x22B59C, b"eng"),
            el(0xE1, &uint(0x9F, 8)),
        ]),
    );
    let eac3 = el(
        0xAE,
        &cat(&[
            uint(0xD7, 3),
            uint(0x83, 2),
            el(0x86, b"A_EAC3"),
            el(0x22B59D, b"de-DE"),
            el(0xE1, &uint(0x9F, 6)),
        ]),
    );
    let tracks = el(0x1654_AE6B, &cat(&[video, truehd, eac3]));
    let info = el(0x1549_A966, &uint(0x2AD7B1, 1_000_000));
    let el_nal = [0x7E, 0x01, 0x00];
    let cluster = el(
        0x1F43_B675,
        &cat(&[
            uint(0xE7, 0),
            simple_block(1, true, &length_prefixed(&[&el_nal, &rpu_nal()])),
            simple_block(2, true, &truehd_atmos_au()),
            simple_block(3, true, &eac3_joc_frame()),
        ]),
    );
    let ebml = el(0x1A45_DFA3, &el(0x4282, b"matroska"));
    cat(&[ebml, el(0x1853_8067, &cat(&[info, tracks, cluster]))])
}

#[test]
fn dolby_vision_fel_and_object_audio() {
    let Probe::Read {
        summary,
        wants_more,
    } = probe_prefix(&file(), Limits::default())
    else {
        panic!("header not read");
    };
    assert!(!wants_more);
    let video = summary.video.as_ref().unwrap();
    assert_eq!(video.hdr, vec!["DV", "HDR10"]);
    assert_eq!(video.bit_depth, Some(10));
    let dovi = video.dovi.as_ref().unwrap();
    assert_eq!(dovi.profile.as_deref(), Some("7.6"));
    assert_eq!(dovi.el, Some(ElType::Fel));
    assert!(dovi.el_present);
    assert_eq!(summary.audio[0].label(), "TrueHD Atmos 7.1");
    assert_eq!(summary.audio[1].label(), "E-AC-3 Atmos 5.1");
    assert_eq!(summary.audio[1].language, "ger");

    let tags = summary.tags();
    for expected in [
        "dv", "dvp7", "dvp76", "fel", "hdr10", "atmos", "truehd", "ger",
    ] {
        assert!(
            tags.contains(&expected.to_string()),
            "missing {expected} in {tags:?}"
        );
    }
    let attrs = summary.attrs();
    assert!(attrs.contains(&("dvprofile", "7.6".into())));
    assert!(attrs.contains(&("dvel", "FEL".into())));
    assert!(attrs.contains(&("atmos", "1".into())));
    assert!(attrs.contains(&("language", "English, German".into())));
}

#[test]
fn header_without_frames_wants_more() {
    let whole = file();
    // Cut just after the cluster's Timestamp, before any block.
    let cluster = whole
        .windows(4)
        .position(|w| w == [0x1F, 0x43, 0xB6, 0x75])
        .unwrap();
    let cut = cluster + 4 + 8 + 1 + 8 + 8;
    let Probe::Read {
        summary,
        wants_more,
    } = probe_prefix(&whole[..cut], Limits::default())
    else {
        panic!("header not read");
    };
    assert!(wants_more);
    // The config record alone gives the profile, but not MEL or FEL.
    let dovi = summary.video.as_ref().unwrap().dovi.as_ref().unwrap();
    assert_eq!(dovi.profile.as_deref(), Some("7.6"));
    assert_eq!(dovi.el, None);
}

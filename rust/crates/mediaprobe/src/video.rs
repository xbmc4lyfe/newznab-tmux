//! HEVC and AVC access units: Dolby Vision RPU and enhancement-layer NALs, HDR10+ and the
//! encoder's settings string, all found without decoding a picture.

use crate::dovi::{self, RpuSummary};
use serde::Serialize;

#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub enum Codec {
    Hevc,
    Avc,
}

impl Codec {
    pub fn from_codec_id(codec_id: &str) -> Option<Self> {
        match codec_id {
            "V_MPEGH/ISO/HEVC" => Some(Self::Hevc),
            "V_MPEG4/ISO/AVC" => Some(Self::Avc),
            _ => None,
        }
    }
}

/// NAL length size from `hvcC` / `avcC` codec private data (4 when it can't be read).
pub fn nal_length_size(codec: Codec, private: &[u8]) -> usize {
    let byte = match codec {
        Codec::Hevc => private.get(21),
        Codec::Avc => private.get(4),
    };
    byte.map_or(4, |b| usize::from(b & 3) + 1)
}

/// Splits a length-prefixed access unit into NAL units. Stops at the first inconsistent length.
pub fn split_nals(frame: &[u8], length_size: usize) -> Vec<&[u8]> {
    let mut out = Vec::new();
    let mut pos = 0;
    while pos + length_size <= frame.len() {
        let len = frame[pos..pos + length_size]
            .iter()
            .fold(0usize, |acc, &b| (acc << 8) | usize::from(b));
        pos += length_size;
        let Some(nal) = frame.get(pos..pos + len) else {
            break;
        };
        if !nal.is_empty() {
            out.push(nal);
        }
        pos += len;
    }
    out
}

/// What the first access units of a video track showed.
#[derive(Clone, Debug, Default, PartialEq, Eq, Serialize)]
pub struct VideoFindings {
    pub rpu: Option<RpuSummary>,
    /// An in-band enhancement layer (HEVC NAL type 63) was seen.
    pub el_nal_seen: bool,
    /// An ST 2094-40 (HDR10+) SEI was seen.
    pub hdr10plus: bool,
    /// x264 / x265 settings string from a user-data-unregistered SEI.
    pub encoder: Option<String>,
    pub access_units: usize,
}

impl VideoFindings {
    /// Folds in one access unit.
    pub fn add_access_unit(&mut self, codec: Codec, length_size: usize, frame: &[u8]) {
        self.access_units += 1;
        for nal in split_nals(frame, length_size) {
            match codec {
                Codec::Hevc => {
                    let kind = (nal[0] >> 1) & 0x3F;
                    match kind {
                        62 if self.rpu.is_none() => self.rpu = dovi::summarize_rpu(nal),
                        63 => self.el_nal_seen = true,
                        39 | 40 if nal.len() > 2 => self.read_sei(&nal[2..]),
                        _ => {}
                    }
                }
                Codec::Avc => {
                    if nal[0] & 0x1F == 6 && nal.len() > 1 {
                        self.read_sei(&nal[1..]);
                    }
                }
            }
        }
    }

    /// A BlockAddition holding an RPU (some muxers put it there instead of in-band).
    pub fn add_block_addition(&mut self, data: &[u8]) {
        if self.rpu.is_none() {
            self.rpu = dovi::summarize_rpu(data);
        }
    }

    fn read_sei(&mut self, rbsp_with_epb: &[u8]) {
        let rbsp = strip_emulation_prevention(rbsp_with_epb);
        let mut pos = 0;
        // Each SEI message: payload type and size as runs of 0xFF plus a final byte.
        while pos < rbsp.len() && rbsp[pos] != 0x80 {
            let Some((kind, next)) = read_ff_coded(&rbsp, pos) else {
                return;
            };
            let Some((size, next)) = read_ff_coded(&rbsp, next) else {
                return;
            };
            let Some(payload) = rbsp.get(next..next + size) else {
                return;
            };
            match kind {
                4 => {
                    // user_data_registered_itu_t_t35: US (0xB5), Samsung (0x003C), oriented code 1,
                    // application identifier 4 is ST 2094-40.
                    if payload.len() >= 6
                        && payload[0] == 0xB5
                        && payload[1..3] == [0x00, 0x3C]
                        && payload[3..5] == [0x00, 0x01]
                        && payload[5] == 4
                    {
                        self.hdr10plus = true;
                    }
                }
                5 if self.encoder.is_none() && payload.len() > 16 => {
                    let text = String::from_utf8_lossy(&payload[16..]);
                    let text = text.trim_end_matches('\0').trim();
                    if text.starts_with("x265") || text.starts_with("x264") {
                        self.encoder = Some(text.chars().take(2048).collect());
                    }
                }
                _ => {}
            }
            pos = next + size;
        }
    }
}

fn read_ff_coded(data: &[u8], mut pos: usize) -> Option<(usize, usize)> {
    let mut value = 0usize;
    loop {
        let byte = *data.get(pos)?;
        pos += 1;
        value += usize::from(byte);
        if byte != 0xFF {
            return Some((value, pos));
        }
    }
}

/// Removes `00 00 03` emulation prevention bytes.
pub fn strip_emulation_prevention(data: &[u8]) -> Vec<u8> {
    let mut out = Vec::with_capacity(data.len());
    let mut zeros = 0;
    for &byte in data {
        if zeros >= 2 && byte == 3 {
            zeros = 0;
            continue;
        }
        zeros = if byte == 0 { zeros + 1 } else { 0 };
        out.push(byte);
    }
    out
}

#[cfg(test)]
mod tests {
    use super::*;

    fn length_prefixed(nals: &[&[u8]]) -> Vec<u8> {
        let mut out = Vec::new();
        for nal in nals {
            out.extend_from_slice(&(nal.len() as u32).to_be_bytes());
            out.extend_from_slice(nal);
        }
        out
    }

    #[test]
    fn finds_hdr10plus_el_and_rpu() {
        // The fixture is an Annex B NAL; in Matroska it is length-prefixed with its 7C 01 header.
        let annex_b = std::fs::read(format!(
            "{}/tests/fixtures/dovi/fel_orig.bin",
            env!("CARGO_MANIFEST_DIR")
        ))
        .unwrap();
        let mut rpu = vec![0x7C, 0x01];
        rpu.extend_from_slice(&annex_b[4..]);
        // Prefix SEI (type 39), one message: type 4, size 7, the HDR10+ T.35 header plus a byte.
        let sei = [0x4E, 0x01, 4, 7, 0xB5, 0x00, 0x3C, 0x00, 0x01, 4, 1, 0x80];
        let el = [0x7E, 0x01, 0xAA];
        let frame = length_prefixed(&[&sei, &el, &rpu]);
        let mut findings = VideoFindings::default();
        findings.add_access_unit(Codec::Hevc, 4, &frame);
        assert!(findings.hdr10plus);
        assert!(findings.el_nal_seen);
        assert_eq!(
            findings.rpu.as_ref().and_then(|r| r.el_type),
            Some(crate::dovi::ElType::Fel)
        );
    }

    #[test]
    fn reads_x265_settings() {
        let mut payload = vec![0u8; 16];
        payload.extend_from_slice(b"x265 (build 199) - 3.5: options: crf=18");
        let mut sei = vec![0x4E, 0x01, 5, payload.len() as u8];
        sei.extend_from_slice(&payload);
        sei.push(0x80);
        let mut findings = VideoFindings::default();
        findings.add_access_unit(Codec::Hevc, 4, &length_prefixed(&[&sei]));
        assert_eq!(
            findings.encoder.as_deref(),
            Some("x265 (build 199) - 3.5: options: crf=18")
        );
    }

    #[test]
    fn strips_emulation_prevention() {
        assert_eq!(
            strip_emulation_prevention(&[0, 0, 3, 1, 0, 0, 3, 0]),
            vec![0, 0, 1, 0, 0, 0]
        );
    }
}

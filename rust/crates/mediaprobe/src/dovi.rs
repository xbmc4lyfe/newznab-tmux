//! Dolby Vision: the decoder configuration record from the track header, and what an RPU says.

use dolby_vision::rpu::dovi_rpu::DoviRpu;
use dolby_vision::rpu::extension_metadata::blocks::ExtMetadataBlock;
use dolby_vision::rpu::rpu_data_nlq::DoviELType;
use serde::Serialize;

/// The `DOVIDecoderConfigurationRecord` (ETSI / Dolby "Dolby Vision streams within ISOBMFF").
#[derive(Clone, Copy, Debug, PartialEq, Eq, Serialize)]
pub struct DoviConfig {
    pub version_major: u8,
    pub version_minor: u8,
    pub profile: u8,
    pub level: u8,
    pub rpu_present: bool,
    pub el_present: bool,
    pub bl_present: bool,
    /// The cross-compatibility ID: 1 is HDR10, 2 SDR, 4 HLG, 6 Blu-ray HDR10 (profile 7).
    pub bl_compatibility_id: u8,
}

impl DoviConfig {
    pub fn parse(data: &[u8]) -> Option<Self> {
        if data.len() < 5 {
            return None;
        }
        let bits = u16::from_be_bytes([data[2], data[3]]);
        Some(Self {
            version_major: data[0],
            version_minor: data[1],
            profile: (bits >> 9) as u8,
            level: ((bits >> 3) & 0x3F) as u8,
            rpu_present: bits & 0b100 != 0,
            el_present: bits & 0b10 != 0,
            bl_present: bits & 1 != 0,
            bl_compatibility_id: data[4] >> 4,
        })
    }

    /// The profile as players name it: `8.1`, `7.6`, `5`.
    pub fn profile_name(&self) -> String {
        match (self.profile, self.bl_compatibility_id) {
            (5, _) | (_, 0) => self.profile.to_string(),
            (profile, compat) => format!("{profile}.{compat}"),
        }
    }
}

/// The enhancement layer of a profile 7 stream.
#[derive(Clone, Copy, Debug, PartialEq, Eq, Serialize)]
#[serde(rename_all = "UPPERCASE")]
pub enum ElType {
    Mel,
    Fel,
}

impl ElType {
    pub fn as_str(self) -> &'static str {
        match self {
            Self::Mel => "MEL",
            Self::Fel => "FEL",
        }
    }
}

/// What the first RPU of a stream says.
#[derive(Clone, Debug, PartialEq, Eq, Serialize)]
pub struct RpuSummary {
    pub profile: u8,
    pub el_type: Option<ElType>,
    /// `2.9` or `4.0`.
    pub cm_version: &'static str,
    pub max_cll: Option<u16>,
    pub max_fall: Option<u16>,
}

/// Parses one HEVC UNSPEC62 NAL unit (with or without its `7C 01` header).
pub fn summarize_rpu(nal: &[u8]) -> Option<RpuSummary> {
    // The parser is a third-party crate fed untrusted bytes; a panic in it costs one probe, not
    // the worker.
    let rpu = std::panic::catch_unwind(|| DoviRpu::parse_unspec62_nalu(nal))
        .ok()?
        .ok()?;
    let el_type = match rpu
        .el_type
        .clone()
        .or_else(|| rpu.get_enhancement_layer_type())
    {
        Some(DoviELType::MEL) => Some(ElType::Mel),
        Some(DoviELType::FEL) => Some(ElType::Fel),
        None => None,
    };
    let (cm_version, level6) = match &rpu.vdr_dm_data {
        Some(dm) => {
            let level6 = dm.get_block(6).and_then(|block| match block {
                ExtMetadataBlock::Level6(l6) => {
                    Some((l6.max_content_light_level, l6.max_frame_average_light_level))
                }
                _ => None,
            });
            (
                if dm.cmv40_metadata.is_some() {
                    "4.0"
                } else {
                    "2.9"
                },
                level6,
            )
        }
        None => ("2.9", None),
    };
    Some(RpuSummary {
        profile: rpu.dovi_profile,
        el_type: if rpu.dovi_profile == 7 { el_type } else { None },
        cm_version,
        max_cll: level6.map(|l| l.0).filter(|&v| v > 0),
        max_fall: level6.map(|l| l.1).filter(|&v| v > 0),
    })
}

#[cfg(test)]
mod tests {
    use super::*;

    fn fixture(name: &str) -> Vec<u8> {
        std::fs::read(format!(
            "{}/tests/fixtures/dovi/{name}",
            env!("CARGO_MANIFEST_DIR")
        ))
        .unwrap()
    }

    #[test]
    fn config_record_profile_81() {
        // dvcC for profile 8, level 6, RPU + BL, compatibility 1.
        let bits: u16 = (8 << 9) | (6 << 3) | 0b101;
        let [hi, lo] = bits.to_be_bytes();
        let config = DoviConfig::parse(&[1, 0, hi, lo, 0x10, 0, 0, 0]).unwrap();
        assert_eq!(config.profile, 8);
        assert_eq!(config.level, 6);
        assert!(config.rpu_present && config.bl_present && !config.el_present);
        assert_eq!(config.profile_name(), "8.1");
    }

    #[test]
    fn config_record_profile_76_with_el() {
        let bits: u16 = (7 << 9) | (6 << 3) | 0b111;
        let [hi, lo] = bits.to_be_bytes();
        let config = DoviConfig::parse(&[1, 0, hi, lo, 0x60]).unwrap();
        assert!(config.el_present);
        assert_eq!(config.profile_name(), "7.6");
    }

    #[test]
    fn fel_and_mel_rpus() {
        let fel = summarize_rpu(&fixture("fel_orig.bin")).unwrap();
        assert_eq!(fel.profile, 7);
        assert_eq!(fel.el_type, Some(ElType::Fel));
        let mel = summarize_rpu(&fixture("mel_orig.bin")).unwrap();
        assert_eq!(mel.profile, 7);
        assert_eq!(mel.el_type, Some(ElType::Mel));
    }

    #[test]
    fn profile_8_and_5_have_no_el() {
        let p8 = summarize_rpu(&fixture("profile8.bin")).unwrap();
        assert_eq!(p8.profile, 8);
        assert_eq!(p8.el_type, None);
        let p5 = summarize_rpu(&fixture("profile5.bin")).unwrap();
        assert_eq!(p5.profile, 5);
    }

    #[test]
    fn cm_v40_is_recognised() {
        let rpu = summarize_rpu(&fixture("cmv40_full_rpu.bin")).unwrap();
        assert_eq!(rpu.cm_version, "4.0");
    }
}

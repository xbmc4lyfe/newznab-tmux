//! SHA-256 of the parsers' exact JSON, recorded from the php-final FFI library by
//! `fixtures/generate_archive_golden.php`.

use nntmux_formats as formats;
use serde::Serialize;
use serde_json::Value;
use sha2::{Digest, Sha256};
use std::path::Path;

fn golden() -> Value {
    serde_json::from_str(include_str!("fixtures/archive-golden.json")).unwrap()
}

fn digest(value: Option<impl Serialize>) -> Value {
    value.map_or(Value::Null, |value| {
        let json = serde_json::to_string(&value).unwrap();
        Value::String(hex::encode(Sha256::digest(json)))
    })
}

#[test]
fn real_samples_and_their_prefixes_match_the_library() {
    let directory = Path::new(env!("CARGO_MANIFEST_DIR")).join("tests/fixtures/archives");
    let golden = golden();
    let samples = golden["samples"].as_array().unwrap();
    assert!(samples.len() > 500);
    for sample in samples {
        let file = sample["file"].as_str().unwrap();
        let bytes = std::fs::read(directory.join(file)).unwrap();
        let length = usize::try_from(sample["length"].as_u64().unwrap()).unwrap();
        let prefix = &bytes[..length];
        assert_eq!(
            digest(formats::archive_metadata(prefix)),
            sample["format"],
            "format metadata of {file} at {length}"
        );
        assert_eq!(
            digest(formats::par2_metadata(prefix)),
            sample["par2"],
            "PAR2 metadata of {file} at {length}"
        );
    }
}

/// Splits the packed form that the PHP collector built back into typed arguments.
fn fragments(
    input: &[u8],
    mut cursor: usize,
    count: usize,
) -> (Vec<formats::RangeFragment<'_>>, usize) {
    let mut fragments = Vec::new();
    for _ in 0..count {
        let file_offset = u64::from_le_bytes(input[cursor..cursor + 8].try_into().unwrap());
        let length =
            u32::from_le_bytes(input[cursor + 8..cursor + 12].try_into().unwrap()) as usize;
        cursor += 12;
        fragments.push(formats::RangeFragment {
            file_offset,
            bytes: &input[cursor..cursor + length],
        });
        cursor += length;
    }
    (fragments, cursor)
}

#[test]
fn generated_fixtures_match_the_library() {
    let golden = golden();
    for case in golden["generated"].as_array().unwrap() {
        let name = &case["name"];
        let Some(input) = case["bytes"].as_str() else {
            continue;
        };
        let input = hex::decode(input).unwrap();
        let actual = match case["operation"].as_str().unwrap() {
            "par2" => digest(formats::par2_metadata(&input)),
            "formats" => digest(formats::archive_metadata(&input)),
            "seven-ranges" => {
                let count = u32::from_le_bytes(input[..4].try_into().unwrap()) as usize;
                let size = u64::from_le_bytes(input[4..12].try_into().unwrap());
                let (fragments, _) = fragments(&input, 12, count);
                digest(formats::seven_zip_ranges(size, &fragments))
            }
            "par2-ranges" => {
                let count = u32::from_le_bytes(input[4..8].try_into().unwrap()) as usize;
                let slices = u32::from_le_bytes(input[8..12].try_into().unwrap()) as usize;
                let size = u64::from_le_bytes(input[12..20].try_into().unwrap());
                let (fragments, mut cursor) = fragments(&input, 20, count);
                let mut checksums = Vec::new();
                for _ in 0..slices {
                    let word = |at: usize| {
                        u64::from_le_bytes(input[cursor + at..cursor + at + 8].try_into().unwrap())
                    };
                    checksums.push(formats::Par2SliceChecksum {
                        slice_index: u32::from_le_bytes(
                            input[cursor..cursor + 4].try_into().unwrap(),
                        ),
                        source_byte_start: word(4),
                        source_byte_end_exclusive: word(12),
                        zero_padding_bytes: word(20),
                        md5: input[cursor + 28..cursor + 44].try_into().unwrap(),
                        crc32: u32::from_le_bytes(
                            input[cursor + 44..cursor + 48].try_into().unwrap(),
                        ),
                    });
                    cursor += 48;
                }
                digest(formats::par2_source_ranges(size, &fragments, &checksums))
            }
            other => panic!("unknown operation {other}"),
        };
        assert_eq!(actual, case["json_sha256"], "{name}");
    }
}

#[test]
fn seven_zip_ranges_refuse_an_empty_request() {
    assert!(formats::seven_zip_ranges(10, &[]).is_none());
}

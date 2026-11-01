//! The archive readers against rarinfo's own output over its fixture archives, whole and cut
//! short. See `fixtures/generate_archive_info_golden.php`.

use std::path::Path;
use std::sync::Arc;

use nntmux_postprocess::archive_info::{self, ArchiveInfo, FileRecord, Kind, ListEntry};
use serde_json::{Value, json};

fn encode(bytes: &[u8]) -> String {
    const TABLE: &[u8; 64] = b"ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/";
    let mut out = String::new();
    for chunk in bytes.chunks(3) {
        let block = (u32::from(chunk[0]) << 16)
            | (u32::from(*chunk.get(1).unwrap_or(&0)) << 8)
            | u32::from(*chunk.get(2).unwrap_or(&0));
        for index in 0..4 {
            if index <= chunk.len() {
                out.push(char::from(
                    TABLE[((block >> (18 - index * 6)) & 63) as usize],
                ));
            } else {
                out.push('=');
            }
        }
    }
    out
}

fn record(file: &FileRecord) -> Value {
    json!({
        "name": encode(&file.name),
        "size": file.size,
        "date": file.date,
        "pass": file.pass,
        "compressed": file.compressed,
        "next_offset": file.next_offset,
        "is_dir": file.is_dir,
        "range": file.range.map(|(start, end)| format!("{start}-{end}")),
        "crc32": file.crc32,
        "split": file.split,
        "split_after": file.split_after,
        "source": file.source,
        "hash": file.hash,
        "hash_16k": file.hash_16k,
        "blocks": file.blocks,
        "checksum": file.checksum,
    })
}

fn entry(entry: &ListEntry) -> Value {
    match entry {
        ListEntry::File(file) => record(file),
        ListEntry::Error { error, source } => json!({"error": error, "source": source}),
        ListEntry::Nested(entries) => {
            json!({"nested": entries.iter().map(self::entry).collect::<Vec<_>>()})
        }
    }
}

fn kind_name(kind: Kind) -> &'static str {
    match kind {
        Kind::None => "None",
        Kind::Rar => "Rar",
        Kind::Srr => "Srr",
        Kind::Par2 => "Par2",
        Kind::Zip => "Zip",
        Kind::Sfv => "Sfv",
        Kind::Szip => "Szip",
    }
}

fn md5_hex(bytes: &[u8]) -> String {
    use md5::{Digest, Md5};
    hex::encode(Md5::digest(bytes))
}

fn describe(data: &[u8]) -> Value {
    let Ok(mut archive) = ArchiveInfo::from_data(Arc::new(data.to_vec()), true, None) else {
        return json!({"open_throws": true});
    };
    let mut result = json!({
        "kind": kind_name(archive.kind),
        "error": archive.error(),
        "encrypted": archive.is_encrypted(),
    });
    if archive.kind == Kind::None {
        return result;
    }
    let listing_only = archive.kind == Kind::Szip;
    let Ok(summary) = archive.summary(!listing_only) else {
        result["summary_throws"] = json!(true);
        return result;
    };
    result["summary_throws"] = json!(false);
    result["is_volume"] = json!(summary.is_volume);
    result["enc_header"] = json!(summary.enc_header);
    match archive.archive_file_list(!listing_only) {
        Ok(list) => {
            let list = list.unwrap_or_default();
            result["list"] = list.iter().map(entry).collect();
            let mut read = Vec::new();
            for item in &list {
                if let ListEntry::File(file) = item
                    && file.range.is_some()
                    && file.source == "main"
                    && !file.is_dir
                    && !listing_only
                {
                    let bytes = archive.file_data(&file.name).ok().flatten();
                    read.push(
                        json!({"name": encode(&file.name), "md5": bytes.map(|b| md5_hex(&b))}),
                    );
                }
            }
            if !read.is_empty() {
                result["data"] = json!(read);
            }
        }
        Err(_) => result["list_throws"] = json!(true),
    }
    if archive.kind == Kind::Rar {
        result["terminal"] = json!(archive.has_terminal_rar_block(data.len()));
    }
    result
}

fn fixture(name: &str) -> Vec<u8> {
    std::fs::read(
        Path::new(env!("CARGO_MANIFEST_DIR"))
            .join("tests/fixtures/archives")
            .join(name),
    )
    .expect("the fixture archive")
}

fn golden() -> Value {
    let text = std::fs::read_to_string(
        Path::new(env!("CARGO_MANIFEST_DIR")).join("tests/fixtures/archive_info_golden.json"),
    )
    .expect("the golden file");
    serde_json::from_str(&text).expect("valid JSON")
}

#[test]
fn archive_listings_match_rarinfo() {
    let golden = golden();
    let mut failures = Vec::new();
    let cases = golden["cases"].as_array().expect("cases");
    for case in cases {
        let name = case["file"].as_str().expect("file");
        let data = fixture(name);
        let skip = usize::try_from(case["skip"].as_u64().expect("skip")).expect("skip");
        let cut = usize::try_from(case["cut"].as_u64().expect("cut")).expect("cut");
        let actual = describe(&data[skip..cut]);
        let mut expected = case.clone();
        let object = expected.as_object_mut().expect("object");
        object.remove("file");
        object.remove("skip");
        object.remove("cut");
        if actual != expected {
            failures.push(format!(
                "{name} {skip}..{cut}\n  want {expected}\n  got  {actual}"
            ));
        }
    }
    let shown: Vec<&String> = failures.iter().take(8).collect();
    assert!(
        failures.is_empty(),
        "{} of {} differ:\n{}",
        failures.len(),
        cases.len(),
        shown
            .iter()
            .map(|s| s.as_str())
            .collect::<Vec<_>>()
            .join("\n")
    );
}

#[test]
fn par2_file_lists_match_php() {
    for case in golden()["par2"].as_array().expect("par2") {
        let name = case["file"].as_str().expect("file");
        let reader = archive_info::par2_from_data(Arc::new(fixture(name))).expect("a reader");
        assert_eq!(
            reader.error,
            case["error"].as_str().expect("error"),
            "{name}"
        );
        assert_eq!(
            reader.has_unresolved_descriptors(),
            case["unresolved"].as_bool().expect("unresolved"),
            "{name}"
        );
        let files: Vec<Value> = reader.files().iter().map(record).collect();
        assert_eq!(json!(files), case["files"], "{name}");
    }
}

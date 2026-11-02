//! Archive inspection and the pure helpers against PHP's `ArchiveExtractionService`, from
//! `fixtures/generate_archive_extraction_golden.php`. Byte strings in the fixture are hex.

use std::collections::BTreeMap;
use std::path::Path;
use std::sync::Arc;

use nntmux_postprocess::additional::archive_extraction::{
    ArchiveFlags, Extractor, Inspection, complete_stored_entries, detect_standalone_video,
    has_archive_signature, is_nfo_file, needs_seven_zip_end_header, with_seven_zip_end_header,
};
use nntmux_postprocess::additional::seven_zip_listing;
use nntmux_postprocess::archive_info::{FileRecord, Kind, ListEntry};
use serde_json::{Value, json};

fn golden() -> Value {
    serde_json::from_str(include_str!("fixtures/archive_extraction_golden.json"))
        .expect("valid JSON")
}

fn bytes(value: &Value) -> Vec<u8> {
    hex::decode(value.as_str().expect("a hex string")).expect("valid hex")
}

fn md5_hex(bytes: &[u8]) -> String {
    use md5::{Digest, Md5};
    hex::encode(Md5::digest(bytes))
}

#[test]
fn nfo_names_match_php() {
    for case in golden()["nfo"].as_array().expect("cases") {
        let name = bytes(&case[0]);
        assert_eq!(
            is_nfo_file(&name),
            case[1].as_bool().expect("a bool"),
            "{:?}",
            String::from_utf8_lossy(&name)
        );
    }
}

#[test]
fn signatures_and_inline_video_match_php() {
    for case in golden()["signatures"].as_array().expect("cases") {
        let data = bytes(&case["data"]);
        let name = &case["name"];
        assert_eq!(
            has_archive_signature(&data),
            case["archive"].as_bool().expect("a bool"),
            "{name}: signature"
        );
        assert_eq!(
            detect_standalone_video(&data).map(|kind| kind.extension()),
            case["video"].as_str(),
            "{name}: video"
        );
    }
}

#[test]
fn seven_zip_end_headers_match_php() {
    for case in golden()["seven_zip"].as_array().expect("cases") {
        let head = bytes(&case["head"]);
        let tail = bytes(&case["tail"]);
        let name = &case["name"];
        assert_eq!(
            needs_seven_zip_end_header(&head),
            case["needs_end_header"].as_bool().expect("a bool"),
            "{name}: needs end header"
        );
        let expected = case["joined"]
            .as_str()
            .map(|joined| hex::decode(joined).expect("hex"));
        assert_eq!(
            with_seven_zip_end_header(&head, &tail),
            expected,
            "{name}: joined"
        );
    }
}

fn php_record(file: &FileRecord) -> Value {
    let mut record = json!({
        "name": hex::encode(&file.name),
        "size": file.size,
        "date": file.date,
        "pass": file.pass,
        "compressed": file.compressed,
    });
    if let Some(crc) = &file.crc32 {
        record["crc32"] = json!(crc);
    }
    record
}

#[test]
fn seven_zip_listings_match_php() {
    for case in golden()["listings"].as_array().expect("cases") {
        let (files, reliable) = seven_zip_listing::parse(&bytes(&case["output"]));
        let name = &case["name"];
        assert_eq!(reliable, case["reliable"], "{name}: reliable");
        let files: Vec<Value> = files.iter().map(php_record).collect();
        assert_eq!(Value::from(files), case["files"], "{name}: files");
    }
}

#[test]
fn complete_stored_entries_match_php() {
    let golden = golden();
    let entries: Vec<ListEntry> = golden["stored_entries"]["input"]
        .as_array()
        .expect("entries")
        .iter()
        .map(|input| {
            let range = input["range"].as_str().map(|range| {
                let (start, end) = range.split_once('-').expect("start-end");
                (start.parse().expect("start"), end.parse().expect("end"))
            });
            ListEntry::File(FileRecord {
                name: input["name"].as_str().expect("name").as_bytes().to_vec(),
                size: input["size"].as_i64().expect("size"),
                range,
                compressed: input["compressed"].as_i64(),
                pass: input["pass"].as_i64().unwrap_or(0),
                is_dir: input["is_dir"].as_bool().unwrap_or(false),
                source: input["source"].as_str().unwrap_or_default().to_owned(),
                ..FileRecord::default()
            })
        })
        .collect();
    let found: BTreeMap<String, i64> = complete_stored_entries(&entries)
        .into_iter()
        .map(|(name, size)| (String::from_utf8(name).expect("UTF-8"), size))
        .collect();
    let expected: BTreeMap<String, i64> =
        serde_json::from_value(golden["stored_entries"]["output"].clone()).expect("a map");
    assert_eq!(found, expected);
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

fn inputs() -> BTreeMap<String, Vec<u8>> {
    let directory = Path::new(env!("CARGO_MANIFEST_DIR")).join("tests/fixtures/archives");
    let mut inputs = BTreeMap::new();
    for entry in std::fs::read_dir(directory).expect("the fixture archives") {
        let path = entry.expect("an entry").path();
        let name = path
            .file_name()
            .expect("a name")
            .to_string_lossy()
            .into_owned();
        // PHP's glob skips dot files.
        if name.starts_with('.') {
            continue;
        }
        let data = std::fs::read(&path).expect("the archive");
        inputs.insert(
            format!("{name} (first 3000 bytes)"),
            data[..data.len().min(3000)].to_vec(),
        );
        inputs.insert(name, data);
    }
    let mut mkv = b"\x1a\x45\xdf\xa3".to_vec();
    mkv.resize(400, 0);
    inputs.insert("inline mkv".to_owned(), mkv);
    // PHP never inspects empty data.
    inputs.retain(|_, data| !data.is_empty());
    inputs
}

/// What PHP recorded for one input, from what this port returns.
fn describe(extractor: &Extractor, data: Vec<u8>, tmp: &Path) -> Value {
    let data = Arc::new(data);
    let mut flags = ArchiveFlags::default();
    let Ok(inspection) = extractor.process_compressed_data(&data, &mut flags, tmp) else {
        return json!({"throws": true});
    };
    let mut result = json!({
        "throws": false,
        "success": matches!(inspection, Inspection::Listed(_)),
        "has_password": matches!(inspection, Inspection::Passworded { .. }),
        "password_status": inspection.password_status(),
        "listing_only": false,
        "undecodable": false,
        "video": null,
        "marker": null,
        "kind": null,
        "is_volume": false,
        "rar_listing_complete": null,
        "entries": 0,
        "names": [],
        "checked": flags.compressed_files_checked,
        "retryable": flags.archive_retryable,
        "flag_undecodable": flags.archive_listing_undecodable,
    });
    let mut names = Vec::new();
    match inspection {
        Inspection::StandaloneVideo(kind) => result["video"] = json!(kind.extension()),
        Inspection::Failed {
            undecodable,
            listing_only,
        } => {
            result["undecodable"] = json!(undecodable);
            result["listing_only"] = json!(listing_only);
        }
        Inspection::Passworded { listing_only } => result["listing_only"] = json!(listing_only),
        Inspection::Listed(listing) => {
            result["listing_only"] = json!(listing.listing_only);
            result["marker"] = json!(listing.marker.map(|marker| marker.as_char().to_string()));
            result["kind"] = json!(kind_name(listing.kind));
            result["is_volume"] = json!(listing.summary.is_volume);
            result["rar_listing_complete"] = json!(listing.rar_listing_complete);
            result["entries"] = json!(listing.files.len());
            for entry in &listing.files {
                if let ListEntry::File(file) = entry {
                    names.push(file.name.clone());
                }
            }
        }
    }
    result["names"] = names.iter().map(hex::encode).collect();
    let wanted: Vec<&[u8]> = names.iter().map(Vec::as_slice).collect();
    result["extracted"] = match extractor.extract_specific_files(&data, &wanted, tmp) {
        Ok(found) => found
            .into_iter()
            .map(|(name, bytes)| (hex::encode(name), json!(md5_hex(&bytes))))
            .collect::<BTreeMap<_, _>>()
            .into_iter()
            .collect(),
        Err(_) => Value::Null,
    };
    result
}

#[test]
fn inspections_of_every_fixture_archive_match_php() {
    let tmp = tempfile::tempdir().expect("a temporary directory");
    let extractor = Extractor {
        extract_using_rar_info: true,
        ..Extractor::default()
    };
    let inputs = inputs();
    let golden = golden();
    let cases = golden["inspections"].as_array().expect("cases");
    assert_eq!(
        cases.len(),
        inputs.len(),
        "every non-empty input is covered"
    );
    for case in cases {
        let name = case["name"].as_str().expect("a name");
        let mut expected = case.clone();
        expected.as_object_mut().expect("an object").remove("name");
        let data = inputs.get(name).expect("the input").clone();
        assert_eq!(describe(&extractor, data, tmp.path()), expected, "{name}");
    }
}

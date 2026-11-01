//! The paths through the external clients, with scripted stand-ins for 7-Zip, unrar and unzip.

use std::fmt::Write as _;
use std::fs;
use std::os::unix::fs::PermissionsExt;
use std::path::{Path, PathBuf};
use std::sync::Arc;

use nntmux_postprocess::additional::archive_extraction::{
    ArchiveFlags, Extractor, Inspection, Marker, SEVENZIP_LISTING_MAX_ENTRIES,
};
use nntmux_postprocess::archive_info::{Kind, ListEntry};
use tempfile::TempDir;

fn fixture(name: &str) -> Vec<u8> {
    fs::read(
        Path::new(env!("CARGO_MANIFEST_DIR"))
            .join("tests/fixtures/archives")
            .join(name),
    )
    .expect("the fixture archive")
}

/// An executable shell script in `directory`.
fn script(directory: &Path, name: &str, body: &str) -> PathBuf {
    let path = directory.join(name);
    fs::write(&path, format!("#!/bin/sh\n{body}\n")).expect("the script");
    fs::set_permissions(&path, fs::Permissions::from_mode(0o755)).expect("executable");
    path
}

fn names(inspection: &Inspection) -> Vec<String> {
    let Inspection::Listed(listing) = inspection else {
        return Vec::new();
    };
    listing
        .files
        .iter()
        .filter_map(|entry| match entry {
            ListEntry::File(file) => Some(String::from_utf8_lossy(&file.name).into_owned()),
            _ => None,
        })
        .collect()
}

/// A 7z whose end header is compressed, which only the client can list.
const ENCODED: &str = "szip__store_method_enc_headers.7z";

struct SevenZip {
    tmp: TempDir,
    extractor: Extractor,
}

/// A 7-Zip stand-in that saves the archive it was given as `seen.7z` and runs `body`.
fn seven_zip(body: &str) -> SevenZip {
    let tmp = tempfile::tempdir().expect("a temporary directory");
    let seen = tmp.path().join("seen.7z");
    let client = script(
        tmp.path(),
        "7z",
        &format!(
            "[ \"$1 $2 $3 $4 $5 $6 $7\" = 'l -slt -ba -sccUTF-8 -pnntmux -t7z --' ] || exit 9\ncp \"$8\" '{}'\n{body}",
            seen.display()
        ),
    );
    SevenZip {
        extractor: Extractor {
            seven_zip: Some(client),
            ..Extractor::default()
        },
        tmp,
    }
}

#[test]
fn the_client_lists_an_encoded_7z_header_from_a_sparse_copy() {
    let client = seven_zip(
        "printf 'Path = Movie.2160p.mkv\\nSize = 9\\nMethod = Copy\\nCRC = 0000000F\\n\\nPath = d\\nFolder = +\\nSize = 0\\n\\nPath = x.nfo\\nSize = 2\\nMethod = LZMA2\\n'",
    );
    let data = fixture(ENCODED);
    let mut flags = ArchiveFlags::default();
    let inspection = client
        .extractor
        .list_encoded_seven_zip(&data, None, 0, &mut flags, client.tmp.path())
        .expect("a listing");
    assert_eq!(names(&inspection), ["Movie.2160p.mkv", "x.nfo"]);
    let Inspection::Listed(listing) = &inspection else {
        unreachable!()
    };
    assert_eq!(listing.marker, Some(Marker::SevenZip));
    assert_eq!(listing.kind, Kind::Szip);
    assert!(listing.listing_only);
    assert_eq!(flags.compressed_files_checked, 1);
    assert!(!flags.archive_listing_incomplete);
    // With no tail the whole archive was in the head, so the copy is the archive.
    assert_eq!(
        fs::read(client.tmp.path().join("seen.7z")).expect("the copy"),
        data
    );
    // The sparse copy is gone.
    let left: Vec<_> = fs::read_dir(client.tmp.path())
        .expect("the directory")
        .map(|entry| entry.expect("an entry").file_name())
        .filter(|name| name.to_string_lossy().starts_with("7z_"))
        .collect();
    assert!(left.is_empty(), "{left:?}");
}

#[test]
fn a_head_and_tail_land_at_their_real_offsets() {
    let client = seven_zip("printf 'Path = a.mkv\\nSize = 1\\n'");
    let data = fixture(ENCODED);
    // All but the packed header stream and end header can be missing.
    let tail = &data[data.len() - 200..];
    let mut flags = ArchiveFlags::default();
    let found = client.extractor.list_encoded_seven_zip(
        &data[..64],
        Some(tail),
        0,
        &mut flags,
        client.tmp.path(),
    );
    assert!(found.is_some(), "listed");
    let copy = fs::read(client.tmp.path().join("seen.7z")).expect("the copy");
    assert_eq!(copy.len(), data.len());
    assert_eq!(&copy[..64], &data[..64]);
    assert_eq!(&copy[data.len() - 200..], tail);
    assert!(copy[64..data.len() - 200].iter().all(|byte| *byte == 0));
}

#[test]
fn a_failing_client_or_a_release_too_small_falls_back() {
    let data = fixture(ENCODED);
    let failing = seven_zip("echo 'Path = a'; echo 'Size = 1'; exit 2");
    let mut flags = ArchiveFlags::default();
    assert!(
        failing
            .extractor
            .list_encoded_seven_zip(&data, None, 0, &mut flags, failing.tmp.path())
            .is_none()
    );
    assert_eq!(flags, ArchiveFlags::default());

    let listing = seven_zip("printf 'Path = a\\nSize = 1\\n'");
    let release_size = i64::try_from(data.len()).expect("small") - 1;
    assert!(
        listing
            .extractor
            .list_encoded_seven_zip(&data, None, release_size, &mut flags, listing.tmp.path())
            .is_none()
    );
    // A head that stops before the end header needs a tail.
    assert!(
        listing
            .extractor
            .list_encoded_seven_zip(&data[..64], None, 0, &mut flags, listing.tmp.path())
            .is_none()
    );
    assert!(!listing.tmp.path().join("seen.7z").exists());

    let missing = Extractor {
        seven_zip: Some(listing.tmp.path().join("absent")),
        ..Extractor::default()
    };
    assert!(
        missing
            .list_encoded_seven_zip(&data, None, 0, &mut flags, listing.tmp.path())
            .is_none()
    );
}

#[test]
fn a_listing_stops_at_the_entry_cap_and_is_marked_incomplete() {
    let mut listing = String::new();
    for index in 0..SEVENZIP_LISTING_MAX_ENTRIES + 50 {
        write!(listing, "Path = f{index}\\nSize = 1\\n\\n").expect("written");
    }
    let client = seven_zip(&format!("printf '{listing}'"));
    let mut flags = ArchiveFlags::default();
    let inspection = client
        .extractor
        .list_encoded_seven_zip(&fixture(ENCODED), None, 0, &mut flags, client.tmp.path())
        .expect("a listing");
    assert_eq!(names(&inspection).len(), SEVENZIP_LISTING_MAX_ENTRIES);
    assert!(flags.archive_listing_incomplete);
}

#[test]
fn a_record_that_cant_be_trusted_is_left_out_and_marks_the_listing_incomplete() {
    let client =
        seven_zip("printf 'Path = bad\\nname.mkv\\nSize = 1\\n\\nPath = ok.mkv\\nSize = 2\\n'");
    let mut flags = ArchiveFlags::default();
    let inspection = client
        .extractor
        .list_encoded_seven_zip(&fixture(ENCODED), None, 0, &mut flags, client.tmp.path())
        .expect("a listing");
    assert_eq!(names(&inspection), ["ok.mkv"]);
    assert!(flags.archive_listing_incomplete);

    // Marked even when nothing survives.
    let rejected = seven_zip("printf 'Path = bad\\nname.mkv\\nSize = 1\\n'");
    let mut flags = ArchiveFlags::default();
    assert!(
        rejected
            .extractor
            .list_encoded_seven_zip(&fixture(ENCODED), None, 0, &mut flags, rejected.tmp.path())
            .is_none()
    );
    assert!(flags.archive_listing_incomplete);
    assert_eq!(flags.compressed_files_checked, 0);
}

#[test]
fn whole_archives_are_unpacked_by_the_client_unless_the_readers_extract() {
    let tmp = tempfile::tempdir().expect("a temporary directory");
    let bin = tempfile::tempdir().expect("a directory for the client");
    let log = bin.path().join("unrar.log");
    let unrar = script(
        bin.path(),
        "unrar",
        &format!("printf '%s\\n' \"$@\" > '{}'", log.display()),
    );
    let data = Arc::new(fixture("rar__store_method.rar"));
    let extractor = Extractor {
        unrar: Some(unrar),
        command_timeout: std::time::Duration::from_secs(30),
        ..Extractor::default()
    };
    let mut flags = ArchiveFlags::default();
    let inspection = extractor
        .process_compressed_data(&data, &mut flags, tmp.path())
        .expect("inspected");
    let Inspection::Listed(listing) = &inspection else {
        panic!("{inspection:?}");
    };
    assert_eq!(listing.marker, Some(Marker::Rar));
    assert!(tmp.path().join("unrar").is_dir());
    assert!(tmp.path().join("unzip").is_dir());
    let arguments = fs::read_to_string(&log).expect("the client ran");
    let arguments: Vec<&str> = arguments.lines().collect();
    assert_eq!(
        arguments[..13],
        [
            "e", "-ai", "-ep", "-c-", "-id", "-inul", "-kb", "-or", "-p-", "-r", "-y", "-@", "--"
        ]
    );
    assert!(arguments[13].ends_with(".rar"));
    assert!(!Path::new(arguments[13]).exists(), "the copy is removed");
    assert_eq!(
        arguments[14],
        format!("{}/", tmp.path().join("unrar").display())
    );

    fs::remove_file(&log).expect("removed");
    let readers = Extractor {
        extract_using_rar_info: true,
        ..extractor
    };
    let mut flags = ArchiveFlags::default();
    readers
        .process_compressed_data(&data, &mut flags, tmp.path())
        .expect("inspected");
    assert!(!log.exists(), "the client is not run");
}

#[test]
fn files_the_readers_cant_copy_come_from_unrar_then_unzip() {
    let tmp = tempfile::tempdir().expect("a temporary directory");
    // unrar finds nothing; unzip leaves the file in a subdirectory under another case.
    let unrar = script(tmp.path(), "unrar", "exit 1");
    let unzip = script(
        tmp.path(),
        "unzip",
        "[ \"$1 $2 $4\" = '-j -d --' ] || exit 9\nmkdir -p \"$3/deep\" \"$3/.hidden\"\nprintf hidden > \"$3/.hidden/movie.nfo\"\nprintf 'from unzip' > \"$3/deep/MOVIE.NFO\"",
    );
    let extractor = Extractor {
        unrar: Some(unrar),
        unzip: Some(unzip),
        command_timeout: std::time::Duration::from_secs(30),
        ..Extractor::default()
    };
    let data = Arc::new(b"not an archive at all".to_vec());
    let found = extractor
        .extract_specific_files(
            &data,
            &[&b"dir/movie.nfo"[..], b"", b"dir/movie.nfo"],
            tmp.path(),
        )
        .expect("extracted");
    assert_eq!(found.len(), 1);
    assert_eq!(found[&b"dir/movie.nfo"[..]], b"from unzip");
    // Every temporary file and directory is gone.
    let mut left: Vec<String> = fs::read_dir(tmp.path())
        .expect("the directory")
        .map(|entry| {
            entry
                .expect("an entry")
                .file_name()
                .to_string_lossy()
                .into_owned()
        })
        .collect();
    left.sort();
    assert_eq!(left, ["unrar", "unzip"]);
}

#[test]
fn unrar_writes_the_file_under_its_base_name() {
    let tmp = tempfile::tempdir().expect("a temporary directory");
    let unrar = script(
        tmp.path(),
        "unrar",
        "[ \"$1 $2 $3 $4 $5 $6 $7\" = 'e -y -c- -inul -p- -@ --' ] || exit 9\nprintf 'from unrar' > \"${10}$(basename \"$9\")\"",
    );
    let extractor = Extractor {
        unrar: Some(unrar),
        command_timeout: std::time::Duration::from_secs(30),
        ..Extractor::default()
    };
    let data = Arc::new(b"still not an archive".to_vec());
    let found = extractor
        .extract_specific_file(&data, b"Sub/Sample.jpg", tmp.path())
        .expect("extracted");
    assert_eq!(found.as_deref(), Some(&b"from unrar"[..]));
}

#[test]
fn a_client_past_its_time_limit_is_killed() {
    let tmp = tempfile::tempdir().expect("a temporary directory");
    let unrar = script(tmp.path(), "unrar", "sleep 30");
    let extractor = Extractor {
        unrar: Some(unrar),
        command_timeout: std::time::Duration::from_millis(200),
        ..Extractor::default()
    };
    let started = std::time::Instant::now();
    let found = extractor
        .extract_specific_file(&Arc::new(b"junk".to_vec()), b"a.nfo", tmp.path())
        .expect("extracted");
    assert_eq!(found, None);
    assert!(started.elapsed() < std::time::Duration::from_secs(10));
}

#[test]
fn stored_files_are_copied_out_without_a_client_and_7z_is_never_extracted() {
    let tmp = tempfile::tempdir().expect("a temporary directory");
    let extractor = Extractor::default();
    let data = Arc::new(fixture("rar__store_method.rar"));
    let mut flags = ArchiveFlags::default();
    let inspection = extractor
        .process_compressed_data(&data, &mut flags, tmp.path())
        .expect("inspected");
    let listed = names(&inspection);
    assert!(!listed.is_empty());
    let wanted: Vec<&[u8]> = listed.iter().map(|name| name.as_bytes()).collect();
    let found = extractor
        .extract_specific_files(&data, &wanted, tmp.path())
        .expect("extracted");
    assert!(!found.is_empty());

    let szip = Arc::new(fixture("szip__store_method.7z"));
    let found = extractor
        .extract_specific_files(&szip, &[&b"anything"[..]], tmp.path())
        .expect("extracted");
    assert!(found.is_empty());
}

//! Crafted inputs that multiply into memory, recursion or quadratic parsing. A run that returns
//! at all is most of the test: before the limits these aborted or ran out of memory.

use std::os::unix::fs::PermissionsExt;
use std::path::Path;
use std::sync::Arc;
use std::thread;
use std::time::{Duration, Instant};

use nntmux_postprocess::archive_info::{ArchiveInfo, ListEntry};

const SMALL_STACK: usize = 2 * 1024 * 1024;

fn le32(v: u32) -> [u8; 4] {
    v.to_le_bytes()
}

fn zip_rec(name: &str, unc: u32) -> Vec<u8> {
    let mut r = vec![0x50, 0x4b, 3, 4, 20, 0, 0, 0, 0, 0, 0, 0, 0x21, 0x21];
    r.extend(le32(0));
    r.extend(le32(0));
    r.extend(le32(unc));
    r.extend((name.len() as u16).to_le_bytes());
    r.extend(0u16.to_le_bytes());
    r.extend(name.as_bytes());
    r
}
fn zipbomb(n: usize) -> Vec<u8> {
    let mut d = Vec::new();
    for i in 0..n {
        d.extend(zip_rec(&format!("{i}.zip"), 0x0fff_ffff));
    }
    d.extend([0u8; 64]);
    d
}
fn szip_self() -> Vec<u8> {
    let mut d: Vec<u8> = vec![
        0xAA, 0x01, 0x04, 0x06, 0x00, 0x01, 0x09, 0x01, 0x00, 0x07, 0x0b, 0x01, 0x00, 0x01, 0x01,
        0x00, 0x0c, 0x01, 0x00, 0x00, 0x05, 0x01, 0x11, 0x0b, 0x00,
    ];
    for c in "a.7z".encode_utf16() {
        d.extend(c.to_le_bytes());
    }
    d.extend([0, 0, 0x00]);
    d
}
fn szip_files(l: usize) -> Vec<u8> {
    let mut d = b"7z\xbc\xaf\x27\x1c".to_vec();
    d.extend([0u8; 26]);
    let count = (l * 8) as u32;
    while d.len() + 6 <= l {
        d.extend([
            0x05,
            0xE0 | ((count >> 24) as u8 & 0xf),
            count as u8,
            (count >> 8) as u8,
            (count >> 16) as u8,
            0x00,
        ]);
    }
    d
}
fn rar5_names(l: usize) -> Vec<u8> {
    let mut d = b"Rar!\x1a\x07\x01\x00".to_vec();
    let ns = (l / 2) as u32;
    while d.len() < l {
        d.extend([
            0,
            0,
            0,
            0,
            11,
            2,
            2,
            0,
            0,
            0,
            0,
            0,
            0,
            (ns & 0x7f) as u8 | 0x80,
            ((ns >> 7) & 0x7f) as u8 | 0x80,
            ((ns >> 14) & 0x7f) as u8,
        ]);
    }
    d
}
fn rar5_headsize() -> Vec<u8> {
    let mut d = b"Rar!\x1a\x07\x01\x00".to_vec();
    d.extend([0, 0, 0, 0]);
    d.extend([0xff, 0xff, 0xff, 0xff, 0xff, 0xff, 0xff, 0xff, 0xff, 0x7f]);
    d.extend([2, 2, 1, 0, 0, 0, 0, 0, 5]);
    d.extend(b"a.txt");
    d.extend([0u8; 40]);
    d
}
fn szip_packed() -> Vec<u8> {
    let mut d = b"7z\xbc\xaf\x27\x1c".to_vec();
    d.extend([0u8; 26]);
    d.extend([0x04, 0x07, 0x0b, 0x01, 0x00]);
    d.extend([0x80, 0x90]); // 0x80 first => 14-bit? see read_number: 2-byte: low = 0x90 | (0&..)<<8 => 144 coders
    for _ in 0..144 {
        d.push(0x10);
        d.extend([0xFE, 0xff, 0xff, 0xff, 0xff, 0xff, 0xff, 0xff]);
        d.push(0x00);
    }
    d.extend([0u8; 32]);
    d
}
fn rar_chain_fast(depth: usize) -> Vec<u8> {
    // same as rar_chain, linear: total size known.
    let unit = 44usize;
    let total = depth * unit + 16;
    let mut out = Vec::with_capacity(total);
    for i in 0..depth {
        let inner = (total - (i + 1) * unit) as u32;
        out.extend(b"Rar!\x1a\x07\x00");
        out.extend([0, 0, 0x74, 0, 0, 37, 0]);
        out.extend(le32(inner));
        out.extend(le32(inner));
        out.push(3);
        out.extend(le32(0));
        out.extend(le32(0x21210000));
        out.push(29);
        out.push(0x30);
        out.extend(5u16.to_le_bytes());
        out.extend(le32(0));
        out.extend(b"a.rar");
    }
    out.extend([0u8; 16]);
    out
}
fn comp_entries(n: usize) -> Vec<u8> {
    let mut d = b"Rar!\x1a\x07\x00".to_vec();
    for i in 0..n {
        let name = format!("{i:05}.rar");
        d.extend([0, 0, 0x74, 0, 0]);
        d.extend(((32 + name.len()) as u16).to_le_bytes());
        d.extend(le32(1));
        d.extend(le32(1));
        d.push(3);
        d.extend(le32(0));
        d.extend(le32(0x21210000));
        d.push(29);
        d.push(0x33);
        d.extend((name.len() as u16).to_le_bytes());
        d.extend(le32(0));
        d.extend(name.as_bytes());
        d.push(0);
    }
    d.extend([0u8; 8]);
    d
}
fn scan7z(l: usize) -> Vec<u8> {
    let unit = [0x17u8, 0x06, 0x00, 0xDF, 0xFF, 0xFF, 0x09];
    let mut d = Vec::with_capacity(l);
    while d.len() < l {
        d.extend(unit);
    }
    d.truncate(l);
    d
}

fn has_error(entries: &[ListEntry]) -> bool {
    entries.iter().any(|entry| match entry {
        ListEntry::Error { .. } => true,
        ListEntry::Nested(inner) => has_error(inner),
        ListEntry::File(_) => false,
    })
}

/// Records named `.sfv` over a shared tail of SFV lines, so each child holds every row.
fn sfv_flood(children: usize, length: usize) -> Vec<u8> {
    let mut data = Vec::new();
    for index in 0..children {
        data.extend(zip_rec(&format!("{index:04}.sfv"), 0x0fff_ffff));
        data.extend(b"x ab\n");
    }
    data.extend([0x50, 0x4b, 3, 4]);
    data.extend([0_u8; 26]);
    while data.len() < length {
        data.extend(b"a 00\n");
    }
    data
}

/// Each candidate claims millions of streams without reading a byte for them.
fn digest_scan(length: usize) -> Vec<u8> {
    let unit = [
        0x01, 0x04, 0x07, 0x0b, 0x01, 0x00, 0x01, 0x01, 0x00, 0x0c, 0x01, 0x00, 0x08, 0x0d, 0xe0,
        0x00, 0x00, 0x7f, 0x0a, 0x00,
    ];
    unit.iter().copied().cycle().take(length).collect()
}

/// A stored entry of a ZIP.
fn stored_entry(name: &str, data: &[u8]) -> Vec<u8> {
    let size = u32::try_from(data.len()).expect("small");
    let mut entry = vec![0x50, 0x4b, 3, 4, 20, 0, 0, 0, 0, 0, 0, 0, 0x21, 0x21];
    entry.extend(le32(1));
    entry.extend(le32(size));
    entry.extend(le32(size));
    entry.extend(u16::try_from(name.len()).expect("short").to_le_bytes());
    entry.extend(0_u16.to_le_bytes());
    entry.extend(name.as_bytes());
    entry.extend(data);
    entry
}

/// A RAR 1.5 file block, with no marker before it, whose header's CRC holds.
fn rar_file_block(name: &str) -> Vec<u8> {
    let mut block = vec![0x74, 0, 0];
    block.extend(u16::try_from(32 + name.len()).expect("short").to_le_bytes());
    block.extend(le32(10));
    block.extend(le32(10));
    block.push(3);
    block.extend(le32(0));
    block.extend(le32(0x2121_0000));
    block.push(29);
    block.push(0x30);
    block.extend(u16::try_from(name.len()).expect("short").to_le_bytes());
    block.extend(le32(0));
    block.extend(name.as_bytes());
    let crc = crc32fast::hash(&block) & 0xffff;
    let mut out = u16::try_from(crc).expect("masked").to_le_bytes().to_vec();
    out.extend(block);
    out
}

fn client(directory: &Path, script: &str) -> std::path::PathBuf {
    let path = directory.join("unrar");
    std::fs::write(&path, format!("#!/bin/sh\n{script}\n")).expect("the client");
    std::fs::set_permissions(&path, std::fs::Permissions::from_mode(0o755)).expect("its mode");
    path
}

fn has_file(entries: &[ListEntry], name: &[u8]) -> bool {
    entries.iter().any(|entry| match entry {
        ListEntry::File(file) => file.name == name,
        ListEntry::Nested(inner) => has_file(inner, name),
        ListEntry::Error { .. } => false,
    })
}

/// Lists `data` on a worker-sized stack, in a time a worker can afford.
fn list(data: Vec<u8>, unrar: Option<&Path>) -> Vec<ListEntry> {
    let unrar = unrar.map(Path::to_path_buf);
    let started = Instant::now();
    let worker = thread::Builder::new()
        .stack_size(SMALL_STACK)
        .spawn(move || {
            let mut info = ArchiveInfo::from_data(Arc::new(data), true, unrar.as_deref())
                .expect("the archive opens");
            info.archive_file_list(true)
                .expect("the listing finishes")
                .unwrap_or_default()
        })
        .expect("a thread");
    let entries = worker.join().expect("no panic or overflow");
    // Generous, for debug builds on a loaded machine.
    assert!(
        started.elapsed() < Duration::from_secs(120),
        "{:?}",
        started.elapsed()
    );
    entries
}

#[test]
fn overlapping_zip_entries_stop_at_the_limits() {
    assert!(has_error(&list(zipbomb(20), None)));
}

#[test]
fn nesting_stops_before_the_stack_does() {
    assert!(has_error(&list(rar_chain_fast(5_000), None)));
}

#[test]
fn a_seven_zip_containing_itself_stops() {
    list(szip_self(), None);
}

#[test]
fn seven_zip_file_counts_stop_at_the_memory_limit() {
    list(szip_files(1_048_576), None);
}

#[test]
fn rar_names_overlapping_later_blocks_stop_at_the_memory_limit() {
    list(rar5_names(1_048_576), None);
}

#[test]
fn fragment_scans_stop() {
    list(vec![0x74; 1_048_576], None);
    list(scan7z(1_048_576), None);
}

#[test]
fn saturated_sizes_do_not_overflow() {
    list(rar5_headsize(), None);
    list(szip_packed(), None);
}

/// A client that unpacks every entry to a nested archive would otherwise go on forever.
#[test]
fn extraction_stops_at_its_count() {
    let directory = tempfile::tempdir().expect("a temp dir");
    let client = directory.path().join("unrar");
    std::fs::write(&client, "#!/bin/sh\nexit 0\n").expect("the client");
    std::fs::set_permissions(&client, std::fs::Permissions::from_mode(0o755)).expect("its mode");
    let entries = list(comp_entries(2_000), Some(&client));
    assert!(has_error(&entries));
}

#[test]
fn sfv_rows_count_against_the_memory_limit() {
    let entries = list(sfv_flood(40, 256 * 1024), None);
    assert!(has_error(&entries), "the limit shows in the listing");
}

#[test]
fn a_digest_scan_stops() {
    list(digest_scan(1_048_576), None);
}

/// A child past a limit has to show, or an encrypted archive could hide behind junk.
#[test]
fn children_past_a_limit_are_listed_as_errors() {
    let mut data = Vec::new();
    for index in 0..16 {
        data.extend(stored_entry(
            &format!("junk{index}.zip"),
            &vec![0x74; 60_000],
        ));
    }
    data.extend(stored_entry("hidden.rar", &rar_chain_fast(1)));
    let entries = list(data, None);
    assert!(has_file(&entries, b"hidden.rar"));
    assert!(has_file(&entries, b"a.rar") || has_error(&entries));
}

#[test]
fn plain_text_can_hold_a_real_header_at_its_end() {
    let line: &[u8] = b"the quick brown fox went to the store to get that thing that it wanted\n";
    let mut data: Vec<u8> = line.iter().copied().cycle().take(800_000).collect();
    data.extend(rar_file_block("next_part.mkv"));
    let entries = list(data, None);
    assert!(has_file(&entries, b"next_part.mkv"));
}

/// Numeric extensions are common in real archives, and most aren't archives.
#[test]
fn many_entries_that_only_look_like_archives_cost_nothing() {
    let directory = tempfile::tempdir().expect("a temp dir");
    let client = client(directory.path(), "echo not an archive");
    let mut data = b"Rar!\x1a\x07\x00".to_vec();
    for index in 0..70 {
        let name = format!("man/tool{index:02}.1");
        data.extend([0, 0, 0x74, 0, 0]);
        data.extend(u16::try_from(32 + name.len()).expect("short").to_le_bytes());
        data.extend(le32(1));
        data.extend(le32(1));
        data.push(3);
        data.extend(le32(0));
        data.extend(le32(0x2121_0000));
        data.push(29);
        data.push(0x33);
        data.extend(u16::try_from(name.len()).expect("short").to_le_bytes());
        data.extend(le32(0));
        data.extend(name.as_bytes());
        data.push(0);
    }
    data.extend([0_u8; 8]);
    assert!(!has_error(&list(data, Some(&client))));
}

#[test]
fn a_client_error_is_cut_short() {
    let directory = tempfile::tempdir().expect("a temp dir");
    let client = client(directory.path(), "head -c 10000000 /dev/zero >&2");
    let entries = list(comp_entries(1), Some(&client));
    let longest = entries
        .iter()
        .map(|entry| match entry {
            ListEntry::Error { error, .. } => error.len(),
            _ => 0,
        })
        .max();
    assert!(
        longest.is_some_and(|length| length <= 512 * 1024),
        "{longest:?}"
    );
}

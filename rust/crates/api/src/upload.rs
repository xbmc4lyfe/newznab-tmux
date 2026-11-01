//! `NzbUploadStagingService`: uploaded NZBs and NFOs land in a hidden staging directory with a
//! manifest, then move whole into the folder the importer scans.

use std::fs::{self, OpenOptions};
use std::io::Write;
use std::os::unix::fs::{MetadataExt, OpenOptionsExt};
use std::path::{Path, PathBuf};

use chrono::{SecondsFormat, Utc};
use quick_xml::Reader;
use quick_xml::events::Event;
use rand::Rng;
use serde_json::json;
use sha2::{Digest, Sha256};
use tracing::{error, info};

const STAGING_DIRECTORY: &str = ".staging";
const MANIFEST_FILENAME: &str = "nntmux-upload.json";
const MAX_NFO_SIZE: usize = 65535;

/// A file from a multipart body.
#[derive(Clone, Debug)]
pub struct UploadedFile {
    pub field: String,
    pub filename: String,
    pub content: Vec<u8>,
}

/// `NzbUploadException`.
#[derive(Debug)]
pub struct UploadError {
    pub message: String,
    pub status: u16,
}

fn fail(status: u16, message: impl Into<String>) -> UploadError {
    UploadError {
        message: message.into(),
        status,
    }
}

pub struct Staged {
    pub name: String,
    pub nzb_filename: String,
    pub nfo_filename: Option<String>,
}

/// `isValidNewznabNzb`: well-formed XML whose root is `nzb` or whose default namespace mentions it.
fn is_valid_nzb(content: &[u8]) -> bool {
    let mut reader = Reader::from_reader(content);
    reader.config_mut().check_end_names = true;
    let mut buffer = Vec::new();
    let mut root_seen = false;
    let mut is_nzb = false;
    let mut depth = 0usize;
    loop {
        match reader.read_event_into(&mut buffer) {
            Ok(Event::Start(start)) => {
                depth += 1;
                if !root_seen {
                    root_seen = true;
                    is_nzb = root_is_nzb(&start);
                }
            }
            Ok(Event::Empty(start)) => {
                if !root_seen {
                    root_seen = true;
                    is_nzb = root_is_nzb(&start);
                }
            }
            Ok(Event::End(_)) => depth = depth.saturating_sub(1),
            Ok(Event::Eof) => return root_seen && depth == 0 && is_nzb,
            Ok(_) => {}
            Err(_) => return false,
        }
        buffer.clear();
    }
}

fn root_is_nzb(start: &quick_xml::events::BytesStart<'_>) -> bool {
    let local = start.local_name();
    let name: &str = local.as_ref();
    if name.eq_ignore_ascii_case("nzb") {
        return true;
    }
    start
        .attributes()
        .flatten()
        .any(|attribute| attribute.key.0 == "xmlns" && attribute.value.contains("nzb"))
}

/// `pathinfo`'s extension and filename, and the safety checks around them. Symfony has already
/// reduced the client's name to its last path component.
fn validate<'a>(
    file: &'a UploadedFile,
    expected: &str,
) -> Result<(String, String, &'a [u8]), UploadError> {
    let normalized = file.filename.replace('\\', "/");
    let filename = normalized.rsplit('/').next().unwrap_or_default().to_owned();
    if filename.is_empty() || filename == "." || filename == ".." || filename.contains('\0') {
        return Err(fail(400, format!("Unsafe {expected} filename")));
    }
    let (basename, extension) = filename.rsplit_once('.').unwrap_or((&filename, ""));
    if !extension.eq_ignore_ascii_case(expected) || basename.trim().is_empty() {
        return Err(fail(
            400,
            format!("The {expected} field must contain a .{expected} file"),
        ));
    }
    if expected == "nzb" && !is_valid_nzb(&file.content) {
        return Err(fail(400, "Invalid NZB payload"));
    }
    if expected == "nfo" && (file.content.is_empty() || file.content.len() > MAX_NFO_SIZE) {
        return Err(fail(
            400,
            "NFO upload must not be empty or exceed 65535 bytes",
        ));
    }
    let basename = basename.to_owned();
    Ok((filename, basename, &file.content))
}

fn ensure_folder(folder: Option<&Path>) -> Result<PathBuf, UploadError> {
    let Some(folder) = folder.filter(|path| !path.as_os_str().is_empty()) else {
        return Err(fail(500, "NZB upload folder is not configured"));
    };
    if !folder.is_dir() && fs::create_dir_all(folder).is_err() {
        return Err(fail(500, "Failed to create NZB upload folder"));
    }
    Ok(folder.to_path_buf())
}

/// Lives inside the upload folder so publishing is a same-filesystem rename; the dot keeps importer
/// scans from seeing abandoned uploads.
fn ensure_staging(folder: &Path) -> Result<PathBuf, UploadError> {
    let staging = folder.join(STAGING_DIRECTORY);
    // Concurrent uploads may race to create it, so only the end state counts.
    let _ = fs::create_dir_all(&staging);
    if !staging.is_dir() {
        return Err(fail(500, "Failed to create upload staging directory"));
    }
    Ok(staging)
}

fn random_hex(bytes: usize) -> String {
    let mut buffer = vec![0u8; bytes];
    rand::rng().fill(buffer.as_mut_slice());
    hex::encode(buffer)
}

/// A random version-4 UUID, as `Str::uuid` gives.
fn uuid() -> String {
    let mut bytes = [0u8; 16];
    rand::rng().fill(&mut bytes);
    bytes[6] = (bytes[6] & 0x0f) | 0x40;
    bytes[8] = (bytes[8] & 0x3f) | 0x80;
    let text = hex::encode(bytes);
    format!(
        "{}-{}-{}-{}-{}",
        &text[..8],
        &text[8..12],
        &text[12..16],
        &text[16..20],
        &text[20..]
    )
}

/// `sourceIdentity`: the importer compares this exact JSON against the file it finds.
fn source_identity(path: &Path) -> std::io::Result<String> {
    let meta = fs::symlink_metadata(path)?;
    Ok(json_identity(&meta))
}

fn json_identity(meta: &fs::Metadata) -> String {
    format!(
        "{{\"dev\":{},\"ino\":{},\"mode\":{},\"size\":{},\"mtime\":{},\"ctime\":{}}}",
        meta.dev(),
        meta.ino(),
        meta.mode(),
        meta.size(),
        meta.mtime(),
        meta.ctime()
    )
}

/// `NzbUploadManifestService::create`, with `write`'s private, durable replace.
fn write_manifest(
    directory: &Path,
    upload_id: &str,
    nzb_filename: &str,
    nfo: Option<(&str, &[u8])>,
) -> std::io::Result<()> {
    let timestamp = Utc::now().to_rfc3339_opts(SecondsFormat::Secs, false);
    let mut manifest = json!({
        "version": 2,
        "upload_id": upload_id,
        "state": "staged",
        "nzb": {"filename": nzb_filename},
        "nfo": nfo.map(|(name, _)| json!({"filename": name})),
        "release_id": null,
        "release_guid": null,
        "candidate_identity": null,
        "last_error": null,
        "created_at": timestamp,
        "updated_at": timestamp,
    });
    if let Some((name, content)) = nfo {
        manifest["nfo_capture"] = json!({
            "protocol": 1,
            "attempt": random_hex(16),
            "upload_id": upload_id,
            "filename": name,
            "digest": hex::encode(Sha256::digest(content)),
            "bytes": content.len(),
            "source_identity": source_identity(&directory.join(name))?,
        });
    }
    let mut body = serde_json::to_string_pretty(&manifest).map_err(std::io::Error::other)?;
    body.push('\n');
    let target = directory.join(MANIFEST_FILENAME);
    let temporary = directory.join(format!("{MANIFEST_FILENAME}.tmp.{}", random_hex(16)));
    let mut stream = OpenOptions::new()
        .write(true)
        .create_new(true)
        .mode(0o600)
        .open(&temporary)?;
    stream.write_all(body.as_bytes())?;
    stream.sync_all()?;
    drop(stream);
    fs::rename(&temporary, &target)?;
    fs::File::open(directory)?.sync_all()
}

fn write_pair(
    staging: &Path,
    upload_id: &str,
    nzb: (&str, &[u8]),
    nfo: Option<(&str, &[u8])>,
) -> Result<(), UploadError> {
    for (filename, content) in std::iter::once(nzb).chain(nfo) {
        fs::write(staging.join(filename), content)
            .map_err(|_| fail(500, format!("Failed to write {filename} to disk")))?;
    }
    write_manifest(staging, upload_id, nzb.0, nfo)
        .map_err(|_| fail(500, "Failed to write upload pair to disk"))
}

/// Stages a paired NZB and NFO. Blocking: call from `spawn_blocking`.
pub fn stage(
    folder: Option<&Path>,
    nzb: &UploadedFile,
    nfo: Option<&UploadedFile>,
) -> Result<Staged, UploadError> {
    let (nzb_name, nzb_base, nzb_content) = validate(nzb, "nzb")?;
    let nfo_details = nfo.map(|file| validate(file, "nfo")).transpose()?;
    let folder = ensure_folder(folder)?;
    let upload_id = uuid();
    let staging = ensure_staging(&folder)?.join(&upload_id);
    fs::create_dir_all(&staging)
        .map_err(|_| fail(500, "Failed to create upload staging directory"))?;
    let nfo_pair = nfo_details
        .as_ref()
        .map(|(name, _, content)| (name.as_str(), *content));
    let written =
        write_pair(&staging, &upload_id, (&nzb_name, nzb_content), nfo_pair).and_then(|()| {
            fs::rename(&staging, folder.join(&upload_id))
                .map_err(|_| fail(500, "Failed to publish staged upload"))
        });
    if let Err(failure) = written {
        if staging.is_dir() && fs::remove_dir_all(&staging).is_err() {
            error!(upload_id, "failed to roll back a partial API upload");
            return Err(fail(
                500,
                "Failed to write upload pair and roll back partial files",
            ));
        }
        return Err(failure);
    }
    info!(upload_id, filename = nzb_name, "file staged by API");
    Ok(Staged {
        name: nzb_base,
        nzb_filename: nzb_name,
        nfo_filename: nfo_details.map(|(name, _, _)| name),
    })
}

/// v1's single `file` field: NZBs go through staging, NFOs publish alone. Returns the name.
pub fn stage_legacy(folder: Option<&Path>, file: &UploadedFile) -> Result<String, UploadError> {
    let extension = file
        .filename
        .rsplit_once('.')
        .map_or("", |(_, extension)| extension)
        .to_ascii_lowercase();
    if extension == "nzb" {
        return stage(folder, file, None).map(|staged| staged.name);
    }
    if extension != "nfo" {
        return Err(fail(400, "File is not an NZB or NFO file"));
    }
    let (filename, basename, content) = validate(file, "nfo")?;
    let folder = ensure_folder(folder)?;
    let target = folder.join(&filename);
    if target.exists() {
        return Err(fail(
            409,
            format!("A staged file named {filename} already exists"),
        ));
    }
    let temporary = ensure_staging(&folder)?.join(format!("{}.nfo", uuid()));
    fs::write(&temporary, content)
        .map_err(|_| fail(500, format!("Failed to write {filename} to disk")))?;
    if fs::rename(&temporary, &target).is_err() {
        let _ = fs::remove_file(&temporary);
        return Err(fail(500, "Failed to publish staged upload"));
    }
    Ok(basename)
}

#[cfg(test)]
mod tests {
    use super::*;

    fn file(field: &str, filename: &str, content: &str) -> UploadedFile {
        UploadedFile {
            field: field.to_owned(),
            filename: filename.to_owned(),
            content: content.as_bytes().to_vec(),
        }
    }

    #[test]
    fn stages_a_pair_where_the_importer_looks() {
        let root = tempfile::tempdir().expect("tempdir");
        let nzb = file("nzb", "x/Some.Name.NZB", "<nzb><file/></nzb>");
        let nfo = file("nfo", "Some.Name.nfo", "hello");
        let staged = stage(Some(root.path()), &nzb, Some(&nfo)).expect("staged");
        assert_eq!(staged.name, "Some.Name");
        let uploads: Vec<_> = fs::read_dir(root.path())
            .expect("list")
            .flatten()
            .filter(|entry| entry.file_name() != STAGING_DIRECTORY)
            .collect();
        assert_eq!(uploads.len(), 1);
        let dir = uploads[0].path();
        assert_eq!(fs::read(dir.join("Some.Name.nfo")).expect("nfo"), b"hello");
        let manifest: serde_json::Value =
            serde_json::from_slice(&fs::read(dir.join(MANIFEST_FILENAME)).expect("manifest"))
                .expect("json");
        assert_eq!(manifest["state"], "staged");
        assert_eq!(manifest["nfo_capture"]["bytes"], 5);
        assert_eq!(
            manifest["nfo_capture"]["source_identity"],
            source_identity(&dir.join("Some.Name.nfo")).expect("identity")
        );
    }

    #[test]
    fn refuses_what_php_refuses() {
        let cases = [
            (
                file("nzb", "a.txt", "<nzb/>"),
                "The nzb field must contain a .nzb file",
            ),
            (
                file("nzb", ".nzb", "<nzb/>"),
                "The nzb field must contain a .nzb file",
            ),
            (file("nzb", "a.nzb", "<html/>"), "Invalid NZB payload"),
            (
                file("nzb", "a.nzb", "<nzb><a></nzb>"),
                "Invalid NZB payload",
            ),
            (file("nzb", "a.nzb", "plain"), "Invalid NZB payload"),
        ];
        for (upload, message) in cases {
            let failure = stage(None, &upload, None).err().expect("refused");
            assert_eq!(failure.message, message, "{}", upload.filename);
            assert_eq!(failure.status, 400);
        }
        assert!(is_valid_nzb(
            b"<root xmlns=\"http://www.newzbin.com/DTD/2003/nzb\"/>"
        ));
        let failure = stage(None, &file("nzb", "a.nzb", "<nzb/>"), None)
            .err()
            .expect("unconfigured");
        assert_eq!(failure.status, 500);
    }
}

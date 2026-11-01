//! The part of the `Par2Info` vendor class the PAR2 rename path uses: whole packets are
//! checksummed and unpacked, and a recovery set's file list is read back from them.
//!
//! `Par2Info` never checks the packet marker of a header, finds the first marker in the data
//! and then trusts each header's length. A truncated tail ends the walk quietly; a checksum
//! failure is an error. Callers feed it complete packets only, so the leniency is kept for
//! parity rather than because anything relies on it.

use md5::{Digest, Md5};

pub const MARKER: &[u8; 8] = b"PAR2\0PKT";
pub const MAIN: &[u8; 16] = b"PAR 2.0\0Main\0\0\0\0";
pub const FILEDESC: &[u8; 16] = b"PAR 2.0\0FileDesc";
pub const IFSC: &[u8; 16] = b"PAR 2.0\0IFSC\0\0\0\0";
pub const RECOVERY: &[u8; 16] = b"PAR 2.0\0RecvSlic";
pub const CREATOR: &[u8; 16] = b"PAR 2.0\0Creator\0";
pub const UNICODE_NAME: &[u8; 16] = b"PAR 2.0\0UniFileN";
pub const COMMENT_ASCII: &[u8; 16] = b"PAR 2.0\0CommASCI";
pub const COMMENT_UNICODE: &[u8; 16] = b"PAR 2.0\0CommUni\0";
pub const INPUT_BLOCK: &[u8; 16] = b"PAR 2.0\0FileSlic";
pub const RECOVERY_VERIFICATION: &[u8; 16] = b"PAR 2.0\0RFSC\0\0\0\0";
pub const PACKED_MAIN: &[u8; 16] = b"PAR 2.0\0PkdMain\0";
pub const PACKED_RECOVERY: &[u8; 16] = b"PAR 2.0\0PkdRecvS";

/// `Par2Info::$error` was set, or no packet could be read.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct Par2Error(pub &'static str);

impl std::fmt::Display for Par2Error {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.write_str(self.0)
    }
}

impl std::error::Error for Par2Error {}

#[derive(Debug, Clone, PartialEq, Eq)]
pub enum Body {
    Main {
        recoverable: Vec<String>,
        other: Vec<String>,
    },
    FileDesc {
        file_id: String,
        hash: String,
        hash_16k: String,
        length: u64,
        /// The name as the accumulator's subclass reads it: only NUL padding trimmed.
        name: Vec<u8>,
    },
    Ifsc {
        file_id: String,
        blocks: usize,
    },
    Other,
}

#[derive(Debug, Clone, PartialEq, Eq)]
pub struct Packet {
    pub kind: [u8; 16],
    pub set_id: String,
    pub body: Body,
}

#[derive(Debug, Clone, PartialEq, Eq)]
pub struct Par2Info {
    pub packets: Vec<Packet>,
}

fn le32(bytes: &[u8]) -> u64 {
    u64::from(u32::from_le_bytes(bytes[..4].try_into().expect("four bytes")))
}

/// `setData` over bytes that start at (or contain) a packet marker.
pub fn parse(data: &[u8]) -> Result<Par2Info, Par2Error> {
    if data.is_empty() {
        return Err(Par2Error("No data was passed, nothing to analyze"));
    }
    let Some(start) = data.windows(MARKER.len()).position(|w| w == MARKER) else {
        return Err(Par2Error(
            "Could not find a Packet Marker, not a valid PAR2 file",
        ));
    };
    let mut packets = Vec::new();
    let mut offset = start;
    while offset < data.len() {
        // A header that doesn't fit ends the walk without an error, as a failed read does.
        if offset + 64 > data.len() {
            break;
        }
        let header = &data[offset..offset + 64];
        let length = le32(&header[8..12]).wrapping_add(le32(&header[12..16]) << 32);
        if length < 64 || offset as u64 + length > data.len() as u64 {
            break;
        }
        let end = offset + length as usize;
        let checksum: [u8; 16] = Md5::digest(&data[offset + 32..end]).into();
        if checksum != header[16..32] {
            return Err(Par2Error("Packet failed checksum"));
        }
        let kind: [u8; 16] = header[48..64].try_into().expect("sixteen bytes");
        let set_id = hex::encode(&header[32..48]);
        let packet = unpack(&data[offset..end], kind, set_id);
        let Some(packet) = packet else {
            // The body promised more than the packet holds, so a read failed.
            break;
        };
        packets.push(packet);
        offset = end;
    }
    if packets.is_empty() {
        return Err(Par2Error("No valid PAR2 packets were found"));
    }
    Ok(Par2Info { packets })
}

/// `processPacket`. `None` when the body is shorter than its fields need.
fn unpack(packet: &[u8], kind: [u8; 16], set_id: String) -> Option<Packet> {
    let body = &packet[64..];
    let parsed = if &kind == MAIN {
        if body.len() < 12 {
            return None;
        }
        let count = le32(&body[8..12]) as usize;
        let ids = &body[12..];
        let mut recoverable = Vec::new();
        let mut other = Vec::new();
        let mut at = 0;
        for _ in 0..count {
            recoverable.push(hex::encode(ids.get(at..at + 16)?));
            at += 16;
        }
        while at < ids.len() {
            other.push(hex::encode(ids.get(at..at + 16)?));
            at += 16;
        }
        Body::Main { recoverable, other }
    } else if &kind == FILEDESC {
        if body.len() < 56 {
            return None;
        }
        let low = le32(&body[48..52]);
        let high = le32(&body[52..56]);
        let mut name = body[56..].to_vec();
        while name.last() == Some(&0) {
            name.pop();
        }
        Body::FileDesc {
            file_id: hex::encode(&body[0..16]),
            hash: hex::encode(&body[16..32]),
            hash_16k: hex::encode(&body[32..48]),
            length: low.wrapping_add(high << 32),
            name,
        }
    } else if &kind == IFSC {
        if body.len() < 16 {
            return None;
        }
        let mut blocks = 0;
        let mut at = 16;
        while at < body.len() {
            body.get(at..at + 20)?;
            blocks += 1;
            at += 20;
        }
        Body::Ifsc {
            file_id: hex::encode(&body[0..16]),
            blocks,
        }
    } else {
        Body::Other
    };
    Some(Packet {
        kind,
        set_id,
        body: parsed,
    })
}

/// One recovery set's file, as `getFileList` and `Par2FileList::getFiles` assemble it.
#[derive(Debug, Clone, Default, PartialEq, Eq)]
pub struct FileEntry {
    pub file_id: String,
    pub name: Option<Vec<u8>>,
    pub size: Option<u64>,
    pub size_decimal: Option<String>,
    pub hash: Option<String>,
    pub hash_16k: Option<String>,
    pub blocks: usize,
}

impl Par2Info {
    /// `Par2FileList::hasUnresolvedDescriptors`: a declared identity without a usable
    /// description in its recovery set.
    pub fn has_unresolved_descriptors(&self) -> bool {
        use std::collections::{HashMap, HashSet};
        let mut required: HashMap<&str, Vec<&str>> = HashMap::new();
        let mut described: HashMap<&str, HashMap<&str, bool>> = HashMap::new();
        let mut any_described = false;
        for packet in &self.packets {
            let set = packet.set_id.as_str();
            match &packet.body {
                Body::Main { recoverable, other } => {
                    for id in recoverable.iter().chain(other) {
                        required.entry(set).or_default().push(id);
                    }
                }
                Body::Ifsc { file_id, .. } => required.entry(set).or_default().push(file_id),
                Body::FileDesc { file_id, name, .. } => {
                    required.entry(set).or_default().push(file_id);
                    any_described = true;
                    let entry = described.entry(set).or_default();
                    entry
                        .entry(file_id)
                        .or_insert_with(|| !php_trim(name).is_empty());
                }
                Body::Other => {}
            }
        }
        let mut seen: HashSet<(&str, &str)> = HashSet::new();
        for (set, ids) in &required {
            for id in ids {
                if !seen.insert((set, id)) {
                    continue;
                }
                let ok = described
                    .get(set)
                    .and_then(|files| files.get(id))
                    .copied()
                    .unwrap_or(false);
                if !ok {
                    return true;
                }
            }
        }
        !any_described
    }

    /// `getFileList` followed by `Par2FileList::getFiles`' repair of entries a verification
    /// packet created before their description arrived. Insertion order is kept.
    pub fn files(&self) -> Vec<FileEntry> {
        let mut files: Vec<FileEntry> = Vec::new();
        let index = |files: &Vec<FileEntry>, id: &str| files.iter().position(|f| f.file_id == id);
        // getFileList
        for packet in &self.packets {
            match &packet.body {
                Body::FileDesc {
                    file_id,
                    hash,
                    hash_16k,
                    length,
                    name,
                } => {
                    if index(&files, file_id).is_none() {
                        files.push(FileEntry {
                            file_id: file_id.clone(),
                            name: Some(summary_name(name)),
                            size: Some(*length),
                            size_decimal: None,
                            hash: Some(hash.clone()),
                            hash_16k: Some(hash_16k.clone()),
                            blocks: 0,
                        });
                    }
                }
                Body::Ifsc { file_id, blocks } => {
                    let at = match index(&files, file_id) {
                        Some(at) => at,
                        None => {
                            files.push(FileEntry {
                                file_id: file_id.clone(),
                                ..FileEntry::default()
                            });
                            files.len() - 1
                        }
                    };
                    if files[at].blocks == 0 {
                        files[at].blocks = *blocks;
                    }
                }
                _ => {}
            }
        }
        // getFiles
        for packet in &self.packets {
            if let Body::FileDesc {
                file_id,
                hash,
                hash_16k,
                length,
                name,
            } = &packet.body
                && let Some(at) = index(&files, file_id)
                && files[at].name.is_none()
            {
                files[at].name = Some(summary_name(name));
                files[at].size = Some(*length);
                files[at].hash = Some(hash.clone());
                files[at].hash_16k = Some(hash_16k.clone());
            }
        }
        files
    }
}

/// `!empty($name) ? $name : 'Unknown'`, where `"0"` is empty too.
fn summary_name(name: &[u8]) -> Vec<u8> {
    if name.is_empty() || name == b"0" {
        b"Unknown".to_vec()
    } else {
        name.to_vec()
    }
}

/// PHP `trim` with its default characters.
pub fn php_trim(value: &[u8]) -> &[u8] {
    let is_space = |b: &u8| matches!(b, b' ' | b'\t' | b'\n' | b'\r' | 0 | 0x0b);
    let start = value.iter().position(|b| !is_space(b)).unwrap_or(value.len());
    let end = value
        .iter()
        .rposition(|b| !is_space(b))
        .map_or(start, |p| p + 1);
    &value[start..end]
}

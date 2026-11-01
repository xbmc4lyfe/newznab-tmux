//! PAR2 packet parsing, and SFV lists. Port of `Par2Info`, `Par2FileList` and `SfvInfo`.
//!
//! `nntmux_formats::par2` reports the packets as evidence. This reader keeps `Par2Info`'s rule
//! that one bad checksum invalidates the whole file, which additional processing depends on.

use std::collections::HashMap;
use std::sync::LazyLock;

use md5::{Digest, Md5};
use nntmux_core::pcre::Pcre;

use super::reader::{Cursor, FileRecord, Parse, Thrown, find, int64, truncated_name};

pub const MARKER: &[u8] = b"PAR2\x00PKT";
pub const PACKET_MAIN: &[u8; 16] = b"PAR 2.0\x00Main\x00\x00\x00\x00";
pub const PACKET_FILEDESC: &[u8; 16] = b"PAR 2.0\x00FileDesc";
pub const PACKET_FILEVER: &[u8; 16] = b"PAR 2.0\x00IFSC\x00\x00\x00\x00";
pub const PACKET_RECOVERY: &[u8; 16] = b"PAR 2.0\x00RecvSlic";
pub const PACKET_CREATOR: &[u8; 16] = b"PAR 2.0\x00Creator\x00";

/// A file id as kept: a 32-character hex string.
const ID_SIZE: usize = 32 + std::mem::size_of::<String>();

#[derive(Clone, Debug, Default)]
pub struct Packet {
    pub offset: i64,
    pub head_length: i64,
    pub head_hash: String,
    pub set_id: String,
    pub kind: [u8; 16],
    pub next_offset: i64,
    pub file_id: String,
    pub file_hash: String,
    pub file_hash_16k: String,
    pub file_length: i64,
    pub file_name: Vec<u8>,
    pub checksum_count: i64,
    pub recoverable_ids: Vec<String>,
    pub other_ids: Vec<String>,
}

#[derive(Clone, Debug)]
pub struct Par2Reader {
    cursor: Cursor,
    marker_position: Option<Option<i64>>,
    pub packets: Vec<Packet>,
    pub error: String,
}

impl Par2Reader {
    pub fn analyze(cursor: Cursor) -> Self {
        let mut reader = Self {
            cursor,
            marker_position: None,
            packets: Vec::new(),
            error: String::new(),
        };
        let Some(start) = reader.find_marker() else {
            reader.error = "Could not find a Packet Marker, not a valid PAR2 file".to_owned();
            return reader;
        };
        if reader.cursor.seek(start).is_err() {
            return reader;
        }
        while reader.cursor.offset < reader.cursor.length {
            if reader.one_packet().is_err() || !reader.error.is_empty() {
                break;
            }
        }
        if reader.error.is_empty() && reader.packets.is_empty() {
            reader.error = "No valid PAR2 packets were found".to_owned();
        }
        reader
    }

    pub fn find_marker(&mut self) -> Option<i64> {
        if let Some(position) = self.marker_position {
            return position;
        }
        let position = find(&self.cursor.marker_buffer().ok()?, MARKER);
        self.marker_position = Some(position);
        position
    }

    fn one_packet(&mut self) -> Parse<()> {
        let mut packet = self.next_packet()?;
        // The checksum covers from the set id to the packet's end, and a failure is fatal.
        let resume = self.cursor.offset;
        self.cursor.seek(packet.offset + 32)?;
        let data = self.cursor.read(packet.head_length - 32)?;
        self.cursor.seek(resume)?;
        if hex::encode(Md5::digest(&data)) != packet.head_hash {
            self.error = format!("Packet failed checksum (offset: {})", self.cursor.offset);
            return Err(Thrown);
        }
        self.process_packet(&mut packet)?;
        let (offset, next) = (packet.offset, packet.next_offset);
        self.cursor
            .budget
            .retain(std::mem::size_of::<Packet>() + packet.file_name.len())?;
        self.packets.push(packet);
        if self.cursor.offset != next {
            self.cursor.seek(next)?;
        }
        if offset == self.cursor.offset {
            self.error = "Parsing seems to be stuck".to_owned();
        }
        Ok(())
    }

    fn next_packet(&mut self) -> Parse<Packet> {
        let offset = self.cursor.offset;
        let head = self.cursor.read(64)?;
        let word = |at: usize| {
            i64::from(u32::from_le_bytes([
                head[at],
                head[at + 1],
                head[at + 2],
                head[at + 3],
            ]))
        };
        let head_length = int64(word(8), word(12));
        let mut kind = [0_u8; 16];
        kind.copy_from_slice(&head[48..64]);
        Ok(Packet {
            offset,
            head_length,
            head_hash: hex::encode(&head[16..32]),
            set_id: hex::encode(&head[32..48]),
            kind,
            next_offset: offset.saturating_add(head_length),
            ..Packet::default()
        })
    }

    fn process_packet(&mut self, packet: &mut Packet) -> Parse<()> {
        if &packet.kind == PACKET_MAIN {
            self.cursor.read(8)?;
            let recoverable = self.cursor.u32()?;
            for _ in 0..recoverable {
                self.cursor.budget.retain(ID_SIZE)?;
                packet
                    .recoverable_ids
                    .push(hex::encode(self.cursor.read(16)?));
            }
            while self.cursor.offset < packet.next_offset {
                self.cursor.budget.retain(ID_SIZE)?;
                packet.other_ids.push(hex::encode(self.cursor.read(16)?));
            }
        } else if &packet.kind == PACKET_FILEDESC {
            packet.file_id = hex::encode(self.cursor.read(16)?);
            packet.file_hash = hex::encode(self.cursor.read(16)?);
            packet.file_hash_16k = hex::encode(self.cursor.read(16)?);
            packet.file_length = self.cursor.u64_pair()?;
            let length = packet.offset + packet.head_length - self.cursor.offset;
            let name = self.cursor.read(length)?;
            packet.file_name = php_rtrim(&name).to_vec();
        } else if &packet.kind == PACKET_FILEVER {
            packet.file_id = hex::encode(self.cursor.read(16)?);
            while self.cursor.offset < packet.next_offset {
                self.cursor.read(20)?;
                packet.checksum_count += 1;
            }
        } else if &packet.kind == PACKET_RECOVERY {
            self.cursor.u32()?;
        } else if &packet.kind == PACKET_CREATOR {
            let length = packet.offset + packet.head_length - self.cursor.offset;
            self.cursor.skip(length)?;
        }
        Ok(())
    }

    /// `getFileList`, in first-seen order of the file ids. A verification packet that comes
    /// before its description leaves a row with only `blocks`.
    pub fn file_list(&self) -> Vec<(String, Option<FileRecord>, i64)> {
        let mut order: Vec<String> = Vec::new();
        let mut rows: HashMap<String, (Option<FileRecord>, i64)> = HashMap::new();
        for packet in &self.packets {
            if &packet.kind == PACKET_FILEDESC {
                if !rows.contains_key(&packet.file_id) {
                    order.push(packet.file_id.clone());
                    rows.insert(packet.file_id.clone(), (Some(describe(packet, true)), 0));
                }
            } else if &packet.kind == PACKET_FILEVER {
                if !rows.contains_key(&packet.file_id) {
                    order.push(packet.file_id.clone());
                    rows.insert(packet.file_id.clone(), (None, 0));
                }
                if let Some(row) = rows.get_mut(&packet.file_id)
                    && row.1 == 0
                {
                    row.1 = packet.checksum_count;
                }
            }
        }
        order
            .into_iter()
            .filter_map(|id| rows.remove(&id).map(|(file, blocks)| (id, file, blocks)))
            .collect()
    }

    /// `Par2FileList::getFiles`: restores descriptions that verification packets preceded. Those
    /// names aren't cut to the maximum length.
    pub fn files(&self) -> Vec<FileRecord> {
        if !self.error.is_empty() {
            return Vec::new();
        }
        let mut rows = self.file_list();
        for packet in &self.packets {
            if &packet.kind != PACKET_FILEDESC {
                continue;
            }
            if let Some(row) = rows.iter_mut().find(|row| row.0 == packet.file_id)
                && row.1.is_none()
            {
                row.1 = Some(describe(packet, false));
            }
        }
        rows.into_iter()
            .filter_map(|(_, file, blocks)| {
                file.map(|mut file| {
                    file.blocks = Some(blocks);
                    file
                })
            })
            .collect()
    }

    /// A declared file identity without a usable description in its recovery set.
    pub fn has_unresolved_descriptors(&self) -> bool {
        if !self.error.is_empty() || self.packets.is_empty() {
            return true;
        }
        let mut required: HashMap<&str, Vec<&str>> = HashMap::new();
        let mut described: HashMap<(&str, &str), bool> = HashMap::new();
        for packet in &self.packets {
            let set = packet.set_id.as_str();
            if &packet.kind == PACKET_MAIN {
                for id in packet.recoverable_ids.iter().chain(&packet.other_ids) {
                    required.entry(set).or_default().push(id);
                }
            } else if &packet.kind == PACKET_FILEVER {
                required.entry(set).or_default().push(&packet.file_id);
            } else if &packet.kind == PACKET_FILEDESC {
                required.entry(set).or_default().push(&packet.file_id);
                described
                    .entry((set, packet.file_id.as_str()))
                    .or_insert_with(|| !php_trim_bytes(&packet.file_name).is_empty());
            }
        }
        let unresolved = required.iter().any(|(set, ids)| {
            ids.iter()
                .any(|id| !described.get(&(*set, *id)).copied().unwrap_or(false))
        });
        unresolved || described.is_empty()
    }
}

fn describe(packet: &Packet, truncate: bool) -> FileRecord {
    let name = if super::reader::php_empty(&packet.file_name) {
        b"Unknown".to_vec()
    } else if truncate {
        truncated_name(&packet.file_name)
    } else {
        packet.file_name.clone()
    };
    FileRecord {
        name,
        size: packet.file_length,
        hash: Some(packet.file_hash.clone()),
        hash_16k: Some(packet.file_hash_16k.clone()),
        blocks: Some(0),
        next_offset: Some(packet.next_offset),
        ..FileRecord::default()
    }
}

fn is_php_space(byte: u8) -> bool {
    matches!(byte, b' ' | b'\t' | b'\n' | b'\r' | 0 | 0x0b)
}

fn php_rtrim(bytes: &[u8]) -> &[u8] {
    let end = bytes
        .iter()
        .rposition(|byte| !is_php_space(*byte))
        .map_or(0, |last| last + 1);
    &bytes[..end]
}

fn php_trim_bytes(bytes: &[u8]) -> &[u8] {
    let bytes = php_rtrim(bytes);
    let start = bytes
        .iter()
        .position(|byte| !is_php_space(*byte))
        .unwrap_or(bytes.len());
    &bytes[start..]
}

static SFV_LINE: LazyLock<Pcre> =
    LazyLock::new(|| Pcre::new(r"/^(.+?)\s+([[:xdigit:]]{2,8})$/").expect("a valid regex"));

/// `SfvInfo`: `name checksum` lines and `;` comments. Its marker is always zero, so any data
/// that passes this reads as an SFV.
pub fn parse_sfv(cursor: &mut Cursor) -> Result<Vec<FileRecord>, String> {
    let Ok(data) = cursor.marker_buffer() else {
        return Err(String::new());
    };
    let mut files = Vec::new();
    for line in split_lines(&data) {
        if line.first() == Some(&b';') {
            continue;
        }
        let Some(found) = SFV_LINE.captures(php_trim_bytes(line)) else {
            return Err("Not a valid SFV file".to_owned());
        };
        files.push(FileRecord {
            name: found.group(1).unwrap_or_default().to_vec(),
            checksum: found
                .group(2)
                .map(|checksum| String::from_utf8_lossy(checksum).into_owned()),
            ..FileRecord::default()
        });
    }
    Ok(files)
}

/// `preg_split('/\R/', ..., PREG_SPLIT_NO_EMPTY)` in byte mode.
fn split_lines(data: &[u8]) -> Vec<&[u8]> {
    let mut lines = Vec::new();
    let mut start = 0;
    let mut position = 0;
    while position < data.len() {
        let width = match data[position] {
            b'\r' if data.get(position + 1) == Some(&b'\n') => 2,
            b'\n' | b'\r' | 0x0b | 0x0c | 0x85 => 1,
            _ => 0,
        };
        if width == 0 {
            position += 1;
            continue;
        }
        if position > start {
            lines.push(&data[start..position]);
        }
        position += width;
        start = position;
    }
    if start < data.len() {
        lines.push(&data[start..]);
    }
    lines
}

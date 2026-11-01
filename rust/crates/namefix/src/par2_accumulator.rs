//! `Par2PacketAccumulator`: a restartable PAR2 metadata stream. Recovery payloads never enter
//! the inventory, and appends are transactional, so a caller may retry the same input after an
//! error.

use base64::Engine as _;
use base64::engine::general_purpose::STANDARD;
use serde_json::Value;

use crate::par2_info::{self as info, Par2Info};
use crate::par2_json::base64_json_len;

/// What `append` and the state codecs refuse with.
#[derive(Debug, Clone, PartialEq, Eq)]
pub enum AccumulatorError {
    /// `InvalidArgumentException`: the bytes or state aren't acceptable PAR2 metadata.
    Invalid(&'static str),
    /// `LengthException`: the state would exceed its byte ceiling.
    Length(&'static str),
}

impl std::fmt::Display for AccumulatorError {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        match self {
            Self::Invalid(message) | Self::Length(message) => f.write_str(message),
        }
    }
}

impl std::error::Error for AccumulatorError {}

type Result<T> = std::result::Result<T, AccumulatorError>;

const KNOWN_TYPES: [&[u8; 16]; 12] = [
    info::MAIN,
    info::FILEDESC,
    info::IFSC,
    info::RECOVERY,
    info::CREATOR,
    info::UNICODE_NAME,
    info::COMMENT_ASCII,
    info::COMMENT_UNICODE,
    info::INPUT_BLOCK,
    info::RECOVERY_VERIFICATION,
    info::PACKED_MAIN,
    info::PACKED_RECOVERY,
];

const SKIPPED_TYPES: [&[u8; 16]; 3] = [info::RECOVERY, info::INPUT_BLOCK, info::PACKED_RECOVERY];

/// The state document's constant text: `{"version":1,"packets":[`, `],"tail":`,
/// `,"skip_remaining":` and `}`. Quotes, escapes and the separators between packets are counted
/// separately.
const STATE_FIXED_BYTES: usize = 24 + 9 + 18 + 1;

/// One descriptor, as `descriptors()` projects it.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct Descriptor {
    pub recovery_set_id: String,
    pub file_id: String,
    pub hash: String,
    pub hash_16k: String,
    pub size_decimal: String,
    pub filename_bytes: Vec<u8>,
    pub packet_checksum: String,
}

/// A file as `filesBySet` reports it.
#[derive(Debug, Clone, Default, PartialEq, Eq)]
pub struct SetFile {
    pub name: Option<Vec<u8>>,
    pub size: Option<u64>,
    pub size_decimal: Option<String>,
    pub hash: Option<String>,
    pub hash_16k: Option<String>,
    pub blocks: usize,
}

/// The exported checkpoint form.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct State {
    pub packets: Vec<String>,
    pub tail: String,
    pub skip_remaining: u64,
}

#[derive(Clone, Debug)]
pub struct PacketAccumulator {
    max_state_bytes: usize,
    packets: Vec<Vec<u8>>,
    /// The encoded size of each retained packet.
    packet_json_bytes: Vec<usize>,
    tail: Vec<u8>,
    skip_remaining: u64,
}

fn packet_length(header: &[u8]) -> Result<u64> {
    let low = u64::from(u32::from_le_bytes(header[8..12].try_into().expect("four")));
    let high = u64::from(u32::from_le_bytes(header[12..16].try_into().expect("four")));
    if high > 0x7FFF_FFFF {
        return Err(AccumulatorError::Invalid(
            "PAR2 packet length exceeds supported integer range",
        ));
    }
    let length = high * 0x1_0000_0000 + low;
    if length < 64 || !length.is_multiple_of(4) {
        return Err(AccumulatorError::Invalid("Invalid PAR2 packet length"));
    }
    Ok(length)
}

fn decode(encoded: &str) -> Result<Vec<u8>> {
    let invalid = AccumulatorError::Invalid("Invalid PAR2 state base64");
    let decoded = STANDARD.decode(encoded).map_err(|_| invalid.clone())?;
    if STANDARD.encode(&decoded) != encoded {
        return Err(invalid);
    }
    Ok(decoded)
}

impl PacketAccumulator {
    pub const DEFAULT_MAX_STATE_BYTES: usize = 262_144;

    pub fn new(max_state_bytes: usize) -> Result<Self> {
        let accumulator = Self {
            max_state_bytes,
            packets: Vec::new(),
            packet_json_bytes: Vec::new(),
            tail: Vec::new(),
            skip_remaining: 0,
        };
        accumulator.assert_state_limit()?;
        Ok(accumulator)
    }

    /// The same inventory under another ceiling, refused if it no longer fits.
    pub fn with_limit(mut self, max_state_bytes: usize) -> Result<Self> {
        self.max_state_bytes = max_state_bytes;
        self.assert_state_limit()?;
        Ok(self)
    }

    pub fn append(&mut self, bytes: &[u8]) -> Result<()> {
        let mut next = self.clone();
        next.consume(bytes)?;
        *self = next;
        Ok(())
    }

    pub fn export_state(&self) -> State {
        State {
            packets: self.packets.iter().map(|p| STANDARD.encode(p)).collect(),
            tail: STANDARD.encode(&self.tail),
            skip_remaining: self.skip_remaining,
        }
    }

    /// The PHP-encoded state document, as stored in a checkpoint.
    pub fn state_json(&self) -> String {
        self.export_state().json()
    }

    pub fn from_state(state: &Value, max_state_bytes: usize) -> Result<Self> {
        let invalid = || AccumulatorError::Invalid("Invalid PAR2 accumulator state");
        let object = state.as_object().ok_or_else(invalid)?;
        let mut keys: Vec<&str> = object.keys().map(String::as_str).collect();
        keys.sort_unstable();
        if keys != ["packets", "skip_remaining", "tail", "version"] {
            return Err(invalid());
        }
        let version = object["version"].as_i64();
        let packets = object["packets"].as_array();
        let tail = object["tail"].as_str();
        let skip = object["skip_remaining"].as_i64();
        let (Some(1), Some(packets), Some(tail), Some(skip)) = (version, packets, tail, skip)
        else {
            return Err(invalid());
        };
        if !(0..=i64::MAX - 64).contains(&skip) || (skip > 0 && !tail.is_empty()) {
            return Err(invalid());
        }
        let mut result = Self::new(max_state_bytes)?;
        let strings: Vec<&str> = packets
            .iter()
            .map(|p| {
                p.as_str()
                    .ok_or(AccumulatorError::Invalid("Invalid PAR2 packet inventory"))
            })
            .collect::<Result<_>>()?;
        let canonical = State {
            packets: strings.iter().map(|s| (*s).to_owned()).collect(),
            tail: tail.to_owned(),
            skip_remaining: skip as u64,
        };
        if canonical.json().len() > max_state_bytes {
            return Err(AccumulatorError::Length(
                "PAR2 metadata state exceeds byte ceiling",
            ));
        }
        for packet in &strings {
            result.append(&decode(packet)?)?;
        }
        result.append(&decode(tail)?)?;
        result.skip_remaining = skip as u64;
        if result.export_state() != canonical {
            return Err(AccumulatorError::Invalid(
                "Noncanonical PAR2 accumulator state",
            ));
        }
        result.assert_state_limit()?;
        Ok(result)
    }

    /// `strlen(json_encode($this->exportState()))`.
    pub fn state_bytes(&self) -> usize {
        let packets: usize = self.packet_json_bytes.iter().sum();
        let separators = self.packets.len().saturating_sub(1);
        let tail = base64_json_len(&STANDARD.encode(&self.tail));
        STATE_FIXED_BYTES + packets + separators + tail + self.skip_remaining.to_string().len()
    }

    pub fn packet_count(&self) -> usize {
        self.packets.len()
    }

    pub fn skip_remaining(&self) -> u64 {
        self.skip_remaining
    }

    pub fn tail_is_empty(&self) -> bool {
        self.tail.is_empty()
    }

    pub fn has_packets(&self) -> bool {
        !self.packets.is_empty()
    }

    pub fn is_complete(&self) -> Result<bool> {
        if !self.tail.is_empty() || self.skip_remaining != 0 || self.packets.is_empty() {
            return Ok(false);
        }
        for (_, parser) in self.parsers_by_set()? {
            if parser.has_unresolved_descriptors() {
                return Ok(false);
            }
        }
        Ok(true)
    }

    /// A complete Main inventory can stop before reading a recognized recovery payload.
    pub fn has_complete_main_inventory(&self) -> Result<bool> {
        if !self.tail.is_empty() || self.packets.is_empty() {
            return Ok(false);
        }
        let main_sets: Vec<String> = self
            .packets
            .iter()
            .filter(|p| p[48..64] == info::MAIN[..])
            .map(|p| hex::encode(&p[32..48]))
            .collect();
        for (set, parser) in self.parsers_by_set()? {
            if !main_sets.contains(&set) || parser.has_unresolved_descriptors() {
                return Ok(false);
            }
        }
        Ok(true)
    }

    /// Each recovery set parsed on its own, so file-ID deduplication can't merge descriptions
    /// belonging to different sets. Sets keep the order of their first packet.
    pub fn parsers_by_set(&self) -> Result<Vec<(String, Par2Info)>> {
        let mut sets: Vec<(String, Vec<u8>)> = Vec::new();
        for packet in &self.packets {
            let set = hex::encode(&packet[32..48]);
            match sets.iter_mut().find(|(known, _)| *known == set) {
                Some((_, bytes)) => bytes.extend_from_slice(packet),
                None => sets.push((set, packet.clone())),
            }
        }
        sets.into_iter()
            .map(|(set, bytes)| Ok((set, parse(&bytes)?)))
            .collect()
    }

    /// Every set's files, with each description's raw name and exact size restored.
    pub fn files_by_set(&self) -> Result<Vec<(String, Vec<SetFile>)>> {
        let mut result: Vec<(String, Vec<(String, SetFile)>)> = Vec::new();
        for (set, parser) in self.parsers_by_set()? {
            let files = parser
                .files()
                .into_iter()
                .map(|file| {
                    (
                        file.file_id,
                        SetFile {
                            name: file.name,
                            size: file.size,
                            size_decimal: file.size_decimal,
                            hash: file.hash,
                            hash_16k: file.hash_16k,
                            blocks: file.blocks,
                        },
                    )
                })
                .collect();
            result.push((set, files));
        }
        for packet in &self.packets {
            if packet[48..64] != info::FILEDESC[..] {
                continue;
            }
            let set = hex::encode(&packet[32..48]);
            let file_id = hex::encode(&packet[64..80]);
            let mut name = packet[120..].to_vec();
            while name.last() == Some(&0) {
                name.pop();
            }
            let size = little_endian_size(&packet[112..120]);
            if let Some((_, files)) = result.iter_mut().find(|(known, _)| *known == set) {
                let at = match files.iter().position(|(id, _)| *id == file_id) {
                    Some(at) => at,
                    None => {
                        files.push((file_id, SetFile::default()));
                        files.len() - 1
                    }
                };
                files[at].1.name = Some(name);
                files[at].1.size_decimal = Some(size.to_string());
            }
        }
        Ok(result
            .into_iter()
            .map(|(set, files)| (set, files.into_iter().map(|(_, file)| file).collect()))
            .collect())
    }

    /// Accepted FileDesc packets without filename or file-ID deduplication.
    pub fn descriptors(&self) -> Vec<Descriptor> {
        self.packets
            .iter()
            .filter(|p| p[48..64] == info::FILEDESC[..])
            .map(|p| Descriptor {
                recovery_set_id: hex::encode(&p[32..48]),
                file_id: hex::encode(&p[64..80]),
                hash: hex::encode(&p[80..96]),
                hash_16k: hex::encode(&p[96..112]),
                size_decimal: little_endian_size(&p[112..120]).to_string(),
                filename_bytes: p[120..].to_vec(),
                packet_checksum: hex::encode(&p[16..32]),
            })
            .collect()
    }

    fn consume(&mut self, bytes: &[u8]) -> Result<()> {
        let mut offset = 0usize;
        let available = bytes.len();
        while offset < available {
            if self.skip_remaining > 0 {
                let skip = self.skip_remaining.min((available - offset) as u64);
                self.skip_remaining -= skip;
                offset += skip as usize;
                continue;
            }
            if self.tail.len() < 64 {
                let take = (64 - self.tail.len()).min(available - offset);
                self.tail.extend_from_slice(&bytes[offset..offset + take]);
                offset += take;
                let prefix = self.tail.len().min(8);
                if self.tail[..prefix] != info::MARKER[..prefix] {
                    return Err(AccumulatorError::Invalid("Invalid PAR2 packet marker"));
                }
                if self.tail.len() >= 16 {
                    packet_length(&self.tail)?;
                }
                if self.tail.len() < 64 {
                    self.assert_state_limit()?;
                    break;
                }
            }
            let length = packet_length(&self.tail)?;
            let kind: [u8; 16] = self.tail[48..64].try_into().expect("sixteen");
            if !KNOWN_TYPES.iter().any(|known| **known == kind) {
                return Err(AccumulatorError::Invalid("Unknown PAR2 packet type"));
            }
            if SKIPPED_TYPES.iter().any(|skipped| **skipped == kind) {
                self.tail.clear();
                self.skip_remaining = length - 64;
                continue;
            }
            if length > self.max_state_bytes as u64 {
                return Err(AccumulatorError::Length(
                    "PAR2 metadata packet exceeds byte ceiling",
                ));
            }
            let take = ((length - self.tail.len() as u64) as usize).min(available - offset);
            self.tail.extend_from_slice(&bytes[offset..offset + take]);
            offset += take;
            if self.tail.len() as u64 == length {
                let packet = std::mem::take(&mut self.tail);
                self.retain(packet)?;
            }
            self.assert_state_limit()?;
        }
        self.assert_state_limit()
    }

    fn retain(&mut self, packet: Vec<u8>) -> Result<()> {
        let kind: [u8; 16] = packet[48..64].try_into().expect("sixteen");
        let body = packet.len() - 64;
        let malformed = (kind == *info::MAIN && (body < 12 || !(body - 12).is_multiple_of(16)))
            || (kind == *info::FILEDESC && body < 56)
            || (kind == *info::IFSC && (body < 16 || !(body - 16).is_multiple_of(20)));
        if malformed {
            return Err(AccumulatorError::Invalid(
                "Malformed PAR2 metadata packet body",
            ));
        }
        if kind == *info::MAIN {
            let count = u64::from(u32::from_le_bytes(packet[72..76].try_into().expect("four")));
            if count > ((body - 12) / 16) as u64 {
                return Err(AccumulatorError::Invalid(
                    "PAR2 Main count exceeds packet identities",
                ));
            }
        }
        parse(&packet)?;
        if ![info::MAIN, info::FILEDESC, info::IFSC]
            .iter()
            .any(|retained| **retained == kind)
        {
            return Ok(());
        }
        let identity = |packet: &[u8]| {
            let mut identity = packet[32..64].to_vec();
            if packet[48..64] != info::MAIN[..] {
                identity.extend_from_slice(&packet[64..80]);
            }
            identity
        };
        let wanted = identity(&packet);
        for existing in &self.packets {
            if *existing == packet {
                return Ok(());
            }
            if identity(existing) == wanted {
                return Err(AccumulatorError::Invalid(
                    "Conflicting PAR2 metadata identity",
                ));
            }
        }
        self.packet_json_bytes
            .push(base64_json_len(&STANDARD.encode(&packet)));
        self.packets.push(packet);
        Ok(())
    }

    fn assert_state_limit(&self) -> Result<()> {
        if self.state_bytes() > self.max_state_bytes {
            return Err(AccumulatorError::Length(
                "PAR2 metadata state exceeds byte ceiling",
            ));
        }
        Ok(())
    }
}

impl State {
    /// `json_encode` of the exported document.
    pub fn json(&self) -> String {
        use crate::par2_json::write_string;
        let mut out = String::from(r#"{"version":1,"packets":["#);
        for (index, packet) in self.packets.iter().enumerate() {
            if index > 0 {
                out.push(',');
            }
            write_string(packet, &mut out);
        }
        out.push_str(r#"],"tail":"#);
        write_string(&self.tail, &mut out);
        out.push_str(r#","skip_remaining":"#);
        out.push_str(&self.skip_remaining.to_string());
        out.push('}');
        out
    }
}

/// `Par2Info` over a recovery set's packets, which must checksum. Any failure is the
/// accumulator's `InvalidArgumentException`.
fn parse(bytes: &[u8]) -> Result<Par2Info> {
    info::parse(bytes)
        .map_err(|_| AccumulatorError::Invalid("Invalid PAR2 metadata checksum or packet"))
}

/// `Par2FileHashEvidence::littleEndianSize`: eight bytes as an unsigned decimal.
pub fn little_endian_size(bytes: &[u8]) -> u64 {
    u64::from_le_bytes(bytes[..8].try_into().expect("eight bytes"))
}

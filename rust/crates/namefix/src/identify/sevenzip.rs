//! Opens a 7z archive from its first bytes and its last ones, without reading what's between.
//!
//! A 7z keeps its index at the very end, usually compressed itself, and says there how the data
//! is packed. If that index or the data is AES encrypted, nothing more can be learned. Otherwise
//! the data starts straight after the 32-byte signature header, and decoding only its beginning
//! is enough to see what's inside.

use std::io::Read;

use lzma_rust2::{Lzma2Reader, LzmaReader};

const SIGNATURE: &[u8] = b"7z\xbc\xaf\x27\x1c";
const SIGNATURE_HEADER: u64 = 32;
const MAX_HEADER_BYTES: u64 = 64 * 1024 * 1024;
const MAX_HEADER_LAYERS: usize = 4;
/// Hostile properties must not be able to ask for more than this per decoder.
const MAX_DICTIONARY_BYTES: u64 = 256 * 1024 * 1024;

const METHOD_COPY: &[u8] = &[0x00];
const METHOD_LZMA: &[u8] = &[0x03, 0x01, 0x01];
const METHOD_LZMA2: &[u8] = &[0x21];
const METHOD_AES: &[u8] = &[0x06, 0xf1, 0x07, 0x01];

#[derive(Clone, Debug, PartialEq, Eq)]
pub enum Opened {
    /// The index or the data is encrypted.
    Encrypted { names: Vec<String> },
    /// The start of the first packed file, decoded as far as the bytes at hand allow.
    Inner {
        method: &'static str,
        names: Vec<String>,
        bytes: Vec<u8>,
    },
    /// A readable index over data packed in a way this can't decode yet.
    Unsupported { method: String, names: Vec<String> },
    /// The index isn't whole in these bytes, so more of the tail is needed.
    NeedTail,
    /// Not a 7z, or its index fails its checksum.
    Damaged,
}

struct Cursor<'a> {
    bytes: &'a [u8],
    at: usize,
}

impl<'a> Cursor<'a> {
    fn new(bytes: &'a [u8]) -> Self {
        Self { bytes, at: 0 }
    }

    fn byte(&mut self) -> Option<u8> {
        let byte = *self.bytes.get(self.at)?;
        self.at += 1;
        Some(byte)
    }

    fn take(&mut self, count: usize) -> Option<&'a [u8]> {
        let end = self.at.checked_add(count)?;
        let taken = self.bytes.get(self.at..end)?;
        self.at = end;
        Some(taken)
    }

    /// 7z's variable-length number: leading one bits in the first byte count the bytes that
    /// follow, little-endian, and what's left of the first byte is the top.
    fn number(&mut self) -> Option<u64> {
        let first = self.byte()?;
        let mut mask = 0x80u8;
        let mut value = 0u64;
        for index in 0..8u32 {
            if first & mask == 0 {
                let top = u64::from(first & (mask.wrapping_sub(1)));
                return Some(value | (top << (8 * index)));
            }
            value |= u64::from(self.byte()?) << (8 * index);
            mask >>= 1;
        }
        Some(value)
    }

    /// A count of things each at least a byte long, which can't honestly exceed what's left.
    fn count(&mut self) -> Option<usize> {
        let count = usize::try_from(self.number()?).ok()?;
        (count <= self.bytes.len() - self.at).then_some(count)
    }

    /// A bit vector with its "all set" shortcut. Bits are read most significant first.
    fn all_or_bits(&mut self, count: usize) -> Option<Vec<bool>> {
        if self.byte()? != 0 {
            return Some(vec![true; count]);
        }
        let packed = self.take(count.div_ceil(8))?;
        Some(
            (0..count)
                .map(|index| packed[index / 8] & (0x80 >> (index % 8)) != 0)
                .collect(),
        )
    }

    fn digests(&mut self, count: usize) -> Option<()> {
        self.all_or_bits_with_crcs(count).map(|_| ())
    }

    /// Which of `count` items have a CRC, stepping over the CRCs themselves.
    fn all_or_bits_with_crcs(&mut self, count: usize) -> Option<Vec<bool>> {
        let defined = self.all_or_bits(count)?;
        self.take(defined.iter().filter(|set| **set).count().checked_mul(4)?)?;
        Some(defined)
    }
}

#[derive(Clone, Debug, Default, PartialEq, Eq)]
struct Coder {
    id: Vec<u8>,
    properties: Vec<u8>,
}

#[derive(Clone, Debug, Default, PartialEq, Eq)]
struct Folder {
    coders: Vec<Coder>,
    unpack_size: u64,
}

#[derive(Clone, Debug, Default, PartialEq, Eq)]
struct Streams {
    pack_position: u64,
    pack_sizes: Vec<u64>,
    folders: Vec<Folder>,
}

fn read_folder(cursor: &mut Cursor<'_>) -> Option<(Folder, usize)> {
    let coder_count = cursor.count()?;
    let mut folder = Folder::default();
    let (mut inputs, mut outputs) = (0usize, 0usize);
    for _ in 0..coder_count {
        let flags = cursor.byte()?;
        let id = cursor.take(usize::from(flags & 0x0f))?.to_vec();
        let (coder_in, coder_out) = if flags & 0x10 != 0 {
            (cursor.count()?, cursor.count()?)
        } else {
            (1, 1)
        };
        inputs = inputs.checked_add(coder_in)?;
        outputs = outputs.checked_add(coder_out)?;
        let properties = if flags & 0x20 != 0 {
            let length = cursor.count()?;
            cursor.take(length)?.to_vec()
        } else {
            Vec::new()
        };
        folder.coders.push(Coder { id, properties });
    }
    let bind_pairs = outputs.checked_sub(1)?;
    for _ in 0..bind_pairs {
        cursor.number()?;
        cursor.number()?;
    }
    let packed = inputs.checked_sub(bind_pairs)?;
    if packed > 1 {
        for _ in 0..packed {
            cursor.number()?;
        }
    }
    Some((folder, outputs))
}

/// `SubStreamsInfo`, which has no length of its own and sits before the file names. It's read
/// only to get past it. A folder's one file shares the folder's CRC when the folder has one.
fn read_substreams(cursor: &mut Cursor<'_>, folders: &[Folder], digests: &[bool]) -> Option<()> {
    let mut files: Vec<usize> = vec![1; folders.len()];
    let mut kind = cursor.byte()?;
    if kind == 0x0d {
        for count in &mut files {
            *count = usize::try_from(cursor.number()?).ok()?;
        }
        kind = cursor.byte()?;
    }
    if kind == 0x09 {
        for count in &files {
            for _ in 1..*count {
                cursor.number()?;
            }
        }
        kind = cursor.byte()?;
    }
    while kind != 0x00 {
        if kind != 0x0a {
            return None;
        }
        let unknown = files
            .iter()
            .enumerate()
            .map(|(index, count)| {
                let shared = *count == 1 && digests.get(index) == Some(&true);
                if shared { 0 } else { *count }
            })
            .sum();
        cursor.digests(unknown)?;
        kind = cursor.byte()?;
    }
    Some(())
}

/// `StreamsInfo`, up to and including its end marker.
fn read_streams(cursor: &mut Cursor<'_>) -> Option<Streams> {
    let mut streams = Streams::default();
    let mut digests = Vec::new();
    loop {
        match cursor.byte()? {
            0x00 => return Some(streams),
            0x06 => {
                streams.pack_position = cursor.number()?;
                let count = cursor.count()?;
                loop {
                    match cursor.byte()? {
                        0x00 => break,
                        0x09 => {
                            for _ in 0..count {
                                streams.pack_sizes.push(cursor.number()?);
                            }
                        }
                        0x0a => cursor.digests(count)?,
                        _ => return None,
                    }
                }
            }
            0x07 => {
                if cursor.byte()? != 0x0b {
                    return None;
                }
                let count = cursor.count()?;
                // Folder data kept in another stream never turns up in practice.
                if cursor.byte()? != 0 {
                    return None;
                }
                let mut outputs = Vec::with_capacity(count);
                for _ in 0..count {
                    let (folder, output_count) = read_folder(cursor)?;
                    streams.folders.push(folder);
                    outputs.push(output_count);
                }
                loop {
                    match cursor.byte()? {
                        0x00 => break,
                        0x0c => {
                            for (folder, output_count) in streams.folders.iter_mut().zip(&outputs) {
                                // The folder's last output is its result in every chain we read.
                                for _ in 0..*output_count {
                                    folder.unpack_size = cursor.number()?;
                                }
                            }
                        }
                        0x0a => digests = cursor.all_or_bits_with_crcs(count)?,
                        _ => return None,
                    }
                }
            }
            0x08 => read_substreams(cursor, &streams.folders, &digests)?,
            _ => return None,
        }
    }
}

fn read_names(cursor: &mut Cursor<'_>) -> Option<Vec<String>> {
    let file_count = cursor.count()?;
    let mut names = Vec::new();
    loop {
        let kind = cursor.byte()?;
        if kind == 0x00 {
            return Some(names);
        }
        let size = cursor.count()?;
        let property = cursor.take(size)?;
        if kind == 0x11 && property.first() == Some(&0) {
            let units: Vec<u16> = property[1..]
                .as_chunks::<2>()
                .0
                .iter()
                .map(|pair| u16::from_le_bytes(*pair))
                .collect();
            names = units
                .split(|unit| *unit == 0)
                .take(file_count)
                .map(String::from_utf16_lossy)
                .filter(|name| !name.is_empty())
                .collect();
        }
    }
}

/// The main index: the streams, and the file names when it can reach them.
fn read_main(header: &[u8]) -> Option<(Streams, Vec<String>)> {
    let mut cursor = Cursor::new(header);
    let mut streams = Streams::default();
    let mut names = Vec::new();
    if cursor.byte()? != 0x01 {
        return None;
    }
    loop {
        match cursor.byte() {
            None | Some(0x00) => return Some((streams, names)),
            Some(0x04) => streams = read_streams(&mut cursor)?,
            Some(0x05) => names = read_names(&mut cursor).unwrap_or_default(),
            // Archive properties and additional streams carry nothing this needs, and can't
            // be skipped without reading them, so stop at what's been found.
            Some(_) => return Some((streams, names)),
        }
    }
}

/// A coder's dictionary, when its properties are sane enough to act on.
fn lzma2_dictionary(properties: &[u8]) -> Option<u32> {
    let bits = u32::from(*properties.first()? & 0x3f);
    if bits > 40 {
        return None;
    }
    if bits == 40 {
        return Some(u32::MAX);
    }
    Some((2 | (bits & 1)) << (bits / 2 + 11))
}

/// Decodes as much as these bytes allow, up to `limit`. A stream cut short isn't an error here.
fn decode(coder: &Coder, packed: &[u8], unpack_size: u64, limit: usize) -> Option<Vec<u8>> {
    fn drain(mut reader: impl Read, limit: usize) -> Vec<u8> {
        let mut out = Vec::new();
        // Small reads, because a read that runs out of input loses what it had decoded.
        let mut chunk = [0u8; 1024];
        while out.len() < limit {
            match reader.read(&mut chunk) {
                Ok(0) | Err(_) => break,
                Ok(read) => out.extend_from_slice(&chunk[..read]),
            }
        }
        out.truncate(limit);
        out
    }
    if coder.id == METHOD_COPY {
        return Some(packed[..packed.len().min(limit)].to_vec());
    }
    if coder.id == METHOD_LZMA {
        let props = *coder.properties.first()?;
        let dict = u32::from_le_bytes(coder.properties.get(1..5)?.try_into().ok()?);
        if u64::from(dict) > MAX_DICTIONARY_BYTES {
            return None;
        }
        let reader = LzmaReader::new_with_props(packed, unpack_size, props, dict, None).ok()?;
        return Some(drain(reader, limit));
    }
    if coder.id == METHOD_LZMA2 {
        let dict = lzma2_dictionary(&coder.properties)?;
        if u64::from(dict) > MAX_DICTIONARY_BYTES {
            return None;
        }
        return Some(drain(Lzma2Reader::new(packed, dict, None), limit));
    }
    None
}

fn method_name(id: &[u8]) -> String {
    match id {
        METHOD_COPY => "copy".to_owned(),
        METHOD_LZMA => "lzma".to_owned(),
        METHOD_LZMA2 => "lzma2".to_owned(),
        METHOD_AES => "aes".to_owned(),
        [0x03, 0x04, 0x01] => "ppmd".to_owned(),
        [0x04, 0x01, 0x08] => "deflate".to_owned(),
        [0x04, 0x01, 0x09] => "deflate64".to_owned(),
        [0x04, 0x02, 0x02] => "bzip2".to_owned(),
        other => format!(
            "0x{}",
            other
                .iter()
                .map(|byte| format!("{byte:02x}"))
                .collect::<String>()
        ),
    }
}

/// Opens an archive. `tail` must end exactly where the archive ends, and `decoded_limit` caps
/// how much of the first packed file comes back.
pub fn open(prefix: &[u8], tail: &[u8], decoded_limit: usize) -> Opened {
    if !prefix.starts_with(SIGNATURE) || prefix.len() < SIGNATURE_HEADER as usize {
        return Opened::Damaged;
    }
    let field = |at: usize| u64::from_le_bytes(prefix[at..at + 8].try_into().expect("eight bytes"));
    let (next_offset, next_size) = (field(12), field(20));
    let next_crc = u32::from_le_bytes(prefix[28..32].try_into().expect("four bytes"));
    if next_size == 0 || next_size > MAX_HEADER_BYTES {
        return Opened::Damaged;
    }
    let Some(index_start) = usize::try_from(next_size)
        .ok()
        .and_then(|size| tail.len().checked_sub(size))
    else {
        return Opened::NeedTail;
    };
    let mut header = tail[index_start..].to_vec();
    if crc32fast::hash(&header) != next_crc {
        // Whole but wrong means trailing bytes or damage. Either way this tail won't do.
        return Opened::Damaged;
    }
    for _ in 0..MAX_HEADER_LAYERS {
        if header.first() != Some(&0x17) {
            break;
        }
        let mut cursor = Cursor::new(&header[1..]);
        let Some(streams) = read_streams(&mut cursor) else {
            return Opened::Damaged;
        };
        let Some(folder) = streams.folders.first() else {
            return Opened::Damaged;
        };
        if folder.coders.iter().any(|coder| coder.id == METHOD_AES) {
            return Opened::Encrypted { names: Vec::new() };
        }
        let [coder] = folder.coders.as_slice() else {
            return Opened::Unsupported {
                method: "index with a filter chain".to_owned(),
                names: Vec::new(),
            };
        };
        let (Some(pack_size), Some(distance)) = (
            streams.pack_sizes.first().copied(),
            next_offset.checked_sub(streams.pack_position),
        ) else {
            return Opened::Damaged;
        };
        let Some(start) = usize::try_from(distance)
            .ok()
            .and_then(|distance| index_start.checked_sub(distance))
        else {
            return Opened::NeedTail;
        };
        let Some(packed) = usize::try_from(pack_size)
            .ok()
            .and_then(|size| tail.get(start..start.checked_add(size)?))
        else {
            return Opened::NeedTail;
        };
        if folder.unpack_size > MAX_HEADER_BYTES {
            return Opened::Damaged;
        }
        let Some(decoded) = decode(
            coder,
            packed,
            folder.unpack_size,
            folder.unpack_size as usize,
        ) else {
            return Opened::Unsupported {
                method: format!("index packed with {}", method_name(&coder.id)),
                names: Vec::new(),
            };
        };
        header = decoded;
    }
    let Some((streams, names)) = read_main(&header) else {
        return Opened::Damaged;
    };
    let Some(folder) = streams.folders.first() else {
        return Opened::Damaged;
    };
    if folder.coders.iter().any(|coder| coder.id == METHOD_AES) {
        return Opened::Encrypted { names };
    }
    let [coder] = folder.coders.as_slice() else {
        let chain: Vec<String> = folder
            .coders
            .iter()
            .map(|coder| method_name(&coder.id))
            .collect();
        return Opened::Unsupported {
            method: chain.join("+"),
            names,
        };
    };
    let method = if coder.id == METHOD_COPY {
        "copy"
    } else if coder.id == METHOD_LZMA {
        "lzma"
    } else if coder.id == METHOD_LZMA2 {
        "lzma2"
    } else {
        return Opened::Unsupported {
            method: method_name(&coder.id),
            names,
        };
    };
    let Some(start) = SIGNATURE_HEADER
        .checked_add(streams.pack_position)
        .and_then(|start| usize::try_from(start).ok())
    else {
        return Opened::Damaged;
    };
    let Some(packed) = prefix.get(start..) else {
        return Opened::Inner {
            method,
            names,
            bytes: Vec::new(),
        };
    };
    // Only this file's packed bytes, because the index can follow them in the same buffer.
    let pack_size = streams
        .pack_sizes
        .first()
        .and_then(|size| usize::try_from(*size).ok())
        .unwrap_or(packed.len());
    let packed = &packed[..packed.len().min(pack_size)];
    let Some(bytes) = decode(coder, packed, folder.unpack_size, decoded_limit) else {
        return Opened::Unsupported {
            method: format!("{method} beyond the memory limit"),
            names,
        };
    };
    Opened::Inner {
        method,
        names,
        bytes,
    }
}

#[cfg(test)]
pub(crate) mod tests {
    use std::io::Cursor as IoCursor;

    use sevenz_rust2::encoder_options::AesEncoderOptions;
    use sevenz_rust2::{ArchiveEntry, ArchiveWriter, EncoderConfiguration, EncoderMethod};

    use super::*;

    const LIMIT: usize = 8 * 1024 * 1024;

    /// Fairly compressible, so a packed stream stays well below it, and long enough to matter.
    pub(crate) fn payload() -> Vec<u8> {
        let mut out = b"\x1a\x45\xdf\xa3 pretend this is a stream ".to_vec();
        for index in 0..200_000u32 {
            out.extend_from_slice(&index.to_le_bytes());
        }
        out
    }

    pub(crate) fn archive(method: EncoderMethod, name: &str, data: &[u8]) -> Vec<u8> {
        let mut writer = ArchiveWriter::new(IoCursor::new(Vec::new())).expect("writer");
        writer.set_content_methods(vec![EncoderConfiguration::new(method)]);
        writer
            .push_archive_entry(ArchiveEntry::new_file(name), Some(data))
            .expect("entry");
        writer.finish().expect("finish").into_inner()
    }

    fn encrypted(header_too: bool) -> Vec<u8> {
        let mut writer = ArchiveWriter::new(IoCursor::new(Vec::new())).expect("writer");
        writer.set_content_methods(vec![
            AesEncoderOptions::new("secret".into()).into(),
            EncoderConfiguration::new(EncoderMethod::LZMA2),
        ]);
        writer.set_encrypt_header(header_too);
        writer
            .push_archive_entry(ArchiveEntry::new_file("a.mkv"), Some(payload().as_slice()))
            .expect("entry");
        writer.finish().expect("finish").into_inner()
    }

    #[test]
    fn opens_each_method_from_both_ends_alone() {
        let data = payload();
        for (method, label) in [
            (EncoderMethod::COPY, "copy"),
            (EncoderMethod::LZMA, "lzma"),
            (EncoderMethod::LZMA2, "lzma2"),
        ] {
            let archive = archive(method, "Some.Film.2021.1080p.WEB-DL.H264-GRP.mkv", &data);
            let Opened::Inner {
                method,
                names,
                bytes,
            } = open(&archive, &archive, LIMIT)
            else {
                panic!("expected {label} to open");
            };
            assert_eq!(method, label);
            assert_eq!(names, ["Some.Film.2021.1080p.WEB-DL.H264-GRP.mkv"]);
            assert_eq!(bytes, data, "{label}");
        }
    }

    #[test]
    fn only_the_start_of_the_data_is_needed() {
        // Barely compressible, like video, so the packed stream is about this long.
        let mut state = 0x2545_f491_4f6c_dd1du64;
        let data: Vec<u8> = (0..3 * 1024 * 1024)
            .map(|_| {
                state ^= state << 13;
                state ^= state >> 7;
                state ^= state << 17;
                (state >> 32) as u8
            })
            .collect();
        for method in [
            EncoderMethod::COPY,
            EncoderMethod::LZMA,
            EncoderMethod::LZMA2,
        ] {
            let archive = archive(method, "a.mkv", &data);
            let Opened::Inner { method, bytes, .. } = open(&archive[..512 * 1024], &archive, LIMIT)
            else {
                panic!("expected it to open");
            };
            assert!(bytes.len() > 64 * 1024, "{method} gave {}", bytes.len());
            assert!(data.starts_with(&bytes), "{method}");
        }
    }

    #[test]
    fn the_limit_caps_what_comes_back() {
        let data = payload();
        let archive = archive(EncoderMethod::LZMA2, "a.mkv", &data);
        let Opened::Inner { bytes, .. } = open(&archive, &archive, 1024) else {
            panic!("expected it to open");
        };
        assert_eq!(bytes, data[..1024]);
    }

    #[test]
    fn encryption_is_recognized_and_not_attempted() {
        for header_too in [false, true] {
            let archive = encrypted(header_too);
            assert!(
                matches!(open(&archive, &archive, LIMIT), Opened::Encrypted { .. }),
                "header_too={header_too}"
            );
        }
    }

    #[test]
    fn a_short_tail_asks_for_more_and_damage_is_refused() {
        let archive = archive(EncoderMethod::LZMA2, "a.mkv", &payload());
        assert_eq!(
            open(&archive, &archive[archive.len() - 4..], LIMIT),
            Opened::NeedTail
        );
        let mut broken = archive.clone();
        let last = broken.len() - 1;
        broken[last] ^= 0xff;
        assert_eq!(open(&archive, &broken, LIMIT), Opened::Damaged);
        assert_eq!(open(b"not a 7z", &archive, LIMIT), Opened::Damaged);
    }

    #[test]
    fn garbage_in_the_index_never_panics() {
        let archive = archive(EncoderMethod::COPY, "a.mkv", &payload());
        let index = u64::from_le_bytes(archive[20..28].try_into().expect("eight")) as usize;
        for at in archive.len() - index..archive.len() {
            let mut broken = archive.clone();
            broken[at] ^= 0xff;
            // Fix the checksum, so the parser has to face the garbage itself.
            let crc = crc32fast::hash(&broken[archive.len() - index..]);
            broken[28..32].copy_from_slice(&crc.to_le_bytes());
            let _ = open(&broken, &broken, LIMIT);
        }
    }

    #[test]
    fn dictionaries_read_as_the_format_defines() {
        assert_eq!(lzma2_dictionary(&[0]), Some(4096));
        assert_eq!(lzma2_dictionary(&[1]), Some(6144));
        assert_eq!(lzma2_dictionary(&[24]), Some(16 * 1024 * 1024));
        assert_eq!(lzma2_dictionary(&[40]), Some(u32::MAX));
        assert_eq!(lzma2_dictionary(&[41]), None);
    }
}

//! Bounded metadata-only 7z header reader. Archive payloads are never decoded.
use serde::Serialize;
use std::io::{Cursor, Read};

const MAX_HEADER: usize = 1024 * 1024;
const MAX_SAMPLE: usize = 16 * 1024 * 1024;
const MAX_ARCHIVE: u64 = 1 << 40;
const MAX_FILES: usize = 4096;
const MAX_OUTPUT_FILES: usize = 1000;
const MAX_FOLDERS: usize = 128;
const MAX_CODERS: usize = 8;
const AES: &[u8] = &[0x06, 0xf1, 0x07, 0x01];

#[derive(Debug, Serialize)]
pub struct Listing {
    pub schema_version: u8,
    pub status: &'static str,
    pub files: Vec<File>,
    pub listing_complete: bool,
    pub encryption: &'static str,
    pub reason: Option<&'static str>,
}
#[derive(Debug, Serialize)]
pub struct File {
    pub name: String,
    pub size: u64,
    pub date: i64,
    pub pass: u8,
    pub compressed: u8,
    #[serde(skip_serializing_if = "Option::is_none")]
    pub crc32: Option<String>,
}
#[derive(Clone, Copy, Debug)]
struct Error(&'static str, &'static str, bool);
type Result<T> = std::result::Result<T, Error>;
fn invalid(reason: &'static str) -> Error {
    Error("invalid", reason, false)
}
fn unsupported(reason: &'static str) -> Error {
    Error("unsupported", reason, false)
}
fn budget(reason: &'static str) -> Error {
    Error("incomplete", reason, false)
}
fn failure(error: Error) -> Listing {
    Listing {
        schema_version: 1,
        status: error.0,
        files: Vec::new(),
        listing_complete: false,
        encryption: if error.2 { "encrypted" } else { "unknown" },
        reason: Some(error.1),
    }
}

pub fn parse(head: &[u8], tail: &[u8], known_release_size: u64) -> Listing {
    match parse_inner(head, tail, known_release_size) {
        Ok(listing) => listing,
        Err(error) => failure(error),
    }
}
fn parse_inner(head: &[u8], tail: &[u8], known_release_size: u64) -> Result<Listing> {
    if head.len() > MAX_SAMPLE || tail.len() > MAX_SAMPLE - head.len() {
        return Err(budget("sample_budget"));
    }
    let start = head
        .get(..32)
        .ok_or(Error("missing_bytes", "start_header_missing", false))?;
    if &start[..6] != b"7z\xbc\xaf'\x1c" {
        return Err(invalid("signature"));
    }
    if start[6] != 0 {
        return Err(unsupported("archive_version"));
    }
    if crc32fast::hash(&start[12..32]) != u32::from_le_bytes(start[8..12].try_into().unwrap()) {
        return Err(invalid("start_header_crc"));
    }
    let offset = u64::from_le_bytes(start[12..20].try_into().unwrap());
    let length = u64::from_le_bytes(start[20..28].try_into().unwrap());
    if length == 0 || length > MAX_HEADER as u64 {
        return Err(budget("next_header_budget"));
    }
    let next_start = 32u64
        .checked_add(offset)
        .ok_or(invalid("archive_offset_overflow"))?;
    let archive_end = next_start
        .checked_add(length)
        .ok_or(invalid("archive_offset_overflow"))?;
    if archive_end > MAX_ARCHIVE {
        return Err(budget("archive_size_budget"));
    }
    if known_release_size != 0 && archive_end > known_release_size {
        return Err(invalid("archive_exceeds_release_size"));
    }
    if head.len() as u64 > archive_end || tail.len() as u64 > archive_end {
        return Err(invalid("sample_outside_archive"));
    }
    let samples = Samples {
        head,
        tail,
        tail_start: archive_end - tail.len() as u64,
    };
    samples.validate_overlap()?;
    let next = samples.read(next_start, length as usize)?;
    if crc32fast::hash(&next) != u32::from_le_bytes(start[28..32].try_into().unwrap()) {
        return Err(invalid("next_header_crc"));
    }
    let mut reader = Reader::new(&next);
    if reader.byte()? != 0x17 {
        return Err(unsupported("plain_next_header"));
    }
    let streams = read_streams(&mut reader)?;
    reader.finish().map_err(|mut error| {
        error.2 |= reader.observed_aes;
        error
    })?;
    if streams.folders.iter().any(Folder::encrypted) {
        return Ok(Listing {
            schema_version: 1,
            status: "encrypted",
            files: vec![],
            listing_complete: false,
            encryption: "encrypted",
            reason: Some("encrypted_header"),
        });
    }
    if streams.folders.len() != 1
        || streams.pack_sizes.len() != 1
        || streams.folders[0].coders.len() != 1
        || streams.substreams.len() != 1
    {
        return Err(unsupported("encoded_header_topology"));
    }
    let folder = &streams.folders[0];
    if folder.size > MAX_HEADER as u64 {
        return Err(budget("decoded_header_budget"));
    }
    if streams.pack_sizes[0] > MAX_HEADER as u64 {
        return Err(budget("packed_header_budget"));
    }
    let pack_start = 32u64
        .checked_add(streams.pack_position)
        .ok_or(invalid("packed_header_offset_overflow"))?;
    if pack_start
        .checked_add(streams.pack_sizes[0])
        .ok_or(invalid("packed_header_offset_overflow"))?
        > next_start
    {
        return Err(invalid("packed_header_outside_data"));
    }
    let packed = samples.read(pack_start, streams.pack_sizes[0] as usize)?;
    if streams.pack_crc[0].is_some_and(|crc| crc != crc32fast::hash(&packed)) {
        return Err(invalid("packed_header_crc"));
    }
    if streams.pack_crc[0].is_none() && folder.crc.is_none() && streams.substreams[0].crc.is_none()
    {
        return Err(unsupported("header_checksum_missing"));
    }
    let decoded = decode_header(&folder.coders[0], &packed, folder.size as usize)?;
    if folder
        .crc
        .is_some_and(|crc| crc != crc32fast::hash(&decoded))
    {
        return Err(invalid("decoded_header_crc"));
    }
    if streams.substreams[0]
        .crc
        .is_some_and(|crc| crc != crc32fast::hash(&decoded))
    {
        return Err(invalid("decoded_substream_crc"));
    }
    read_plain_header(&decoded, pack_start)
}

struct Samples<'a> {
    head: &'a [u8],
    tail: &'a [u8],
    tail_start: u64,
}
impl Samples<'_> {
    fn validate_overlap(&self) -> Result<()> {
        if self.tail_start < self.head.len() as u64 {
            let start = self.tail_start as usize;
            let count = self.tail.len().min(self.head.len() - start);
            if self.head[start..start + count] != self.tail[..count] {
                return Err(invalid("conflicting_sample_overlap"));
            }
        }
        Ok(())
    }
    fn read(&self, start: u64, length: usize) -> Result<Vec<u8>> {
        let end = start
            .checked_add(length as u64)
            .ok_or(invalid("sample_offset_overflow"))?;
        if end <= self.head.len() as u64 {
            return Ok(self.head[start as usize..end as usize].to_vec());
        }
        if start >= self.tail_start {
            let i = (start - self.tail_start) as usize;
            return self
                .tail
                .get(i..i + length)
                .map(|s| s.to_vec())
                .ok_or(Error("missing_bytes", "header_range_missing", false));
        }
        if start < self.head.len() as u64
            && self.tail_start <= self.head.len() as u64
            && end <= self.tail_start + self.tail.len() as u64
        {
            let mut out = self.head[start as usize..].to_vec();
            let tail_index = (self.head.len() as u64 - self.tail_start) as usize;
            out.extend_from_slice(&self.tail[tail_index..tail_index + length - out.len()]);
            return Ok(out);
        }
        Err(Error("missing_bytes", "header_range_missing", false))
    }
}
struct Reader<'a> {
    data: &'a [u8],
    position: usize,
    observed_aes: bool,
}
impl<'a> Reader<'a> {
    fn new(data: &'a [u8]) -> Self {
        Self {
            data,
            position: 0,
            observed_aes: false,
        }
    }
    fn bytes(&mut self, count: usize) -> Result<&'a [u8]> {
        let end = self
            .position
            .checked_add(count)
            .ok_or(invalid("header_offset_overflow"))?;
        let bytes = self
            .data
            .get(self.position..end)
            .ok_or(invalid("truncated_header"))?;
        self.position = end;
        Ok(bytes)
    }
    fn byte(&mut self) -> Result<u8> {
        Ok(self.bytes(1)?[0])
    }
    fn number(&mut self) -> Result<u64> {
        let first = self.byte()?;
        let mut mask = 0x80;
        let mut n = 0;
        for i in 0..8 {
            if first & mask == 0 {
                return Ok(n | (u64::from(first & (mask - 1)) << (8 * i)));
            }
            n |= u64::from(self.byte()?) << (8 * i);
            mask >>= 1;
        }
        Ok(n)
    }
    fn count(&mut self, max: usize, reason: &'static str) -> Result<usize> {
        let n = self.number()?;
        if n > max as u64 {
            return Err(budget(reason));
        }
        Ok(n as usize)
    }
    fn expect(&mut self, expected: u8) -> Result<()> {
        if self.byte()? == expected {
            Ok(())
        } else {
            Err(invalid("unexpected_property"))
        }
    }
    fn finish(&self) -> Result<()> {
        if self.position == self.data.len() {
            Ok(())
        } else {
            Err(invalid("trailing_header_bytes"))
        }
    }
    fn bits(&mut self, count: usize) -> Result<Vec<bool>> {
        let bytes = self.bytes(count.div_ceil(8))?;
        Ok((0..count)
            .map(|i| bytes[i / 8] & (0x80 >> (i % 8)) != 0)
            .collect())
    }
    fn defined(&mut self, count: usize) -> Result<Vec<bool>> {
        match self.byte()? {
            0 => self.bits(count),
            1 => Ok(vec![true; count]),
            _ => Err(invalid("invalid_defined_marker")),
        }
    }
    fn digests(&mut self, count: usize) -> Result<Vec<Option<u32>>> {
        let bits = self.defined(count)?;
        bits.into_iter()
            .map(|defined| {
                if defined {
                    Ok(Some(u32::from_le_bytes(self.bytes(4)?.try_into().unwrap())))
                } else {
                    Ok(None)
                }
            })
            .collect()
    }
}
#[derive(Debug)]
struct Coder {
    method: Vec<u8>,
    properties: Vec<u8>,
}
struct Folder {
    coders: Vec<Coder>,
    final_output: usize,
    size: u64,
    crc: Option<u32>,
    stream_count: usize,
}
impl Folder {
    fn encrypted(&self) -> bool {
        self.coders.iter().any(|c| c.method == AES)
    }
    fn compressed(&self) -> bool {
        self.coders
            .iter()
            .any(|c| c.method != [0] && c.method != AES)
    }
}
struct Substream {
    size: u64,
    crc: Option<u32>,
    folder: usize,
}
#[derive(Default)]
struct Streams {
    pack_position: u64,
    pack_sizes: Vec<u64>,
    pack_crc: Vec<Option<u32>>,
    folders: Vec<Folder>,
    substreams: Vec<Substream>,
}
fn read_folder(r: &mut Reader<'_>) -> Result<Folder> {
    let count = r.count(MAX_CODERS, "coder_count_budget")?;
    if count == 0 {
        return Err(invalid("empty_folder"));
    }
    let mut coders = Vec::with_capacity(count);
    for _ in 0..count {
        let flags = r.byte()?;
        if flags & 0xc0 != 0 {
            return Err(unsupported("alternative_coder"));
        }
        let method_len = usize::from(flags & 15);
        if method_len == 0 || method_len > 8 {
            return Err(unsupported("coder_method_length"));
        }
        let method = r.bytes(method_len)?.to_vec();
        if flags & 0x10 != 0 && (r.number()? != 1 || r.number()? != 1) {
            return Err(unsupported("complex_coder"));
        }
        let properties = if flags & 0x20 != 0 {
            let length = r.count(4096, "coder_properties_budget")?;
            r.bytes(length)?.to_vec()
        } else {
            Vec::new()
        };
        coders.push(Coder { method, properties });
    }
    let mut inputs = vec![false; count];
    let mut outputs = vec![false; count];
    let mut next = vec![None; count];
    for _ in 0..count - 1 {
        let input = r.count(count - 1, "binding_index")?;
        let output = r.count(count - 1, "binding_index")?;
        if inputs[input] || outputs[output] {
            return Err(invalid("duplicate_folder_binding"));
        }
        inputs[input] = true;
        outputs[output] = true;
        next[output] = Some(input);
    }
    let initial = inputs
        .iter()
        .position(|bound| !bound)
        .ok_or(invalid("folder_input_missing"))?;
    let final_output = outputs
        .iter()
        .position(|bound| !bound)
        .ok_or(invalid("folder_output_missing"))?;
    let mut seen = vec![false; count];
    let mut current = initial;
    loop {
        if seen[current] {
            return Err(invalid("cyclic_folder_binding"));
        }
        seen[current] = true;
        match next[current] {
            Some(n) => current = n,
            None => break,
        }
    }
    if current != final_output || seen.iter().any(|seen| !seen) {
        return Err(invalid("disconnected_folder_binding"));
    }
    Ok(Folder {
        coders,
        final_output,
        size: 0,
        crc: None,
        stream_count: 1,
    })
}
fn read_streams(r: &mut Reader<'_>) -> Result<Streams> {
    read_streams_inner(r).map_err(|mut error| {
        error.2 |= r.observed_aes;
        error
    })
}
fn read_streams_inner(r: &mut Reader<'_>) -> Result<Streams> {
    let mut streams = Streams::default();
    let mut id = r.byte()?;
    if id == 6 {
        streams.pack_position = r.number()?;
        let count = r.count(MAX_FOLDERS, "packed_stream_count_budget")?;
        r.expect(9)?;
        for _ in 0..count {
            streams.pack_sizes.push(r.number()?);
        }
        streams.pack_crc = vec![None; count];
        id = r.byte()?;
        if id == 10 {
            streams.pack_crc = r.digests(count)?;
            id = r.byte()?;
        }
        if id != 0 {
            return Err(unsupported("pack_info_property"));
        }
        id = r.byte()?;
    }
    if id == 7 {
        r.expect(11)?;
        let count = r.count(MAX_FOLDERS, "folder_count_budget")?;
        if r.byte()? != 0 {
            return Err(unsupported("external_folder_data"));
        }
        for _ in 0..count {
            let folder = read_folder(r)?;
            r.observed_aes |= folder.encrypted();
            streams.folders.push(folder);
        }
        r.expect(12)?;
        for folder in &mut streams.folders {
            for i in 0..folder.coders.len() {
                let size = r.number()?;
                if i == folder.final_output {
                    folder.size = size;
                }
            }
        }
        id = r.byte()?;
        if id == 10 {
            for (folder, crc) in streams.folders.iter_mut().zip(r.digests(count)?) {
                folder.crc = crc;
            }
            id = r.byte()?;
        }
        if id != 0 {
            return Err(unsupported("unpack_info_property"));
        }
        id = r.byte()?;
    }
    if streams.folders.len() != streams.pack_sizes.len() {
        return Err(invalid("folder_pack_count_mismatch"));
    }
    if id == 8 {
        id = r.byte()?;
        if id == 13 {
            let mut total = 0usize;
            for folder in &mut streams.folders {
                folder.stream_count = r.count(MAX_FILES, "substream_count_budget")?;
                total = total
                    .checked_add(folder.stream_count)
                    .ok_or(invalid("substream_count_overflow"))?;
                if total > MAX_FILES {
                    return Err(budget("substream_count_budget"));
                }
            }
            id = r.byte()?;
        }
        let needs_sizes = streams.folders.iter().any(|f| f.stream_count > 1);
        if id != 9 && needs_sizes {
            return Err(invalid("substream_sizes_missing"));
        }
        for (index, folder) in streams.folders.iter().enumerate() {
            let mut sum = 0u64;
            for n in 0..folder.stream_count {
                let size = if n + 1 == folder.stream_count {
                    folder
                        .size
                        .checked_sub(sum)
                        .ok_or(invalid("substream_size_overflow"))?
                } else {
                    r.number()?
                };
                sum = sum
                    .checked_add(size)
                    .ok_or(invalid("substream_size_overflow"))?;
                streams.substreams.push(Substream {
                    size,
                    crc: if folder.stream_count == 1 {
                        folder.crc
                    } else {
                        None
                    },
                    folder: index,
                });
            }
            if folder.stream_count == 0 && folder.size != 0 {
                return Err(invalid("nonempty_zero_substream_folder"));
            }
        }
        if id == 9 {
            id = r.byte()?;
        }
        if id == 10 {
            let digest_count = streams
                .folders
                .iter()
                .filter(|f| f.stream_count != 1 || f.crc.is_none())
                .map(|f| f.stream_count)
                .sum();
            let mut digests = r.digests(digest_count)?.into_iter();
            for stream in &mut streams.substreams {
                let folder = &streams.folders[stream.folder];
                if folder.stream_count != 1 || folder.crc.is_none() {
                    stream.crc = digests.next().unwrap();
                }
            }
            id = r.byte()?;
        }
        if id != 0 {
            return Err(unsupported("substreams_info_property"));
        }
        id = r.byte()?;
    } else {
        for (index, folder) in streams.folders.iter().enumerate() {
            streams.substreams.push(Substream {
                size: folder.size,
                crc: folder.crc,
                folder: index,
            });
        }
    }
    if id != 0 {
        return Err(unsupported("streams_info_property"));
    }
    Ok(streams)
}
fn decode_header(coder: &Coder, packed: &[u8], expected: usize) -> Result<Vec<u8>> {
    if expected > MAX_HEADER {
        return Err(budget("decoded_header_budget"));
    }
    if packed.len() > MAX_HEADER {
        return Err(budget("packed_header_budget"));
    }
    if coder.method == [0] && coder.properties.is_empty() {
        if packed.len() != expected {
            return Err(invalid("decoded_header_size"));
        }
        return Ok(packed.to_vec());
    }
    let (dictionary, property) = decoder_parameters(coder)?;
    let mut output = Vec::with_capacity(expected);
    let consumed = match coder.method.as_slice() {
        [0x03, 0x01, 0x01] => {
            let mut decoder = lzma_rust2::LzmaReader::new_with_props(
                Cursor::new(packed),
                expected as u64,
                property,
                dictionary,
                None,
            )
            .map_err(|_| invalid("lzma_header_decode"))?;
            decoder
                .by_ref()
                .take(expected as u64 + 1)
                .read_to_end(&mut output)
                .map_err(|_| invalid("lzma_header_decode"))?;
            decoder.inner().position()
        }
        [0x21] => {
            let mut decoder = lzma_rust2::Lzma2Reader::new(Cursor::new(packed), dictionary, None);
            decoder
                .by_ref()
                .take(expected as u64 + 1)
                .read_to_end(&mut output)
                .map_err(|_| invalid("lzma2_header_decode"))?;
            decoder.inner().position()
        }
        _ => return Err(unsupported("header_codec")),
    };
    if output.len() != expected {
        return Err(invalid("decoded_header_size"));
    }
    if consumed != packed.len() as u64 {
        return Err(invalid("packed_header_trailing_bytes"));
    }
    Ok(output)
}
fn decoder_parameters(coder: &Coder) -> Result<(u32, u8)> {
    let (dictionary, property) = match coder.method.as_slice() {
        [0x03, 0x01, 0x01] => {
            if coder.properties.len() != 5 || coder.properties[0] >= 225 {
                return Err(invalid("lzma_properties"));
            }
            let p = coder.properties[0];
            if p % 9 + (p / 9) % 5 > 4 {
                return Err(budget("lzma_literal_context_budget"));
            }
            (
                u32::from_le_bytes(coder.properties[1..5].try_into().unwrap()).max(4096),
                p,
            )
        }
        [0x21] => {
            if coder.properties.len() != 1 || coder.properties[0] > 40 {
                return Err(invalid("lzma2_properties"));
            }
            let p = coder.properties[0];
            if p == 40 {
                return Err(budget("dictionary_budget"));
            }
            ((2 | u32::from(p & 1)) << (u32::from(p / 2) + 11), p)
        }
        _ => return Err(unsupported("header_codec")),
    };
    if dictionary > 8 * 1024 * 1024 {
        return Err(budget("dictionary_budget"));
    }
    Ok((dictionary, property))
}
fn read_plain_header(decoded: &[u8], data_end: u64) -> Result<Listing> {
    let mut r = Reader::new(decoded);
    r.expect(1)?;
    let mut id = r.byte()?;
    if id == 2 {
        // ArchiveProperties has no streams or external references.
        loop {
            let property = r.byte()?;
            if property == 0 {
                break;
            }
            let len = r.count(MAX_HEADER, "archive_property_budget")?;
            r.bytes(len)?;
        }
        id = r.byte()?;
    }
    if id == 3 {
        return Err(unsupported("additional_streams"));
    }
    let streams = if id == 4 {
        let streams = read_streams(&mut r)?;
        id = r.byte().map_err(|mut error| {
            error.2 |= r.observed_aes;
            error
        })?;
        streams
    } else {
        Streams::default()
    };
    let observed_aes = streams.folders.iter().any(Folder::encrypted);
    finish_plain_header(r, id, streams, data_end).map_err(|mut error| {
        error.2 |= observed_aes;
        error
    })
}
fn finish_plain_header(
    mut r: Reader<'_>,
    id: u8,
    streams: Streams,
    data_end: u64,
) -> Result<Listing> {
    for folder in &streams.folders {
        for coder in &folder.coders {
            if coder.method != [0]
                && coder.method != [0x03, 0x01, 0x01]
                && coder.method != [0x21]
                && coder.method != AES
                && coder.method != [0x03, 0x03, 0x01, 0x03]
                && coder.method != [0x03, 0x03, 0x02, 0x05]
                && coder.method != [0x03, 0x03, 0x04, 0x01]
                && coder.method != [0x03, 0x03, 0x05, 0x01]
                && coder.method != [0x03, 0x03, 0x07, 0x01]
                && coder.method != [0x03, 0x03, 0x08, 0x05]
                && coder.method != [0x03]
                && coder.method != [0x04, 0x01, 0x08]
                && coder.method != [0x04, 0x02, 0x02]
            {
                return Err(unsupported("file_coder"));
            }
        }
    }
    let packed_end = streams.pack_sizes.iter().try_fold(
        32u64
            .checked_add(streams.pack_position)
            .ok_or(invalid("payload_offset_overflow"))?,
        |sum, size| {
            sum.checked_add(*size)
                .ok_or(invalid("payload_offset_overflow"))
        },
    )?;
    if packed_end > data_end {
        return Err(invalid("payload_range_outside_archive"));
    }
    if id != 5 {
        return Err(unsupported("files_info_missing"));
    }
    let count = r.count(MAX_FILES, "file_count_budget")?;
    let mut names: Option<Vec<String>> = None;
    let mut empty = vec![false; count];
    let mut empty_files = vec![];
    let mut anti = vec![];
    let mut dates = vec![0; count];
    let mut seen = [false; 256];
    loop {
        let property = r.byte()?;
        if property == 0 {
            break;
        }
        if property != 25 && seen[property as usize] {
            return Err(invalid("duplicate_file_property"));
        }
        seen[property as usize] = true;
        let len = r.count(MAX_HEADER, "file_property_budget")?;
        let mut value = Reader::new(r.bytes(len)?);
        match property {
            14 => {
                empty = value.bits(count)?;
            }
            15 => {
                if !seen[14] {
                    return Err(invalid("empty_file_without_empty_stream"));
                }
                empty_files = value.bits(empty.iter().filter(|b| **b).count())?;
            }
            16 => {
                if !seen[14] {
                    return Err(invalid("anti_without_empty_stream"));
                }
                anti = value.bits(empty.iter().filter(|b| **b).count())?;
            }
            17 => {
                if value.byte()? != 0 {
                    return Err(unsupported("external_file_names"));
                }
                let mut parsed = Vec::with_capacity(count);
                for _ in 0..count {
                    let mut chars = Vec::new();
                    loop {
                        let c = u16::from_le_bytes(value.bytes(2)?.try_into().unwrap());
                        if c == 0 {
                            break;
                        }
                        if chars.len() >= 4096 {
                            return Err(budget("file_name_budget"));
                        }
                        chars.push(c);
                    }
                    parsed.push(
                        String::from_utf16(&chars).map_err(|_| invalid("invalid_utf16_name"))?,
                    );
                }
                names = Some(parsed);
            }
            18..=20 => {
                let defined = value.defined(count)?;
                if value.byte()? != 0 {
                    return Err(unsupported("external_file_times"));
                }
                for (i, defined) in defined.into_iter().enumerate() {
                    if defined {
                        let raw = u64::from_le_bytes(value.bytes(8)?.try_into().unwrap());
                        if property == 20 {
                            dates[i] = (raw / 10_000_000) as i64 - 11_644_473_600;
                        }
                    }
                }
            }
            21 => {
                let defined = value.defined(count)?;
                if value.byte()? != 0 {
                    return Err(unsupported("external_file_attributes"));
                }
                for defined in defined {
                    if defined {
                        value.bytes(4)?;
                    }
                }
            }
            25 => {
                if value.bytes(len)?.iter().any(|b| *b != 0) {
                    return Err(invalid("nonzero_dummy_property"));
                }
            }
            _ => return Err(unsupported("file_property")),
        }
        value.finish()?;
    }
    r.expect(0)?;
    r.finish()?;
    let names = names.ok_or(unsupported("file_names_missing"))?;
    if empty.iter().filter(|b| !**b).count() != streams.substreams.len() {
        return Err(invalid("file_stream_count_mismatch"));
    }
    if anti.iter().any(|b| *b) {
        return Err(unsupported("anti_items"));
    }
    let encrypted = streams.folders.iter().any(Folder::encrypted);
    let truncated = count > MAX_OUTPUT_FILES;
    let mut stream_index = 0;
    let mut empty_index = 0;
    let mut files = Vec::with_capacity(count.min(MAX_OUTPUT_FILES));
    for (i, name) in names.into_iter().enumerate() {
        let (size, crc, pass, compressed, directory) = if empty[i] {
            let directory = !empty_files.get(empty_index).copied().unwrap_or(false);
            empty_index += 1;
            (0, None, 0, 0, directory)
        } else {
            let stream = &streams.substreams[stream_index];
            stream_index += 1;
            let folder = &streams.folders[stream.folder];
            (
                stream.size,
                stream.crc,
                u8::from(folder.encrypted()),
                u8::from(folder.compressed()),
                false,
            )
        };
        if size > i64::MAX as u64 {
            return Err(unsupported("file_size_exceeds_signed_integer"));
        }
        if i < MAX_OUTPUT_FILES && !directory {
            files.push(File {
                name,
                size,
                date: dates[i],
                pass,
                compressed,
                crc32: crc.map(|c| format!("{c:x}")),
            });
        }
    }
    let listing = Listing {
        schema_version: 1,
        status: if truncated { "incomplete" } else { "complete" },
        files,
        listing_complete: !truncated,
        encryption: if encrypted {
            "encrypted"
        } else if truncated {
            "unknown"
        } else {
            "unencrypted"
        },
        reason: if truncated {
            Some("output_file_budget")
        } else {
            None
        },
    };
    if serde_json::to_vec(&listing)
        .map_err(|_| invalid("json_serialization"))?
        .len()
        > 4 * 1024 * 1024
    {
        return Err(budget("json_output_budget"));
    }
    Ok(listing)
}

#[cfg(test)]
mod tests {
    use super::*;
    fn number(mut n: u64) -> Vec<u8> {
        for bytes in 0..8 {
            if n < (1u64 << (7 + 7 * bytes)) {
                let mut out = vec![((0xffu16 << (8 - bytes)) as u8) | ((n >> (bytes * 8)) as u8)];
                for _ in 0..bytes {
                    out.push(n as u8);
                    n >>= 8;
                }
                return out;
            }
        }
        let mut out = vec![0xff];
        out.extend_from_slice(&n.to_le_bytes());
        out
    }
    fn archive(decoded: &[u8]) -> Vec<u8> {
        archive_with_payload(decoded, 0)
    }
    fn archive_with_payload(decoded: &[u8], payload: u8) -> Vec<u8> {
        let mut next = vec![0x17, 0x06, payload, 1, 0x09];
        next.extend(number(decoded.len() as u64));
        next.extend([10, 1]);
        next.extend(crc32fast::hash(decoded).to_le_bytes());
        next.extend([0, 0x07, 0x0b, 1, 0, 1, 1, 0, 0x0c]);
        next.extend(number(decoded.len() as u64));
        next.extend([10, 1]);
        next.extend(crc32fast::hash(decoded).to_le_bytes());
        next.extend([0, 0]);
        let mut start = Vec::new();
        start.extend_from_slice(&(decoded.len() as u64 + u64::from(payload)).to_le_bytes());
        start.extend_from_slice(&(next.len() as u64).to_le_bytes());
        start.extend_from_slice(&crc32fast::hash(&next).to_le_bytes());
        let mut out = b"7z\xbc\xaf'\x1c\0\x04".to_vec();
        out.extend_from_slice(&crc32fast::hash(&start).to_le_bytes());
        out.extend(start);
        out.extend(vec![0; usize::from(payload)]);
        out.extend(decoded);
        out.extend(next);
        out
    }
    fn empty_file_header(count: usize) -> Vec<u8> {
        let mut header = vec![1, 5];
        header.extend(number(count as u64));
        header.push(0x0e);
        let bits = vec![0xff; count.div_ceil(8)];
        header.extend(number(bits.len() as u64));
        header.extend(bits);
        header.push(0x0f);
        let bits = vec![0xff; count.div_ceil(8)];
        header.extend(number(bits.len() as u64));
        header.extend(bits);
        let mut names = vec![0];
        for i in 0..count {
            for c in format!("file{i}.txt").encode_utf16() {
                names.extend(c.to_le_bytes());
            }
            names.extend([0, 0]);
        }
        header.push(0x11);
        header.extend(number(names.len() as u64));
        header.extend(names);
        header.extend([0, 0]);
        header
    }
    #[test]
    fn copy_header_lists_empty_files() {
        let bytes = archive(&empty_file_header(2));
        let listing = parse(&bytes, &bytes, bytes.len() as u64);
        assert_eq!(listing.status, "complete");
        assert_eq!(listing.files.len(), 2);
        assert_eq!(listing.files[1].name, "file1.txt");
        assert_eq!(listing.encryption, "unencrypted");
    }
    #[test]
    fn validates_start_crc() {
        let mut bytes = archive(&empty_file_header(1));
        bytes[12] ^= 1;
        assert_eq!(parse(&bytes, &[], 0).reason, Some("start_header_crc"));
    }
    #[test]
    fn validates_next_crc() {
        let mut bytes = archive(&empty_file_header(1));
        *bytes.last_mut().unwrap() ^= 1;
        assert_eq!(parse(&bytes, &[], 0).reason, Some("next_header_crc"));
    }
    #[test]
    fn known_release_is_an_upper_bound() {
        let bytes = archive(&empty_file_header(1));
        assert_eq!(
            parse(&bytes, &bytes, bytes.len() as u64 - 1).reason,
            Some("archive_exceeds_release_size")
        );
    }
    #[test]
    fn conflicting_overlap_is_invalid() {
        let bytes = archive(&empty_file_header(1));
        let mut tail = bytes.clone();
        tail[40] ^= 1;
        assert_eq!(
            parse(&bytes, &tail, 0).reason,
            Some("conflicting_sample_overlap")
        );
    }
    #[test]
    fn missing_bytes_are_not_zero_filled() {
        let bytes = archive(&empty_file_header(1));
        assert_eq!(parse(&bytes[..32], &[], 0).status, "missing_bytes");
    }
    #[test]
    fn output_truncation_never_asserts_unencrypted() {
        let bytes = archive(&empty_file_header(1001));
        let listing = parse(&bytes, &[], 0);
        assert_eq!(listing.status, "incomplete");
        assert_eq!(listing.files.len(), 1000);
        assert!(!listing.listing_complete);
        assert_eq!(listing.encryption, "unknown");
    }
    #[test]
    fn real_encrypted_header_is_positive_without_payload_decode() {
        let head = include_bytes!("../../../../tests/Fixtures/SevenZip/encrypted-header.head");
        let tail = include_bytes!("../../../../tests/Fixtures/SevenZip/encrypted-header.tail");
        let listing = parse(head, tail, 300336);
        assert_eq!(listing.status, "encrypted", "{listing:?}");
        assert_eq!(listing.encryption, "encrypted");
        assert!(listing.files.is_empty());
    }
    #[test]
    fn plaintext_next_header_is_unsupported() {
        let head = include_bytes!("../../../../tests/Fixtures/SevenZip/plain-header.head");
        let tail = include_bytes!("../../../../tests/Fixtures/SevenZip/plain-header.tail");
        assert_eq!(parse(head, tail, 300282).reason, Some("plain_next_header"));
    }
    #[test]
    fn rejects_archive_offset_overflow() {
        let mut bytes = archive(&empty_file_header(1));
        bytes[12..20].copy_from_slice(&u64::MAX.to_le_bytes());
        let crc = crc32fast::hash(&bytes[12..32]);
        bytes[8..12].copy_from_slice(&crc.to_le_bytes());
        assert_eq!(
            parse(&bytes, &[], 0).reason,
            Some("archive_offset_overflow")
        );
    }
    #[test]
    fn rejects_cyclic_and_disconnected_folders() {
        // Three Copy coders, with a 0 -> 1 -> 0 cycle and disconnected coder 2.
        let data = [3, 1, 0, 1, 0, 1, 0, 1, 0, 0, 1];
        assert_eq!(
            read_folder(&mut Reader::new(&data)).err().unwrap().1,
            "disconnected_folder_binding"
        );
    }
    #[test]
    fn rejects_external_folder_and_unsupported_codec() {
        assert_eq!(
            read_streams(&mut Reader::new(&[7, 11, 1, 1]))
                .err()
                .unwrap()
                .1,
            "external_folder_data"
        );
        assert_eq!(
            decode_header(
                &Coder {
                    method: vec![0x99],
                    properties: vec![]
                },
                &[],
                0
            )
            .err()
            .unwrap()
            .0,
            "unsupported"
        );
    }
    #[test]
    fn malformed_names_after_aes_preserve_positive_encryption() {
        let mut decoded = vec![1, 4, 6, 0, 1, 9, 1, 0, 7, 11, 1, 0, 1, 4];
        decoded.extend(AES);
        decoded.extend([12, 1, 0, 0, 5, 1, 17, 1, 0, 0, 0]);
        let listing = parse(&archive_with_payload(&decoded, 1), &[], 0);
        assert_eq!(listing.status, "invalid");
        assert_eq!(listing.encryption, "encrypted");
        assert!(listing.files.is_empty());
    }

    #[test]
    fn oversized_file_count_is_incomplete_and_unknown() {
        let mut header = vec![1, 5];
        header.extend(number(4097));
        let listing = parse(&archive(&header), &[], 0);
        assert_eq!(listing.status, "incomplete");
        assert_eq!(listing.encryption, "unknown");
        assert!(listing.files.is_empty());
    }
    #[test]
    fn invalid_utf16_names_are_invalid() {
        let decoded = [
            1, 5, 1, 14, 1, 0x80, 15, 1, 0x80, 17, 5, 0, 0, 0xd8, 0, 0, 0, 0,
        ];
        assert_eq!(
            parse(&archive(&decoded), &[], 0).reason,
            Some("invalid_utf16_name")
        );
    }
    #[test]
    fn packed_and_decoded_header_budgets_precede_decode() {
        let mut next = vec![0x17, 6, 0, 1, 9];
        next.extend(number(MAX_HEADER as u64 + 1));
        next.extend([0, 7, 11, 1, 0, 1, 1, 0, 12]);
        next.extend(number(MAX_HEADER as u64 + 1));
        next.extend([0, 0]);
        let mut start = Vec::new();
        start.extend(0u64.to_le_bytes());
        start.extend((next.len() as u64).to_le_bytes());
        start.extend(crc32fast::hash(&next).to_le_bytes());
        let mut bytes = b"7z\xbc\xaf'\x1c\0\x04".to_vec();
        bytes.extend(crc32fast::hash(&start).to_le_bytes());
        bytes.extend(start);
        bytes.extend(next);
        assert_eq!(parse(&bytes, &[], 0).reason, Some("decoded_header_budget"));
    }
    #[test]
    fn bounded_mutations_never_panic_or_return_unverified_files() {
        let mut seed = 0x12345678u64;
        for length in 0..256 {
            let mut bytes = vec![0; length];
            for b in &mut bytes {
                seed ^= seed << 13;
                seed ^= seed >> 7;
                seed ^= seed << 17;
                *b = seed as u8;
            }
            let listing = parse(&bytes, &[], 0);
            assert!(listing.files.is_empty());
        }
        let good = archive(&empty_file_header(3));
        for i in 32..good.len() {
            let mut bad = good.clone();
            bad[i] ^= 0x81;
            let listing = parse(&bad, &[], 0);
            if listing.status == "complete" {
                assert_eq!(listing.files.len(), 3);
            }
        }
    }
    #[test]
    fn encrypted_file_beyond_output_cap_is_still_positive() {
        let count = 1001usize;
        let mut decoded = vec![1, 4, 6, 0, 1, 9, 1, 0, 7, 11, 1, 0, 1, 4];
        decoded.extend(AES);
        decoded.extend([12, 1, 0, 0, 5]);
        decoded.extend(number(count as u64));
        decoded.push(14);
        decoded.extend(number(126));
        decoded.extend([0xff; 125]);
        decoded.push(0);
        decoded.push(15);
        decoded.extend(number(125));
        decoded.extend([0xff; 125]);
        let mut names = vec![0];
        for i in 0..count {
            for c in format!("file{i}.txt").encode_utf16() {
                names.extend(c.to_le_bytes());
            }
            names.extend([0, 0]);
        }
        decoded.push(17);
        decoded.extend(number(names.len() as u64));
        decoded.extend(names);
        decoded.extend([0, 0]);
        let listing = parse(&archive_with_payload(&decoded, 1), &[], 0);
        assert_eq!(listing.status, "incomplete", "{listing:?}");
        assert_eq!(listing.files.len(), 1000);
        assert_eq!(listing.encryption, "encrypted");
        assert!(listing.files.iter().all(|f| f.pass == 0));
    }
    #[test]
    fn validates_packed_header_crc_without_decoding_payload() {
        let mut bytes = archive(&empty_file_header(1));
        bytes[32] ^= 0x40;
        assert_eq!(parse(&bytes, &[], 0).reason, Some("packed_header_crc"));
    }
    #[test]
    fn rejects_overlong_names_and_truncated_names() {
        let mut header = vec![1, 5, 1, 14, 1, 0x80, 15, 1, 0x80, 17];
        let mut name = vec![0];
        for _ in 0..4097 {
            name.extend([b'a', 0]);
        }
        name.extend([0, 0]);
        header.extend(number(name.len() as u64));
        header.extend(name);
        header.extend([0, 0]);
        assert_eq!(
            parse(&archive(&header), &[], 0).reason,
            Some("file_name_budget")
        );
        let header = [1, 5, 1, 14, 1, 0x80, 15, 1, 0x80, 17, 2, 0, 0];
        assert_eq!(
            parse(&archive(&header), &[], 0).reason,
            Some("truncated_header")
        );
    }
    #[test]
    fn ffi_owns_json_and_rejects_invalid_pointers_and_lengths() {
        let bytes = archive(&empty_file_header(1));
        // SAFETY: bytes is live and covers the passed size; zero tail needs no pointer.
        let output = unsafe {
            crate::nntmux_seven_zip_listing_v1_json(
                bytes.as_ptr(),
                bytes.len(),
                std::ptr::null(),
                0,
                bytes.len() as u64,
            )
        };
        assert!(!output.is_null());
        // SAFETY: output is an allocated NUL-terminated string from the API.
        let value: serde_json::Value =
            serde_json::from_slice(unsafe { std::ffi::CStr::from_ptr(output) }.to_bytes()).unwrap();
        assert_eq!(value["schema_version"], 1);
        assert_eq!(value["status"], "complete");
        // SAFETY: this function owns and has not freed output.
        unsafe {
            crate::nntmux_metadata_string_free(output);
        }
        // SAFETY: invalid pointers are rejected before access.
        unsafe {
            assert!(
                crate::nntmux_seven_zip_listing_v1_json(
                    std::ptr::null(),
                    1,
                    std::ptr::null(),
                    0,
                    0
                )
                .is_null()
            );
            assert!(
                crate::nntmux_seven_zip_listing_v1_json(
                    std::ptr::null(),
                    MAX_SAMPLE + 1,
                    std::ptr::null(),
                    0,
                    0
                )
                .is_null()
            );
        }
    }
    fn bare_wrapper(decoded: &[u8]) -> Vec<u8> {
        let mut next = vec![0x17, 6, 0, 1, 9];
        next.extend(number(decoded.len() as u64));
        next.extend([0, 7, 11, 1, 0, 1, 1, 0, 12]);
        next.extend(number(decoded.len() as u64));
        next.push(0);
        next
    }
    fn archive_with_wrapper(decoded: &[u8], next: &[u8], payload: u8) -> Vec<u8> {
        let mut start = Vec::new();
        start.extend((decoded.len() as u64 + u64::from(payload)).to_le_bytes());
        start.extend((next.len() as u64).to_le_bytes());
        start.extend(crc32fast::hash(next).to_le_bytes());
        let mut bytes = b"7z\xbc\xaf'\x1c\0\x04".to_vec();
        bytes.extend(crc32fast::hash(&start).to_le_bytes());
        bytes.extend(start);
        bytes.extend(vec![0; usize::from(payload)]);
        bytes.extend(decoded);
        bytes.extend(next);
        bytes
    }
    #[test]
    fn requires_a_checksum_for_encoded_header_bytes() {
        let decoded = empty_file_header(1);
        let mut next = bare_wrapper(&decoded);
        next.push(0);
        let listing = parse(&archive_with_wrapper(&decoded, &next, 0), &[], 0);
        assert_eq!(listing.reason, Some("header_checksum_missing"));
        assert_eq!(listing.encryption, "unknown");
        assert!(listing.files.is_empty());
    }
    #[test]
    fn verifies_sole_substream_crc_even_when_folder_crc_is_absent() {
        let decoded = empty_file_header(1);
        let mut next = bare_wrapper(&decoded);
        next.extend([8, 10, 1]);
        next.extend((crc32fast::hash(&decoded) ^ 1).to_le_bytes());
        next.extend([0, 0]);
        let listing = parse(&archive_with_wrapper(&decoded, &next, 0), &[], 0);
        assert_eq!(listing.reason, Some("decoded_substream_crc"));
        let crc_position = next.len() - 6;
        next[crc_position..crc_position + 4]
            .copy_from_slice(&crc32fast::hash(&decoded).to_le_bytes());
        assert_eq!(
            parse(&archive_with_wrapper(&decoded, &next, 0), &[], 0).status,
            "complete"
        );
    }
    #[test]
    fn eof_immediately_after_aes_streams_preserves_encryption() {
        let mut decoded = vec![1, 4, 6, 0, 1, 9, 1, 0, 7, 11, 1, 0, 1, 4];
        decoded.extend(AES);
        decoded.extend([12, 1, 0, 0]);
        let listing = parse(&archive_with_payload(&decoded, 1), &[], 0);
        assert_eq!(listing.reason, Some("truncated_header"));
        assert_eq!(listing.encryption, "encrypted");
    }
    #[test]
    fn decoder_dictionary_and_literal_context_are_bounded() {
        let mut properties = vec![0x5d];
        properties.extend((8u32 * 1024 * 1024).to_le_bytes());
        let mut coder = Coder {
            method: vec![3, 1, 1],
            properties,
        };
        assert_eq!(decoder_parameters(&coder).unwrap().0, 8 * 1024 * 1024);
        coder.properties[1..].copy_from_slice(&(8u32 * 1024 * 1024 + 1).to_le_bytes());
        assert_eq!(
            decoder_parameters(&coder).err().unwrap().1,
            "dictionary_budget"
        );
        coder.properties[0] = 8;
        assert_eq!(
            decoder_parameters(&coder).err().unwrap().1,
            "lzma_literal_context_budget"
        );
        for p in [23, 39, 40] {
            assert_eq!(
                decoder_parameters(&Coder {
                    method: vec![0x21],
                    properties: vec![p]
                })
                .err()
                .unwrap()
                .1,
                "dictionary_budget"
            );
        }
        assert_eq!(
            decoder_parameters(&Coder {
                method: vec![0x21],
                properties: vec![22]
            })
            .unwrap()
            .0,
            8 * 1024 * 1024
        );
        assert_eq!(
            decoder_parameters(&Coder {
                method: vec![0x21],
                properties: vec![41]
            })
            .err()
            .unwrap()
            .1,
            "lzma2_properties"
        );
    }
    #[test]
    fn combined_sample_budget_is_enforced() {
        let bytes = archive(&empty_file_header(1));
        let tail = vec![0; MAX_SAMPLE];
        assert_eq!(parse(&bytes[..32], &tail, 0).reason, Some("sample_budget"));
        // SAFETY: lengths fail the combined budget before any pointer access.
        unsafe {
            assert!(
                crate::nntmux_seven_zip_listing_v1_json(
                    std::ptr::null(),
                    32,
                    std::ptr::null(),
                    MAX_SAMPLE,
                    0
                )
                .is_null()
            );
        }
    }
    #[test]
    fn real_lzma1_and_lzma2_headers_match_verified_metadata() {
        for (head, tail, size) in [
            (
                &include_bytes!("../../../../tests/Fixtures/SevenZip/basic.head")[..],
                &include_bytes!("../../../../tests/Fixtures/SevenZip/basic.tail")[..],
                300268,
            ),
            (
                &include_bytes!("../../../../tests/Fixtures/SevenZip/lzma2-header.head")[..],
                &include_bytes!("../../../../tests/Fixtures/SevenZip/lzma2-header.tail")[..],
                300269,
            ),
        ] {
            let listing = parse(head, tail, size);
            assert_eq!(listing.status, "complete", "{listing:?}");
            assert_eq!(listing.encryption, "unencrypted");
            assert_eq!(listing.files.len(), 3);
            let expected = [
                ("Real.Movie.2026.mkv", 300000, "69921800"),
                ("naïve notes.txt", 14, "c0e1e2ba"),
                ("real.nfo", 12, "4c5eb640"),
            ];
            for (file, (name, size, crc)) in listing.files.iter().zip(expected) {
                assert_eq!(file.name, name);
                assert_eq!(file.size, size);
                assert_eq!(file.date, 946684800);
                assert_eq!(file.crc32.as_deref(), Some(crc));
                assert_eq!(file.pass, 0);
                assert_eq!(file.compressed, 1);
            }
        }
    }
    #[test]
    fn real_encrypted_files_are_listed_without_payload_decryption() {
        let listing = parse(
            include_bytes!("../../../../tests/Fixtures/SevenZip/encrypted-files.head"),
            include_bytes!("../../../../tests/Fixtures/SevenZip/encrypted-files.tail"),
            300297,
        );
        assert_eq!(listing.status, "complete", "{listing:?}");
        assert_eq!(listing.encryption, "encrypted");
        assert_eq!(listing.files.len(), 3);
        assert!(listing.files.iter().all(|f| f.pass == 1));
        assert_eq!(listing.files[0].size, 300000);
    }
    #[test]
    fn real_compact_many_and_late_encryption_archives_are_bounded() {
        let compact = parse(
            include_bytes!("../../../../tests/Fixtures/SevenZip/compact.head"),
            &[],
            233,
        );
        assert_eq!(compact.status, "complete", "{compact:?}");
        assert_eq!(compact.files.len(), 2);
        assert_eq!(compact.files[0].name, "Compact.Movie.mkv");
        assert_eq!(compact.files[0].size, 390);
        assert_eq!(compact.files[0].crc32.as_deref(), Some("32624263"));
        let many = parse(
            include_bytes!("../../../../tests/Fixtures/SevenZip/many.head"),
            &[],
            893,
        );
        assert_eq!(many.status, "complete", "{many:?}");
        assert_eq!(many.files.len(), 100);
        assert_eq!(many.files[99].name, "File-099.txt");
        assert_eq!(many.encryption, "unencrypted");
        let capped = parse(
            include_bytes!("../../../../tests/Fixtures/SevenZip/capped-encrypted.head"),
            &[],
            854,
        );
        assert_eq!(capped.status, "incomplete", "{capped:?}");
        assert_eq!(capped.files.len(), 1000);
        assert_eq!(capped.encryption, "encrypted");
        assert!(capped.files.iter().all(|f| f.pass == 0));
    }

    #[test]
    fn codecs_reject_truncated_streams_and_bound_output_before_allocation() {
        let bytes = include_bytes!("../../../../tests/Fixtures/SevenZip/compact.head");
        let next_start = 32 + u64::from_le_bytes(bytes[12..20].try_into().unwrap()) as usize;
        let mut r = Reader::new(&bytes[next_start..]);
        r.expect(0x17).unwrap();
        let streams = read_streams(&mut r).unwrap();
        let folder = &streams.folders[0];
        let packed_start = 32 + streams.pack_position as usize;
        let packed = &bytes[packed_start..packed_start + streams.pack_sizes[0] as usize];
        assert!(decode_header(&folder.coders[0], packed, folder.size as usize).is_ok());
        for n in 0..5 {
            assert!(decode_header(&folder.coders[0], &packed[..n], folder.size as usize).is_err());
        }
        let missing = parse(
            &bytes[..packed_start + packed.len() - 1],
            &bytes[next_start..],
            bytes.len() as u64,
        );
        assert_eq!(missing.status, "missing_bytes");
        assert!(missing.files.is_empty());
        let mut corrupted = bytes.to_vec();
        corrupted[packed_start] ^= 0x80;
        let listing = parse(&corrupted, &[], bytes.len() as u64);
        assert_eq!(listing.status, "invalid");
        assert!(listing.files.is_empty());
        assert_eq!(
            decode_header(&folder.coders[0], packed, MAX_HEADER + 1)
                .err()
                .unwrap()
                .1,
            "decoded_header_budget"
        );
        assert_eq!(
            decode_header(&folder.coders[0], &vec![0; MAX_HEADER + 1], 1)
                .err()
                .unwrap()
                .1,
            "packed_header_budget"
        );
    }
    #[test]
    fn lzma2_decoded_size_and_trailing_bytes_are_checked() {
        let coder = Coder {
            method: vec![0x21],
            properties: vec![0],
        };
        // A raw uncompressed LZMA2 chunk holding four bytes, followed by EOS.
        let packed = [1, 0, 3, b'a', b'b', b'c', b'd', 0];
        assert_eq!(decode_header(&coder, &packed, 4).unwrap(), b"abcd");
        assert_eq!(
            decode_header(&coder, &packed, 3).err().unwrap().1,
            "decoded_header_size"
        );
        assert_eq!(
            decode_header(&coder, &packed, 5).err().unwrap().1,
            "decoded_header_size"
        );
        assert_eq!(
            decode_header(&coder, &packed[..7], 4).err().unwrap().1,
            "lzma2_header_decode"
        );
        let mut trailing = packed.to_vec();
        trailing.push(0);
        assert_eq!(
            decode_header(&coder, &trailing, 4).err().unwrap().1,
            "packed_header_trailing_bytes"
        );
    }
    #[test]
    fn file_crc_matches_rarinfo_unpadded_hex_convention() {
        let mut decoded = vec![1, 4, 6, 0, 1, 9, 1, 0, 7, 11, 1, 0, 1, 1, 0, 12, 1, 10, 1];
        decoded.extend(0x0012_3456u32.to_le_bytes());
        decoded.extend([0, 0, 5, 1, 17]);
        let mut name = vec![0];
        for c in "one.bin".encode_utf16() {
            name.extend(c.to_le_bytes());
        }
        name.extend([0, 0]);
        decoded.extend(number(name.len() as u64));
        decoded.extend(name);
        decoded.extend([0, 0]);
        let listing = parse(&archive_with_payload(&decoded, 1), &[], 0);
        assert_eq!(listing.status, "complete", "{listing:?}");
        assert_eq!(listing.files[0].crc32.as_deref(), Some("123456"));
    }
}

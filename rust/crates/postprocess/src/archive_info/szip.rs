//! 7z header parsing and file lists. Port of `SzipInfo`.
//!
//! It keeps a bug the rest of the pipeline is built around: `readBooleans` without its
//! all-defined flag returns no flags and reads no bytes, so empty-stream markers and undefined
//! digests derail the parse, and the caller falls back to the 7-Zip client.

use super::reader::{
    Cursor, FileRecord, Parse, Thrown, find, int64, truncated_name, utf16le_to_utf8,
    windows_to_unix,
};

const PROPERTY_END: i64 = 0x00;
const PROPERTY_HEADER: i64 = 0x01;
const PROPERTY_ARCHIVE_PROPERTIES: i64 = 0x02;
const PROPERTY_ADDITIONAL_STREAMS_INFO: i64 = 0x03;
const PROPERTY_MAIN_STREAMS_INFO: i64 = 0x04;
const PROPERTY_FILES_INFO: i64 = 0x05;
const PROPERTY_PACK_INFO: i64 = 0x06;
const PROPERTY_UNPACK_INFO: i64 = 0x07;
const PROPERTY_SUBSTREAMS_INFO: i64 = 0x08;
const PROPERTY_SIZE: i64 = 0x09;
const PROPERTY_CRC: i64 = 0x0a;
const PROPERTY_FOLDER: i64 = 0x0b;
const PROPERTY_CODERS_UNPACK_SIZE: i64 = 0x0c;
const PROPERTY_NUM_UNPACK_STREAM: i64 = 0x0d;
const PROPERTY_EMPTY_STREAM: i64 = 0x0e;
const PROPERTY_EMPTY_FILE: i64 = 0x0f;
const PROPERTY_ANTI: i64 = 0x10;
const PROPERTY_NAME: i64 = 0x11;
const PROPERTY_CREATION_TIME: i64 = 0x12;
const PROPERTY_LAST_ACCESS_TIME: i64 = 0x13;
const PROPERTY_LAST_WRITE_TIME: i64 = 0x14;
const PROPERTY_ATTRIBUTES: i64 = 0x15;
const PROPERTY_START_POSITION: i64 = 0x18;
pub const PROPERTY_ENCODED_HEADER: i64 = 0x17;
const START_HEADER: i64 = 0x100;
const METHOD_COPY: &str = "00";
const METHOD_7Z_AES: &str = "06f10701";
pub const MARKER_SIGNATURE: &[u8] = b"7z\xbc\xaf\x27\x1c";
const START_HEADER_SIZE: i64 = 26;

/// A packed block's absolute range, unless all of it is missing from the data.
type PackedRange = Option<(i64, i64)>;

#[derive(Clone, Debug, Default)]
struct Folder {
    is_encrypted: i64,
    is_compressed: i64,
    total_out_streams: i64,
    bind_pairs: Vec<(i64, i64)>,
    unpack_sizes: Vec<i64>,
    digest_defined: bool,
    unpack_crc: i64,
}

#[derive(Clone, Debug, Default)]
struct Substreams {
    num_unpack_streams: Vec<i64>,
    unpack_sizes: Vec<i64>,
    digests_defined: Vec<i64>,
    digests: Vec<i64>,
}

#[derive(Clone, Debug)]
struct SevenZipFile {
    has_stream: bool,
    name: Option<Vec<u8>>,
    utime: Option<i64>,
    is_dir: bool,
}

/// A parsed header. Pack fields are public for the encoded-header listing.
#[derive(Clone, Debug, Default)]
pub struct Header {
    pub offset: i64,
    pub kind: i64,
    next_offset: i64,
    data_offset: i64,
    pub pack_offset: Option<i64>,
    pub pack_sizes: Option<Vec<i64>>,
    num_streams: Option<i64>,
    num_folders: Option<i64>,
    folders: Option<Vec<Folder>>,
    substreams: Option<Substreams>,
    files: Vec<SevenZipFile>,
    empty_streams: Vec<i64>,
    empty_files: Vec<i64>,
}

#[derive(Clone, Debug)]
pub struct SzipReader {
    cursor: Cursor,
    marker_position: Option<Option<i64>>,
    pub headers: Vec<Header>,
    pub error: String,
    pub is_encrypted: bool,
    pub has_encoded_header: bool,
}

/// Marker found with too few bytes after it for a Start header. The PHP hit a warning outside
/// any `try` here, which aborted the whole release.
#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub struct StartHeaderTruncated;

impl SzipReader {
    pub fn analyze(cursor: Cursor, fragment: bool) -> Result<Self, StartHeaderTruncated> {
        let mut reader = Self {
            cursor,
            marker_position: None,
            headers: Vec::new(),
            error: String::new(),
            is_encrypted: false,
            has_encoded_header: false,
        };
        let start = reader.find_marker();
        match start {
            None if !fragment => {
                reader.error = "Could not find marker signature, not a valid 7z file".to_owned();
                return Ok(reader);
            }
            Some(position) => {
                let after = position + i64::try_from(MARKER_SIGNATURE.len()).unwrap_or(6);
                if reader.cursor.seek(after).is_err() {
                    return Err(StartHeaderTruncated);
                }
                let header = reader.read_start_header().ok_or(StartHeaderTruncated)?;
                let next = header.next_offset.min(reader.cursor.length);
                reader.headers.push(header);
                if reader.cursor.seek(next).is_err() {
                    return Err(StartHeaderTruncated);
                }
            }
            None => {
                let Some(found) = reader.find_header() else {
                    reader.error = "Could not find a valid 7z header".to_owned();
                    return Ok(reader);
                };
                if reader.cursor.seek(found).is_err() {
                    return Ok(reader);
                }
            }
        }
        while reader.cursor.offset < reader.cursor.length {
            match reader.next_loop_header() {
                Ok(Some(())) => {}
                Ok(None) | Err(Thrown) => break,
            }
        }
        if reader.headers.is_empty() {
            reader.error = "No valid 7z headers were found".to_owned();
        }
        Ok(reader)
    }

    pub fn cursor(&self) -> &Cursor {
        &self.cursor
    }

    pub fn find_marker(&mut self) -> Option<i64> {
        if let Some(position) = self.marker_position {
            return position;
        }
        let position = find(&self.cursor.marker_buffer().ok()?, MARKER_SIGNATURE);
        self.marker_position = Some(position);
        position
    }

    fn next_loop_header(&mut self) -> Parse<Option<()>> {
        let Some(header) = self.read_next_header()? else {
            return Ok(None);
        };
        let (offset, next) = (header.offset, header.next_offset);
        self.headers.push(header);
        if self.cursor.offset != next {
            self.cursor.seek(next)?;
        }
        if offset == self.cursor.offset {
            self.error = "Parsing seems to be stuck".to_owned();
            return Ok(None);
        }
        Ok(Some(()))
    }

    /// A fragment with no signature: looks for a Header or Encoded Header that makes sense.
    fn find_header(&mut self) -> Option<i64> {
        let start = self.cursor.offset;
        let buffer = self.cursor.marker_buffer().ok()?;
        let mut positions: Vec<(usize, bool)> = Vec::new();
        for (position, pair) in buffer.windows(2).enumerate() {
            let pair = [i64::from(pair[0]), i64::from(pair[1])];
            if pair == [PROPERTY_HEADER, PROPERTY_MAIN_STREAMS_INFO] {
                positions.push((position, false));
            } else if pair == [PROPERTY_ENCODED_HEADER, PROPERTY_PACK_INFO] {
                positions.push((position, true));
            }
        }
        for (position, encoded) in positions {
            let offset = i64::try_from(position).ok()? + start;
            if self
                .cursor
                .seek(if encoded { offset } else { offset + 1 })
                .is_err()
            {
                continue;
            }
            if let Ok(Some(header)) = self.read_next_header()
                && sane_streams_info(&header)
            {
                self.marker_position = Some(Some(offset));
                return Some(offset);
            }
        }
        None
    }

    fn read_start_header(&mut self) -> Option<Header> {
        let offset = self.cursor.offset;
        let head = self.cursor.read(START_HEADER_SIZE).ok()?;
        let word = |at: usize| {
            i64::from(u32::from_le_bytes([
                head[at],
                head[at + 1],
                head[at + 2],
                head[at + 3],
            ]))
        };
        let next_head_offset = int64(word(6), word(10));
        Some(Header {
            offset,
            kind: START_HEADER,
            next_offset: self.cursor.offset.saturating_add(next_head_offset),
            data_offset: self.cursor.offset,
            ..Header::default()
        })
    }

    /// `Ok(None)` is the PHP's `false`, with `error` possibly set.
    fn read_next_header(&mut self) -> Parse<Option<Header>> {
        let mut header = Header {
            offset: self.cursor.offset,
            kind: self.cursor.byte()?,
            next_offset: self.cursor.length,
            ..Header::default()
        };
        match header.kind {
            PROPERTY_HEADER | PROPERTY_END => {
                header.next_offset = header.offset + 1;
                Ok(Some(header))
            }
            PROPERTY_ARCHIVE_PROPERTIES => {
                self.error = format!(
                    "Archive properties not implemented, at: {}",
                    self.cursor.offset
                );
                Ok(None)
            }
            PROPERTY_ADDITIONAL_STREAMS_INFO
            | PROPERTY_MAIN_STREAMS_INFO
            | PROPERTY_ENCODED_HEADER => {
                if self.process_streams_info(&mut header)? {
                    Ok(Some(header))
                } else {
                    Ok(None)
                }
            }
            PROPERTY_FILES_INFO => {
                if self.process_files_info(&mut header)? {
                    Ok(Some(header))
                } else {
                    Ok(None)
                }
            }
            _ => Ok(Some(header)),
        }
    }

    fn process_streams_info(&mut self, header: &mut Header) -> Parse<bool> {
        let mut id = self.cursor.byte()?;
        if id == PROPERTY_PACK_INFO {
            if !self.process_pack_info(header)? {
                return Ok(false);
            }
            id = self.cursor.byte()?;
        }
        if id == PROPERTY_UNPACK_INFO {
            if !self.process_unpack_info(header)? {
                return Ok(false);
            }
            id = self.cursor.byte()?;
        }
        if id == PROPERTY_SUBSTREAMS_INFO {
            if !self.process_substreams_info(header)? {
                return Ok(false);
            }
            id = self.cursor.byte()?;
        }
        if !self.check_is_end(id) {
            return Ok(false);
        }
        self.has_encoded_header = header.kind == PROPERTY_ENCODED_HEADER;
        header.next_offset = self.cursor.offset;
        Ok(true)
    }

    fn process_pack_info(&mut self, header: &mut Header) -> Parse<bool> {
        header.pack_offset = Some(self.read_number()?);
        let streams = self.read_number()?;
        header.num_streams = Some(streams);
        let mut id = self.cursor.byte()?;
        if id == PROPERTY_SIZE {
            let mut sizes = Vec::new();
            for _ in 0..streams {
                sizes.push(self.read_number()?);
            }
            header.pack_sizes = Some(sizes);
            id = self.cursor.byte()?;
            if id == PROPERTY_CRC {
                let (defined, _) = self.read_digests(streams)?;
                // Reading an undefined flag was a warning.
                if i64::try_from(defined.len()).unwrap_or(0) < streams {
                    return Err(Thrown);
                }
                id = self.cursor.byte()?;
            }
        }
        Ok(self.check_is_end(id))
    }

    fn process_unpack_info(&mut self, header: &mut Header) -> Parse<bool> {
        let id = self.cursor.byte()?;
        if id != PROPERTY_FOLDER {
            self.error = format!(
                "Expecting PROPERTY_FOLDER but found: {id} at: {}",
                self.cursor.offset - 1
            );
            return Ok(false);
        }
        let count = self.read_number()?;
        header.num_folders = Some(count);
        if !self.check_external()? {
            return Ok(false);
        }
        let mut folders = self.process_folders(header.kind, count)?;
        let id = self.cursor.byte()?;
        if id != PROPERTY_CODERS_UNPACK_SIZE {
            self.error = format!(
                "Expecting PROPERTY_CODERS_UNPACK_SIZE but found: {id} at: {}",
                self.cursor.offset - 1
            );
            header.folders = Some(folders);
            return Ok(false);
        }
        for folder in &mut folders {
            for _ in 0..folder.total_out_streams {
                folder.unpack_sizes.push(self.read_number()?);
            }
        }
        let mut id = self.cursor.byte()?;
        if id == PROPERTY_CRC {
            let (defined, crcs) = self.read_digests(count)?;
            for (index, folder) in folders.iter_mut().enumerate() {
                folder.digest_defined = *defined.get(index).ok_or(Thrown)? != 0;
                folder.unpack_crc = crcs.get(index).copied().unwrap_or(0);
            }
            id = self.cursor.byte()?;
        }
        header.folders = Some(folders);
        Ok(self.check_is_end(id))
    }

    fn process_folders(&mut self, header_kind: i64, count: i64) -> Parse<Vec<Folder>> {
        let mut folders = Vec::new();
        for _ in 0..count {
            let mut folder = Folder::default();
            let coders = self.read_number()?;
            let (mut total_in, mut total_out) = (0_i64, 0_i64);
            for _ in 0..coders {
                let flags = self.cursor.byte()?;
                let method = hex::encode(self.cursor.read(flags & 0x0f)?);
                let complex = flags & 0x10 != 0;
                let num_in = if complex { self.read_number()? } else { 1 };
                let num_out = if complex { self.read_number()? } else { 1 };
                total_in = total_in.saturating_add(num_in);
                total_out = total_out.saturating_add(num_out);
                if flags & 0x20 != 0 {
                    let size = self.read_number()?;
                    self.cursor.read(size)?;
                }
                if method == METHOD_7Z_AES {
                    folder.is_encrypted = 1;
                    if header_kind == PROPERTY_ENCODED_HEADER {
                        self.is_encrypted = true;
                    }
                } else if method != METHOD_COPY {
                    folder.is_compressed = 1;
                }
            }
            folder.total_out_streams = total_out;
            let bind_pair_count = total_out - 1;
            for _ in 0..bind_pair_count.max(0) {
                let input = self.read_number()?;
                let output = self.read_number()?;
                folder.bind_pairs.push((input, output));
            }
            let packed = total_in - bind_pair_count;
            if packed > 1 {
                for _ in 0..packed {
                    self.read_number()?;
                }
            }
            folders.push(folder);
        }
        Ok(folders)
    }

    fn process_substreams_info(&mut self, header: &mut Header) -> Parse<bool> {
        let folders = header.folders.clone().unwrap_or_default();
        if folders.is_empty() {
            self.error = "No folders found, cannot process substreams info".to_owned();
            return Ok(false);
        }
        let count = header.num_folders.unwrap_or(0);
        let mut id = self.cursor.byte()?;
        let mut subs = Substreams::default();
        if id == PROPERTY_NUM_UNPACK_STREAM {
            for _ in 0..count {
                subs.num_unpack_streams.push(self.read_number()?);
            }
            id = self.cursor.byte()?;
        } else {
            subs.num_unpack_streams = vec![1; folders.len()];
        }
        if id == PROPERTY_SIZE {
            for (index, streams) in subs.num_unpack_streams.iter().enumerate() {
                let streams = *streams;
                if streams == 0 {
                    continue;
                }
                let mut sum = 0_i64;
                for _ in 1..streams {
                    let size = self.read_number()?;
                    subs.unpack_sizes.push(size);
                    sum = sum.saturating_add(size);
                }
                let total = folders.get(index).map_or(0, folder_unpack_size);
                subs.unpack_sizes.push(total - sum);
            }
            id = self.cursor.byte()?;
        }
        let (mut needed, mut total) = (0_i64, 0_i64);
        for (index, streams) in subs.num_unpack_streams.iter().enumerate() {
            let defined = folders
                .get(index)
                .is_some_and(|folder| folder.digest_defined);
            if *streams != 1 || !defined {
                needed = needed.saturating_add(*streams);
            }
            total = total.saturating_add(*streams);
        }
        // PHP would exhaust its memory on a corrupt count; here it ends the parse.
        if total > self.cursor.length.saturating_mul(8) {
            return Err(Thrown);
        }
        if id == PROPERTY_CRC {
            let (defined, crcs) = self.read_digests(needed)?;
            let mut digest = 0_usize;
            for (index, streams) in subs.num_unpack_streams.iter().enumerate() {
                let folder = folders.get(index);
                if *streams == 1 && folder.is_some_and(|folder| folder.digest_defined) {
                    subs.digests_defined.push(1);
                    subs.digests
                        .push(folder.map_or(0, |folder| folder.unpack_crc));
                } else {
                    for _ in 0..*streams {
                        subs.digests_defined
                            .push(*defined.get(digest).ok_or(Thrown)?);
                        subs.digests.push(crcs.get(digest).copied().unwrap_or(0));
                        digest += 1;
                    }
                }
            }
            id = self.cursor.byte()?;
        } else {
            let count = usize::try_from(total).map_err(|_| Thrown)?;
            subs.digests_defined = vec![0; count];
            subs.digests = vec![0; count];
        }
        header.substreams = Some(subs);
        Ok(self.check_is_end(id))
    }

    fn process_files_info(&mut self, header: &mut Header) -> Parse<bool> {
        let count = self.read_number()?;
        // PHP would exhaust its memory on a corrupt count; here it ends the parse.
        if count > self.cursor.length.saturating_mul(8) {
            return Err(Thrown);
        }
        let count_usize = usize::try_from(count).map_err(|_| Thrown)?;
        header.files = vec![
            SevenZipFile {
                has_stream: true,
                name: None,
                utime: None,
                is_dir: false,
            };
            count_usize
        ];
        let mut empty_stream_count = 0;
        while self.cursor.offset < self.cursor.length {
            let kind = self.read_number()?;
            if kind > 255 {
                self.error = format!(
                    "Invalid type, must be below 256: {kind} at: {}",
                    self.cursor.offset - 1
                );
                return Ok(false);
            }
            if kind == PROPERTY_END {
                break;
            }
            let size = self.read_number()?;
            match kind {
                PROPERTY_NAME => {
                    if !self.check_external()? {
                        return Ok(false);
                    }
                    for file in &mut header.files {
                        let mut name = Vec::new();
                        while self.cursor.offset < self.cursor.length {
                            let data = self.cursor.read(2)?;
                            if data == [0, 0] {
                                file.name = Some(utf16le_to_utf8(&name));
                                break;
                            }
                            name.extend(data);
                        }
                    }
                }
                PROPERTY_LAST_WRITE_TIME | PROPERTY_CREATION_TIME | PROPERTY_LAST_ACCESS_TIME => {
                    let defined = self.read_booleans(count, true)?;
                    if !self.check_external()? {
                        return Ok(false);
                    }
                    for (index, file) in header.files.iter_mut().enumerate() {
                        if *defined.get(index).ok_or(Thrown)? == 1 {
                            let low = self.cursor.u32()?;
                            let high = self.cursor.u32()?;
                            if kind == PROPERTY_LAST_WRITE_TIME {
                                file.utime = Some(windows_to_unix(low, high));
                            }
                        }
                    }
                }
                PROPERTY_ATTRIBUTES => {
                    let defined = self.read_booleans(count, true)?;
                    if !self.check_external()? {
                        return Ok(false);
                    }
                    for index in 0..count_usize {
                        if *defined.get(index).ok_or(Thrown)? == 1 {
                            self.cursor.u32()?;
                        }
                    }
                }
                PROPERTY_START_POSITION => {
                    let defined = self.read_booleans(count, true)?;
                    if !self.check_external()? {
                        return Ok(false);
                    }
                    for index in 0..count_usize {
                        if *defined.get(index).ok_or(Thrown)? == 1 {
                            self.cursor.read(8)?;
                        }
                    }
                }
                PROPERTY_EMPTY_STREAM => {
                    header.empty_streams = self.read_booleans(count, false)?;
                    empty_stream_count = header.empty_streams.iter().sum();
                }
                PROPERTY_EMPTY_FILE => {
                    header.empty_files = self.read_booleans(empty_stream_count, false)?;
                }
                PROPERTY_ANTI => {
                    self.read_booleans(empty_stream_count, false)?;
                }
                _ => {
                    self.cursor.read(size)?;
                }
            }
        }
        let mut empty_index = 0_usize;
        for (index, file) in header.files.iter_mut().enumerate() {
            if header.empty_streams.get(index).copied().unwrap_or(0) != 0 {
                file.has_stream = false;
                if header.empty_files.get(empty_index).copied().unwrap_or(0) == 0 {
                    file.is_dir = true;
                }
                empty_index += 1;
            }
        }
        header.next_offset = self.cursor.offset;
        Ok(true)
    }

    fn check_is_end(&mut self, id: i64) -> bool {
        if id != PROPERTY_END {
            self.error = format!(
                "Expecting PROPERTY_END but found: {id} at: {}",
                self.cursor.offset - 1
            );
            return false;
        }
        true
    }

    fn check_external(&mut self) -> Parse<bool> {
        if self.cursor.byte()? != 0 {
            self.error = format!(
                "External switch not implemented, at: {}",
                self.cursor.offset - 1
            );
            return Ok(false);
        }
        Ok(true)
    }

    fn read_number(&mut self) -> Parse<i64> {
        let first = self.cursor.byte()?;
        let (mut low, mut high) = (0_i64, 0_i64);
        let mut mask = 0x80;
        for count in 0..8_u32 {
            if first & mask == 0 {
                let remainder = first & (mask - 1);
                if count < 4 {
                    low += remainder << (count * 8);
                } else {
                    high += remainder << ((count - 4) * 8);
                }
                return Ok(if high == 0 { low } else { int64(low, high) });
            }
            let next = self.cursor.byte()?;
            if count < 4 {
                low += next << (count * 8);
            } else {
                high += next << ((count - 4) * 8);
            }
            mask >>= 1;
        }
        Ok(0)
    }

    /// See the module comment for why this returns nothing without the all-defined flag.
    fn read_booleans(&mut self, count: i64, check_all: bool) -> Parse<Vec<i64>> {
        if check_all && self.cursor.byte()? != 0 {
            let limit = self.cursor.length.saturating_mul(8);
            if count > limit {
                return Err(Thrown);
            }
            return Ok(vec![1; usize::try_from(count).map_err(|_| Thrown)?]);
        }
        Ok(Vec::new())
    }

    fn read_digests(&mut self, count: i64) -> Parse<(Vec<i64>, Vec<i64>)> {
        let defined = self.read_booleans(count, true)?;
        let mut crcs = Vec::new();
        for index in 0..usize::try_from(count).map_err(|_| Thrown)? {
            if defined.get(index).copied().unwrap_or(0) != 0 {
                crcs.push(self.cursor.u32()?);
            } else {
                crcs.push(0);
            }
        }
        Ok((defined, crcs))
    }

    fn header_of_kind(&self, kind: i64) -> Option<&Header> {
        self.headers.iter().find(|header| header.kind == kind)
    }

    /// Encoded Headers in order, for the sparse listing through the 7-Zip client.
    pub fn encoded_headers(&self) -> impl Iterator<Item = &Header> {
        self.headers
            .iter()
            .filter(|header| header.kind == PROPERTY_ENCODED_HEADER)
    }

    /// `getFileList`. `Err` is one of the PHP's warnings for a header set it couldn't collate.
    pub fn file_list(&self) -> Parse<Vec<FileRecord>> {
        let Some(info) = self.header_of_kind(PROPERTY_FILES_INFO) else {
            return Ok(Vec::new());
        };
        if info.files.is_empty() {
            return Ok(Vec::new());
        }
        let streams = self.header_of_kind(PROPERTY_MAIN_STREAMS_INFO);
        let subs = streams.and_then(|header| header.substreams.as_ref());
        let folders = streams
            .and_then(|header| header.folders.as_deref())
            .unwrap_or(&[]);
        let unpack_sizes: Vec<i64> = match subs.filter(|subs| !subs.unpack_sizes.is_empty()) {
            Some(subs) => subs.unpack_sizes.clone(),
            None => folders.iter().map(folder_unpack_size).collect(),
        };
        let pack_ranges = self.packed_ranges()?;
        let (mut folder_index, mut size_index, mut stream_index) = (0_usize, 0_usize, 0_i64);
        let mut files = Vec::with_capacity(info.files.len());
        for file in &info.files {
            let mut record = FileRecord {
                name: file
                    .name
                    .as_deref()
                    .map_or_else(|| b"Unknown".to_vec(), truncated_name),
                size: if file.has_stream {
                    unpack_sizes.get(size_index).copied().unwrap_or(0)
                } else {
                    0
                },
                date: file.utime.unwrap_or(0),
                compressed: Some(0),
                is_dir: file.is_dir,
                ..FileRecord::default()
            };
            if file.has_stream {
                let mut streams_in_folder = 1;
                if let Some(folder) = folders.get(folder_index) {
                    record.pass = folder.is_encrypted;
                    record.compressed = Some(folder.is_compressed);
                    if let Some(count) =
                        subs.and_then(|subs| subs.num_unpack_streams.get(folder_index))
                    {
                        streams_in_folder = *count;
                    }
                }
                if let Some(Some(range)) = pack_ranges.as_ref().and_then(|r| r.get(folder_index)) {
                    record.range = Some(*range);
                }
                if let Some(subs) = subs
                    && subs.digests_defined.get(size_index).copied().unwrap_or(0) != 0
                {
                    let crc = subs.digests.get(size_index).copied().ok_or(Thrown)?;
                    record.crc32 = Some(format!("{crc:x}"));
                }
                stream_index += 1;
                if stream_index == streams_in_folder {
                    stream_index = 0;
                    folder_index += 1;
                }
                size_index += 1;
            }
            files.push(record);
        }
        Ok(files)
    }

    /// `getPackedRanges`: `None` without main streams, and each block's range when any of it
    /// is in the data.
    fn packed_ranges(&self) -> Parse<Option<Vec<PackedRange>>> {
        let Some(main) = self.header_of_kind(PROPERTY_MAIN_STREAMS_INFO) else {
            return Ok(None);
        };
        let pack_sizes = main.pack_sizes.as_ref().ok_or(Thrown)?;
        let start_header = self.header_of_kind(START_HEADER);
        let (start, end) = if let Some(header) = start_header {
            let start = self.cursor.start + header.data_offset;
            let total = pack_sizes
                .iter()
                .fold(0_i64, |sum, size| sum.saturating_add(*size));
            (start, start.saturating_add(total) - 1)
        } else {
            let start = self.cursor.start;
            if let Some(encoded) = self.header_of_kind(PROPERTY_ENCODED_HEADER) {
                let first = *encoded
                    .pack_sizes
                    .as_ref()
                    .and_then(|sizes| sizes.first())
                    .ok_or(Thrown)?;
                (start, start + encoded.offset - first - 1)
            } else {
                let header = self.header_of_kind(PROPERTY_HEADER).ok_or(Thrown)?;
                (start, start + header.offset - 1)
            }
        };
        let mut ranges = Vec::with_capacity(pack_sizes.len());
        let mut block_end = end;
        for size in pack_sizes.iter().rev() {
            if block_end < start {
                ranges.push(None);
                continue;
            }
            let block_start = start.max(block_end.saturating_sub(*size) + 1);
            ranges.push(Some((block_start, block_end)));
            block_end = block_end.saturating_sub(*size);
        }
        ranges.reverse();
        Ok(Some(ranges))
    }
}

fn folder_unpack_size(folder: &Folder) -> i64 {
    for (index, size) in folder.unpack_sizes.iter().enumerate().rev() {
        let index = i64::try_from(index).unwrap_or(i64::MAX);
        if !folder.bind_pairs.iter().any(|(_, output)| *output == index) {
            return *size;
        }
    }
    0
}

fn sane_streams_info(header: &Header) -> bool {
    let fail = i32::from(header.pack_offset.is_none())
        + i32::from(
            header
                .pack_sizes
                .as_ref()
                .is_none_or(|sizes| sizes.is_empty()),
        )
        + i32::from(!header.num_folders.is_some_and(|count| (1..=50).contains(&count)))
        + i32::from(!header.num_streams.is_some_and(|count| (1..=50).contains(&count)))
        // The PHP looks for `coders` on the folder list itself, which is always empty.
        + 1;
    fail < 3
}

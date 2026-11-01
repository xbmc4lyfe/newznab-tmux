//! RAR 1.5 to 5.0 block parsing and file lists. Port of `RarInfo`, and `SrrInfo`, which is the
//! same reader with no file data in it.

use std::mem::size_of;

use super::reader::{
    Cursor, FileRecord, MAX_SCAN_WORK, Parse, Thrown, dos_to_unix, find, int64, php_empty,
    truncated_name, utf16le_to_utf8, windows_to_unix,
};

const BLOCK_MAIN: i64 = 0x73;
const BLOCK_FILE: i64 = 0x74;
const BLOCK_SUB: i64 = 0x7a;
const BLOCK_ENDARC: i64 = 0x7b;
const BLOCK_NULL: i64 = 0x00;
const MAIN_VOLUME: i64 = 0x0001;
const MAIN_PASSWORD: i64 = 0x0080;
const FILE_SPLIT_BEFORE: i64 = 0x0001;
const FILE_SPLIT_AFTER: i64 = 0x0002;
const FILE_PASSWORD: i64 = 0x0004;
const FILE_DICTMASK: i64 = 0x00e0;
const FILE_DIRECTORY: i64 = 0x00e0;
const FILE_LARGE: i64 = 0x0100;
const FILE_UNICODE: i64 = 0x0200;
const FILE_SALT: i64 = 0x0400;
const LONG_BLOCK: i64 = 0x8000;
const HEADER_SIZE: i64 = 7;
const METHOD_STORE: i64 = 0x30;

const R50_BLOCK_MAIN: i64 = 0x01;
const R50_BLOCK_FILE: i64 = 0x02;
const R50_BLOCK_SERVICE: i64 = 0x03;
const R50_BLOCK_CRYPT: i64 = 0x04;
const R50_BLOCK_ENDARC: i64 = 0x05;
const R50_HAS_EXTRA: i64 = 0x0001;
const R50_HAS_DATA: i64 = 0x0002;
const R50_SPLIT_BEFORE: i64 = 0x0008;
const R50_SPLIT_AFTER: i64 = 0x0010;
const R50_MAIN_VOLUME: i64 = 0x0001;
const R50_MAIN_VOLNUMBER: i64 = 0x0002;
const R50_FILE_DIRECTORY: i64 = 0x0001;
const R50_FILE_UTIME: i64 = 0x0002;
const R50_FILE_CRC32: i64 = 0x0004;
const R50_ENDARC_NEXT_VOLUME: i64 = 0x0001;
const R50_METHOD_STORE: i64 = 0;

const SRR_BLOCK_MARK: i64 = 0x69;
const SRR_STORED_FILE: i64 = 0x6a;
const SRR_OSO_HASH: i64 = 0x6b;
const SRR_RAR_FILE: i64 = 0x71;

const MARKER_RAR15: &[u8] = b"Rar!\x1a\x07\x00";
const MARKER_RAR50: &[u8] = b"Rar!\x1a\x07\x01\x00";
const MARKER_SRR: &[u8] = b"\x69\x69\x69";

/// An SRR volume's name, and its files.
pub type SrrVolume = (Option<Vec<u8>>, Vec<FileRecord>);

#[derive(Clone, Copy, Debug, PartialEq, Eq)]
enum Format {
    None,
    Rar15,
    Rar50,
}

/// The block fields file lists and later checks read.
#[derive(Clone, Debug, Default)]
pub struct Block {
    pub offset: i64,
    pub head_crc: i64,
    pub head_type: i64,
    pub head_flags: i64,
    pub head_size: i64,
    pub add_size: i64,
    pub next_offset: i64,
    pub extra_size: i64,
    pub data_size: Option<i64>,
    pub pack_size: Option<i64>,
    pub unp_size: Option<i64>,
    pub host_os: i64,
    pub file_crc: i64,
    pub ftime: i64,
    pub utime: i64,
    pub unp_ver: i64,
    pub method: Option<i64>,
    pub name_size: i64,
    pub file_name: Vec<u8>,
    pub is_dir: bool,
    pub has_salt: bool,
    pub has_password: bool,
    pub split_before: bool,
    pub split_after: bool,
    pub more_volumes: Option<bool>,
}

#[derive(Clone, Debug)]
pub struct RarReader {
    cursor: Cursor,
    srr: bool,
    fragment: bool,
    format: Format,
    marker_position: Option<Option<i64>>,
    pub blocks: Vec<Block>,
    pub error: String,
    pub is_volume: bool,
    pub is_encrypted: bool,
}

impl RarReader {
    pub fn analyze(cursor: Cursor, fragment: bool, srr: bool) -> Self {
        let mut reader = Self {
            cursor,
            srr,
            fragment,
            format: Format::None,
            marker_position: None,
            blocks: Vec::new(),
            error: String::new(),
            is_volume: false,
            is_encrypted: false,
        };
        if srr {
            reader.analyze_srr();
        } else {
            reader.analyze_rar();
        }
        reader
    }

    pub fn cursor(&self) -> &Cursor {
        &self.cursor
    }

    pub fn find_marker(&mut self) -> Option<i64> {
        if let Some(position) = self.marker_position {
            return position;
        }
        let Ok(buffer) = self.cursor.marker_buffer() else {
            return None;
        };
        let primary = if self.srr { MARKER_SRR } else { MARKER_RAR15 };
        let position = if let Some(position) = find(&buffer, primary) {
            self.format = Format::Rar15;
            Some(position)
        } else if let Some(position) = find(&buffer, MARKER_RAR50) {
            self.format = Format::Rar50;
            Some(position)
        } else {
            None
        };
        self.marker_position = Some(position);
        position
    }

    fn analyze_rar(&mut self) {
        let start = self.find_marker();
        let begun = if self.format == Format::Rar50 {
            let position = start.unwrap_or(0) + 8;
            self.cursor.seek(position)
        } else if start.is_none() && !self.fragment {
            self.error = "Could not find Marker block, not a valid RAR file".to_owned();
            return;
        } else if let Some(position) = start {
            self.cursor.seek(position)
        } else {
            let Some(position) = self.find_file_header() else {
                self.error = "Could not find a valid File header".to_owned();
                return;
            };
            self.cursor.seek(position)
        };
        // A marker is always wholly inside the data, so these seeks can't fail.
        if begun.is_err() {
            return;
        }
        while self.cursor.offset < self.cursor.length {
            if !matches!(self.one_block(), Ok(true)) {
                break;
            }
        }
    }

    /// Ok(false) ends the loop, as the encrypted-archive break does.
    fn one_block(&mut self) -> Parse<bool> {
        let mut block = self.next_block()?;
        self.process_block(&mut block)?;
        let offset = block.offset;
        let next = block.next_offset;
        self.keep(block)?;
        if self.is_encrypted {
            return Ok(false);
        }
        if self.cursor.offset != next {
            self.cursor.seek(next)?;
        }
        if offset == self.cursor.offset {
            self.error = "Parsing seems to be stuck".to_owned();
            return Ok(false);
        }
        Ok(true)
    }

    /// A block costs its row too, since each one can become a file list entry.
    fn keep(&mut self, block: Block) -> Parse<()> {
        self.cursor
            .budget
            .retain(size_of::<Block>() + size_of::<FileRecord>() + block.file_name.len())?;
        self.blocks.push(block);
        Ok(())
    }

    fn analyze_srr(&mut self) {
        let Some(start) = self.find_marker() else {
            self.error = "Could not find Marker block, not a valid SRR file".to_owned();
            return;
        };
        if self.cursor.seek(start).is_err() {
            return;
        }
        while self.cursor.offset < self.cursor.length {
            if self.one_srr_block().is_err() || !self.error.is_empty() {
                break;
            }
        }
    }

    fn one_srr_block(&mut self) -> Parse<()> {
        let mut block = self.next_block()?;
        match block.head_type {
            SRR_BLOCK_MARK => {
                if block.head_flags & 1 != 0 {
                    let size = self.cursor.u16()?;
                    self.cursor.read(size)?;
                }
                if block.head_flags > 1 || self.cursor.offset != block.next_offset {
                    self.error = "Could not find Marker block, not a valid SRR file".to_owned();
                    return Ok(());
                }
            }
            SRR_STORED_FILE => {
                let size = self.cursor.u16()?;
                block.file_name = self.cursor.read(size)?;
                self.cursor.skip(block.add_size)?;
            }
            SRR_OSO_HASH => {
                self.cursor.read(16)?;
                let size = self.cursor.u16()?;
                block.file_name = self.cursor.read(size)?;
            }
            SRR_RAR_FILE => {
                let size = self.cursor.u16()?;
                block.file_name = self.cursor.read(size)?;
            }
            _ => self.process_block(&mut block)?,
        }
        let offset = block.offset;
        let next = block.next_offset;
        self.keep(block)?;
        if self.cursor.offset != next {
            self.cursor.seek(next)?;
        }
        if offset == self.cursor.offset {
            self.error = "Parsing seems to be stuck".to_owned();
        }
        Ok(())
    }

    /// Searches a fragment for a File header that passes its CRC and a sanity check. Slow, and
    /// only useful for RAR 1.5 to 4.x.
    fn find_file_header(&mut self) -> Option<i64> {
        let start = self.cursor.offset;
        let buffer = self.cursor.marker_buffer().ok()?;
        let began = self.cursor.budget.work();
        for (position, _) in buffer
            .iter()
            .enumerate()
            .filter(|(_, byte)| i64::from(**byte) == BLOCK_FILE)
        {
            if self.cursor.budget.work() - began > MAX_SCAN_WORK {
                return None;
            }
            let offset = i64::try_from(position).ok()? + start;
            let Ok(found) = self.try_file_header(offset) else {
                continue;
            };
            if let Some(found) = found {
                self.format = Format::Rar15;
                self.marker_position = Some(Some(found));
                return Some(found);
            }
        }
        None
    }

    fn try_file_header(&mut self, offset: i64) -> Parse<Option<i64>> {
        self.cursor.seek(offset - 2)?;
        let mut block = self.next_block()?;
        if self.check_file_header_crc(&block) {
            self.cursor.seek(block.offset + HEADER_SIZE)?;
            self.process_block(&mut block)?;
            if sane_file_header(&block) {
                return Ok(Some(block.offset));
            }
        }
        Ok(None)
    }

    fn check_file_header_crc(&mut self, block: &Block) -> bool {
        if self.format == Format::Rar50 || self.cursor.seek(block.offset + 2).is_err() {
            return false;
        }
        self.cursor
            .read(block.head_size - 2)
            .is_ok_and(|data| i64::from(crc32(&data) & 0xffff) == block.head_crc)
    }

    fn next_block(&mut self) -> Parse<Block> {
        if self.format == Format::Rar50 {
            return self.next_block_r50();
        }
        let mut block = Block {
            offset: self.cursor.offset,
            ..Block::default()
        };
        let head = self.cursor.read(HEADER_SIZE)?;
        block.head_crc = i64::from(u16::from_le_bytes([head[0], head[1]]));
        block.head_type = i64::from(head[2]);
        block.head_flags = i64::from(u16::from_le_bytes([head[3], head[4]]));
        block.head_size = i64::from(u16::from_le_bytes([head[5], head[6]]));
        if block.head_flags & LONG_BLOCK != 0
            && block.head_type != BLOCK_FILE
            && block.head_type != BLOCK_SUB
        {
            block.add_size = self.cursor.u32()?;
        }
        block.head_size = block.head_size.max(HEADER_SIZE);
        block.next_offset = block.offset + block.head_size + block.add_size;
        Ok(block)
    }

    fn process_block(&mut self, block: &mut Block) -> Parse<()> {
        if self.format == Format::Rar50 {
            return self.process_block_r50(block, false);
        }
        match block.head_type {
            BLOCK_MAIN => {
                self.cursor.read(6)?;
                if block.head_flags & MAIN_VOLUME != 0 {
                    self.is_volume = true;
                }
                if block.head_flags & MAIN_PASSWORD != 0 {
                    self.is_encrypted = true;
                }
            }
            BLOCK_ENDARC => block.more_volumes = Some(block.head_flags & 1 != 0),
            BLOCK_NULL => {
                let remainder = self.cursor.length - block.offset - block.head_size;
                if remainder < HEADER_SIZE {
                    block.next_offset = self.cursor.length;
                    block.add_size = remainder;
                }
            }
            BLOCK_FILE | BLOCK_SUB => self.process_file_block(block)?,
            _ => {}
        }
        if block.head_type == BLOCK_SUB && block.file_name == b"AV" {
            let name = self.cursor.u16()?;
            self.cursor.read(name)?;
            self.cursor.u16()?;
            let created_by = self.cursor.u16()?;
            self.cursor.read(created_by)?;
        }
        Ok(())
    }

    fn process_file_block(&mut self, block: &mut Block) -> Parse<()> {
        let header = self.cursor.read(25)?;
        let long = |at: usize| {
            i64::from(u32::from_le_bytes([
                header[at],
                header[at + 1],
                header[at + 2],
                header[at + 3],
            ]))
        };
        let mut pack_size = long(0);
        let mut unp_size = long(4);
        block.host_os = i64::from(header[8]);
        block.file_crc = long(9);
        block.ftime = long(13);
        block.unp_ver = i64::from(header[17]);
        block.method = Some(i64::from(header[18]));
        block.name_size = i64::from(u16::from_le_bytes([header[19], header[20]]));
        if block.head_flags & FILE_LARGE != 0 {
            let high_pack = self.cursor.u32()?;
            let high_unp = self.cursor.u32()?;
            pack_size = int64(pack_size, high_pack);
            unp_size = int64(unp_size, high_unp);
        }
        block.pack_size = Some(pack_size);
        block.unp_size = Some(unp_size);
        if block.head_flags & FILE_DICTMASK == FILE_DIRECTORY {
            block.is_dir = true;
        }
        let raw = self.cursor.read(block.name_size)?;
        block.file_name = if block.head_flags & FILE_UNICODE != 0 {
            let mut parts = raw.split(|byte| *byte == 0);
            let standard = parts.next().filter(|part| !php_empty(part)).unwrap_or(&[]);
            let encoded = parts.next().filter(|part| !php_empty(part)).unwrap_or(&[]);
            match decode_unicode_name(standard, encoded) {
                Some(decoded) if !php_empty(&decoded) => utf16le_to_utf8(&decoded),
                _ => standard.to_vec(),
            }
        } else {
            raw
        };
        if block.head_flags & FILE_SALT != 0 {
            self.cursor.read(8)?;
            block.has_salt = true;
        }
        if block.head_flags & FILE_PASSWORD != 0 {
            block.has_password = true;
        }
        if block.head_flags & FILE_SPLIT_BEFORE != 0 {
            block.split_before = true;
        }
        if block.head_flags & FILE_SPLIT_AFTER != 0 {
            block.split_after = true;
        }
        let headers_only = self.srr
            && (block.head_type == BLOCK_FILE
                || (block.head_type == BLOCK_SUB && block.file_name == b"RR"));
        if !headers_only {
            block.next_offset = block.next_offset.saturating_add(pack_size);
        }
        Ok(())
    }

    fn next_block_r50(&mut self) -> Parse<Block> {
        let mut block = Block {
            offset: self.cursor.offset,
            ..Block::default()
        };
        block.head_crc = self.cursor.u32()?;
        let raw_size = self.var_int()?;
        block.head_size = (self.cursor.offset - block.offset).saturating_add(raw_size);
        block.head_type = self.var_int()?;
        block.head_flags = self.var_int()?;
        if block.head_flags & R50_HAS_EXTRA != 0 {
            block.extra_size = self.var_int()?;
        }
        if block.head_flags & R50_HAS_DATA != 0 {
            block.data_size = Some(self.var_int()?);
        }
        block.head_size = block.head_size.max(HEADER_SIZE);
        block.next_offset = block
            .offset
            .saturating_add(block.head_size)
            .saturating_add(block.data_size.unwrap_or(0));
        Ok(block)
    }

    fn process_block_r50(&mut self, block: &mut Block, quick_open: bool) -> Parse<()> {
        match block.head_type {
            R50_BLOCK_MAIN => {
                let flags = self.var_int()?;
                if flags & R50_MAIN_VOLUME != 0 {
                    self.is_volume = true;
                }
                if flags & R50_MAIN_VOLNUMBER != 0 {
                    self.var_int()?;
                }
            }
            R50_BLOCK_ENDARC => {
                let flags = self.var_int()?;
                block.more_volumes = Some(flags & R50_ENDARC_NEXT_VOLUME != 0);
            }
            R50_BLOCK_CRYPT => self.is_encrypted = true,
            R50_BLOCK_FILE | R50_BLOCK_SERVICE => {
                let data_size = block.data_size.ok_or(Thrown)?;
                let flags = self.var_int()?;
                block.pack_size = Some(data_size);
                block.unp_size = Some(self.var_int()?);
                self.var_int()?;
                if flags & R50_FILE_UTIME != 0 {
                    block.utime = self.cursor.u32()?;
                }
                if flags & R50_FILE_CRC32 != 0 {
                    block.file_crc = self.cursor.u32()?;
                }
                if flags & R50_FILE_DIRECTORY != 0 {
                    block.is_dir = true;
                }
                let compression = self.var_int()?;
                block.unp_ver = compression & 0x3f;
                block.method = Some((compression >> 7) & 7);
                block.host_os = self.var_int()?;
                block.name_size = self.var_int()?;
                block.file_name = self.cursor.read(block.name_size)?;
                if !quick_open && block.head_type == R50_BLOCK_SERVICE {
                    if block.file_name == b"CMT" {
                        self.cursor.skip(data_size)?;
                    } else if block.file_name == b"QO" {
                        self.process_quick_open_records(data_size)?;
                    }
                }
            }
            _ => {}
        }
        if block.head_flags & R50_SPLIT_BEFORE != 0 {
            block.split_before = true;
        }
        if block.head_flags & R50_SPLIT_AFTER != 0 {
            block.split_after = true;
        }
        if block.head_flags & R50_HAS_EXTRA != 0 {
            self.process_extra_records(block)?;
        }
        Ok(())
    }

    /// Cached headers aren't used, but a malformed cache ends parsing just as the PHP's did.
    fn process_quick_open_records(&mut self, data_size: i64) -> Parse<()> {
        let end = self
            .cursor
            .offset
            .saturating_add(data_size)
            .min(self.cursor.length);
        while self.cursor.offset < end {
            self.cursor.u32()?;
            self.var_int()?;
            self.var_int()?;
            self.var_int()?;
            let size = self.var_int()?;
            let next = self.cursor.offset.saturating_add(size);
            let mut cached = self.next_block_r50()?;
            self.process_block_r50(&mut cached, true)?;
            if self.cursor.offset != next {
                self.cursor.seek(next)?;
            }
        }
        Ok(())
    }

    fn process_extra_records(&mut self, block: &mut Block) -> Parse<()> {
        const MAX_NAME: i64 = 256;
        let end = self
            .cursor
            .offset
            .saturating_add(block.extra_size)
            .min(self.cursor.length);
        while self.cursor.offset < end {
            let size = self.var_int()?;
            let next = self.cursor.offset.saturating_add(size);
            let kind = self.var_int()?;
            if block.head_type == R50_BLOCK_MAIN {
                if kind == 0x01 {
                    let flags = self.var_int()?;
                    if flags & 0x0001 != 0 {
                        self.var_int()?;
                    }
                    if flags & 0x0002 != 0 {
                        self.var_int()?;
                    }
                }
            } else if block.head_type == R50_BLOCK_FILE {
                match kind {
                    0x01 => block.has_password = true,
                    0x03 => self.file_time_record(block)?,
                    0x04 => {
                        self.var_int()?;
                    }
                    0x05 => {
                        self.var_int()?;
                        self.var_int()?;
                        let target = self.var_int()?;
                        if target > 0 && target <= MAX_NAME {
                            self.cursor.read(target)?;
                        }
                    }
                    0x06 => {
                        let flags = self.var_int()?;
                        for bit in [0x0001, 0x0002] {
                            if flags & bit != 0 {
                                let length = self.var_int()?;
                                if length > 0 && length <= MAX_NAME {
                                    self.cursor.read(length)?;
                                }
                            }
                        }
                        for bit in [0x0004, 0x0008] {
                            if flags & bit != 0 {
                                self.var_int()?;
                            }
                        }
                    }
                    _ => {}
                }
            }
            if self.cursor.offset != next {
                self.cursor.seek(next)?;
            }
        }
        Ok(())
    }

    fn file_time_record(&mut self, block: &mut Block) -> Parse<()> {
        let flags = self.var_int()?;
        let unix = flags & 0x0001 != 0;
        if flags & 0x0002 != 0 {
            if unix {
                block.utime = self.cursor.u32()?;
            } else {
                let low = self.cursor.u32()?;
                let high = self.cursor.u32()?;
                block.utime = windows_to_unix(low, high);
            }
        }
        for bit in [0x0004, 0x0008] {
            if flags & bit != 0 {
                self.cursor.read(if unix { 4 } else { 8 })?;
            }
        }
        Ok(())
    }

    fn var_int(&mut self) -> Parse<i64> {
        let (mut shift, mut low, mut high, mut count) = (0_i64, 0_i64, 0_i64, 1);
        while self.cursor.offset < self.cursor.length && count <= 10 {
            let byte = self.cursor.byte()?;
            if count < 5 {
                low += (byte & 0x7f) << shift;
            } else if count == 5 {
                low += (byte & 0x0f) << shift;
                high += (byte >> 4) & 0x07;
                shift = -4;
            } else {
                high = high.wrapping_add((byte & 0x7f) << shift);
            }
            if byte & 0x80 == 0 {
                return Ok(if high == 0 { low } else { int64(low, high) });
            }
            shift += 7;
            count += 1;
        }
        Ok(0)
    }

    /// `getFileList`. `Err` is PHP's warning for a file block with neither size.
    pub fn file_list(&self, skip_dirs: bool) -> Parse<Vec<FileRecord>> {
        let mut files = Vec::new();
        for block in &self.blocks {
            if block.head_type == BLOCK_FILE || block.head_type == R50_BLOCK_FILE {
                if skip_dirs && block.is_dir {
                    continue;
                }
                files.push(self.summary_of(block)?);
            }
        }
        Ok(files)
    }

    fn summary_of(&self, block: &Block) -> Parse<FileRecord> {
        let mut record = FileRecord {
            name: if php_empty(&block.file_name) {
                b"Unknown".to_vec()
            } else {
                truncated_name(&block.file_name)
            },
            size: block.unp_size.unwrap_or(0),
            date: if block.utime != 0 {
                block.utime
            } else if block.ftime != 0 {
                dos_to_unix(block.ftime)
            } else {
                0
            },
            pass: i64::from(block.has_password),
            compressed: block
                .method
                .map(|method| i64::from(method != METHOD_STORE && method != R50_METHOD_STORE)),
            next_offset: Some(block.next_offset),
            ..FileRecord::default()
        };
        if block.is_dir {
            record.is_dir = true;
        } else if !self.srr {
            let pack_size = match block.pack_size {
                Some(size) if size != 0 => size,
                _ => block.data_size.ok_or(Thrown)?,
            };
            let start = self
                .cursor
                .start
                .saturating_add(block.offset)
                .saturating_add(block.head_size);
            let end = self.cursor.end.min(start.saturating_add(pack_size) - 1);
            record.range = Some((start, end));
        }
        if block.file_crc != 0 {
            record.crc32 = Some(format!("{:x}", block.file_crc));
        }
        record.split = block.split_after || block.split_before;
        record.split_after = block.split_after;
        Ok(record)
    }

    /// `SrrInfo::getFileList`: each RAR volume by name, with its files.
    pub fn srr_file_list(&self) -> Parse<Vec<SrrVolume>> {
        let mut volumes: Vec<SrrVolume> = Vec::new();
        for block in &self.blocks {
            if block.head_type == SRR_RAR_FILE {
                volumes.push((Some(block.file_name.clone()), Vec::new()));
            } else if block.head_type == BLOCK_FILE {
                if volumes.is_empty() {
                    volumes.push((None, Vec::new()));
                }
                if let Some(last) = volumes.last_mut() {
                    last.1.push(self.summary_of(block)?);
                }
            }
        }
        Ok(volumes)
    }

    /// The last block, for `hasTerminalRarBlock`.
    pub fn last_end_block(&self) -> Option<(bool, i64)> {
        let last = self.blocks.last()?;
        if last.head_type != BLOCK_ENDARC && last.head_type != R50_BLOCK_ENDARC {
            return None;
        }
        Some((last.more_volumes.unwrap_or(true), last.next_offset))
    }
}

fn sane_file_header(block: &Block) -> bool {
    let fail = i32::from(block.host_os > 5)
        + i32::from(block.method.unwrap_or(0) > 0x35)
        + i32::from(block.unp_ver > 50)
        + i32::from(block.name_size > 256)
        + i32::from(block.has_salt && !block.has_password);
    fail < 3
}

pub fn crc32(data: &[u8]) -> u32 {
    crc32fast::hash(data)
}

/// `RarUnicodeFilename::decode`: UTF-16LE from the standard name plus the unicode data, or
/// `None` when either runs out.
fn decode_unicode_name(standard: &[u8], encoded: &[u8]) -> Option<Vec<u8>> {
    let mut decoder = UnicodeName {
        standard,
        encoded,
        position: 0,
        enc_position: 0,
        failed: false,
        output: Vec::new(),
    };
    let high_byte = decoder.enc_byte();
    let (mut flags, mut flag_bits) = (0_u8, 0_u32);
    while decoder.enc_position < encoded.len() {
        if flag_bits == 0 {
            flags = decoder.enc_byte();
            flag_bits = 8;
        }
        flag_bits -= 2;
        match (flags >> flag_bits) & 3 {
            0 => {
                let low = decoder.enc_byte();
                decoder.put(low, 0);
            }
            1 => {
                let low = decoder.enc_byte();
                decoder.put(low, high_byte);
            }
            2 => {
                let low = decoder.enc_byte();
                let high = decoder.enc_byte();
                decoder.put(low, high);
            }
            _ => {
                let count = decoder.enc_byte();
                if count & 0x80 != 0 {
                    let correction = decoder.enc_byte();
                    for _ in 0..(usize::from(count & 0x7f) + 2) {
                        let low = decoder.std_byte().wrapping_add(correction);
                        decoder.put(low, high_byte);
                    }
                } else {
                    for _ in 0..(usize::from(count) + 2) {
                        let low = decoder.std_byte();
                        decoder.put(low, 0);
                    }
                }
            }
        }
    }
    (!decoder.failed).then_some(decoder.output)
}

struct UnicodeName<'a> {
    standard: &'a [u8],
    encoded: &'a [u8],
    position: usize,
    enc_position: usize,
    failed: bool,
    output: Vec<u8>,
}

impl UnicodeName<'_> {
    fn enc_byte(&mut self) -> u8 {
        let byte = self.encoded.get(self.enc_position).copied();
        self.enc_position += 1;
        self.failed |= byte.is_none();
        byte.unwrap_or(0)
    }

    fn std_byte(&mut self) -> u8 {
        let byte = self.standard.get(self.position).copied();
        self.failed |= byte.is_none();
        byte.unwrap_or(b'?')
    }

    fn put(&mut self, low: u8, high: u8) {
        self.output.extend([low, high]);
        self.position += 1;
    }
}

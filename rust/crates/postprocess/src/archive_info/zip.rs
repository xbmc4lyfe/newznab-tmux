//! ZIP record parsing and file lists. Port of `ZipInfo`.

use std::mem::size_of;

use super::reader::{Cursor, FileRecord, Parse, Thrown, dos_to_unix, find, int64, truncated_name};

const RECORD_CENTRAL_FILE: u32 = 0x0201_4b50;
const RECORD_LOCAL_FILE: u32 = 0x0403_4b50;
const RECORD_SIGNATURE: u32 = 0x0505_4b50;
const RECORD_ENDCENTRAL: u32 = 0x0605_4b50;
const RECORD_Z64_ENDCENTRAL: u32 = 0x0606_4b50;
const RECORD_Z64_ENDCENTRAL_LOC: u32 = 0x0706_4b50;
const RECORD_ARCHIVE_EXTRA: u32 = 0x0806_4b50;
const RECORD_DATA_DESCR: u32 = 0x0807_4b50;

const FILE_ENCRYPTED: i64 = 0x0001;
const FILE_DESCRIPTOR_USED: i64 = 0x0008;
const FILE_CDR_ENCRYPTED: i64 = 0x2000;
const EXTRA_ZIP64: i64 = 0x0001;

#[derive(Clone, Debug, Default)]
struct Record {
    offset: i64,
    kind: u32,
    flags: i64,
    method: i64,
    last_mod_time: i64,
    last_mod_date: i64,
    crc32: i64,
    compressed_size: i64,
    uncompressed_size: i64,
    file_name_length: i64,
    extra_length: i64,
    rel_offset: Option<i64>,
    disk_start: Option<i64>,
    file_name: Vec<u8>,
    next_offset: i64,
    is_encrypted: bool,
    is_dir: bool,
}

#[derive(Clone, Debug)]
pub struct ZipReader {
    cursor: Cursor,
    marker_position: Option<Option<i64>>,
    records: Vec<Record>,
    pub error: String,
    pub is_encrypted: bool,
}

impl ZipReader {
    pub fn analyze(cursor: Cursor) -> Self {
        let mut reader = Self {
            cursor,
            marker_position: None,
            records: Vec::new(),
            error: String::new(),
            is_encrypted: false,
        };
        let Some(start) = reader.find_marker() else {
            reader.error = "Could not find any records, not a valid ZIP file".to_owned();
            return reader;
        };
        if reader.cursor.seek(start).is_err() {
            return reader;
        }
        while reader.cursor.offset < reader.cursor.length {
            match reader.one_record() {
                Ok(true) => {}
                Ok(false) | Err(Thrown) => break,
            }
        }
        if reader.error.is_empty() && reader.records.is_empty() {
            reader.error = "No valid ZIP records were found".to_owned();
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
        let position = find(&buffer, &RECORD_LOCAL_FILE.to_le_bytes())
            .or_else(|| find(&buffer, &RECORD_CENTRAL_FILE.to_le_bytes()))
            .or_else(|| find(&buffer, &RECORD_ENDCENTRAL.to_le_bytes()));
        self.marker_position = Some(position);
        position
    }

    /// Ok(false) ends the loop with an error set.
    fn one_record(&mut self) -> Parse<bool> {
        let offset = self.cursor.offset;
        let kind = u32::try_from(self.cursor.u32()?).map_err(|_| Thrown)?;
        if !matches!(
            kind,
            RECORD_CENTRAL_FILE
                | RECORD_LOCAL_FILE
                | RECORD_SIGNATURE
                | RECORD_ENDCENTRAL
                | RECORD_Z64_ENDCENTRAL
                | RECORD_Z64_ENDCENTRAL_LOC
                | RECORD_ARCHIVE_EXTRA
                | RECORD_DATA_DESCR
        ) {
            // A bad signature resumes the search one byte on.
            self.cursor.seek(self.cursor.offset - 3)?;
            return Ok(true);
        }
        let mut record = Record {
            offset,
            kind,
            ..Record::default()
        };
        self.process_record(&mut record)?;
        let next = record.next_offset;
        self.cursor
            .budget
            .retain(size_of::<Record>() + size_of::<FileRecord>() + record.file_name.len())?;
        self.records.push(record);
        if self.cursor.offset != next {
            self.cursor.seek(next)?;
        }
        if offset == self.cursor.offset {
            self.error = "Parsing seems to be stuck".to_owned();
            return Ok(false);
        }
        Ok(true)
    }

    fn process_record(&mut self, record: &mut Record) -> Parse<()> {
        match record.kind {
            RECORD_LOCAL_FILE => {
                self.cursor.read(2)?;
                self.common_file_fields(record)?;
                record.file_name_length = self.cursor.u16()?;
                record.extra_length = self.cursor.u16()?;
                record.file_name = self.cursor.read(record.file_name_length)?;
                if record.extra_length > 0 {
                    self.process_extra_fields(record)?;
                }
                record.next_offset = self.cursor.offset.saturating_add(record.compressed_size);
                if record.flags & FILE_DESCRIPTOR_USED != 0 {
                    self.cursor.seek(record.next_offset)?;
                    self.cursor.u32()?;
                    record.crc32 = self.cursor.u32()?;
                    record.compressed_size = self.cursor.u32()?;
                    record.uncompressed_size = self.cursor.u32()?;
                    record.next_offset = self.cursor.offset;
                }
            }
            RECORD_CENTRAL_FILE => {
                self.cursor.read(4)?;
                self.common_file_fields(record)?;
                record.file_name_length = self.cursor.u16()?;
                record.extra_length = self.cursor.u16()?;
                let comment_length = self.cursor.u16()?;
                record.disk_start = Some(self.cursor.u16()?);
                self.cursor.u16()?;
                self.cursor.u32()?;
                record.rel_offset = Some(self.cursor.u32()?);
                record.file_name = self.cursor.read(record.file_name_length)?;
                if record.extra_length > 0 {
                    self.process_extra_fields(record)?;
                }
                if comment_length > 0 {
                    self.cursor.read(comment_length)?;
                }
                record.next_offset = self.cursor.offset;
            }
            RECORD_ENDCENTRAL => {
                self.cursor.read(16)?;
                let comment_length = self.cursor.u16()?;
                if comment_length > 0 {
                    self.cursor.read(comment_length)?;
                }
                record.next_offset = self.cursor.offset;
            }
            RECORD_Z64_ENDCENTRAL => {
                let low = self.cursor.u32()?;
                let high = self.cursor.u32()?;
                self.cursor.read(42)?;
                record.next_offset = record.offset.saturating_add(int64(low, high));
            }
            RECORD_Z64_ENDCENTRAL_LOC => {
                self.cursor.read(16)?;
                record.next_offset = self.cursor.offset;
            }
            _ => record.next_offset = self.cursor.offset + 1,
        }
        if record.kind == RECORD_LOCAL_FILE || record.kind == RECORD_CENTRAL_FILE {
            if record.flags & FILE_ENCRYPTED != 0 {
                record.is_encrypted = true;
            }
            if record.flags & FILE_CDR_ENCRYPTED != 0 {
                self.is_encrypted = true;
            }
            // An empty name is PHP's uninitialized string offset warning.
            if *record.file_name.last().ok_or(Thrown)? == b'/' {
                record.is_dir = true;
            }
        }
        Ok(())
    }

    fn common_file_fields(&mut self, record: &mut Record) -> Parse<()> {
        record.flags = self.cursor.u16()?;
        record.method = self.cursor.u16()?;
        record.last_mod_time = self.cursor.u16()?;
        record.last_mod_date = self.cursor.u16()?;
        record.crc32 = self.cursor.u32()?;
        record.compressed_size = self.cursor.u32()?;
        record.uncompressed_size = self.cursor.u32()?;
        Ok(())
    }

    fn process_extra_fields(&mut self, record: &mut Record) -> Parse<()> {
        let end = self.cursor.offset + record.extra_length;
        while self.cursor.offset < end {
            let header = self.cursor.u16()?;
            let size = self.cursor.u16()?;
            if header == EXTRA_ZIP64 {
                if record.uncompressed_size == 0xffff_ffff {
                    record.uncompressed_size = self.cursor.u64_pair()?;
                }
                if record.compressed_size == 0xffff_ffff {
                    record.compressed_size = self.cursor.u64_pair()?;
                }
                if record.rel_offset == Some(0xffff_ffff) {
                    record.rel_offset = Some(self.cursor.u64_pair()?);
                }
                if record.disk_start == Some(0xffff) {
                    record.disk_start = Some(self.cursor.u32()?);
                }
            } else {
                self.cursor.skip(size)?;
            }
        }
        Ok(())
    }

    /// Local File records only, as `getFileList` reads them by default.
    pub fn file_list(&self) -> Vec<FileRecord> {
        self.records
            .iter()
            .filter(|record| record.kind == RECORD_LOCAL_FILE)
            .map(|record| {
                let mut file = FileRecord {
                    name: truncated_name(&record.file_name),
                    size: record.uncompressed_size,
                    date: dos_to_unix((record.last_mod_date << 16) | record.last_mod_time),
                    pass: i64::from(record.is_encrypted),
                    compressed: Some(i64::from(record.method > 0)),
                    next_offset: Some(record.next_offset),
                    ..FileRecord::default()
                };
                if record.is_dir {
                    file.is_dir = true;
                } else {
                    let start = self.cursor.start
                        + record.offset
                        + 30
                        + record.file_name_length
                        + record.extra_length;
                    let end = self
                        .cursor
                        .end
                        .min(start.saturating_add(record.uncompressed_size) - 1);
                    file.range = Some((start, end));
                }
                if record.crc32 != 0 {
                    file.crc32 = Some(format!("{:x}", record.crc32));
                }
                file
            })
            .collect()
    }
}

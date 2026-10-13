use serde::Serialize;

const MAX_HEADER_BYTES: usize = 65_536;
const MAX_OCCURRENCES: usize = 256;

#[derive(Serialize)]
pub struct ArticleDates {
    pub parser_engine: &'static str,
    pub schema_version: u8,
    pub syntax_profile: &'static str,
    pub date: DateSource,
    pub injection_date: DateSource,
    pub legacy_nntp_posting_date: DateSource,
    pub interpretation: &'static str,
}

#[derive(Serialize)]
pub struct DateSource {
    pub parse_status: &'static str,
    pub occurrence_count: usize,
    pub occurrence_count_basis: &'static str,
    pub candidates: Vec<DateOccurrence>,
    pub utc_epoch_seconds: Option<i64>,
}

#[derive(Serialize)]
pub struct DateOccurrence {
    pub source_line_indices: Vec<usize>,
    pub raw_field_hex: String,
    pub unfolded_value_hex: String,
    pub parse_status: &'static str,
    pub utc_epoch_seconds: Option<i64>,
    pub timezone_token: Option<String>,
    pub timezone_offset_minutes: Option<i32>,
    pub timezone_local_offset_unknown: Option<bool>,
    pub seconds_present: Option<bool>,
    pub leap_second: Option<bool>,
    pub weekday_matches_calendar: Option<bool>,
    pub epoch_basis: &'static str,
}

pub fn parse(headers: &[u8]) -> ArticleDates {
    let exceeded = headers.len() > MAX_HEADER_BYTES;
    let mut date = Vec::new();
    let mut injection = Vec::new();
    let mut legacy = Vec::new();
    if !exceeded {
        let mut name = Vec::new();
        let mut value = Vec::new();
        let mut raw = Vec::new();
        let mut lines = Vec::new();
        for (index, physical) in headers.split_inclusive(|byte| *byte == b'\n').enumerate() {
            let line = physical.strip_suffix(b"\n").unwrap_or(physical);
            let line = line.strip_suffix(b"\r").unwrap_or(line);
            if line.is_empty() {
                break;
            }
            if matches!(line.first(), Some(b' ' | b'\t')) {
                if !name.is_empty() {
                    value.extend_from_slice(line);
                    raw.extend_from_slice(physical);
                    lines.push(index + 1);
                }
                continue;
            }
            append(
                &name,
                &value,
                &raw,
                &lines,
                &mut date,
                &mut injection,
                &mut legacy,
            );
            name.clear();
            value.clear();
            raw.clear();
            lines.clear();
            if let Some(colon) = line.iter().position(|byte| *byte == b':') {
                name.extend_from_slice(&line[..colon]);
                value.extend_from_slice(&line[colon + 1..]);
                raw.extend_from_slice(physical);
                lines.push(index + 1);
            }
        }
        append(
            &name,
            &value,
            &raw,
            &lines,
            &mut date,
            &mut injection,
            &mut legacy,
        );
    }
    ArticleDates {
        parser_engine: "nntmux-rust-article-dates",
        schema_version: 1,
        syntax_profile: "Absolute RFC-style dates with four-or-more-digit years, numeric zones, GMT or UT, optional seconds, folded whitespace and bounded nested comments; other obsolete forms are retained as malformed or unsupported",
        date: source(date, exceeded),
        injection_date: source(injection, exceeded),
        legacy_nntp_posting_date: source(legacy, exceeded),
        interpretation: "Absolute header timestamps describe reported composition or injection clocks; they do not measure uploader bandwidth or establish uploader location. A -0000 zone gives UTC time without information about the originating local timezone.",
    }
}

fn source(candidates: Vec<DateOccurrence>, exceeded: bool) -> DateSource {
    let status = if exceeded || candidates.len() >= MAX_OCCURRENCES {
        "budget_exceeded"
    } else {
        match candidates.len() {
            0 => "missing",
            1 => candidates[0].parse_status,
            _ => "duplicate",
        }
    };
    let epoch = (status == "valid")
        .then(|| candidates[0].utc_epoch_seconds)
        .flatten();
    DateSource {
        parse_status: status,
        occurrence_count: candidates.len(),
        occurrence_count_basis: "retained candidates; the total may be larger when budget_exceeded",
        candidates,
        utc_epoch_seconds: epoch,
    }
}

#[allow(clippy::too_many_arguments)]
fn append(
    name: &[u8],
    value: &[u8],
    raw: &[u8],
    lines: &[usize],
    date: &mut Vec<DateOccurrence>,
    injection: &mut Vec<DateOccurrence>,
    legacy: &mut Vec<DateOccurrence>,
) {
    let output = if name.eq_ignore_ascii_case(b"Date") {
        date
    } else if name.eq_ignore_ascii_case(b"Injection-Date") {
        injection
    } else if name.eq_ignore_ascii_case(b"NNTP-Posting-Date") {
        legacy
    } else {
        return;
    };
    if output.len() >= MAX_OCCURRENCES {
        return;
    }
    let mut occurrence = DateOccurrence {
        source_line_indices: lines.to_vec(),
        raw_field_hex: hex(raw),
        unfolded_value_hex: hex(value),
        parse_status: "malformed_or_unsupported_absolute_date",
        utc_epoch_seconds: None,
        timezone_token: None,
        timezone_offset_minutes: None,
        timezone_local_offset_unknown: None,
        seconds_present: None,
        leap_second: None,
        weekday_matches_calendar: None,
        epoch_basis: "Gregorian calendar and POSIX seconds; second 60 maps to the following POSIX second without asserting a historical leap-second event",
    };
    parse_value(value, &mut occurrence);
    output.push(occurrence);
}

fn parse_value(value: &[u8], result: &mut DateOccurrence) {
    if value
        .iter()
        .any(|byte| !matches!(*byte, b'\t' | 0x20..=0x7e))
    {
        result.parse_status = "invalid_octet_or_folding";
        return;
    }
    let mut parser = DateParser {
        bytes: value,
        position: 0,
    };
    let parsed = (|| -> Option<(i64, Option<bool>)> {
        parser.cfws()?;
        let weekday = if parser.peek()?.is_ascii_alphabetic() {
            let token = parser.word()?;
            let weekday = lookup(
                token,
                &[b"Sun", b"Mon", b"Tue", b"Wed", b"Thu", b"Fri", b"Sat"],
            )?;
            parser.cfws()?;
            parser.take(b',')?;
            parser.cfws()?;
            Some(weekday)
        } else {
            None
        };
        let day = parser.digits(1, 2)?;
        parser.separator()?;
        let month = lookup(
            parser.word()?,
            &[
                b"Jan", b"Feb", b"Mar", b"Apr", b"May", b"Jun", b"Jul", b"Aug", b"Sep", b"Oct",
                b"Nov", b"Dec",
            ],
        )? + 1;
        parser.separator()?;
        // Deliberately require modern four-or-more-digit years; obsolete short
        // years remain visible as unsupported rather than guessed centuries.
        let year = parser.digits(4, 9)?;
        parser.separator()?;
        let hour = parser.digits(2, 2)?;
        parser.cfws()?;
        parser.take(b':')?;
        parser.cfws()?;
        let minute = parser.digits(2, 2)?;
        let separator = parser.cfws()?;
        let seconds_present = parser.peek() == Some(b':');
        let (second, zone_separator) = if seconds_present {
            parser.take(b':')?;
            parser.cfws()?;
            let second = parser.digits(2, 2)?;
            (second, parser.cfws()?)
        } else {
            (0, separator)
        };
        if !zone_separator {
            return None;
        }
        let zone_start = parser.position;
        let (offset, unknown) = match parser.peek()? {
            b'+' | b'-' => {
                let negative = parser.peek()? == b'-';
                parser.position += 1;
                let digits = parser.digits(4, 4)?;
                if digits / 100 > 23 || digits % 100 > 59 {
                    return None;
                }
                let minutes = (digits / 100 * 60 + digits % 100) as i32;
                (
                    if negative { -minutes } else { minutes },
                    negative && digits == 0,
                )
            }
            _ => {
                let zone = parser.word()?;
                result.timezone_token = Some(String::from_utf8_lossy(zone).into_owned());
                if !zone.eq_ignore_ascii_case(b"GMT") && !zone.eq_ignore_ascii_case(b"UT") {
                    result.parse_status = "unsupported_absolute_timezone";
                    return None;
                }
                (0, false)
            }
        };
        result.timezone_token =
            Some(String::from_utf8_lossy(&value[zone_start..parser.position]).into_owned());
        result.timezone_offset_minutes = Some(offset);
        result.timezone_local_offset_unknown = Some(unknown);
        result.seconds_present = Some(seconds_present);
        result.leap_second = Some(second == 60);
        parser.cfws()?;
        if parser.position != value.len() {
            return None;
        }
        let leap = year % 4 == 0 && (year % 100 != 0 || year % 400 == 0);
        let days = [
            31,
            if leap { 29 } else { 28 },
            31,
            30,
            31,
            30,
            31,
            31,
            30,
            31,
            30,
            31,
        ];
        if year < 1900
            || day < 1
            || day > days[month - 1]
            || hour > 23
            || minute > 59
            || second > 60
        {
            result.parse_status = "invalid_calendar_or_time";
            return None;
        }
        let civil_days = days_from_civil(year, month as u32, day);
        let matches = weekday.map(|weekday| (civil_days + 4).rem_euclid(7) == weekday as i64);
        result.weekday_matches_calendar = matches;
        if matches == Some(false) {
            result.parse_status = "weekday_calendar_mismatch";
            return None;
        }
        let epoch = civil_days
            .checked_mul(86_400)?
            .checked_add(i64::from(hour * 3600 + minute * 60 + second))?
            .checked_sub(i64::from(offset) * 60)?;
        Some((epoch, matches))
    })();
    if let Some((epoch, _)) = parsed {
        result.utc_epoch_seconds = Some(epoch);
        result.parse_status = "valid";
    }
}

struct DateParser<'a> {
    bytes: &'a [u8],
    position: usize,
}

impl<'a> DateParser<'a> {
    fn peek(&self) -> Option<u8> {
        self.bytes.get(self.position).copied()
    }
    fn take(&mut self, byte: u8) -> Option<()> {
        if self.peek()? != byte {
            return None;
        }
        self.position += 1;
        Some(())
    }
    fn digits(&mut self, minimum: usize, maximum: usize) -> Option<u32> {
        let start = self.position;
        let mut value = 0u32;
        while self.peek().is_some_and(|byte| byte.is_ascii_digit()) {
            if self.position - start >= maximum {
                return None;
            }
            value = value
                .checked_mul(10)?
                .checked_add(u32::from(self.peek()? - b'0'))?;
            self.position += 1;
        }
        (self.position - start >= minimum).then_some(value)
    }
    fn word(&mut self) -> Option<&'a [u8]> {
        let start = self.position;
        while self.peek().is_some_and(|byte| byte.is_ascii_alphabetic()) {
            self.position += 1;
        }
        (self.position > start).then_some(&self.bytes[start..self.position])
    }
    fn separator(&mut self) -> Option<()> {
        self.cfws()?.then_some(())
    }
    fn cfws(&mut self) -> Option<bool> {
        let start = self.position;
        loop {
            while matches!(self.peek(), Some(b' ' | b'\t')) {
                self.position += 1;
            }
            if self.peek() != Some(b'(') {
                break;
            }
            self.position += 1;
            let mut depth = 1usize;
            while depth > 0 {
                match self.peek()? {
                    b'(' => {
                        depth += 1;
                        if depth > 32 {
                            return None;
                        }
                    }
                    b')' => depth -= 1,
                    b'\\' => {
                        self.position += 1;
                        self.peek()?;
                    }
                    _ => {}
                }
                self.position += 1;
            }
        }
        Some(self.position > start)
    }
}

fn lookup(value: &[u8], choices: &[&[u8]]) -> Option<usize> {
    choices
        .iter()
        .position(|choice| value.eq_ignore_ascii_case(choice))
}

fn days_from_civil(year: u32, month: u32, day: u32) -> i64 {
    // Gregorian era arithmetic, relative to 1970-01-01; no host timezone or clock.
    let year = i64::from(year) - i64::from(month <= 2);
    let era = year / 400;
    let year_of_era = year - era * 400;
    let month = i64::from(month) + if month > 2 { -3 } else { 9 };
    let day_of_year = (153 * month + 2) / 5 + i64::from(day) - 1;
    era * 146_097 + year_of_era * 365 + year_of_era / 4 - year_of_era / 100 + day_of_year - 719_468
}

fn hex(bytes: &[u8]) -> String {
    const DIGITS: &[u8; 16] = b"0123456789abcdef";
    let mut value = String::with_capacity(bytes.len().saturating_mul(2));
    for byte in bytes {
        value.push(DIGITS[(byte >> 4) as usize] as char);
        value.push(DIGITS[(byte & 15) as usize] as char);
    }
    value
}

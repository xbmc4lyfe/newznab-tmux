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
    pub expires: DateSource,
    pub reported_clock_relations: ReportedClockRelations,
    pub interpretation: &'static str,
}

#[derive(Serialize)]
pub struct ReportedClockRelations {
    pub date_to_injection_date: ClockRelation,
    pub date_to_legacy_nntp_posting_date: ClockRelation,
    pub injection_date_to_legacy_nntp_posting_date: ClockRelation,
    pub date_to_expires: ClockRelation,
    pub injection_date_to_expires: ClockRelation,
    pub legacy_nntp_posting_date_to_expires: ClockRelation,
}

#[derive(Serialize)]
pub struct ClockRelation {
    pub comparison_status: &'static str,
    pub lhs_parse_status: &'static str,
    pub rhs_parse_status: &'static str,
    pub rhs_minus_lhs_seconds: Option<i64>,
    pub lhs_order_relative_to_rhs: Option<&'static str>,
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
    let mut expires = Vec::new();
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
                &mut expires,
            );
            name.clear();
            value.clear();
            raw.clear();
            lines.clear();
            if let Some(colon) = line.iter().position(|byte| *byte == b':') {
                let field_name = &line[..colon];
                if !field_name.eq_ignore_ascii_case(b"Date")
                    && !field_name.eq_ignore_ascii_case(b"Injection-Date")
                    && !field_name.eq_ignore_ascii_case(b"NNTP-Posting-Date")
                    && !field_name.eq_ignore_ascii_case(b"Expires")
                {
                    continue;
                }
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
            &mut expires,
        );
    }
    let date = source(date, exceeded);
    let injection_date = source(injection, exceeded);
    let legacy_nntp_posting_date = source(legacy, exceeded);
    let expires = source(expires, exceeded);
    let reported_clock_relations = ReportedClockRelations {
        date_to_injection_date: clock_relation(&date, &injection_date),
        date_to_legacy_nntp_posting_date: clock_relation(&date, &legacy_nntp_posting_date),
        injection_date_to_legacy_nntp_posting_date: clock_relation(
            &injection_date,
            &legacy_nntp_posting_date,
        ),
        date_to_expires: clock_relation(&date, &expires),
        injection_date_to_expires: clock_relation(&injection_date, &expires),
        legacy_nntp_posting_date_to_expires: clock_relation(&legacy_nntp_posting_date, &expires),
    };
    ArticleDates {
        parser_engine: "nntmux-rust-article-dates",
        schema_version: 2,
        syntax_profile: "Absolute RFC-style dates with four-or-more-digit years, numeric zones, GMT or UT, optional seconds, folded whitespace and bounded nested comments; other obsolete forms are retained as malformed or unsupported",
        date,
        injection_date,
        legacy_nntp_posting_date,
        expires,
        reported_clock_relations,
        interpretation: "Absolute header timestamps describe reported composition or injection clocks; they do not measure uploader bandwidth or establish uploader location. A -0000 zone gives UTC time without information about the originating local timezone.",
    }
}

fn clock_relation(lhs: &DateSource, rhs: &DateSource) -> ClockRelation {
    let delta = match (lhs.utc_epoch_seconds, rhs.utc_epoch_seconds) {
        (Some(lhs), Some(rhs)) => rhs.checked_sub(lhs),
        _ => None,
    };
    ClockRelation {
        comparison_status: if delta.is_some() {
            "compared"
        } else if lhs.utc_epoch_seconds.is_none() || rhs.utc_epoch_seconds.is_none() {
            "source_unavailable"
        } else {
            "epoch_difference_out_of_range"
        },
        lhs_parse_status: lhs.parse_status,
        rhs_parse_status: rhs.parse_status,
        rhs_minus_lhs_seconds: delta,
        lhs_order_relative_to_rhs: delta.map(|delta| match delta.cmp(&0) {
            std::cmp::Ordering::Greater => "before",
            std::cmp::Ordering::Equal => "equal",
            std::cmp::Ordering::Less => "after",
        }),
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
    expires: &mut Vec<DateOccurrence>,
) {
    let output = if name.eq_ignore_ascii_case(b"Date") {
        date
    } else if name.eq_ignore_ascii_case(b"Injection-Date") {
        injection
    } else if name.eq_ignore_ascii_case(b"NNTP-Posting-Date") {
        legacy
    } else if name.eq_ignore_ascii_case(b"Expires") {
        expires
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
                result.timezone_token =
                    Some(String::from_utf8_lossy(&value[zone_start..parser.position]).into_owned());
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

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn retains_date_provenance_around_ignored_folded_fields() {
        let parsed = serde_json::to_value(parse(
            b"Subject: ignored\r\n\tfolded\r\ndAtE: 01 Jan 2024\r\n\t00:00 -0000\r\nX-Trace: ignored\r\n\tfolded too\r\nInjection-Date: 01 Jan 2024 01:00 +0000\r\neXpIrEs: 02 Jan 2024 00:00 +0000\r\n\r\nNNTP-Posting-Date: 01 Jan 2024 00:00 +0000\r\n",
        ))
        .unwrap();
        assert_eq!(
            parsed["date"]["candidates"][0]["source_line_indices"],
            serde_json::json!([3, 4])
        );
        assert_eq!(
            parsed["date"]["candidates"][0]["raw_field_hex"],
            hex(b"dAtE: 01 Jan 2024\r\n\t00:00 -0000\r\n")
        );
        assert_eq!(
            parsed["injection_date"]["candidates"][0]["source_line_indices"],
            serde_json::json!([7])
        );
        assert_eq!(
            parsed["expires"]["candidates"][0]["source_line_indices"],
            serde_json::json!([8])
        );
        assert_eq!(parsed["date"]["utc_epoch_seconds"], 1_704_067_200);
        assert_eq!(parsed["expires"]["utc_epoch_seconds"], 1_704_153_600);
        assert_eq!(
            parsed["legacy_nntp_posting_date"]["parse_status"],
            "missing"
        );
        assert_eq!(
            parsed["reported_clock_relations"]["date_to_injection_date"]["rhs_minus_lhs_seconds"],
            3600
        );
        assert_eq!(
            parsed["reported_clock_relations"]["date_to_expires"]["rhs_minus_lhs_seconds"],
            86_400
        );
        assert_eq!(
            parsed["reported_clock_relations"]["injection_date_to_expires"]["rhs_minus_lhs_seconds"],
            82_800
        );
    }

    #[test]
    fn reports_pairwise_reported_clock_relations() {
        let parsed = serde_json::to_value(parse(
            b"Date: Thu, 01 Jan 1970 00:00:00 +0000\r\nInjection-Date: Thu, 01 Jan 1970 01:00:00 +0000\r\nNNTP-Posting-Date: Thu, 01 Jan 1970 00:00:00 +0000\r\nExpires: Thu, 01 Jan 1970 02:00:00 +0000\r\n",
        ))
        .unwrap();
        for (pair, delta, order) in [
            ("date_to_injection_date", 3600, "before"),
            ("date_to_legacy_nntp_posting_date", 0, "equal"),
            ("injection_date_to_legacy_nntp_posting_date", -3600, "after"),
            ("date_to_expires", 7200, "before"),
            ("injection_date_to_expires", 3600, "before"),
            ("legacy_nntp_posting_date_to_expires", 7200, "before"),
        ] {
            let relation = &parsed["reported_clock_relations"][pair];
            assert_eq!(relation["comparison_status"], "compared", "{pair}");
            assert_eq!(relation["rhs_minus_lhs_seconds"], delta, "{pair}");
            assert_eq!(relation["lhs_order_relative_to_rhs"], order, "{pair}");
        }
    }

    #[test]
    fn compares_absolute_instants_without_inferring_local_timezones() {
        let parsed = serde_json::to_value(parse(
            b"Date: Thu, 01 Jan 1970\r\n 00:00:00 -0000\r\nInjection-Date: Thu, 01 Jan 1970 01:00:00 +0100\r\nNNTP-Posting-Date: Thu, 01 Jan 1970 00:00:00 GMT\r\nExpires: Thu, 01 Jan 1970 00:00:00 UT\r\n",
        ))
        .unwrap();
        assert_eq!(parsed["date"]["parse_status"], "valid");
        for pair in [
            "date_to_injection_date",
            "date_to_legacy_nntp_posting_date",
            "injection_date_to_legacy_nntp_posting_date",
            "date_to_expires",
            "injection_date_to_expires",
            "legacy_nntp_posting_date_to_expires",
        ] {
            let relation = &parsed["reported_clock_relations"][pair];
            assert_eq!(relation["comparison_status"], "compared", "{pair}");
            assert_eq!(relation["rhs_minus_lhs_seconds"], 0, "{pair}");
            assert_eq!(relation["lhs_order_relative_to_rhs"], "equal", "{pair}");
        }
        assert_eq!(
            parsed["date"]["candidates"][0]["timezone_local_offset_unknown"],
            true
        );
    }

    #[test]
    fn withholds_clock_relations_for_unavailable_operands() {
        for (date, status) in [
            ("", "missing"),
            ("Date: bad\r\n", "malformed_or_unsupported_absolute_date"),
            (
                "Date: Thu, 01 Jan 1970 00:00:00 +0000\r\nDate: Thu, 01 Jan 1970 00:00:00 +0000\r\n",
                "duplicate",
            ),
        ] {
            let headers = format!(
                "{date}Injection-Date: Thu, 01 Jan 1970 00:00:00 +0000\r\nNNTP-Posting-Date: Thu, 01 Jan 1970 01:00:00 +0000\r\nExpires: Thu, 01 Jan 1970 02:00:00 +0000\r\n"
            );
            let parsed = serde_json::to_value(parse(headers.as_bytes())).unwrap();
            assert_eq!(parsed["date"]["parse_status"], status);
            for pair in [
                "date_to_injection_date",
                "date_to_legacy_nntp_posting_date",
                "date_to_expires",
            ] {
                let relation = &parsed["reported_clock_relations"][pair];
                assert_eq!(
                    relation["comparison_status"], "source_unavailable",
                    "{status}: {pair}"
                );
                assert!(relation["rhs_minus_lhs_seconds"].is_null());
                assert!(relation["lhs_order_relative_to_rhs"].is_null());
            }
            assert_eq!(
                parsed["reported_clock_relations"]["injection_date_to_legacy_nntp_posting_date"]["rhs_minus_lhs_seconds"],
                3600
            );
            assert_eq!(
                parsed["reported_clock_relations"]["injection_date_to_expires"]["rhs_minus_lhs_seconds"],
                7200
            );
            assert_eq!(
                parsed["reported_clock_relations"]["legacy_nntp_posting_date_to_expires"]["rhs_minus_lhs_seconds"],
                3600
            );
        }
        let parsed = serde_json::to_value(parse(&vec![b'x'; MAX_HEADER_BYTES + 1])).unwrap();
        for pair in [
            "date_to_injection_date",
            "date_to_legacy_nntp_posting_date",
            "injection_date_to_legacy_nntp_posting_date",
            "date_to_expires",
            "injection_date_to_expires",
            "legacy_nntp_posting_date_to_expires",
        ] {
            let relation = &parsed["reported_clock_relations"][pair];
            assert_eq!(relation["comparison_status"], "source_unavailable");
            assert!(relation["rhs_minus_lhs_seconds"].is_null());
        }
    }

    #[test]
    fn qualifies_each_clock_pair_independently_of_other_unavailable_sources() {
        let fields = ["Date", "Injection-Date", "NNTP-Posting-Date", "Expires"];
        let pairs = [
            ("date_to_injection_date", 0, 1),
            ("date_to_legacy_nntp_posting_date", 0, 2),
            ("injection_date_to_legacy_nntp_posting_date", 1, 2),
            ("date_to_expires", 0, 3),
            ("injection_date_to_expires", 1, 3),
            ("legacy_nntp_posting_date_to_expires", 2, 3),
        ];
        for unavailable in 0..fields.len() {
            for replacement in ["", "bad", "duplicate"] {
                let mut headers = String::new();
                for (index, field) in fields.iter().enumerate() {
                    if index != unavailable || replacement == "duplicate" {
                        headers.push_str(&format!("{field}: Thu, 01 Jan 1970 00:00:00 +0000\r\n"));
                    }
                    if index == unavailable && !replacement.is_empty() {
                        headers.push_str(&format!(
                            "{field}: {}\r\n",
                            if replacement == "duplicate" {
                                "Thu, 01 Jan 1970 00:00:00 +0000"
                            } else {
                                replacement
                            }
                        ));
                    }
                }
                let parsed = serde_json::to_value(parse(headers.as_bytes())).unwrap();
                for (pair, lhs, rhs) in pairs {
                    let relation = &parsed["reported_clock_relations"][pair];
                    let unavailable_pair = lhs == unavailable || rhs == unavailable;
                    assert_eq!(
                        relation["comparison_status"],
                        if unavailable_pair {
                            "source_unavailable"
                        } else {
                            "compared"
                        },
                        "{} {replacement}: {pair}",
                        fields[unavailable]
                    );
                    if unavailable_pair {
                        assert!(relation["rhs_minus_lhs_seconds"].is_null());
                        assert!(relation["lhs_order_relative_to_rhs"].is_null());
                    } else {
                        assert_eq!(relation["rhs_minus_lhs_seconds"], 0);
                        assert_eq!(relation["lhs_order_relative_to_rhs"], "equal");
                    }
                }
            }
        }
    }

    #[test]
    fn keeps_unaffected_clock_pair_when_one_source_exhausts_its_budget() {
        let fields = ["Date", "Injection-Date", "NNTP-Posting-Date", "Expires"];
        let pairs = [
            ("date_to_injection_date", 0, 1, 3600),
            ("date_to_legacy_nntp_posting_date", 0, 2, 10800),
            ("injection_date_to_legacy_nntp_posting_date", 1, 2, 7200),
            ("date_to_expires", 0, 3, 21600),
            ("injection_date_to_expires", 1, 3, 18000),
            ("legacy_nntp_posting_date_to_expires", 2, 3, 10800),
        ];
        for exhausted in 0..fields.len() {
            let mut headers = String::new();
            for (index, field) in fields.iter().enumerate() {
                let hour = [0, 1, 3, 6][index];
                for _ in 0..if index == exhausted {
                    MAX_OCCURRENCES
                } else {
                    1
                } {
                    headers.push_str(&format!(
                        "{field}: Thu, 01 Jan 1970 {hour:02}:00:00 +0000\r\n"
                    ));
                }
            }
            let parsed = serde_json::to_value(parse(headers.as_bytes())).unwrap();
            let source = [
                "date",
                "injection_date",
                "legacy_nntp_posting_date",
                "expires",
            ][exhausted];
            assert_eq!(parsed[source]["parse_status"], "budget_exceeded");
            for (pair, lhs, rhs, delta) in pairs {
                let relation = &parsed["reported_clock_relations"][pair];
                if lhs == exhausted || rhs == exhausted {
                    assert_eq!(relation["comparison_status"], "source_unavailable");
                    assert!(relation["rhs_minus_lhs_seconds"].is_null());
                    assert!(relation["lhs_order_relative_to_rhs"].is_null());
                } else {
                    assert_eq!(relation["comparison_status"], "compared");
                    assert_eq!(relation["rhs_minus_lhs_seconds"], delta);
                    assert_eq!(relation["lhs_order_relative_to_rhs"], "before");
                }
            }
        }
    }

    #[test]
    fn compares_distinct_clock_pairs_across_the_epoch() {
        let parsed = serde_json::to_value(parse(
            b"Date: Wed, 31 Dec 1969 23:59:00 +0000\r\nInjection-Date: Thu, 01 Jan 1970 00:01:00 +0000\r\nNNTP-Posting-Date: Wed, 31 Dec 1969 23:58:00 +0000\r\nExpires: Wed, 31 Dec 1969 23:57:00 +0000\r\n",
        ))
        .unwrap();
        for (pair, delta, order) in [
            ("date_to_injection_date", 120, "before"),
            ("date_to_legacy_nntp_posting_date", -60, "after"),
            ("injection_date_to_legacy_nntp_posting_date", -180, "after"),
            ("date_to_expires", -120, "after"),
            ("injection_date_to_expires", -240, "after"),
            ("legacy_nntp_posting_date_to_expires", -60, "after"),
        ] {
            let relation = &parsed["reported_clock_relations"][pair];
            assert_eq!(relation["comparison_status"], "compared");
            assert_eq!(relation["rhs_minus_lhs_seconds"], delta);
            assert_eq!(relation["lhs_order_relative_to_rhs"], order);
        }
    }

    #[test]
    fn flushes_the_final_date_once_at_the_header_body_boundary() {
        let parsed =
            parse(b"Subject: sample\r\nDate: Thu, 01 Jan 1970 00:00:00 +0000\r\n\r\nbody\r\n");
        assert_eq!(parsed.date.occurrence_count, 1);
        assert_eq!(parsed.date.parse_status, "valid");
        assert_eq!(parsed.date.utc_epoch_seconds, Some(0));
    }
}

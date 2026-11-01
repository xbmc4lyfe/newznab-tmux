use serde::Serialize;

#[derive(Debug, Serialize)]
pub struct LinesBodyRelationship {
    pub parser_engine: &'static str,
    pub schema_version: u8,
    pub status: &'static str,
    pub comparison_basis: &'static str,
    pub lines_field_occurrence_count: usize,
    pub field_occurrences_complete: bool,
    pub declared_body_line_count: Option<u64>,
    pub observed_body_line_count: Option<usize>,
    pub matches: Option<bool>,
    pub absolute_difference: Option<u64>,
}

pub fn compare_lines_to_body(headers: &[u8], body: &[u8]) -> LinesBodyRelationship {
    let parsed_headers = crate::nntp_headers::parse(headers);
    let fields_complete = parsed_headers.status == "complete"
        && !parsed_headers.field_occurrences_truncated
        && parsed_headers.malformed_line_count == 0
        && parsed_headers.orphan_continuation_count == 0;
    let lines_fields = parsed_headers
        .fields
        .iter()
        .filter(|field| field.normalized_name.as_deref() == Some("lines"))
        .collect::<Vec<_>>();
    let mut relationship = LinesBodyRelationship {
        parser_engine: "nntmux-rust-nntp-body",
        schema_version: 1,
        status: "unavailable",
        comparison_basis: "Unique RFC 5536 Lines value in the completed HEAD/article header field list versus complete BODY CRLF separator count after dot unstuffing",
        lines_field_occurrence_count: lines_fields.len(),
        field_occurrences_complete: fields_complete,
        declared_body_line_count: None,
        observed_body_line_count: parse(body).body_line_count,
        matches: None,
        absolute_difference: None,
    };

    if !fields_complete {
        relationship.status = "header_occurrences_incomplete";
        return relationship;
    }
    let Some(field) = lines_fields.first() else {
        relationship.status = "missing_lines";
        return relationship;
    };
    if lines_fields.len() != 1 {
        relationship.status = "ambiguous_lines";
        return relationship;
    }
    let Some(lines) = field.lines.as_ref() else {
        relationship.status = "malformed_lines";
        return relationship;
    };
    if lines.status != "complete" {
        relationship.status = match lines.status {
            "numeric_overflow" => "numeric_overflow",
            "non_crlf_terminated" => "malformed_lines",
            _ => "malformed_lines",
        };
        return relationship;
    }
    let Some(declared) = lines.declared_body_line_count else {
        relationship.status = "malformed_lines";
        return relationship;
    };
    relationship.declared_body_line_count = Some(declared);
    let Some(observed) = relationship.observed_body_line_count else {
        relationship.status = "body_framing_unavailable";
        return relationship;
    };
    let Ok(observed) = u64::try_from(observed) else {
        relationship.status = "observed_count_overflow";
        return relationship;
    };
    relationship.matches = Some(declared == observed);
    relationship.absolute_difference = Some(declared.abs_diff(observed));
    relationship.status = if declared == observed {
        "match"
    } else {
        "mismatch"
    };
    relationship
}

#[derive(Debug, Serialize)]
pub struct NntpBodyMetadata {
    pub parser_engine: &'static str,
    pub schema_version: u8,
    pub status: &'static str,
    pub body_octets_after_dot_unstuffing: usize,
    pub crlf_line_break_count: usize,
    pub lf_line_break_count: usize,
    pub bare_lf_count: usize,
    pub bare_cr_count: usize,
    pub nul_byte_count: usize,
    pub body_line_count: Option<usize>,
    pub observed_lf_delimited_line_count: usize,
    pub unterminated_tail_byte_count: usize,
    pub final_line_terminated: bool,
    pub measurement_basis: &'static str,
}

pub fn parse(body: &[u8]) -> NntpBodyMetadata {
    let crlf_line_break_count = body.windows(2).filter(|window| *window == b"\r\n").count();
    let lf_line_break_count = body.iter().filter(|&&byte| byte == b'\n').count();
    let bare_lf_count = body
        .iter()
        .enumerate()
        .filter(|(index, byte)| **byte == b'\n' && (*index == 0 || body[*index - 1] != b'\r'))
        .count();
    let bare_cr_count = body
        .iter()
        .enumerate()
        .filter(|(index, byte)| {
            **byte == b'\r' && (*index + 1 == body.len() || body[*index + 1] != b'\n')
        })
        .count();
    let final_line_terminated = body.is_empty() || body.ends_with(b"\r\n");
    let unterminated_tail_byte_count = body
        .iter()
        .rposition(|&byte| byte == b'\n')
        .map_or(body.len(), |last_lf| body.len() - last_lf - 1);
    let observed_lf_delimited_line_count =
        lf_line_break_count + usize::from(!body.is_empty() && !body.ends_with(b"\n"));
    let complete_crlf_framing = bare_lf_count == 0 && bare_cr_count == 0 && final_line_terminated;
    let status = if body.is_empty() {
        "empty"
    } else if bare_lf_count > 0 || bare_cr_count > 0 {
        "non_crlf_line_endings"
    } else if !final_line_terminated {
        "unterminated_final_line"
    } else {
        "complete_crlf"
    };

    NntpBodyMetadata {
        parser_engine: "nntmux-rust-nntp-body",
        schema_version: 1,
        status,
        body_octets_after_dot_unstuffing: body.len(),
        crlf_line_break_count,
        lf_line_break_count,
        bare_lf_count,
        bare_cr_count,
        nul_byte_count: body.iter().filter(|&&byte| byte == 0).count(),
        body_line_count: complete_crlf_framing.then_some(crlf_line_break_count),
        observed_lf_delimited_line_count,
        unterminated_tail_byte_count,
        final_line_terminated,
        measurement_basis: "Raw NNTP BODY octets after one dot-unstuffing pass; excludes the NNTP dot terminator.",
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    fn relate(headers: &[u8], body: &[u8]) -> LinesBodyRelationship {
        compare_lines_to_body(headers, body)
    }

    #[test]
    fn counts_blank_and_trailing_blank_body_lines() {
        for body in [b"a\r\n\r\n".as_slice(), b"\r\n\r\n".as_slice()] {
            let metadata = parse(body);
            assert_eq!(metadata.status, "complete_crlf");
            assert_eq!(metadata.body_line_count, Some(2));
        }
    }

    #[test]
    fn distinguishes_empty_single_line_and_unterminated_body() {
        assert_eq!(parse(b"").body_line_count, Some(0));
        assert_eq!(parse(b"one\r\n").body_line_count, Some(1));
        let unterminated = parse(b"one");
        assert_eq!(unterminated.status, "unterminated_final_line");
        assert_eq!(unterminated.body_line_count, None);
        assert_eq!(unterminated.observed_lf_delimited_line_count, 1);
        assert_eq!(unterminated.unterminated_tail_byte_count, 3);
    }

    #[test]
    fn withholds_rfc_line_count_for_bare_or_mixed_line_endings() {
        for body in [b"one\n\n".as_slice(), b"one\r\ntwo\n".as_slice()] {
            let metadata = parse(body);
            assert_eq!(metadata.status, "non_crlf_line_endings");
            assert_eq!(metadata.body_line_count, None);
        }

        let metadata = parse(b"one\rtwo");
        assert_eq!(metadata.bare_cr_count, 1);
        assert_eq!(metadata.body_line_count, None);

        let metadata = parse(b".\r\n\0");
        assert_eq!(metadata.nul_byte_count, 1);
        assert_eq!(metadata.observed_lf_delimited_line_count, 2);
    }

    #[test]
    fn relates_unique_lines_field_to_complete_body_crlf_count() {
        let headers = b"Message-ID: <a@b>\r\nLines:  2 \t\r\n\r\n";
        let match_result = relate(headers, b"one\r\n\r\n");
        assert_eq!(match_result.status, "match");
        assert_eq!(match_result.declared_body_line_count, Some(2));
        assert_eq!(match_result.observed_body_line_count, Some(2));
        assert_eq!(match_result.absolute_difference, Some(0));

        let mismatch = relate(headers, b"one\r\n");
        assert_eq!(mismatch.status, "mismatch");
        assert_eq!(mismatch.matches, Some(false));
        assert_eq!(mismatch.absolute_difference, Some(1));
    }

    #[test]
    fn withholds_lines_relationship_for_missing_duplicate_or_malformed_fields() {
        for (headers, status) in [
            (b"Subject: x\r\n\r\n".as_slice(), "missing_lines"),
            (b"Lines: 1\r\nLines: 1\r\n\r\n", "ambiguous_lines"),
            (b"Lines:\t1\r\n\r\n", "malformed_lines"),
            (b"Lines: 1 comment\r\n\r\n", "malformed_lines"),
            (b"Lines: 18446744073709551616\r\n\r\n", "numeric_overflow"),
            (b"Lines: 1\n\n", "malformed_lines"),
        ] {
            assert_eq!(relate(headers, b"x\r\n").status, status);
        }
        let overflow_metadata = crate::nntp_headers::parse(b"Lines: 18446744073709551616\r\n");
        let overflow_lines = overflow_metadata.fields[0].lines.as_ref().unwrap();
        assert_eq!(overflow_lines.status, "numeric_overflow");
        assert!(overflow_lines.syntax_valid);

        assert_eq!(relate(b"Lines: 1\r\n", b"x\r\n").status, "match");
        let mut oversized = vec![b'x'; crate::nntp_headers::MAX_HEADER_BYTES + 1];
        oversized[..9].copy_from_slice(b"Lines: 1\r");
        assert_eq!(
            relate(&oversized, b"x\r\n").status,
            "header_occurrences_incomplete"
        );
    }

    #[test]
    fn parses_long_valid_lines_values_beyond_generic_preview_limit() {
        let headers = format!("Lines: {}1\r\n", " ".repeat(300));
        let relationship = relate(headers.as_bytes(), b"x\r\n");
        assert_eq!(relationship.status, "match");
        assert_eq!(relationship.declared_body_line_count, Some(1));
    }

    #[test]
    fn withholds_comparison_for_non_crlf_or_unterminated_bodies() {
        let headers = b"Lines: 1\r\n\r\n";
        for body in [b"x\n".as_slice(), b"x".as_slice()] {
            let relationship = relate(headers, body);
            assert_eq!(relationship.status, "body_framing_unavailable");
            assert_eq!(relationship.matches, None);
        }
    }
}

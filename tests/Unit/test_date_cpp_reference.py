"""Literal ctypes checks for the standalone C++ NNTP date reference ABI."""

from __future__ import annotations

import ctypes
import hashlib
import json
import os
from pathlib import Path
import re
import unittest


class CppDateReferenceSourceTest(unittest.TestCase):
    def test_genuine_native_parser_bodies_match_the_pinned_upstream_copy(self) -> None:
        source = (Path(__file__).resolve().parents[2] / "scripts" / "benchmark-date-cpp.cpp").read_text()
        expected = {'is_leap': '351efa0a9750d6e4ebf10ed69f97854eb0f34dfd9950daf8303695c8c7d10d76', 'days_from_0': 'd5105c22178b97072dce1fff5637145b502d77bdf5e3a149532fecabca32efdf', 'days_from_1970': '92a6505b8890c032433a68b3d1270d7bcd5789c3f46b8c39d961faac55f6803c', 'days_from_1jan': 'fadfb81b77998872ac2b9ed06d65f7cade0e1ffd246553aa18769a38a04dd498', 'internal_timegm': 'bd366dbe48eb61a84488797789d59d48d8fdf6ffc902583f22cd3245938edd36', 'Util::Timegm': 'a2e81546c66356a3253156c73401f23da750e8276363a30a42097882c392c8da', 'WebUtil::ParseRfc822DateTime': '994906dd6eb8f15a16df5c2576e93aed0b80d66e89c546a1ca6022c3815ee51f'}
        for name, expected_hash in expected.items():
            with self.subTest(function=name):
                pattern = r"\b" + re.escape(name) + r"\([^;{}]*\)\s*\{"
                match = re.search(pattern, source)
                self.assertIsNotNone(match)
                begin = source.index("{", match.start())
                depth, end = 1, begin + 1
                while depth:
                    depth += (source[end] == "{") - (source[end] == "}")
                    end += 1
                self.assertEqual(hashlib.sha256(source[begin:end].encode()).hexdigest(), expected_hash)
        self.assertNotIn("nzbget_rs_parse_rfc822_date_time", source)


class CppDateReferenceTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        library_path = os.environ.get("NNTMUX_DATE_CPP_LIBRARY")
        if not library_path:
            raise unittest.SkipTest("NNTMUX_DATE_CPP_LIBRARY is not set")

        cls.library = ctypes.CDLL(library_path)
        cls.parse_dates = cls.library.nntmux_article_dates_v1_json
        cls.parse_dates.argtypes = (ctypes.c_void_p, ctypes.c_size_t)
        cls.parse_dates.restype = ctypes.c_void_p
        cls.free_string = cls.library.nntmux_metadata_string_free
        cls.free_string.argtypes = (ctypes.c_void_p,)
        cls.free_string.restype = None

    def parse(self, payload: bytes) -> dict:
        storage = ctypes.create_string_buffer(payload, len(payload) + 1)
        output = self.parse_dates(ctypes.cast(storage, ctypes.c_void_p), len(payload))
        self.assertTrue(output, "C++ date parser returned null for an in-bounds input")
        try:
            metadata = json.loads(ctypes.string_at(output))
        finally:
            self.free_string(output)

        self.assertEqual(metadata["parser_engine"], "nntmux-cpp-nzbget-date-reference")
        self.assertEqual(metadata["schema_version"], 2)
        return metadata

    @staticmethod
    def source_projection(metadata: dict) -> dict:
        return {
            name: {
                "parse_status": metadata[name]["parse_status"],
                "utc_epoch_seconds": metadata[name]["utc_epoch_seconds"],
                "candidates": [
                    {
                        "parse_status": candidate["parse_status"],
                        "utc_epoch_seconds": candidate["utc_epoch_seconds"],
                        "source_line_indices": candidate["source_line_indices"],
                    }
                    for candidate in metadata[name]["candidates"]
                ],
            }
            for name in ("date", "injection_date", "legacy_nntp_posting_date", "expires")
        }

    @staticmethod
    def relation_projection(metadata: dict) -> dict:
        return {
            name: {
                key: metadata["reported_clock_relations"][name][key]
                for key in (
                    "comparison_status",
                    "lhs_parse_status",
                    "rhs_parse_status",
                    "rhs_minus_lhs_seconds",
                    "lhs_order_relative_to_rhs",
                )
            }
            for name in (
                "date_to_injection_date",
                "date_to_legacy_nntp_posting_date",
                "injection_date_to_legacy_nntp_posting_date",
                "date_to_expires",
                "injection_date_to_expires",
                "legacy_nntp_posting_date_to_expires",
            )
        }

    @staticmethod
    def candidate(status: str, epoch: int | None, lines: list[int]) -> dict:
        return {
            "parse_status": status,
            "utc_epoch_seconds": epoch,
            "source_line_indices": lines,
        }

    @staticmethod
    def relation(status: str, lhs: str, rhs: str, delta: int | None, order: str | None) -> dict:
        return {
            "comparison_status": status,
            "lhs_parse_status": lhs,
            "rhs_parse_status": rhs,
            "rhs_minus_lhs_seconds": delta,
            "lhs_order_relative_to_rhs": order,
        }

    @staticmethod
    def full_candidate(raw: bytes, unfolded: bytes, lines: list[int], status: str, epoch: int | None,
                       zone: str | None = None, offset: int | None = None, unknown: bool | None = None,
                       seconds: bool | None = None, leap: bool | None = None,
                       weekday: bool | None = None) -> dict:
        # Raw bytes and every semantic value are supplied by literal oracles;
        # this helper neither parses dates nor consults adapter output.
        return {
            "source_line_indices": lines,
            "raw_field_hex": raw.hex(),
            "unfolded_value_hex": unfolded.hex(),
            "parse_status": status,
            "utc_epoch_seconds": epoch,
            "timezone_token": zone,
            "timezone_offset_minutes": offset,
            "timezone_local_offset_unknown": unknown,
            "seconds_present": seconds,
            "leap_second": leap,
            "weekday_matches_calendar": weekday,
            "epoch_basis": "Gregorian calendar and POSIX seconds; second 60 maps to the following POSIX second without asserting a historical leap-second event",
        }

    @staticmethod
    def full_source(status: str, epoch: int | None, candidates: list[dict]) -> dict:
        return {
            "parse_status": status,
            "occurrence_count": len(candidates),
            "occurrence_count_basis": "retained candidates; the total may be larger when budget_exceeded",
            "candidates": candidates,
            "utc_epoch_seconds": epoch,
        }

    def assert_full(self, payload: bytes, sources: dict, relations: dict) -> None:
        expected = {
            "parser_engine": "nntmux-cpp-nzbget-date-reference",
            "schema_version": 2,
            "syntax_profile": "Restricted equal-output reference: years 1900-9999; numeric zones, GMT/UT, optional seconds and folded whitespace; comments excluded; not full Rust grammar equivalence",
            **sources,
            "reported_clock_relations": relations,
            "interpretation": "Absolute header timestamps describe reported composition or injection clocks; they do not measure uploader bandwidth or establish uploader location. A -0000 zone gives UTC time without information about the originating local timezone.",
        }
        self.assertEqual(self.parse(payload), expected)

    def test_full_evidence_for_all_four_benchmark_strata(self) -> None:
        valid, missing = "valid", "missing"
        empty = self.full_source(missing, None, [])
        ordinary = (
            b"Date: Mon, 01 Jan 2024 00:00:00 +0000\r\n"
            b"Injection-Date: 01 Jan 2024 01:00:00 +0000\r\n"
            b"NNTP-Posting-Date: 31 Dec 2023 19:00:00 -0500\r\n"
            b"Expires: 02 Jan 2024 00:00:00 +0000\r\n"
        )
        self.assert_full(ordinary, {
            "date": self.full_source(valid, 1704067200, [self.full_candidate(
                b"Date: Mon, 01 Jan 2024 00:00:00 +0000\r\n", b" Mon, 01 Jan 2024 00:00:00 +0000", [1],
                valid, 1704067200, "+0000", 0, False, True, False, True)]),
            "injection_date": self.full_source(valid, 1704070800, [self.full_candidate(
                b"Injection-Date: 01 Jan 2024 01:00:00 +0000\r\n", b" 01 Jan 2024 01:00:00 +0000", [2],
                valid, 1704070800, "+0000", 0, False, True, False)]),
            "legacy_nntp_posting_date": self.full_source(valid, 1704067200, [self.full_candidate(
                b"NNTP-Posting-Date: 31 Dec 2023 19:00:00 -0500\r\n", b" 31 Dec 2023 19:00:00 -0500", [3],
                valid, 1704067200, "-0500", -300, False, True, False)]),
            "expires": self.full_source(valid, 1704153600, [self.full_candidate(
                b"Expires: 02 Jan 2024 00:00:00 +0000\r\n", b" 02 Jan 2024 00:00:00 +0000", [4],
                valid, 1704153600, "+0000", 0, False, True, False)]),
        }, {
            "date_to_injection_date": self.relation("compared", valid, valid, 3600, "before"),
            "date_to_legacy_nntp_posting_date": self.relation("compared", valid, valid, 0, "equal"),
            "injection_date_to_legacy_nntp_posting_date": self.relation("compared", valid, valid, -3600, "after"),
            "date_to_expires": self.relation("compared", valid, valid, 86400, "before"),
            "injection_date_to_expires": self.relation("compared", valid, valid, 82800, "before"),
            "legacy_nntp_posting_date_to_expires": self.relation("compared", valid, valid, 86400, "before"),
        })
        folded = (b"Subject: folded offline date\r\ndAtE: 01 Jan 2024\r\n\t00:00 -0000\r\n"
                  b"Injection-Date: 01 Jan 2024 00:00:00 GMT\r\n")
        self.assert_full(folded, {
            "date": self.full_source(valid, 1704067200, [self.full_candidate(
                b"dAtE: 01 Jan 2024\r\n\t00:00 -0000\r\n", b" 01 Jan 2024\t00:00 -0000", [2, 3],
                valid, 1704067200, "-0000", 0, True, False, False)]),
            "injection_date": self.full_source(valid, 1704067200, [self.full_candidate(
                b"Injection-Date: 01 Jan 2024 00:00:00 GMT\r\n", b" 01 Jan 2024 00:00:00 GMT", [4],
                valid, 1704067200, "GMT", 0, False, True, False)]),
            "legacy_nntp_posting_date": empty,
            "expires": empty,
        }, {
            "date_to_injection_date": self.relation("compared", valid, valid, 0, "equal"),
            "date_to_legacy_nntp_posting_date": self.relation("source_unavailable", valid, missing, None, None),
            "injection_date_to_legacy_nntp_posting_date": self.relation("source_unavailable", valid, missing, None, None),
            "date_to_expires": self.relation("source_unavailable", valid, missing, None, None),
            "injection_date_to_expires": self.relation("source_unavailable", valid, missing, None, None),
            "legacy_nntp_posting_date_to_expires": self.relation("source_unavailable", missing, missing, None, None),
        })
        raw = b"Date: 01 Jan 2024 00:00:00 +0000\r\n"
        duplicates = raw * 257
        duplicates += b"X-Padding: " + b"p" * (65536 - len(duplicates) - 13) + b"\r\n"
        self.assertEqual(len(duplicates), 65536)
        self.assert_full(duplicates, {
            "date": self.full_source("budget_exceeded", None, [self.full_candidate(
                raw, b" 01 Jan 2024 00:00:00 +0000", [line], valid, 1704067200,
                "+0000", 0, False, True, False) for line in range(1, 257)]),
            "injection_date": empty,
            "legacy_nntp_posting_date": empty,
            "expires": empty,
        }, {
            "date_to_injection_date": self.relation("source_unavailable", "budget_exceeded", missing, None, None),
            "date_to_legacy_nntp_posting_date": self.relation("source_unavailable", "budget_exceeded", missing, None, None),
            "injection_date_to_legacy_nntp_posting_date": self.relation("source_unavailable", missing, missing, None, None),
            "date_to_expires": self.relation("source_unavailable", "budget_exceeded", missing, None, None),
            "injection_date_to_expires": self.relation("source_unavailable", missing, missing, None, None),
            "legacy_nntp_posting_date_to_expires": self.relation("source_unavailable", missing, missing, None, None),
        })
        calendar, zone, octet = "invalid_calendar_or_time", "unsupported_absolute_timezone", "invalid_octet_or_folding"
        self.assert_full(
            b"Date: 31 Feb 2024 00:00:00 +0000\r\nInjection-Date: 01 Jan 2024 00:00:00 PST\r\n"
            b"NNTP-Posting-Date: 01 Jan 2024 00:00:00 +0000\0\r\n", {
                "date": self.full_source(calendar, None, [self.full_candidate(
                    b"Date: 31 Feb 2024 00:00:00 +0000\r\n", b" 31 Feb 2024 00:00:00 +0000", [1],
                    calendar, None, "+0000", 0, False, True, False)]),
                "injection_date": self.full_source(zone, None, [self.full_candidate(
                    b"Injection-Date: 01 Jan 2024 00:00:00 PST\r\n", b" 01 Jan 2024 00:00:00 PST", [2],
                    zone, None, "PST")]),
                "legacy_nntp_posting_date": self.full_source(octet, None, [self.full_candidate(
                    b"NNTP-Posting-Date: 01 Jan 2024 00:00:00 +0000\0\r\n", b" 01 Jan 2024 00:00:00 +0000\0", [3],
                    octet, None)]),
                "expires": empty,
            }, {
                "date_to_injection_date": self.relation("source_unavailable", calendar, zone, None, None),
                "date_to_legacy_nntp_posting_date": self.relation("source_unavailable", calendar, octet, None, None),
                "injection_date_to_legacy_nntp_posting_date": self.relation("source_unavailable", zone, octet, None, None),
                "date_to_expires": self.relation("source_unavailable", calendar, missing, None, None),
                "injection_date_to_expires": self.relation("source_unavailable", zone, missing, None, None),
                "legacy_nntp_posting_date_to_expires": self.relation("source_unavailable", octet, missing, None, None),
            })

    def test_full_positive_boundary_evidence(self) -> None:
        cases = [
            (b"Wed, 31 Dec 1969 23:59:00 +0000", -60, "+0000", 0, False, True, False, True),
            (b"Thu, 01 Jan 1970 00:00:60 +0000", 60, "+0000", 0, False, True, True, True),
            (b"01 Jan 1970 00:00 UT", 0, "UT", 0, False, False, False, None),
            (b"Thu, 01 Jan 1970 01:00:00 +0100", 0, "+0100", 60, False, True, False, True),
            (b"01 Jan 1900 00:00:00 +0000", -2208988800, "+0000", 0, False, True, False, None),
            (b"31 Dec 9999 23:59:59 +0000", 253402300799, "+0000", 0, False, True, False, None),
        ]
        for value, epoch, zone, offset, unknown, seconds, leap, weekday in cases:
            with self.subTest(value=value):
                raw = b"Date: " + value + b"\r\n"
                metadata = self.parse(raw)
                self.assertEqual(metadata["date"], self.full_source("valid", epoch, [self.full_candidate(
                    raw, b" " + value, [1], "valid", epoch, zone, offset, unknown, seconds, leap, weekday)]))

    def assert_projection(self, payload: bytes, sources: dict, relations: dict) -> dict:
        metadata = self.parse(payload)
        self.assertEqual(self.source_projection(metadata), sources)
        for name, source in sources.items():
            self.assertEqual(metadata[name]["occurrence_count"], len(source["candidates"]))
        self.assertEqual(self.relation_projection(metadata), relations)
        return metadata

    def test_semantic_branches_are_not_answers_special_cased_for_timing_fixtures(self) -> None:
        cases = [
            (b"Fri, 01 Jan 1970 00:00:00 +0000", "weekday_calendar_mismatch", None),
            (b"Thu, 01 Jan 1970 00:00:60 +0000", "valid", 60),
            (b"Thu, 01 Jan 1970 00:00:61 +0000", "invalid_calendar_or_time", None),
            (b"01 Jan 2024 00:00:00 XYZ", "unsupported_absolute_timezone", None),
            (b"01 Jan 2024 00:00:00 +2400", "malformed_or_unsupported_absolute_date", None),
            # These two lie outside the declared 1900-9999/no-comments reference profile.
            (b"01 Jan 10000 00:00:00 +0000", "malformed_or_unsupported_absolute_date", None),
            (b"01 Jan 2024 (comment) 00:00:00 +0000", "malformed_or_unsupported_absolute_date", None),
        ]
        for value, status, epoch in cases:
            with self.subTest(value=value):
                metadata = self.parse(b"Date: " + value + b"\r\n")
                self.assertEqual(metadata["date"]["parse_status"], status)
                self.assertEqual(metadata["date"]["utc_epoch_seconds"], epoch)
                self.assertEqual(metadata["date"]["occurrence_count"], 1)
                self.assertEqual(metadata["date"]["candidates"][0]["source_line_indices"], [1])
                self.assertEqual(metadata["reported_clock_relations"]["date_to_injection_date"],
                                 self.relation("source_unavailable", status, "missing", None, None))
                self.assertEqual(metadata["reported_clock_relations"]["date_to_expires"],
                                 self.relation("source_unavailable", status, "missing", None, None))

    def test_exact_occurrence_limit_and_duplicates_are_distinct(self) -> None:
        for count, status in [(255, "duplicate"), (256, "budget_exceeded")]:
            with self.subTest(count=count):
                metadata = self.parse(b"Date: 01 Jan 2024 00:00 +0000\r\n" * count)
                self.assertEqual(metadata["date"]["occurrence_count"], count)
                self.assertEqual(len(metadata["date"]["candidates"]), count)
                self.assertEqual(metadata["date"]["parse_status"], status)
                self.assertIsNone(metadata["date"]["utc_epoch_seconds"])

    def test_one_duplicate_source_does_not_disable_the_other_pair(self) -> None:
        metadata = self.parse(b"Date: 01 Jan 1970 00:00:00 +0000\r\n" * 2
                              + b"Injection-Date: 01 Jan 1970 00:00:01 +0000\r\n"
                              + b"NNTP-Posting-Date: 01 Jan 1970 00:00:02 +0000\r\n"
                              + b"Expires: 01 Jan 1970 00:00:05 +0000\r\n")
        self.assertEqual(metadata["date"]["parse_status"], "duplicate")
        self.assertEqual(metadata["reported_clock_relations"]["date_to_injection_date"],
                         self.relation("source_unavailable", "duplicate", "valid", None, None))
        self.assertEqual(metadata["reported_clock_relations"]["date_to_expires"],
                         self.relation("source_unavailable", "duplicate", "valid", None, None))
        self.assertEqual(metadata["reported_clock_relations"]["injection_date_to_legacy_nntp_posting_date"],
                         self.relation("compared", "valid", "valid", 1, "before"))
        self.assertEqual(metadata["reported_clock_relations"]["injection_date_to_expires"],
                         self.relation("compared", "valid", "valid", 4, "before"))
        self.assertEqual(metadata["reported_clock_relations"]["legacy_nntp_posting_date_to_expires"],
                         self.relation("compared", "valid", "valid", 3, "before"))

    def test_ordinary_offsets_produce_all_three_literal_relations(self) -> None:
        valid = "valid"
        self.assert_projection(
            b"Date: Thu, 01 Jan 1970 00:00:00 +0000\r\n"
            b"Injection-Date: Thu, 01 Jan 1970 01:00:00 +0000\r\n"
            b"NNTP-Posting-Date: Wed, 31 Dec 1969 19:00:00 -0500\r\n"
            b"Expires: Thu, 01 Jan 1970 02:00:00 +0000\r\n",
            {
                "date": {"parse_status": valid, "utc_epoch_seconds": 0, "candidates": [self.candidate(valid, 0, [1])]},
                "injection_date": {"parse_status": valid, "utc_epoch_seconds": 3600, "candidates": [self.candidate(valid, 3600, [2])]},
                "legacy_nntp_posting_date": {"parse_status": valid, "utc_epoch_seconds": 0, "candidates": [self.candidate(valid, 0, [3])]},
                "expires": {"parse_status": valid, "utc_epoch_seconds": 7200, "candidates": [self.candidate(valid, 7200, [4])]},
            },
            {
                "date_to_injection_date": self.relation("compared", valid, valid, 3600, "before"),
                "date_to_legacy_nntp_posting_date": self.relation("compared", valid, valid, 0, "equal"),
                "injection_date_to_legacy_nntp_posting_date": self.relation("compared", valid, valid, -3600, "after"),
                "date_to_expires": self.relation("compared", valid, valid, 7200, "before"),
                "injection_date_to_expires": self.relation("compared", valid, valid, 3600, "before"),
                "legacy_nntp_posting_date_to_expires": self.relation("compared", valid, valid, 7200, "before"),
            },
        )

    def test_folded_unknown_local_zone_keeps_physical_line_provenance(self) -> None:
        valid = "valid"
        metadata = self.assert_projection(
            b"Date: Thu, 01 Jan 1970\r\n 00:00:00 -0000\r\n"
            b"Injection-Date: Thu, 01 Jan 1970 01:00:00 +0100\r\n"
            b"NNTP-Posting-Date: Thu, 01 Jan 1970 00:00:00 GMT\r\n"
            b"Expires: Thu, 01 Jan 1970 00:00:00 UT\r\n",
            {
                "date": {"parse_status": valid, "utc_epoch_seconds": 0, "candidates": [self.candidate(valid, 0, [1, 2])]},
                "injection_date": {"parse_status": valid, "utc_epoch_seconds": 0, "candidates": [self.candidate(valid, 0, [3])]},
                "legacy_nntp_posting_date": {"parse_status": valid, "utc_epoch_seconds": 0, "candidates": [self.candidate(valid, 0, [4])]},
                "expires": {"parse_status": valid, "utc_epoch_seconds": 0, "candidates": [self.candidate(valid, 0, [5])]},
            },
            {
                "date_to_injection_date": self.relation("compared", valid, valid, 0, "equal"),
                "date_to_legacy_nntp_posting_date": self.relation("compared", valid, valid, 0, "equal"),
                "injection_date_to_legacy_nntp_posting_date": self.relation("compared", valid, valid, 0, "equal"),
                "date_to_expires": self.relation("compared", valid, valid, 0, "equal"),
                "injection_date_to_expires": self.relation("compared", valid, valid, 0, "equal"),
                "legacy_nntp_posting_date_to_expires": self.relation("compared", valid, valid, 0, "equal"),
            },
        )
        self.assertIs(metadata["date"]["candidates"][0]["timezone_local_offset_unknown"], True)

    def test_257_occurrences_exhaust_the_date_budget_and_withhold_relations(self) -> None:
        budget = "budget_exceeded"
        missing = "missing"
        headers = b"Date: 01 Jan 2024 00:00:00 +0000\r\n" * 257
        candidates = [self.candidate("valid", 1704067200, [line]) for line in range(1, 257)]
        self.assert_projection(
            headers,
            {
                "date": {"parse_status": budget, "utc_epoch_seconds": None, "candidates": candidates},
                "injection_date": {"parse_status": missing, "utc_epoch_seconds": None, "candidates": []},
                "legacy_nntp_posting_date": {"parse_status": missing, "utc_epoch_seconds": None, "candidates": []},
                "expires": {"parse_status": missing, "utc_epoch_seconds": None, "candidates": []},
            },
            {
                "date_to_injection_date": self.relation("source_unavailable", budget, missing, None, None),
                "date_to_legacy_nntp_posting_date": self.relation("source_unavailable", budget, missing, None, None),
                "injection_date_to_legacy_nntp_posting_date": self.relation("source_unavailable", missing, missing, None, None),
                "date_to_expires": self.relation("source_unavailable", budget, missing, None, None),
                "injection_date_to_expires": self.relation("source_unavailable", missing, missing, None, None),
                "legacy_nntp_posting_date_to_expires": self.relation("source_unavailable", missing, missing, None, None),
            },
        )

    def test_malformed_calendar_zone_and_octet_are_independent_sources(self) -> None:
        calendar = "invalid_calendar_or_time"
        zone = "unsupported_absolute_timezone"
        octet = "invalid_octet_or_folding"
        missing = "missing"
        self.assert_projection(
            b"Date: Sat, 31 Feb 2024 00:00:00 +0000\r\n"
            b"Injection-Date: 01 Jan 2024 00:00:00 PST\r\n"
            b"NNTP-Posting-Date: 01 Jan 2024 00:00:00 +0000\x00\r\n",
            {
                "date": {"parse_status": calendar, "utc_epoch_seconds": None, "candidates": [self.candidate(calendar, None, [1])]},
                "injection_date": {"parse_status": zone, "utc_epoch_seconds": None, "candidates": [self.candidate(zone, None, [2])]},
                "legacy_nntp_posting_date": {"parse_status": octet, "utc_epoch_seconds": None, "candidates": [self.candidate(octet, None, [3])]},
                "expires": {"parse_status": missing, "utc_epoch_seconds": None, "candidates": []},
            },
            {
                "date_to_injection_date": self.relation("source_unavailable", calendar, zone, None, None),
                "date_to_legacy_nntp_posting_date": self.relation("source_unavailable", calendar, octet, None, None),
                "injection_date_to_legacy_nntp_posting_date": self.relation("source_unavailable", zone, octet, None, None),
                "date_to_expires": self.relation("source_unavailable", calendar, missing, None, None),
                "injection_date_to_expires": self.relation("source_unavailable", zone, missing, None, None),
                "legacy_nntp_posting_date_to_expires": self.relation("source_unavailable", octet, missing, None, None),
            },
        )

    def test_blank_line_ends_fields_and_empty_input_stays_missing(self) -> None:
        missing = "missing"
        expected_sources = {
            name: {"parse_status": missing, "utc_epoch_seconds": None, "candidates": []}
            for name in ("date", "injection_date", "legacy_nntp_posting_date", "expires")
        }
        expected_relations = {
            name: self.relation("source_unavailable", missing, missing, None, None)
            for name in (
                "date_to_injection_date",
                "date_to_legacy_nntp_posting_date",
                "injection_date_to_legacy_nntp_posting_date",
                "date_to_expires",
                "injection_date_to_expires",
                "legacy_nntp_posting_date_to_expires",
            )
        }
        self.assert_projection(
            b"Subject: ignored\r\n\r\nDate: Thu, 01 Jan 1970 00:00:00 +0000\r\n",
            expected_sources,
            expected_relations,
        )
        self.assert_projection(b"", expected_sources, expected_relations)

    def test_exact_input_limit_is_accepted_and_invalid_pointer_or_oversize_is_null(self) -> None:
        suffix = b"\r\nDate: Thu, 01 Jan 1970 00:00:00 +0000\r\n"
        prefix = b"X-Ignored: "
        padding = 65_536 - len(prefix) - len(suffix)
        self.assertGreaterEqual(padding, 0)
        payload = prefix + (b"x" * padding) + suffix
        self.assertEqual(len(payload), 65_536)
        valid = "valid"
        missing = "missing"
        self.assert_projection(
            payload,
            {
                "date": {"parse_status": valid, "utc_epoch_seconds": 0, "candidates": [self.candidate(valid, 0, [2])]},
                "injection_date": {"parse_status": missing, "utc_epoch_seconds": None, "candidates": []},
                "legacy_nntp_posting_date": {"parse_status": missing, "utc_epoch_seconds": None, "candidates": []},
                "expires": {"parse_status": missing, "utc_epoch_seconds": None, "candidates": []},
            },
            {
                "date_to_injection_date": self.relation("source_unavailable", valid, missing, None, None),
                "date_to_legacy_nntp_posting_date": self.relation("source_unavailable", valid, missing, None, None),
                "injection_date_to_legacy_nntp_posting_date": self.relation("source_unavailable", missing, missing, None, None),
                "date_to_expires": self.relation("source_unavailable", valid, missing, None, None),
                "injection_date_to_expires": self.relation("source_unavailable", missing, missing, None, None),
                "legacy_nntp_posting_date_to_expires": self.relation("source_unavailable", missing, missing, None, None),
            },
        )
        self.assertFalse(self.parse_dates(None, 1))
        too_large = ctypes.create_string_buffer(b"X" * 65_537, 65_538)
        self.assertFalse(self.parse_dates(ctypes.cast(too_large, ctypes.c_void_p), 65_537))


if __name__ == "__main__":
    unittest.main()

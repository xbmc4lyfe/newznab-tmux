/*
 * This file contains a benchmark-only copy of nzbget's date parser helpers.
 *
 * This file is part of nzbget. See <https://nzbget.com>.
 * Copyright (C) 2007-2017 Andrey Prygunkov <hugbug@users.sourceforge.net>
 * Copyright (C) 2023-2026 Denis <denis@nzbget.com>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 *
 * Provenance: https://github.com/Appz4Fun/nzbget/blob/8f2f409d4e263ecac3313404ed4685e7dca3d9ec/daemon/util/Util.cpp
 * 8f2f409d4e263ecac3313404ed4685e7dca3d9ec. Source file SHA-256:
 * 14a1c4c82c6c551806b142b3f9ac177a39af48dbaa92bceb763f98a21f0390d8.
 * The copied parser is the non-NZBGET_USE_RUST implementation of
 * WebUtil::ParseRfc822DateTime, with Util::Timegm and its arithmetic helpers.
 * The adapter below emits complete date evidence within a restricted grammar,
 * not full Rust grammar equivalence. The genuine copied chunks remain unchanged.
 */

#include <cctype>
#include <cstdio>
#include <cstdlib>
#include <cstring>
#include <ctime>
#include <strings.h>
#include <array>
#include <charconv>
#include <cstdint>
#include <limits>
#include <string>
#include <string_view>
#include <utility>
#include <vector>

class Util {
public:
	static time_t Timegm(tm const* t);
};

class WebUtil {
public:
	static time_t ParseRfc822DateTime(const char* dateTimeStr);
};

/* From boost */

inline int is_leap(int year)
{
  if(year % 400 == 0)
  return 1;
  if(year % 100 == 0)
  return 0;
  if(year % 4 == 0)
  return 1;
  return 0;
}
inline int days_from_0(int year)
{
  year--;
  return 365 * year + (year / 400) - (year/100) + (year / 4);
}
inline int days_from_1970(int year)
{
  static const int days_from_0_to_1970 = 719162; // days_from_0(1970);
  return days_from_0(year) - days_from_0_to_1970;
}
inline int days_from_1jan(int year,int month,int day)
{
  static const int days[2][12] =
  {
	{ 0,31,59,90,120,151,181,212,243,273,304,334},
	{ 0,31,60,91,121,152,182,213,244,274,305,335}
  };
  return days[is_leap(year)][month-1] + day - 1;
}

inline time_t internal_timegm(tm const *t)
{
  int year = t->tm_year + 1900;
  int month = t->tm_mon;
  if(month > 11)
  {
	year += month/12;
	month %= 12;
  }
  else if(month < 0)
  {
	int years_diff = (-month + 11)/12;
	year -= years_diff;
	month+=12 * years_diff;
  }
  month++;
  int day = t->tm_mday;
  int day_of_year = days_from_1jan(year,month,day);
  int days_since_epoch = days_from_1970(year) + day_of_year;

  time_t seconds_in_day = 3600 * 24;
  time_t result = seconds_in_day * days_since_epoch + 3600 * t->tm_hour + 60 * t->tm_min + t->tm_sec;

  return result;
}

time_t Util::Timegm(tm const *t)
{
	return internal_timegm(t);
}

/*
 The date/time can be formatted according to RFC822 in different ways. Examples:
   Wed, 26 Jun 2013 01:02:54 -0600
   Wed, 26 Jun 2013 01:02:54 GMT
   26 Jun 2013 01:02:54 -0600
   26 Jun 2013 01:02 -0600
   26 Jun 2013 01:02 A
 The day name and the seconds are optional; the zone is an offset (+hhmm or
 -hhmm) or a name (UT, GMT, UTC, Z and the US zones). Military zones and
 unknown names count as UTC. Only the first format was read before: the others
 (GMT is common in feeds) gave no time at all.
*/
time_t WebUtil::ParseRfc822DateTime(const char* dateTimeStr)
{
	const char* p = dateTimeStr;
	while (*p == ' ') p++;

	// optional day name ("Wed,")
	if (isalpha((unsigned char)*p))
	{
		while (*p && *p != ',' && *p != ' ') p++;
		if (*p == ',') p++;
	}

	char month[4] = "";
	int day, year, hours, minutes, seconds = 0;
	int len = 0;
	if (sscanf(p, "%d %3s %d %d:%d%n", &day, month, &year, &hours, &minutes, &len) != 5)
	{
		return 0;
	}
	p += len;
	if (*p == ':')
	{
		int secLen = 0;
		if (sscanf(p + 1, "%d%n", &seconds, &secLen) != 1)
		{
			return 0;
		}
		p += 1 + secLen;
	}
	while (*p == ' ') p++;

	int zoneOffset = 0; // minutes east of UTC
	if ((*p == '+' || *p == '-') && isdigit((unsigned char)p[1]))
	{
		int zone = atoi(p + 1);
		zoneOffset = (zone / 100) * 60 + zone % 100;
		if (*p == '-') zoneOffset = -zoneOffset;
	}
	else
	{
		struct { const char* name; int hours; } zones[] = {
			{"EST", -5}, {"EDT", -4}, {"CST", -6}, {"CDT", -5},
			{"MST", -7}, {"MDT", -6}, {"PST", -8}, {"PDT", -7}};
		for (auto& zone : zones)
		{
			if (!strncasecmp(p, zone.name, 3))
			{
				zoneOffset = zone.hours * 60;
				break;
			}
		}
	}

	int mon = 0;
	if (!strcasecmp(month, "Jan")) mon = 0;
	else if (!strcasecmp(month, "Feb")) mon = 1;
	else if (!strcasecmp(month, "Mar")) mon = 2;
	else if (!strcasecmp(month, "Apr")) mon = 3;
	else if (!strcasecmp(month, "May")) mon = 4;
	else if (!strcasecmp(month, "Jun")) mon = 5;
	else if (!strcasecmp(month, "Jul")) mon = 6;
	else if (!strcasecmp(month, "Aug")) mon = 7;
	else if (!strcasecmp(month, "Sep")) mon = 8;
	else if (!strcasecmp(month, "Oct")) mon = 9;
	else if (!strcasecmp(month, "Nov")) mon = 10;
	else if (!strcasecmp(month, "Dec")) mon = 11;

	struct tm rawtime;
	memset(&rawtime, 0, sizeof(rawtime));

	rawtime.tm_year = year - 1900;
	rawtime.tm_mon = mon;
	rawtime.tm_mday = day;
	rawtime.tm_hour = hours;
	rawtime.tm_min = minutes;
	rawtime.tm_sec = seconds;

	time_t enctime = Util::Timegm(&rawtime);
	enctime -= zoneOffset * 60;

	return enctime;
}

namespace date_reference {
constexpr size_t max_input = 65'536;
constexpr size_t max_occurrences = 256;
constexpr const char* names[] = {"date", "injection_date", "legacy_nntp_posting_date", "expires"};
constexpr const char* months[] = {"Jan", "Feb", "Mar", "Apr", "May", "Jun", "Jul", "Aug", "Sep", "Oct", "Nov", "Dec"};
constexpr const char* weekdays[] = {"Sun", "Mon", "Tue", "Wed", "Thu", "Fri", "Sat"};
constexpr const char* epoch_basis = "Gregorian calendar and POSIX seconds; second 60 maps to the following POSIX second without asserting a historical leap-second event";
constexpr const char* count_basis = "retained candidates; the total may be larger when budget_exceeded";

bool equal_ascii(std::string_view a, std::string_view b)
{
    if (a.size() != b.size()) return false;
    for (size_t i = 0; i < a.size(); ++i) {
        auto lower = [](unsigned char c) { return c >= 'A' && c <= 'Z' ? c + ('a' - 'A') : c; };
        if (lower(a[i]) != lower(b[i])) return false;
    }
    return true;
}

bool alpha(unsigned char c) { return (c >= 'A' && c <= 'Z') || (c >= 'a' && c <= 'z'); }
bool digit(unsigned char c) { return c >= '0' && c <= '9'; }

struct Cursor {
    std::string_view text;
    size_t position = 0;
    char peek() const { return position < text.size() ? text[position] : '\0'; }
    bool space() {
        const size_t start = position;
        while (peek() == ' ' || peek() == '\t') ++position;
        return position != start;
    }
    bool take(char c) { if (peek() != c) return false; ++position; return true; }
    bool number(unsigned minimum, unsigned maximum, int& output) {
        const size_t start = position;
        output = 0;
        while (digit(peek())) {
            if (position - start >= maximum) return false;
            output = output * 10 + (text[position++] - '0');
        }
        return position - start >= minimum;
    }
    std::string_view word() {
        const size_t start = position;
        while (alpha(peek())) ++position;
        return text.substr(start, position - start);
    }
};

struct Candidate {
    const char* status = "malformed_or_unsupported_absolute_date";
    int64_t epoch = 0;
    // -1 means the adapter has not recognized a timezone.
    int unknown_local_zone = -1;
    std::string raw;
    std::string unfolded;
    std::string zone_token;
    bool has_zone_token = false;
    int zone_offset = 0;
    int seconds_present = -1;
    int leap_second = -1;
    int weekday_matches = -1;
    std::vector<size_t> lines;
};

Candidate parse_value(std::string_view value, std::string raw, std::vector<size_t> lines)
{
    Candidate result;
    result.lines = std::move(lines);
    result.raw = std::move(raw);
    result.unfolded.assign(value);
    for (unsigned char byte : value) {
        if (byte != '\t' && (byte < 0x20 || byte > 0x7e)) {
            result.status = "invalid_octet_or_folding";
            return result;
        }
    }
    // Comments are outside this adapter's declared restricted grammar.
    if (value.find_first_of("()") != std::string_view::npos) return result;
    Cursor p{value};
    p.space();
    int weekday = -1;
    if (alpha(p.peek())) {
        const auto token = p.word();
        for (int i = 0; i < 7; ++i) if (equal_ascii(token, weekdays[i])) weekday = i;
        if (weekday == -1) return result;
        p.space();
        if (!p.take(',')) return result;
        p.space();
    }
    int day, year, hour, minute, second = 0;
    if (!p.number(1, 2, day) || !p.space()) return result;
    const auto month_token = p.word();
    int month = -1;
    for (int i = 0; i < 12; ++i) if (equal_ascii(month_token, months[i])) month = i;
    if (month == -1 || !p.space() || !p.number(4, 9, year) || !p.space()) return result;
    // The original C++ Timegm has int intermediates; never pass huge years.
    if (year > 9999) return result;
    if (!p.number(2, 2, hour)) return result;
    p.space();
    if (!p.take(':')) return result;
    p.space();
    if (!p.number(2, 2, minute)) return result;
    bool zone_separator = p.space();
    const bool has_seconds = p.peek() == ':';
    if (p.take(':')) {
        p.space();
        if (!p.number(2, 2, second)) return result;
        zone_separator = p.space();
    }
    if (!zone_separator) return result;
    int zone = 0;
    char sign = '+';
    const size_t zone_start = p.position;
    if (p.peek() == '+' || p.peek() == '-') {
        sign = p.peek();
        ++p.position;
        if (!p.number(4, 4, zone) || zone / 100 > 23 || zone % 100 > 59) return result;
        result.zone_token.assign(value.substr(zone_start, p.position - zone_start));
        result.has_zone_token = true;
        result.unknown_local_zone = sign == '-' && zone == 0;
    } else {
        const auto token = p.word();
        if (token.empty()) return result;
        result.zone_token.assign(token);
        result.has_zone_token = true;
        if (!equal_ascii(token, "GMT") && !equal_ascii(token, "UT")) {
            result.status = "unsupported_absolute_timezone";
            return result;
        }
        result.unknown_local_zone = 0;
    }
    result.zone_offset = (zone / 100 * 60 + zone % 100) * (sign == '-' ? -1 : 1);
    result.seconds_present = has_seconds;
    result.leap_second = second == 60;
    p.space();
    if (p.position != value.size()) return result;
    const int month_days[] = {31, is_leap(year) ? 29 : 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31};
    if (year < 1900 || day < 1 || day > month_days[month] || hour > 23 || minute > 59 || second > 60) {
        result.status = "invalid_calendar_or_time";
        return result;
    }
    const int civil_day = days_from_1970(year) + days_from_1jan(year, month + 1, day);
    if (weekday != -1) result.weekday_matches = ((civil_day + 4) % 7 + 7) % 7 == weekday;
    if (result.weekday_matches == 0) {
        result.status = "weekday_calendar_mismatch";
        return result;
    }
    // Canonical admission removes permissive sscanf/zone behavior without
    // substituting a different epoch implementation for the genuine parser.
    char canonical[96];
    const int length = std::snprintf(canonical, sizeof(canonical), "%02d %s %04d %02d:%02d:%02d %c%04d",
        day, months[month], year, hour, minute, second, sign, zone);
    if (length < 0 || static_cast<size_t>(length) >= sizeof(canonical)) return result;
    result.epoch = static_cast<int64_t>(WebUtil::ParseRfc822DateTime(canonical));
    result.status = "valid";
    return result;
}

struct Source {
    std::vector<Candidate> candidates;
    const char* status() const {
        return candidates.size() >= max_occurrences ? "budget_exceeded" :
            candidates.empty() ? "missing" : candidates.size() > 1 ? "duplicate" : candidates[0].status;
    }
    bool available() const { return std::strcmp(status(), "valid") == 0; }
};

std::array<Source, 4> extract(std::string_view input)
{
    std::array<Source, 4> sources;
    int active = -1;
    std::string value;
    std::string raw;
    std::vector<size_t> lines;
    auto flush = [&] {
        if (active >= 0 && sources[active].candidates.size() < max_occurrences)
            sources[active].candidates.push_back(parse_value(value, std::move(raw), std::move(lines)));
        value.clear();
        raw.clear();
        lines.clear();
        active = -1;
    };
    size_t offset = 0, line_number = 0;
    while (offset < input.size()) {
        const size_t start = offset;
        const auto lf = input.find('\n', offset);
        const size_t end = lf == std::string_view::npos ? input.size() : lf;
        auto line = input.substr(offset, end - offset);
        offset = lf == std::string_view::npos ? input.size() : lf + 1;
        const auto physical = input.substr(start, offset - start);
        ++line_number;
        if (!line.empty() && line.back() == '\r') line.remove_suffix(1);
        if (line.empty()) break;
        if (line.front() == ' ' || line.front() == '\t') {
            if (active >= 0) { value.append(line); raw.append(physical); lines.push_back(line_number); }
            continue;
        }
        flush();
        const auto colon = line.find(':');
        if (colon == std::string_view::npos) continue;
        const auto name = line.substr(0, colon);
        if (equal_ascii(name, "Date")) active = 0;
        else if (equal_ascii(name, "Injection-Date")) active = 1;
        else if (equal_ascii(name, "NNTP-Posting-Date")) active = 2;
        else if (equal_ascii(name, "Expires")) active = 3;
        if (active >= 0 && sources[active].candidates.size() < max_occurrences) {
            value.assign(line.substr(colon + 1));
            raw.assign(physical);
            lines.push_back(line_number);
        } else active = -1;
    }
    flush();
    return sources;
}

template <typename T> void integer(std::string& out, T value)
{
    char buffer[32];
    const auto converted = std::to_chars(buffer, buffer + sizeof(buffer), value);
    out.append(buffer, converted.ptr);
}
void quoted(std::string& out, const char* value) { out.push_back('"'); out.append(value); out.push_back('"'); }
void hex(std::string& out, std::string_view bytes) {
    constexpr char digits[] = "0123456789abcdef";
    out.push_back('"');
    const size_t begin = out.size();
    out.resize(begin + bytes.size() * 2);
    for (size_t i = 0; i < bytes.size(); ++i) {
        const auto byte = static_cast<unsigned char>(bytes[i]);
        out[begin + i * 2] = digits[byte >> 4];
        out[begin + i * 2 + 1] = digits[byte & 15];
    }
    out.push_back('"');
}
void optional_bool(std::string& out, int value) { out.append(value < 0 ? "null" : value ? "true" : "false"); }
void epoch(std::string& out, const Candidate& candidate) {
    if (std::strcmp(candidate.status, "valid") == 0) integer(out, candidate.epoch);
    else out.append("null");
}

std::string serialize(const std::array<Source, 4>& sources)
{
    std::string out;
    out.reserve(2048);
    out.append("{\"parser_engine\":\"nntmux-cpp-nzbget-date-reference\",\"schema_version\":2,\"syntax_profile\":\"Restricted equal-output reference: years 1900-9999; numeric zones, GMT/UT, optional seconds and folded whitespace; comments excluded; not full Rust grammar equivalence\"");
    for (size_t i = 0; i < sources.size(); ++i) {
        const auto& source = sources[i];
        out.push_back(','); quoted(out, names[i]);
        out.append(":{\"parse_status\":"); quoted(out, source.status());
        out.append(",\"occurrence_count\":"); integer(out, source.candidates.size());
        out.append(",\"occurrence_count_basis\":"); quoted(out, count_basis);
        out.append(",\"candidates\":[");
        bool first = true;
        for (const auto& candidate : source.candidates) {
            if (!first) out.push_back(',');
            first = false;
            out.append("{\"source_line_indices\":[");
            for (size_t j = 0; j < candidate.lines.size(); ++j) {
                if (j) out.push_back(',');
                integer(out, candidate.lines[j]);
            }
            out.append("],\"raw_field_hex\":"); hex(out, candidate.raw);
            out.append(",\"unfolded_value_hex\":"); hex(out, candidate.unfolded);
            out.append(",\"parse_status\":"); quoted(out, candidate.status);
            out.append(",\"utc_epoch_seconds\":"); epoch(out, candidate);
            out.append(",\"timezone_token\":");
            if (candidate.has_zone_token) quoted(out, candidate.zone_token.c_str()); else out.append("null");
            out.append(",\"timezone_offset_minutes\":");
            if (candidate.unknown_local_zone >= 0) integer(out, candidate.zone_offset); else out.append("null");
            out.append(",\"timezone_local_offset_unknown\":"); optional_bool(out, candidate.unknown_local_zone);
            out.append(",\"seconds_present\":"); optional_bool(out, candidate.seconds_present);
            out.append(",\"leap_second\":"); optional_bool(out, candidate.leap_second);
            out.append(",\"weekday_matches_calendar\":"); optional_bool(out, candidate.weekday_matches);
            out.append(",\"epoch_basis\":"); quoted(out, epoch_basis);
            out.push_back('}');
        }
        out.append("],\"utc_epoch_seconds\":");
        if (source.available()) integer(out, source.candidates[0].epoch); else out.append("null");
        out.push_back('}');
    }
    out.append(",\"reported_clock_relations\":{");
    constexpr int pairs[][2] = {{0, 1}, {0, 2}, {1, 2}, {0, 3}, {1, 3}, {2, 3}};
    for (size_t i = 0; i < 6; ++i) {
        const auto& lhs = sources[pairs[i][0]];
        const auto& rhs = sources[pairs[i][1]];
        if (i) out.push_back(',');
        out.push_back('"'); out.append(names[pairs[i][0]]); out.append("_to_"); out.append(names[pairs[i][1]]);
        out.append("\":{\"comparison_status\":");
        const bool available = lhs.available() && rhs.available();
        quoted(out, available ? "compared" : "source_unavailable");
        out.append(",\"lhs_parse_status\":"); quoted(out, lhs.status());
        out.append(",\"rhs_parse_status\":"); quoted(out, rhs.status());
        out.append(",\"rhs_minus_lhs_seconds\":");
        // Valid years are bounded to 1900-9999, so this signed difference fits.
        const int64_t delta = available ? rhs.candidates[0].epoch - lhs.candidates[0].epoch : 0;
        if (available) integer(out, delta); else out.append("null");
        out.append(",\"lhs_order_relative_to_rhs\":");
        if (available) quoted(out, delta > 0 ? "before" : delta < 0 ? "after" : "equal");
        else out.append("null");
        out.push_back('}');
    }
    out.append("},\"interpretation\":\"Absolute header timestamps describe reported composition or injection clocks; they do not measure uploader bandwidth or establish uploader location. A -0000 zone gives UTC time without information about the originating local timezone.\"}");
    return out;
}
} // namespace date_reference

extern "C" __attribute__((visibility("default"))) char*
nntmux_article_dates_v1_json(const unsigned char* data, size_t length)
{
    if (length > date_reference::max_input || (length != 0 && data == nullptr)) return nullptr;
    // The copied C++ time arithmetic requires a signed, at least 64-bit time_t.
    if (!std::numeric_limits<time_t>::is_signed || sizeof(time_t) < sizeof(int64_t)) return nullptr;
    try {
        const auto input = length == 0 ? std::string_view{} :
            std::string_view(reinterpret_cast<const char*>(data), length);
        const auto result = date_reference::serialize(date_reference::extract(input));
        auto* owned = static_cast<char*>(std::malloc(result.size() + 1));
        if (owned == nullptr) return nullptr;
        std::memcpy(owned, result.c_str(), result.size() + 1);
        return owned;
    } catch (...) {
        return nullptr;
    }
}

extern "C" __attribute__((visibility("default"))) void
nntmux_metadata_string_free(char* value)
{
	std::free(value);
}

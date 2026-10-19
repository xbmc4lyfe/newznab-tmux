#!/usr/bin/env python3
"""docker/.env as Compose (compose-go) reads it, for docker/bin/compose and the Justfile's LOAD_ENV.

Optional `export`, spaces around `=`, the last assignment wins. A quoted value ends at the first
matching quote not escaped by a backslash and may carry a trailing comment; double-quoted values
decode compose-go's escapes (`\\n`, `\\t`, `\\\\`, `\\"`, octal `\\0NNN`, ...; `\\$` is a literal `$`) and
keep any other backslash sequence; single-quoted ones are literal except for `\\'`. An
unquoted value ends at an inline ` #` comment and is trimmed.

Neither caller evaluates `$` interpolation or reads a value across lines, so each value is flagged:
"interpolated" (unquoted or double-quoted with an unescaped `$`) or "multiline" (an opening quote
with no closing one on its line). Run as a script, it prints one `dotenv KEY FLAG VALUE` line per
requested key present, every word shell-quoted, for LOAD_ENV to evaluate.
"""
import re
import shlex
import sys
from pathlib import Path

ASSIGNMENT = re.compile(r"^\s*(?:export\s+)?([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(.*)$")
# compose-go's control escapes; with `\$`, `\"`, `\\` and octal `\0NNN` the only decoded ones:
# any other backslash sequence is kept as written.
ESCAPES = {"a": "\a", "b": "\b", "f": "\f", "n": "\n", "r": "\r", "t": "\t", "v": "\v"}


def decode_double_quoted(body: str) -> tuple[str, bool]:
    """The decoded value and whether an unescaped `$` makes Compose interpolate it."""
    out: list[str] = []
    interpolates = False
    index = 0
    while index < len(body):
        char = body[index]
        if char == "\\" and index + 1 < len(body):
            escaped = body[index + 1]
            if escaped == "0":
                digits = body[index + 2:index + 5]
                if len(digits) == 3 and all(c in "01234567" for c in digits) and int(digits, 8) <= 0o377:
                    out.append(chr(int(digits, 8)))  # `\0NNN`: one octal byte
                    index += 5
                    continue
                # Any other `\0` loses its `0` and keeps the backslash (`\0b` is `\b`, `\0777` is `\777`).
                out.append(char)
                index += 2
                continue
            if escaped in ESCAPES or escaped in '$"\\':
                out.append(ESCAPES.get(escaped, escaped))
                index += 2
                continue
            # Any other sequence is kept as written, backslash included.
            out.append(char)
            index += 1
            continue
        interpolates = interpolates or char == "$"
        out.append(char)
        index += 1
    return "".join(out), interpolates


def parse_value(value: str) -> tuple[str, str]:
    """(value, flag) for the text after `=`; flag is "", "interpolated" or "multiline"."""
    if value[:1] in ("'", '"'):
        quote = value[0]
        # A backslash escapes the next character, so `"a\\"` closes after the escaped backslash.
        end = 1
        while end < len(value) and value[end] != quote:
            end += 2 if value[end] == "\\" else 1
        if end >= len(value):
            # An opening quote with no closing one starts a value spanning lines.
            return value, "multiline"
        if re.match(r"^\s*(?:#.*)?$", value[end + 1:]):
            body = value[1:end]
            if quote == "'":
                # Literal, except that an escaped single quote loses its backslash.
                return body.replace("\\'", "'"), ""
            decoded, interpolates = decode_double_quoted(body)
            return decoded, "interpolated" if interpolates else ""
    value = re.sub(r"\s#.*$", "", value).strip()
    return value, "interpolated" if "$" in value else ""


def parse(path: Path) -> dict[str, tuple[str, str]]:
    """Every key of the file with its last (value, flag); {} when the file is missing."""
    try:
        text = path.read_text(encoding="utf-8", errors="surrogateescape")
    except FileNotFoundError:
        return {}
    values: dict[str, tuple[str, str]] = {}
    for raw in text.splitlines():
        match = ASSIGNMENT.match(raw)
        if match:
            values[match.group(1)] = parse_value(match.group(2))
    return values


if __name__ == "__main__":
    if len(sys.argv) < 2:
        sys.exit("usage: compose_dotenv.py FILE [KEY...]")
    try:
        parsed = parse(Path(sys.argv[1]))
    except OSError as error:
        sys.exit(f"Cannot read {sys.argv[1]}: {error.strerror or error}")
    lines = [
        "dotenv " + " ".join(shlex.quote(word) for word in (key, parsed[key][1] or "-", parsed[key][0])) + "\n"
        for key in sys.argv[2:] if key in parsed
    ]
    sys.stdout.buffer.write("".join(lines).encode("utf-8", errors="surrogateescape"))

#!/usr/bin/env python3
"""docker/.env as Compose (compose-go) reads it, for docker/bin/compose and the Justfile's LOAD_ENV.

Optional `export`, `=` or YAML-style `: `, spaces around it, a leading BOM, the last assignment wins;
the lines of a multi-line quoted value are never assignments of their own. A quoted value ends at the first
matching quote not escaped by a backslash; anything after it is dropped; double-quoted values
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

# `KEY=value`, or the YAML-style `KEY: value` Compose also accepts.
ASSIGNMENT = re.compile(r"^\s*(?:export\s+)?([A-Za-z_][A-Za-z0-9_]*)\s*(?:=|:)\s*(.*)$")
# compose-go's control escapes; with `\$`, `\"`, `\\` and octal `\0NNN` the only decoded ones:
# any other backslash sequence is kept as written.
ESCAPES = {"a": "\a", "b": "\b", "f": "\f", "n": "\n", "r": "\r", "t": "\t", "v": "\v"}


def decode_double_quoted(body: str) -> tuple[str, bool]:
    """The decoded value and whether an unescaped `$` makes Compose interpolate it. As in Compose,
    escapes are expanded first (`\\$` becoming the literal `$$`), then `$$` reads as one `$` and any
    other `$` interpolates, so `"\\0044$HOME"` (an octal `$` before `$HOME`) is the literal `$HOME`."""
    out: list[str] = []
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
            if escaped == "$":
                out.append("$$")  # Literal through the variable pass below.
                index += 2
                continue
            if escaped in ESCAPES or escaped in '"\\':
                out.append(ESCAPES.get(escaped, escaped))
                index += 2
                continue
            # Any other sequence is kept as written, backslash included.
            out.append(char)
            index += 1
            continue
        out.append(char)
        index += 1
    return unescape_dollars("".join(out))


def unescape_dollars(text: str) -> tuple[str, bool]:
    """`$$` is one literal `$`; any other `$` makes Compose interpolate the value."""
    out: list[str] = []
    interpolates = False
    index = 0
    while index < len(text):
        if text[index] == "$" and text[index + 1:index + 2] == "$":
            out.append("$")
            index += 2
            continue
        interpolates = interpolates or text[index] == "$"
        out.append(text[index])
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
        # Text after the closing quote (`"abc" def`) is dropped by Compose, as a comment is.
        body = value[1:end]
        if quote == "'":
            # Literal, except that an escaped single quote loses its backslash.
            return body.replace("\\'", "'"), ""
        decoded, interpolates = decode_double_quoted(body)
        return decoded, "interpolated" if interpolates else ""
    value = re.sub(r"\s#.*$", "", value).strip()
    return value.replace("$$", "$"), "interpolated" if "$" in value.replace("$$", "") else ""


def parse(path: Path) -> dict[str, tuple[str, str]]:
    """Every key of the file with its last (value, flag); {} when the file is missing."""
    try:
        text = path.read_text(encoding="utf-8", errors="surrogateescape").removeprefix("\ufeff")
    except FileNotFoundError:
        return {}
    values: dict[str, tuple[str, str]] = {}
    lines = text.splitlines()
    index = 0
    while index < len(lines):
        match = ASSIGNMENT.match(lines[index])
        index += 1
        if not match:
            continue
        value, flag = parse_value(match.group(2))
        if flag == "multiline":
            # The value runs on to its closing quote: the lines up to it are part of it, never
            # assignments of their own (a continuation reading `DC=...` must not set DC).
            quote = value[0]
            while index < len(lines) and not closes(lines[index], quote):
                index += 1
            index += 1
        values[match.group(1)] = (value, flag)
    return values


def closes(line: str, quote: str) -> bool:
    """Whether a continuation line holds the unescaped quote that ends a multi-line value."""
    position = 0
    while position < len(line):
        if line[position] == "\\":
            position += 2
            continue
        if line[position] == quote:
            return True
        position += 1
    return False


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

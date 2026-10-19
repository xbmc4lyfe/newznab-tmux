#!/usr/bin/env python3
"""docker/.env as Compose (compose-go) reads it, for docker/bin/compose and the Justfile's LOAD_ENV.

Optional `export`, spaces around `=`, the last assignment wins. A quoted value ends at the first
matching quote not preceded by a backslash and may carry a trailing comment; double-quoted values
decode escapes (`\\n`, `\\t`, `\\\\`, `\\"`, ...; `\\$` is a literal `$`), single-quoted ones are
literal. An unquoted value ends at an inline ` #` comment and is trimmed.

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
# compose-go's control escapes; any other escaped character stands for itself.
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
            out.append(ESCAPES.get(escaped, escaped))
            index += 2
            continue
        interpolates = interpolates or char == "$"
        out.append(char)
        index += 1
    return "".join(out), interpolates


def parse_value(value: str) -> tuple[str, str]:
    """(value, flag) for the text after `=`; flag is "", "interpolated" or "multiline"."""
    if value[:1] in ("'", '"'):
        quote = value[0]
        for end in range(1, len(value)):
            if value[end] == quote and value[end - 1] != "\\":
                if re.match(r"^\s*(?:#.*)?$", value[end + 1:]):
                    body = value[1:end]
                    if quote == "'":
                        return body, ""
                    decoded, interpolates = decode_double_quoted(body)
                    return decoded, "interpolated" if interpolates else ""
                break
        else:
            # An opening quote with no closing one starts a value spanning lines.
            return value, "multiline"
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

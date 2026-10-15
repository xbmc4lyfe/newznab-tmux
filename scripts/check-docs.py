#!/usr/bin/env python3
"""Check local links, HTML anchors, and retained evidence in an assembled site."""
import argparse
import hashlib
from html.parser import HTMLParser
import json
from pathlib import Path, PurePosixPath
import re
from urllib.parse import unquote, urlsplit


class Document(HTMLParser):
    def __init__(self):
        super().__init__(convert_charrefs=True)
        self.ids = set()
        self.duplicates = set()
        self.links = []

    def handle_starttag(self, tag, attrs):
        for name, value in attrs:
            if name == 'id' and value:
                if value in self.ids:
                    self.duplicates.add(value)
                self.ids.add(value)
            if name in ('href', 'src') and value is not None:
                self.links.append((self.getpos()[0], value))
            # Older report pages may use named anchors.
            if tag == 'a' and name == 'name' and value:
                self.ids.add(value)

    handle_startendtag = handle_starttag


def contained_path(root, base, value):
    """Resolve browser-style local paths without accepting a site-root escape."""
    if '\\' in value or '\x00' in value:
        raise ValueError('invalid local path')
    parts = [] if value.startswith('/') else list(base.relative_to(root).parts)
    for part in PurePosixPath(value).parts:
        if part in ('/', '.', ''):
            continue
        if part == '..':
            if not parts:
                raise ValueError('path escapes site root')
            parts.pop()
        else:
            parts.append(part)
    path = root.joinpath(*parts)
    if not path.resolve().is_relative_to(root):
        raise ValueError('symlink escapes site root')
    return path


def check_site(site):
    root = Path(site).resolve()
    if not root.is_dir():
        return [f'Site directory does not exist: {root}']
    errors = []

    def fail(message):
        if len(errors) < 100:
            errors.append(message)
        elif len(errors) == 100:
            errors.append('Further errors omitted after the first 100.')

    documents = {}
    for path in sorted(root.rglob('*')):
        if path.suffix.lower() != '.html' or not path.is_file():
            continue
        relative = path.relative_to(root).as_posix()
        if not path.resolve().is_relative_to(root):
            fail(f'{relative}: HTML symlink escapes site root')
            continue
        try:
            document = Document()
            document.feed(path.read_text(encoding='utf-8'))
            documents[path] = document
            for anchor in sorted(document.duplicates):
                fail(f'{relative}: duplicate HTML id {anchor!r}')
        except (OSError, UnicodeError) as error:
            fail(f'{relative}: cannot read HTML: {error}')
    if not documents:
        fail('No HTML documents found in site directory')
    for path, document in documents.items():
        relative = path.relative_to(root).as_posix()
        for line, link in document.links:
            location = f'{relative}:{line}'
            try:
                parsed = urlsplit(link)
                if parsed.scheme or parsed.netloc:
                    continue
                local_path = unquote(parsed.path, errors='strict')
                target = contained_path(root, path.parent, local_path) if local_path else path
                if target.is_dir():
                    target = target / 'index.html'
                if not target.is_file():
                    fail(f'{location}: missing local target {link!r}')
                    continue
                if parsed.fragment and target.suffix.lower() == '.html':
                    anchor = unquote(parsed.fragment, errors='strict')
                    target_document = documents.get(target)
                    if target_document is None:
                        fail(f'{location}: target HTML was not parsed: {link!r}')
                    elif anchor not in target_document.ids:
                        fail(f'{location}: missing fragment {link!r}')
            except (ValueError, UnicodeError) as error:
                fail(f'{location}: invalid link {link!r}: {error}')
    provenance = root / 'data/historical-provenance.json'
    if provenance.exists():
        try:
            if not provenance.resolve().is_relative_to(root):
                raise ValueError('provenance symlink escapes site root')
            payload = json.loads(provenance.read_text(encoding='utf-8'))
            summaries = payload['summaries']
            if not isinstance(summaries, list) or not summaries:
                raise ValueError('summaries must be a nonempty list')
            seen = set()
            for row in summaries:
                filename, expected = row['file'], row['sha256']
                if not isinstance(filename, str) or not re.fullmatch(r'[a-z0-9][a-z0-9-]*\.json', filename):
                    raise ValueError('summary file must be a flat JSON filename')
                if filename in seen:
                    raise ValueError(f'duplicate summary: {filename}')
                seen.add(filename)
                if not isinstance(expected, str) or not re.fullmatch(r'[0-9a-f]{64}', expected):
                    raise ValueError(f'invalid SHA-256 for {filename}')
                summary = contained_path(root, root / 'data', filename)
                actual = hashlib.sha256(summary.read_bytes()).hexdigest()
                if actual != expected:
                    fail(f'data/{filename}: historical evidence checksum mismatch (expected {expected}, got {actual})')
        except (KeyError, TypeError, ValueError, OSError, UnicodeError) as error:
            fail(f'data/historical-provenance.json: invalid retained evidence: {error}')
    return errors


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--site', required=True, type=Path)
    options = parser.parse_args()
    errors = check_site(options.site)
    if errors:
        parser.exit(1, '\n'.join(errors) + '\n')
    print('Documentation links, HTML anchors, and retained evidence checksums passed.')


if __name__ == '__main__':
    main()

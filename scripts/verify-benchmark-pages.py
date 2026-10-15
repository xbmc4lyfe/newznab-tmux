#!/usr/bin/env python3
"""Bind a Pages artifact to tested source and verify every public byte after deploy."""

import argparse
import hashlib
import json
from pathlib import Path
import re
import time
import urllib.error
import urllib.parse
import urllib.request

MAX_FILES = 4096
MAX_FILE_BYTES = 64 * 1024 * 1024
MAX_TOTAL_BYTES = 256 * 1024 * 1024
MAX_MANIFEST_BYTES = 1024 * 1024
PUBLICATION = 'publication.json'
SITE_URL = 'https://xbmc4lyfe.github.io/newznab-tmux/'
# Pages consumes these control files but does not serve them over HTTP.
EXCLUDED_CONTROL_FILES = ['.nojekyll', 'benchmarks/.nojekyll']


def source_sha(value):
    if not isinstance(value, str) or re.fullmatch(r'[0-9a-f]{40}', value) is None:
        raise ValueError('Expected a full source commit SHA')
    return value


def safe_path(value):
    if (not isinstance(value, str) or len(value) > 1024
            or re.fullmatch(r'[A-Za-z0-9_.\-/]+', value) is None
            or any(part in ('', '.', '..') for part in value.split('/'))
            or value in [PUBLICATION, *EXCLUDED_CONTROL_FILES]):
        raise ValueError('Unsafe publication file path')
    return value


def parse_json(data):
    def unique(pairs):
        result = {}
        for key, value in pairs:
            if key in result:
                raise ValueError('Duplicate JSON field')
            result[key] = value
        return result
    try:
        return json.loads(data, object_pairs_hook=unique)
    except (UnicodeError, json.JSONDecodeError, RecursionError) as error:
        raise ValueError('Malformed publication JSON') from error


def validate_manifest(manifest, sha):
    source_sha(sha)
    if (not isinstance(manifest, dict)
            or set(manifest) != {'schema_version', 'source_sha', 'file_count', 'total_bytes', 'files', 'excluded_control_files'}
            or type(manifest['schema_version']) is not int or manifest['schema_version'] != 1
            or manifest['source_sha'] != sha
            or manifest['excluded_control_files'] != EXCLUDED_CONTROL_FILES
            or type(manifest['file_count']) is not int
            or type(manifest['total_bytes']) is not int
            or not isinstance(manifest['files'], list)
            or not 1 <= len(manifest['files']) <= MAX_FILES
            or manifest['file_count'] != len(manifest['files'])):
        raise ValueError('Invalid publication manifest identity or counts')
    names = set()
    total = 0
    for row in manifest['files']:
        if not isinstance(row, dict) or set(row) != {'path', 'size', 'sha256'}:
            raise ValueError('Malformed publication file record')
        name = safe_path(row['path'])
        if (name in names or type(row['size']) is not int or not 0 <= row['size'] <= MAX_FILE_BYTES
                or not isinstance(row['sha256'], str) or re.fullmatch(r'[0-9a-f]{64}', row['sha256']) is None):
            raise ValueError('Invalid publication size, digest, or duplicate path')
        names.add(name)
        total += row['size']
    if total > MAX_TOTAL_BYTES or manifest['total_bytes'] != total:
        raise ValueError('Invalid publication total size')
    if not {'results.json', 'index.html'} <= names:
        raise ValueError('Publication lacks results or index')
    return manifest


def read_bounded(path, limit):
    with path.open('rb') as stream:
        data = stream.read(limit + 1)
    if len(data) > limit:
        raise ValueError('File exceeds publication size limit')
    return data


def create_manifest(site, sha):
    source_sha(sha)
    site = Path(site)
    if site.is_symlink() or not site.is_dir():
        raise ValueError('Site must be a regular directory')
    rows = []
    total = 0
    for path in site.rglob('*'):
        if path.is_symlink():
            raise ValueError('Publication cannot contain symlinks')
        if path.is_dir():
            continue
        if not path.is_file():
            raise ValueError('Publication must contain regular files')
        relative = path.relative_to(site).as_posix()
        if relative in [PUBLICATION, *EXCLUDED_CONTROL_FILES]:
            continue
        safe_path(relative)
        data = read_bounded(path, MAX_FILE_BYTES)
        if relative == 'results.json':
            results = parse_json(data)
            if not isinstance(results, dict) or results.get('head_sha') != sha:
                raise ValueError('Collected results do not match tested source')
        total += len(data)
        rows.append({'path': relative, 'size': len(data), 'sha256': hashlib.sha256(data).hexdigest()})
        if len(rows) > MAX_FILES or total > MAX_TOTAL_BYTES:
            raise ValueError('Publication exceeds file or byte limit')
    return validate_manifest({'schema_version': 1, 'source_sha': sha, 'file_count': len(rows),
                              'total_bytes': total, 'files': sorted(rows, key=lambda row: row['path']),
                              'excluded_control_files': EXCLUDED_CONTROL_FILES.copy()}, sha)


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        raise ValueError('Publication redirects are not allowed')


def fetch_bytes(url, limit, timeout):
    request = urllib.request.Request(url, headers={'Cache-Control': 'no-cache',
                                                  'Accept-Encoding': 'identity',
                                                  'User-Agent': 'NNTmux-Pages-verifier/1'})
    opener = urllib.request.build_opener(NoRedirect())
    deadline = time.monotonic() + timeout
    with opener.open(request, timeout=timeout) as response:
        if response.headers.get('Content-Encoding', 'identity') != 'identity':
            raise ValueError('Unexpected publication content encoding')
        chunks = []
        size = 0
        while True:
            if time.monotonic() >= deadline:
                raise ValueError('Publication fetch deadline exceeded')
            # read1 returns available bytes without waiting to fill a large buffer.
            chunk = response.read1(min(65536, limit + 1 - size))
            if not chunk:
                break
            size += len(chunk)
            if size > limit:
                raise ValueError('Public response exceeds expected size')
            chunks.append(chunk)
    return b''.join(chunks)


def verify_publication(manifest, sha, base_url, *, fetch=fetch_bytes, attempts=25,
                       delay=30, timeout=10, deadline_seconds=750):
    validate_manifest(manifest, sha)
    # The deployment URL must name this repository's public Pages site. This also
    # excludes credentials, custom ports, queries, fragments and private hosts.
    if base_url.rstrip('/') + '/' != SITE_URL:
        raise ValueError('Unexpected public Pages URL')
    if (type(attempts) is not int or not 1 <= attempts <= 25 or not 0 <= delay <= 30
            or not 0 < timeout <= 30 or not 0 < deadline_seconds <= 750):
        raise ValueError('Invalid publication retry budget')
    # Pages advertises a 600-second cache lifetime. Keep checking the exact
    # trusted publication through that interval, with time left for file reads.
    # Attempts and the monotonic deadline bound stale or unavailable sites.
    deadline = time.monotonic() + deadline_seconds
    last_error = 'Publication not checked'
    for attempt in range(attempts):
        try:
            def get(name, limit):
                remaining = deadline - time.monotonic()
                if remaining <= 0:
                    raise ValueError('Publication verification deadline exceeded')
                url = SITE_URL + name + '?source=' + sha + '&attempt=' + str(attempt)
                data = fetch(url, limit, min(timeout, remaining))
                if len(data) > limit or time.monotonic() >= deadline:
                    raise ValueError('Public response exceeds byte or time budget')
                return data
            live = parse_json(get(PUBLICATION, MAX_MANIFEST_BYTES))
            validate_manifest(live, sha)
            if live != manifest:
                raise ValueError('Live publication differs from trusted build manifest')
            for row in manifest['files']:
                data = get(row['path'], row['size'])
                if len(data) != row['size'] or hashlib.sha256(data).hexdigest() != row['sha256']:
                    raise ValueError('Public file differs from trusted build: ' + row['path'])
                if row['path'] == 'results.json':
                    results = parse_json(data)
                    if not isinstance(results, dict) or results.get('head_sha') != sha:
                        raise ValueError('Published results source does not match tested source')
            return manifest['file_count']
        except (ValueError, OSError, urllib.error.URLError) as error:
            # Do not print network exception details: they can include private
            # proxy configuration or unexpected response bodies.
            last_error = str(error) if isinstance(error, ValueError) else 'Publication fetch failed'
            remaining = deadline - time.monotonic()
            if attempt + 1 < attempts and remaining > delay:
                time.sleep(delay)
            else:
                break
    raise ValueError('Publication verification failed: ' + last_error)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    commands = parser.add_subparsers(dest='command', required=True)
    create = commands.add_parser('create')
    create.add_argument('--site', type=Path, required=True)
    create.add_argument('--sha', required=True)
    verify = commands.add_parser('verify')
    verify.add_argument('--manifest', type=Path, required=True)
    verify.add_argument('--sha', required=True)
    verify.add_argument('--url', required=True)
    args = parser.parse_args()
    try:
        if args.command == 'create':
            manifest = create_manifest(args.site, args.sha)
            data = json.dumps(manifest, indent=2, sort_keys=True) + '\n'
            if len(data.encode()) > MAX_MANIFEST_BYTES:
                raise ValueError('Publication manifest exceeds size limit')
            (args.site / PUBLICATION).write_text(data)
            print(f"Bound {manifest['file_count']} public files to source {args.sha}")
        else:
            manifest = parse_json(read_bounded(args.manifest, MAX_MANIFEST_BYTES))
            count = verify_publication(manifest, args.sha, args.url)
            print(f'Verified all {count} published files against trusted source {args.sha}')
    except (ValueError, OSError) as error:
        parser.exit(1, (str(error) if isinstance(error, ValueError) else 'Publication file operation failed') + '\n')


if __name__ == '__main__':
    main()

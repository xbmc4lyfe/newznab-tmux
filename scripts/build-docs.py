#!/usr/bin/env python3
"""Assemble trusted repository HTML fragments into a dependency-free docs site."""
import argparse
from html import escape
from html.parser import HTMLParser
import json
import re
from pathlib import Path
import shutil

ROOT = Path(__file__).resolve().parents[1]
PAGES = (
    ('Start here', (('index', 'Overview'), ('differences', 'About this fork'))),
    ('Engineering', (('rust', 'Rust components'), ('performance', 'Performance'),
                     ('processing', 'Processing pipeline'), ('releases', 'Release lifecycle'))),
    ('Operations', (('deployment', 'Deployment'),)),
    ('Evidence', (('pull-requests', 'Pull requests'), ('evidence', 'Evidence guide'))),
)
SLUGS = tuple(slug for _, items in PAGES for slug, _ in items)
REPOSITORY = 'https://github.com/xbmc4lyfe/newznab-tmux'
REWRITE_PROGRESS_MARKER = '<!-- rewrite-progress -->'


class FragmentText(HTMLParser):
    def __init__(self):
        super().__init__(convert_charrefs=True)
        self.parts = []
        self.title = []
        self.in_heading = False
        self.found_heading = False
        self.hidden_depth = 0

    def handle_starttag(self, tag, attrs):
        if tag in ('script', 'style'):
            self.hidden_depth += 1
        if tag == 'br':
            self.parts.append(' ')
            if self.in_heading:
                self.title.append(' ')
        if tag == 'h1' and not self.found_heading:
            self.in_heading = True
            self.found_heading = True

    def handle_endtag(self, tag):
        if tag in ('script', 'style'):
            self.hidden_depth = max(0, self.hidden_depth - 1)
        if tag == 'h1':
            self.in_heading = False
        if tag in ('p', 'li', 'h1', 'h2', 'h3', 'td', 'th', 'div'):
            self.parts.append(' ')

    def handle_data(self, data):
        if not self.hidden_depth:
            self.parts.append(data)
            if self.in_heading:
                self.title.append(data)


def navigation(active):
    groups = []
    for label, items in PAGES:
        links = ''.join(
            f'<a href="{slug}.html"' + (' aria-current="page"' if slug == active else '')
            + f'>{escape(title)}</a>' for slug, title in items)
        groups.append(f'<section class="nav-group"><h2>{escape(label)}</h2>{links}</section>')
    return ''.join(groups)


def safe_file(path):
    """Refuse symlink targets, including parent symlinks, before reading/writing."""
    for part in (path, *path.parents):
        if part.is_symlink():
            raise ValueError(f'Symlinks are not supported: {part}')
    return path


def commit_link(commit):
    return f'<a href="{REPOSITORY}/commit/{escape(commit, quote=True)}"><code>{escape(commit)}</code></a>'


def rewrite_progress(report_path):
    """Render scripts/rewrite-metrics/progress.py's report, or say that this build has none."""
    safe_file(report_path)
    if not report_path.is_file():
        return '<p>Rewrite progress is unavailable in this build.</p>'
    report = json.loads(report_path.read_text(encoding='utf-8'))
    headline = float(report['headline_pct'])
    baseline, entries, master, rust_native = (report[key] for key in ('baseline', 'entries', 'master', 'rust_native'))
    rows = ''.join(
        f'<tr><td>{escape(row["title"])}</td><td>{float(row["ported_pct"]):.1f}%</td><td>{int(row["baseline_live"]):,}</td>'
        f'<td>{int(row["master_live"]):,}</td><td>{int(row["rust_lines"]):,}</td></tr>' for row in report['subsystems'])
    branch = ''
    if rust_native:
        branch = (f' <code>rust-native</code> at {commit_link(rust_native["commit"])} has {int(rust_native["live_php"]):,} lines of live PHP '
                  f'and {int(rust_native["rust_lines"]):,} lines of Rust in its crates, because its PHP was deleted before the port began.')
    if int(master['live_php']) == 0:
        master_note = f'<code>rust-native</code> at {commit_link(master["commit"])} has no live PHP left.'
    else:
        master_note = (f'<code>rust-native</code> at {commit_link(master["commit"])} still has {int(master["live_php"]):,} lines of live PHP, '
                       f'{float(master["removed_pct"]):.1f}% fewer than the baseline.')
    return (
        f'<p class="rewrite-figure"><strong>{headline:.1f}%</strong> of the way to replacing PHP with Rust</p>'
        f'<progress class="rewrite-bar" max="100" value="{headline:.1f}" aria-label="Rust rewrite progress">{headline:.1f}%</progress>'
        f'<p>This is the share of the {int(baseline["live_lines"]):,} live PHP lines at {commit_link(baseline["commit"])} whose entry points are done. '
        f'Each subsystem counts by the share of its entry points that are ported or dropped: {int(entries["done"]):,} of {int(entries["total"]):,} so far. '
        f'Shared code follows the average of the others.</p>'
        f'<p>{master_note}{branch}</p>'
        '<table><thead><tr><th>Subsystem</th><th>Entry points done</th><th>PHP at baseline</th><th>PHP on rust-native</th><th>Rust in the workspace</th></tr></thead>'
        f'<tbody>{rows}</tbody></table>')


def build(source, output, benchmark_page=None):
    source, output = Path(source).absolute(), Path(output).absolute()
    if source.is_symlink() or output.is_symlink():
        raise ValueError('Symlinks are not supported for source or output')
    # Canonicalize the chosen roots (macOS /var and /tmp are system symlinks).
    source = source.parent.resolve() / source.name
    output = output.parent.resolve() / output.name
    safe_file(source)
    safe_file(output)
    # Never permit generated files to overwrite the inputs or repository root.
    if source == output or source in output.parents or output in source.parents:
        raise ValueError('Output must be separate from the documentation source')
    template = safe_file(source / 'template.html').read_text(encoding='utf-8')
    required = ('TITLE', 'NAVIGATION', 'CONTENT', 'SOURCE_URL', 'EDIT_URL', 'PAGE_CLASS')
    for key in required:
        if '{{' + key + '}}' not in template:
            raise ValueError(f'Template is missing placeholder {key}')
    documents = []
    for slug in SLUGS:
        fragment = safe_file(source / 'content' / f'{slug}.html').read_text(encoding='utf-8')
        if REWRITE_PROGRESS_MARKER in fragment:
            fragment = fragment.replace(REWRITE_PROGRESS_MARKER, rewrite_progress(output / 'rewrite-progress.json'))
        parsed = FragmentText()
        parsed.feed(fragment)
        title = ' '.join(''.join(parsed.title).split())
        if not title:
            raise ValueError(f'{slug}.html requires a nonempty first h1')
        documents.append((slug, title, fragment, ' '.join(''.join(parsed.parts).split())))
    assets = [safe_file(source / 'assets' / name) for name in ('site.css', 'site.js')]
    data_dir = safe_file(source / 'data')
    data_files = []
    if data_dir.exists():
        for item in sorted(data_dir.glob('*.json')):
            safe_file(item)
            if not item.is_file() or not re.fullmatch(r'[a-z0-9][a-z0-9-]*\.json', item.name):
                raise ValueError(f'Invalid data file: {item}')
            # Validate JSON before publishing; data remains a file, never HTML.
            json.loads(item.read_text(encoding='utf-8'), parse_constant=lambda value: (_ for _ in ()).throw(ValueError(value)))
            data_files.append(item)
    # Validate all destinations before the first write so a bad path cannot cause a partial build.
    destinations = [output / f'{slug}.html' for slug in SLUGS]
    destinations += [output / name for name in ('search.json', '.nojekyll', 'performance-current.html')]
    destinations += [output / 'assets' / asset.name for asset in assets]
    destinations += [output / 'data' / item.name for item in data_files]
    for destination in destinations:
        safe_file(destination)
    for asset in assets:
        if not asset.is_file():
            raise ValueError(f'Missing asset: {asset}')
    existing_index = output / 'index.html'
    benchmark = None
    if existing_index.is_file():
        existing = existing_index.read_text(encoding='utf-8')
        if '<title>NNTmux Rust benchmarks</title>' in existing and '<h1>NNTmux Rust benchmarks</h1>' in existing:
            benchmark = existing
        elif 'data-nntmux-docs' not in existing:
            raise ValueError('Refusing to overwrite an unrecognized index.html')
    if benchmark_page is not None:
        benchmark_path = Path(benchmark_page).absolute()
        if benchmark_path.is_symlink():
            raise ValueError('Symlinks are not supported for the benchmark page')
        benchmark = (benchmark_path.parent.resolve() / benchmark_path.name).read_text(encoding='utf-8')
    output.mkdir(parents=True, exist_ok=True)
    (output / 'assets').mkdir(exist_ok=True)
    if benchmark is not None:
        heading = '<h1>NNTmux Rust benchmarks</h1>'
        if '<title>NNTmux Rust benchmarks</title>' in benchmark and heading in benchmark:
            body = heading + benchmark.partition(heading)[2]
            body = re.sub(r'</?(?:body|html)\s*>', '', body, flags=re.IGNORECASE)
            values = dict(TITLE='Current benchmark report', NAVIGATION=navigation('performance'),
                          CONTENT=body, SOURCE_URL=f'{REPOSITORY}/blob/rust-native/scripts/benchmark-pages.py',
                          EDIT_URL=f'{REPOSITORY}/edit/rust-native/scripts/benchmark-pages.py', PAGE_CLASS='document')
            benchmark = re.sub(r'\{\{([A-Z_]+)\}\}', lambda match: values.get(match[1], match[0]), template)
        (output / 'performance-current.html').write_text(benchmark, encoding='utf-8')
    elif not (output / 'performance-current.html').exists():
        values = dict(TITLE='Current benchmark report', NAVIGATION=navigation('performance'),
                      CONTENT='<h1>Current benchmark report</h1><p>Current benchmark collection is unavailable in this build. No performance conclusion can be drawn from this placeholder.</p><p><a href="performance.html">Read the performance evidence guide</a>.</p>',
                      SOURCE_URL=f'{REPOSITORY}/blob/rust-native/scripts/benchmark-pages.py',
                      EDIT_URL=f'{REPOSITORY}/edit/rust-native/scripts/benchmark-pages.py', PAGE_CLASS='document')
        placeholder = re.sub(r'\{\{([A-Z_]+)\}\}', lambda match: values.get(match[1], match[0]), template)
        (output / 'performance-current.html').write_text(placeholder, encoding='utf-8')
    search = []
    for slug, title, fragment, plain in documents:
        values = dict(TITLE=escape(title), NAVIGATION=navigation(slug), CONTENT=fragment,
                      SOURCE_URL=f'{REPOSITORY}/blob/rust-native/github-pages/docs/content/{slug}.html',
                      EDIT_URL=f'{REPOSITORY}/edit/rust-native/github-pages/docs/content/{slug}.html',
                      PAGE_CLASS='overview' if slug == 'index' else 'document')
        # Single substitution pass: content containing a placeholder stays content.
        rendered = re.sub(r'\{\{([A-Z_]+)\}\}', lambda match: values.get(match[1], match[0]), template)
        (output / f'{slug}.html').write_text(rendered, encoding='utf-8')
        search.append(dict(title=title, url=f'{slug}.html', text=plain))
    (output / 'search.json').write_text(json.dumps(search, ensure_ascii=True, indent=2) + '\n', encoding='utf-8')
    for asset in assets:
        shutil.copyfile(asset, output / 'assets' / asset.name)
    if data_files:
        (output / 'data').mkdir(exist_ok=True)
        for item in data_files:
            shutil.copyfile(item, output / 'data' / item.name)
    (output / '.nojekyll').write_text('', encoding='utf-8')


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--output', required=True, type=Path)
    parser.add_argument('--source', type=Path, default=ROOT / 'github-pages/docs')
    parser.add_argument('--benchmark-page', type=Path, help='Explicit benchmark dashboard HTML to preserve')
    args = parser.parse_args()
    try:
        build(args.source, args.output, args.benchmark_page)
    except (OSError, ValueError) as error:
        parser.exit(1, f'Documentation build failed: {error}\n')


if __name__ == '__main__':
    main()

import importlib.util
import json
from pathlib import Path
import shutil
import tempfile
import tomllib
import unittest

ROOT = Path(__file__).resolve().parents[2]


def load(name, path):
    spec = importlib.util.spec_from_file_location(name, ROOT / path)
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


progress = load('rewrite_progress', 'scripts/rewrite-metrics/progress.py')
docs = load('docs_site_progress', 'scripts/build-docs.py')
CONFIG = tomllib.loads((ROOT / 'scripts/rewrite-metrics/subsystems.toml').read_text())


def metrics(commit, live):
    rows = [dict(key=row['key'], live=live) for row in CONFIG['subsystem']]
    return dict(commit=commit, php=dict(live=live * len(rows)), subsystems=rows)


def entry(source, status):
    return f'[[entry]]\nkind = "command"\nid = "{source}"\nsource = "{source}"\nstatus = "{status}"\n\n'


class RewriteProgressTest(unittest.TestCase):
    def setUp(self):
        temp = tempfile.TemporaryDirectory()
        self.addCleanup(temp.cleanup)
        self.root = Path(temp.name).resolve()

    def test_baseline_map_adds_up(self):
        self.assertEqual(CONFIG['baseline']['live_lines'], sum(row['baseline_live'] for row in CONFIG['subsystem']))

    def test_ledgers_skip_excluded_files_and_ports_of_rust(self):
        ledger = self.root / 'rust/porting/ledger'
        ledger.mkdir(parents=True)
        (ledger / 'dropped.toml').write_text(entry('Vendor@route', 'dropped'))
        (ledger / 'ws-formats.toml').write_text(
            entry('src/yenc.rs', 'ported') + entry('app/A.php', 'ported') + entry('app/B.php', 'dropped') + entry('app/C.php', 'todo'))
        self.assertEqual({'ingest': [2, 3]}, progress.read_ledgers(self.root, {'dropped'}, CONFIG['subsystem']))

    def test_shared_and_unlisted_workstreams_go_by_source_path(self):
        rows = CONFIG['subsystem']
        self.assertEqual('categorization', progress.owner('ws-naming', 'app/Console/Commands/TestCategorization.php', rows))
        self.assertEqual('namefix_predb', progress.owner('ws-naming', 'app/Console/Commands/FixReleaseNames.php', rows))
        self.assertEqual('namefix_predb', progress.owner('ws-naming', 'unknown', rows))
        self.assertEqual('api', progress.owner('ws-renamed', 'App\\Http\\Controllers\\Api\\ApiController@api', rows))
        self.assertEqual('platform', progress.owner('ws-renamed', 'unknown', rows))

    def test_rust_lines_count_src_only(self):
        src = self.root / 'rust/crates/api/src'
        src.mkdir(parents=True)
        (src / 'lib.rs').write_text('a\nb\n')
        (src.parent / 'tests').mkdir()
        (src.parent / 'tests/it.rs').write_text('c\n')
        self.assertEqual({'api': 2}, progress.rust_lines(self.root))

    def test_headline_weighs_by_baseline_lines(self):
        report = progress.compute(CONFIG, metrics('aaa', 10), metrics('bbb', 0), {'api': [1, 2], 'ops': [0, 5]}, {'api': 7, 'new': 3})
        api = 5276 * 0.5
        expected = round(100 * api / (188303 - 23179), 1)
        self.assertEqual(expected, report['headline_pct'])
        rows = {row['key']: row for row in report['subsystems']}
        self.assertEqual(50.0, rows['api']['ported_pct'])
        self.assertEqual(expected, rows['platform']['ported_pct'])
        self.assertEqual(7, rows['api']['rust_lines'])
        self.assertEqual(3, rows['platform']['rust_lines'])
        self.assertEqual({'done': 1, 'total': 7}, report['entries'])
        self.assertEqual(10, rows['api']['master_live'])

    def test_report_without_rust_native(self):
        report = progress.compute(CONFIG, metrics('aaa', 1), None, {'api': [1, 1]}, {})
        self.assertIsNone(report['rust_native'])
        self.assertEqual(1, report['entries']['total'])

    def test_front_page_renders_and_escapes(self):
        report = progress.compute(CONFIG, metrics('aaa', 10), metrics('bbb', 0), {'api': [1, 2]}, {'api': 7})
        report['subsystems'][0]['title'] = '<script>'
        path = self.root / 'rewrite-progress.json'
        path.write_text(json.dumps(report))
        page = docs.rewrite_progress(path)
        self.assertIn('<progress', page)
        self.assertIn(f'{report["headline_pct"]:.1f}%', page)
        self.assertIn('&lt;script&gt;', page)
        self.assertNotIn('<script>', page)
        self.assertIn('rust-native', page)
        report['rust_native'] = None
        path.write_text(json.dumps(report))
        self.assertIn('master', docs.rewrite_progress(path))

    def test_marker_replaced_with_notice_when_no_report(self):
        source = self.root / 'source'
        (source / 'content').mkdir(parents=True)
        shutil.copyfile(ROOT / 'github-pages/docs/template.html', source / 'template.html')
        shutil.copytree(ROOT / 'github-pages/docs/assets', source / 'assets')
        for slug in docs.SLUGS:
            (source / 'content' / f'{slug}.html').write_text(f'<h1>{slug}</h1>{docs.REWRITE_PROGRESS_MARKER}')
        docs.build(source, self.root / 'site')
        page = (self.root / 'site/index.html').read_text()
        self.assertIn('unavailable', page)
        self.assertNotIn(docs.REWRITE_PROGRESS_MARKER, page)


if __name__ == '__main__':
    unittest.main()

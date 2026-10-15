import importlib.util
import json
from pathlib import Path
import shutil
import tempfile
import unittest
from urllib.parse import urljoin, urlparse

ROOT = Path(__file__).resolve().parents[2]
SPEC = importlib.util.spec_from_file_location('docs_site', ROOT / 'scripts/build-docs.py')
docs = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(docs)


class DocumentationSiteTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        self.source = self.root / 'source'
        self.output = self.root / 'site'
        (self.source / 'content').mkdir(parents=True)
        shutil.copyfile(ROOT / 'github-pages/docs/template.html', self.source / 'template.html')
        shutil.copytree(ROOT / 'github-pages/docs/assets', self.source / 'assets')
        for slug in docs.SLUGS:
            (self.source / 'content' / f'{slug}.html').write_text(
                f'<h1>{slug} &amp; evidence</h1><p>Trusted article.</p>', encoding='utf-8')

    def test_build_complete_navigation_assets_and_project_subpath(self):
        docs.build(self.source, self.output)
        for slug in docs.SLUGS:
            page = (self.output / f'{slug}.html').read_text()
            self.assertIn(f'href="{slug}.html" aria-current="page"', page)
            self.assertIn('https://github.com/NNTmux/newznab-tmux', page)
            self.assertIn('href="#main"', page)
            self.assertIn('View page source', page)
            self.assertIn('Edit this page', page)
            self.assertNotIn('{{', page)
            for name in ('assets/site.css', 'assets/site.js', 'search.json', 'performance.html'):
                resolved = urlparse(urljoin('https://example.org/newznab-tmux/' + slug + '.html', name))
                self.assertTrue(resolved.path.startswith('/newznab-tmux/'))
                self.assertTrue((self.output / name).is_file())
        self.assertTrue((self.output / '.nojekyll').exists())
        self.assertIn('unavailable', (self.output / 'performance-current.html').read_text())
        first = (self.output / 'index.html').read_bytes()
        docs.build(self.source, self.output)
        self.assertEqual(first, (self.output / 'index.html').read_bytes())

    def test_search_extracts_plain_text_and_escapes_titles(self):
        (self.source / 'content/index.html').write_text(
            '<h1>&lt;img src=x onerror=alert(1)&gt; &amp; title</h1>'
            '<p>Text &lt;script&gt;literal&lt;/script&gt; {{TITLE}}</p>'
            '<script>secret-code</script><style>secret-style</style>')
        docs.build(self.source, self.output)
        rows = json.loads((self.output / 'search.json').read_text())
        row = next(row for row in rows if row['url'] == 'index.html')
        self.assertEqual('<img src=x onerror=alert(1)> & title', row['title'])
        self.assertIn('<script>literal</script>', row['text'])
        self.assertNotIn('secret-code', row['text'])
        self.assertNotIn('secret-style', row['text'])
        page = (self.output / 'index.html').read_text()
        self.assertIn('<title>&lt;img src=x onerror=alert(1)&gt; &amp; title', page)
        self.assertIn('literal&lt;/script&gt; {{TITLE}}', page)
        script = (self.output / 'assets/site.js').read_text()
        self.assertNotIn('innerHTML', script)
        self.assertIn('link.textContent = entry.title', script)
        self.assertIn('preview.textContent', script)

    def test_missing_inputs_fail_before_mutation(self):
        for relative in ('content/rust.html', 'assets/site.js', 'template.html'):
            path = self.source / relative
            saved = path.read_bytes()
            path.unlink()
            with self.assertRaises((ValueError, OSError)):
                docs.build(self.source, self.output)
            self.assertFalse(self.output.exists())
            path.write_bytes(saved)
        (self.source / 'content/rust.html').write_text('<p>No heading.</p>')
        with self.assertRaisesRegex(ValueError, 'first h1'):
            docs.build(self.source, self.output)
        self.assertFalse(self.output.exists())

    def test_benchmark_preserved_and_unknown_index_refused(self):
        self.output.mkdir()
        benchmark = '<title>NNTmux Rust benchmarks</title><h1>NNTmux Rust benchmarks</h1><p>Data</p>'
        (self.output / 'index.html').write_text(benchmark)
        (self.output / 'results.json').write_text('{"unchanged": true}')
        docs.build(self.source, self.output)
        preserved = (self.output / 'performance-current.html').read_text()
        self.assertIn('<h1>NNTmux Rust benchmarks</h1><p>Data</p>', preserved)
        self.assertIn('https://github.com/NNTmux/newznab-tmux', preserved)
        docs.build(self.source, self.output)
        self.assertEqual(preserved, (self.output / 'performance-current.html').read_text())
        self.assertEqual('{"unchanged": true}', (self.output / 'results.json').read_text())
        (self.output / 'index.html').write_text('Other website')
        with self.assertRaisesRegex(ValueError, 'unrecognized'):
            docs.build(self.source, self.output)
        self.assertEqual('Other website', (self.output / 'index.html').read_text())

    def test_dashboard_wrapping_preserves_measurements_and_relative_evidence_links(self):
        self.output.mkdir()
        content = ('<h1>NNTmux Rust benchmarks</h1>'
                   '<p>Current unavailable. Last valid result remains historical.</p>'
                   '<a href="results.json">JSON</a><a href="benchmarks/">History</a>'
                   '<table><tr><td>1.23</td><td>not established</td></tr></table>'
                   '<details><summary>Last valid</summary><pre>{&quot;p99&quot;: 4.5}</pre></details>')
        (self.output / 'index.html').write_text(
            '<!doctype html><html><title>NNTmux Rust benchmarks</title><style>old</style>'
            + content + '</html>')
        docs.build(self.source, self.output)
        wrapped = (self.output / 'performance-current.html').read_text()
        self.assertIn(content, wrapped)
        self.assertNotIn('<style>old</style>', wrapped)
        self.assertEqual(1, wrapped.count('<html'))
        self.assertEqual(1, wrapped.count('</html>'))
        for link in ('results.json', 'benchmarks/'):
            self.assertIn(f'href="{link}"', wrapped)
            resolved = urlparse(urljoin('https://example.org/project/performance-current.html', link))
            self.assertTrue(resolved.path.startswith('/project/'))
        self.assertIn('href="assets/site.css"', wrapped)

    def test_first_heading_with_line_break_retains_word_boundary(self):
        (self.source / 'content/index.html').write_text('<h1>Built on NNTmux.<br><span>Measured.</span></h1><h1>Ignore</h1>')
        docs.build(self.source, self.output)
        row = next(row for row in json.loads((self.output / 'search.json').read_text()) if row['url'] == 'index.html')
        self.assertEqual('Built on NNTmux. Measured.', row['title'])

    def test_explicit_dashboard(self):
        report = self.root / 'dashboard.html'
        report.write_text('<h1>Explicit report</h1>')
        docs.build(self.source, self.output, report)
        self.assertEqual(report.read_bytes(), (self.output / 'performance-current.html').read_bytes())

    def test_symlink_and_source_output_overlap_refused(self):
        outside = self.root / 'outside'
        outside.mkdir()
        (outside / 'sentinel').write_text('preserve')
        self.output.symlink_to(outside, target_is_directory=True)
        with self.assertRaisesRegex(ValueError, 'Symlink'):
            docs.build(self.source, self.output)
        self.output.unlink()
        self.output.mkdir()
        (self.output / 'assets').symlink_to(outside, target_is_directory=True)
        with self.assertRaisesRegex(ValueError, 'Symlink'):
            docs.build(self.source, self.output)
        (self.output / 'assets').unlink()
        for output in (self.source, self.source / 'generated', self.root):
            with self.assertRaisesRegex(ValueError, 'separate'):
                docs.build(self.source, output)
        path = self.source / 'content/rust.html'
        path.unlink()
        path.symlink_to(outside / 'sentinel')
        with self.assertRaisesRegex(ValueError, 'Symlink'):
            docs.build(self.source, self.output)
        self.assertEqual('preserve', (outside / 'sentinel').read_text())

    def test_flat_json_copy_and_invalid_or_symlinked_data_refused(self):
        data = self.source / 'data'
        data.mkdir()
        (data / 'fork-pull-requests.json').write_text('{"rows": []}')
        (data / 'ignore.txt').write_text('ignored')
        (data / 'nested').mkdir()
        (data / 'nested/hidden.json').write_text('{}')
        docs.build(self.source, self.output)
        self.assertEqual('{"rows": []}', (self.output / 'data/fork-pull-requests.json').read_text())
        self.assertFalse((self.output / 'data/ignore.txt').exists())
        self.assertFalse((self.output / 'data/nested').exists())
        (data / 'escape.json').symlink_to(self.root / 'outside.json')
        with self.assertRaisesRegex(ValueError, 'Symlink'):
            docs.build(self.source, self.output)
        (data / 'escape.json').unlink()
        (data / 'invalid.json').write_text('{broken')
        with self.assertRaises(ValueError):
            docs.build(self.source, self.output)


if __name__ == '__main__':
    unittest.main()

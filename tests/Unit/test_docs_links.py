import hashlib
import importlib.util
import json
from pathlib import Path
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[2]
SPEC = importlib.util.spec_from_file_location('docs_links', ROOT / 'scripts/check-docs.py')
links = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(links)


class DocumentationLinksTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.site = Path(self.temp.name).resolve() / 'site'
        self.site.mkdir()

    def write(self, name, text):
        target = self.site / name
        target.parent.mkdir(parents=True, exist_ok=True)
        target.write_text(text, encoding='utf-8')
        return target

    def test_relative_reports_svg_unicode_anchors_and_external_schemes(self):
        self.write('index.html', '<a href="benchmarks/#history">Historical</a>'
                   '<a href="https://example.org/absent">External</a>'
                   '<a href="//example.org/absent">External</a>'
                   '<a href="mailto:author@example.org">Mail</a>'
                   '<img src="data:image/png;base64,AAA">')
        self.write('benchmarks/index.html', '<h1 id="history">Reports</h1>'
                   '<a href="runs/1/report.html?view=all#%C3%A9vidence">Detail</a>')
        self.write('benchmarks/runs/1/report.html', '<h1 id="évidence">Evidence</h1>'
                   '<img src="charts/speedup.svg"><a href="../../../index.html">Home</a>'
                   '<a href="#legacy">Legacy</a><a name="legacy"></a>')
        self.write('benchmarks/runs/1/charts/speedup.svg', '<svg/>')
        self.assertEqual([], links.check_site(self.site))

    def test_missing_paths_and_fragments_include_source_location(self):
        self.write('index.html', '<a href="missing.html">Broken</a>\n<a href="page.html#absent">Anchor</a>')
        self.write('page.html', '<h1 id="present">Page</h1>')
        errors = links.check_site(self.site)
        self.assertTrue(any('index.html:1: missing local target' in error for error in errors))
        self.assertTrue(any('index.html:2: missing fragment' in error for error in errors))

    def test_encoded_and_root_path_escape_rejected_but_parent_inside_site_valid(self):
        self.write('index.html', '<h1>Home</h1>')
        self.write('sub/page.html', '<a href="../index.html">Good</a>'
                   '<a href="../../outside.html">Escape</a>'
                   '<a href="%2e%2e/%2e%2e/outside.html">Encoded escape</a>'
                   '<a href="/../outside.html">Root escape</a>')
        (self.site.parent / 'outside.html').write_text('outside')
        errors = links.check_site(self.site)
        self.assertEqual(3, len(errors))
        self.assertTrue(all('escapes site root' in error for error in errors))

    def test_symlink_target_cannot_escape(self):
        outside = self.site.parent / 'outside.svg'
        outside.write_text('<svg/>')
        (self.site / 'escape.svg').symlink_to(outside)
        self.write('index.html', '<img src="escape.svg">')
        self.assertTrue(any('symlink escapes site root' in error for error in links.check_site(self.site)))

    def test_duplicate_ids_fail(self):
        self.write('index.html', '<h1 id="same">One</h1><h2 id="same">Two</h2>')
        self.assertIn("index.html: duplicate HTML id 'same'", links.check_site(self.site))

    def test_summary_integrity_and_checksum_corruption(self):
        self.write('index.html', '<h1>Evidence</h1>')
        summary = self.write('data/historical-php-x86.json', '{"rows": []}\n')
        manifest = {'summaries': [{'file': summary.name, 'sha256': hashlib.sha256(summary.read_bytes()).hexdigest()}]}
        self.write('data/historical-provenance.json', json.dumps(manifest))
        self.assertEqual([], links.check_site(self.site))
        summary.write_text('{"rows": [123]}\n')
        self.assertTrue(any('historical evidence checksum mismatch' in error for error in links.check_site(self.site)))
        summary.unlink()
        self.assertTrue(any('invalid retained evidence' in error for error in links.check_site(self.site)))

    def test_manifest_filename_traversal_and_duplicate_rows_fail(self):
        self.write('index.html', '<h1>Evidence</h1>')
        manifest_path = self.write('data/historical-provenance.json', json.dumps({'summaries': [{'file': '../outside.json', 'sha256': 'a' * 64}]}))
        self.assertTrue(any('flat JSON filename' in error for error in links.check_site(self.site)))
        summary = self.write('data/summary.json', '{}')
        row = {'file': summary.name, 'sha256': hashlib.sha256(summary.read_bytes()).hexdigest()}
        manifest_path.write_text(json.dumps({'summaries': [row, row]}))
        self.assertTrue(any('duplicate summary' in error for error in links.check_site(self.site)))

    def test_errors_bounded_and_empty_site_fails(self):
        self.assertTrue(links.check_site(self.site))
        self.write('index.html', '<a href="missing.html">Broken</a>' * 120)
        errors = links.check_site(self.site)
        self.assertEqual(101, len(errors))
        self.assertIn('omitted', errors[-1])


if __name__ == '__main__':
    unittest.main()

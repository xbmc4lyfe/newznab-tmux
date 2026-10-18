"""Source-only inventory contract; no Rust builds or benchmark execution."""
import hashlib
import importlib.util
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[2]
SCRIPT = ROOT / 'scripts/benchmark_rust_inventory.py'
SOURCE = 'docker/app/nzb-yenc-ffi/src/lib.rs'


def module():
    spec = importlib.util.spec_from_file_location('rust_inventory', SCRIPT)
    loaded = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(loaded)
    return loaded


class RustInventoryTest(unittest.TestCase):
    def test_every_current_export_has_a_source_location_and_explicit_scope(self):
        self.assertTrue(SCRIPT.exists(), 'source-derived inventory helper is missing')
        rows = module().inventory(ROOT)
        self.assertEqual(len(rows), 43)
        self.assertEqual(len({row['name'] for row in rows}), 43)
        self.assertEqual(module().check(ROOT), [])
        for row in rows:
            path = ROOT / row['target']
            self.assertEqual(row['source_sha256'], hashlib.sha256(path.read_bytes()).hexdigest())
            self.assertIn(row['name'], path.read_text().splitlines()[row['line'] - 1])
            self.assertNotEqual(row['coverage'], 'unclassified-gap')
            for caller in row['callers']:
                self.assertTrue((ROOT / caller).is_file())
                self.assertIn(row['name'], (ROOT / caller).read_text())

    def test_experimental_rapidyenc_exports_and_profile_are_explicit_unmeasured_gaps(self):
        experimental = {
            'rapidyenc_decode_kernel', 'rapidyenc_encode_max_length', 'rapidyenc_decode',
            'rapidyenc_decode_incremental', 'rapidyenc_crc_kernel', 'rapidyenc_crc_combine',
            'rapidyenc_crc_zeros', 'rapidyenc_crc_unzero', 'rapidyenc_crc_multiply',
            'rapidyenc_crc_2pow', 'rapidyenc_crc_256pow',
        }
        rows = {row['name']: row for row in module().inventory(ROOT)}
        source = (ROOT / SOURCE).read_text()
        for name in experimental:
            with self.subTest(name=name):
                row = rows[name]
                self.assertEqual(row['coverage'], 'experimental-compatibility-gap')
                self.assertEqual(row['evidence_kinds'], [])
                self.assertEqual(row['callers'], [])
                self.assert_experimental_feature_gate(source, name)
        profile = rows['nntmux_yenc_implementation_profile_v1']
        self.assertEqual(profile['coverage'], 'profile-unmeasured-gap')
        self.assertEqual(profile['evidence_kinds'], [])
        self.assertEqual(profile['callers'], [])

        name = 'rapidyenc_decode_kernel'
        gated_prefix = ('#[cfg(feature = "experimental-rapidyenc-abi")]\n'
                        '#[unsafe(no_mangle)]\n'
                        'pub extern "C" fn ' + name + '(')
        ungated_source = source.replace(gated_prefix,
                                        '#[unsafe(no_mangle)]\n'
                                        'pub extern "C" fn ' + name + '(', 1)
        self.assertNotEqual(ungated_source, source)
        with self.assertRaises(AssertionError):
            self.assert_experimental_feature_gate(ungated_source, name)

    def assert_experimental_feature_gate(self, source, name):
        position = source.index('fn ' + name + '(')
        lines = source.splitlines()
        function_line = source.count('\n', 0, position)
        self.assertGreaterEqual(function_line, 2)
        self.assertEqual(lines[function_line - 1].strip(), '#[unsafe(no_mangle)]')
        self.assertEqual(lines[function_line - 2].strip(),
                         '#[cfg(feature = "experimental-rapidyenc-abi")]')

    def test_duplicate_linker_exports_remain_rejected(self):
        root = self.fixture('''
#[unsafe(no_mangle)]
extern "C" fn duplicate_export() {}
#[unsafe(no_mangle)]
extern "C" fn duplicate_export() {}
''')
        self.assertIn('duplicate export: duplicate_export', module().check(root))

    def test_scope_does_not_turn_support_or_legacy_apis_into_speedup_claims(self):
        self.assertTrue(SCRIPT.exists(), 'source-derived inventory helper is missing')
        rows = {row['name']: row for row in module().inventory(ROOT)}
        self.assertEqual(rows['rapidyenc_version']['coverage'], 'lifecycle-included')
        self.assertEqual(rows['nntmux_metadata_string_free']['coverage'], 'lifecycle-included')
        self.assertEqual(rows['nntmux_article_identity_json']['coverage'], 'compatibility-gap')
        self.assertEqual(rows['nntmux_article_identity_json']['evidence_kinds'], [])
        self.assertEqual(rows['nntmux_article_identity_v2_json']['coverage'], 'shared-reference-comparison')
        self.assertEqual(rows['nntmux_article_identity_v2_json']['evidence_kinds'], ['metadata-modules'])
        self.assertEqual(rows['nntmux_nntp_header_pair_comparison_json']['coverage'], 'unmeasured-gap')
        self.assertEqual(rows['nntmux_nntp_header_pair_comparison_json']['evidence_kinds'], [])

    def fixture(self, content):
        temp = tempfile.TemporaryDirectory()
        self.addCleanup(temp.cleanup)
        root = Path(temp.name)
        path = root / SOURCE
        path.parent.mkdir(parents=True)
        path.write_text(content)
        subprocess.run(['git', 'init', '-q', str(root)], check=True)
        subprocess.run(['git', '-C', str(root), 'add', SOURCE], check=True)
        return root

    def test_new_multiline_export_is_visible_and_check_fails_ignoring_comments_and_strings(self):
        self.assertTrue(SCRIPT.exists(), 'source-derived inventory helper is missing')
        root = self.fixture('''/* nested /* #[unsafe(no_mangle)] pub extern "C" fn bogus() {} */ end */
// #[unsafe(no_mangle)] pub extern "C" fn commented() {}
const TEXT: &str = r###"#[unsafe(no_mangle)] pub extern "C" fn in_string() {}"###;
#[unsafe(no_mangle)]
pub unsafe extern "C"
fn future_export(
) {}
''')
        rows = module().inventory(root)
        self.assertEqual([row['name'] for row in rows], ['future_export'])
        self.assertEqual(rows[0]['line'], 6)
        self.assertEqual(rows[0]['coverage'], 'unclassified-gap')
        result = subprocess.run([sys.executable, '-B', str(SCRIPT), '--check', '--root', str(root)],
                                capture_output=True, text=True, env={**os.environ, 'PYTHONDONTWRITEBYTECODE': '1'})
        self.assertNotEqual(result.returncode, 0)
        self.assertIn('future_export', result.stdout + result.stderr)

    def test_linker_exports_do_not_depend_on_rust_visibility_or_function_name(self):
        root = self.fixture('''
#[unsafe(export_name = "future_export")]
pub extern "C" fn internal_name() {}
#[unsafe(no_mangle)]
unsafe extern "C" fn private_export() {}
#[no_mangle]
pub(crate) extern "C" fn restricted_export() {}
''')
        rows = module().inventory(root)
        self.assertEqual([row['name'] for row in rows],
                         ['future_export', 'private_export', 'restricted_export'])
        issues = module().check(root)
        for name in ('future_export', 'private_export', 'restricted_export'):
            self.assertIn('unclassified export: ' + name, issues)

    def test_non_c_abi_and_rust_abi_exports_are_visible(self):
        root = self.fixture('''
#[unsafe(no_mangle)]
extern "C-unwind" fn unwind_export() {}
#[no_mangle]
fn rust_export() {}
''')
        self.assertEqual([row['name'] for row in module().inventory(root)],
                         ['unwind_export', 'rust_export'])

    def test_unsupported_exported_declarations_fail_closed(self):
        for declaration in ('#[unsafe(no_mangle)] pub static EXPORTED: u8 = 1;',
                            '#[unsafe(export_name = r"raw_name")] extern "C" fn internal() {}'):
            with self.subTest(declaration=declaration):
                root = self.fixture(declaration)
                with self.assertRaises(ValueError):
                    module().inventory(root)

    def test_removed_declared_export_fails_check(self):
        self.assertTrue(SCRIPT.exists(), 'source-derived inventory helper is missing')
        root = self.fixture((ROOT / SOURCE).read_text().replace('fn rapidyenc_version(', 'fn removed_version('))
        issues = module().check(root)
        self.assertTrue(any('rapidyenc_version' in issue and 'missing' in issue for issue in issues))
        self.assertTrue(any('removed_version' in issue for issue in issues))


if __name__ == '__main__':
    unittest.main()

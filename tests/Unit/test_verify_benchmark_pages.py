import copy
import hashlib
import importlib.util
import json
import os
import shlex
import subprocess
import textwrap
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch

ROOT = Path(__file__).parents[2]
SCRIPT = ROOT / 'scripts/verify-benchmark-pages.py'
SHA = 'a' * 40
URL = 'https://xbmc4lyfe.github.io/newznab-tmux/'
if SCRIPT.exists():
    spec = importlib.util.spec_from_file_location('publication', SCRIPT)
    publication = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(publication)
else:
    publication = None


class PublicationTest(unittest.TestCase):
    def setUp(self):
        self.assertIsNotNone(publication, 'Publication verifier is not implemented')
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.site = Path(self.temp.name)
        self.files = {'index.html': b'<html>report</html>',
                      'results.json': json.dumps({'head_sha': SHA, 'rows': []}).encode(),
                      'benchmarks/summary.json': b'{"latency": 42}',
                      'benchmarks/source.rs': b'fn main() {}'}
        for name, data in self.files.items():
            target = self.site / name
            target.parent.mkdir(exist_ok=True)
            target.write_bytes(data)
        self.manifest = publication.create_manifest(self.site, SHA)

    def fetch(self, url, limit, timeout):
        name = url.removeprefix(URL).split('?', 1)[0]
        if name == 'publication.json':
            return json.dumps(self.manifest).encode()
        if name not in self.files:
            raise OSError('missing file')
        return self.files[name]

    def verify(self, **kwargs):
        return publication.verify_publication(self.manifest, SHA, URL, fetch=self.fetch,
                                              attempts=kwargs.pop('attempts', 1), delay=0, **kwargs)

    def test_manifest_covers_every_file_and_binds_source(self):
        self.assertEqual(SHA, self.manifest['source_sha'])
        self.assertEqual(4, self.manifest['file_count'])
        self.assertEqual(sum(map(len, self.files.values())), self.manifest['total_bytes'])
        files = {row['path']: row for row in self.manifest['files']}
        self.assertEqual(set(self.files), set(files))
        self.assertEqual(hashlib.sha256(b'{"latency": 42}').hexdigest(), files['benchmarks/summary.json']['sha256'])
        self.assertEqual(4, self.verify())

    def test_only_both_pages_control_files_are_explicitly_excluded(self):
        (self.site / '.nojekyll').write_bytes(b'')
        (self.site / 'benchmarks/.nojekyll').write_bytes(b'')
        (self.site / 'benchmarks/ordinary.nojekyll').write_bytes(b'public content')
        (self.site / '.well-known').write_bytes(b'public content')
        manifest = publication.create_manifest(self.site, SHA)
        self.assertEqual(['.nojekyll', 'benchmarks/.nojekyll'], manifest.get('excluded_control_files'))
        self.assertEqual(6, manifest['file_count'])
        self.assertIn('benchmarks/ordinary.nojekyll', {row['path'] for row in manifest['files']})
        self.assertIn('.well-known', {row['path'] for row in manifest['files']})
        manifest['excluded_control_files'].append('results.json')
        with self.assertRaises(ValueError):
            publication.validate_manifest(manifest, SHA)

    def test_wrong_results_source_is_rejected(self):
        (self.site / 'results.json').write_text(json.dumps({'head_sha': 'b' * 40}))
        with self.assertRaises(ValueError):
            publication.create_manifest(self.site, SHA)

    def test_existing_manifest_is_excluded_and_symlinks_are_rejected(self):
        (self.site / 'publication.json').write_text('{}')
        self.assertEqual(4, publication.create_manifest(self.site, SHA)['file_count'])
        (self.site / 'link').symlink_to(self.site / 'index.html')
        with self.assertRaises(ValueError):
            publication.create_manifest(self.site, SHA)

    def test_missing_or_modified_metric_is_rejected(self):
        del self.files['benchmarks/summary.json']
        with self.assertRaises(ValueError):
            self.verify()
        self.files['benchmarks/summary.json'] = b'{"latency": 43}'
        with self.assertRaises(ValueError):
            self.verify()

    def test_live_manifest_cannot_replace_trusted_expected_hashes(self):
        trusted = copy.deepcopy(self.manifest)
        self.files['benchmarks/summary.json'] = b'{"latency": 43}'
        for row in self.manifest['files']:
            if row['path'] == 'benchmarks/summary.json':
                row['sha256'] = hashlib.sha256(self.files[row['path']]).hexdigest()
        with self.assertRaises(ValueError):
            publication.verify_publication(trusted, SHA, URL, fetch=self.fetch, attempts=1)

    def test_stale_publication_never_passes(self):
        expected = copy.deepcopy(self.manifest)
        self.manifest['source_sha'] = 'b' * 40
        with self.assertRaises(ValueError):
            publication.verify_publication(expected, SHA, URL, fetch=self.fetch, attempts=2, delay=0)

    def test_retry_fetches_entire_site_until_correct(self):
        calls = []
        def fetch(url, limit, timeout):
            calls.append(url)
            if len(calls) == 1:
                raise OSError('CDN not ready')
            return self.fetch(url, limit, timeout)
        self.assertEqual(4, publication.verify_publication(self.manifest, SHA, URL,
                         fetch=fetch, attempts=2, delay=0))
        self.assertEqual(6, len(calls))

    def test_failed_fetch_and_retry_limit_are_enforced(self):
        calls = []
        def fetch(*args):
            calls.append(args)
            raise OSError('unavailable')
        with self.assertRaises(ValueError):
            publication.verify_publication(self.manifest, SHA, URL, fetch=fetch, attempts=2, delay=0)
        self.assertEqual(2, len(calls))

    def propagation(self, *, tampered=False, never_current=False):
        clock = [0.0]
        calls = []
        stale = copy.deepcopy(self.manifest)
        # A different publication of the same source must remain detectable.
        stale['files'][0]['sha256'] = '0' * 64

        def fetch(url, limit, timeout):
            name = url.removeprefix(URL).split('?', 1)[0]
            calls.append((clock[0], name))
            if name == 'publication.json' and (clock[0] < 600 or never_current):
                return json.dumps(stale).encode()
            if tampered and name == 'benchmarks/summary.json':
                return b'{"latency": 43}'
            return self.fetch(url, limit, timeout)

        def sleep(seconds):
            clock[0] += seconds

        with patch.object(publication.time, 'monotonic', side_effect=lambda: clock[0]), \
                patch.object(publication.time, 'sleep', side_effect=sleep):
            try:
                result = publication.verify_publication(self.manifest, SHA, URL, fetch=fetch)
            finally:
                self.assertLessEqual(clock[0], 750)
                self.assertLessEqual(sum(name == 'publication.json' for _, name in calls), 25)
        return result, clock[0], calls

    def test_default_budget_covers_pages_cache_lifetime_then_checks_every_file(self):
        count, elapsed, calls = self.propagation()
        self.assertEqual(4, count)
        self.assertGreaterEqual(elapsed, 600)
        self.assertLessEqual(elapsed, 750)
        self.assertEqual(set(self.files), {name for _, name in calls if name != 'publication.json'})

    def test_propagation_never_accepts_a_stale_or_tampered_publication(self):
        for options in ({'never_current': True}, {'tampered': True}):
            with self.subTest(options=options), self.assertRaises(ValueError):
                self.propagation(**options)

    def test_unsafe_urls_are_rejected_before_fetch(self):
        for url in ('http://xbmc4lyfe.github.io/newznab-tmux/', 'https://evil.invalid/',
                    'https://xbmc4lyfe.github.io@evil.invalid/newznab-tmux/',
                    URL + '../secret', URL + '?redirect=evil', URL + '#fragment',
                    'https://xbmc4lyfe.github.io:444/newznab-tmux/'):
            with self.subTest(url=url), self.assertRaises(ValueError):
                publication.verify_publication(self.manifest, SHA, url, fetch=self.fetch, attempts=1)

    def test_unsafe_and_duplicate_paths_are_rejected(self):
        for path in ('../secret', '/etc/passwd', 'a//b', 'a/./b', 'a/../b', 'a\\b',
                     'https://evil.invalid/x', 'a%2fb', 'publication.json'):
            manifest = copy.deepcopy(self.manifest)
            manifest['files'][0]['path'] = path
            with self.subTest(path=path), self.assertRaises(ValueError):
                publication.validate_manifest(manifest, SHA)
        manifest = copy.deepcopy(self.manifest)
        manifest['files'][1]['path'] = manifest['files'][0]['path']
        with self.assertRaises(ValueError):
            publication.validate_manifest(manifest, SHA)

    def test_malformed_and_unbounded_manifests_are_rejected(self):
        for key, value in (('schema_version', 2), ('file_count', True), ('total_bytes', -1),
                           ('source_sha', 'b' * 40), ('files', [])):
            manifest = copy.deepcopy(self.manifest)
            manifest[key] = value
            with self.subTest(key=key), self.assertRaises(ValueError):
                publication.validate_manifest(manifest, SHA)
        manifest = copy.deepcopy(self.manifest)
        manifest['files'][0]['size'] = 2**40
        with self.assertRaises(ValueError):
            publication.validate_manifest(manifest, SHA)
        manifest['files'][0]['size'] = True
        with self.assertRaises(ValueError):
            publication.validate_manifest(manifest, SHA)

    def test_oversized_response_cannot_pass(self):
        def fetch(url, limit, timeout):
            return b'x' * (limit + 1)
        with self.assertRaises(ValueError):
            publication.verify_publication(self.manifest, SHA, URL, fetch=fetch, attempts=1)

    def test_deadline_prevents_a_late_success(self):
        with patch.object(publication.time, 'monotonic', side_effect=[0, 0, 1, 1]):
            with self.assertRaisesRegex(ValueError, 'time budget'):
                self.verify(deadline_seconds=0.5)

    def test_malformed_live_json_and_duplicate_fields_fail(self):
        for data in (b'{', b'null', b'{"schema_version": 1, "schema_version": 1}'):
            def fetch(url, limit, timeout):
                return data
            with self.subTest(data=data), self.assertRaises(ValueError):
                publication.verify_publication(self.manifest, SHA, URL, fetch=fetch, attempts=1)

    def test_network_reader_enforces_limit_and_rejects_redirects(self):
        class Response:
            headers = {}
            def __enter__(self):
                return self
            def __exit__(self, *args):
                pass
            def read1(self, amount):
                return b'x' * amount
        with patch.object(publication.urllib.request.OpenerDirector, 'open', return_value=Response()):
            with self.assertRaises(ValueError):
                publication.fetch_bytes(URL, 8, 1)
        with self.assertRaises(ValueError):
            publication.NoRedirect().redirect_request(None, None, 302, '', {}, 'https://evil.invalid/')


class WorkflowContractTest(unittest.TestCase):
    def script(self, step):
        workflow = (ROOT / '.github/workflows/benchmark-pages.yml').read_text()
        body = workflow.split('- name: ' + step, 1)[1].split('        run: |\n', 1)[1]
        lines = []
        for line in body.splitlines():
            if line and not line.startswith('          '):
                break
            lines.append(line)
        return textwrap.dedent('\n'.join(lines))

    def execute(self, script, *, current=SHA, event='push', verifier=0, later_current=None):
        with tempfile.TemporaryDirectory() as root:
            output, summary = Path(root) / 'output', Path(root) / 'summary'
            output.touch()
            summary.touch()
            env = dict(os.environ, GITHUB_OUTPUT=str(output), GITHUB_STEP_SUMMARY=str(summary),
                       EVENT_NAME=event, EVENT_SHA='b' * 40, BUILD_SHA=SHA,
                       PAGE_URL=URL, PYTHONDONTWRITEBYTECODE='1')
            prelude = 'gh() { printf "%s\\n" ' + repr(current) + '; }\n'
            if later_current is not None:
                marker = shlex.quote(str(Path(root) / 'gh-called'))
                prelude = ('gh() { if [[ -e ' + marker + ' ]]; then printf "%s\\n" '
                           + shlex.quote(later_current) + '; else touch ' + marker
                           + '; printf "%s\\n" ' + shlex.quote(current) + '; fi; }\n')
            prelude += 'python3() { return ' + str(verifier) + '; }\n'
            run = subprocess.run(['bash', '-e', '-o', 'pipefail', '-c', prelude + script],
                                 env=env, capture_output=True, text=True)
            return run.returncode, output.read_text(), summary.read_text()

    def test_source_resolution_pins_master_but_preserves_pr_merge_sha(self):
        script = self.script('Resolve the source once before testing')
        self.assertEqual((0, 'sha=' + SHA + '\n', ''), self.execute(script))
        self.assertEqual((0, 'sha=' + 'b' * 40 + '\n', ''), self.execute(script, event='pull_request'))
        self.assertNotEqual(0, self.execute(script, current='null')[0])

    def test_predeploy_guard_only_accepts_current_source(self):
        script = self.script('Check that main still matches the rendered build')
        self.assertEqual((0, 'matches=true\n', ''), self.execute(script))
        code, output, summary = self.execute(script, current='c' * 40)
        self.assertEqual((0, ''), (code, output))
        self.assertIn('main advanced', summary)
        self.assertNotEqual(0, self.execute(script, current='null')[0])

    def test_postdeploy_failure_is_superseded_only_with_valid_new_master(self):
        script = self.script('Verify the published source and every public file')
        self.assertEqual(1, self.execute(script, verifier=1)[0])
        code, output, summary = self.execute(script, current='c' * 40, verifier=1)
        self.assertEqual(0, code)
        self.assertIn('superseded', summary)
        self.assertNotIn('Verified every', summary)
        self.assertNotEqual(0, self.execute(script, current='null', verifier=1)[0])
        self.assertIn('Verified every', self.execute(script)[2])
        code, output, summary = self.execute(script, later_current='c' * 40, verifier=1)
        self.assertEqual(0, code)
        self.assertIn('superseded', summary)
        self.assertNotIn('Verified every', summary)
        self.assertNotEqual(0, self.execute(script, later_current='null', verifier=1)[0])

    def test_build_uses_tested_sha_and_verifies_after_deployment(self):
        workflow = (ROOT / '.github/workflows/benchmark-pages.yml').read_text()
        test, remainder = workflow.split('  build:', 1)
        build, deploy = remainder.split('  deploy:', 1)
        self.assertIn('sha: ${{ steps.source.outputs.sha }}', test)
        self.assertIn('ref: ${{ steps.source.outputs.sha }}', test)
        self.assertIn('ref: ${{ needs.test.outputs.sha }}', build)
        self.assertNotIn('ref: main', build)
        self.assertIn("github.event.workflow_run.event == 'workflow_dispatch'", build)
        self.assertIn("github.event.workflow_run.head_repository.full_name == github.repository", build)
        self.assertIn("github.event.workflow_run.head_branch == 'main'", build)
        self.assertIn('actions/upload-artifact@', build)
        self.assertIn('actions/download-artifact@', deploy)
        self.assertGreater(deploy.index('verify-benchmark-pages.py verify'), deploy.index('actions/deploy-pages@'))
        self.assertIn('main advanced; publication verification is superseded', deploy)


if __name__ == '__main__':
    unittest.main()

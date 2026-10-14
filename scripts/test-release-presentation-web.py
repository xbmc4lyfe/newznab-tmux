"""Exercise a disposable FrankenPHP fixture over HTTP; no application data used."""
import concurrent.futures
import html
import json
import sys
import urllib.request

url = sys.argv[1]


def request(index):
    name = f'日本語 {index} <script> & " \' '
    payload = json.dumps({'url_prefix': '/details/', 'url_suffix': '', 'releases': [{'searchname': name, 'name': None, 'guid': 'abc123'}]}).encode()
    with urllib.request.urlopen(urllib.request.Request(url, data=payload, headers={'Content-Type': 'application/json'}), timeout=10) as response:
        data = json.load(response)
    assert data['sapi'] == 'frankenphp', data
    assert data['wrapper_loaded'] is True and data['header_locked'] is True, data
    assert data['ffi_mode'] == 'preload', data
    assert data['blocked'] == {'cdef': True, 'load': True, 'scope': True}, data
    result = data['output']
    assert len(result) == 1, data
    escaped = html.escape(name, quote=True).replace('&#x27;', '&#039;')
    assert f'title="{escaped}">{escaped}</a>' in result[0], data
    assert 'href="/details/abc123"' in result[0], data
    return index


with concurrent.futures.ThreadPoolExecutor(max_workers=8) as executor:
    assert sorted(executor.map(request, range(64))) == list(range(64))
print('64 concurrent FrankenPHP requests passed native output and raw FFI isolation checks')

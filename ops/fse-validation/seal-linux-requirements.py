"""Print reviewed-version Linux CPython 3.11 wheel hashes from public PyPI metadata.

Maintenance only; the image installs the checked-in output with --require-hashes.
No installation, credentials, application code, automatic version upgrades or DB.
"""
from concurrent.futures import ThreadPoolExecutor
from datetime import datetime, timezone
import json
from pathlib import Path
import re
from urllib.request import build_opener, HTTPSHandler, ProxyHandler, Request
from pip._vendor.packaging.tags import cpython_tags, compatible_tags
from pip._vendor.packaging.utils import parse_wheel_filename

platforms = [f'manylinux_2_{n}_x86_64' for n in range(5, 37)] + ['manylinux1_x86_64', 'manylinux2010_x86_64', 'manylinux2014_x86_64']
tags = set(cpython_tags((3, 11), ['cp311', 'abi3', 'none'], platforms))
tags.update(compatible_tags((3, 11), 'cp311', platforms))


def resolve(line):
    name, version = line.split('==')
    opener = build_opener(ProxyHandler({}), HTTPSHandler())
    with opener.open(Request(f'https://pypi.org/pypi/{name}/{version}/json'), timeout=30) as response:
        data = json.load(response)
    hashes = set()
    for entry in data['urls']:
        if entry.get('packagetype') != 'bdist_wheel' or entry.get('yanked'):
            continue
        wheel_tags = parse_wheel_filename(entry['filename'])[3]
        if tags.intersection(wheel_tags):
            digest = entry['digests']['sha256']
            if not re.fullmatch('[0-9a-f]{64}', digest):
                raise ValueError('INVALID_PYPI_DIGEST')
            hashes.add(digest)
    if not hashes:
        raise ValueError('NO_LOCKED_LINUX_WHEEL: ' + line)
    return line + ''.join(' \\\n    --hash=sha256:' + digest for digest in sorted(hashes))


if __name__ == '__main__':
    lines = [line.strip() for line in Path(__file__).with_name('requirements.lock.txt').read_text().splitlines()
             if line.strip() and not line.startswith('#')]
    if any(not re.fullmatch('[A-Za-z0-9_.-]+==[A-Za-z0-9_.+-]+', line) for line in lines):
        raise ValueError('ONLY_EXACT_VERSION_PINS_ALLOWED')
    with ThreadPoolExecutor(max_workers=4) as pool:
        result = list(pool.map(resolve, lines))
    print(f'# CPython 3.11 Linux x86_64, Debian Bookworm; public PyPI wheel hashes, {datetime.now(timezone.utc).date()}.\n'
          '# Regenerate deliberately with seal-linux-requirements.py; no version updates.\n' + '\n'.join(result))

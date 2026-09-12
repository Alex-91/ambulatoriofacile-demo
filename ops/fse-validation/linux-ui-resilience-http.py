"""Bounded HTTP load against the marked Linux loopback lab. Not a browser test."""
import concurrent.futures
import hashlib
import importlib.util
import json
import os
import statistics
import time
from pathlib import Path

ROOT = Path('/var/www/html')
lab = Path(os.environ['FSE_LAB_ROOT']).resolve(strict=True)
if os.name != 'posix' or not Path('/opt/fse/linux-lab-image').is_file() or lab.parent != ROOT / 'rest/writable/fse-app-labs':
    raise RuntimeError('Linux synthetic boundary')
config = json.loads((lab / 'lab.json').read_text())
if config.get('mode') != 'FSE_SYNTHETIC_APP_LAB' or config.get('runtime') != 'linux-container':
    raise RuntimeError('Synthetic marker required')
spec = importlib.util.spec_from_file_location('fse_http', ROOT / 'ops/fse-validation/app-lab-http.py')
http = importlib.util.module_from_spec(spec)
spec.loader.exec_module(http)


def client(index):
    session = http.LabSession(lab.name)
    session.login('a' if index % 2 == 0 else 'b', config['login_password'])
    timings = []
    for _ in range(10):
        start = time.monotonic()
        response = session.request('GET', '/admin/fse2/documenti')
        if response.status_code != 200 or response.headers.get('X-FSE-Framework-Version') != '4.7.4':
            raise RuntimeError('Authenticated Linux FSE list failed')
        timings.append(time.monotonic() - start)
    session.request('GET', '/logout')
    return timings


started = time.monotonic()
with concurrent.futures.ThreadPoolExecutor(max_workers=4) as pool:
    timings = sum(list(pool.map(client, range(4))), [])
report = {'mode': 'BOUNDED_SYNTHETIC_LINUX_HTTP_LOAD', 'status': 'passed',
          'clients': 4, 'authenticated_list_requests': len(timings),
          'elapsed_seconds': time.monotonic()-started,
          'p50_seconds': statistics.median(timings),
          'p95_seconds': sorted(timings)[int(len(timings)*.95)-1],
          'max_seconds': max(timings), 'external_gateway_calls': 0,
          'scope': '4 sessions / 40 authenticated reads on two synthetic tenants; not production capacity'}
target = lab / ('http-load-' + str(time.time_ns()) + '.json')
target.write_text(json.dumps(report, indent=2))
print(json.dumps(report, indent=2))

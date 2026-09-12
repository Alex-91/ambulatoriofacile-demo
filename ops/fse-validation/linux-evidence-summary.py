"""Read only selected synthetic reports; never export credentials or signing keys.

Run on stdin inside the marked app container and redirect stdout to host evidence.
"""
import hashlib
import json
import os
import re
from pathlib import Path

if os.name != 'posix' or not Path('/opt/fse/linux-lab-image').is_file():
    raise RuntimeError('Synthetic Linux image required')
lab = Path(os.environ['FSE_LAB_ROOT']).resolve(strict=True)
if lab.parent != Path('/var/www/html/rest/writable/fse-app-labs') or not re.fullmatch('[a-f0-9]{32}', lab.name):
    raise RuntimeError('Lab boundary')
marker = json.loads((lab / 'lab.json').read_text())
if marker.get('mode') != 'FSE_SYNTHETIC_APP_LAB' or marker.get('runtime') != 'linux-container':
    raise RuntimeError('Synthetic Linux marker required')
reports = {}
for pattern in ('rehearsal-report.json', 'http-report-*.json', 'billing-http-*.json',
                'clinical-http-*/report.json', 'recovery/*/result.json'):
    for path in sorted(lab.glob(pattern)):
        if path.is_symlink() or not path.resolve().is_relative_to(lab):
            raise RuntimeError('Report boundary')
        raw = path.read_bytes()
        data = json.loads(raw)
        reports[path.relative_to(lab).as_posix()] = {
            'sha256': hashlib.sha256(raw).hexdigest(), 'report': data,
        }
print(json.dumps({'mode': 'SYNTHETIC_LINUX_REPORTS_ONLY', 'lab_id': lab.name,
                  'external_services': False, 'reports': reports}, indent=2))

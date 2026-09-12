"""Regenerate a QA-only revision PDF using the real builder and strict validator."""
import base64
from pathlib import Path
import validator as v
from test_validator import SETTINGS, ROOT, synthetic_revision
import json

settings = json.loads(SETTINGS.read_text(encoding='utf-8-sig'))
cda = synthetic_revision()
result = v.validate_cda(cda, settings)
if not result['ok']:
    raise RuntimeError('Synthetic revision failed official local rules')
pdf = base64.b64decode(v.build_pdf(cda, settings)['pdf'])
directory = ROOT / 'tmp/pdfs'
directory.mkdir(parents=True, exist_ok=True)
path = directory / 'fse-revision-preview.pdf'
path.write_bytes(pdf)
print(path)

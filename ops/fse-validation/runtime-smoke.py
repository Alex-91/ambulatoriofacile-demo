"""Linux container's synthetic check; no DB, credentials, HTTP or clinical input."""
import base64
import json
import platform
from pathlib import Path

import validator

root = Path(__file__).resolve().parent
settings = root / 'settings.json'
cda = (root / 'synthetic.xml').read_bytes()
result = validator.execute({'operation': 'health', 'settings': str(settings), 'cda': base64.b64encode(cda).decode()})
if not result.get('ok') or result.get('artifacts') != 'passed' or result.get('trust_material') != 'missing':
    raise RuntimeError('Synthetic runtime checks did not pass with empty trust material')
try:
    validator.xml_document(b'<!DOCTYPE x [<!ENTITY e SYSTEM "file:///etc/passwd">]><x>&e;</x>')
except validator.InvalidArtifact:
    pass
else:
    raise RuntimeError('Unsafe XML was accepted')
print(json.dumps({'mode': 'OFFLINE_SYNTHETIC_ONLY', 'status': 'passed_runtime_smoke',
                  'system': platform.system(), 'python': platform.python_version(),
                  'artifacts': 'passed', 'trust_material': 'missing',
                  'qualified_signature': 'not_assessed', 'official_accreditation_evidence': False,
                  'full_application_tests': 'NOT_EXECUTED', 'gateway_tests': 'NOT_EXECUTED'}))

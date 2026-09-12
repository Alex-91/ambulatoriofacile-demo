"""Generate a synthetic PAdES fixture ONLY inside a marked, isolated app lab.

Not a clinical signing service. The ephemeral test private keys are never saved.
"""
import json
import os
import sys
from pathlib import Path
from test_validator import ArtifactTests, SETTINGS

repo = Path(__file__).resolve().parents[2]
lab = Path(os.environ['FSE_LAB_ROOT']).resolve(strict=True)
if lab.parent != (repo / 'rest/writable/fse-app-labs').resolve() or len(lab.name) != 32:
    raise RuntimeError('Outside the synthetic lab')
if json.loads((lab / 'lab.json').read_text(encoding='utf-8-sig'))['mode'] != 'FSE_SYNTHETIC_APP_LAB':
    raise RuntimeError('Missing lab marker')
original = Path(sys.argv[1]).resolve(strict=True)
if not original.is_relative_to(lab / 'writable') or original.suffix.lower() != '.pdf':
    raise RuntimeError('Only lab-generated documents are accepted')
target = lab / 'signing'
if (target / 'settings.json').exists():
    raise RuntimeError('Synthetic trust already exists: never replace it implicitly')
fixture = ArtifactTests()
fixture.settings = json.loads(SETTINGS.read_text(encoding='utf-8'))
fixture.pdf = original.read_bytes()
try:
    settings, signer = fixture.signer_fixture()
    for key, name in [('trust_roots', 'synthetic-root.pem'), ('crls', 'synthetic-revocations.der')]:
        (target / name).write_bytes(Path(settings[key][0]).read_bytes())
        settings[key] = [str(target / name)]
    settings['ocsps'] = []
    (target / 'settings.json').write_text(json.dumps(settings, indent=2), encoding='utf-8')
    (target / 'synthetic-signed.pdf').write_bytes(fixture.sign(signer))
    (target / 'synthetic-altered.pdf').write_bytes(fixture.sign(signer, change_page=True))
    print('Synthetic signed and altered fixtures created. NOT qualified signatures.')
finally:
    fixture.doCleanups()

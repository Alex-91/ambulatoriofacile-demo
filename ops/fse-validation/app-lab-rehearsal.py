"""Actual application/DB/artifact rehearsal; ephemeral signing key, invented data, zero Gateway calls."""
import json
import os
import shutil
import subprocess
from pathlib import Path
from test_validator import ArtifactTests, SETTINGS

repo = Path(__file__).resolve().parents[2]
lab = Path(os.environ['FSE_LAB_ROOT']).resolve(strict=True)
if lab.parent != (repo / 'rest/writable/fse-app-labs').resolve() or len(lab.name) != 32:
    raise RuntimeError('Outside the synthetic lab')
if json.loads((lab / 'lab.json').read_text(encoding='utf-8-sig'))['mode'] != 'FSE_SYNTHETIC_APP_LAB':
    raise RuntimeError('Missing marker')
target = lab / 'signing'
if (target / 'settings.json').exists() or (lab / 'rehearsal-report.json').exists():
    raise RuntimeError('Fresh lab required: never overwrite existing trust or evidence')
php = shutil.which('php')
if not php:
    raise RuntimeError('PHP runtime required')

def app(*args):
    process = subprocess.run([php, str(Path(__file__).with_suffix('.php')), *map(str, args)],
                             cwd=repo, env={**os.environ, 'XDEBUG_MODE': 'off'}, capture_output=True, timeout=240)
    if process.returncode:
        raise RuntimeError('Synthetic PHP scenario failed: ' + process.stdout.decode(errors='replace')[-1500:])
    return json.loads(process.stdout)

fixture = ArtifactTests()
fixture.settings = json.loads(SETTINGS.read_text(encoding='utf-8'))
try:
    original = app('prepare')
    fixture.pdf = Path(original['unsigned_pdf']).read_bytes()
    settings, signer = fixture.signer_fixture()
    for key, name in [('trust_roots', 'synthetic-root.pem'), ('crls', 'synthetic-revocations.der')]:
        (target / name).write_bytes(Path(settings[key][0]).read_bytes())
        settings[key] = [str(target / name)]
    settings['ocsps'] = []
    (target / 'settings.json').write_text(json.dumps(settings, indent=2), encoding='utf-8')

    def accept(doc):
        pdf = Path(doc['unsigned_pdf']).resolve(strict=True)
        if not pdf.is_relative_to(lab / 'writable'):
            raise RuntimeError('Not a lab artifact')
        fixture.pdf = pdf.read_bytes()
        (target / f"rehearsal-signed-{doc['document_id']}.pdf").write_bytes(fixture.sign(signer))
        result = app('accept', doc['document_id'])
        if result['state'] != 'signed':
            raise RuntimeError('Signature not accepted')
        print(f"Synthetic source #{doc['document_id']}: artifacts/signature verified", flush=True)

    accept(original)
    revision = app('revise', original['document_id'])
    accept(revision)
    uncertain = app('prepare')
    accept(uncertain)
    print(json.dumps(app('exercise', original['document_id'], revision['document_id'], uncertain['document_id']), indent=2))
finally:
    fixture.doCleanups()

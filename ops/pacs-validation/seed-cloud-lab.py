"""Seed the explicitly marked, isolated cloud lab with two synthetic DICOM fixtures."""
import ast, base64, json, pathlib, ssl, struct, urllib.request
root = pathlib.Path(__file__).resolve().parents[2]
run = root / 'rest/writable/pacs-cloud-lab'
state = json.loads((run / 'state.json').read_text(encoding='utf-8-sig'))
assert state['marker'] == 'AF_PACS_CLOUD_SYNTHETIC_V1' and state['tenantId'] == 4
expected = 'https://orthanc-' + state['serviceUuid'] + '.178.104.113.107.sslip.io'
assert state['baseUrl'] == expected
# Reuse only the existing deterministic fixture writers; never execute local lab setup.
source = ast.parse((root / 'ops/pacs-validation/prepare-lab.py').read_text(encoding='utf-8'))
writers = [n for n in source.body if isinstance(n, ast.FunctionDef) and n.name in ('element', 'dicom')]
assert len(writers) == 2
exec(compile(ast.Module(body=writers, type_ignores=[]), 'synthetic-fixture-writers', 'exec'))
fixtures = [dicom('P-100', 'TEST-HOSPITAL', 1), dicom('P-200', 'OTHER-HOSPITAL', 2)]
(run / 'fixtures.json').write_text(json.dumps(fixtures), encoding='utf-8')
auth = 'Basic ' + base64.b64encode((state['username'] + ':' + state['password']).encode()).decode()
context = ssl.create_default_context()
for n in (1, 2):
    data = (run / ('synthetic-' + str(n) + '.dcm')).read_bytes()
    boundary = 'af-pacs-cloud-synthetic-boundary'
    body = ('--' + boundary + '\r\nContent-Type: application/dicom\r\n\r\n').encode() + data + ('\r\n--' + boundary + '--\r\n').encode()
    request = urllib.request.Request(expected + '/dicom-web/studies', data=body, headers={
        'Authorization': auth, 'Content-Type': 'multipart/related; type="application/dicom"; boundary=' + boundary,
        'Accept': 'application/dicom+json'})
    with urllib.request.urlopen(request, context=context, timeout=20) as response:
        assert response.status == 200
        assert json.loads(response.read()).get('00081199', {}).get('Value'), 'STOW references missing'
print('Two synthetic cloud DICOM fixtures stored through authenticated HTTPS STOW-RS.')

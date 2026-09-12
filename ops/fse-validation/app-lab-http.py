"""Real HTTP/session checks against the marked loopback lab only; not browser/JS or accreditation."""
import hashlib
import json
import os
import re
import shutil
import subprocess
import uuid
from datetime import datetime, timezone
from html.parser import HTMLParser
from pathlib import Path
from urllib.parse import urljoin, urlsplit
import requests

BASE = 'http://127.0.0.1:8088'
REPO = Path(__file__).resolve().parents[2]


def local_url(path):
    url = urljoin(BASE + '/', path)
    parsed = urlsplit(url)
    if parsed.scheme != 'http' or parsed.netloc != '127.0.0.1:8088' or parsed.username or parsed.password:
        raise RuntimeError('HTTP_DESTINATION_REFUSED')
    return url


class CsrfParser(HTMLParser):
    def __init__(self):
        super().__init__()
        self.tokens = set()

    def handle_starttag(self, tag, attributes):
        attrs = dict(attributes)
        if tag == 'input' and attrs.get('name') == 'csrf_test_name':
            self.tokens.add(attrs.get('value', ''))


def csrf(html):
    parsed = CsrfParser()
    parsed.feed(html)
    if len(parsed.tokens) != 1 or not next(iter(parsed.tokens)):
        raise RuntimeError('CSRF_FORM_TOKEN_MISSING')
    return next(iter(parsed.tokens))


class LabSession:
    def __init__(self, lab_id):
        self.lab_id = lab_id
        self.session = requests.Session()
        self.session.trust_env = False
        self.dependency_requests = set()

    def request(self, method, path, **kwargs):
        response = self.session.request(method, local_url(path), allow_redirects=False, timeout=(3, 90), **kwargs)
        if response.headers.get('X-FSE-Test-Environment') != 'synthetic-only' or response.headers.get('X-FSE-Lab-Id') != self.lab_id:
            raise RuntimeError('HTTP_LAB_IDENTITY_MISMATCH')
        expected_framework = ('4.6.0' if os.environ.get('FSE_FRAMEWORK_VARIANT') == 'baseline' else '4.7.4') if os.environ.get('FSE_FRAMEWORK_LAB') else None
        if expected_framework and response.headers.get('X-FSE-Framework-Version') != expected_framework:
            raise RuntimeError('HTTP_FRAMEWORK_VERSION_MISMATCH')
        if os.environ.get('FSE_DEPENDENCY_LAB'):
            variant = os.environ.get('FSE_APPLICATION_VARIANT') or 'candidate'
            request_id = response.headers.get('X-FSE-Dependency-Request', '')
            if response.headers.get('X-FSE-Dependency-Variant') != variant or not re.fullmatch('[a-f0-9]{32}', request_id):
                raise RuntimeError('HTTP_DEPENDENCY_VARIANT_MISMATCH')
            self.dependency_requests.add(request_id)
        if response.is_redirect:
            local_url(response.headers['Location'])  # Never follow an off-host redirect or send it credentials.
        return response

    def login(self, suffix, password):
        self.request('GET', '/login')
        response = self.request('POST', '/login', json={'username':f'studio-{suffix}@fse.invalid', 'password':password})
        if response.status_code != 200:
            raise RuntimeError('SYNTHETIC_LOGIN_FAILED')
        page = self.request('GET', '/admin/fse2/documenti')
        if page.status_code != 200 or 'FSE' not in page.text:
            raise RuntimeError('AUTHENTICATED_FSE_PAGE_MISSING')


def run():
    supplied = Path(os.environ['FSE_LAB_ROOT']).absolute()
    lab = supplied.resolve(strict=True)
    if supplied.is_symlink() or lab.parent != (REPO / 'rest/writable/fse-app-labs').resolve(strict=True) or not re.fullmatch('[a-f0-9]{32}', lab.name):
        raise RuntimeError('LOCAL_LAB_BOUNDARY')
    config = json.loads((lab / 'lab.json').read_text(encoding='utf-8-sig'))
    rehearsal = json.loads((lab / 'rehearsal-report.json').read_text(encoding='utf-8'))
    if config.get('mode') != 'FSE_SYNTHETIC_APP_LAB' or config.get('port') != 33079 or config.get('web_port') != 8088 or rehearsal.get('status') != 'passed':
        raise RuntimeError('COMPLETE_SYNTHETIC_REHEARSAL_REQUIRED')
    php = shutil.which('php')
    if not php:
        raise RuntimeError('PHP_RUNTIME_REQUIRED')

    def baseline():
        process = subprocess.run([php, str(Path(__file__).with_name('app-lab-rehearsal.php')), 'http-baseline'],
                                 cwd=REPO, env={**os.environ, 'XDEBUG_MODE':'off'}, capture_output=True, timeout=30)
        if process.returncode:
            raise RuntimeError('SYNTHETIC_DATABASE_BOUNDARY_FAILED')
        return json.loads(process.stdout)

    report = {'mode':'SYNTHETIC_APPLICATION_HTTP', 'status':'incomplete', 'started_at':datetime.now(timezone.utc).isoformat(),
              'lab_id':lab.name, 'official_accreditation_evidence':False, 'external_gateway_calls':0,
              'browser_javascript':'NOT_EXECUTED', 'checks':{}}
    report['framework_expected'] = ('4.6.0' if os.environ.get('FSE_FRAMEWORK_VARIANT') == 'baseline' else '4.7.4') if os.environ.get('FSE_FRAMEWORK_LAB') else 'active'
    report['dependencies_expected'] = (os.environ.get('FSE_APPLICATION_VARIANT') or 'candidate') if os.environ.get('FSE_DEPENDENCY_LAB') else 'active'
    target = lab / ('http-report-' + uuid.uuid4().hex + '.json')
    sessions = []
    before = None
    try:
        before = baseline()
        doc_id = int(rehearsal['source_document_ids'][0])
        doc = next(d for d in before['documents'] if d['id'] == doc_id)
        if doc['state'] != 'signed':
            raise RuntimeError('SIGNED_SOURCE_REQUIRED')
        a, b, anonymous = [LabSession(lab.name) for _ in range(3)]
        sessions = [a,b,anonymous]
        download = f'/admin/fse2/documenti/download/{doc_id}/signed'
        support = f'/admin/fse2/documenti/assistenza/{doc_id}'
        form = f'/admin/fse2/documenti/modifica/{doc_id}'
        imported = f'/admin/fse2/documenti/laboratorio-toscana/{doc_id}'
        upload = f'/admin/fse2/documenti/firma/{doc_id}'
        if anonymous.request('GET', download).status_code not in (302,303,401,403):
            raise RuntimeError('ANONYMOUS_DOWNLOAD_ALLOWED')
        report['checks']['anonymous_download_denied'] = 'passed'
        a.login('a', config['login_password'])
        page = a.request('GET', form)
        if page.status_code != 200 or 'no-store' not in page.headers.get('Cache-Control',''):
            raise RuntimeError('SIGNED_PAGE_OR_CACHE_POLICY')
        token = csrf(page.text)
        pdf = a.request('GET', download)
        if pdf.status_code != 200 or not pdf.content.startswith(b'%PDF-') or hashlib.sha256(pdf.content).hexdigest() != doc['pdf_sha256']:
            raise RuntimeError('SIGNED_DOWNLOAD_HASH_MISMATCH')
        report['checks']['authenticated_signed_download_matches_source'] = 'passed'
        response = a.request('GET', support)
        bundle = response.json()
        if response.status_code != 200 or bundle.get('kind') != 'FSE_TECHNICAL_SUPPORT_ONLY' or 'no-store' not in response.headers.get('Cache-Control',''):
            raise RuntimeError('TECHNICAL_BUNDLE_FAILED')
        if any(key in response.text for key in ['patient_cf', 'report_text_enc', 'password', 'private_key']):
            raise RuntimeError('TECHNICAL_BUNDLE_UNEXPECTED_FIELDS')
        report['checks']['technical_bundle_no_clinical_fields_no_store'] = 'passed'
        if a.request('POST', upload, files={'signed_pdf':('synthetic.pdf', pdf.content, 'application/pdf')}).status_code != 403:
            raise RuntimeError('UPLOAD_WITHOUT_CSRF_ACCEPTED')
        report['checks']['multipart_upload_requires_csrf'] = 'passed'
        for contents, expected_error, check_name in [
            (b'NOT_A_PDF_SYNTHETIC_ONLY', 'non è un PDF valido', 'non_pdf_multipart_rejected'),
            (pdf.content, 'Valida prima il PDF/CDA', 'signed_source_cannot_be_overwritten'),
        ]:
            upload_token = csrf(a.request('GET', form).text)
            rejected = a.request('POST', upload, data={'csrf_test_name':upload_token},
                                 files={'signed_pdf':('synthetic.pdf', contents, 'application/pdf')})
            if rejected.status_code not in (302,303) or urlsplit(rejected.headers.get('Location','')).path != form:
                raise RuntimeError('UPLOAD_REJECTION_REDIRECT_MISSING')
            feedback = a.request('GET', form)
            if feedback.status_code != 200 or expected_error not in feedback.text or 'alert-danger' not in feedback.text:
                raise RuntimeError('UPLOAD_REJECTION_FEEDBACK_MISSING')
            report['checks'][check_name] = 'passed'
        if a.request('POST', imported, data={}).status_code != 403:
            raise RuntimeError('MISSING_CSRF_ACCEPTED')
        report['checks']['missing_csrf_denied'] = 'passed'
        token = csrf(a.request('GET', form).text)
        accepted = a.request('POST', imported, data={'csrf_test_name':token})
        if accepted.status_code not in (302,303) or urlsplit(accepted.headers.get('Location','')).path != '/admin/fse2/laboratorio-toscana':
            raise RuntimeError('SIGNED_SNAPSHOT_REIMPORT_FAILED')
        new_token = csrf(a.request('GET', form).text)
        if new_token == token or a.request('POST', imported, data={'csrf_test_name':token}).status_code != 403:
            raise RuntimeError('STALE_CSRF_ACCEPTED')
        report['checks']['valid_csrf_rotated_stale_csrf_denied'] = 'passed'
        b.login('b', config['login_password'])
        if b.request('GET', download).status_code not in (302,303,403,404) or b.request('GET', support).status_code != 404:
            raise RuntimeError('CROSS_TENANT_ARTIFACT_OR_SUPPORT_EXPOSED')
        if b.request('GET', form).status_code not in (302,303,403,404):
            raise RuntimeError('CROSS_TENANT_FORM_EXPOSED')
        if b.request('GET', f"/login/spazio/fse2?profile={doc['profile']}").status_code != 404:
            raise RuntimeError('CROSS_TENANT_PROFILE_EXPOSED')
        report['checks']['cross_tenant_document_support_profile_denied'] = 'passed'
        if b.request('POST', imported, data={'csrf_test_name':new_token}).status_code != 403:
            raise RuntimeError('FOREIGN_SESSION_CSRF_ACCEPTED')
        report['checks']['foreign_session_csrf_denied'] = 'passed'
        own_token = csrf(b.request('GET', '/admin/fse2/documenti/nuovo').text)
        foreign_upload = b.request('POST', upload, data={'csrf_test_name':own_token},
                                   files={'signed_pdf':('synthetic.pdf', pdf.content, 'application/pdf')})
        if foreign_upload.status_code not in (302,303):
            raise RuntimeError('CROSS_TENANT_UPLOAD_REJECTION_MISSING')
        rejection = b.request('GET', '/admin/fse2/documenti')
        if rejection.status_code != 200 or 'Referto FSE non trovato.' not in rejection.text:
            raise RuntimeError('CROSS_TENANT_UPLOAD_FEEDBACK_MISSING')
        report['checks']['cross_tenant_upload_with_own_csrf_denied'] = 'passed'
        preserved = a.request('GET', download)
        if preserved.status_code != 200 or hashlib.sha256(preserved.content).hexdigest() != doc['pdf_sha256']:
            raise RuntimeError('UPLOAD_ATTEMPT_CHANGED_SIGNED_BYTES')
        report['checks']['signed_artifact_bytes_unchanged_after_upload_attempts'] = 'passed'
        a.request('GET', '/logout')
        if a.request('GET', download).status_code not in (302,303,401,403):
            raise RuntimeError('LOGGED_OUT_DOWNLOAD_ALLOWED')
        report['checks']['logout_revokes_download_access'] = 'passed'
        after = baseline()
        if before != after:
            raise RuntimeError('HTTP_CHECK_CHANGED_SOURCE_OR_SIMULATION')
        report['checks']['source_rows_events_and_simulation_unchanged'] = 'passed'
        if os.environ.get('FSE_DEPENDENCY_LAB'):
            request_ids = sorted(set().union(*(session.dependency_requests for session in sessions)))
            if not request_ids:
                raise RuntimeError('HTTP_DEPENDENCY_PROVENANCE_MISSING')
            for request_id in request_ids:
                proof_path = lab / 'writable/dependency-provenance' / (request_id + '.json')
                proof = json.loads(proof_path.read_text(encoding='utf-8'))
                if (proof.get('mode') != 'SYNTHETIC_HTTP_DEPENDENCY_PROVENANCE_ONLY' or not proof.get('passed')
                        or proof.get('request_id') != request_id or proof.get('lab_id') != lab.name
                        or proof.get('variant') != report['dependencies_expected']
                        or proof.get('framework_version') != report['framework_expected']
                        or not proof.get('active_dependencies_unchanged') or proof.get('unexpected_dependency_sources')
                        or not proof.get('loaded_dependency_sha256')):
                    raise RuntimeError('HTTP_DEPENDENCY_PROVENANCE_FAILED')
            report['checks']['every_http_dependency_provenance_verified'] = 'passed'
            report['dependency_provenance_requests'] = request_ids
        report['status'] = 'passed_synthetic_http'
    except Exception as error:
        report['status'] = 'failed_or_incomplete'
        report['error_code'] = str(error) if re.fullmatch('[A-Z_]{1,90}', str(error)) else 'HTTP_CHECK_FAILED'
    finally:
        for session in sessions:
            session.session.close()
        report['finished_at'] = datetime.now(timezone.utc).isoformat()
        with target.open('x', encoding='utf-8') as stream:
            json.dump(report, stream, indent=2)
    print(json.dumps(report, indent=2))
    print('Evidence: ' + str(target))
    return 0 if report['status'] == 'passed_synthetic_http' else 2


if __name__ == '__main__':
    raise SystemExit(run())

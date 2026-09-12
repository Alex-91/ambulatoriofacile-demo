"""Actual billing HTTP controllers with synthetic MySQL only. No TS, mail or fiscal submission."""
import hashlib
import importlib.util
import json
import os
import re
import shutil
import subprocess
import uuid
from datetime import datetime, timezone
from pathlib import Path
from urllib.parse import urlsplit

spec = importlib.util.spec_from_file_location('lab_http', Path(__file__).with_name('app-lab-http.py'))
http = importlib.util.module_from_spec(spec)
spec.loader.exec_module(http)
BASE_PATH = '/admin/fatturazione-documenti'


def run():
    supplied = Path(os.environ['FSE_LAB_ROOT']).absolute()
    lab = supplied.resolve(strict=True)
    if supplied.is_symlink() or lab.parent != (http.REPO / 'rest/writable/fse-app-labs').resolve(strict=True) or not re.fullmatch('[a-f0-9]{32}', lab.name):
        raise RuntimeError('LOCAL_LAB_BOUNDARY')
    config = json.loads((lab / 'lab.json').read_text(encoding='utf-8-sig'))
    marker = json.loads((lab / 'billing-seeded.json').read_text(encoding='utf-8'))
    if config.get('mode') != 'FSE_SYNTHETIC_APP_LAB' or config.get('port') != 33079 or config.get('web_port') != 8088 or marker.get('mode') != 'SYNTHETIC_BILLING_HTTP' or marker.get('dispatch_enabled') is not False:
        raise RuntimeError('BILLING_SYNTHETIC_SEED_REQUIRED')
    php = shutil.which('php')
    if not php:
        raise RuntimeError('PHP_RUNTIME_REQUIRED')
    variant = os.environ.get('FSE_APPLICATION_VARIANT', 'candidate')
    run_id = uuid.uuid4().hex
    report = {'mode': 'SYNTHETIC_BILLING_HTTP', 'status': 'incomplete', 'lab_id': lab.name,
              'started_at': datetime.now(timezone.utc).isoformat(), 'official_accreditation_evidence': False,
              'framework_expected': '4.6.0' if os.environ.get('FSE_FRAMEWORK_VARIANT') == 'baseline' else '4.7.4',
              'dependencies_expected': variant, 'browser_javascript': 'NOT_EXECUTED',
              'external_dispatch_tests': 'EXCLUDED_BY_LAB_ROUTER', 'checks': {}}
    target = lab / ('billing-http-' + run_id + '.json')
    sessions = []

    def check(condition, name):
        if not condition:
            raise RuntimeError(name.upper())
        report['checks'][name] = 'passed'

    def snapshot():
        # Read-only DB assertions use the active isolated CLI bootstrap; the variant is tested
        # by real HTTP requests, each with independent provenance. Avoid remapping all classes
        # and rehashing the framework again for every SQL snapshot on Windows.
        snapshot_env = {key: value for key, value in os.environ.items()
                        if key not in ('FSE_FRAMEWORK_LAB', 'FSE_DEPENDENCY_LAB')}
        result = subprocess.run([php, str(Path(__file__).with_name('app-lab-billing.php')), 'snapshot'], cwd=http.REPO,
                                env={**snapshot_env, 'XDEBUG_MODE': 'off'}, capture_output=True, timeout=60)
        if result.returncode:
            raise RuntimeError('SYNTHETIC_SNAPSHOT_FAILED')
        return json.loads(result.stdout)

    def documents(tenant='42'):
        return snapshot()['tenants'][tenant]['documents']

    def active_fonts():
        root = http.REPO / 'vendor/dompdf/dompdf/lib/fonts'
        return {path.relative_to(root).as_posix(): hashlib.sha256(path.read_bytes()).hexdigest()
                for path in sorted(root.rglob('*')) if path.is_file()}

    def document(document_id):
        return next(row for row in documents() if int(row['id_billing_document']) == document_id)

    def form_token(session, path=BASE_PATH + '/nuovo'):
        response = session.request('GET', path)
        if response.status_code != 200:
            raise RuntimeError('BILLING_FORM_FAILED')
        return http.csrf(response.text)

    def login(session, suffix):
        session.request('GET', '/login')
        response = session.request('POST', '/login', json={'username': f'studio-{suffix}@fse.invalid', 'password': config['login_password']})
        if response.status_code != 200:
            raise RuntimeError('BILLING_LOGIN_FAILED')
        form_token(session)

    def rejected_without_changes(session, path, payload, expected, name):
        before = snapshot()
        response = session.request('POST', path, data=payload)
        after = snapshot()
        report.setdefault('rejection_observations', {})[name] = {
            'status_code': response.status_code, 'database_unchanged': after == before,
            'document_counts_before': {k: len(v['documents']) for k, v in before['tenants'].items()},
            'document_counts_after': {k: len(v['documents']) for k, v in after['tenants'].items()},
        }
        check(response.status_code in expected and after == before, name)
        return response

    payload = {'document_number': 'LAB-' + run_id[:12], 'document_type': 'invoice', 'issue_date': '2026-09-12',
               'patient_name': 'PAZIENTE SINTETICO', 'payment_method': 'bank_transfer', 'id_client': '0',
               'item_description[]': ['PRESTAZIONE FITTIZIA <b>NON HTML</b>', 'CONTROLLO SINTETICO'],
               'item_qty[]': ['2', '1'], 'item_unit_amount[]': ['40.00', '20.00'],
               'stamp_duty_amount': '2', 'vat_rate': '0', 'vat_nature': 'N4', 'ts_sync_enabled': '0',
               'notes': 'PROVA TECNICA SENZA VALORE FISCALE', 'save_mode': 'draft'}
    try:
        before = snapshot()
        fonts_before = active_fonts()
        a, b, anonymous = [http.LabSession(lab.name) for _ in range(3)]
        sessions = [a, b, anonymous]
        check(anonymous.request('GET', BASE_PATH).status_code in (302, 303, 401, 403), 'anonymous_access_denied')
        login(a, 'a')
        # First run intentionally detects missing protection, preserving any failing evidence.
        rejected_without_changes(a, BASE_PATH + '/save', payload, (403,), 'missing_csrf_denied')
        token = form_token(a)
        saved = a.request('POST', BASE_PATH + '/save', data={**payload, 'csrf_test_name': token})
        match = re.fullmatch(re.escape(BASE_PATH) + r'/modifica/(\d+)', urlsplit(saved.headers.get('Location', '')).path)
        check(saved.status_code in (302, 303) and match is not None, 'draft_save_redirect')
        document_id = int(match[1])
        edit = BASE_PATH + '/modifica/' + str(document_id)
        row = document(document_id)
        check(row['local_state'] == 'draft' and float(row['amount_total']) == 102 and int(row['ts_sync_enabled']) == 0, 'draft_persisted_with_correct_total')
        catalog = snapshot()['tenants']['42']
        check(list(map(int, catalog['catalog_actor_ids'])) == [42] and catalog['catalog_item_counts'] == [2], 'catalog_saved_with_platform_actor')
        rotated = form_token(a, edit)
        check(rotated != token, 'csrf_cookie_rotates_on_redirect')
        rejected_without_changes(a, BASE_PATH + '/save', {**payload, 'csrf_test_name': token}, (403,), 'stale_csrf_denied')
        login(b, 'b')
        foreign_token = form_token(b)
        rejected_without_changes(a, BASE_PATH + '/save', {**payload, 'csrf_test_name': foreign_token}, (403,), 'foreign_cookie_csrf_denied')
        rejected = rejected_without_changes(a, BASE_PATH + '/save', {**payload, 'csrf_test_name': form_token(a)}, (302, 303), 'duplicate_does_not_insert_or_change_rows')
        feedback = a.request('GET', rejected.headers['Location'])
        check(feedback.status_code == 200 and 'alert-danger' in feedback.text, 'duplicate_error_visible')
        edited_payload = {**payload, 'id_billing_document': str(document_id), 'item_qty[]': ['3', '1']}
        saved = a.request('POST', BASE_PATH + '/save', data={**edited_payload, 'csrf_test_name': form_token(a, edit)})
        check(saved.status_code in (302, 303) and float(document(document_id)['amount_total']) == 142, 'draft_edit_recalculates_total')
        for endpoint in ('modifica', 'preview', 'pdf'):
            state = snapshot()
            denied = b.request('GET', f'{BASE_PATH}/{endpoint}/{document_id}')
            check(denied.status_code in (302, 303, 403, 404) and snapshot() == state, 'cross_tenant_' + endpoint + '_denied')
        rejected_without_changes(b, BASE_PATH + '/save', {**edited_payload, 'id_tenant': '42', 'csrf_test_name': form_token(b)}, (302, 303, 403), 'cross_tenant_edit_denied')
        payment = f'{BASE_PATH}/pagamento/{document_id}'
        rejected_without_changes(b, payment, {'payment_status': 'paid', 'csrf_test_name': form_token(b)}, (302, 303, 403), 'cross_tenant_payment_denied')
        preview = a.request('GET', f'{BASE_PATH}/preview/{document_id}')
        check(preview.status_code == 200 and 'PAZIENTE SINTETICO' in preview.text and '&lt;b&gt;NON HTML&lt;/b&gt;' in preview.text, 'preview_synthetic_data_escaped')
        issued = a.request('POST', BASE_PATH + '/save', data={**edited_payload, 'save_mode': 'final', 'csrf_test_name': form_token(a, edit)})
        check(issued.status_code in (302, 303) and urlsplit(issued.headers.get('Location', '')).path == BASE_PATH and document(document_id)['local_state'] == 'issued', 'final_issue_saved_without_ts')
        pdf = a.request('GET', f'{BASE_PATH}/pdf/{document_id}')
        check(pdf.status_code == 200 and pdf.content.startswith(b'%PDF-') and 'application/pdf' in pdf.headers.get('Content-Type', '') and bool(document(document_id)['pdf_generated_at']), 'pdf_endpoint_and_generation_timestamp')
        pdf_path = lab / ('billing-' + variant + '-' + run_id + '.pdf')
        with pdf_path.open('xb') as stream:
            stream.write(pdf.content)
        report['pdf'] = {'file': pdf_path.name, 'sha256': hashlib.sha256(pdf.content).hexdigest()}
        rejected_without_changes(a, payment, {'payment_status': 'paid'}, (403,), 'payment_missing_csrf_denied')
        for status in ('paid', 'unpaid'):
            result = a.request('POST', payment, data={'payment_status': status, 'payment_date': '2026-09-12', 'csrf_test_name': form_token(a, edit)})
            row = document(document_id)
            check(result.status_code in (302, 303) and row['payment_status'] == status and bool(row['payment_date']) == (status == 'paid'), 'payment_' + status + '_persisted')
        page = a.request('GET', BASE_PATH)
        check(page.status_code == 200 and payload['document_number'] in page.text, 'billing_list_shows_created_document')
        a.request('GET', '/logout')
        check(a.request('GET', f'{BASE_PATH}/pdf/{document_id}').status_code in (302, 303, 401, 403), 'logout_revokes_pdf_access')
        after = snapshot()
        check(after['tenants']['43'] == before['tenants']['43'], 'other_tenant_rows_and_preferences_unchanged')
        check(after['ts_profile_count'] == 0 and all(t['email_log_count'] == 0 and t['ts_document_count'] == 0 for t in after['tenants'].values()), 'no_ts_documents_or_email_log_created')
        check(bool(fonts_before) and active_fonts() == fonts_before, 'active_vendor_fonts_unchanged')
        if os.environ.get('FSE_DEPENDENCY_LAB'):
            requests = sorted(set().union(*(s.dependency_requests for s in sessions)))
            for request_id in requests:
                proof = json.loads((lab / 'writable/dependency-provenance' / (request_id + '.json')).read_text(encoding='utf-8'))
                if (proof.get('mode') != 'SYNTHETIC_HTTP_DEPENDENCY_PROVENANCE_ONLY' or not proof.get('passed')
                        or proof.get('request_id') != request_id or proof.get('lab_id') != lab.name
                        or proof.get('variant') != variant or proof.get('framework_version') != report['framework_expected']
                        or not proof.get('active_dependencies_unchanged') or proof.get('unexpected_dependency_sources')
                        or not proof.get('loaded_dependency_sha256')):
                    raise RuntimeError('HTTP_DEPENDENCY_PROVENANCE_FAILED')
            check(bool(requests), 'every_http_dependency_provenance_verified')
            report['dependency_provenance_requests'] = requests
        report['document_id'] = document_id
        report['status'] = 'passed_synthetic_http'
    except Exception as error:
        report['status'] = 'failed_or_incomplete'
        report['error_code'] = str(error) if re.fullmatch('[A-Z_]{1,90}', str(error)) else 'BILLING_HTTP_CHECK_FAILED'
        report['error_type'] = type(error).__name__
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

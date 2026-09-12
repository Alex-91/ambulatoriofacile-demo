"""Read-only dependency inventory + optional public OSV lookup. Never installs packages.

Run with the FSE validator's Python to inspect its installed distribution metadata.
Only public ecosystem/name/version triples leave the machine; no source, config or keys.
This is a version advisory lookup, not an exploitability or legal compliance assessment.
"""
import argparse
from datetime import datetime, timezone
import hashlib
from importlib import metadata
import json
from pathlib import Path
import re
import sys
import uuid
from urllib.request import Request, build_opener, ProxyHandler, HTTPRedirectHandler

ROOT = Path(__file__).resolve().parents[2]


def canonical(name):
    return re.sub(r"[-_.]+", "-", name).lower()


def locked_python(text):
    result = []
    seen = set()
    for line in text.splitlines():
        line = line.strip()
        if not line or line.startswith('#'):
            continue
        match = re.fullmatch(r'([A-Za-z0-9][A-Za-z0-9_.-]*)==([A-Za-z0-9][A-Za-z0-9_.+!-]*)', line)
        if not match or canonical(match[1]) in seen:
            raise ValueError('Requirements must contain unique exact public version pins only.')
        seen.add(canonical(match[1]))
        result.append((match[1], match[2]))
    if not result:
        raise ValueError('Empty requirements inventory.')
    return result


def inventory():
    entries, inputs = [], {}

    def read(relative):
        data = (ROOT / relative).read_bytes()
        inputs[relative] = hashlib.sha256(data).hexdigest()
        return data.decode('utf-8-sig')

    for relative, scope, installed in [
        ('composer.lock', 'product_lock', False),
        ('vendor/composer/installed.json', 'product_installed_local', True),
        ('rest/vendor/composer/installed.json', 'framework_dependencies_installed_local', True),
        ('ops/fse-validation/linux-lab-composer.lock', 'linux_lab_lock_not_executed', False),
    ]:
        data = json.loads(read(relative))
        packages = data['packages'] + ([] if installed else data.get('packages-dev', []))
        for package in packages:
            entries.append({'scope': scope, 'ecosystem': 'Packagist', 'name': package['name'],
                            'version': package['version'].removeprefix('v'),
                            'licenses_declared': package.get('license', []),
                            'abandoned_declared': package.get('abandoned', False)})
    framework = read('rest/system/CodeIgniter.php')
    match = re.search(r"CI_VERSION\s*=\s*'([0-9.]+)'", framework)
    if not match:
        raise ValueError('Cannot identify vendored framework version.')
    entries.append({'scope': 'vendored_framework_version_not_integrity_attestation', 'ecosystem': 'Packagist',
                    'name': 'codeigniter4/framework', 'version': match[1],
                    'licenses_declared': [json.loads(read('rest/composer.json'))['license']]})
    for name, version in locked_python(read('ops/fse-validation/requirements.lock.txt')):
        try:
            package = metadata.distribution(name)
            license_value = package.metadata.get('License-Expression') or package.metadata.get('License') or ''
            classifiers = [x for x in package.metadata.get_all('Classifier', []) if x.startswith('License ::')]
            license_files = []
            for path in package.files or []:
                if re.search(r'(^|/)(licenses?|copying|notice)([./_-]|$)', str(path), re.I):
                    local = Path(package.locate_file(path)).resolve()
                    if local.is_relative_to(Path(sys.prefix).resolve()) and local.is_file():
                        license_files.append({'file': str(path), 'sha256': hashlib.sha256(local.read_bytes()).hexdigest()})
            installed = package.version
        except metadata.PackageNotFoundError:
            installed, license_value, classifiers, license_files = None, '', [], []
        entries.append({'scope': 'python_validator_lock', 'ecosystem': 'PyPI', 'name': name, 'version': version,
                        'installed_version': installed, 'matches_installed': installed == version,
                        'license_metadata': license_value, 'license_classifiers': classifiers,
                        'license_files': license_files})
    return entries, inputs


class NoRedirect(HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        raise ValueError('Unexpected redirect from advisory service.')


def public_json(path, payload=None):
    # Fixed origin, no proxy/auth/environment credentials, verified HTTPS, bounded response.
    if not re.fullmatch(r'(querybatch|vulns/[A-Za-z0-9_.-]+)', path):
        raise ValueError('Invalid advisory API path.')
    body = None if payload is None else json.dumps(payload).encode('utf-8')
    req = Request('https://api.osv.dev/v1/' + path, data=body,
                  headers={'Content-Type': 'application/json', 'User-Agent': 'FSE-local-dependency-audit/1'})
    with build_opener(ProxyHandler({}), NoRedirect()).open(req, timeout=30) as response:
        data = response.read(8 * 1024 * 1024 + 1)
    if len(data) > 8 * 1024 * 1024:
        raise ValueError('Advisory response too large.')
    return json.loads(data)


def lookup(queries, fetch=public_json):
    findings = {}
    pending = [(index, query) for index, query in enumerate(queries)]
    for _ in range(20):
        if not pending:
            return findings
        data = fetch('querybatch', {'queries': [query for _, query in pending]})
        results = data.get('results')
        if not isinstance(results, list) or len(results) != len(pending):
            raise ValueError('Incomplete advisory response.')
        following = []
        for (index, query), item in zip(pending, results):
            if not isinstance(item, dict) or set(item) - {'vulns', 'next_page_token'}:
                raise ValueError('Unexpected advisory result.')
            vulns = item.get('vulns', [])
            if not isinstance(vulns, list):
                raise ValueError('Malformed advisory result.')
            for vuln in vulns:
                identifier = vuln.get('id') if isinstance(vuln, dict) else None
                if not isinstance(identifier, str) or not re.fullmatch(r'[A-Za-z0-9_.-]+', identifier):
                    raise ValueError('Invalid advisory identifier.')
                findings.setdefault(index, set()).add(identifier)
            token = item.get('next_page_token')
            if token:
                if not isinstance(token, str):
                    raise ValueError('Invalid advisory pagination token.')
                following.append((index, {**query, 'page_token': token}))
        pending = following
    raise ValueError('Incomplete advisory pagination.')


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--online', action='store_true', help='Send only public package names/versions to OSV.')
    args = parser.parse_args()
    entries, hashes = inventory()
    directory = ROOT / 'rest/writable/fse-dependency-audits' / uuid.uuid4().hex
    directory.mkdir(parents=True, exist_ok=False)
    report = {'generated_at': datetime.now(timezone.utc).isoformat(), 'status': 'inventory_only',
              'source_sha256': hashes, 'entries': entries, 'official_accreditation_evidence': False,
              'limitations': ['Version matching only; no exploitability assessment.',
                              'License metadata is not a legal clearance or complete distribution notice bundle.',
                              'No OS/container/Java/JS/CDN audit; no production inventory.',
                              'Vendored framework version declaration does not prove unmodified upstream sources.',
                              'rest/composer.lock is absent; installed local dependencies are inventoried instead.']}
    if args.online:
        try:
            triples = sorted({(e['ecosystem'], e['name'], e['version']) for e in entries})
            queries = [{'package': {'ecosystem': e, 'name': n}, 'version': v} for e, n, v in triples]
            findings = lookup(queries)
            details = {}
            for identifier in sorted({v for values in findings.values() for v in values}):
                details[identifier] = public_json('vulns/' + identifier)
            by_triple = {triple: sorted(findings.get(index, set())) for index, triple in enumerate(triples)}
            for entry in entries:
                entry['advisories'] = by_triple[(entry['ecosystem'], entry['name'], entry['version'])]
            report.update(status='findings_require_review' if details else 'no_known_advisories_in_queried_scope',
                          public_source='https://api.osv.dev/v1/querybatch', unique_queries=len(queries),
                          advisory_records=details)
        except Exception as error:
            report.update(status='incomplete_advisory_lookup', error_class=type(error).__name__)
    report['source_unchanged'] = all(hashlib.sha256((ROOT / path).read_bytes()).hexdigest() == digest for path, digest in hashes.items())
    if not report['source_unchanged']:
        report['status'] = 'incomplete_source_changed'
    output = directory / 'audit.json'
    output.write_text(json.dumps(report, indent=2, ensure_ascii=True) + '\n', encoding='utf-8')
    print(json.dumps({'report': str(output), 'status': report['status'], 'entries': len(entries),
                      'affected_entries': sum(bool(e.get('advisories')) for e in entries)}))
    return 2 if report['status'].startswith('incomplete') else (1 if report['status'] == 'findings_require_review' else 0)


if __name__ == '__main__':
    raise SystemExit(main())

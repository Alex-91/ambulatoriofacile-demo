"""Build-time installation of pinned public assets, never clinical data or keys.

Only runs in a new /opt/fse directory. A failed download/hash/install aborts the
image build. No network calls are made by the installed document validator.
"""
import hashlib
import json
import os
from pathlib import Path, PurePosixPath
import re
import shutil
import stat
import subprocess
import tempfile
from urllib.request import Request, build_opener, ProxyHandler, HTTPSHandler, HTTPRedirectHandler
from urllib.parse import urlsplit
import zipfile

HERE = Path(__file__).resolve().parent
DEST = Path('/opt/fse')
VERAPDF = 'https://software.verapdf.org/rel/1.30/verapdf-greenfield-1.30.2-installer.zip'
VERAPDF_SHA = '6cc6341cb1af644044054b81f00a6590a7918abb18f762243de115258bcad838'
VERAPDF_JAR_SHA = '889075253fb9df4db5482efb8f8208fb3b4f2e00f5f7e1b1e31edf6fb4b69bb6'
SCHXSLT = 'https://repo.maven.apache.org/maven2/name/dmaus/schxslt/schxslt/1.10.1/schxslt-1.10.1.jar'
SCHXSLT_SHA = '4f4f21edab7b37f96ad59ae12a344d3510f1092ac46b6d81a4efa0120b73cb58'
HOSTS = {'software.verapdf.org', 'repo.maven.apache.org', 'raw.githubusercontent.com'}


def check_url(url):
    p = urlsplit(url)
    if p.scheme != 'https' or p.hostname not in HOSTS or p.username or p.password or p.port not in (None, 443) or p.query or p.fragment:
        raise ValueError('ASSET_URL_NOT_ALLOWED')


class Redirects(HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        check_url(newurl)
        if urlsplit(newurl).hostname != urlsplit(req.full_url).hostname:
            raise ValueError('ASSET_CROSS_HOST_REDIRECT')
        return super().redirect_request(req, fp, code, msg, headers, newurl)


def fetch(url, expected, target, limit=40 * 1024 * 1024):
    check_url(url)
    if not re.fullmatch('[a-f0-9]{64}', expected):
        raise ValueError('ASSET_DIGEST')
    opener = build_opener(ProxyHandler({}), HTTPSHandler(), Redirects())
    with opener.open(Request(url, headers={'User-Agent': 'AmbulatorioFacile-FSE-build/1'}), timeout=45) as response:
        data = response.read(limit + 1)
    if len(data) > limit or hashlib.sha256(data).hexdigest() != expected:
        raise ValueError('ASSET_HASH_OR_SIZE')
    target.parent.mkdir(parents=True, exist_ok=True)
    with target.open('xb') as output:
        output.write(data)


def extract(archive, target):
    """Reject traversal, symlinks, duplicate names and oversized archives."""
    with zipfile.ZipFile(archive) as z:
        seen, size = set(), 0
        for item in z.infolist():
            name = item.orig_filename
            path = PurePosixPath(name)
            size += item.file_size
            if (not name or name != item.filename or '\\' in name or ':' in name or path.is_absolute()
                    or '..' in path.parts or name in seen or len(seen) >= 5000
                    or stat.S_ISLNK(item.external_attr >> 16) or size > 150 * 1024 * 1024):
                raise ValueError('ASSET_ARCHIVE_UNSAFE')
            seen.add(name)
        z.extractall(target)


def main():
    if os.name != 'posix' or DEST.exists():
        raise ValueError('FRESH_LINUX_BUILD_ONLY')
    DEST.mkdir(mode=0o755)
    manifest = json.loads((HERE / 'production-catalog.lock.json').read_text())
    if manifest['repository'] != 'ministero-salute/it-fse-catalogs' or manifest['revision'] != '687cf371e1d0caf4f5f9f7bcc80eab3a97f09885' or len(manifest['files']) != 12:
        raise ValueError('CATALOG_LOCK')
    for name, digest in manifest['files'].items():
        if not re.fullmatch(r'(schema|schematron)/[A-Za-z0-9_./-]+', name) or '..' in name:
            raise ValueError('CATALOG_PATH')
        fetch(f"https://raw.githubusercontent.com/{manifest['repository']}/{manifest['revision']}/{name}", digest, DEST / 'catalog' / name, 8 * 1024 * 1024)
    shutil.copyfile(HERE / 'production-catalog.lock.json', DEST / 'catalog/manifest.json')
    with tempfile.TemporaryDirectory(prefix='af-fse-install-') as job:
        job = Path(job)
        fetch(SCHXSLT, SCHXSLT_SHA, job / 'schxslt.jar')
        extract(job / 'schxslt.jar', DEST / 'schxslt')
        fetch(VERAPDF, VERAPDF_SHA, job / 'verapdf.zip')
        extract(job / 'verapdf.zip', job / 'installer')
        installer = job / 'installer/verapdf-greenfield-1.30.2/verapdf-izpack-installer-1.30.2.jar'
        shutil.copyfile(HERE / 'verapdf-install.xml', job / 'install.xml')
        subprocess.run(['/usr/bin/java', '-Xmx128m', '-Djava.awt.headless=true', '-jar', str(installer), str(job / 'install.xml')], check=True, timeout=120)
    jar = DEST / 'verapdf/bin/cli-1.30.2.jar'
    if hashlib.sha256(jar.read_bytes()).hexdigest() != VERAPDF_JAR_SHA:
        raise ValueError('VERAPDF_INSTALLED_HASH')
    shutil.copyfile(HERE / 'settings.example.json', DEST / 'settings.json')
    (DEST / 'public-assets.json').write_text(json.dumps({
        'verapdf_version': '1.30.2', 'verapdf_archive_sha256': VERAPDF_SHA,
        'verapdf_jar_sha256': VERAPDF_JAR_SHA,
        'verapdf_gpg_verified_on': '2026-09-13',
        'verapdf_gpg_fingerprint': '13DD102B4DD69354D12DE5A83184863278B17FE7',
        'schxslt_version': '1.10.1', 'schxslt_sha256': SCHXSLT_SHA,
        'catalog_revision': manifest['revision'], 'trust_material': 'not_included',
        'official_accreditation': False}, indent=2) + '\n')
    # Read-only to the Apache account; private trust material must be separate.
    for path in DEST.rglob('*'):
        path.chmod(0o755 if path.is_dir() else 0o644)
    print('FSE_PINNED_PUBLIC_ASSETS_INSTALLED')


if __name__ == '__main__':
    main()

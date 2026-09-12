"""Resolve public Docker Official Images to verified manifest digests; never pull/run/push images."""
import hashlib
import json
import re
import uuid
from datetime import datetime, timezone
from pathlib import Path

import requests

REPO = Path(__file__).resolve().parents[2]
IMAGES = {'php': ('library/php', '8.2-cli-bookworm'),
          'composer': ('library/composer', '2'), 'mysql': ('library/mysql', '8.4')}
ACCEPT = ', '.join(['application/vnd.oci.image.index.v1+json',
    'application/vnd.docker.distribution.manifest.list.v2+json',
    'application/vnd.oci.image.manifest.v1+json',
    'application/vnd.docker.distribution.manifest.v2+json'])


def digest(value):
    if not isinstance(value, str) or not re.fullmatch(r'sha256:[a-f0-9]{64}', value):
        raise ValueError('INVALID_IMAGE_DIGEST')
    return value


def manifest(body, header_digest):
    if len(body) > 1048576 or 'sha256:' + hashlib.sha256(body).hexdigest() != digest(header_digest):
        raise ValueError('MANIFEST_INTEGRITY_FAILED')
    value = json.loads(body)
    if not isinstance(value, dict) or value.get('schemaVersion') != 2:
        raise ValueError('MANIFEST_SCHEMA_UNSUPPORTED')
    return value


def select_amd64(index):
    choices = [entry for entry in index.get('manifests', [])
               if entry.get('platform', {}).get('os') == 'linux'
               and entry.get('platform', {}).get('architecture') == 'amd64'
               and entry.get('platform', {}).get('variant', '') in ('', 'v1')]
    if len(choices) != 1:
        raise ValueError('AMBIGUOUS_OR_MISSING_LINUX_AMD64')
    return digest(choices[0]['digest'])


def bounded_get(session, url, **kwargs):
    with session.get(url, timeout=(10, 30), allow_redirects=False, stream=True, **kwargs) as response:
        if response.status_code != 200:
            raise ValueError('REGISTRY_READ_FAILED')
        body = bytearray()
        for chunk in response.iter_content(65536):
            body.extend(chunk)
            if len(body) > 1048576:
                raise ValueError('REGISTRY_RESPONSE_TOO_LARGE')
        return bytes(body), response.headers.get('Docker-Content-Digest')


def resolve(session, repository, tag):
    # Endpoints, repositories and tags come from the fixed reviewed allowlist above.
    if (repository, tag) not in IMAGES.values():
        raise ValueError('IMAGE_NOT_ALLOWLISTED')
    token_body, _ = bounded_get(session, 'https://auth.docker.io/token',
        params={'service':'registry.docker.io', 'scope':f'repository:{repository}:pull'})
    token = json.loads(token_body).get('token')
    if not isinstance(token, str) or not token or len(token) > 16384:
        raise ValueError('REGISTRY_AUTH_FAILED')
    headers = {'Accept':ACCEPT, 'Authorization':'Bearer ' + token}
    base = 'https://registry-1.docker.io/v2/' + repository + '/manifests/'
    body, index_digest = bounded_get(session, base + tag, headers=headers)
    selected = select_amd64(manifest(body, index_digest))
    child_body, child_digest = bounded_get(session, base + selected, headers=headers)
    child = manifest(child_body, child_digest)
    if child_digest != selected or not child.get('layers'):
        raise ValueError('IMAGE_PLATFORM_MANIFEST_INVALID')
    digest(child['config']['digest'])
    for layer in child['layers']:
        digest(layer['digest'])
    return {'source_tag': repository + ':' + tag, 'index_digest':index_digest,
            'platform':'linux/amd64', 'manifest_digest':selected,
            'image':repository + '@' + selected,
            'config_digest':child['config']['digest'], 'layer_count':len(child['layers']),
            'compressed_layer_bytes':sum(layer['size'] for layer in child['layers'])}


def main():
    target = REPO / 'rest/writable/fse-linux-image-resolutions' / uuid.uuid4().hex
    target.mkdir(parents=True, exist_ok=False)
    report = {'mode':'PUBLIC_IMAGE_MANIFEST_RESOLUTION_ONLY', 'started_at':datetime.now(timezone.utc).isoformat(),
        'status':'incomplete', 'images':{}, 'images_pulled':False, 'linux_execution':'NOT_EXECUTED',
        'publisher_signature_verified':False, 'official_accreditation_evidence':False}
    try:
        with requests.Session() as session:
            session.trust_env = False  # No .netrc, saved Docker credentials or arbitrary proxy.
            for name, values in IMAGES.items():
                report['images'][name] = resolve(session, *values)
                print('Manifest verified: ' + name, flush=True)
        report['status'] = 'RESOLVED_NOT_PULLED_OR_EXECUTED'
    except (ValueError, KeyError, TypeError, requests.RequestException):
        report['status'] = 'FAILED_OR_INCOMPLETE'
        report['error'] = 'PUBLIC_REGISTRY_RESOLUTION_FAILED'
    finally:
        report['finished_at'] = datetime.now(timezone.utc).isoformat()
        with (target / 'result.json').open('x', encoding='utf-8') as stream:
            json.dump(report, stream, indent=2)
    print('Image resolution evidence: ' + str(target / 'result.json'))
    return 0 if report['status'] == 'RESOLVED_NOT_PULLED_OR_EXECUTED' else 2


if __name__ == '__main__':
    raise SystemExit(main())

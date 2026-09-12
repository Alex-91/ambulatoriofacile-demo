"""Read-only preflight of the generated FSE recipe. Never invokes Docker or a network API."""
import argparse
import hashlib
import importlib.util
import json
import re
import sys
from pathlib import Path

HERE = Path(__file__).resolve().parent
REPO = HERE.parents[1]
SPEC = importlib.util.spec_from_file_location('fse_bundle_verifier', HERE / 'verify-linux-bundle.py')
VERIFIER = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(VERIFIER)


def image_digest(value):
    if re.fullmatch(r'sha256:[a-f0-9]{64}', value):
        return value
    match = re.fullmatch(r'([a-z0-9][a-z0-9._:/-]{0,240})@sha256:[a-f0-9]{64}', value)
    if not match or '://' in value:
        raise ValueError('Immutable image SHA-256 required; mutable tags and URLs refused')
    return value


def check_recipe_files(context, templates=HERE):
    """Compare exact reviewed recipes; unknown Compose fields are not silently accepted."""
    context = Path(context)
    if context.is_symlink() or not re.fullmatch('[a-f0-9]{32}', context.name):
        raise ValueError('Invalid synthetic context identifier')
    result = {}
    for target_name, source_name in [('Dockerfile', 'linux-lab.Dockerfile'),
                                      ('.dockerignore', 'linux-lab.dockerignore'),
                                      ('compose.yaml', 'linux-lab.compose.yaml'),
                                      ('runtime/linux-images.lock.json', 'linux-images.lock.json')]:
        target = context / target_name
        if target.is_symlink() or not target.is_file() or target.stat().st_size > 262144:
            raise ValueError('Missing or unsafe recipe file')
        expected = (Path(templates) / source_name).read_text(encoding='utf-8').replace('__LAB_ID__', context.name)
        if target.read_text(encoding='utf-8') != expected:
            raise ValueError('Recipe changed: regenerate and review; do not bypass the check')
        result[target_name] = hashlib.sha256(target.read_bytes()).hexdigest()
    return result


def check_source_freshness(manifest, repo=REPO):
    """Compare packaged files to the selected local sources; not an upstream authenticity check."""
    files, origins = manifest.get('files'), manifest.get('source_files')
    variant = manifest.get('variant')
    if variant not in ('baseline', 'candidate') or not isinstance(files, dict) or not files or not isinstance(origins, dict) or set(files) != set(origins):
        raise ValueError('Missing source provenance; regenerate context')
    root = Path(repo).resolve(strict=True)
    candidate_roots = {'framework': set(), 'dependencies': set()}
    for packaged, origin in origins.items():
        if not isinstance(origin, str) or not isinstance(packaged, str) or not re.fullmatch('[A-Za-z0-9_./@-]+', origin) or any(p in ('', '.', '..') for p in origin.split('/')):
            raise ValueError('Unsafe source provenance')
        expected = packaged
        if packaged in ('rest/composer.json', 'rest/composer.lock'):
            expected = 'ops/fse-validation/linux-lab-' + packaged.split('/')[1]
        elif variant == 'candidate' and packaged.startswith('rest/system/'):
            match = re.fullmatch(r'(rest/writable/fse-framework-compat/[a-f0-9]{32})/source-4\.7\.4/CodeIgniter4-2bd0f01d2813f9ec06db42643ce39d9f5428bf6d/system/(.+)', origin)
            if not match or match[2] != packaged[len('rest/system/'):]:
                raise ValueError('Unexpected candidate framework source')
            candidate_roots['framework'].add(match[1])
            expected = origin
        elif variant == 'candidate' and packaged in ('composer.json', 'composer.lock'):
            match = re.fullmatch(r'(rest/writable/fse-dependency-compat/[a-f0-9]{32})/candidate/(composer\.(json|lock))', origin)
            if not match or match[2] != packaged:
                raise ValueError('Unexpected candidate dependency source')
            candidate_roots['dependencies'].add(match[1])
            expected = origin
        elif not re.fullmatch(r'(rest/(app|system|tests)/.+|public/.+|ops/fse-validation/.+|composer\.(json|lock))', packaged):
            raise ValueError('Unexpected packaged source')
        if origin != expected:
            raise ValueError('Unexpected source mapping')
        source = root / origin
        if any(part.is_symlink() for part in (source, *source.parents)) or not source.resolve(strict=True).is_relative_to(root) or not source.is_file():
            raise ValueError('Unsafe source file')
        if hashlib.sha256(source.read_bytes()).hexdigest() != files[packaged]:
            raise ValueError('Source changed since preparation; regenerate context')
    if variant == 'candidate' and any(len(roots) != 1 for roots in candidate_roots.values()):
        raise ValueError('Incomplete or mixed candidate source selection')
    return len(files)


def inspect_context(context, app_image=None, mysql_image=None):
    supplied = Path(context).absolute()
    resolved = supplied.resolve(strict=True)
    if supplied.is_symlink() or resolved.parent != (REPO / 'rest/writable/fse-linux-labs').resolve(strict=True):
        raise ValueError('Context must be a generated private FSE lab')
    if bool(app_image) != bool(mysql_image):
        raise ValueError('Specify both image digests, or neither for a preparation-only check')
    recipe_hashes = check_recipe_files(resolved)
    app_files = VERIFIER.verify(resolved / 'app', resolved / 'runtime/app-manifest.json')
    runtime_files = VERIFIER.verify(resolved / 'runtime', resolved / 'runtime/bundle-manifest.json')
    manifest = json.loads((resolved / 'runtime/app-manifest.json').read_text(encoding='utf-8'))
    current_sources = check_source_freshness(manifest)
    images = {'app': image_digest(app_image), 'mysql': image_digest(mysql_image)} if app_image else None
    locked = json.loads((resolved / 'runtime/linux-images.lock.json').read_text(encoding='utf-8'))
    if images and images['mysql'] != locked['images']['mysql']:
        raise ValueError('MySQL image differs from reviewed lock')
    return {'status': 'PREPARATION_CHECK_PASSED_NOT_STARTED', 'lab_id': resolved.name,
            'app_files': app_files, 'runtime_files': runtime_files, 'recipe_sha256': recipe_hashes,
            'variant': manifest['variant'], 'current_packaged_sources': current_sources,
            'source_freshness': 'PACKAGED_FILES_MATCH_SELECTED_LOCAL_SOURCES',
            'source_scope': 'Selected files only; not all checkout files or upstream authenticity',
            'base_images': locked['images'],
            'image_references': images, 'image_check': 'FORMAT_ONLY_NOT_INSPECTED' if images else 'NOT_PROVIDED',
            'linux_build': 'NOT_EXECUTED', 'runtime_isolation': 'NOT_ATTESTED', 'server_capacity': 'NOT_CHECKED',
            'official_accreditation_evidence': False, 'remote_changes': False}


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('context', type=Path)
    parser.add_argument('--app-image')
    parser.add_argument('--mysql-image')
    args = parser.parse_args()
    try:
        print(json.dumps(inspect_context(args.context, args.app_image, args.mysql_image), indent=2))
        return 0
    except (ValueError, OSError, KeyError, TypeError):
        # Do not echo untrusted file contents, paths, command arguments or parser internals.
        print(json.dumps({'status': 'PREPARATION_CHECK_FAILED', 'remote_changes': False,
                          'action': 'Regenerate the private context and review recipe/manifests and image digests.'}))
        return 2


if __name__ == '__main__':
    sys.exit(main())

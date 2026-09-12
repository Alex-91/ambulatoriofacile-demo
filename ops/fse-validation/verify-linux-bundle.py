"""Verify an allowlisted build context; never read application configuration or credentials."""
import hashlib
import json
import sys
from pathlib import Path, PurePosixPath


def verify(root, manifest):
    root = Path(root).resolve(strict=True)
    data = json.loads(Path(manifest).read_text(encoding='utf-8'))
    expected = set(data['files'])
    actual = set()
    manifest_path = Path(manifest).resolve(strict=True)
    for candidate in root.rglob('*'):
        if candidate.is_symlink():
            raise ValueError('Symlink in bundle')
        if candidate.is_file() and candidate.resolve(strict=True) != manifest_path:
            actual.add(candidate.relative_to(root).as_posix())
    for relative, digest in data['files'].items():
        parts = PurePosixPath(relative)
        if not relative or parts.is_absolute() or '..' in parts.parts or '\\' in relative or ':' in relative or parts.as_posix() != relative:
            raise ValueError('Unsafe manifest path')
        target = root / relative
        if target.is_symlink() or not target.resolve(strict=True).is_relative_to(root):
            raise ValueError('Manifest path escaped bundle')
        if hashlib.sha256(target.read_bytes()).hexdigest() != digest:
            raise ValueError('Bundle hash mismatch: ' + relative)
    if actual != expected:
        raise ValueError('Unlisted or missing bundle files')
    return len(data['files'])


if __name__ == '__main__':
    app, runtime = map(Path, sys.argv[1:3])
    print(json.dumps({'app_files': verify(app, runtime / 'app-manifest.json'),
                      'runtime_files': verify(runtime, runtime / 'bundle-manifest.json'),
                      'status': 'hashes_verified_not_accredited'}))

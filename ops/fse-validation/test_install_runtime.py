"""No network or application/database bootstrap: build installer regressions."""
import importlib.util
import io
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch
import zipfile

spec = importlib.util.spec_from_file_location('install_runtime', Path(__file__).with_name('install-runtime.py'))
runtime = importlib.util.module_from_spec(spec)
spec.loader.exec_module(runtime)


class InstallerTests(unittest.TestCase):
    def test_only_fixed_https_hosts(self):
        for url in ['http://software.verapdf.org/a', 'https://evil.test/a',
                    'https://software.verapdf.org@evil.test/a', 'https://software.verapdf.org:8443/a',
                    'https://software.verapdf.org/a?token=x', 'file:///etc/passwd']:
            with self.subTest(url=url), self.assertRaises(ValueError):
                runtime.check_url(url)
        runtime.check_url(runtime.VERAPDF)
        runtime.check_url(runtime.SCHXSLT)

    def test_wrong_digest_never_writes(self):
        opener = unittest.mock.MagicMock()
        opener.open.return_value.__enter__.return_value.read.return_value = b'wrong file'
        with tempfile.TemporaryDirectory() as folder, patch.object(runtime, 'build_opener', return_value=opener):
            path = Path(folder) / 'asset'
            with self.assertRaises(ValueError):
                runtime.fetch(runtime.SCHXSLT, '0' * 64, path)
            self.assertFalse(path.exists())

    def test_good_digest_written_once(self):
        opener = unittest.mock.MagicMock()
        opener.open.return_value.__enter__.return_value.read.return_value = b'public asset'
        with tempfile.TemporaryDirectory() as folder, patch.object(runtime, 'build_opener', return_value=opener):
            path = Path(folder) / 'asset'
            digest = runtime.hashlib.sha256(b'public asset').hexdigest()
            runtime.fetch(runtime.SCHXSLT, digest, path)
            self.assertEqual(path.read_bytes(), b'public asset')
            with self.assertRaises(FileExistsError):
                runtime.fetch(runtime.SCHXSLT, digest, path)

    def test_oversized_response_never_writes(self):
        opener = unittest.mock.MagicMock()
        opener.open.return_value.__enter__.return_value.read.return_value = b'123'
        with tempfile.TemporaryDirectory() as folder, patch.object(runtime, 'build_opener', return_value=opener):
            path = Path(folder) / 'asset'
            with self.assertRaises(ValueError):
                runtime.fetch(runtime.SCHXSLT, runtime.hashlib.sha256(b'123').hexdigest(), path, 2)
            self.assertFalse(path.exists())

    def test_archive_paths_and_links(self):
        for name in ['../escape', '/escape', 'a/../../escape', 'a\\escape', 'C:/escape']:
            with self.subTest(name=name), tempfile.TemporaryDirectory() as folder:
                buffer = io.BytesIO()
                with zipfile.ZipFile(buffer, 'w') as archive:
                    info = zipfile.ZipInfo('payload')
                    info.filename = name  # Preserve malicious backslashes on Windows too.
                    archive.writestr(info, b'x')
                buffer.seek(0)
                with self.assertRaises(ValueError):
                    runtime.extract(buffer, Path(folder) / 'out')
                self.assertFalse((Path(folder) / 'out').exists())
        buffer = io.BytesIO()
        with zipfile.ZipFile(buffer, 'w') as archive:
            link = zipfile.ZipInfo('link')
            link.external_attr = 0o120777 << 16
            archive.writestr(link, '/etc/passwd')
        buffer.seek(0)
        with tempfile.TemporaryDirectory() as folder, self.assertRaises(ValueError):
            runtime.extract(buffer, Path(folder))

    def test_normal_archive(self):
        buffer = io.BytesIO()
        with zipfile.ZipFile(buffer, 'w') as archive:
            archive.writestr('xslt/pipeline.xsl', b'public stylesheet')
        buffer.seek(0)
        with tempfile.TemporaryDirectory() as folder:
            runtime.extract(buffer, Path(folder))
            self.assertEqual((Path(folder) / 'xslt/pipeline.xsl').read_bytes(), b'public stylesheet')

    def test_existing_installation_is_not_modified(self):
        with tempfile.TemporaryDirectory() as folder, patch.object(runtime, 'DEST', Path(folder)):
            with self.assertRaises(ValueError):
                runtime.main()

    def test_empty_trust_settings_and_no_live_sends(self):
        settings = runtime.json.loads((runtime.HERE / 'settings.example.json').read_text())
        self.assertEqual(settings['trust_roots'], [])
        self.assertEqual(settings['crls'], [])
        self.assertEqual(settings['ocsps'], [])
        dockerfile = (runtime.HERE.parents[1] / 'Dockerfile').read_text()
        self.assertIn('FSE2_ALLOW_PRODUCTION=false', dockerfile)
        self.assertIn('FSE2_ALLOW_TOSCANA_STAGE=false', dockerfile)
        self.assertIn('FSE2_VALIDATOR_MAX_CONCURRENT=1', dockerfile)


if __name__ == '__main__':
    unittest.main()

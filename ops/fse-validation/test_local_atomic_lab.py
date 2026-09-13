"""Offline guards for the scoped overlay; never invokes WSL or Docker."""
import copy
import importlib.util
import json
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

spec = importlib.util.spec_from_file_location('local_atomic_lab', Path(__file__).with_name('local-atomic-lab.py'))
lab = importlib.util.module_from_spec(spec)
spec.loader.exec_module(lab)


class AtomicLabGuards(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        self.id = '1' * 32
        self.directory = self.root / self.id
        self.directory.mkdir()
        hashes = {}
        for name in lab.FILES:
            target = self.directory / name
            target.parent.mkdir(parents=True, exist_ok=True)
            target.write_bytes(b'synthetic source')
            hashes[name] = lab.ctl.sha(target)
        (self.directory / 'Dockerfile').write_bytes(b'synthetic recipe')
        self.image = 'sha256:' + '2' * 64
        self.info = {'mode': 'FSE_ATOMIC_SCOPED_OVERLAY', 'base_image': lab.BASE,
                     'files': hashes, 'image': self.image,
                     'recipe_sha256': lab.ctl.sha(self.directory / 'Dockerfile')}
        self.base = {'Id': lab.BASE, 'RootFS': {'Layers': ['base-layer']},
                     'Config': {'User': '33:33', 'Labels': {}}}
        self.derived = copy.deepcopy(self.base)
        self.derived['Id'] = self.image
        self.derived['RootFS']['Layers'].append('overlay-layer')
        self.derived['Config']['Labels']['af.scope'] = 'fse-local-atomic-only'
        self.save_manifest()
        for attribute, value in [('BUILDS', self.root)]:
            guard = patch.object(lab, attribute, value)
            guard.start()
            self.addCleanup(guard.stop)
        for attribute in ('IMAGE', 'SCRIPTS'):
            guard = patch.object(lab.ctl, attribute, getattr(lab.ctl, attribute))
            guard.start()
            self.addCleanup(guard.stop)
        guard = patch.object(lab.ctl, 'docker', side_effect=self.inspect_only)
        self.docker = guard.start()
        self.addCleanup(guard.stop)

    def save_manifest(self):
        (self.directory / 'build.json').write_text(json.dumps(self.info), encoding='utf-8')

    def inspect_only(self, *args, **kwargs):
        self.assertEqual(args[:2], ('image', 'inspect'))
        self.assertIn(args[2], (lab.BASE, self.image))
        return json.dumps([self.base if args[2] == lab.BASE else self.derived]).encode()

    def test_valid_overlay_uses_exact_image_and_only_read_only_inspection(self):
        path, info = lab.load_build(self.id)
        self.assertEqual(path, self.directory)
        self.assertEqual(info['image'], self.image)
        self.assertEqual(lab.ctl.IMAGE, self.image)
        self.assertEqual(self.docker.call_count, 2)

    def test_invalid_ids_rejected_before_docker(self):
        for value in (None, '', '../' + self.id, 'main', 'A' * 32):
            with self.subTest(value=value), self.assertRaises(RuntimeError):
                lab.load_build(value)
        self.docker.assert_not_called()

    def test_changed_source_rejected_before_docker(self):
        (self.directory / lab.FILES[0]).write_bytes(b'changed')
        with self.assertRaisesRegex(RuntimeError, 'Changed build source'):
            lab.load_build(self.id)
        self.docker.assert_not_called()

    def test_changed_recipe_rejected_before_docker(self):
        (self.directory / 'Dockerfile').write_bytes(b'changed')
        with self.assertRaisesRegex(RuntimeError, 'Changed recipe'):
            lab.load_build(self.id)
        self.docker.assert_not_called()

    def test_extra_product_file_is_not_allowed(self):
        self.info['files']['rest/app/Controllers/Unrelated.php'] = '0' * 64
        self.save_manifest()
        with self.assertRaisesRegex(RuntimeError, 'Wrong scoped build'):
            lab.load_build(self.id)
        self.docker.assert_not_called()

    def test_other_base_is_not_allowed(self):
        self.info['base_image'] = 'sha256:' + '3' * 64
        self.save_manifest()
        with self.assertRaisesRegex(RuntimeError, 'Wrong scoped build'):
            lab.load_build(self.id)
        self.docker.assert_not_called()

    def test_different_ancestry_is_not_allowed(self):
        self.derived['RootFS']['Layers'][0] = 'other-layer'
        with self.assertRaisesRegex(RuntimeError, 'Wrong image ancestry or scope'):
            lab.load_build(self.id)

    def test_runtime_configuration_change_is_not_allowed(self):
        self.derived['Config']['User'] = 'root'
        with self.assertRaisesRegex(RuntimeError, 'Runtime configuration changed: User'):
            lab.load_build(self.id)

    def test_failed_or_completed_scenario_is_never_overwritten(self):
        (self.directory / 'evidence').mkdir()
        result = self.directory / 'evidence/atomic-scenarios.json'
        result.write_bytes(b'{"status":"incomplete"}')
        with self.assertRaisesRegex(RuntimeError, 'Never rerun'):
            lab.scenarios(self.directory, self.info)
        self.assertEqual(result.read_bytes(), b'{"status":"incomplete"}')
        self.docker.assert_not_called()


if __name__ == '__main__':
    unittest.main()

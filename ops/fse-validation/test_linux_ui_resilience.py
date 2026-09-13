"""Offline safety checks for the supplementary Linux UI harness."""
import importlib.util
import unittest
import json
import tempfile
from unittest.mock import patch
from pathlib import Path

spec = importlib.util.spec_from_file_location('relay', Path(__file__).with_name('linux-ui-relay.py'))
relay = importlib.util.module_from_spec(spec)
spec.loader.exec_module(relay)


class Boundaries(unittest.TestCase):
    def test_only_loopback_origin_and_expected_host(self):
        self.assertTrue(relay.valid_request('GET', '/login', '127.0.0.1:8088', None, None))
        for host in ('localhost:8088','127.0.0.1:8085','evil.invalid','127.0.0.1:8088.evil.invalid'):
            self.assertFalse(relay.valid_request('GET', '/login', host, None, None))

    def test_cross_origin_and_ambiguous_targets_refused(self):
        for path in ('https://example.com/x','//example.com/x','/\\example.com/x','/x\r\ny'):
            self.assertFalse(relay.valid_request('GET', path, '127.0.0.1:8088', None, None))
        for source in ('null','https://127.0.0.1:8088','http://example.com','http://user@127.0.0.1:8088'):
            self.assertFalse(relay.valid_request('POST', '/login', '127.0.0.1:8088', source, None))
            self.assertFalse(relay.valid_request('POST', '/login', '127.0.0.1:8088', None, source))

    def test_no_connect_or_arbitrary_instance_selection(self):
        self.assertFalse(relay.valid_request('CONNECT','/','127.0.0.1:8088',None,None))
        for value in ('../other', 'main', '', 'a'*31, 'g'*32):
            with self.assertRaises(ValueError):
                relay.instance_tools.instance(value)

    def test_http_port_alone_does_not_attest_database_health(self):
        ctl = relay.instance_tools
        with patch.object(ctl, 'inspect', return_value={'State': {'Health': {'Status': 'healthy'}}}), \
                patch.object(ctl, 'worker_json', side_effect=RuntimeError('DB unreachable')):
            with self.assertRaisesRegex(RuntimeError, 'DB unreachable'):
                ctl.healthy(Path('synthetic'), 'app', timeout=1)

    def test_wrong_database_image_is_refused(self):
        ctl = relay.instance_tools
        path = Path('a' * 32)
        data = {'Config': {'Labels': {'com.docker.compose.project': 'af-fse-ui-' + path.name,
                                     'com.docker.compose.service': 'mysql'}}, 'Image': 'untrusted'}
        with patch.object(ctl, 'docker', side_effect=[b'a'*64, json.dumps([data]).encode()]), \
                patch.object(ctl, 'compose_args', return_value=[]):
            with self.assertRaisesRegex(ValueError, 'Database image changed'):
                ctl.inspect(path, 'mysql')

    def test_changed_production_flag_is_refused(self):
        ctl = relay.instance_tools
        path = Path('a' * 32)
        data = {'Config': {'Labels': {'com.docker.compose.project': 'af-fse-ui-' + path.name,
                                     'com.docker.compose.service': 'app'}, 'User': '33:33',
                           'Env': ['FSE2_ALLOW_PRODUCTION=true', 'FSE2_ALLOW_TOSCANA_STAGE=false']},
                'Image': ctl.IMAGE, 'HostConfig': {'ReadonlyRootfs': True}, 'Mounts': []}
        with patch.object(ctl, 'docker', side_effect=[b'a'*64, json.dumps([data]).encode()]), \
                patch.object(ctl, 'compose_args', return_value=[]):
            with self.assertRaisesRegex(ValueError, 'isolation flags changed'):
                ctl.inspect(path, 'app')

    def test_evidence_cannot_be_overwritten(self):
        with tempfile.TemporaryDirectory(prefix='fse-evidence-') as folder:
            target = Path(folder) / 'synthetic.json'
            relay.instance_tools.save(target, {'original': True})
            with self.assertRaises(FileExistsError):
                relay.instance_tools.save(target, {'replacement': True})
            self.assertEqual(json.loads(target.read_text()), {'original': True})


if __name__ == '__main__':
    unittest.main(verbosity=2)

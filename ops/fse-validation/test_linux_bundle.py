"""Packaging and private HTTP harness guard tests. No HTTP requests, Docker or Linux execution."""
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import tempfile
import unittest
from unittest.mock import Mock, patch
import yaml
from check_linux_recipe import check_recipe_files, check_source_freshness, image_digest
from test_dependency_audit import DependencyAuditTests
from test_linux_images import ImageManifestTests
from test_local_atomic_lab import AtomicLabGuards

HERE = Path(__file__).resolve().parent
SPEC = importlib.util.spec_from_file_location('fse_bundle_verifier', HERE / 'verify-linux-bundle.py')
MODULE = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(MODULE)
verify = MODULE.verify
HTTP_SPEC = importlib.util.spec_from_file_location('fse_lab_http', HERE / 'app-lab-http.py')
HTTP = importlib.util.module_from_spec(HTTP_SPEC)
HTTP_SPEC.loader.exec_module(HTTP)


class LinuxBundleTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        (self.root / 'ok.txt').write_text('synthetic', encoding='utf-8')
        self.manifest = self.root / 'manifest.json'

    def write_manifest(self, files):
        self.manifest.write_text(json.dumps({'files': files}), encoding='utf-8')

    def test_matching_bundle(self):
        self.write_manifest({'ok.txt': hashlib.sha256(b'synthetic').hexdigest()})
        self.assertEqual(1, verify(self.root, self.manifest))

    def source_fixture(self):
        relative='rest/app/Synthetic.php'
        source=self.root / relative
        source.parent.mkdir(parents=True)
        source.write_bytes(b'<?php // synthetic only')
        return source, {'variant':'baseline', 'files':{relative:hashlib.sha256(source.read_bytes()).hexdigest()}, 'source_files':{relative:relative}}

    def test_current_packaged_source_is_accepted(self):
        _, manifest=self.source_fixture()
        self.assertEqual(1, check_source_freshness(manifest,self.root))

    def test_reviewed_retina_asset_filename_is_accepted(self):
        relative='public/plugins/iCheck/flat/aero@2x.png'
        source=self.root / relative
        source.parent.mkdir(parents=True)
        source.write_bytes(b'synthetic image fixture')
        self.assertEqual(1, check_source_freshness({'variant':'baseline',
            'files':{relative:hashlib.sha256(source.read_bytes()).hexdigest()},
            'source_files':{relative:relative}},self.root))

    def test_changed_source_rejects_self_consistent_old_package(self):
        source, manifest=self.source_fixture()
        source.write_bytes(b'<?php // updated source')
        with self.assertRaisesRegex(ValueError, 'Source changed'):
            check_source_freshness(manifest,self.root)

    def test_missing_or_unsafe_source_provenance_is_rejected(self):
        _, manifest=self.source_fixture()
        for mapping in [{}, {'rest/app/Synthetic.php':'../outside'}, {'rest/app/Synthetic.php':'rest/app/Other.php'}, {'rest/app/Synthetic.php':'C:/outside'}]:
            with self.assertRaises(ValueError):
                check_source_freshness(dict(manifest,source_files=mapping),self.root)

    def test_candidate_cannot_use_baseline_framework_or_omit_dependencies(self):
        _, manifest=self.source_fixture()
        with self.assertRaisesRegex(ValueError, 'Incomplete'):
            check_source_freshness(dict(manifest,variant='candidate'),self.root)
        for packaged in ['rest/system/CodeIgniter.php','composer.lock']:
            with self.assertRaisesRegex(ValueError, 'Unexpected candidate'):
                check_source_freshness({'variant':'candidate','files':{packaged:'0'*64}, 'source_files':{packaged:packaged}}, self.root)

    def test_alteration_rejected(self):
        self.write_manifest({'ok.txt': '0' * 64})
        with self.assertRaisesRegex(ValueError, 'hash mismatch'):
            verify(self.root, self.manifest)

    def test_traversal_rejected(self):
        for path in ['../outside', '/outside', r'folder\outside', 'C:/outside', './ok.txt', '']:
            self.write_manifest({path: '0' * 64})
            with self.assertRaisesRegex(ValueError, 'Unsafe'):
                verify(self.root, self.manifest)

    def test_unlisted_file_rejected(self):
        self.write_manifest({'ok.txt': hashlib.sha256(b'synthetic').hexdigest()})
        (self.root / 'unexpected.env').write_text('synthetic', encoding='utf-8')
        with self.assertRaisesRegex(ValueError, 'Unlisted'):
            verify(self.root, self.manifest)

    def test_compose_has_no_public_ports_or_host_mounts(self):
        spec = yaml.safe_load((HERE / 'linux-lab.compose.yaml').read_text(encoding='utf-8'))
        self.assertTrue(spec['networks']['isolated']['internal'])
        self.assertEqual({'lab-state', 'lab-mysql'}, set(spec['volumes']))
        for service in spec['services'].values():
            self.assertNotIn('ports', service)
            self.assertNotIn('privileged', service)
            self.assertNotIn('extra_hosts', service)
            self.assertNotIn('devices', service)
            self.assertIn('mem_limit', service)
            self.assertIn('cpus', service)
            self.assertIn('pids_limit', service)
            self.assertEqual('no', service['restart'])
            for volume in service.get('volumes', []):
                self.assertIn(volume.split(':', 1)[0], spec['volumes'])
        self.assertEqual('none', spec['services']['init']['network_mode'])
        self.assertEqual('service:mysql', spec['services']['app']['network_mode'])
        self.assertEqual(['isolated'], spec['services']['mysql']['networks'])

    def test_application_fails_closed_and_runs_unprivileged(self):
        spec = yaml.safe_load((HERE / 'linux-lab.compose.yaml').read_text(encoding='utf-8'))
        app = spec['services']['app']
        self.assertEqual('33:33', app['user'])
        self.assertTrue(app['read_only'])
        self.assertEqual(['ALL'], app['cap_drop'])
        self.assertEqual('false', app['environment']['FSE2_ALLOW_PRODUCTION'])
        self.assertEqual('false', app['environment']['FSE2_ALLOW_TOSCANA_STAGE'])
        self.assertIn('127.0.0.1:8088', (HERE / 'linux-lab-entrypoint.sh').read_text(encoding='utf-8'))
        self.assertNotIn('app-lab-seed', (HERE / 'linux-lab-entrypoint.sh').read_text(encoding='utf-8'))

    def recipe(self):
        context = self.root / ('a' * 32)
        context.mkdir()
        for dest, source in [('Dockerfile','linux-lab.Dockerfile'), ('.dockerignore','linux-lab.dockerignore'), ('compose.yaml','linux-lab.compose.yaml'), ('runtime/linux-images.lock.json','linux-images.lock.json')]:
            (context / dest).parent.mkdir(parents=True, exist_ok=True)
            (context / dest).write_text((HERE / source).read_text(encoding='utf-8').replace('__LAB_ID__',context.name), encoding='utf-8')
        return context

    def test_generated_recipe_matches_reviewed_sources(self):
        self.assertEqual({'Dockerfile','.dockerignore','compose.yaml','runtime/linux-images.lock.json'}, set(check_recipe_files(self.recipe())))

    def test_base_images_are_pinned_to_the_reviewed_lock(self):
        locked=json.loads((HERE / 'linux-images.lock.json').read_text(encoding='utf-8'))
        self.assertEqual('linux/amd64',locked['platform'])
        self.assertEqual({'php','composer','mysql'},set(locked['images']))
        for name, value in locked['images'].items():
            self.assertEqual(value,image_digest(value))
            self.assertTrue(value.startswith('library/'+name+'@sha256:'))
        recipe=(HERE / 'linux-lab.Dockerfile').read_text(encoding='utf-8')
        self.assertIn('FROM '+locked['images']['php']+'\n',recipe)
        self.assertIn('COPY --from='+locked['images']['composer']+' ',recipe)

    def test_added_port_or_external_volume_is_not_accepted(self):
        context = self.recipe()
        target = context / 'compose.yaml'
        original = target.read_text(encoding='utf-8')
        for change in [original.replace('  app:\n', '  app:\n    ports: ["8088:8088"]\n'),
                       original.replace('  lab-mysql:\n', '  lab-mysql:\n    external: true\n'),
                       original.replace("FSE2_ALLOW_PRODUCTION: 'false'", "FSE2_ALLOW_PRODUCTION: 'true'")]:
            target.write_text(change, encoding='utf-8')
            with self.assertRaisesRegex(ValueError, 'Recipe changed'):
                check_recipe_files(context)

    def test_missing_dockerignore_is_rejected(self):
        context = self.recipe()
        (context / '.dockerignore').unlink()
        with self.assertRaisesRegex(ValueError, 'Missing'):
            check_recipe_files(context)

    def test_missing_or_mutable_image_identity_is_rejected(self):
        for value in ['', 'mysql:8.4', 'latest', 'sha256:abc', 'https://host/image@sha256:'+'a'*64,
                      'user:password@registry/image@sha256:'+'a'*64, 'sha256:'+'A'*64]:
            with self.assertRaises(ValueError):
                image_digest(value)

    def test_digest_format_does_not_imply_image_exists(self):
        for value in ['sha256:'+'a'*64, 'mysql@sha256:'+'b'*64, 'registry.invalid:5000/af/lab@sha256:'+'c'*64]:
            self.assertEqual(value, image_digest(value))

    def test_recipe_identifier_cannot_select_an_existing_project(self):
        with self.assertRaisesRegex(ValueError, 'identifier'):
            check_recipe_files(self.root)

    def test_http_harness_refuses_off_host_and_other_local_ports(self):
        for target in ['https://127.0.0.1:8088/login','http://127.0.0.1:8085/login','//example.invalid/login',
                       'http://user:pass@127.0.0.1:8088/login','http://localhost:8088/login','https://example.invalid']:
            with self.assertRaisesRegex(RuntimeError, 'DESTINATION_REFUSED'):
                HTTP.local_url(target)
        self.assertEqual('http://127.0.0.1:8088/admin/fse2', HTTP.local_url('/admin/fse2'))

    def test_http_harness_requires_one_consistent_csrf_value(self):
        good='<input name="csrf_test_name" value="synthetic">'
        self.assertEqual('synthetic', HTTP.csrf(good+good))
        for html in ['',good+'<input name="csrf_test_name" value="different">','<input name="csrf_test_name" value="">']:
            with self.assertRaisesRegex(RuntimeError, 'CSRF_FORM_TOKEN_MISSING'):
                HTTP.csrf(html)

    def test_http_harness_ignores_proxy_and_netrc_environment(self):
        session=HTTP.LabSession('a'*32)
        try:
            self.assertFalse(session.session.trust_env)
        finally:
            session.session.close()

    def test_http_harness_requires_selected_dependency_headers(self):
        response = HTTP.requests.Response()
        response.status_code = 200
        response.headers.update({'X-FSE-Test-Environment':'synthetic-only', 'X-FSE-Lab-Id':'a'*32,
                                 'X-FSE-Framework-Version':'4.7.4'})
        session = HTTP.LabSession('a'*32)
        self.addCleanup(session.session.close)
        session.session.request = Mock(return_value=response)
        with patch.dict(os.environ, {'FSE_DEPENDENCY_LAB':'synthetic', 'FSE_APPLICATION_VARIANT':'candidate',
                                     'FSE_FRAMEWORK_LAB':'synthetic', 'FSE_FRAMEWORK_VARIANT':'candidate'}, clear=True):
            for variant, request_id in [('', ''), ('baseline', 'b'*32), ('candidate', '../proof'), ('candidate', 'b'*31)]:
                response.headers['X-FSE-Dependency-Variant'] = variant
                response.headers['X-FSE-Dependency-Request'] = request_id
                with self.assertRaisesRegex(RuntimeError, 'HTTP_DEPENDENCY_VARIANT_MISMATCH'):
                    session.request('GET', '/login')
            response.headers.update({'X-FSE-Dependency-Variant':'candidate', 'X-FSE-Dependency-Request':'b'*32})
            self.assertIs(response, session.request('GET', '/login'))
            self.assertEqual({'b'*32}, session.dependency_requests)

    def test_http_harness_rejects_wrong_framework_before_accepting_response(self):
        response = HTTP.requests.Response()
        response.status_code = 200
        response.headers.update({'X-FSE-Test-Environment':'synthetic-only', 'X-FSE-Lab-Id':'a'*32,
                                 'X-FSE-Framework-Version':'4.6.0'})
        session = HTTP.LabSession('a'*32)
        self.addCleanup(session.session.close)
        session.session.request = Mock(return_value=response)
        with patch.dict(os.environ, {'FSE_FRAMEWORK_LAB':'synthetic', 'FSE_FRAMEWORK_VARIANT':'candidate'}, clear=True):
            with self.assertRaisesRegex(RuntimeError, 'HTTP_FRAMEWORK_VERSION_MISMATCH'):
                session.request('GET', '/login')

    def test_http_harness_never_follows_an_external_redirect(self):
        response = HTTP.requests.Response()
        response.status_code = 302
        response.headers.update({'X-FSE-Test-Environment':'synthetic-only', 'X-FSE-Lab-Id':'a'*32,
                                 'Location':'https://example.invalid/collect'})
        session = HTTP.LabSession('a'*32)
        self.addCleanup(session.session.close)
        session.session.request = Mock(return_value=response)
        with patch.dict(os.environ, {}, clear=True):
            with self.assertRaisesRegex(RuntimeError, 'HTTP_DESTINATION_REFUSED'):
                session.request('GET', '/login')
        self.assertEqual(1, session.session.request.call_count)
        self.assertFalse(session.session.request.call_args.kwargs['allow_redirects'])


if __name__ == '__main__':
    program = unittest.main(exit=False)
    report = os.environ.get('FSE2_LINUX_TEST_REPORT')
    if report:
        target = Path(report).absolute()
        allowed = (HERE.parents[1] / 'rest/writable/fse-validation-reports').resolve(strict=True)
        if target.is_symlink() or target.exists() or not target.resolve().is_relative_to(allowed):
            raise RuntimeError('A new isolated report path is required')
        result = program.result
        with target.open('x', encoding='utf-8') as stream:
            json.dump({'mode':'OFFLINE_RECIPE_TESTS_ONLY', 'linux_execution':'NOT_EXECUTED',
                       'tests':result.testsRun, 'failures':len(result.failures), 'errors':len(result.errors),
                       'skipped':len(result.skipped), 'passed':result.wasSuccessful() and not result.skipped}, stream, indent=2)
    raise SystemExit(0 if program.result.wasSuccessful() else 1)

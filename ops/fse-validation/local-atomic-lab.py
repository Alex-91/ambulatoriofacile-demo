"""An explicit six-file FSE overlay on the previously verified Linux candidate.

Runtime images use immutable IDs; a unique build-only local alias is verified
against the base ID. No dependency install, public port or old evidence overwrite.
"""
import argparse
import concurrent.futures
import contextlib
import importlib.util
import io
import json
import re
import subprocess
import sys
import time
import uuid
from pathlib import Path

spec = importlib.util.spec_from_file_location('atomic_ctl', Path(__file__).with_name('linux-ui-resilience.py'))
ctl = importlib.util.module_from_spec(spec)
spec.loader.exec_module(ctl)
BASE = ctl.IMAGE
BUILDS = ctl.REPO / 'rest/writable/fse-atomic-linux-builds'
FILES = tuple('rest/app/Services/' + name + '.php' for name in (
    'FseAuditService', 'FseDocumentService', 'FseRevisionService', 'FseLocalPersistenceService')) + (
    'rest/tests/unit/FseLocalPersistenceTest.php', 'rest/tests/unit/FseRevisionTest.php')


def require(ok, message):
    if not ok:
        raise RuntimeError(message)


def linux(path):
    return '/mnt/' + path.drive[0].lower() + path.as_posix()[2:]


def build():
    require(not ctl.docker('ps', '--quiet').strip(), 'Other containers running; no build')
    ctl.docker('image', 'inspect', BASE)
    BUILDS.mkdir(exist_ok=True)
    path = BUILDS / uuid.uuid4().hex
    path.mkdir()
    hashes = {}
    for name in FILES:
        source = ctl.REPO / name
        require(source.is_file() and not source.is_symlink(), 'Regular source files required')
        target = path / name
        target.parent.mkdir(parents=True, exist_ok=True)
        with target.open('xb') as output:
            output.write(source.read_bytes())
        hashes[name] = ctl.sha(target)
    # BuildKit treats a bare sha256 image ID in FROM as a registry repository.
    # A fresh local alias is necessary for the build only; never used at runtime.
    alias = 'af-fse-atomic-base-' + path.name + ':local'
    ctl.docker('tag', BASE, alias)
    require(json.loads(ctl.docker('image', 'inspect', alias))[0]['Id'] == BASE, 'Build alias mismatch')
    recipe = 'FROM ' + alias + '\nLABEL af.scope="fse-local-atomic-only"\n'
    recipe += ''.join('COPY ' + json.dumps([name, '/var/www/html/' + name]) + '\n' for name in FILES)
    (path / 'Dockerfile').write_text(recipe, encoding='utf-8')
    (path / '.dockerignore').write_text('**\n!Dockerfile\n!rest/\n!rest/**\n', encoding='utf-8')
    try:
        output = ctl.docker('build', '--network', 'none', '--pull=false', '--progress=plain',
                            '--iidfile', linux(path / 'image-id.txt'), linux(path), timeout=180)
    except Exception as error:
        ctl.save(path / 'build-failure.json', {'status': 'failed', 'error': str(error)})
        raise
    require(json.loads(ctl.docker('image', 'inspect', alias))[0]['Id'] == BASE, 'Build alias changed')
    (path / 'build-output.log').write_bytes(output)
    image = (path / 'image-id.txt').read_text().strip()
    require(re.fullmatch('sha256:[a-f0-9]{64}', image), 'Immutable image required')
    info = {'mode': 'FSE_ATOMIC_SCOPED_OVERLAY', 'id': path.name, 'base_image': BASE, 'image': image, 'build_alias': alias,
            'files': hashes, 'recipe_sha256': ctl.sha(path / 'Dockerfile'),
            'scope': 'Six listed files only; all other product files/dependencies are from the previous immutable candidate'}
    ctl.save(path / 'build.json', info)
    print(json.dumps(info, indent=2))


def load_build(value):
    require(re.fullmatch('[a-f0-9]{32}', value or ''), 'Exact build ID required')
    path = BUILDS / value
    require(not path.is_symlink() and path.resolve(strict=True).parent == BUILDS.resolve(), 'Build boundary')
    info = json.loads((path / 'build.json').read_text())
    require(info['mode'] == 'FSE_ATOMIC_SCOPED_OVERLAY' and info['base_image'] == BASE and set(info['files']) == set(FILES), 'Wrong scoped build')
    require(ctl.sha(path / 'Dockerfile') == info['recipe_sha256'], 'Changed recipe')
    for name, digest in info['files'].items():
        require(ctl.sha(path / name) == digest, 'Changed build source')
    image = json.loads(ctl.docker('image', 'inspect', info['image']))[0]
    base = json.loads(ctl.docker('image', 'inspect', BASE))[0]
    layers = base['RootFS']['Layers']
    require(image['Id'] == info['image'] and image['RootFS']['Layers'][:len(layers)] == layers
            and image['Config']['Labels'].get('af.scope') == 'fse-local-atomic-only', 'Wrong image ancestry or scope')
    for key in ('User', 'Entrypoint', 'Cmd', 'Env', 'WorkingDir', 'Volumes'):
        require(image['Config'].get(key) == base['Config'].get(key), 'Runtime configuration changed: ' + key)
    ctl.IMAGE = info['image']
    ctl.SCRIPTS = (*ctl.SCRIPTS, 'fse-local-atomic-worker.php')
    return path, info


def call_main(action, instance):
    previous = sys.argv
    try:
        sys.argv = ['controller', action, instance]
        ctl.main()
    finally:
        sys.argv = previous


def scenarios(path, build_info):
    target = path / 'evidence' / 'atomic-scenarios.json'
    require(not target.exists(), 'Never rerun this scenario instance')
    lab = '/var/www/html/rest/writable/fse-app-labs/' + path.name
    worker = lab + '/harness/fse-local-atomic-worker.php'
    w = lambda *a: ctl.worker_json(path, *a)
    a = lambda *args: json.loads(ctl.app_command(path, ['php', worker, *map(str, args)]))
    snapshot = lambda: w('snapshot')['data']
    report = {'mode': 'ACTUAL_FSE_LOCAL_TRANSACTION_FAULTS', 'status': 'incomplete', 'build': build_info,
              'official_accreditation_evidence': False, 'external_gateway_calls': 0,
              'runner_sha256': ctl.sha(__file__), 'checks': {}}

    def checked(name, ok, evidence):
        require(ok, name)
        report['checks'][name] = evidence
        print(name + ': passed', flush=True)

    def race(action, document, count):
        token = uuid.uuid4().hex
        with concurrent.futures.ThreadPoolExecutor(max_workers=count) as pool:
            jobs = [pool.submit(w, action, document, token, i) for i in range(count)]
            deadline = time.monotonic() + 45
            while w('barrier-status', token)['ready'] != count:
                for job in jobs:
                    if job.done() and job.exception():
                        raise RuntimeError('Worker failed before barrier') from job.exception()
                require(time.monotonic() < deadline, 'Barrier timeout')
                time.sleep(.2)
            w('release', token)
            results = [job.result(timeout=180) for job in jobs]
        ctl.save(path / 'evidence' / (action + '-' + token + '.json'), results)
        return results

    try:
        initial = snapshot()
        require(all(t['fse_documents']['count'] == 0 for t in initial.values()), 'Empty synthetic databases required')
        original = w('new-draft')['id']
        w('prepare', original)
        source = w('unsigned-copy', original)['unsigned_pdf']
        require(source == lab + '/writable/ui-original-' + str(original) + '.pdf', 'Fixed unsigned fixture required')
        ctl.app_command(path, ['/opt/fse/venv/bin/python', 'ops/fse-validation/app-lab-sign.py', source])
        w('accept', original)
        sealed = a('proof', original)
        checked('real_synthetic_signature_committed_with_audit', sealed['state'] == 'signed', sealed)
        editable = w('new-draft')['id']
        edits = race('race-save', editable, 4)
        checked('atomic_concurrent_edits', sum(r['outcome'] == 'accepted' for r in edits) == 1
                and all(r['outcome'] == 'accepted' or 'ricaricare' in r.get('message', '') for r in edits), edits)
        preparations = race('race-prepare', editable, 2)
        checked('atomic_concurrent_preparations', sum(r['outcome'] == 'accepted' for r in preparations) == 1
                and all(r['outcome'] == 'accepted' or 'elaborazione' in r.get('message', '') for r in preparations), preparations)
        revisions = race('race-revision', original, 4)
        checked('atomic_concurrent_revisions', all(r['outcome'] == 'accepted' for r in revisions)
                and len({r['id'] for r in revisions}) == 1 and a('proof', original) == sealed, revisions)

        for mode, service in [('new', 'app'), ('edit', 'app'), ('prepare', 'app'), ('new', 'mysql')]:
            before = snapshot()
            before_editable = a('proof', editable)
            token = uuid.uuid4().hex
            app = ctl.inspect(path, 'app', True)
            proc = subprocess.Popen(['wsl.exe', '-d', ctl.DISTRO, '-u', 'root', '--exec', 'docker', 'exec',
                                     app['Id'], 'php', worker, 'fault', token, mode, str(editable if mode != 'new' else 0)],
                                    stdout=subprocess.PIPE, stderr=subprocess.PIPE)
            deadline = time.monotonic() + 60
            checkpoint = None
            while time.monotonic() < deadline:
                checkpoint = a('checkpoint', token)
                if checkpoint:
                    break
                require(proc.poll() is None, 'Fault worker exited before checkpoint')
                time.sleep(.2)
            require(checkpoint and checkpoint['phase'] == 'application_write_before_required_audit', 'No application transaction checkpoint')
            if mode == 'new':
                require(not a('proof', checkpoint['id'])['exists'], 'Uncommitted draft visible outside application transaction')
            data = ctl.inspect(path, service, True)
            ctl.docker('kill', '--signal', 'KILL', data['Id'])
            stopped = ctl.inspect(path, service, False)['State']
            require(stopped['ExitCode'] == 137 and not stopped['OOMKilled'], 'Wrong controlled failure')
            if service == 'mysql':
                ctl.docker('stop', '--time', '20', app['Id'], timeout=35)
            proc.communicate(timeout=30)
            ctl.docker('start', data['Id'])
            ctl.healthy(path, service)
            if service == 'mysql':
                ctl.docker('start', app['Id'])
                ctl.healthy(path, 'app')
            after = snapshot()
            after_editable = a('proof', editable)
            stable = before == after
            if mode == 'prepare':
                stable = after_editable['state'] == 'preparing' and before['43'] == after['43'] \
                    and before['42']['fse_document_events'] == after['42']['fse_document_events'] \
                    and before['42']['artifacts'] == after['42']['artifacts'] \
                    and before_editable['stable_row_sha256'] == after_editable['stable_row_sha256']
            checked('actual_' + mode + '_transaction_' + service + '_crash', stable and a('proof', original) == sealed,
                    {'checkpoint': checkpoint, 'stopped': stopped, 'after_editable': after_editable,
                     'no_outer_test_transaction': True, 'original_unchanged': True})
        ctl.save(path / 'evidence' / 'atomic-final-state.json', snapshot())
        ctl.snapshot(path, 'atomic-healthy')
        report['status'] = 'passed'
    except Exception as error:
        report['error'] = str(error)[:1200]
        raise
    finally:
        report['finished_at'] = time.time()
        ctl.save(target, report)
    print('All eight scoped atomic scenarios passed.', flush=True)


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('action', choices=['build', 'prepare', 'start', 'run', 'stop', 'unit'])
    parser.add_argument('build_id', nargs='?')
    parser.add_argument('instance_id', nargs='?')
    args = parser.parse_args()
    if args.action == 'build':
        build()
        return
    build_path, info = load_build(args.build_id)
    if args.action == 'prepare':
        output = io.StringIO()
        with contextlib.redirect_stdout(output):
            ctl.prepare('resilience')
        instance_info = json.loads(output.getvalue())
        path, _ = ctl.instance(instance_info['id'])
        ctl.save(path / 'atomic-scope.json', {'build_id': args.build_id, 'scope': info['scope']})
        print(json.dumps(instance_info, indent=2))
        return
    path, _ = ctl.instance(args.instance_id)
    require(json.loads((path / 'atomic-scope.json').read_text())['build_id'] == args.build_id, 'Wrong instance build')
    if args.action in ('start', 'stop'):
        call_main(args.action, path.name)
    elif args.action == 'run':
        scenarios(path, info)
    elif args.action == 'unit':
        destination = path / 'evidence' / ('atomic-unit-' + uuid.uuid4().hex + '.log')
        require(not (path / 'evidence' / 'atomic-unit.json').exists(), 'Unit success already recorded')
        app = ctl.inspect(path, 'app', True)
        # Preserve failed test output too; only synthetic suite output is involved.
        p = subprocess.run(['wsl.exe', '-d', ctl.DISTRO, '-u', 'root', '--exec', 'docker', 'exec', app['Id'],
            'env', 'FSE2_VALIDATOR_PYTHON=/opt/fse/venv/bin/python', 'FSE2_VALIDATOR_SETTINGS=/opt/fse/settings.json',
            'FSE2_RUN_ARTIFACT_INTEGRATION=1', 'php', 'rest/vendor/bin/phpunit', '-c', 'ops/fse-validation/phpunit.xml',
            '--bootstrap', 'ops/fse-validation/linux-clinical-bootstrap.php',
            '--filter', 'Fse', '--fail-on-skipped', '--no-coverage', '--do-not-cache-result'], capture_output=True, timeout=240)
        with destination.open('xb') as output:
            output.write(p.stdout + p.stderr)
        print(p.stdout.decode(errors='replace')[-2200:])
        require(p.returncode == 0 and b'OK (' in p.stdout and b'Skipped:' not in p.stdout, 'Linux unit suite failed or incomplete')
        ctl.save(path / 'evidence' / 'atomic-unit.json', {'passed': True, 'log': destination.name,
                 'sha256': ctl.sha(destination), 'runner_sha256': ctl.sha(__file__)})


if __name__ == '__main__':
    main()

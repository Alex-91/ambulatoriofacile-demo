"""Manage NEW synthetic instances of the already tested FSE Linux image.

No deploy, database import, global Docker/WSL stop, public port or host mount.
The immutable product image is unchanged; supplementary CLI harnesses are
copied separately into the new private writable volume with their hashes.
"""
import argparse
import hashlib
import json
import os
import re
import subprocess
import sys
import time
import uuid
from datetime import datetime, timezone
from pathlib import Path

REPO = Path(__file__).resolve().parents[2]
ROOT = REPO / 'rest/writable/fse-linux-ui-resilience'
DISTRO = 'AmbulatorioFacile-FSE'
IMAGE = 'sha256:10c1973e8bc302115429402e2de5cd48bd38a550fcc6b33b98f50d6fff490b3a'
MYSQL = 'library/mysql@sha256:8c19b656bb381f163750b238852bd377ba5764e1ec30cdd3f02e55cf8e2f89b7'
SCRIPTS = ('linux-ui-resilience-worker.php', 'linux-ui-resilience-http.py')


def run(args, timeout=60, input=None):
    p = subprocess.run(args, input=input, capture_output=True, timeout=timeout)
    if p.returncode:
        # No stdout dump: a command can be handling synthetic credentials.
        raise RuntimeError('COMMAND_FAILED: ' + p.stderr.decode(errors='replace')[-1500:])
    return p.stdout


def wsl(*args, **kwargs):
    return run(['wsl.exe', '-d', DISTRO, '-u', 'root', '--exec', *args], **kwargs)


def docker(*args, **kwargs):
    return wsl('docker', *args, **kwargs)


def sha(path):
    return hashlib.sha256(Path(path).read_bytes()).hexdigest()


def save(path, value):
    with Path(path).open('x', encoding='utf-8') as stream:
        json.dump(value, stream, indent=2)


def instance(value):
    if not re.fullmatch('[a-f0-9]{32}', value or ''):
        raise ValueError('Invalid instance ID')
    path = ROOT / value
    if path.is_symlink() or path.resolve(strict=True).parent != ROOT.resolve(strict=True):
        raise ValueError('Instance boundary')
    info = json.loads((path / 'instance.json').read_text())
    if info.get('mode') != 'FSE_LINUX_UI_RESILIENCE' or info.get('id') != value or info.get('image') != IMAGE:
        raise ValueError('Instance marker mismatch')
    if sha(path / 'compose.yaml') != info['compose_sha256']:
        raise ValueError('Compose changed')
    for name, digest in info['harness_sha256'].items():
        if name not in SCRIPTS or sha(path / 'harness' / name) != digest:
            raise ValueError('Harness changed')
    return path, info


def compose_args(path):
    # Compose file remains on the Windows workspace. No application host mounts.
    converted = '/mnt/' + path.drive[0].lower() + path.as_posix()[2:]
    return ['compose', '-f', converted + '/compose.yaml']


def inspect(path, service, running=None):
    ids = docker(*compose_args(path), 'ps', '--all', '--quiet', service).decode().split()
    if len(ids) != 1 or not re.fullmatch('[a-f0-9]{64}', ids[0]):
        raise ValueError('Expected one exact lab container')
    data = json.loads(docker('inspect', ids[0]))[0]
    if data['Config']['Labels'].get('com.docker.compose.project') != 'af-fse-ui-' + path.name:
        raise ValueError('Container project mismatch')
    if data['Config']['Labels'].get('com.docker.compose.service') != service:
        raise ValueError('Container service mismatch')
    if service == 'app' and data['Image'] != IMAGE:
        raise ValueError('Application image changed')
    if service == 'mysql' and data['Image'] != MYSQL.split('@', 1)[1]:
        raise ValueError('Database image changed')
    if data['HostConfig'].get('Privileged') or data['HostConfig'].get('PortBindings'):
        raise ValueError('Unexpected privileges or published ports')
    if any(m['Type'] != 'volume' for m in data['Mounts']):
        raise ValueError('Unexpected host mount')
    if service == 'app':
        required = {'FSE2_ALLOW_PRODUCTION=false', 'FSE2_ALLOW_TOSCANA_STAGE=false'}
        if not required.issubset(set(data['Config']['Env'])) or data['Config']['User'] != '33:33' or not data['HostConfig']['ReadonlyRootfs']:
            raise ValueError('Application isolation flags changed')
        mysql = inspect(path, 'mysql')
        if data['HostConfig']['NetworkMode'] != 'container:' + mysql['Id']:
            raise ValueError('Unexpected application network')
    else:
        network = 'af-fse-ui-' + path.name + '_isolated'
        if data['HostConfig']['NetworkMode'] != network or not json.loads(docker('network', 'inspect', network))[0]['Internal']:
            raise ValueError('Expected internal lab network')
    if running is not None and data['State']['Running'] != running:
        raise ValueError('Unexpected running state')
    return data


def app_command(path, args, timeout=120, input=None):
    app = inspect(path, 'app', True)
    return docker('exec', '-i', app['Id'], *args, timeout=timeout, input=input)


def healthy(path, service, timeout=75):
    deadline = time.monotonic() + timeout
    while time.monotonic() < deadline:
        data = inspect(path, service, True)
        if data['State'].get('Health', {}).get('Status') == 'healthy':
            if service == 'app':
                # A TCP-only HTTP healthcheck can pass while the shared MySQL
                # namespace has changed. Verify real tenant DB connectivity too.
                worker_json(path, 'snapshot')
            return data
        time.sleep(1)
    raise RuntimeError('Synthetic service did not become healthy: ' + service)


def worker_json(path, *args):
    target = '/var/www/html/rest/writable/fse-app-labs/' + path.name + '/harness/linux-ui-resilience-worker.php'
    return json.loads(app_command(path, ['php', target, *map(str, args)], timeout=240))


def snapshot(path, label):
    if not re.fullmatch('[a-z][a-z0-9-]{0,35}', label):
        raise ValueError('Invalid evidence label')
    selected = []
    for service in ('app', 'mysql'):
        data = inspect(path, service)
        selected.append({
            'service': service, 'id': data['Id'], 'image': data['Image'],
            'state': data['State'], 'user': data['Config']['User'],
            'host_config': {key: data['HostConfig'].get(key) for key in (
                'Init', 'ReadonlyRootfs', 'Privileged', 'CapDrop', 'CapAdd',
                'SecurityOpt', 'Memory', 'MemorySwap', 'NanoCpus', 'PidsLimit',
                'PortBindings', 'NetworkMode')},
            'flags': [v for v in data['Config']['Env'] if v.startswith((
                'FSE2_ALLOW_', 'PHP_CLI_SERVER_WORKERS=', 'TINI_KILL_PROCESS_GROUP='))],
            'mounts': [{k: m[k] for k in ('Type', 'Name', 'Destination', 'RW')} for m in data['Mounts']],
        })
    value = {'at': datetime.now(timezone.utc).isoformat(), 'containers': selected}
    save(path / 'evidence' / (label + '.json'), value)
    return value


def prepare(purpose):
    import yaml
    ROOT.mkdir(exist_ok=True)
    path = ROOT / uuid.uuid4().hex
    path.mkdir()
    (path / 'harness').mkdir()
    (path / 'evidence').mkdir()
    template = yaml.safe_load((REPO / 'ops/fse-validation/linux-lab.compose.yaml').read_text(encoding='utf-8'))
    template = json.loads(json.dumps(template).replace('__LAB_ID__', path.name))
    template['name'] = 'af-fse-ui-' + path.name
    if purpose == 'resilience':
        # Four paused service workers plus audit connections exceed the very
        # small 20-connection browser lab limit. CPU/RAM limits stay unchanged.
        command = template['services']['mysql']['command']
        template['services']['mysql']['command'] = ['--max-connections=64' if item == '--max-connections=20' else item for item in command]
    for service in template['services'].values():
        service['image'] = MYSQL if service is template['services']['mysql'] else IMAGE
        service['pull_policy'] = 'never'
    app = template['services']['app']
    app['init'] = True
    app['environment']['PHP_CLI_SERVER_WORKERS'] = '4'
    app['environment']['TINI_KILL_PROCESS_GROUP'] = '1'
    app['stop_grace_period'] = '20s'
    app['healthcheck']['test'] = ['CMD', 'php', '-r', '$$a=@fsockopen("127.0.0.1",8088);$$b=@fsockopen("127.0.0.1",33079);exit($$a&&$$b?0:1);']
    (path / 'compose.yaml').write_text(yaml.safe_dump(template, sort_keys=False), encoding='utf-8')
    hashes = {}
    for name in SCRIPTS:
        raw = (REPO / 'ops/fse-validation' / name).read_bytes()
        (path / 'harness' / name).write_bytes(raw)
        hashes[name] = hashlib.sha256(raw).hexdigest()
    info = {'mode': 'FSE_LINUX_UI_RESILIENCE', 'id': path.name,
            'purpose': purpose, 'image': IMAGE, 'mysql': MYSQL,
            'created_at': datetime.now(timezone.utc).isoformat(),
            'compose_sha256': sha(path / 'compose.yaml'), 'harness_sha256': hashes,
            'source_scope': 'Immutable previous product image plus separately hashed test harnesses; not current checkout',
            'official_accreditation_evidence': False}
    save(path / 'instance.json', info)
    print(json.dumps(info, indent=2))


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('action', choices=['prepare', 'start', 'stop', 'snapshot', 'worker', 'http', 'export', 'restart', 'sign-ui', 'capture', 'healthy'])
    parser.add_argument('id', nargs='?')
    parser.add_argument('args', nargs='*')
    parser.add_argument('--purpose', choices=['browser', 'resilience'], default='browser')
    args = parser.parse_args()
    if args.action == 'prepare':
        prepare(args.purpose)
        return
    path, info = instance(args.id)
    if args.action == 'start':
        # Never overlap other labs on this resource-limited dedicated distribution.
        if docker('ps', '--quiet').strip() or docker(*compose_args(path), 'ps', '--all', '--quiet').strip():
            raise RuntimeError('Running containers or already initialized project; refusing start')
        if json.loads(docker('image', 'inspect', IMAGE))[0]['Id'] != IMAGE:
            raise RuntimeError('Test image not available')
        docker(*compose_args(path), 'up', '-d', timeout=240)
        app = inspect(path, 'app', True)
        src = path / 'harness'
        converted = '/mnt/' + src.drive[0].lower() + src.as_posix()[2:]
        lab = '/var/www/html/rest/writable/fse-app-labs/' + path.name
        docker('cp', converted, app['Id'] + ':' + lab + '/harness')
        print(app_command(path, ['php', 'ops/fse-validation/app-lab-seed.php']).decode())
        snapshot(path, 'initial')
    elif args.action == 'stop':
        inspect(path, 'app')
        inspect(path, 'mysql')
        docker(*compose_args(path), 'stop', timeout=60)
        snapshot(path, args.args[0] if args.args else 'stopped')
        print('Only this lab stopped; volumes preserved.')
    elif args.action == 'snapshot':
        print(json.dumps(snapshot(path, args.args[0]), indent=2))
    elif args.action == 'healthy':
        for service in ('mysql', 'app'):
            healthy(path, service)
        print('Both synthetic services healthy.')
    elif args.action == 'capture':
        if len(args.args) != 1 or not re.fullmatch('[a-z][a-z0-9-]{0,35}', args.args[0]):
            raise ValueError('Evidence label required')
        value = worker_json(path, 'snapshot')
        save(path / 'evidence' / (args.args[0] + '.json'), value)
        print(json.dumps(value, indent=2))
    elif args.action in ('worker', 'http'):
        script = 'linux-ui-resilience-worker.php' if args.action == 'worker' else 'linux-ui-resilience-http.py'
        binary = 'php' if args.action == 'worker' else '/opt/fse/venv/bin/python'
        target = '/var/www/html/rest/writable/fse-app-labs/' + path.name + '/harness/' + script
        print(app_command(path, [binary, target, *args.args], timeout=240).decode())
    elif args.action == 'sign-ui':
        if len(args.args) != 1 or not re.fullmatch('[1-9][0-9]*', args.args[0]):
            raise ValueError('Document ID required')
        lab = '/var/www/html/rest/writable/fse-app-labs/' + path.name
        raw = app_command(path, ['php', lab + '/harness/linux-ui-resilience-worker.php', 'unsigned-copy', args.args[0]])
        original = json.loads(raw)['unsigned_pdf']
        if original != lab + '/writable/ui-original-' + args.args[0] + '.pdf':
            raise ValueError('Unexpected synthetic original')
        print(app_command(path, ['/opt/fse/venv/bin/python', 'ops/fse-validation/app-lab-sign.py', original]).decode())
    elif args.action == 'restart':
        if len(args.args) != 2 or args.args[0] not in ('app', 'mysql') or args.args[1] not in ('graceful', 'kill'):
            raise ValueError('Exact service and restart mode required')
        service, mode = args.args
        data = inspect(path, service, True)
        before = datetime.now(timezone.utc)
        if mode == 'graceful':
            docker('stop', '--time', '20', data['Id'], timeout=40)
        else:
            docker('kill', '--signal', 'KILL', data['Id'])
        state = inspect(path, service, False)['State']
        dependent = None
        if service == 'mysql':
            # The app shares MySQL's network namespace; rejoin it on restart.
            dependent = inspect(path, 'app', True)
            docker('stop', '--time', '20', dependent['Id'], timeout=40)
        docker('start', data['Id'])
        healthy(path, service)
        if dependent:
            docker('start', dependent['Id'])
            healthy(path, 'app')
        report = {'mode': mode, 'service': service, 'container': data['Id'],
                  'stopped_state': state, 'elapsed_seconds': (datetime.now(timezone.utc)-before).total_seconds()}
        save(path / 'evidence' / ('restart-' + service + '-' + mode + '-' + uuid.uuid4().hex[:8] + '.json'), report)
        print(json.dumps(report, indent=2))
    elif args.action == 'export':
        app = inspect(path, 'app', True)
        lab = '/var/www/html/rest/writable/fse-app-labs/' + path.name
        # Export only known synthetic fixtures/reports, never lab.json, DB credentials or keys.
        if args.args == ['reports']:
            names = json.loads(app_command(path, ['php', '-r', 'echo json_encode(array_map("basename",array_merge(glob(getenv("FSE_LAB_ROOT")."/browser-state-*.json"),glob(getenv("FSE_LAB_ROOT")."/http-load-*.json"))));']))
            for name in names:
                if not re.fullmatch(r'(browser-state-[a-f0-9]{16}|http-load-[0-9]{15,25})\.json', name):
                    raise ValueError('Unexpected report name')
                target = path / 'evidence' / name
                if target.exists():
                    continue
                destination = '/mnt/' + target.drive[0].lower() + target.as_posix()[2:]
                docker('cp', app['Id'] + ':' + lab + '/' + name, destination)
                print(json.dumps({'file': str(target), 'sha256': sha(target)}))
        elif args.args == ['browser-login']:
            raw = app_command(path, ['php', '-r', '$c=json_decode(file_get_contents(getenv("FSE_LAB_ROOT")."/lab.json"),true);echo json_encode(["password"=>$c["login_password"]]);'])
            target = path / 'browser-login.local.json'
            with target.open('xb') as stream:
                stream.write(raw)
            print('Synthetic browser credentials saved privately; not printed.')
        elif len(args.args) == 1 and args.args[0] in ('synthetic-signed.pdf', 'synthetic-altered.pdf'):
            target = path / args.args[0]
            if target.exists():
                raise ValueError('Never overwrite exported evidence')
            destination = '/mnt/' + target.drive[0].lower() + target.as_posix()[2:]
            docker('cp', app['Id'] + ':' + lab + '/signing/' + args.args[0], destination)
            print(json.dumps({'file': str(target), 'sha256': sha(target)}))
        elif len(args.args) == 1 and re.fullmatch(r'(browser-state-[a-f0-9]{16}|http-load-[0-9]{15,25})\.json', args.args[0]):
            target = path / 'evidence' / args.args[0]
            if target.exists():
                raise ValueError('Never overwrite evidence')
            destination = '/mnt/' + target.drive[0].lower() + target.as_posix()[2:]
            docker('cp', app['Id'] + ':' + lab + '/' + args.args[0], destination)
            print(json.dumps({'file': str(target), 'sha256': sha(target)}))
        else:
            raise ValueError('Only fixed synthetic exports accepted')


if __name__ == '__main__':
    main()

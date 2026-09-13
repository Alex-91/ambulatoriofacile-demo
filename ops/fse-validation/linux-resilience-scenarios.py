"""Bounded concurrency and deterministic crash tests on a NEW synthetic lab only.

Run once on an empty, prepared 'resilience' instance. Evidence is append-only.
The production image is never edited. Fault checkpoints are injected via test
dependencies, never via production flags or manual SQL state resets.
"""
import concurrent.futures
import importlib.util
import json
import subprocess
import sys
import time
import uuid
from pathlib import Path

spec = importlib.util.spec_from_file_location('labctl', Path(__file__).with_name('linux-ui-resilience.py'))
ctl = importlib.util.module_from_spec(spec)
spec.loader.exec_module(ctl)


def require(ok, message):
    if not ok:
        raise RuntimeError(message)


def main():
    path, info = ctl.instance(sys.argv[1])
    require(info['purpose'] == 'resilience', 'Dedicated resilience instance required')
    result_path = path / 'evidence' / 'resilience-summary.json'
    require(not result_path.exists(), 'Never rerun a completed or failed scenario instance')
    report = {'mode': 'BOUNDED_SYNTHETIC_CONCURRENCY_AND_CRASHES', 'status': 'incomplete',
              'official_accreditation_evidence': False, 'image': info['image'],
              'runner_sha256': ctl.sha(__file__), 'controller_sha256': ctl.sha(ctl.__file__), 'checks': {}}
    w = lambda *a: ctl.worker_json(path, *a)
    snapshot = lambda: w('snapshot')['data']
    proof = lambda i: w('proof', i)
    processes = []

    def check(name, ok, evidence):
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
                        raise RuntimeError('A service worker failed before reaching the barrier') from job.exception()
                require(time.monotonic() < deadline, 'Workers did not reach the start barrier')
                time.sleep(.2)
            w('release', token)
            results = [job.result(timeout=180) for job in jobs]
        ctl.save(path / 'evidence' / (action + '-' + token + '.json'), results)
        return results

    def spawn(*args):
        app = ctl.inspect(path, 'app', True)
        script = '/var/www/html/rest/writable/fse-app-labs/' + path.name + '/harness/linux-ui-resilience-worker.php'
        p = subprocess.Popen(['wsl.exe', '-d', ctl.DISTRO, '-u', 'root', '--exec', 'docker', 'exec',
                              app['Id'], 'php', script, *map(str, args)], stdout=subprocess.PIPE, stderr=subprocess.PIPE)
        processes.append(p)
        return p

    def checkpoint(token):
        deadline = time.monotonic() + 45
        while time.monotonic() < deadline:
            value = w('checkpoint', token)
            if value:
                return value
            time.sleep(.2)
        raise RuntimeError('No interruption checkpoint')

    def interrupt(service):
        data = ctl.inspect(path, service, True)
        ctl.docker('kill', '--signal', 'KILL', data['Id'])
        state = ctl.inspect(path, service, False)['State']
        require(state['ExitCode'] == 137 and not state['OOMKilled'], 'Not the intended controlled SIGKILL')
        ctl.save(path / 'evidence' / ('killed-' + service + '-' + uuid.uuid4().hex + '.json'), state)
        return data['Id'], state

    def restart(container, service):
        ctl.docker('start', container)
        ctl.healthy(path, service)

    try:
        initial = snapshot()
        require(all(t['fse_documents']['count'] == 0 for t in initial.values()), 'Empty synthetic tenants required')
        draft = w('new-draft')['id']
        before = proof(draft)
        edits = race('race-save', draft, 4)
        after = proof(draft)
        require(all(r['outcome'] == 'accepted' or ('ricaricare' in r.get('message', '')) for r in edits), 'Unexpected save failure')
        check('four_edits_one_winner', sum(r['outcome'] == 'accepted' for r in edits) == 1
              and after['event_count'] == before['event_count'] + 1 and snapshot()['42']['fse_documents']['count'] == 1, edits)
        prepared = race('race-prepare', draft, 2)
        require(all(r['outcome'] == 'accepted' or ('elaborazione' in r.get('message', '') or 'ricaricare' in r.get('message', '')) for r in prepared), 'Unexpected preparation failure')
        check('two_preparations_one_winner', sum(r['outcome'] == 'accepted' for r in prepared) == 1
              and proof(draft)['state'] == 'ready_to_validate', prepared)
        # Only the fixed synthetic signer, with an ephemeral test CA, can seal this fixture.
        ctl.run([sys.executable, '-B', str(Path(ctl.__file__)), 'sign-ui', path.name, str(draft)], timeout=180)
        w('accept', draft)
        sealed = proof(draft)
        sealed_artifacts = snapshot()['42']['artifacts']
        revisions = race('race-revision', draft, 4)
        check('four_revision_requests_one_child', all(r['outcome'] == 'accepted' for r in revisions)
              and len({r['id'] for r in revisions}) == 1 and proof(draft) == sealed
              and snapshot()['42']['fse_documents']['count'] == 2, revisions)
        counts_before = snapshot()
        creates = race('load-create', 0, 4)
        counts_after = snapshot()
        for tenant in (42, 43):
            ids = [i for r in creates if r.get('tenant') == tenant for i in r.get('ids', [])]
            require(len(ids) == len(set(ids)) == 20, 'Duplicate or lost synthetic create')
            require(counts_after[str(tenant)]['fse_documents']['count'] == counts_before[str(tenant)]['fse_documents']['count'] + 20,
                    'Wrong tenant create count')
            require(counts_after[str(tenant)]['fse_document_events']['count'] == counts_before[str(tenant)]['fse_document_events']['count'] + 20,
                    'Lost or duplicated create audit')
        check('forty_creates_two_tenants', proof(draft) == sealed, creates)

        interrupted = w('new-draft')['id']
        pre_crash = proof(interrupted)
        token = uuid.uuid4().hex
        proc = spawn('crash-prepare', interrupted, token)
        ready = checkpoint(token)
        require(ready['phase'] == 'after_prepare_lock_before_artifact_write', 'Wrong prepare interruption point')
        require(proof(interrupted)['state'] == 'preparing', 'Persisted lock not observed before kill')
        app_id, killed = interrupt('app')
        proc.communicate(timeout=30)
        restart(app_id, 'app')
        locked = w('assert-locked', interrupted)
        check('app_crash_preserves_and_locks_draft', locked['proof']['clinical_sha256'] == pre_crash['clinical_sha256']
              and locked['proof']['events_sha256'] == pre_crash['events_sha256'] and proof(draft) == sealed
              and snapshot()['42']['artifacts'] == sealed_artifacts,
              {'checkpoint': ready, 'locked': locked, 'exit_code': killed['ExitCode'], 'worker_exit_code': proc.returncode})

        before_db_crash = snapshot()
        token = uuid.uuid4().hex
        proc = spawn('crash-transaction', token)
        ready = checkpoint(token)
        require(ready['phase'] == 'uncommitted_draft_and_audit', 'Wrong database interruption point')
        require(not proof(ready['id'])['exists'], 'Uncommitted row was visible to an independent connection')
        mysql_id, killed = interrupt('mysql')
        # Both services share a private network namespace. Stop the app as well,
        # preserving all volumes, then rejoin the database's new namespace.
        app = ctl.inspect(path, 'app', True)
        ctl.docker('stop', '--time', '20', app['Id'], timeout=35)
        proc.communicate(timeout=30)
        restart(mysql_id, 'mysql')
        restart(app['Id'], 'app')
        after_db_crash = snapshot()
        check('mysql_crash_rolls_back_uncommitted_write', not proof(ready['id'])['exists']
              and before_db_crash == after_db_crash and proof(draft) == sealed,
              {'checkpoint': ready, 'committed_state_unchanged': True, 'uncommitted_row_absent': True,
               'uncommitted_audit_absent': True, 'exit_code': killed['ExitCode']})
        ctl.save(path / 'evidence' / 'resilience-final-state.json', after_db_crash)
        ctl.snapshot(path, 'resilience-healthy')
        report['status'] = 'passed'
        report['external_gateway_calls'] = 0
        report['limits'] = 'Bounded synthetic sample; no real signature provider, production load, arbitrary power-loss or official FSE calls.'
    except Exception as error:
        report['failure'] = str(error)[:1200]
        raise
    finally:
        # A failed scenario never resets data or silently releases a persisted lock.
        # Known CLI processes are bounded at 300s; caller must stop its own lab.
        report['finished_at'] = time.time()
        ctl.save(result_path, report)
    print(json.dumps(report, indent=2))


if __name__ == '__main__':
    main()

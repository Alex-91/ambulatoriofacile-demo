"""Offline corroboration of recorded browser state, bounded load and crash tests.

This does not run a browser and cannot independently attest the recorded UI
actions. It verifies the machine-readable evidence that corroborates them.
"""
import importlib.util
import argparse
import json
import sys
from pathlib import Path

spec = importlib.util.spec_from_file_location('fse_ctl', Path(__file__).with_name('linux-ui-resilience.py'))
ctl = importlib.util.module_from_spec(spec)
spec.loader.exec_module(ctl)


def verify(browser_id, resilience_id):
    browser, bi = ctl.instance(browser_id)
    resilience, ri = ctl.instance(resilience_id)
    if bi['purpose'] != 'browser' or ri['purpose'] != 'resilience':
        raise ValueError('Distinct browser and resilience instances required')
    checks = []

    def check(ok, name):
        if not ok:
            raise ValueError(name)
        checks.append(name)

    def read(path, name):
        return json.loads((path / 'evidence' / name).read_text(encoding='utf-8'))

    def only(path, pattern):
        files = list((path / 'evidence').glob(pattern))
        check(len(files) == 1, 'exactly_one_' + pattern)
        return json.loads(files[0].read_text(encoding='utf-8'))

    audit = only(browser, 'browser-state-*.json')
    check(audit['status'] == 'passed_state_corroboration' and len(audit['checks']) == 15
          and set(audit['checks'].values()) == {'passed'}, 'fifteen_browser_state_checks')
    load = only(browser, 'http-load-*.json')
    check(load['status'] == 'passed' and load['clients'] == 4 and load['authenticated_list_requests'] == 40,
          'four_sessions_forty_authenticated_reads')
    snapshots = [read(browser, name + '.json') for name in
                 ('before-load', 'after-load', 'after-app-restart', 'after-paired-restart')]
    check(all(v == snapshots[0] for v in snapshots), 'browser_database_audit_artifacts_unchanged')
    check(snapshots[0]['data']['42']['fse_documents']['count'] == 2
          and snapshots[0]['data']['43']['fse_documents']['count'] == 0,
          'two_documents_in_a_zero_in_b')
    result = read(resilience, 'resilience-summary.json')
    expected = {'four_edits_one_winner', 'two_preparations_one_winner', 'four_revision_requests_one_child',
                'forty_creates_two_tenants', 'app_crash_preserves_and_locks_draft',
                'mysql_crash_rolls_back_uncommitted_write'}
    check(result['status'] == 'passed' and set(result['checks']) == expected, 'all_six_resilience_scenarios')
    check(result['runner_sha256'] == ctl.sha(Path(__file__).with_name('linux-resilience-scenarios.py'))
          and result['controller_sha256'] == ctl.sha(ctl.__file__), 'executed_controller_and_runner_unchanged')
    final = read(resilience, 'resilience-final-state.json')
    check(read(resilience, 'before-integrated-restart.json') == read(resilience, 'after-integrated-restart.json')
          and read(resilience, 'after-integrated-restart.json')['data'] == final, 'integrated_paired_restart_preserves_state')
    check(final['42']['fse_documents']['count'] == 23 and final['43']['fse_documents']['count'] == 20,
          'forty_three_committed_synthetic_documents')
    for service in ('app', 'mysql'):
        state = only(resilience, 'killed-' + service + '-*.json')
        check(state['ExitCode'] == 137 and not state['OOMKilled'], 'deliberate_not_oom_' + service)
    for path in (browser, resilience):
        stopped = read(path, 'stopped.json')
        for container in stopped['containers']:
            check(not container['state']['Running'] and not container['state']['OOMKilled'],
                  path.name + '_' + container['service'] + '_stopped_without_oom')
            check(not container['host_config']['PortBindings'], path.name + '_' + container['service'] + '_no_public_ports')
            if container['service'] == 'app':
                check(container['image'] == ctl.IMAGE and container['user'] == '33:33', path.name + '_pinned_nonroot_app')
                check({'FSE2_ALLOW_PRODUCTION=false', 'FSE2_ALLOW_TOSCANA_STAGE=false'}.issubset(container['flags']),
                      path.name + '_real_dispatch_disabled')
    check(not audit['official_accreditation_evidence'] and not result['official_accreditation_evidence']
          and load['external_gateway_calls'] == result['external_gateway_calls'] == 0, 'no_official_or_external_claim')
    return {'mode': 'OFFLINE_EVIDENCE_CORROBORATION', 'status': 'passed', 'checks': checks,
            'browser_actions_reexecuted': False, 'official_accreditation_evidence': False}


if __name__ == '__main__':
    parser = argparse.ArgumentParser()
    parser.add_argument('browser_id')
    parser.add_argument('resilience_id')
    parser.add_argument('--save', action='store_true')
    args = parser.parse_args()
    result = verify(args.browser_id, args.resilience_id)
    result['verifier_sha256'] = ctl.sha(__file__)
    if args.save:
        path, _ = ctl.instance(args.resilience_id)
        ctl.save(path / 'evidence' / 'verification-final.json', result)
    print(json.dumps(result, indent=2))

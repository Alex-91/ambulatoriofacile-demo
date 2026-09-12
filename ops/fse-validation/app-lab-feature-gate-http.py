"""Prove revocation on real HTTP sessions and no clinical writes in the marked lab."""
import json, os, subprocess, uuid
from pathlib import Path
from importlib.util import spec_from_file_location, module_from_spec
spec=spec_from_file_location('lab_http',Path(__file__).with_name('app-lab-http.py'))
http=module_from_spec(spec); spec.loader.exec_module(http)
lab=Path(os.environ['FSE_LAB_ROOT']).resolve(strict=True)
assert lab.parent==(http.REPO/'rest/writable/fse-app-labs').resolve() and len(lab.name)==32
config=json.loads((lab/'lab.json').read_text(encoding='utf-8-sig'))
assert config['mode']=='FSE_SYNTHETIC_APP_LAB'
session=http.LabSession(lab.name)
base='/cartella-clinica/pazienti/100'
report={'mode':'SYNTHETIC_CLINICAL_ENTITLEMENT_HTTP','status':'incomplete','checks':[]}
def action(name):
    return subprocess.check_output(['php',str(Path(__file__).with_name('app-lab-feature-gate.php')),name],cwd=http.REPO)
def check(value,label):
    if not value: raise RuntimeError(label)
    report['checks'].append(label)
try:
    session.login('a',config['login_password'])
    for method,path in [('GET',base),('GET',base+'/referti-fse/1'),('GET',base+'/allegati/'+'a'*32),
                        *[('POST',base+'/'+suffix) for suffix in ('salva','consensi','modelli','allegati','documenti/1/finalizza','documenti/1/firma')]]:
        action('enable')
        page=session.request('GET',base)
        check(page.status_code==200,'enabled_chart_access')
        token=http.csrf(page.text)
        action('disable')
        before=action('snapshot')
        response=session.request(method,path,**({'data':{'csrf_test_name':token}} if method=='POST' else {}))
        check(response.status_code==400 and 'Modulo Cartella clinica non attivo' in response.text,'disabled_'+method+'_'+path)
        check(before==action('snapshot'),'denied_request_no_clinical_writes')
    action('enable')
    check(session.request('GET',base).status_code==200,'reenabled_same_session')
    report['status']='passed'
finally:
    action('enable')
    target=lab/('feature-gate-http-'+uuid.uuid4().hex+'.json')
    target.write_text(json.dumps(report,indent=2),encoding='utf-8')
    print(json.dumps({'status':report['status'],'checks':len(report['checks']),'report':str(target)}))

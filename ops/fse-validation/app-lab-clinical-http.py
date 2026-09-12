"""HTTP integration of real clinical controllers, sessions, MySQL, encryption and signature worker."""
import asyncio, hashlib, io, json, logging, os, re, shutil, uuid
from pathlib import Path
from lxml import html
from importlib.util import spec_from_file_location, module_from_spec
from test_clinical_signature import ClinicalSignatures

spec=spec_from_file_location('lab_http',Path(__file__).with_name('app-lab-http.py'))
http=module_from_spec(spec);spec.loader.exec_module(http)
repo=Path(__file__).resolve().parents[2]
lab=Path(os.environ['FSE_LAB_ROOT']).resolve(strict=True)
if lab.parent != (repo/'rest/writable/fse-app-labs').resolve() or not re.fullmatch('[a-f0-9]{32}',lab.name): raise RuntimeError('LAB_BOUNDARY')
config=json.loads((lab/'lab.json').read_text(encoding='utf-8-sig'))
if config['mode']!='FSE_SYNTHETIC_APP_LAB' or not (lab/'clinical-seeded.json').is_file(): raise RuntimeError('CLINICAL_SEED_REQUIRED')
report={'mode':'SYNTHETIC_CLINICAL_HTTP','status':'incomplete','checks':[],'external_services':False,'qualified_signature':'not_assessed'}
run=lab/('clinical-http-'+uuid.uuid4().hex);run.mkdir()
base='/cartella-clinica/pazienti/100'
session=http.LabSession(lab.name);other=http.LabSession(lab.name)
fixture=ClinicalSignatures('test_pades_with_prepared_original')
logging.disable(logging.CRITICAL)

def check(value,code):
    if not value: raise RuntimeError(code)
    report['checks'].append(code)

def page():
    response=session.request('GET',base);check(response.status_code==200,'CHART_LOAD')
    return response

def post(path,data=None,files=None,success=True):
    token=http.csrf(page().text)
    result=session.request('POST',path,data={'csrf_test_name':token,**(data or {})},files=files)
    check(result.status_code==303 or result.status_code==302 if success else result.status_code==400,'POST_ACCEPTED' if success else 'POST_REJECTED')
    return result

def article(title):
    tree=html.fromstring(page().content)
    result=tree.xpath('//article[h3/text()=$title]',title=title)
    check(len(result)==1,'DOCUMENT_VISIBLE')
    return result[0]

try:
    session.login('a',config['login_password']);other.login('b',config['login_password'])
    check('no-store' in page().headers.get('Cache-Control',''),'NO_STORE')
    no_csrf=session.request('POST',base+'/salva',data={'title':'Rejected'})
    check(no_csrf.status_code in (302,303,403),'CSRF_REQUIRED')
    check('Rejected' not in page().text,'CSRF_NO_WRITE')
    settings,signer=fixture.signer_fixture()
    settings_path=lab/'signing/settings.json'
    if settings_path.exists(): raise RuntimeError('NEVER_OVERWRITE_SIGNING_CONFIG')
    for field in ('trust_roots','crls'):
        source=Path(settings[field][0]);target=lab/'signing'/source.name;shutil.copyfile(source,target);settings[field]=[str(target)]
    settings_path.write_text(json.dumps(settings),encoding='utf-8')
    originals=[];signed_paths=[]
    for format in ('pades','cades'):
        title='HTTP synthetic '+format+' '+run.name[-8:]
        post(base+'/salva',{'kind':'report','title':title,'body':'Synthetic clinical body','occurred_at':'2026-09-12T10:00','appointment_id':'1'})
        row=article(title);form=row.xpath('.//form[contains(@action,"/finalizza")]')[0]
        revision=form.xpath('.//input[@name="revision"]/@value')[0]
        endpoint=form.get('action');entry_id=int(re.search(r'/documenti/(\d+)/',endpoint).group(1))
        post(endpoint,{'revision':revision});row=article(title)
        original_url=row.xpath('.//a[contains(text(),"originale")]/@href')[0]
        original=session.request('GET',original_url).content
        check(original.startswith(b'%PDF-'),'ORIGINAL_PDF')
        (run/(format+'-original.pdf')).write_bytes(original);originals.append(original_url)
        fixture.pdf=original
        signed=fixture.sign(signer) if format=='pades' else fixture.cades(signer)
        post(base+f'/documenti/{entry_id}/firma',{'format':format},{'document':('invalid.pdf',signed+b'INVALID','application/octet-stream')},False)
        check('Definitivo' in html.tostring(article(title)).decode(),'INVALID_SIGNATURE_NO_STATE_CHANGE')
        post(base+f'/documenti/{entry_id}/firma',{'format':format},{'document':('signed.pdf' if format=='pades' else 'signed.pdf.p7m',signed,'application/octet-stream')})
        row=article(title);signed_url=row.xpath('.//a[contains(text(),"documento firmato")]/@href')[0]
        download=session.request('GET',signed_url)
        check(download.content==signed,'SIGNED_DOWNLOAD_EXACT')
        check(session.request('GET',original_url).content==original,'ORIGINAL_PRESERVED')
        check(other.request('GET',signed_url).status_code==400,'OTHER_TENANT_DOWNLOAD_DENIED')
        signed_paths.append(signed_url)
        (run/(format+'-signed'+('.pdf' if format=='pades' else '.p7m'))).write_bytes(signed)
    post(base+'/modelli',{'kind':'dossier','title':'Dossier sintetico','version':run.name[-8:],'content':'Testo esclusivamente sintetico di collaudo.'})
    tree=html.fromstring(page().content);template=tree.xpath('//input[@name="template_id"]/@value')[-1]
    post(base+'/allegati',{'category':'consent'},{'document':('synthetic-consent.pdf',original,'application/pdf')})
    tree=html.fromstring(page().content);proof=tree.xpath('//select[@name="evidence_object_id"]/option[text()="synthetic-consent.pdf"]/@value')[0]
    consent={'template_id':template,'previous_id':'0','decision':'granted','signer_name':'Persona Sintetica','signer_capacity':'Paziente','evidence_object_id':proof}
    post(base+'/consensi',consent);check('Condivisione del dossier attiva' in page().text,'CONSENT_GRANT')
    tree=html.fromstring(page().content);head=tree.xpath('//input[@name="previous_id"]/@value')[-1]
    post(base+'/consensi',{**consent,'previous_id':head,'decision':'revoked'})
    check('Condivisione del dossier non attiva' in page().text,'CONSENT_REVOKE')
    post(base+'/consensi',consent,success=False)
    check('HTTP synthetic' not in other.request('GET',base).text,'OTHER_TENANT_CONTENT_ISOLATED')
    anonymous=http.LabSession(lab.name);check(anonymous.request('GET',signed_paths[0]).status_code in (302,303,400,401,403),'ANONYMOUS_DOWNLOAD_DENIED')
    report['status']='passed';report['signed_files']=signed_paths;report['originals']=originals
except Exception as e:
    report['failure']=str(e) if isinstance(e,RuntimeError) else type(e).__name__
    raise
finally:
    fixture.doCleanups()
    (run/'report.json').write_text(json.dumps(report,indent=2),encoding='utf-8')
    print(json.dumps({'status':report['status'],'report':str(run/'report.json'),'checks':len(report['checks'])}))

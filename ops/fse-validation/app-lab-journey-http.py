"""Connected synthetic patient journey through real HTTP controllers. No external delivery."""
import json, os, re, subprocess, shutil, uuid, hashlib, logging
from pathlib import Path
from importlib.util import spec_from_file_location,module_from_spec
from lxml import html
from pypdf import PdfReader
import io
from urllib.parse import urlsplit
from test_clinical_signature import ClinicalSignatures

spec=spec_from_file_location('lab_http',Path(__file__).with_name('app-lab-http.py'))
http=module_from_spec(spec);spec.loader.exec_module(http)
repo=Path(__file__).resolve().parents[2];lab=Path(os.environ['FSE_LAB_ROOT']).resolve(strict=True)
if lab.parent!=(repo/'rest/writable/fse-app-labs').resolve() or not re.fullmatch('[a-f0-9]{32}',lab.name):raise RuntimeError('LAB_BOUNDARY')
cfg=json.loads((lab/'lab.json').read_text(encoding='utf-8-sig'));marker=json.loads((lab/'journey-seeded.json').read_text())
if cfg['mode']!='FSE_SYNTHETIC_APP_LAB' or marker['mode']!='SYNTHETIC_PATIENT_JOURNEY' or marker['external_delivery'] is not False:raise RuntimeError('JOURNEY_BOUNDARY')
run=lab/('journey-http-'+uuid.uuid4().hex);run.mkdir();report={'status':'incomplete','mode':'SYNTHETIC_PATIENT_JOURNEY_HTTP','checks':[],'external_delivery':False}
a=http.LabSession(lab.name);b=http.LabSession(lab.name);base='/cartella-clinica/pazienti/101'
cache=lab/'journey-artifacts';cache.mkdir(exist_ok=True)
fixture=ClinicalSignatures('test_pades_with_prepared_original');logging.disable(logging.CRITICAL)
def check(ok,name):
    if not ok:raise RuntimeError(name)
    report['checks'].append(name)
def cli(*args):
    env={k:v for k,v in os.environ.items() if k not in ('FSE_DEPENDENCY_LAB','FSE_FRAMEWORK_LAB')}
    p=subprocess.run(['php',str(Path(__file__).with_name('app-lab-journey.php')),*args],cwd=repo,env={**env,'XDEBUG_MODE':'off'},capture_output=True,timeout=60)
    if p.returncode:
        (run/'cli-error.txt').write_bytes(p.stdout+p.stderr);raise RuntimeError('JOURNEY_CLI_FAILED')
    return json.loads(p.stdout)
def snapshot():return cli('snapshot')
def get(path,session=a):
    r=session.request('GET',path);check(r.status_code==200,'GET_'+path);return r
def post(path,data,files=None,form=base):
    token=http.csrf(get(form).text);r=a.request('POST',path,data={**data,'csrf_test_name':token},files=files)
    (run/'last-response.html').write_bytes(r.content)
    check(r.status_code in (302,303),'POST_STATUS_'+path);return r
def api(path,data):
    r=a.request('POST',path,data=data,headers={'X-Requested-With':'XMLHttpRequest'})
    (run/'last-response.html').write_bytes(r.content)
    result=r.json();check(r.status_code==200 and result.get('status') is True,'API_'+path+':'+str(result.get('message','')));return result
try:
    a.login('a',cfg['login_password']);b.login('b',cfg['login_password'])
    before=snapshot();check(any(int(x['id_client'] or 0)==101 for x in before['42']['dap02_clients']),'BROWSER_CREATED_PATIENT_PRESENT')
    if os.environ.get('FSE_JOURNEY_RECHECK_PATIENT')=='1':
        api('/agenda/salva-paziente-gestione',{'id_paziente':'101','id_dot':'11','nome':'LUIGI','cognome':'VERDI','denominazione':'Verdi Luigi','cod_fis':'VRDLGU70A01H501O','note_cliente':'PAZIENTE SINTETICO - verifica conservazione identita'})
        identity=cli('patient-identity');check(identity['patient_name']=='VERDI LUIGI','PATIENT_IDENTITY_AFTER_UPDATE')
    check(any(int(x['id_client'] or 0)==101 and int(x['id_dot'] or 0)==1 for x in before['42']['dap09_client_doctor']),'PATIENT_LINK_USES_STAFF_ID')
    appointments=[x for x in before['42']['dap12_agenda_appuntamenti'] if int(x['id_client'] or 0)==101 and int(x['id_slot'] or 0)==201 and x['stato']!='ANNULLATO']
    if appointments:appointment=int(appointments[0]['id_appuntamento'])
    else:
        lock=api('/agenda/lock-slot',{'id_slot':'201'})
        booking=api('/agenda/salva-appuntamento',{'id_slot':'201','id_dot':'11','id_paziente':'101','nome':'Luigi','cognome':'Verdi','cod_fis':'VRDLGU70A01H501O','motivo_visita':'Visita sintetica integrata','token_lock':lock['token_lock']})
        appointment=int(booking['id_appuntamento'])
    report['appointment_id']=appointment
    saved=next(x for x in snapshot()['42']['dap12_agenda_appuntamenti'] if int(x['id_appuntamento'] or 0)==appointment)
    check(int(saved['id_client'] or 0)==101 and int(saved['id_dot'] or 0)==11,'APPOINTMENT_PATIENT_AND_LEGACY_DOCTOR_LINK')
    chart=get(base);tree=html.fromstring(chart.content)
    check(bool(tree.xpath('//select[@name="appointment_id"]/option[@value=$id]',id=str(appointment))),'APPOINTMENT_VISIBLE_IN_CHART')
    title='Referto sintetico visita integrata'
    # Consent precedes the clinical report. This PDF is a synthetic scanned-proof fixture.
    if not snapshot()['42']['clinical_consents']:
        fixture.setUp();proof_pdf=fixture.pdf
        post(base+'/modelli',{'kind':'dossier','title':'Consenso dossier - collaudo','version':'journey-1','content':'Modello sintetico per verificare il flusso, senza valore per uso clinico.'})
        template=html.fromstring(get(base).content).xpath('//input[@name="template_id"]/@value')[-1]
        post(base+'/allegati',{'category':'consent'},{'document':('consenso-sintetico.pdf',proof_pdf,'application/pdf')})
        proof=html.fromstring(get(base).content).xpath('//select[@name="evidence_object_id"]/option[text()="consenso-sintetico.pdf"]/@value')[0]
        post(base+'/consensi',{'template_id':template,'previous_id':'0','decision':'granted','signer_name':'Luigi Verdi - sintetico','signer_capacity':'Paziente','evidence_object_id':proof})
    check('Condivisione del dossier attiva' in get(base).text,'CONSENT_GRANTED_WITH_PROOF')
    def article():
        rows=html.fromstring(get(base).content).xpath('//article[h3/text()=$title]',title=title)
        check(len(rows)==1,'REPORT_VISIBLE');return rows[0]
    entries=[x for x in snapshot()['42']['clinical_entries'] if int(x['id_client'])==101]
    if not entries:
        post(base+'/salva',{'kind':'report','title':title,'body':'Visita di collaudo su paziente sintetico. Nessun dato clinico reale.','occurred_at':'2026-09-14T09:00','appointment_id':str(appointment)})
    row=article();forms=row.xpath('.//form[contains(@action,"/finalizza")]')
    if forms:post(forms[0].get('action'),{'revision':forms[0].xpath('.//input[@name="revision"]/@value')[0]})
    entry=next(x for x in snapshot()['42']['clinical_entries'] if int(x['id_client'])==101)
    report['entry_id']=int(entry['id']);check(int(entry['appointment_id'])==appointment,'REPORT_LINKED_TO_VISIT')
    original_url=article().xpath('.//a[contains(text(),"originale")]/@href')[0]
    original=get(original_url).content;check(original.startswith(b'%PDF-'),'REPORT_ORIGINAL_PDF')
    (cache/'report-original.pdf').write_bytes(original)
    settings_path=lab/'signing/settings.json';signed_path=cache/'report-signed.pdf'
    if not signed_path.exists():
        check(not settings_path.exists(),'SIGNING_TRUST_NOT_OVERWRITTEN')
        settings,signer=fixture.signer_fixture();fixture.pdf=original;signed=fixture.sign(signer)
        signed_path.write_bytes(signed)
        for field in ('trust_roots','crls'):
            source=Path(settings[field][0]);target=lab/'signing'/source.name;shutil.copyfile(source,target);settings[field]=[str(target)]
        settings_path.write_text(json.dumps(settings),encoding='utf-8')
    signed=signed_path.read_bytes()
    if entry['state']!='signed':post(base+f'/documenti/{entry["id"]}/firma',{'format':'pades'},{'document':('report-signed.pdf',signed,'application/pdf')})
    signed_url=article().xpath('.//a[contains(text(),"documento firmato")]/@href')[0]
    check(get(signed_url).content==signed,'SIGNED_DOCUMENT_PRESERVED')
    check(get(original_url).content==original,'ORIGINAL_UNCHANGED_AFTER_SIGNATURE')
    check(cli('patient-identity')['patient_name']=='VERDI LUIGI','PATIENT_IDENTITY_CONFIRMED')
    # Exercise correction, preserving the first signed snapshot and testing the second format.
    correction_title='Correzione referto sintetico visita integrata'
    def correction_article():
        return html.fromstring(get(base).content).xpath('//article[h3/text()=$title]',title=correction_title)
    if not correction_article():
        post(base+'/salva',{'kind':'report','title':correction_title,'body':'Correzione sintetica: intestazione anagrafica verificata. Nessun dato clinico reale.','occurred_at':'2026-09-14T09:00','appointment_id':str(appointment),'previous_entry_id':str(entry['id'])})
    row=correction_article()[0];forms=row.xpath('.//form[contains(@action,"/finalizza")]')
    if forms:post(forms[0].get('action'),{'revision':forms[0].xpath('.//input[@name="revision"]/@value')[0]})
    correction=next(x for x in snapshot()['42']['clinical_entries'] if int(x.get('previous_entry_id') or 0)==int(entry['id']))
    corrected_url=correction_article()[0].xpath('.//a[contains(text(),"originale")]/@href')[0];corrected=get(corrected_url).content
    pdf_text='\n'.join(page.extract_text() for page in PdfReader(io.BytesIO(corrected)).pages)
    check('VERDI LUIGI' in pdf_text and 'VRDLGU70A01H501O' in pdf_text,'CORRECTED_PDF_PATIENT_NAME_AND_TAX_CODE')
    (cache/'correction-original.pdf').write_bytes(corrected);cades_path=cache/'correction-signed.p7m'
    if not cades_path.exists():
        settings,signer=fixture.signer_fixture();fixture.pdf=corrected;cades_path.write_bytes(fixture.cades(signer))
        current=json.loads(settings_path.read_text());(cache/'initial-signing-settings.json').write_text(json.dumps(current),encoding='utf-8')
        # Add a second synthetic CA without replacing the first CA or its revocation evidence.
        for field in ('trust_roots','crls'):
            source=Path(settings[field][0]);target=lab/'signing'/('correction-'+source.name);shutil.copyfile(source,target);current[field].append(str(target))
        settings_path.write_text(json.dumps(current),encoding='utf-8')
    cades=cades_path.read_bytes()
    if correction['state']!='signed':post(base+f'/documenti/{correction["id"]}/firma',{'format':'cades'},{'document':('correction-signed.p7m',cades,'application/octet-stream')})
    correction_signed_url=correction_article()[0].xpath('.//a[contains(text(),"documento firmato")]/@href')[0]
    check(get(correction_signed_url).content==cades,'CADES_CORRECTION_DOWNLOAD_EXACT')
    check(get(signed_url).content==signed and get(original_url).content==original,'PREVIOUS_SIGNED_REVISION_RETAINED')
    report['correction_entry_id']=int(correction['id'])
    check(b.request('GET',signed_url).status_code==400,'OTHER_TENANT_REPORT_DENIED')
    anon=http.LabSession(lab.name);check(anon.request('GET',signed_url).status_code in (302,303,400,401,403),'ANONYMOUS_REPORT_DENIED')
    billing='/admin/fatturazione-documenti';number='JOURNEY-'+lab.name[:12]
    invoices=[x for x in snapshot()['42']['billing_documents'] if x['document_number']==number]
    if not invoices:
        prefill=a.request('GET',f'/agenda/fatturazione-da-appuntamento/{appointment}')
        next_path=prefill.headers.get('Location','');check(prefill.status_code in (302,303) and '/nuovo?prefill_key=' in next_path,'INVOICE_STARTED_FROM_APPOINTMENT')
        form=get(next_path);tree=html.fromstring(form.content);(run/'billing-prefill.html').write_bytes(form.content)
        check(tree.xpath('//input[@name="id_client"]/@value')==['101'],'INVOICE_PREFILLED_PATIENT')
        check(tree.xpath('//input[@name="patient_tax_code"]/@value')==['VRDLGU70A01H501O'],'INVOICE_PREFILLED_TAX_CODE')
        description=tree.xpath('//input[@name="item_description[]"]/@value')
        check(any('Visita sintetica integrata' in x for x in description),'INVOICE_PREFILLED_VISIT')
        payload={'document_number':number,'document_type':'invoice','issue_date':'2026-09-14','id_client':'101','patient_name':'VERDI LUIGI','patient_tax_code':'VRDLGU70A01H501O','payment_method':'bank_transfer','item_description[]':description,'item_qty[]':['1'],'item_unit_amount[]':['100.00'],'stamp_duty_amount':'2','vat_rate':'0','vat_nature':'N4','ts_sync_enabled':'1','ts_expense_type_code':'SP','notes':'COLLAUDO SINTETICO SENZA VALORE FISCALE','save_mode':'final'}
        post(billing+'/save',payload,form=next_path)
        invoices=[x for x in snapshot()['42']['billing_documents'] if x['document_number']==number]
    check(len(invoices)==1,'ONE_INVOICE_CREATED');invoice=invoices[0];invoice_id=int(invoice['id_billing_document']);report['invoice_id']=invoice_id
    check(invoice['local_state']=='issued' and int(invoice['id_client'])==101 and float(invoice['amount_total'])==102,'INVOICE_ISSUED_CORRECT_PATIENT_TOTAL')
    edit=f'{billing}/modifica/{invoice_id}'
    post(f'{billing}/pagamento/{invoice_id}',{'payment_status':'paid','payment_date':'2026-09-14'},form=edit)
    paid=next(x for x in snapshot()['42']['billing_documents'] if int(x['id_billing_document'])==invoice_id)
    check(paid['payment_status']=='paid' and paid['payment_date']=='2026-09-14','PAYMENT_PERSISTED')
    pdf=get(f'{billing}/pdf/{invoice_id}');check(pdf.content.startswith(b'%PDF-'),'INVOICE_PDF_GENERATED');(cache/'invoice.pdf').write_bytes(pdf.content)
    prepared=cli('prepare-ts',str(invoice_id));(run/'ts-preparation.json').write_text(json.dumps(prepared,indent=2),encoding='utf-8')
    check(prepared['status']=='ready' and prepared['validation']['valid'] and prepared['external_delivery'] is False,'TS_PREPARED_LOCALLY_WITH_VALID_DATA')
    after=snapshot();ts=next(x for x in after['42']['ts_documents'] if int(x['id_ts_document'])==int(prepared['ts_document_id']))
    check(ts['local_state']=='ready' and not ts.get('ts_protocol') and not ts.get('ts_sent_at') and ts['source_type']=='billing' and int(ts['source_ref_id'])==invoice_id,'TS_LINKED_TO_INVOICE_AND_NOT_SENT')
    check(ts['vat_rate'] is None and ts['vat_nature']=='N4','TS_EXEMPTION_WITHOUT_CONFLICTING_ZERO_RATE')
    check(after['43']==before['43'],'OTHER_TENANT_UNCHANGED')
    check(b.request('GET',f'{billing}/pdf/{invoice_id}').status_code in (302,303,403,404),'OTHER_TENANT_INVOICE_DENIED')
    chart=get(base);check(title in chart.text and 'Visita sintetica integrata' in chart.text,'CLINICAL_HISTORY_RETAINS_VISIT_AND_REPORT')
    requests=sorted(a.dependency_requests | b.dependency_requests | anon.dependency_requests)
    for request_id in requests:
        proof=json.loads((lab/'writable/dependency-provenance'/(request_id+'.json')).read_text())
        check(proof.get('passed') and proof.get('active_dependencies_unchanged') and proof.get('framework_version')=='4.7.4' and proof.get('variant')=='candidate','HTTP_PROVENANCE_'+request_id)
    check(bool(requests),'HTTP_CANDIDATE_PROVENANCE_PRESENT')
    report.update(patient_id=101,qualified_signature='not_assessed',signature_fixture='synthetic_pades_and_cades',artifacts=str(cache),resumed=True)
    report['status']='passed'
except Exception as e:
    report['failure']=str(e) if isinstance(e,RuntimeError) else type(e).__name__
    raise
finally:
    fixture.doCleanups()
    (run/'report.json').write_text(json.dumps(report,indent=2),encoding='utf-8');print(json.dumps({'status':report['status'],'report':str(run/'report.json'),'failure':report.get('failure'),'checks':len(report['checks'])}))

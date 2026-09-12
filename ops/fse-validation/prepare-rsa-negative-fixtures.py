"""Test-only fault injection AFTER the real builder. Never disables an application check."""
import base64
import hashlib
import json
import subprocess
from datetime import datetime, timezone
from pathlib import Path
from lxml import etree
import validator as v

ROOT = Path(__file__).resolve().parents[2]
PRIVATE = ROOT / 'ops/.local/fse-accreditamento'
REVISION = 'd937255fd7e9c079c5641c537da17fe98a2f2259'
SECTION = 'h:component/h:structuredBody/h:component/h:section'
# Reviewed against the marked cells in the original RSA-KO workbook; NOT official executions.
CASES = [
    ('cda-lower-cf',152,6,'h:recordTarget/h:patientRole/h:id','extension','lower'),
    ('cda-no-city',154,8,'h:recordTarget/h:patientRole/h:addr/h:city',None,None),
    ('cda-no-given',155,9,'h:recordTarget/h:patientRole/h:patient/h:name/h:given',None,None),
    ('cda-gender',156,10,'h:recordTarget/h:patientRole/h:patient/h:administrativeGenderCode','code','NB'),
    ('cda-no-act-code',160,14,SECTION+'[h:code/@code="62387-6"]/h:entry/h:act/h:code',None,None),
    ('cda-no-report',161,15,SECTION+'[h:code/@code="47045-0"]/..',None,None),
    ('cda-no-reason-text',162,16,SECTION+'[h:code/@code="29299-5"]/h:text',None,None),
    ('cda-no-service-entry',163,17,SECTION+'[h:code/@code="62387-6"]/h:entry',None,None),
    ('cda-signature-code',169,23,'h:legalAuthenticator/h:signatureCode','code','X'),
    ('cda-no-confidentiality',468,27,'h:confidentialityCode',None,None),
]


def main():
    settings=json.loads((ROOT/'rest/writable/fse-validator-settings.json').read_text(encoding='utf-8-sig'))
    markers=json.loads((PRIVATE/'rsa-ko-markers.json').read_text(encoding='utf-8'))
    original=PRIVATE/'official-sources'/REVISION/'RSA-KO.xlsx'
    v.require(hashlib.sha256(original.read_bytes()).hexdigest()==markers['source_sha256'],'SOURCE_HASH_MISMATCH')
    cda=subprocess.check_output(['php',str(Path(__file__).with_name('gateway-app-cda.php')),'--with-sections'],cwd=ROOT)
    baseline=v.validate_cda(cda,settings)
    v.require(baseline['ok'],'POSITIVE_BASELINE_INVALID')
    digest=hashlib.sha256(cda).hexdigest()
    output=PRIVATE/'negative-fixtures'
    output.mkdir(parents=True,exist_ok=True)
    (output/f'baseline-{digest[:16]}.xml').write_bytes(cda)
    base=v.xml_document(cda)
    document={'author_cf':base.find('h:author/h:assignedAuthor/h:id',v.NS).get('extension'),
        'patient_cf':base.find('h:recordTarget/h:patientRole/h:id',v.NS).get('extension'),
        'patient_consent':True,'loinc_code':'11488-4'}
    report={'mode':'RSA_FAULT_INJECTION_PREPARATORY_ONLY','generated_at':datetime.now(timezone.utc).isoformat(),
        'official_accreditation_evidence':False,'source_revision':REVISION,'source_url':markers['source_url'],
        'source_workbook_sha256':markers['source_sha256'],'baseline_cda_sha256':digest,'baseline_validation':baseline,
        'network_attempted':False,'cases':{}}
    control_path=output/f'cda-control-{digest[:16]}.pdf'
    if control_path.exists():
        control_pdf=control_path.read_bytes(); v.pdf_attachment(control_pdf,cda); v.validate_pdfa(control_pdf,settings)
    else:
        control_pdf=base64.b64decode(v.build_pdf(cda,settings)['pdf']); control_path.write_bytes(control_pdf)
    report['cases']['cda-control']={'pdf_file':control_path.name,'pdf_sha256':hashlib.sha256(control_pdf).hexdigest(),
        'cda_sha256':digest,'source_url':markers['source_url'],'document':document,'local_cda_validation':baseline,
        'pdfa':'3b','fixture_kind':'APP_GENERATED_SYNTHETIC_POSITIVE_CONTROL','not_an_official_case':True}
    for name,checklist,case,path,attribute,value in CASES:
        root=v.xml_document(cda)
        nodes=root.xpath(path,namespaces=v.NS)
        v.require(len(nodes)==1,'FAULT_TARGET_NOT_UNIQUE')
        node=nodes[0]
        if attribute:
            v.require(attribute in node.attrib,'FAULT_ATTRIBUTE_MISSING')
            node.set(attribute,node.get(attribute).lower() if value=='lower' else value)
        else:
            node.getparent().remove(node)
        mutated=etree.tostring(root,xml_declaration=True,encoding='UTF-8')
        v.require(mutated!=cda,'FAULT_NOT_APPLIED')
        local=v.validate_cda(mutated,settings)
        fault_hash=hashlib.sha256(mutated).hexdigest()
        stem=f'{name}-{fault_hash[:16]}'
        (output/(stem+'.xml')).write_bytes(mutated)
        entry={'checklist_id_reference':checklist,'rsa_case_reference':case,'fixture_kind':'APP_OUTPUT_MUTATED_BY_TEST_HARNESS',
            'mutation_xpath':path,'mutation_attribute':attribute,'mutation_value':value,'cda_sha256':fault_hash,
            'xml_file':stem+'.xml','source_url':markers['source_url'],'document':document,
            'local_cda_validation':local,'local_detection':'REJECTED_LOCALLY' if not local['ok'] else 'NOT_DETECTED_LOCALLY',
            'not_an_official_case':True}
        # Only two small PDF fixtures are enabled for remote negative diagnostics.
        if name in ['cda-no-given','cda-gender']:
            pdf_path=output/(stem+'.pdf')
            if pdf_path.exists():
                pdf=pdf_path.read_bytes(); v.pdf_attachment(pdf,mutated); v.validate_pdfa(pdf,settings)
            else:
                pdf=base64.b64decode(v.build_pdf(mutated,settings)['pdf']); pdf_path.write_bytes(pdf)
            entry.update(pdf_file=pdf_path.name,pdf_sha256=hashlib.sha256(pdf).hexdigest(),pdfa='3b',clinical_signature='NOT_APPLIED')
        report['cases'][name]=entry
        print(f'{name}: {entry["local_detection"]} ({local.get("code")})',flush=True)
    report['source_sha256']={p:hashlib.sha256((ROOT/p).read_bytes()).hexdigest() for p in [
        'ops/fse-validation/prepare-rsa-negative-fixtures.py','ops/fse-validation/gateway-app-cda.php',
        'rest/app/Services/FseCdaRsaBuilderService.php','ops/fse-validation/validator.py']}
    report_path=output/('matrix-'+datetime.now(timezone.utc).strftime('%Y%m%d-%H%M%S')+'.json')
    report_path.write_text(json.dumps(report,indent=2),encoding='utf-8')
    (output/'manifest.json').write_text(json.dumps(report,indent=2),encoding='utf-8')
    print('Preparatory report:',report_path)


if __name__=='__main__':
    main()

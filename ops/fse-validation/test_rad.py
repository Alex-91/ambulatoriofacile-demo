"""Synthetic RAD/RSA regression against pinned official XSD and Schematron."""
import copy
import base64
import io
import json
import os
from pathlib import Path
import subprocess
import unittest
import validator as v
from test_validator import synthetic_cda

ROOT=Path(__file__).resolve().parents[2]

def rad_cda():
    code="require 'rest/system/Config/BaseConfig.php'; require 'rest/app/Config/Fse2.php'; require 'rest/app/Services/FseCdaRsaBuilderService.php'; require 'rest/app/Services/FseCdaRadBuilderService.php'; $d=require 'rest/tests/_support/fse_synthetic.php';"
    code+="$d += ['patient_local_id'=>'SYNTHETIC-100','patient_local_oid'=>'1.2.3.10','order_id'=>'SYNTHETIC-ORDER','order_oid'=>'1.2.3.11','exam_code'=>'36643-5','exam_code_system'=>'2.16.840.1.113883.6.1']; echo (new App\\Services\\FseCdaRadBuilderService())->build($d);"
    return subprocess.check_output(['php','-d','xdebug.mode=off','-r',code],cwd=ROOT)

class RadiologyTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.settings=json.loads(Path(os.environ['FSE2_VALIDATOR_SETTINGS']).read_text(encoding='utf-8-sig'))
        cls.cda=rad_cda()
    def test_generated_rad_passes_official_rules(self):
        result=v.validate_cda(self.cda,self.settings)
        self.assertTrue(result['ok'],result)
    def test_rsa_regression(self):
        result=v.validate_cda(synthetic_cda(),self.settings)
        self.assertTrue(result['ok'],result)
    def test_rad_pdfa_embeds_exact_cda_and_visible_exam(self):
        from pypdf import PdfReader
        pdf=base64.b64decode(v.build_pdf(self.cda,self.settings)['pdf'])
        self.assertEqual('3b',v.validate_pdfa(pdf,self.settings)['pdfa'])
        v.pdf_attachment(pdf,self.cda)
        text='\n'.join(p.extract_text() for p in PdfReader(io.BytesIO(pdf)).pages)
        self.assertIn('Esame eseguito',text)
        self.assertIn('Referto Radiologico',text)
    def test_rad_cannot_be_relabelled_rsa(self):
        wrong=self.cda.replace(b'68604-8',b'11488-4')
        self.assertFalse(v.validate_cda(wrong,self.settings)['ok'])
    def test_missing_order_is_rejected(self):
        root=v.xml_document(self.cda)
        order=root.find('{urn:hl7-org:v3}inFulfillmentOf'); root.remove(order)
        self.assertFalse(v.validate_cda(v.etree.tostring(root),self.settings)['ok'])
    def test_missing_local_patient_identity_is_rejected(self):
        root=v.xml_document(self.cda)
        local=root.xpath("h:recordTarget/h:patientRole/h:id[@root='1.2.3.10']",namespaces=v.NS)[0]
        local.getparent().remove(local)
        self.assertFalse(v.validate_cda(v.etree.tostring(root),self.settings)['ok'])
    def test_mixed_templates_are_rejected(self):
        root=v.xml_document(self.cda)
        t=v.etree.Element('{urn:hl7-org:v3}templateId',root='2.16.840.1.113883.2.9.10.1.9.1',extension='1.1')
        root.insert(2,t)
        with self.assertRaisesRegex(v.InvalidArtifact,'AMBIGUOUS_TEMPLATE'):
            v.validate_cda(v.etree.tostring(root),self.settings)

if __name__=='__main__': unittest.main()

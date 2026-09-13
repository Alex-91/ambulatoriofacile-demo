"""Synthetic end-to-end checks; no environment DB, production, patient data or network."""
import base64
import io
import json
import logging
import os
import subprocess
import tempfile
import unittest
from datetime import datetime, timedelta, timezone
from pathlib import Path

import validator as v
logging.disable(logging.CRITICAL)

ROOT = Path(__file__).resolve().parents[2]
SETTINGS = Path(os.environ.get('FSE2_VALIDATOR_SETTINGS', ROOT / "rest/writable/fse-validator-settings.json"))


def synthetic_cda(with_reason=False):
    code = "require 'rest/system/Config/BaseConfig.php'; require 'rest/app/Config/Fse2.php'; require 'rest/app/Services/FseCdaRsaBuilderService.php'; $d=require 'rest/tests/_support/fse_synthetic.php';"
    if with_reason:
        code += "$d['reason_text']='Quesito sintetico di test';"
    code += "echo (new App\\Services\\FseCdaRsaBuilderService())->build($d);"
    return subprocess.check_output(["php", "-r", code], cwd=ROOT)


def synthetic_revision():
    code = "require 'rest/system/Config/BaseConfig.php'; require 'rest/app/Config/Fse2.php'; require 'rest/app/Services/FseCdaRsaBuilderService.php'; $d = require 'rest/tests/_support/fse_synthetic.php'; $d['previous_document'] = $d + ['version_number'=>1]; $d['version_number']=2; $d['document_unique_id']='AF.TEST.2'; echo (new App\\Services\\FseCdaRsaBuilderService())->build($d);"
    return subprocess.check_output(["php", "-r", code], cwd=ROOT)


class ArtifactTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.settings = json.loads(SETTINGS.read_text(encoding="utf-8"))
        cls.cda = synthetic_cda()
        cls.pdf = base64.b64decode(v.build_pdf(cls.cda, cls.settings)["pdf"])

    def test_official_rsa_rules(self):
        self.assertTrue(v.validate_cda(self.cda, self.settings)["ok"])

    def test_revision_xsd_schematron_pdfa_and_visible_identity(self):
        from pypdf import PdfReader
        cda = synthetic_revision()
        result = v.validate_cda(cda, self.settings)
        self.assertTrue(result['ok'], result)
        pdf = base64.b64decode(v.build_pdf(cda, self.settings)['pdf'])
        text = '\n'.join(p.extract_text() for p in PdfReader(io.BytesIO(pdf)).pages)
        self.assertIn('Versione: 2', text)
        self.assertIn('AF.TEST.2', text)
        self.assertIn('Correzione del documento: AF.TEST.1', text)
        v.pdf_attachment(pdf, cda)

    def test_health_does_not_return_clinical_artifacts(self):
        result = v.execute({'operation':'health', 'settings':str(SETTINGS), 'cda':base64.b64encode(self.cda).decode()})
        self.assertTrue(result['ok'])
        self.assertEqual('passed', result['artifacts'])
        self.assertNotIn('pdf', result)
        self.assertNotIn('cda', result)

    def test_missing_trust_is_not_ready(self):
        self.assertEqual('missing', v.trust_readiness({**self.settings, 'trust_roots':[]}))

    def test_malformed_trust_is_not_ready(self):
        self.assertEqual('invalid', v.trust_readiness({**self.settings, 'trust_roots':['DOES-NOT-EXIST'], 'crls':['DOES-NOT-EXIST']}))

    def test_synthetic_trust_material_is_configured_not_qualified(self):
        settings, _ = self.signer_fixture()
        self.assertEqual('configured', v.trust_readiness(settings))

    def test_pdfa_and_embedded_cda(self):
        pdf = self.pdf
        result = v.validate_pdfa(pdf, self.settings)
        self.assertEqual("3b", result["pdfa"])
        self.assertEqual(v.sha(pdf), result["pdf_sha256"])
        v.pdf_attachment(pdf, self.cda)

    def test_reject_doctype(self):
        with self.assertRaisesRegex(v.InvalidArtifact, "DOCTYPE"):
            v.xml_document(b'<!DOCTYPE ClinicalDocument [<!ENTITY x SYSTEM "file:///sensitive">]><ClinicalDocument xmlns="urn:hl7-org:v3">&x;</ClinicalDocument>')

    def test_schema_invalid_document(self):
        invalid = self.cda.replace(b'<realmCode code="IT"/>', b'<unknown/>')
        self.assertEqual('CDA_XSD', v.validate_cda(invalid, self.settings)['code'])

    def test_semantic_invalid_document(self):
        invalid = self.cda.replace(b'code="11488-4"', b'code="00000-0"')
        result = v.validate_cda(invalid, self.settings)
        self.assertFalse(result['ok'])
        self.assertIn('ERRORE-5', result['issues'])

    def test_rsa_fault_matrix_on_actual_synthetic_builder_output(self):
        import importlib.util
        from lxml import etree
        spec=importlib.util.spec_from_file_location('rsa_faults',Path(__file__).with_name('prepare-rsa-negative-fixtures.py'))
        module=importlib.util.module_from_spec(spec); spec.loader.exec_module(module)
        baseline=synthetic_cda(with_reason=True)
        self.assertTrue(v.validate_cda(baseline,self.settings)['ok'])
        for name,checklist,case,path,attribute,value in module.CASES:
            with self.subTest(case=case,name=name):
                root=v.xml_document(baseline)
                nodes=root.xpath(path,namespaces=v.NS)
                self.assertEqual(1,len(nodes),'Fault target must exist once in the synthetic app fixture')
                node=nodes[0]
                if attribute:
                    node.set(attribute,node.get(attribute).lower() if value=='lower' else value)
                else:
                    node.getparent().remove(node)
                result=v.validate_cda(etree.tostring(root),self.settings)
                # XSD/Schematron is not a complete terminology service. The app builder
                # rejects NB; the real Gateway rejects this post-builder mutation with 400.
                self.assertEqual(name=='cda-gender',result['ok'],result)

    def test_xml_substitution(self):
        with self.assertRaisesRegex(v.InvalidArtifact, 'PDF_CDA_MISMATCH'):
            v.pdf_attachment(self.pdf, self.cda + b' ')

    def test_missing_attachment(self):
        from pypdf import PdfWriter
        w = PdfWriter(); w.add_blank_page(100, 100)
        out = io.BytesIO(); w.write(out)
        with self.assertRaisesRegex(v.InvalidArtifact, 'PDF_CDA_ATTACHMENT_COUNT'):
            v.pdf_attachment(out.getvalue(), self.cda)

    def test_unsigned_or_fake_signature_is_rejected(self):
        with self.assertRaisesRegex(v.InvalidArtifact, 'SIGNATURE_REVISION_POLICY|SIGNATURE_COUNT'):
            settings, signer = self.signer_fixture()
            v.validate_signature(self.pdf + b'\n% /ByteRange /Contents fake', self.pdf, self.cda,
                                 'VRDLGI70A01H501X', settings)

    def signer_fixture(self, revoked=False, expired=False):
        from cryptography import x509
        from cryptography.hazmat.primitives import hashes, serialization
        from cryptography.hazmat.primitives.asymmetric import rsa
        from cryptography.x509.oid import NameOID
        from asn1crypto import x509 as asn_x509, keys
        from pyhanko.sign.signers import SimpleSigner
        from pyhanko_certvalidator.registry import SimpleCertificateStore
        now = datetime.now(timezone.utc)
        ca_key = rsa.generate_private_key(public_exponent=65537, key_size=2048)
        ca_name = x509.Name([x509.NameAttribute(NameOID.COMMON_NAME, 'FSE Synthetic Test CA')])
        ca = (x509.CertificateBuilder().subject_name(ca_name).issuer_name(ca_name).public_key(ca_key.public_key())
              .serial_number(100).not_valid_before(now-timedelta(days=30)).not_valid_after(now+timedelta(days=30))
              .add_extension(x509.BasicConstraints(ca=True, path_length=0), True)
              .add_extension(x509.KeyUsage(False, False, False, False, False, True, True, False, False), True)
              .sign(ca_key, hashes.SHA256()))
        signer_key = rsa.generate_private_key(public_exponent=65537, key_size=2048)
        name = x509.Name([x509.NameAttribute(NameOID.COMMON_NAME, 'Medico sintetico'),
                         x509.NameAttribute(NameOID.SERIAL_NUMBER, 'TINIT-VRDLGI70A01H501X')])
        cert = (x509.CertificateBuilder().subject_name(name).issuer_name(ca_name).public_key(signer_key.public_key())
                .serial_number(101).not_valid_before(now-timedelta(days=10))
                .not_valid_after(now+timedelta(days=-1 if expired else 10))
                .add_extension(x509.BasicConstraints(ca=False, path_length=None), True)
                .add_extension(x509.KeyUsage(True, True, False, False, False, False, False, False, False), True)
                .sign(ca_key, hashes.SHA256()))
        crl = x509.CertificateRevocationListBuilder().issuer_name(ca_name).last_update(now-timedelta(hours=1)).next_update(now+timedelta(hours=1))
        if revoked:
            crl = crl.add_revoked_certificate(x509.RevokedCertificateBuilder().serial_number(101).revocation_date(now-timedelta(hours=2)).build())
        directory = tempfile.TemporaryDirectory(prefix='fse-signature-tests-')
        self.addCleanup(directory.cleanup)
        root = Path(directory.name)
        (root/'root.pem').write_bytes(ca.public_bytes(serialization.Encoding.PEM))
        (root/'revocations.der').write_bytes(crl.sign(ca_key, hashes.SHA256()).public_bytes(serialization.Encoding.DER))
        store = SimpleCertificateStore(); store.register(asn_x509.Certificate.load(ca.public_bytes(serialization.Encoding.DER)))
        signer = SimpleSigner(signing_cert=asn_x509.Certificate.load(cert.public_bytes(serialization.Encoding.DER)),
                              signing_key=keys.PrivateKeyInfo.load(signer_key.private_bytes(serialization.Encoding.DER,
                                  serialization.PrivateFormat.PKCS8, serialization.NoEncryption())), cert_registry=store)
        return {**self.settings, 'trust_roots': [str(root/'root.pem')], 'crls': [str(root/'revocations.der')]}, signer

    def sign(self, signer, change_page=False):
        from pyhanko.pdf_utils.incremental_writer import IncrementalPdfFileWriter
        from pyhanko.sign.signers import PdfSigner, PdfSignatureMetadata
        from pyhanko.sign.fields import SigSeedSubFilter
        from pyhanko.pdf_utils import generic
        result = io.BytesIO()
        writer = IncrementalPdfFileWriter(io.BytesIO(self.pdf))
        if change_page:
            page = writer.root['/Pages']['/Kids'][0].get_object()
            page[generic.pdf_name('/Contents')] = writer.add_object(generic.StreamObject(stream_data=b'q Q'))
            writer.update_container(page)
        PdfSigner(PdfSignatureMetadata(field_name='Firma', subfilter=SigSeedSubFilter.PADES), signer=signer).sign_pdf(
            writer, output=result)
        return result.getvalue()

    def test_valid_signature_over_replaced_page_is_rejected(self):
        settings, signer = self.signer_fixture()
        with self.assertRaisesRegex(v.InvalidArtifact, 'SIGNED_DOCUMENT_CONTENT_CHANGED'):
            v.validate_signature(self.sign(signer, change_page=True), self.pdf, self.cda, 'VRDLGI70A01H501X', settings)

    def test_untrusted_signer(self):
        settings, signer = self.signer_fixture()
        other_settings, _ = self.signer_fixture()
        settings['trust_roots'] = other_settings['trust_roots']
        with self.assertRaisesRegex(v.InvalidArtifact, 'SIGNATURE_TRUST_OR_REVOCATION'):
            v.validate_signature(self.sign(signer), self.pdf, self.cda, 'VRDLGI70A01H501X', settings)

    def test_valid_cryptographic_signature_and_author(self):
        settings, signer = self.signer_fixture()
        signed = self.sign(signer)
        report = v.validate_signature(signed, self.pdf, self.cda, 'VRDLGI70A01H501X', settings)
        self.assertEqual('valid', report['signature'])
        self.assertEqual('not_assessed', report['qualified_signature'])
        v.validate_pdfa(signed, settings)

    def test_wrong_author(self):
        settings, signer = self.signer_fixture()
        with self.assertRaisesRegex(v.InvalidArtifact, 'SIGNER_AUTHOR_MISMATCH'):
            v.validate_signature(self.sign(signer), self.pdf, self.cda, 'RSSMRA80A01H501U', settings)

    def test_altered_signature(self):
        settings, signer = self.signer_fixture()
        signed = self.sign(signer)
        # Change the last byte within signed data, preserving the original unsigned PDF.
        index = signed.rfind(b'/Filter /Adobe.PPKLite')
        self.assertGreater(index, len(self.pdf))
        tampered = signed[:index] + signed[index:].replace(b'Adobe.PPKLite', b'Adobe.PPKLito', 1)
        with self.assertRaisesRegex(v.InvalidArtifact, 'SIGNATURE_CRYPTO_INVALID'):
            v.validate_signature(tampered, self.pdf, self.cda, 'VRDLGI70A01H501X', settings)

    def test_revoked_signer(self):
        settings, signer = self.signer_fixture(revoked=True)
        with self.assertRaisesRegex(v.InvalidArtifact, 'SIGNATURE_TRUST_OR_REVOCATION'):
            v.validate_signature(self.sign(signer), self.pdf, self.cda, 'VRDLGI70A01H501X', settings)

    def test_expired_signer(self):
        settings, signer = self.signer_fixture(expired=True)
        with self.assertRaisesRegex(v.InvalidArtifact, 'SIGNATURE_TRUST_OR_REVOCATION'):
            v.validate_signature(self.sign(signer), self.pdf, self.cda, 'VRDLGI70A01H501X', settings)

    def test_missing_revocation_evidence(self):
        settings, signer = self.signer_fixture()
        settings['crls'] = []
        with self.assertRaisesRegex(v.InvalidArtifact, 'SIGNATURE_TRUST_OR_REVOCATION'):
            v.validate_signature(self.sign(signer), self.pdf, self.cda, 'VRDLGI70A01H501X', settings)

    def test_wrong_original(self):
        settings, signer = self.signer_fixture()
        with self.assertRaisesRegex(v.InvalidArtifact, 'SIGNED_ORIGINAL_MISMATCH'):
            v.validate_signature(self.sign(signer), self.pdf + b'x', self.cda, 'VRDLGI70A01H501X', settings)

    def test_post_signature_bytes(self):
        settings, signer = self.signer_fixture()
        with self.assertRaisesRegex(v.InvalidArtifact, 'SIGNATURE_COVERAGE'):
            v.validate_signature(self.sign(signer) + b'\n% trailing', self.pdf, self.cda, 'VRDLGI70A01H501X', settings)


if __name__ == "__main__":
    program = unittest.main(exit=False)
    report = os.environ.get('FSE2_PYTHON_TEST_REPORT')
    if report:
        target = Path(report).resolve()
        allowed = (ROOT / 'rest/writable/fse-validation-reports').resolve()
        if not target.is_relative_to(allowed):
            raise RuntimeError('Test reports must stay in the isolated report directory')
        result = program.result
        target.write_text(json.dumps({'mode':'OFFLINE_SYNTHETIC_ONLY', 'tests':result.testsRun,
            'failures':len(result.failures), 'errors':len(result.errors), 'skipped':len(result.skipped),
            'passed':result.wasSuccessful() and not result.skipped}, indent=2), encoding='utf-8')
    raise SystemExit(0 if program.result.wasSuccessful() else 1)

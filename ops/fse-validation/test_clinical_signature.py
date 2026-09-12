"""Synthetic standard signatures only; no real provider or private keys loaded."""
import asyncio
import io
import logging
import unittest
import validator as v
from test_validator import ArtifactTests


class ClinicalSignatures(unittest.TestCase):
    settings = {}
    signer_fixture = ArtifactTests.signer_fixture
    sign = ArtifactTests.sign

    def setUp(self):
        from reportlab.pdfgen.canvas import Canvas
        out = io.BytesIO()
        canvas = Canvas(out); canvas.drawString(30, 700, 'Synthetic clinical record'); canvas.save()
        from pypdf import PdfReader, PdfWriter
        from pypdf.generic import NameObject, DictionaryObject, ArrayObject, NumberObject
        writer = PdfWriter(clone_from=PdfReader(out))
        writer._root_object[NameObject('/AcroForm')] = writer._add_object(DictionaryObject({NameObject('/Fields'):ArrayObject(),NameObject('/SigFlags'):NumberObject(0)}))
        result=io.BytesIO(); writer.write(result); self.pdf=result.getvalue()

    def cades(self, signer):
        return asyncio.run(signer.async_sign_general_data(self.pdf, 'sha256', detached=False, use_cades=True)).dump()

    def test_pades_with_prepared_original(self):
        settings, signer = self.signer_fixture()
        evidence = v.validate_signature(self.sign(signer), self.pdf, None, 'VRDLGI70A01H501X', settings)
        self.assertEqual('valid', evidence['signature'])
        self.assertEqual('not_assessed', evidence['qualified_signature'])

    def test_cades_content_and_trust(self):
        settings, signer = self.signer_fixture()
        evidence = v.validate_cades(self.cades(signer), self.pdf, 'VRDLGI70A01H501X', settings)
        self.assertEqual('valid', evidence['signature'])

    def test_cades_rejects_other_content_signer_and_trailing_data(self):
        settings, signer = self.signer_fixture(); signed = self.cades(signer)
        for original, cf, data in [(self.pdf+b'x','VRDLGI70A01H501X',signed),
                                  (self.pdf,'RSSMRA80A01H501U',signed),
                                  (self.pdf,'VRDLGI70A01H501X',signed+b'x')]:
            with self.subTest(cf=cf, length=len(data)), self.assertRaises((v.InvalidArtifact, ValueError)):
                v.validate_cades(data, original, cf, settings)

    def test_both_formats_reject_revoked_expired_or_no_revocation(self):
        for options in [{'revoked':True}, {'expired':True}, {}]:
            settings, signer = self.signer_fixture(**options)
            if not options: settings['crls'] = []
            for fmt in ['pades','cades']:
                with self.subTest(options=options, format=fmt), self.assertRaises(v.InvalidArtifact):
                    if fmt == 'pades': v.validate_signature(self.sign(signer),self.pdf,None,'VRDLGI70A01H501X',settings)
                    else: v.validate_cades(self.cades(signer),self.pdf,'VRDLGI70A01H501X',settings)

    def test_signed_page_replacement_rejected(self):
        settings, signer = self.signer_fixture()
        with self.assertRaises(v.InvalidArtifact):
            v.validate_signature(self.sign(signer,change_page=True),self.pdf,None,'VRDLGI70A01H501X',settings)


if __name__ == '__main__':
    logging.disable(logging.CRITICAL)
    unittest.main()

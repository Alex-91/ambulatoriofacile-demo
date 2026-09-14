"""Local FSE artifact checks. No network, private keys, or clinical values in reports.

Called by PHP with one bounded JSON job on stdin. Settings are administrator-owned.
An unavailable check is a failure, never a successful validation.
"""
import base64
import copy
import hashlib
import io
import json
import logging
import re
import subprocess
import sys
import tempfile
from pathlib import Path
from xml.sax.saxutils import escape

from lxml import etree

LIMIT = 15 * 1024 * 1024
NS = {"h": "urn:hl7-org:v3", "s": "http://purl.oclc.org/dsdl/svrl"}
REVISION = "687cf371e1d0caf4f5f9f7bcc80eab3a97f09885"
SCH_HASH = "5365afe57b8fc241ce27c53f89b876ffb804e7fa790a39b40c53e4a87592569e"


class InvalidArtifact(Exception):
    pass


def require(condition, code):
    if not condition:
        raise InvalidArtifact(code)


def sha(data):
    return hashlib.sha256(data).hexdigest()


def decode(value):
    require(isinstance(value, str) and len(value) <= LIMIT * 4 // 3 + 8, "INPUT_SIZE")
    result = base64.b64decode(value, validate=True)
    require(0 < len(result) <= LIMIT, "INPUT_SIZE")
    return result


def xml_document(data):
    require(len(data) <= LIMIT, "XML_SIZE")
    parser = etree.XMLParser(resolve_entities=False, no_network=True, load_dtd=False)
    root = etree.fromstring(data, parser)
    require(not root.getroottree().docinfo.doctype, "XML_DOCTYPE_FORBIDDEN")
    require(root.tag == "{urn:hl7-org:v3}ClinicalDocument", "CDA_ROOT")
    require(not root.xpath("//processing-instruction()"), "XML_PROCESSING_INSTRUCTION")
    require(not root.xpath("//*[namespace-uri()='http://www.w3.org/2001/XInclude']"), "XML_XINCLUDE")
    return root


def validate_cda(data, settings):
    root = xml_document(data)
    directory = Path(settings["catalog"]).resolve(strict=True)
    manifest = json.loads((directory / "manifest.json").read_text(encoding="utf-8"))
    require(manifest["revision"] == REVISION and len(manifest["files"]) in (12, 13), "CATALOG_VERSION")
    for relative, expected in manifest["files"].items():
        item = (directory / relative).resolve(strict=True)
        require(item.is_relative_to(directory) and sha(item.read_bytes()) == expected, "CATALOG_HASH")
    schema_dir = directory / "schema/POCD_MT000040UV02"

    class CatalogResolver(etree.Resolver):
        def resolve(self, url, public_id, context):
            # Upstream exports flatten coreschemas: resolve only known local basenames.
            name = url.replace('\\', '/').rsplit('/', 1)[-1]
            require('://' not in url or url.startswith('file://'), "XSD_EXTERNAL_RESOURCE")
            candidate = schema_dir / name
            require(candidate.is_file() and f"schema/POCD_MT000040UV02/{name}" in manifest["files"], "XSD_RESOURCE")
            return self.resolve_filename(str(candidate), context)

    parser = etree.XMLParser(resolve_entities=False, no_network=True, load_dtd=False)
    parser.resolvers.add(CatalogResolver())
    schema = etree.XMLSchema(etree.parse(str(schema_dir / "CDA.xsd"), parser))
    if not schema.validate(root):
        # libxml messages can embed clinical values: return only the rule type and line.
        return {"ok": False, "code": "CDA_XSD", "issues": [
            {"rule": e.type_name, "line": e.line} for e in list(schema.error_log)[:30]]}

    template = root.xpath("h:templateId/@root", namespaces=NS)
    is_rad = "2.16.840.1.113883.2.9.10.1.7.1" in template
    require(not (is_rad and "2.16.840.1.113883.2.9.10.1.9.1" in template), "CDA_AMBIGUOUS_TEMPLATE")
    relative = "schematron/schematronFSE_RAD_v4.1.sch" if is_rad else "schematron/schematron_RSA_v8.3.sch"
    expected = "85dfaf2f2356957ace2f571da31b275b1b4898ea8cfee41f2511b7ec48c6c922" if is_rad else SCH_HASH
    require(relative in manifest["files"], "CDA_RAD_CATALOG_REQUIRED")
    sch = directory / relative
    require(sha(sch.read_bytes()) == expected, "SCHEMATRON_HASH")
    from saxonche import PySaxonProcessor
    with PySaxonProcessor(license=False) as proc:
        proc.set_configuration_property("http://saxon.sf.net/feature/allow-external-functions", "false")
        proc.set_configuration_property("http://saxon.sf.net/feature/allowedProtocols", "file")
        xslt = proc.new_xslt30_processor()
        compiled = xslt.transform_to_string(source_file=str(sch), stylesheet_file=settings["schxslt"])
        require(compiled is not None, "SCHEMATRON_COMPILER")
        executable = xslt.compile_stylesheet(stylesheet_text=compiled)
        report = executable.transform_to_string(xdm_node=proc.parse_xml(xml_text=data.decode("utf-8")))
    svrl = etree.fromstring(report.encode(), etree.XMLParser(resolve_entities=False, no_network=True))
    require(svrl.tag == "{http://purl.oclc.org/dsdl/svrl}schematron-output", "SCHEMATRON_REPORT")
    require(bool(svrl.xpath("//s:fired-rule", namespaces=NS)), "SCHEMATRON_NO_RULES")
    def issues(kind):
        codes = []
        for node in svrl.xpath(f"//s:{kind}/s:text", namespaces=NS):
            code = ''.join(node.itertext()).split('|', 1)[0].strip()
            codes.append(code if re.fullmatch(r"[A-Za-z0-9_-]{1,40}", code) else "SCHEMATRON_RULE")
        return codes[:40]
    errors = issues("failed-assert")
    return {"ok": not errors, "code": "CDA_SCHEMATRON" if errors else "OK", "issues": errors,
            "warnings": issues("successful-report"), "catalog_revision": REVISION, "cda_sha256": sha(data)}


def pdf_attachment(pdf, cda):
    from pypdf import PdfReader
    from pypdf.generic import IndirectObject, DictionaryObject, ArrayObject
    from pypdf._configuration import Configuration
    Configuration.zlib_maximum_output_length = LIMIT
    reader = PdfReader(io.BytesIO(pdf), strict=True)
    require(not reader.is_encrypted and 0 < len(reader.pages) <= 200, "PDF_ENCRYPTED_OR_PAGES")
    seen = set()
    forbidden = {"/JavaScript", "/JS", "/OpenAction", "/AA", "/Launch", "/RichMedia", "/XFA"}
    def walk(obj):
        if isinstance(obj, IndirectObject):
            key = (obj.idnum, obj.generation)
            if key in seen:
                return
            seen.add(key)
            require(len(seen) < 50000, "PDF_OBJECT_LIMIT")
            obj = obj.get_object()
        if isinstance(obj, DictionaryObject):
            require(not forbidden.intersection(obj), "PDF_ACTIVE_CONTENT")
            for child in obj.values():
                walk(child)
        elif isinstance(obj, ArrayObject):
            for child in obj:
                walk(child)
    walk(reader.trailer["/Root"])
    entries = list(reader.attachment_list)
    require(len(entries) == 1 and entries[0].name == "cda.xml", "PDF_CDA_ATTACHMENT_COUNT")
    require(entries[0].content == cda, "PDF_CDA_MISMATCH")
    root = reader.trailer["/Root"]
    af = root.get("/AF", [])
    require(len(af) == 1, "PDF_ASSOCIATED_FILE")
    associated = af[0].get_object()
    require(associated.get("/AFRelationship") == "/Data", "PDF_ASSOCIATED_FILE")
    require(associated["/EF"]["/F"].get_data() == cda, "PDF_AF_CDA_MISMATCH")
    return reader


def validate_pdfa(pdf, settings):
    # Call Java directly, never a shell/batch file. No uploaded PDF leaves this machine.
    with tempfile.TemporaryDirectory(prefix="fse-verapdf-") as directory:
        path = Path(directory) / "document.pdf"
        path.write_bytes(pdf)
        path.chmod(0o600)
        with tempfile.TemporaryFile() as out, tempfile.TemporaryFile() as err:
            run = subprocess.run([settings["java"], "-Xmx256m", "-jar", settings["verapdf_jar"],
                                  "--format", "xml", "--flavour", "3b", str(path)],
                                 stdout=out, stderr=err, timeout=35, check=False)
            out.seek(0)
            data = out.read(2 * 1024 * 1024 + 1)
        require(run.returncode in [0, 1] and len(data) <= 2 * 1024 * 1024, "PDFA_VALIDATOR_UNAVAILABLE")
        result = etree.fromstring(data, etree.XMLParser(resolve_entities=False, no_network=True))
        reports = result.xpath(".//*[local-name()='validationReport']")
        require(len(reports) == 1, "PDFA_REPORT")
        require(reports[0].get("profileName", "").startswith("PDF/A-3b"), "PDFA_PROFILE")
        require(reports[0].get("isCompliant") == "true", "PDFA_NONCONFORMANT")
    return {"pdfa": "3b", "pdf_sha256": sha(pdf)}


def build_pdf(cda, settings):
    # Derive visible clinical text from the exact CDA that will be embedded.
    from reportlab.pdfbase import pdfmetrics
    from reportlab.pdfbase.ttfonts import TTFont
    from reportlab.lib.styles import ParagraphStyle
    from reportlab.platypus import SimpleDocTemplate, Paragraph, Spacer
    from reportlab.lib.pagesizes import A4
    import reportlab
    from pypdf import PdfReader, PdfWriter
    from pypdf.generic import (ArrayObject, DictionaryObject, NameObject, NumberObject,
                               TextStringObject, DecodedStreamObject)
    from PIL import ImageCms
    root = xml_document(cda)
    font = Path(reportlab.__file__).parent / "fonts/Vera.ttf"
    pdfmetrics.registerFont(TTFont("FseVera", str(font)))
    style = ParagraphStyle("body", fontName="FseVera", fontSize=10, leading=15, spaceAfter=8)
    heading = ParagraphStyle("heading", parent=style, fontSize=16, leading=22, spaceAfter=18)
    title = root.findtext("h:title", namespaces=NS) or "Referto specialistico"
    story = [Paragraph(escape(title), heading)]
    version = root.find("h:versionNumber", namespaces=NS)
    identity = root.find("h:id", namespaces=NS)
    story.append(Paragraph(escape(f"Versione: {version.get('value', '') if version is not None else ''} - Documento: {identity.get('extension', '') if identity is not None else ''}"), style))
    parent = root.find("h:relatedDocument/h:parentDocument/h:id", namespaces=NS)
    if parent is not None:
        story.append(Paragraph(escape(f"Correzione del documento: {parent.get('extension', '')}. Il collegamento clinico non attesta l'avvenuta sostituzione sul FSE."), style))
    for label, xpath in [
        ("Paziente", "h:recordTarget/h:patientRole/h:patient/h:name"),
        ("Medico", "h:author/h:assignedAuthor/h:assignedPerson/h:name"),
        ("Struttura", "h:custodian/h:assignedCustodian/h:representedCustodianOrganization/h:name")]:
        node = root.find(xpath, namespaces=NS)
        value = ' '.join(node.itertext()) if node is not None else ''
        story.append(Paragraph(escape(f"{label}: {value}"), style))
    for label, xpath, attr in [
        ("Codice fiscale", "h:recordTarget/h:patientRole/h:id", "extension"),
        ("Data di nascita", "h:recordTarget/h:patientRole/h:patient/h:birthTime", "value"),
        ("Data prestazione", "h:effectiveTime", "value")]:
        node = root.find(xpath, namespaces=NS)
        value = node.get(attr, '') if node is not None else ''
        if label == 'Data di nascita' and re.fullmatch(r'\d{8}', value):
            value = f'{value[6:8]}/{value[4:6]}/{value[:4]}'
        if label == 'Data prestazione' and re.fullmatch(r'\d{14}[+-]\d{4}', value):
            value = f'{value[6:8]}/{value[4:6]}/{value[:4]} {value[8:10]}:{value[10:12]} (UTC{value[14:17]}:{value[17:19]})'
        story.append(Paragraph(escape(f"{label}: {value}"), style))
    story.append(Spacer(1, 12))
    for section in root.findall("h:component/h:structuredBody/h:component/h:section", namespaces=NS):
        story.append(Paragraph(escape(section.findtext("h:title", namespaces=NS) or "Sezione"), style))
        text = section.find("h:text", namespaces=NS)
        if text is not None:
            for paragraph in text:
                story.append(Paragraph(escape(' '.join(paragraph.itertext())).replace('\n', '<br/>'), style))
    buffer = io.BytesIO()
    def footer(canvas, doc):
        canvas.setFont("FseVera", 8)
        canvas.drawString(45, 25, f"Ambulatorio Facile - pagina {doc.page}")
    SimpleDocTemplate(buffer, pagesize=A4, leftMargin=45, rightMargin=45, topMargin=45,
                      bottomMargin=45, title=title).build(story, onFirstPage=footer, onLaterPages=footer)
    writer = PdfWriter(clone_from=PdfReader(buffer))
    writer.pdf_header = "%PDF-1.7"
    writer.add_metadata({"/Title": title, "/Creator": "Ambulatorio Facile", "/Producer": "Ambulatorio Facile FSE"})
    writer.generate_file_identifiers()
    # An explicit empty form dictionary lets signature tools add their field as a checked incremental update.
    writer._root_object[NameObject('/AcroForm')] = writer._add_object(DictionaryObject({NameObject('/Fields'): ArrayObject(), NameObject('/SigFlags'): NumberObject(0)}))
    attachment = writer.add_attachment("cda.xml", cda)
    attachment.subtype = NameObject("/text/xml")
    attachment.associated_file_relationship = NameObject("/Data")
    attachment.description = TextStringObject("HL7 CDA R2")
    attachment.size = NumberObject(len(cda))
    # add_attachment creates the Names entry; AF must reference that same Filespec.
    filespec = writer._root_object["/Names"]["/EmbeddedFiles"]["/Names"][1]
    filespec.get_object()[NameObject("/UF")] = TextStringObject("cda.xml")
    writer._root_object[NameObject("/AF")] = ArrayObject([filespec])
    icc = DecodedStreamObject()
    icc.set_data(ImageCms.ImageCmsProfile(ImageCms.createProfile("sRGB")).tobytes())
    icc[NameObject("/N")] = NumberObject(3)
    intent = DictionaryObject({NameObject("/Type"): NameObject("/OutputIntent"),
        NameObject("/S"): NameObject("/GTS_PDFA1"),
        NameObject("/OutputConditionIdentifier"): TextStringObject("sRGB"),
        NameObject("/DestOutputProfile"): writer._add_object(icc)})
    writer._root_object[NameObject("/OutputIntents")] = ArrayObject([writer._add_object(intent)])
    # Match the Info dictionary through the library's XMP support.
    from pypdf.xmp import XmpInformation
    xmp = XmpInformation.create()
    xmp.pdfaid_part = "3"
    xmp.pdfaid_conformance = "B"
    xmp.dc_title = {"x-default": title}
    xmp.xmp_creator_tool = "Ambulatorio Facile"
    xmp.pdf_producer = "Ambulatorio Facile FSE"
    writer.xmp_metadata = xmp
    metadata = writer._root_object["/Metadata"]
    metadata[NameObject("/Type")] = NameObject("/Metadata")
    metadata[NameObject("/Subtype")] = NameObject("/XML")
    # XMP packets don't require an XML declaration; avoid signer parser incompatibilities.
    metadata.set_data(re.sub(br'^\s*<\?xml[^>]*\?>', b'', metadata.get_data()))
    # Remove inherited ReportLab Info fields without corresponding XMP values.
    for key in list(writer.metadata):
        if key not in ["/Title", "/Creator", "/Producer"]:
            writer._info.get_object().pop(key, None)
    output = io.BytesIO()
    writer.write(output)
    pdf = output.getvalue()
    pdf_attachment(pdf, cda)
    evidence = validate_pdfa(pdf, settings)
    return {"ok": True, "code": "OK", "pdf": base64.b64encode(pdf).decode(), **evidence}


def validate_signature(pdf, unsigned, cda, author_cf, settings):
    from pyhanko.pdf_utils.reader import PdfFileReader
    from pyhanko.keys import load_cert_from_pemder
    from pyhanko.sign.validation import validate_pdf_signature, SignatureCoverageLevel
    from pyhanko.sign.diff_analysis import DEFAULT_DIFF_POLICY, DiffResult, ModificationLevel
    from pyhanko_certvalidator import ValidationContext
    from asn1crypto import crl, ocsp
    require(pdf.startswith(unsigned) and len(pdf) > len(unsigned), "SIGNED_ORIGINAL_MISMATCH")
    require(bool(re.fullmatch(r"[A-Z0-9]{16}", author_cf)), "SIGNER_EXPECTED_ID")
    if cda is not None:
        root = xml_document(cda)
        author_ids = root.xpath("h:author/h:assignedAuthor/h:id[@root='2.16.840.1.113883.2.9.4.3.2']/@extension | h:legalAuthenticator/h:assignedEntity/h:id[@root='2.16.840.1.113883.2.9.4.3.2']/@extension", namespaces=NS)
        require(len(author_ids) == 2 and all(value == author_cf for value in author_ids), "SIGNER_AUTHOR_MISMATCH")
    roots = settings.get("trust_roots", [])
    require(bool(roots), "SIGNATURE_TRUST_NOT_CONFIGURED")
    context = ValidationContext(trust_roots=[load_cert_from_pemder(p) for p in roots],
        crls=[crl.CertificateList.load(Path(p).read_bytes()) for p in settings.get("crls", [])],
        ocsps=[ocsp.OCSPResponse.load(Path(p).read_bytes()) for p in settings.get("ocsps", [])],
        allow_fetching=False, revocation_mode="require")
    reader = PdfFileReader(io.BytesIO(pdf), strict=True)
    original = PdfFileReader(io.BytesIO(unsigned), strict=True)
    require(original.total_revisions == 1 and reader.total_revisions == 2, "SIGNATURE_REVISION_POLICY")
    signatures = reader.embedded_signatures
    require(len(signatures) == 1, "SIGNATURE_COUNT")
    sig = signatures[0]
    require(sig.sig_object.get("/SubFilter") == "/ETSI.CAdES.detached", "SIGNATURE_NOT_PADES")
    status = validate_pdf_signature(sig, signer_validation_context=context)
    require(status.intact and status.valid, "SIGNATURE_CRYPTO_INVALID")
    require(status.trusted and not status.revoked, "SIGNATURE_TRUST_OR_REVOCATION")
    require(status.coverage == SignatureCoverageLevel.ENTIRE_FILE and status.docmdp_ok, "SIGNATURE_COVERAGE")
    require(status.bottom_line, "SIGNATURE_POLICY")
    # A valid signature alone does not prove that the signer used the original page content.
    require('/AcroForm' in original.root, 'ORIGINAL_SIGNATURE_FORM_REQUIRED')
    require(original.root['/AcroForm'].get('/SigFlags', 0) == 0
            and reader.root['/AcroForm'].get('/SigFlags') == 3, "SIGNATURE_FORM_FLAGS")
    policy = copy.copy(DEFAULT_DIFF_POLICY)
    policy.form_rule = copy.copy(DEFAULT_DIFF_POLICY.form_rule)
    # The exact 0 -> 3 transition above is permitted when adding the first signature.
    # All other content/form differences still use pyHanko's reject-by-default rules.
    policy.form_rule.ignored_acroform_keys |= {'/SigFlags'}
    changes = policy.review_file(reader, base_revision=0)
    require(isinstance(changes, DiffResult) and changes.modification_level <= ModificationLevel.FORM_FILLING,
            "SIGNED_DOCUMENT_CONTENT_CHANGED")
    serial = sig.signer_cert.subject.native.get("serial_number", "")
    require(serial in [author_cf, "TINIT-" + author_cf], "SIGNER_AUTHOR_MISMATCH")
    if cda is not None:
        pdf_attachment(pdf, cda)
    return {"signature": "valid", "signer_certificate_sha256": sha(sig.signer_cert.dump()),
            "trust_policy": "explicit-roots-offline-revocation-required", "qualified_signature": "not_assessed"}


def validate_cades(signed, original, author_cf, settings):
    import asyncio
    from asn1crypto import cms, crl, ocsp
    from pyhanko.keys import load_cert_from_pemder
    from pyhanko.sign.validation import async_validate_cms_signature
    from pyhanko_certvalidator import ValidationContext
    require(bool(re.fullmatch(r"[A-Z0-9]{16}", author_cf)), "SIGNER_EXPECTED_ID")
    container = cms.ContentInfo.load(signed, strict=True)
    require(container['content_type'].native == 'signed_data', "CADES_CONTENT_TYPE")
    data = container['content']
    require(data['encap_content_info']['content'].native == original, "SIGNED_ORIGINAL_MISMATCH")
    require(len(data['signer_infos']) == 1, "SIGNATURE_COUNT")
    attrs = {a['type'].native for a in data['signer_infos'][0]['signed_attrs']}
    require(bool(attrs & {'signing_certificate','signing_certificate_v2'}), "SIGNATURE_NOT_CADES")
    roots = settings.get('trust_roots', [])
    require(bool(roots), "SIGNATURE_TRUST_NOT_CONFIGURED")
    context = ValidationContext(trust_roots=[load_cert_from_pemder(p) for p in roots],
        crls=[crl.CertificateList.load(Path(p).read_bytes()) for p in settings.get('crls', [])],
        ocsps=[ocsp.OCSPResponse.load(Path(p).read_bytes()) for p in settings.get('ocsps', [])],
        allow_fetching=False, revocation_mode='require')
    status = asyncio.run(async_validate_cms_signature(data, validation_context=context))
    require(status.intact and status.valid, "SIGNATURE_CRYPTO_INVALID")
    require(status.trusted and not status.revoked, "SIGNATURE_TRUST_OR_REVOCATION")
    cert = status.signing_cert
    require(cert.subject.native.get('serial_number', '') in [author_cf, 'TINIT-' + author_cf], "SIGNER_AUTHOR_MISMATCH")
    return {"signature":"valid", "signer_certificate_sha256":sha(cert.dump()),
            "trust_policy":"explicit-roots-offline-revocation-required", "qualified_signature":"not_assessed"}


def trust_readiness(settings):
    """Check presence, parsing and freshness only: not signer trust or qualification."""
    from datetime import datetime, timezone
    from pyhanko.keys import load_cert_from_pemder
    from asn1crypto import crl, ocsp
    roots = settings.get("trust_roots", [])
    crls = settings.get("crls", [])
    ocsps = settings.get("ocsps", [])
    if not roots or not (crls or ocsps):
        return "missing"
    try:
        now = datetime.now(timezone.utc)
        for path in roots:
            cert = load_cert_from_pemder(path)
            validity = cert['tbs_certificate']['validity']
            require(validity['not_before'].native <= now < validity['not_after'].native, "TRUST_CERT_DATE")
            require(cert.ca, "TRUST_CA")
        for path in crls:
            item = crl.CertificateList.load(Path(path).read_bytes())['tbs_cert_list']
            require(item['this_update'].native <= now and item['next_update'].native is not None
                    and now < item['next_update'].native, "CRL_DATE")
        for path in ocsps:
            item = ocsp.OCSPResponse.load(Path(path).read_bytes())
            require(item['response_status'].native == 'successful', "OCSP_STATUS")
            responses = item['response_bytes']['response'].parsed['tbs_response_data']['responses']
            require(len(responses) > 0, "OCSP_EMPTY")
            for response in responses:
                require(response['this_update'].native <= now and response['next_update'].native is not None
                        and now < response['next_update'].native, "OCSP_DATE")
        return "configured"
    except Exception:
        return "invalid"


def execute(job):
    settings = json.loads(Path(job["settings"]).read_text(encoding="utf-8-sig"))
    if job['operation'] == 'readiness':
        return {"ok": True, "trust_material": trust_readiness(settings)}
    if job['operation'] == 'clinical_signature':
        original, signed = decode(job['unsigned']), decode(job['signed'])
        require(original.startswith(b'%PDF-'), 'ORIGINAL_NOT_PDF')
        require(job['format'] in ['pades', 'cades'], 'SIGNATURE_FORMAT')
        evidence = (validate_signature(signed, original, None, job['author_cf'], settings)
                    if job['format'] == 'pades' else validate_cades(signed, original, job['author_cf'], settings))
        return {'ok':True, 'format':job['format'], 'original_sha256':sha(original), 'signed_sha256':sha(signed), **evidence}
    cda = decode(job["cda"])
    cda_result = validate_cda(cda, settings)
    if not cda_result["ok"] or job["operation"] == "cda":
        return cda_result
    if job["operation"] == "health":
        build_pdf(cda, settings)
        return {"ok": True, "artifacts": "passed", "trust_material": trust_readiness(settings)}
    if job["operation"] == "build":
        return {**cda_result, **build_pdf(cda, settings)}
    require(job["operation"] in ["pdf", "signed"], "OPERATION")
    pdf = decode(job["pdf"])
    pdf_attachment(pdf, cda)
    result = {**cda_result, **validate_pdfa(pdf, settings)}
    if job["operation"] == "signed":
        result.update(validate_signature(pdf, decode(job["unsigned"]), cda, job["author_cf"], settings))
    return result


if __name__ == "__main__":
    logging.disable(logging.CRITICAL)  # Parser diagnostics may contain patient data.
    try:
        raw = sys.stdin.buffer.read(64 * 1024 * 1024 + 1)
        require(len(raw) <= 64 * 1024 * 1024, "JOB_SIZE")
        response = execute(json.loads(raw))
    except InvalidArtifact as exc:
        response = {"ok": False, "code": str(exc)}
    except Exception:
        response = {"ok": False, "code": "VALIDATOR_UNAVAILABLE_OR_INVALID_INPUT"}
    print(json.dumps(response, separators=(",", ":")))

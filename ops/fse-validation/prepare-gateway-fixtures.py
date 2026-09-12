"""Build test PDFs from pinned official RSA XML, without changing the XML or signing clinically."""
import base64
import argparse
import hashlib
import json
import subprocess
from pathlib import Path
import validator as v

ROOT = Path(__file__).resolve().parents[2]
REVISION = "d937255fd7e9c079c5641c537da17fe98a2f2259"
SOURCE = ROOT / "ops/.local/fse-accreditamento/official-sources" / REVISION
OUTPUT = ROOT / "ops/.local/fse-accreditamento/gateway-fixtures" / REVISION


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--cases', nargs='+', choices=('1', '2', '3', '4', '24', '25', 'app'), default=['24', '25', 'app'])
    args = parser.parse_args()
    manifest = json.loads((SOURCE / "manifest.json").read_text(encoding="utf-8-sig"))
    settings = json.loads((ROOT / "rest/writable/fse-validator-settings.json").read_text(encoding="utf-8-sig"))
    OUTPUT.mkdir(parents=True, exist_ok=True)
    results = {"mode": "OFFICIAL_RSA_EXAMPLES_PREPARATORY_ONLY", "revision": REVISION, "cases": {}}
    result_path = OUTPUT / "manifest.json"
    if result_path.exists():
        results = json.loads(result_path.read_text(encoding='utf-8'))
        v.require(results['mode'] == 'OFFICIAL_RSA_EXAMPLES_PREPARATORY_ONLY' and results['revision'] == REVISION, 'MANIFEST_MISMATCH')
    for case in args.cases:
        name = f"rsa-case-{case if case != 'app' else '24'}.xml"
        source_cda = (SOURCE / name).read_bytes()
        v.require(hashlib.sha256(source_cda).hexdigest() == manifest["files"][name]["sha256"], "SOURCE_HASH_MISMATCH")
        cda = subprocess.check_output(['php', str(Path(__file__).with_name('gateway-app-cda.php'))], cwd=ROOT) if case == 'app' else source_cda
        root = v.xml_document(cda)
        validation = v.validate_cda(cda, settings)
        # Keep earlier app-generated failures reproducible after builder changes.
        stem = f"rsa-case-app-{hashlib.sha256(cda).hexdigest()[:16]}" if case == 'app' else f"rsa-case-{case}"
        pdf_name = stem + '.pdf'
        if (OUTPUT / pdf_name).exists():
            pdf = (OUTPUT / pdf_name).read_bytes()
            v.pdf_attachment(pdf, cda)
            v.validate_pdfa(pdf, settings)
        else:
            pdf = base64.b64decode(v.build_pdf(cda, settings)["pdf"])
            (OUTPUT / pdf_name).write_bytes(pdf)
        def attr(path, key="extension"):
            node = root.find(path, namespaces=v.NS)
            return node.get(key, "") if node is not None else ""
        author = attr("h:author/h:assignedAuthor/h:id")
        patient = attr("h:recordTarget/h:patientRole/h:id")
        results["cases"][str(case)] = {
            "pdf_file": pdf_name, "pdf_sha256": hashlib.sha256(pdf).hexdigest(),
            "fixture_kind": 'APP_GENERATED_SYNTHETIC' if case == 'app' else 'OFFICIAL_XML_UNMODIFIED',
            "cda_sha256": hashlib.sha256(cda).hexdigest(), "source_url": manifest["files"][name]["url"],
            "local_cda_validation": validation, "pdfa": "3b", "clinical_signature": "NOT_APPLIED",
            "document": {"author_cf": author, "patient_cf": patient, "patient_consent": True,
                         "loinc_code": attr("h:code", "code")},
        }
        if case == 'app':
            (OUTPUT / (stem + '.xml')).write_bytes(cda)
        result_path.write_text(json.dumps(results, indent=2), encoding="utf-8")
        print(f"RSA {case}: local CDA={validation.get('ok')}; PDF/A-3b verified", flush=True)


if __name__ == "__main__":
    main()

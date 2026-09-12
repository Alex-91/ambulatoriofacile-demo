"""Local PDF QA: all pages, content preservation and exact raster comparison.

Only marked synthetic compatibility runs are accepted. No network or app services.
"""
import argparse
import hashlib
import json
from pathlib import Path
import re
import subprocess
import uuid

import pdfplumber
from PIL import Image, ImageChops
from pypdf import PdfReader


def read_run(value: str, variant: str) -> tuple[Path, dict]:
    run = Path(value).resolve(strict=True)
    parent = Path(__file__).resolve().parents[2] / "rest/writable/fse-dependency-compat"
    if run.parent.parent != parent or not re.fullmatch(r"[a-f0-9]{32}", run.parent.name):
        raise ValueError("Not a private dependency laboratory")
    if not re.fullmatch(r"application-" + variant + r"-[a-f0-9]{16}", run.name):
        raise ValueError("Unexpected compatibility run")
    provenance = json.loads((run / "provenance.json").read_text(encoding="utf-8"))
    report = json.loads((run / "pdf-report.json").read_text(encoding="utf-8"))
    if not provenance["passed"] or report["mode"] != "SYNTHETIC_PDF_RENDERING_ONLY" or report["variant"] != variant:
        raise ValueError("Incomplete synthetic rendering or wrong provenance")
    return run, report


def inspect(run: Path, case: dict, prefix: Path, poppler: Path) -> dict:
    path = (run / case["file"]).resolve(strict=True)
    if path.parent != run / "pdf" or path.suffix != ".pdf":
        raise ValueError("Unsafe fixture path")
    if hashlib.sha256(path.read_bytes()).hexdigest() != case["sha256"]:
        raise ValueError("Fixture modified after rendering")
    reader = PdfReader(path)
    if len(reader.pages) != case["pages"] or not 1 <= len(reader.pages) <= 20:
        raise ValueError("Unexpected page count")
    text = "\n".join(page.extract_text() or "" for page in reader.pages)
    missing = [token for token in case["required_tokens"] if len(re.findall(r"\b" + re.escape(token) + r"\b", text)) != 1]
    missing.extend(value for value in case["required_text"] if value not in text)
    outside = []
    with pdfplumber.open(path) as pdf:
        for page_number, page in enumerate(pdf.pages, 1):
            for char in page.chars:
                if char["text"].strip() and (char["x0"] < -1 or char["x1"] > page.width + 1
                        or char["top"] < -1 or char["bottom"] > page.height + 1):
                    outside.append(page_number)
    subprocess.run([str(poppler), "-r", "100", "-png", str(path), str(prefix)], check=True, timeout=60, capture_output=True)
    images = sorted(prefix.parent.glob(prefix.name + "-*.png"), key=lambda p: int(p.stem.rsplit("-", 1)[1]))
    if len(images) != len(reader.pages):
        raise ValueError("Not every PDF page was rendered")
    return {"images": images, "text": text, "missing_or_duplicate_content": missing,
            "text_outside_page_count": len(outside), "text_outside_page_numbers": sorted(set(outside))}


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--baseline", required=True)
    parser.add_argument("--candidate", required=True)
    parser.add_argument("--pdftoppm", required=True)
    args = parser.parse_args()
    baseline, baseline_report = read_run(args.baseline, "baseline")
    candidate, candidate_report = read_run(args.candidate, "candidate")
    if baseline.parent != candidate.parent or baseline_report["outputs"].keys() != candidate_report["outputs"].keys():
        raise ValueError("Different laboratories or fixtures")
    poppler = Path(args.pdftoppm).resolve(strict=True)
    output = candidate / ("pdf-qa-" + uuid.uuid4().hex)
    output.mkdir()
    results = {}
    for name, case in candidate_report["outputs"].items():
        if not re.fullmatch(r"[a-z0-9_-]+", name):
            raise ValueError("Invalid fixture name")
        current = baseline_report["outputs"][name]
        if current["view_html_sha256"] != case["view_html_sha256"]:
            raise ValueError("Baseline and candidate used different input HTML")
        before = inspect(baseline, current, output / (name + "-baseline"), poppler)
        after = inspect(candidate, case, output / (name + "-candidate"), poppler)
        pages = []
        for left, right in zip(before["images"], after["images"]):
            with Image.open(left) as image_a, Image.open(right) as image_b:
                equal = image_a.size == image_b.size and ImageChops.difference(image_a.convert("RGB"), image_b.convert("RGB")).getbbox() is None
            pages.append({"baseline": left.name, "candidate": right.name, "pixels_identical": equal})
        passed = (len(before["images"]) == len(after["images"]) and all(page["pixels_identical"] for page in pages)
                  and before["text"] == after["text"] and not before["missing_or_duplicate_content"]
                  and not after["missing_or_duplicate_content"] and not before["text_outside_page_count"]
                  and not after["text_outside_page_count"])
        results[name] = {"passed": passed, "pages": pages, "text_identical": before["text"] == after["text"],
                         "baseline_content_errors": before["missing_or_duplicate_content"],
                         "candidate_content_errors": after["missing_or_duplicate_content"],
                         "baseline_text_outside_page_count": before["text_outside_page_count"],
                         "candidate_text_outside_page_count": after["text_outside_page_count"]}
    passed = all(case["passed"] for case in results.values())
    report = {"mode": "SYNTHETIC_PDF_COMPARISON_ONLY", "passed": passed,
              "status": "passed_automated_comparison" if passed else "requires_review",
              "baseline": str(baseline), "candidate": str(candidate), "cases": results,
              "manual_visual_review": "NOT_RECORDED_BY_AUTOMATIC_TOOL",
              "limitations": ["Identical raster output does not prove good layout: baseline defects can be present in both.",
                              "No clinical/fiscal document, qualified signature, HTTP endpoint or real patient data."]}
    report_path = output / "comparison.json"
    report_path.write_text(json.dumps(report, indent=2), encoding="utf-8")
    print(json.dumps({"report": str(report_path), "status": report["status"], "cases": len(results)}))
    return 0 if passed else 1


if __name__ == "__main__":
    raise SystemExit(main())

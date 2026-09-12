"""Independent DCMTK parsing and real MWL C-FIND; synthetic lab only, no published DIMSE port."""
import json, pathlib, subprocess, sys, tempfile
root = pathlib.Path(sys.argv[1]).resolve()
manifest = json.loads((root / "manifest.json").read_text())
assert manifest["marker"] == "ambulatoriofacile-pacs-synthetic-v1"
expected = json.loads((root / "worklist-expected.json").read_text())
source = root / "worklists/af-synthetic.wl"
assert source.is_file() and not source.is_symlink()
def parse(path):
    result = subprocess.run(["dcm2json", str(path)], capture_output=True, check=True, timeout=10)
    return json.loads(result.stdout)
data = parse(source)
assert data["00100010"]["Value"][0]["Alphabetic"] == expected["patient_name"]
assert data["00100030"]["Value"][0] == "19800101"
assert data["00100020"]["Value"][0] == expected["patient_id"]
assert data["00100021"]["Value"][0] == expected["issuer"]
assert data["0020000D"]["Value"][0] == expected["study_uid"]
assert data["00080050"]["Value"][0] == expected["accession"]
step = data["00400100"]["Value"][0]
assert step["00400001"]["Value"][0] == "FINDSCU"
assert step["00400002"]["Value"][0] == "20260920"
assert step["00400003"]["Value"][0] == "103000"
assert step["00400008"]["Value"][0]["00080100"]["Value"][0] == "LAB-CT"
assert data["00080201"]["Value"][0] == "+0200"
def query(aet="FINDSCU", modality="CT", date="20260920", called="AF_MWL_LAB", rejected=False):
    with tempfile.TemporaryDirectory(prefix="af-mwl-scu-") as directory:
        command = ["findscu", "-W", "-aec", called, "-aet", aet, "-to", "5", "-ta", "5", "-td", "5",
                   "-X", "-od", directory, "-k", "AccessionNumber=*", "-k", "PatientID", "-k", "IssuerOfPatientID",
                   "-k", "PatientName", "-k", "StudyInstanceUID", "-k", "SpecificCharacterSet",
                   "-k", "ScheduledProcedureStepSequence[0].Modality=" + modality,
                   "-k", "ScheduledProcedureStepSequence[0].ScheduledProcedureStepStartDate=" + date,
                   "127.0.0.1", "4242"]
        result = subprocess.run(command, capture_output=True, timeout=20)
        error = result.stderr.decode(errors="replace")
        if rejected:
            assert not list(pathlib.Path(directory).glob("rsp*.dcm")), "Rejected peer received data"
            # DCMTK can exit 0 after a peer abort; inspect the protocol failure as well.
            assert result.returncode != 0 or "Peer aborted Association" in error or "Association Rejected" in error, error
            return []
        assert result.returncode == 0 and "Find Failed" not in error, error
        return [parse(p) for p in pathlib.Path(directory).glob("rsp*.dcm")]
responses = query()
assert len(responses) == 1, f"Expected one matching request, got {len(responses)}"
for tag in ["00080050", "00100020", "00100021", "0020000D"]:
    assert responses[0][tag]["Value"] == data[tag]["Value"]
assert responses[0]["00100010"]["Value"][0]["Alphabetic"] == expected["patient_name"]
assert query(modality="MR") == []
assert query(date="20260921") == []
assert query(aet="OTHERAE") == []  # Registered peer, but assigned to another station.
query(aet="UNREGISTERED", rejected=True)
query(called="WRONG_SCP", rejected=True)
# Withdrawal is deliberately explicit: downloaded copies are not remotely revocable by the app.
withdrawn = source.with_suffix(".withdrawn")
assert not withdrawn.exists()
source.rename(withdrawn)
try:
    assert query() == [], "A withdrawn item must no longer appear in C-FIND"
finally:
    withdrawn.rename(source)
assert len(query()) == 1
version = subprocess.run(["findscu", "--version"], capture_output=True, check=True).stdout.decode().splitlines()[0]
report = {"result":"passed","tools":version,"checks":["independent DICOM parsing","UTF-8 patient name","stable accession and StudyInstanceUID",
    "real C-FIND match","modality/date filters","calling AE station filter","unknown/called AE rejection","explicit withdrawal and restoration"],
    "scope":"synthetic only; no customer integration or automatic dispatch"}
(root/"worklist-result.json").write_text(json.dumps(report,indent=2))
print(json.dumps(report))

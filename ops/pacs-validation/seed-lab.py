"""Seed only a marked synthetic localhost Orthanc; verify standard STOW ingestion."""
import base64, json, pathlib, ssl, sys, urllib.request
run=pathlib.Path(sys.argv[1]).resolve()
m=json.loads((run/"manifest.json").read_text())
assert m["marker"]=="ambulatoriofacile-pacs-synthetic-v1" and m["port"]==18443
context=ssl.create_default_context(cafile=str(run/"ca.pem"))
auth="Basic "+base64.b64encode((m["username"]+":"+m["password"]).encode()).decode()
for n in (1,2):
    data=(run/("synthetic-"+str(n)+".dcm")).read_bytes()
    boundary="af-pacs-synthetic-boundary"
    body=("--"+boundary+"\r\nContent-Type: application/dicom\r\n\r\n").encode()+data+("\r\n--"+boundary+"--\r\n").encode()
    request=urllib.request.Request("https://127.0.0.1:18443/dicom-web/studies",data=body,headers={"Authorization":auth,"Content-Type":'multipart/related; type="application/dicom"; boundary='+boundary,"Accept":"application/dicom+json"})
    with urllib.request.urlopen(request,context=context,timeout=20) as response:
        assert response.status==200
        result=json.loads(response.read())
        assert result.get("00081199",{}).get("Value"), "Missing successful STOW references"
print("Two synthetic DICOM studies stored through STOW-RS.")

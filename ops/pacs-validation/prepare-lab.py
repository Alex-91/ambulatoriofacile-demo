"""Create a fresh, loopback-only Orthanc TLS lab with synthetic data and private credentials."""
import base64, datetime, ipaddress, json, pathlib, secrets, struct, uuid
from cryptography import x509
from cryptography.hazmat.primitives import hashes, serialization
from cryptography.hazmat.primitives.asymmetric import rsa
from cryptography.x509.oid import NameOID

repo = pathlib.Path(__file__).resolve().parents[2]
run = repo / "rest/writable/pacs-labs" / uuid.uuid4().hex
run.mkdir(parents=True)
project = "af-pacs-" + run.name[:12]
key = rsa.generate_private_key(public_exponent=65537, key_size=2048)
name = x509.Name([x509.NameAttribute(NameOID.COMMON_NAME, "PACS synthetic laboratory")])
now = datetime.datetime.now(datetime.timezone.utc)
cert = (x509.CertificateBuilder().subject_name(name).issuer_name(name).public_key(key.public_key())
        .serial_number(x509.random_serial_number()).not_valid_before(now-datetime.timedelta(minutes=5))
        .not_valid_after(now+datetime.timedelta(days=2))
        .add_extension(x509.SubjectAlternativeName([x509.DNSName("pacs.example.test"), x509.DNSName("localhost"), x509.IPAddress(ipaddress.ip_address("127.0.0.1"))]), critical=False)
        .add_extension(x509.BasicConstraints(ca=True,path_length=0), critical=True).sign(key,hashes.SHA256()))
pem = cert.public_bytes(serialization.Encoding.PEM)
(run/"ca.pem").write_bytes(pem)
(run/"server.pem").write_bytes(key.private_bytes(serialization.Encoding.PEM,serialization.PrivateFormat.PKCS8,serialization.NoEncryption())+pem)
password = secrets.token_urlsafe(32)
manifest={"marker":"ambulatoriofacile-pacs-synthetic-v1","project":project,"port":18443,"username":"lab","password":password,"run":str(run)}
(run/"manifest.json").write_text(json.dumps(manifest),encoding="utf-8")
config={"Name":"AF PACS SYNTHETIC ONLY","AuthenticationEnabled":True,"RegisteredUsers":{"lab":password},
        "RemoteAccessAllowed":True,"DicomServerEnabled":False,"SslEnabled":True,"SslCertificate":"/lab/server.pem",
        "DicomWeb":{"Enable":True,"Root":"/dicom-web/","StudiesMetadata":"Full","SeriesMetadata":"Full"},
        "StoneWebViewer":{}}
(run/"orthanc.json").write_text(json.dumps(config),encoding="utf-8")
compose={"services":{"orthanc":{"image":"orthancteam/orthanc@sha256:99082b87c96d56e57472d703ad799b779da7aa35aedac830d58cce646a43643f",
    "ports":["127.0.0.1:18443:8042"],"volumes":["./orthanc.json:/etc/orthanc/orthanc.json:ro","./server.pem:/lab/server.pem:ro"],
    "tmpfs":["/var/lib/orthanc/db:size=128m"],"mem_limit":"512m","cpus":1,
    "labels":{"af.lab":"pacs-synthetic","af.run":run.name},"environment":{"DICOM_WEB_PLUGIN_ENABLED":"true","STONE_WEB_VIEWER_PLUGIN_ENABLED":"true"}}}}
(run/"compose.json").write_text(json.dumps(compose),encoding="utf-8")
def element(group, tag, vr, value):
    if isinstance(value,str):
        value=value.encode("ascii")
        if len(value)%2: value+=b"\0" if vr=="UI" else b" "
    head=struct.pack("<HH",group,tag)+vr.encode()
    return head+(b"\0\0"+struct.pack("<I",len(value)) if vr in ("OB","OW","SQ","UN","UT") else struct.pack("<H",len(value)))+value
def dicom(patient, issuer, n):
    study="1.2.826.0.1.3680043.10.543.20260913."+str(n)
    series=study+".1"; instance=series+".1"; sop="1.2.840.10008.5.1.4.1.1.7"
    meta=b"".join([element(2,1,"OB",b"\0\1"),element(2,2,"UI",sop),element(2,3,"UI",instance),element(2,0x10,"UI","1.2.840.10008.1.2.1"),element(2,0x12,"UI","1.2.826.0.1.3680043.10.543")])
    fields=[(8,0x16,"UI",sop),(8,0x18,"UI",instance),(8,0x20,"DA","20260913"),(8,0x30,"TM","120000"),(8,0x50,"SH","SYNTHETIC-"+str(n)),(8,0x60,"CS","OT"),(8,0x1030,"LO","PACS synthetic interoperability test"),(0x10,0x10,"PN","SYNTHETIC^PATIENT"),(0x10,0x20,"LO",patient),(0x10,0x21,"LO",issuer),(0x10,0x30,"DA","19800101"),(0x20,0x0d,"UI",study),(0x20,0x0e,"UI",series),(0x20,0x11,"IS","1"),(0x20,0x13,"IS","1"),(0x28,2,"US",struct.pack("<H",1)),(0x28,4,"CS","MONOCHROME2"),(0x28,0x10,"US",struct.pack("<H",2)),(0x28,0x11,"US",struct.pack("<H",2)),(0x28,0x100,"US",struct.pack("<H",8)),(0x28,0x101,"US",struct.pack("<H",8)),(0x28,0x102,"US",struct.pack("<H",7)),(0x28,0x103,"US",struct.pack("<H",0)),(0x7fe0,0x10,"OB",bytes([0,85,170,255]))]
    data=bytes(128)+b"DICM"+element(2,0,"UL",struct.pack("<I",len(meta)))+meta+b"".join(element(*f) for f in fields)
    (run/("synthetic-"+str(n)+".dcm")).write_bytes(data)
    return {"patient":patient,"issuer":issuer,"study":study,"series":series,"instance":instance}
manifest["studies"]=[dicom("P-100","TEST-HOSPITAL",1),dicom("P-200","OTHER-HOSPITAL",2)]
(run/"manifest.json").write_text(json.dumps(manifest),encoding="utf-8")
print(json.dumps({"run":str(run),"project":project,"port":18443}))

import json, pathlib, struct, uuid, math, hashlib, sys, urllib.request, base64
from PIL import Image, ImageDraw
out=pathlib.Path(__file__).resolve().parents[2]/'rest/writable/pacs-complete-case'
request=json.loads((out/'request.json').read_text())
state=json.loads(pathlib.Path(sys.argv[1]).read_text())
expected='https://orthanc-j3le41pb3ym1f79lc52y271b.178.104.113.107.sslip.io'
assert state['marker']=='AF_PACS_CLOUD_SYNTHETIC_V1' and state['tenantId']==4 and state['baseUrl']==expected
manifest_path=out/'manifest.json'
manifest=json.loads(manifest_path.read_text()) if manifest_path.exists() else request | {'series':'2.25.'+str(uuid.uuid4().int),'instances':['2.25.'+str(uuid.uuid4().int) for _ in range(16)]}
assert manifest['study']==request['study'] and manifest['accession']==request['accession']
manifest_path.write_text(json.dumps(manifest,indent=2))
def element(g,t,vr,v):
    if isinstance(v,str):
        v=v.encode('ascii')
    if len(v)%2: v += b'\0' if vr in ('UI','OB','OW') else b' '
    h=struct.pack('<HH',g,t)+vr.encode()
    return h+(b'\0\0'+struct.pack('<I',len(v)) if vr in ('OB','OW','SQ','UN','UT') else struct.pack('<H',len(v)))+v

sop='1.2.840.10008.5.1.4.1.1.7'
for n,instance in enumerate(manifest['instances']):
    im=Image.new('L',(512,512),0)
    d=ImageDraw.Draw(im)
    scale=0.8+0.2*math.sin(math.pi*n/15)
    w,h=170*scale,192*scale
    d.ellipse((256-w,250-h,256+w,250+h),fill=195)
    d.ellipse((262-w,257-h,250+w,243+h),fill=75)
    d.ellipse((160,160+n*2,226,318-n),fill=26)
    d.ellipse((278,160+n,344,318-n*2),fill=32)
    d.ellipse((234,290,278,345),fill=235)
    for k in range(6):
        x=173+k*32
        d.ellipse((x,380,x+12+k,392+k),fill=45+k*32)
    d.rectangle((0,0,511,34),fill=0)
    d.text((12,10),'DEMO - PHANTOM SINTETICO - NON DIAGNOSTICO',fill=255)
    d.text((12,485),f'AMBULATORIOFACILE   IMMAGINE {n+1:02}/16   DATI INVENTATI',fill=255)
    meta=b''.join(element(*f) for f in [(2,1,'OB',b'\0\1'),(2,2,'UI',sop),(2,3,'UI',instance),(2,16,'UI','1.2.840.10008.1.2.1'),(2,18,'UI','2.25.20261001')])
    fields=[(8,8,'CS','DERIVED\\SECONDARY'),(8,22,'UI',sop),(8,24,'UI',instance),(8,32,'DA','20261002'),(8,48,'TM','100000'),(8,80,'SH',manifest['accession']),(8,96,'CS','OT'),(8,100,'CS','SYN'),(8,4144,'LO','DEMO - Phantom sintetico non diagnostico'),(8,4158,'LO','DEMO - 16 immagini sintetiche'),(16,16,'PN','DEMO^PACS COMPLETO'),(16,32,'LO',manifest['patient']),(16,33,'LO',manifest['issuer']),(16,48,'DA','19800101'),(32,13,'UI',manifest['study']),(32,14,'UI',manifest['series']),(32,17,'IS','1'),(32,19,'IS',str(n+1)),(40,2,'US',struct.pack('<H',1)),(40,4,'CS','MONOCHROME2'),(40,16,'US',struct.pack('<H',512)),(40,17,'US',struct.pack('<H',512)),(40,256,'US',struct.pack('<H',8)),(40,257,'US',struct.pack('<H',8)),(40,258,'US',struct.pack('<H',7)),(40,259,'US',struct.pack('<H',0)),(40,4176,'DS','128'),(40,4177,'DS','256'),(0x7fe0,16,'OB',im.tobytes())]
    (out/f'demo-{n+1:02}.dcm').write_bytes(bytes(128)+b'DICM'+element(2,0,'UL',struct.pack('<I',len(meta)))+meta+b''.join(element(*f) for f in fields))
    im.save(out/f'{n+1:02}.png')
print('Generated 16 synthetic DICOM images, 512x512; no patient data used.')

manifest['hashes']=[]
auth=base64.b64encode((state['username']+':'+state['password']).encode()).decode()
for n in range(16):
    data=(out/f'demo-{n+1:02}.dcm').read_bytes()
    manifest['hashes'].append(hashlib.sha256(data).hexdigest())
    req=urllib.request.Request(expected+'/instances',data=data,headers={'Authorization':'Basic '+auth,'Content-Type':'application/dicom'})
    with urllib.request.urlopen(req,timeout=40) as res:
        result=json.load(res)
        assert result['Status'] in ('Success','AlreadyStored')
manifest_path.write_text(json.dumps(manifest,indent=2))
print('Uploaded 16 matching synthetic instances to the fixed isolated PACS.')

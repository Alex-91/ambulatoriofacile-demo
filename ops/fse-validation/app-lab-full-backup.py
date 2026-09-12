"""Full backup drill, marked synthetic source and separate pristine loopback target."""
import importlib.util,json,os,re,shutil,socket,sys,zipfile
from pathlib import Path
repo=Path(__file__).resolve().parents[2];lab=Path(os.environ['FSE_LAB_ROOT']).resolve(strict=True)
if lab.parent!=(repo/'rest/writable/fse-app-labs').resolve() or not re.fullmatch('[a-f0-9]{32}',lab.name): raise RuntimeError('LAB_BOUNDARY')
settings=json.loads((lab/'lab.json').read_text());assert settings['mode']=='FSE_SYNTHETIC_APP_LAB'
try:
    with socket.create_connection(('127.0.0.1',8088),timeout=5): raise RuntimeError('Stop lab web server first.')
except ConnectionRefusedError: pass
spec=importlib.util.spec_from_file_location('fullbackup',repo/'ops/full-backup.py');backup=importlib.util.module_from_spec(spec);spec.loader.exec_module(backup)
root=lab/'full-backup-drill';root.mkdir()
mysqlbin=Path(settings['mysql_base'])/'bin'
base={'mysql_client':str(mysqlbin/'mysql.exe'),'mysqldump':str(mysqlbin/'mysqldump.exe')}
source={**base,'mysql':{'host':'127.0.0.1','port':33079,'user':'root','password':settings['password']}}
target={**base,'mysql':{'host':'127.0.0.1','port':33089,'user':'root','password':''}}
assert Path(backup.Mysql(source).query('SELECT @@datadir')[0]).resolve()==(lab/'mysql').resolve()
assert Path(backup.Mysql(target).query('SELECT @@datadir')[0]).resolve()==(lab/'restore-mysql').resolve()
agenda=json.loads((lab/'agenda-report.json').read_text());assert agenda['status']=='passed'
source['databases']=['fselab_platform','fselab_a','fselab_b',agenda['database']]
configdir=root/'runtime';configdir.mkdir();(configdir/'synthetic-runtime.json').write_text(json.dumps({'encryption_key':settings['secret_key'],'mode':'SYNTHETIC_ONLY'}));(configdir/'synthetic-runtime.json').chmod(0o600)
source['roots']={'application':str(repo/'rest/app'),'runtime':str(configdir),'writable':str(lab/'writable'),'composer':str(repo/'composer.lock')}
key=os.urandom(32);(root/'backup.key').write_bytes(key);(root/'backup.key').chmod(0o600)
archive=root/'snapshot.afbackup';result=backup.backup(source,archive,key)
report={'mode':'SYNTHETIC_FULL_BACKUP','status':'incomplete','backup':result,'checks':[]}
def rejected(op,name):
    try:op()
    except Exception:report['checks'].append(name);return
    raise RuntimeError(name)
try:
    rejected(lambda:backup.verify(archive,os.urandom(32)),'wrong_key_rejected')
    altered=root/'altered.afbackup'
    with zipfile.ZipFile(archive) as original,zipfile.ZipFile(altered,'x') as corrupted:
        for name in original.namelist():
            content=bytearray(original.read(name))
            if name=='payload/000000':content[len(content)//2]^=1
            corrupted.writestr(name,content)
    rejected(lambda:backup.restore(target,altered,key,root/'invalid-restore'),'altered_archive_rejected_before_restore')
    assert not (root/'invalid-restore').exists()
    rejected(lambda:backup.restore(source,archive,key,root/'source-restore'),'source_endpoint_restore_rejected')
    report['restore']=backup.restore(target,archive,key,root/'restored')
    rejected(lambda:backup.restore(target,archive,key,root/'second-restore'),'nonempty_target_rejected')
    report['checks']+=['all_table_counts_and_checksums_match','all_files_authenticated']
    report['status']='passed'
finally:
    (root/'report.json').write_text(json.dumps(report,indent=2))
    print(json.dumps({'status':report['status'],'report':str(root/'report.json')}))

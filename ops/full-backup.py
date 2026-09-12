"""Encrypted full-instance backup. Restore only onto an empty, separate MySQL instance.

Requires cryptography (also available in the document-validation environment).
Configuration is an operator-owned JSON file, never a web request. No credentials
are passed on argv or written into the archive unless explicitly included in a file root.
"""
import argparse, hashlib, json, os, re, shutil, struct, subprocess, tempfile, zipfile
from pathlib import Path, PurePosixPath
from cryptography.hazmat.primitives.ciphers.aead import AESGCM

CHUNK=1024*1024
MAGIC=b'AFBACKUP1\n'

def require(condition, message):
    if not condition: raise RuntimeError(message)

def read_key(path):
    value=Path(path).read_bytes();require(len(value)==32,'Backup key must be exactly 32 random bytes.');return value

def encrypt_stream(source, target, key, name):
    cipher=AESGCM(key);target.write(MAGIC);digest=hashlib.sha256();size=0;index=0
    while True:
        data=source.read(CHUNK);nonce=os.urandom(12)
        payload=cipher.encrypt(nonce,data,(name+':'+str(index)).encode())
        target.write(struct.pack('>I',len(payload))+nonce+payload)
        if not data: break
        digest.update(data);size+=len(data);index+=1
    return {'sha256':digest.hexdigest(),'size':size}

def decrypt_stream(source, target, key, name):
    require(source.read(len(MAGIC))==MAGIC,'Invalid encrypted entry.');cipher=AESGCM(key);digest=hashlib.sha256();size=0;index=0
    while True:
        length=source.read(4);require(len(length)==4,'Truncated encrypted entry.');length=struct.unpack('>I',length)[0]
        require(16<=length<=CHUNK+16,'Invalid chunk size.');nonce=source.read(12);payload=source.read(length)
        require(len(nonce)==12 and len(payload)==length,'Truncated encrypted chunk.')
        data=cipher.decrypt(nonce,payload,(name+':'+str(index)).encode())
        if not data:
            require(not source.read(1),'Unexpected trailing data.');break
        target.write(data);digest.update(data);size+=len(data);index+=1
    return {'sha256':digest.hexdigest(),'size':size}

class Mysql:
    def __init__(self, config): self.config=config
    def command(self, executable, args, **kwargs):
        db=self.config['mysql']
        require(isinstance(db['port'],int) and 0<db['port']<65536,'Invalid MySQL port.')
        # MySQL option-file quoting, independent from shell quoting.
        def quoted(value):
            value=str(value);require(not any(c in value for c in '\r\n\x00'),'Invalid connection setting.')
            return '"'+value.replace('\\','\\\\').replace('"','\\"')+'"'
        with tempfile.TemporaryDirectory(prefix='af-backup-') as directory:
            path=Path(directory)/'client.cnf'
            path.write_text('[client]\nprotocol=tcp\nhost='+quoted(db['host'])+'\nport='+str(db['port'])+'\nuser='+quoted(db['user'])+'\npassword='+quoted(db.get('password',''))+'\n',encoding='utf-8');path.chmod(0o600)
            # Keep the private option file alive until the subprocess finishes.
            result=subprocess.run([self.config[executable],'--defaults-extra-file='+str(path),*args],stderr=subprocess.PIPE,**kwargs)
        require(result.returncode==0,'MySQL operation failed; inspect the isolated server locally.');return result
    def query(self, sql):
        result=self.command('mysql_client',['--batch','--raw','--skip-column-names','--execute='+sql],stdout=subprocess.PIPE,timeout=120)
        return result.stdout.decode('utf-8').strip().splitlines()

def inventory(mysql, databases):
    result={}
    for database in databases:
        require(re.fullmatch('[A-Za-z0-9_]+',database),'Invalid database name.')
        tables=mysql.query("SELECT TABLE_NAME FROM information_schema.tables WHERE TABLE_SCHEMA='"+database+"' AND TABLE_TYPE='BASE TABLE' ORDER BY TABLE_NAME")
        result[database]={}
        for table in tables:
            require(re.fullmatch('[A-Za-z0-9_]+',table),'Unsupported table identifier.')
            count=int(mysql.query('SELECT COUNT(*) FROM `'+database+'`.`'+table+'`')[0])
            checksum=mysql.query('CHECKSUM TABLE `'+database+'`.`'+table+'` EXTENDED')[0].split('\t')[-1]
            require(checksum!='NULL','Table checksum unavailable.')
            result[database][table]={'count':count,'checksum':checksum}
    return result

def files_in_roots(roots):
    for alias, supplied in roots.items():
        require(re.fullmatch('[a-z][a-z0-9_-]{0,40}',alias),'Invalid root alias.')
        root=Path(supplied).absolute();require(root.exists() and not root.is_symlink(),'Missing root or symlink.')
        if root.is_file(): yield alias+'/'+root.name,root;continue
        for folder,dirs,files in os.walk(root,followlinks=False):
            for child in dirs+files: require(not (Path(folder)/child).is_symlink(),'Symlinks are not accepted in backup roots.')
            for name in sorted(files):
                path=Path(folder)/name;yield alias+'/'+path.relative_to(root).as_posix(),path

def backup(config, archive, key):
    require(not archive.exists(),'Archive already exists.')
    for root in config['roots'].values():
        path=Path(root).resolve(strict=True)
        require(archive.resolve()!=path and not (path.is_dir() and archive.resolve().is_relative_to(path)),'Backup destination overlaps a source root.')
    mysql=Mysql(config);databases=config['databases'];require(databases,'No databases configured.')
    before=inventory(mysql,databases)
    manifest={'version':1,'source_endpoint':[config['mysql']['host'],config['mysql']['port']],
              'databases':databases,'table_counts':before,'entries':{}}
    archive.parent.mkdir(parents=True,exist_ok=True)
    try:
        with zipfile.ZipFile(archive,'x',compression=zipfile.ZIP_STORED,allowZip64=True) as bundle:
            with tempfile.TemporaryDirectory(prefix='af-dump-') as directory:
                dump=Path(directory)/'database.sql'
                # Explicitly private; export is temporary and removed even on failure.
                with dump.open('xb') as out:
                    dump.chmod(0o600)
                    mysql.command('mysqldump',['--single-transaction','--routines','--triggers','--events','--hex-blob','--set-gtid-purged=OFF','--databases',*databases],stdout=out,timeout=3600)
                with dump.open('rb') as source,bundle.open('payload/000000','w',force_zip64=True) as target:
                    manifest['entries']['payload/000000']={'kind':'database',**encrypt_stream(source,target,key,'payload/000000')}
            index=1
            for relative,path in files_in_roots(config['roots']):
                require(archive.resolve()!=path.resolve() and not archive.resolve().is_relative_to(path.resolve()),'Backup destination overlaps source.')
                name=f'payload/{index:06d}';stat=path.stat()
                with path.open('rb') as source,bundle.open(name,'w',force_zip64=True) as target:
                    manifest['entries'][name]={'kind':'file','path':relative,**encrypt_stream(source,target,key,name)}
                require((stat.st_size,stat.st_mtime_ns)==(path.stat().st_size,path.stat().st_mtime_ns),'Source changed during backup.')
                index+=1
            require(before==inventory(mysql,databases),'Database changed during backup. Stop application writers first.')
            import io
            with bundle.open('manifest.enc','w') as target: encrypt_stream(io.BytesIO(json.dumps(manifest).encode()),target,key,'manifest.enc')
        archive.chmod(0o600)
        verify(archive,key)
    except Exception:
        # Retain the incomplete artifact for diagnosis, never report it as usable.
        raise
    return {'status':'verified','databases':len(databases),'files':index-1}

def manifest_from(bundle,key):
    import io
    require(bundle.getinfo('manifest.enc').file_size<8*1024*1024,'Manifest too large.')
    output=io.BytesIO()
    with bundle.open('manifest.enc') as source: decrypt_stream(source,output,key,'manifest.enc')
    manifest=json.loads(output.getvalue());require(manifest['version']==1,'Unsupported backup version.')
    names=list(bundle.namelist());require(len(names)==len(set(names)) and set(names)==set(manifest['entries'])|{'manifest.enc'},'Archive entry mismatch.')
    require(sum(item['kind']=='database' for item in manifest['entries'].values())==1,'Expected one database snapshot.')
    return manifest

def verify(archive,key):
    with zipfile.ZipFile(archive) as bundle,open(os.devnull,'wb') as sink:
        manifest=manifest_from(bundle,key)
        for name,info in manifest['entries'].items():
            require(re.fullmatch('payload/[0-9]{6}',name),'Invalid entry name.')
            with bundle.open(name) as source: actual=decrypt_stream(source,sink,key,name)
            require(actual=={'sha256':info['sha256'],'size':info['size']},'Backup content mismatch.')
    return manifest

def restore(config,archive,key,destination):
    # Verify every byte before touching the destination database or extracting files.
    manifest=verify(archive,key);mysql=Mysql(config)
    require([config['mysql']['host'],config['mysql']['port']]!=manifest['source_endpoint'],'Restore endpoint must differ from source.')
    databases=mysql.query('SHOW DATABASES')
    require(not set(databases)-{'information_schema','mysql','performance_schema','sys'},'Restore requires a pristine, isolated MySQL instance.')
    require(not destination.exists(),'Restore file directory must be new.')
    destination.mkdir(parents=True,mode=0o700)
    with zipfile.ZipFile(archive) as bundle,tempfile.TemporaryDirectory(prefix='af-restore-') as private:
        dump=Path(private)/'database.sql'
        for name,info in manifest['entries'].items():
            if info['kind']=='database': target=dump
            else:
                rel=PurePosixPath(info['path'])
                require(not rel.is_absolute() and '..' not in rel.parts and '\\' not in str(rel) and ':' not in str(rel),'Unsafe restore path.')
                target=destination.joinpath(*rel.parts)
                require(target.resolve().is_relative_to(destination.resolve()),'Restore path escaped destination.')
            target.parent.mkdir(parents=True,exist_ok=True,mode=0o700)
            with bundle.open(name) as source,target.open('xb') as out:
                target.chmod(0o600);decrypt_stream(source,out,key,name)
        with dump.open('rb') as source: mysql.command('mysql_client',['--binary-mode','--local-infile=0'],stdin=source,stdout=subprocess.DEVNULL,timeout=3600)
    require(manifest['table_counts']==inventory(mysql,manifest['databases']),'Restored table inventory differs.')
    return {'status':'restored_verified','databases':len(manifest['databases']),'destination':str(destination)}

if __name__=='__main__':
    parser=argparse.ArgumentParser(description=__doc__)
    parser.add_argument('action',choices=['backup','verify','restore']);parser.add_argument('--config');parser.add_argument('--archive',required=True);parser.add_argument('--key-file',required=True)
    parser.add_argument('--source-stopped',action='store_true',help='Confirm app, jobs and upload writers are stopped. MySQL remains running.')
    parser.add_argument('--destination');args=parser.parse_args()
    try:
        key=read_key(args.key_file);archive=Path(args.archive).absolute()
        if args.action=='verify': verify(archive,key);result={'status':'verified'}
        else:
            config=json.loads(Path(args.config).read_text(encoding='utf-8-sig'))
            if args.action=='backup': require(args.source_stopped,'Stop application writers and supply --source-stopped.');result=backup(config,archive,key)
            else: require(args.destination,'New destination required.');result=restore(config,archive,key,Path(args.destination).absolute())
        print(json.dumps(result))
    except Exception as error:
        # No raw DB diagnostics, credentials, SQL or data enter terminal logs.
        print(json.dumps({'status':'failed','reason':str(error) if isinstance(error,RuntimeError) else type(error).__name__}));raise SystemExit(1)

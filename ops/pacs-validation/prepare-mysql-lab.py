"""Fresh synthetic MySQL, no published ports, private random credentials."""
import json, pathlib, secrets, uuid
repo = pathlib.Path(__file__).resolve().parents[2]
run = repo / "rest/writable/pacs-mysql-labs" / uuid.uuid4().hex
run.mkdir(parents=True)
project = "af-pacs-mysql-" + run.name[:12]
server_id = secrets.randbelow(2000000000) + 1000
manifest = {"marker":"af-pacs-mysql-synthetic-v1","database":"af_pacs_synthetic",
            "password":secrets.token_hex(32),"crypto_key":secrets.token_hex(32),
            "server_id":server_id,"project":project}
(run/"manifest.json").write_text(json.dumps(manifest))
(run/"mysql.env").write_text("MYSQL_ROOT_PASSWORD="+manifest["password"]+"\nMYSQL_DATABASE=af_pacs_synthetic\n")
compose={"services":{"mysql":{"image":"mysql@sha256:8c19b656bb381f163750b238852bd377ba5764e1ec30cdd3f02e55cf8e2f89b7",
    "network_mode":"none","env_file":["./mysql.env"],
    "command":["--server-id="+str(server_id),"--innodb-buffer-pool-size=64M","--max-connections=32","--skip-log-bin"],
    "tmpfs":["/var/lib/mysql:size=512m"],"mem_limit":"768m","cpus":1,
    "labels":{"af.lab":"pacs-mysql-synthetic","af.run":run.name}}}}
(run/"compose.json").write_text(json.dumps(compose))
print(json.dumps({"run":str(run),"project":project}))

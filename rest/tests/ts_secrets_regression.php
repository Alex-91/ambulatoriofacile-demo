<?php
// Standalone, synthetic secrets only; never load application configuration or .env.
require __DIR__.'/ts_vat_regression.php';
$cryptoConfig=(object)['keyHex'=>''];$encryptionConfig=(object)['key'=>''];$testEnv=[];
function config($class) {global $cryptoConfig,$encryptionConfig;return str_contains($class,'Crypto')?$cryptoConfig:$encryptionConfig;}
function env($key,$default='') {global $testEnv;return $testEnv[$key]??$default;}
$old=[];foreach(['database.platform.DB_ENCRYPTION_KEY','database.default.DB_ENCRYPTION_KEY'] as $key){$old[$key]=getenv($key);putenv($key);}
function encryptedFixture(string $key): string {
 $iv=str_repeat('i',12);$tag='';$cipher=openssl_encrypt('SYNTHETIC-PIN','aes-256-gcm',$key,OPENSSL_RAW_DATA,$iv,$tag,'',16);
 return 'tssec:v1:'.base64_encode($iv.$tag.$cipher);
}
try {
 $cryptoConfig->keyHex=bin2hex('old-crypto-key');$testEnv=['TS_BILLING_SECRET_KEY'=>'other'];
 check((new App\Services\TsSecretsService())->decrypt(encryptedFixture(hash('sha256','old-crypto-key',true)))==='SYNTHETIC-PIN','Legacy crypto key priority must be unchanged');
 $cryptoConfig->keyHex='';
 foreach(['TS_BILLING_SECRET_KEY','database.platform.DB_ENCRYPTION_KEY','database.default.DB_ENCRYPTION_KEY','DB_ENCRYPTION_KEY'] as $key){
  $testEnv=[$key=>'synthetic-env-only-key'];$secrets=new App\Services\TsSecretsService();
  check($secrets->decrypt($secrets->encrypt('SYNTHETIC-PIN'))==='SYNTHETIC-PIN','Environment-only fallback must roundtrip: '.$key);
 }
 $testEnv=['DB_ENCRYPTION_KEY'=>'new-fallback'];putenv('database.platform.DB_ENCRYPTION_KEY=legacy-process-key');
 check((new App\Services\TsSecretsService())->decrypt(encryptedFixture(hash('sha256','legacy-process-key',true)))==='SYNTHETIC-PIN','Existing process key must precede new fallbacks');
 echo "PASS: legacy ciphertext, key precedence and environment-only fallbacks\n";
}finally{foreach($old as $key=>$value)putenv($value===false?$key:$key.'='.$value);}

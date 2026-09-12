<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__.'/app-lab-common.php';
$lab=fse_lab_config();
$db=new mysqli('127.0.0.1','root',$lab['password'],'',33079);
if (realpath($db->query('SELECT @@datadir AS path')->fetch_assoc()['path']) !== realpath($lab['root'].'/mysql')) throw new RuntimeException('Wrong MySQL instance: refusing shutdown.');
$db->query('SHUTDOWN');
echo "Only the isolated synthetic MySQL instance was stopped. Files retained.\n";

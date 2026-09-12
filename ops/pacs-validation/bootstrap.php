<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$repo=dirname(__DIR__,2);
$base=PHP_OS_FAMILY==='Linux' ? sys_get_temp_dir().'/af-pacs-tests' : $repo.'/rest/writable/pacs-tests';
$run=$base.'/run-'.bin2hex(random_bytes(8)).'/';
foreach (['','cache','logs','session'] as $dir) if (!is_dir($run.$dir)) mkdir($run.$dir,0700,true);
define('WRITEPATH',$run);
require $repo.'/rest/vendor/autoload.php';
require $repo.'/vendor/autoload.php';
require $repo.'/ops/fse-validation/php-bootstrap.php';

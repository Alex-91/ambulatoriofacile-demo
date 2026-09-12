<?php
// Isolated image only; synthetic SQLite tests, no dotenv or external transports.
if(PHP_SAPI!=='cli' || PHP_OS_FAMILY!=='Linux' || !is_file('/opt/fse/linux-lab-image')) throw new RuntimeException('Synthetic Linux image required.');
$lab=getenv('FSE_LAB_ROOT');
if(!$lab || !is_file($lab.'/lab.json')) throw new RuntimeException('Initialized Linux lab required.');
$marker=json_decode(file_get_contents($lab.'/lab.json'),true,flags:JSON_THROW_ON_ERROR);
if(($marker['mode'] ?? '')!=='FSE_SYNTHETIC_APP_LAB' || ($marker['runtime'] ?? '')!=='linux-container') throw new RuntimeException('Synthetic Linux boundary.');
$run=$lab.'/unit-'.bin2hex(random_bytes(8));
foreach ([$run,$run.'/cache',$run.'/logs',$run.'/session'] as $directory) {
    if (!mkdir($directory,0700)) throw new RuntimeException('Cannot initialize synthetic test writable directory.');
}
define('WRITEPATH',$run.'/');
require_once __DIR__.'/../../rest/vendor/autoload.php';
require_once __DIR__.'/../../vendor/autoload.php';
require __DIR__.'/php-bootstrap.php';

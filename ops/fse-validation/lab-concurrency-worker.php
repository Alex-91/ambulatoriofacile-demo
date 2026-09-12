<?php
// Fixed synthetic fixture only. No CI boot, .env, clinical DB, HTTP or credentials.
if (PHP_SAPI!=='cli') exit(1);
$base = realpath(__DIR__.'/../../rest/writable');
$write = realpath($argv[5] ?? $base);
$normalizedBase = str_replace('\\', '/', $base);
$normalizedWrite = str_replace('\\', '/', $write ?: '');
$linuxUnit = false;
if (PHP_OS_FAMILY === 'Linux' && is_file('/opt/fse/linux-lab-image')
    && preg_match('#^'.preg_quote($normalizedBase,'#').'/fse-app-labs/[a-f0-9]{32}/unit-[a-f0-9]{16}$#D', $normalizedWrite)) {
    $lab = dirname($write);
    $marker = json_decode((string) @file_get_contents($lab.'/lab.json'), true);
    $linuxUnit = realpath((string) getenv('FSE_LAB_ROOT')) === $lab
        && ($marker['mode'] ?? '') === 'FSE_SYNTHETIC_APP_LAB'
        && ($marker['runtime'] ?? '') === 'linux-container';
}
if (!$write || ($write !== $base && !preg_match('#^' . preg_quote($normalizedBase, '#')
    . '/fse-framework-compat/[a-f0-9]{32}/run-(?:baseline|candidate)-[a-f0-9]{16}/runtime$#D', $normalizedWrite) && !$linuxUnit)) exit(1);
define('WRITEPATH', $write . '/');
require __DIR__.'/../../rest/app/Services/FseToscanaWorkflow.php';
require __DIR__.'/../../rest/app/Services/FseToscanaLabStore.php';
[$script,$name,$tenant,$actor,$mode]=array_pad($argv,5,'');
if (!preg_match('/^fse-workflow-tests-[a-f0-9]{16}$/D',$name) || !ctype_digit($tenant) || !ctype_digit($actor)
    || !in_array($mode,['race','interrupted'],true)) exit(1);
$root=WRITEPATH.$name;
$store=new \App\Services\FseToscanaLabStore($root);
if ($mode==='race') {
    file_put_contents($root.'/ready-'.$actor,'ready');
    $deadline=microtime(true)+10;
    while (!is_file($root.'/go')) {
        clearstatcache();
        if (microtime(true)>$deadline) exit(3);
        usleep(5000);
    }
}
try {
    $state=$store->command((int)$tenant,(int)$actor,['command'=>'begin_create','document'=>'SIM.1','revision'=>2,
        'profile'=>((int)$tenant%2===0 ? 'SITE_A_PRIVATE' : 'SITE_B_SSR')]);
    if ($mode==='interrupted') exit(77); // Request saved; worker interrupted before a response.
    echo json_encode(['tenant'=>(int)$tenant,'actor'=>(int)$actor,'result'=>'accepted']);
} catch (\RuntimeException $e) {
    echo json_encode(['tenant'=>(int)$tenant,'actor'=>(int)$actor,'result'=>in_array($e->getMessage(),['LAB_BUSY','LAB_STALE'],true) ? 'blocked' : 'unexpected']);
}

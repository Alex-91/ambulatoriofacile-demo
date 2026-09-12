<?php
// Never reachable as a normal deployed web endpoint; no authentication bypass or test routes in app code.
if (PHP_SAPI !== 'cli-server' || !in_array($_SERVER['REMOTE_ADDR']??'', ['127.0.0.1','::1'], true)
    || ($_SERVER['HTTP_HOST']??'') !== '127.0.0.1:8088') { http_response_code(404); exit; }
require __DIR__.'/app-lab-common.php';
$lab=fse_lab_config();
$path = rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
if (str_starts_with($path,'/public/')) {
    $root = realpath(__DIR__.'/../../public'); $file=realpath(__DIR__.'/../..'.$path);
    if (!$root || !$file || !str_starts_with($file,$root.DIRECTORY_SEPARATOR) || !is_file($file)
        || !in_array(strtolower(pathinfo($file,PATHINFO_EXTENSION)),['js','css','png','jpg','jpeg','gif','svg','woff','woff2','ttf','ico','webp'],true)) { http_response_code(404); exit; }
    $types=['css'=>'text/css','js'=>'application/javascript','svg'=>'image/svg+xml'];
    header('Content-Type: '.($types[strtolower(pathinfo($file,PATHINFO_EXTENSION))]??mime_content_type($file))); readfile($file); exit;
}
if (in_array($path,['/','/agenda','/admin','/app'],true)) { header('Location: /admin/fse2'); exit; }
$billing = is_file($lab['root'].'/billing-seeded.json')
    && preg_match('#^/admin/fatturazione-documenti(?:/(?:nuovo|save|(?:modifica|preview|pdf|pagamento)/[1-9][0-9]*))?$#D', $path);
// Explicit local allowlist: no email, bulk/individual TS send, settings or arbitrary legacy endpoints.
if ($billing && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'
    && (!in_array($_POST['save_mode'] ?? 'draft', ['draft', 'final'], true) || ((string) ($_POST['ts_sync_enabled'] ?? '0') !== '0' && !is_file($lab['root'].'/journey-seeded.json')))) {
    http_response_code(403); exit;
}
$clinical = is_file($lab['root'].'/clinical-seeded.json') && preg_match('#^/cartella-clinica/pazienti/[1-9][0-9]*(?:/(?:salva|consensi|modelli|referti-fse/[1-9][0-9]*|allegati(?:/[a-f0-9]{32})?|documenti/[1-9][0-9]*/(?:finalizza|firma)))?$#D',$path);
$journey = is_file($lab['root'].'/journey-seeded.json') && preg_match('#^/agenda/(?:gestione-pazienti|lista-pazienti|salva-paziente-gestione|verifica-codice-fiscale|salva-paziente|lock-slot|unlock-slot|salva-appuntamento|aggiorna-appuntamento|elimina-appuntamento|paziente/[1-9][0-9]*|fatturazione-da-appuntamento/[1-9][0-9]*)$#D',$path);
if (!$clinical && !$billing && !$journey && !preg_match('#^/(login(?:/tenant-select)?|tenant-select|logout|admin/fse2(?:/.*)?|(?:login/)?spazio/fse2(?:/.*)?)$#D',$path)) { http_response_code(404); exit; }
header('X-FSE-Test-Environment: synthetic-only');
header('X-FSE-Lab-Id: '.basename($lab['root']));
header('Cache-Control: no-store');
fse_lab_boot(true);

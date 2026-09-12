<?php
// Synthetic view harness. Does not bootstrap CodeIgniter, sessions, .env or any database.
// Router: php -S 127.0.0.1:8087 ops/fse-validation/ui-preview.php
// CLI: php ops/fse-validation/ui-preview.php dashboard|revision|signed|offline
$repo = dirname(__DIR__, 2);
if (!in_array(PHP_SAPI, ['cli', 'cli-server'], true)) { http_response_code(404); exit; }
if (PHP_SAPI === 'cli-server') {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') { http_response_code(405); exit; }
    $route = rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/');
    if (str_starts_with($route, '/public/')) {
        $root = realpath($repo . '/public'); $asset = realpath($repo . $route);
        $types = ['css'=>'text/css', 'js'=>'application/javascript', 'woff'=>'font/woff', 'woff2'=>'font/woff2', 'ttf'=>'font/ttf', 'png'=>'image/png', 'jpg'=>'image/jpeg'];
        $ext = pathinfo((string) $asset, PATHINFO_EXTENSION);
        if (!$root || !$asset || !str_starts_with($asset, $root . DIRECTORY_SEPARATOR) || !isset($types[$ext]) || !is_file($asset)) { http_response_code(404); exit; }
        header('Content-Type: ' . $types[$ext]); readfile($asset); exit;
    }
    $name = trim($route, '/') ?: 'dashboard';
} else {
    $name = $argv[1] ?? 'dashboard';
}
if (!in_array($name, ['dashboard','revision','signed','signed_lab','offline','new','rejected','timeout','toscana_lab','toscana_timeout','toscana_snapshot'], true)) { http_response_code(404); exit(1); }
require $repo . '/rest/app/Services/FseDocumentLifecycle.php';
require $repo . '/rest/app/Services/FseOfflineLab.php';
require $repo . '/rest/app/Services/FseToscanaSimulation.php';
require $repo . '/rest/app/Services/FseReconciliationService.php';
require $repo . '/rest/app/Services/FseGatewayFeedback.php';
require $repo . '/rest/app/Services/FseToscanaWorkflow.php';
function esc($value): string { return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function base_url($path): string { return '/' . ltrim($path, '/'); }
function site_url($path): string { return '/disabled-preview-action'; }
function portal_tenant_space_url($path): string { return '/disabled-preview-action'; }
function old($key) { return null; }
function csrf_field(): string { return '<input type="hidden" name="csrf_test" value="SYNTHETIC">'; }
function view($name, $data): string {
    if ($name === 'partials/header') return '<header style="background:#176b76;color:white;padding:18px">Ambulatorio Facile · ANTEPRIMA SINTETICA · nessun database collegato</header>';
    return '<nav style="padding:18px"><p><a href="/dashboard">Prontezza FSE</a></p><p><a href="/revision">Correzione locale</a></p><p><a href="/signed">Originale firmato</a></p><p><a href="/offline">Collaudo simulato</a></p></nav>';
}
$success = null; $warning = null; $errors = []; $menu_items = [];
$profile = ['environment'=>'test', 'profile_name'=>'Studio fittizio Toscana', 'access_mode'=>'toscana_privati', 'is_enabled'=>0];
$dashboard = ['table_available'=>true, 'total'=>2, 'by_state'=>['draft'=>1, 'signed'=>1]];
$readiness = ['message'=>'Controlli locali sintetici. Accessi ufficiali non configurati.', 'checks'=>[
    ['label'=>'Runtime documentale','status'=>'ok','message'=>'Runtime locale disponibile'],
    ['label'=>'Autotest CDA / PDF/A-3b','status'=>'ok','message'=>'Verificato su documento fittizio. Non è un collaudo ufficiale.'],
    ['label'=>'Firma e certificati','status'=>'error','message'=>'Da configurare: firma clinica e certificati Gateway sono distinti.'],
    ['label'=>'Accreditamenti e abilitazioni','status'=>'warning','message'=>'In attesa di conferme ufficiali. Nessun invio abilitato.'],
]];
$signed = in_array($name, ['signed','signed_lab'], true);
$syntheticAppLab = $name === 'signed_lab';
$doc = array_merge(require $repo . '/rest/tests/_support/fse_synthetic.php', [
    'id_fse_document'=>$signed ? 1 : 2, 'version_number'=>$signed ? 1 : 2, 'local_state'=>$signed ? 'signed' : 'draft',
    'previous_document_id'=>$signed ? null : 1, 'revision_reason'=>'Correzione sintetica per verificare lo storico. Nessun dato reale.',
    'edit_token'=>'SYNTHETIC', 'signed_pdf_path'=>$signed ? 'NOT_A_REAL_FILE' : null,
]);
$formContext = ['document'=>$doc, 'profile'=>$profile, 'events'=>[], 'can_revise'=>$signed,
    'state_labels'=>['draft'=>'Bozza','signed'=>'Firmato'], 'administrative_requests'=>['NOSSN'=>'Privato / non SSN'],
    'history'=>[['id_fse_document'=>1,'version_number'=>1,'local_state'=>'signed'],['id_fse_document'=>2,'version_number'=>2,'local_state'=>'draft']],
    'diagnosis'=>(new \App\Services\FseReconciliationService())->inspect($doc)];
$report = (new \App\Services\FseOfflineLab())->run();
if ($name === 'new') {
    $formContext['document']=[];
    $formContext['profile']['id_fse_profile']=9;
    $formContext['profiles']=[['id_fse_profile'=>9,'profile_name'=>'Sede <test>','site_code'=>'SITE','care_regime'=>'SSR']];
    $formContext['administrative_requests']=['NOSSN'=>'Privato','SSR'=>'SSR'];
}
if (in_array($name,['rejected','timeout'],true)) {
    $uncertain=$name==='timeout';
    $feedback=\App\Services\FseGatewayFeedback::describe(['ok'=>false,'http_status'=>$uncertain ? 408 : 403,'outcome_uncertain'=>$uncertain],'validation');
    $formContext['profile']['access_mode']='gateway';
    $formContext['document']=array_replace($doc,['previous_document_id'=>null,'local_state'=>$uncertain ? 'validating' : 'rejected',
        'workflow_instance_id'=>null,'gateway_http_status'=>$uncertain ? 408 : 403,'gateway_state'=>$uncertain ? 'VALIDATION_UNCERTAIN' : 'validation_failed',
        'last_gateway_message'=>$feedback['message']]);
    $formContext['diagnosis']=(new \App\Services\FseReconciliationService())->inspect($formContext['document']);
    if ($uncertain) $warning=$feedback['message']; else $errors=['generic'=>$feedback['message']];
}
$file = in_array($name, ['revision','signed','signed_lab','new','rejected','timeout'], true) ? 'document_form' : $name;
if (in_array($name,['toscana_lab','toscana_timeout'],true)) {
    $engine=new \App\Services\FseToscanaWorkflow();
    $lab=$engine->apply($engine->initial(42),42,9,'new','',0);
    foreach (['prepare','sign','begin_create'] as $action) {
        $lab=$engine->apply($lab,42,9,$action,'SIM.1',$lab['documents']['SIM.1']['revision']);
    }
    $doc=$lab['documents']['SIM.1'];
    $lab=$engine->apply($lab,42,9,$name==='toscana_timeout' ? 'timeout' : 'accepted','SIM.1',$doc['revision'],$doc['profile'],$doc['pending']);
    $file='toscana_lab';
}
if ($name==='toscana_snapshot') {
    $engine=new \App\Services\FseToscanaWorkflow();
    $lab=$engine->importSignedSnapshot($engine->initial(42),42,9,['document_id'=>1,'previous_id'=>0,'version'=>1,
        'profile_sha256'=>str_repeat('a',64),'set_sha256'=>str_repeat('b',64),'cda_sha256'=>str_repeat('c',64),'signed_pdf_sha256'=>str_repeat('d',64)]);
    foreach (['begin_create','accepted','confirm_ok'] as $action) {
        $doc=$lab['documents']['SIM.1']; $op=$lab['operations'][$doc['pending'] ?? ''] ?? [];
        $lab=$engine->apply($lab,42,9,$action,'SIM.1',$doc['revision'],$doc['profile'],$op['id'] ?? '',$op['workflow'] ?? '');
    }
    $file='toscana_lab';
}
require $repo . '/rest/app/Views/admin/fse/' . $file . '.php';

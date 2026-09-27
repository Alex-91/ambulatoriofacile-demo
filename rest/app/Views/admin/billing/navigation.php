<?php
$billingSpace=(int)($tenantScope['tenant_id']??0);
$billingTsEnabled=!empty($navigation['ts_enabled']);
$billingOptions=$navigation['capabilities']??[]; $billingArchive=!empty($navigation['unified'])?['preview'=>true]:[]; $hasPreviousArchive=false;
if ($billingSpace>0) {
    try {
        $billingOptions=\App\Services\BillingCapabilities::resolve($billingSpace);
        $billingTsEnabled=(new \App\Services\TsFeatureService())->isEnabledForTenant($billingSpace);
        $billingDb=(new \App\Services\BillingTenantDatabaseContextService())->resolveTenantContext($billingSpace)['db'];
        $billingArchive=\App\Services\UnifiedBillingArchive::state($billingDb);
        $hasPreviousArchive=!$billingArchive && $billingDb->tableExists('pc_documents') && (new \App\Services\PolyclinicFeatureService())->isEnabledForTenant($billingSpace);
    } catch (\Throwable $e) { log_message('error','Billing navigation: '.$e->getMessage()); }
}
$billingTabs=['Documenti'=>'admin/fatturazione-documenti','Incassi'=>'admin/fatturazione-scadenzario'];
if (array_filter($billingOptions)) $billingTabs['Accettazione']='admin/fatturazione/gestione?tab=accettazione';
if (!empty($billingOptions['billing_services'])) {
    $billingTabs['Prestazioni e listini']='admin/fatturazione/gestione?tab=catalogo&kind=service';
}
if (!empty($billingOptions['billing_agreements'])) $billingTabs['Convenzioni']='admin/fatturazione/gestione?tab=catalogo&kind=agreement';
if (!empty($billingOptions['billing_compensation'])) $billingTabs['Compensi']='admin/fatturazione/gestione?tab=catalogo&kind=rule';
$billingTabs['Report']='admin/fatturazione-statistiche';
if ($billingArchive) $billingTabs['Collegamenti']='admin/fatturazione/gestione?tab=integrazioni';
if (!empty($billingTsEnabled)) $billingTabs['Sistema TS']='admin/sistema-ts/documenti';
$billingTabs['Modello documento']='admin/fatturazione-documento';
if (!isset($activeBillingTab)) {
    $billingCurrentPath=trim(service('uri')->getPath(),'/');
    foreach ($billingTabs as $label=>$url) {
        $targetPath=trim((string)parse_url(site_url($url),PHP_URL_PATH),'/');
        parse_str((string)parse_url($url,PHP_URL_QUERY),$targetQuery);
        $matches=$billingCurrentPath===$targetPath || str_starts_with($billingCurrentPath,$targetPath.'/');
        foreach ($targetQuery as $key=>$value) $matches=$matches && (string)(service('request')->getGet($key)??($key==='kind'?'service':''))===$value;
        if ($matches) $activeBillingTab=$label;
    }
}
if (($billingNavigationLayout??'')==='sidebar'): ?>
<nav aria-label="Sezioni fatturazione"><ul class="nav nav-pills nav-stacked">
<?php foreach($billingTabs as $label=>$url): $selected=($activeBillingTab??'')===$label; ?>
<li class="<?= $selected?'active':'' ?>"><a <?= $selected?'aria-current="page"':'' ?> href="<?= site_url($url) ?>"><?= esc($label) ?></a></li>
<?php endforeach ?></ul></nav>
<?php else: ?>
<nav class="bw-tabs" aria-label="Fatturazione" style="display:flex;flex-wrap:wrap;gap:8px;margin:12px 0 20px">
<?php foreach($billingTabs as $label=>$url): ?><a class="btn btn-default" <?= ($activeBillingTab??'')===$label?'aria-current="page"':'' ?> href="<?= site_url($url) ?>"><?= esc($label) ?></a><?php endforeach ?>
</nav>
<?php endif ?>
<?php if($hasPreviousArchive && ($billingNavigationLayout??'')!=='sidebar'): ?><div class="alert alert-info">Lo spazio contiene documenti nell’archivio precedente. L’unificazione deve essere completata prima di usare le funzioni integrate. <a href="<?= site_url('admin/fatturazione-poliambulatori?tab=documenti') ?>">Consulta i documenti precedenti</a>.</div><?php endif ?>

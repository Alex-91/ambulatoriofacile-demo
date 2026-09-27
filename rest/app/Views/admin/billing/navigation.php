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
if ($billingArchive) $billingTabs['Commercialista e XML']='admin/fatturazione/gestione?tab=integrazioni';
if (!empty($billingTsEnabled)) $billingTabs['Sistema TS']='admin/sistema-ts/documenti';
?>
<nav aria-label="Fatturazione" style="display:flex;flex-wrap:wrap;gap:8px;margin:12px 0 20px;padding:12px;background:#fff;border:1px solid #dce5eb;border-radius:8px">
<?php foreach($billingTabs as $label=>$url): ?><a class="btn btn-default" href="<?= site_url($url) ?>"><?= esc($label) ?></a><?php endforeach ?>
</nav>
<?php if($hasPreviousArchive): ?><div class="alert alert-info">Lo spazio contiene documenti nell’archivio precedente. L’unificazione deve essere completata prima di usare le funzioni integrate. <a href="<?= site_url('admin/fatturazione-poliambulatori?tab=documenti') ?>">Consulta i documenti precedenti</a>.</div><?php endif ?>

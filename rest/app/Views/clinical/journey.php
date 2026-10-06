<?php
$a=$state['a'];$r=$state['row'];$report=$state['report'];$doctor=$state['doctor'];$patient=$state['patient'];
$patientId=(int)$a['id_client'];$base=site_url('cartella-clinica/esame/'.$id);$chart=site_url('cartella-clinica/pazienti/'.$patientId);
$labels=\App\Services\ClinicalJourneyService::STAGES;
$active=$r['delivery_simulated_at']?5:($r['signature_simulated_at']?5:($report && $report['state']==='final'?4:($r['stage']==='performed'?3:($r['stage']==='booked'?0:($r['stage']==='accepted'?1:2)))));
$button=static function(string $action,string $label) use($r,$report,$base) { ?>
<form method="post" action="<?= esc($base,'attr') ?>"><?= csrf_field() ?><input type="hidden" name="revision" value="<?= (int)$r['revision'] ?>"><input type="hidden" name="entry_revision" value="<?= (int)($report['revision']??0) ?>"><button class="btn btn-primary" name="action" value="<?= esc($action,'attr') ?>"><?= esc($label) ?></button></form>
<?php };
?>
<!doctype html><html lang="it"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Scheda esame | AmbulatorioFacile</title>
<?= view('partials/operational_workspace_assets') ?>
<style>.clinical-section{font:15px/1.55 system-ui;color:#193345;padding:24px}.clinical-section .journey-panel{background:#fff;border:1px solid #dce5e9;border-radius:12px;padding:24px;margin:18px 0}.clinical-section h1{font-size:28px}.clinical-section h2{font-size:20px}.clinical-section .journey-context,.clinical-section .journey-track,.clinical-section .journey-actions{display:flex;gap:16px;flex-wrap:wrap}.clinical-section .journey-context>div{min-width:130px}.clinical-section small{display:block;color:#576c78}.clinical-section .journey-track span{padding:8px 12px;background:#edf1f5;border-radius:8px}.clinical-section .journey-track .active{background:#d9f0f2;color:#086f75;font-weight:700}.clinical-section textarea{width:100%;min-height:220px;border:1px solid #afc2ca;border-radius:8px;padding:16px;font:inherit}.clinical-section .journey-warning{padding:14px;background:#fff3cd;border-radius:8px;margin:14px 0}.clinical-section .journey-paper{white-space:pre-wrap;border:1px solid #dce5e9;padding:24px;margin-bottom:18px}.clinical-section .journey-history{padding:8px 0;border-bottom:1px solid #dce5e9}.clinical-section .btn{white-space:normal}@media(max-width:600px){.clinical-section{padding:12px}.clinical-section .journey-panel{padding:16px}}</style>
<?= view('partials/operational_workspace_style') ?></head><body class="billing-unified-page skin-blue sidebar-mini"><?= view('partials/operational_workspace_start',['menu_items'=>$menu_items??[]]) ?>
<p><a href="<?= site_url('agenda') ?>">← Agenda</a> · <a href="<?= site_url('cartella-clinica/esami-test') ?>">Accettazione e lista esami</a></p>
<h1><?= esc($a['tipo_visita_label']?:'Esame') ?></h1><p>Appuntamento #<?= (int)$id ?> · <?= esc($labels[$r['stage']]) ?></p>
<div class="journey-panel journey-context"><div><small>Paziente</small><strong><?= esc($patient['patient_name']??'') ?></strong></div><div><small>Appuntamento</small><?= esc(date('d/m/Y',strtotime($a['data_slot'])).' · '.substr($a['ora_inizio'],-8,5)) ?></div><div><small>Medico</small><?= esc($state['doctorName']) ?></div><div><small>Il tuo ruolo</small><?= $doctor?'Medico dell’esame':'Accettazione' ?></div><div><a href="<?= $chart ?>">Cartella e consensi</a></div></div>
<div class="journey-track" aria-label="Percorso dell’esame"><?php foreach(['Prenotazione','Accettazione','Esame','Referto','Firma','Consegna'] as $i=>$label): ?><span class="<?= $i===$active?'active':'' ?>"><?= $i+1 ?> · <?= esc($label) ?></span><?php endforeach ?></div>
<?php if(session()->getFlashdata('success')): ?><p class="alert alert-success" role="status"><?= esc(session()->getFlashdata('success')) ?></p><?php endif ?>
<section class="journey-panel">
<?php if($r['stage']==='booked'): ?>
<h2>Accogli il paziente</h2><p>Prestazione, paziente e orario sono quelli dell’agenda. Verifica anagrafica e consensi, poi registra l’arrivo.</p><?php $button('accept','Accetta paziente'); ?>
<?php elseif($r['stage']==='accepted'): ?>
<h2>Pronto per l’esame</h2><p>Il paziente è stato accettato. Il medico può avviare l’esame dalla stessa scheda.</p><?php if($doctor)$button('start','Avvia esame'); ?>
<?php elseif($r['stage']==='in_progress'): ?>
<h2>Esame in esecuzione</h2><p>Alla conclusione si apre la compilazione del referto, senza reinserire i dati del paziente.</p><?php if($doctor)$button('finish','Concludi e scrivi referto'); ?>
<?php elseif(!$doctor): ?>
<h2>Esame eseguito</h2><p>La compilazione del referto e la firma sono riservate al medico dell’appuntamento.</p>
<?php elseif(!$report || $report['state']==='draft'): ?>
<h2>Referto · Bozza</h2><form method="post" action="<?= $base ?>"><?= csrf_field() ?><input type="hidden" name="revision" value="<?= (int)$r['revision'] ?>"><input type="hidden" name="entry_revision" value="<?= (int)($report['revision']??0) ?>"><label for="report-body">Testo del referto</label><textarea id="report-body" name="body" maxlength="40000" required><?= esc($report['content']['body']??'') ?></textarea><p>Il testo rimane modificabile fino alla creazione del PDF definitivo.</p><button class="btn btn-primary" name="action" value="save">Salva bozza</button></form>
<?php if($report): ?><hr><h2>Controlla prima di confermare</h2><div class="journey-paper"><?= esc($report['content']['body']) ?></div><p>La conferma rende il documento definitivo. Eventuali correzioni successive richiedono una revisione dalla cartella.</p><?php $button('finalize','Conferma testo e genera PDF definitivo'); ?><?php endif ?>
<?php elseif($report['state']==='signed'): ?>
<h2>Firma verificata</h2><p>Il documento firmato è archiviato insieme al PDF originale.</p><a class="btn btn-primary" href="<?= $chart.'/allegati/'.esc($report['signed_object_id'],'attr') ?>">Scarica referto firmato</a><p>La consegna protetta al paziente non è ancora attiva in questo ambiente.</p>
<?php elseif(!$r['signature_simulated_at']): ?>
<h2>Firma del referto</h2><p>Il PDF definitivo è pronto. Scegli come firmarlo.</p>
<div class="journey-panel"><h3>Firma nell’applicativo</h3><p>Autorizza con il tuo servizio di firma remota, senza scaricare e ricaricare il documento.</p><button type="button" class="btn btn-primary" disabled aria-describedby="signature-status">Firma digitalmente</button> <a class="btn btn-default" href="<?= site_url('cartella-clinica/impostazioni-firma') ?>">Imposta firma digitale</a><p id="signature-status" class="journey-warning">Servizio non collegato. La selezione nelle impostazioni non attiva la firma: occorre integrare e collaudare il provider.</p></div>
<div class="journey-panel"><h3>Firma con un servizio esterno</h3><p>Scarica il PDF originale, firmalo con il tuo programma e carica il risultato nella cartella.</p><div class="journey-actions"><a class="btn btn-default" href="<?= $chart.'/allegati/'.esc($report['pdf_object_id'],'attr') ?>">Scarica PDF da firmare</a><a class="btn btn-primary" href="<?= $chart.'?document='.(int)$report['id'].'#documenti' ?>">Carica referto firmato</a></div><p>Formati PDF firmato (PAdES) e P7M (CAdES). L’acquisizione richiede identità del medico e validatore configurati.</p></div>
<details><summary>Prova il passaggio successivo · solo test</summary><div class="journey-warning">La simulazione non firma il PDF e non cambia il documento clinico in «firmato».</div><?php $button('simulate_signature','Simula firma · solo test'); ?></details>
<?php elseif(!$r['delivery_simulated_at']): ?>
<h2>Consegna del referto · Prova</h2><div class="journey-warning">Firma simulata. Il documento originale è ancora non firmato. Nessuna email, SMS o WhatsApp sarà inviata.</div><p>Nel percorso proposto il paziente riceverà un collegamento protetto con scadenza e verifica di accesso.</p><?php $button('simulate_delivery','Simula consegna · nessun invio reale'); ?>
<?php else: ?>
<h2>Percorso di prova completato</h2><p>Esame eseguito, referto salvato e PDF definitivo generato.</p><div class="journey-warning">Firma e consegna simulate. Non è stato inviato alcun documento al paziente.</div><a class="btn btn-primary" href="<?= $chart.'?document='.(int)$report['id'].'#documenti' ?>">Rivedi il referto nello storico</a>
<?php endif ?>
</section>
<?php if($doctor): ?><details class="journey-panel"><summary>Immagini, PACS e referti precedenti</summary><p><a href="<?= $chart ?>">Apri storico clinico del paziente</a></p><p><a href="<?= $chart.'/pacs/richieste?appointment='.(int)$id ?>">Apri richieste PACS collegate all’appuntamento</a></p><p>Il collaudo del percorso non equivale alla ricezione di una worklist sull’ecografo. Il collegamento all’apparecchio richiede una configurazione dedicata.</p></details><?php endif ?>
<details class="journey-panel"><summary>Attività dell’esame</summary><?php $names=['accept'=>'Paziente accettato','start'=>'Esame avviato','finish'=>'Esame eseguito','save'=>'Bozza salvata','finalize'=>'PDF definitivo generato','simulate_signature'=>'Firma simulata (test)','simulate_delivery'=>'Consegna simulata (test)'];foreach($events as $event): ?><div class="journey-history"><?= esc($names[$event['action']]??$event['action']) ?><small><?= esc($event['recorded_at']) ?> UTC · Operatore #<?= (int)$event['actor_user_id'] ?></small></div><?php endforeach ?></details>
<?= view('partials/operational_workspace_end') ?></body></html>



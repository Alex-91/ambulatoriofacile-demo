<?php
use App\Services\Pacs\PacsTrialService;
$demoAction=$base.'/pacs-demo';$demoOrders=$pacsDemo['orders'];$demoSelected=$pacsDemo['selected'];
$demoLabels=['draft'=>'Bozza','ready'=>'Confermato','accepted'=>'Accettato','in_progress'=>'In esecuzione','performed'=>'Eseguito','cancelled'=>'Annullato'];
$demoNext=['draft'=>['confirm','Conferma esame'],'ready'=>['accept','Registra accettazione'],'accepted'=>['start','Avvia esame'],'in_progress'=>['complete','Concludi esame']];
$demoCatalog=PacsTrialService::catalog();$demoStations=PacsTrialService::stations();
?>
<section class="card" id="pacs-demo"><h2>Esami del paziente</h2>
<p class="notice">Esami dello spazio test. Nessun invio ai macchinari o al FSE.</p>
<?php if($message=session()->getFlashdata('error')): ?><p role="alert"><?= esc($message) ?></p><?php endif ?>
<?php if($demoSelected): $ds=$demoSelected; ?>
<p><a class="button secondary" href="<?= $base.'#pacs-demo' ?>">Nuovo esame e archivio del paziente</a></p>
<h3><?= esc($ds['payload']['description']) ?></h3><p><span class="tag"><?= esc($demoLabels[$ds['state']]) ?></span> · <?= esc($ds['accession']) ?></p>
<p><?= esc($ds['payload']['scheduled_at']) ?> · <?= esc($demoStations[$ds['payload']['station_ae']]['label']) ?></p>
<p class="help">Identità fittizia nella worklist: <?= esc($ds['identity']['patient_id']) ?>. L’anagrafica reale non viene esportata.</p>
<div class="actions">
<?php if(isset($demoNext[$ds['state']])): ?><form method="post" action="<?= esc($demoAction,'attr') ?>"><?= csrf_field() ?><input type="hidden" name="order" value="<?= esc($ds['id'],'attr') ?>"><input type="hidden" name="revision" value="<?= (int)$ds['revision'] ?>"><button name="action" value="<?= $demoNext[$ds['state']][0] ?>"><?= esc($demoNext[$ds['state']][1]) ?></button></form><?php endif ?>
<?php if(in_array($ds['state'],['draft','ready','accepted'],true)): ?><form method="post" action="<?= esc($demoAction,'attr') ?>"><?= csrf_field() ?><input type="hidden" name="order" value="<?= esc($ds['id'],'attr') ?>"><input type="hidden" name="revision" value="<?= (int)$ds['revision'] ?>"><button class="secondary" name="action" value="cancel">Annulla esame</button></form><?php endif ?>
</div>
<?php if(in_array($ds['state'],['ready','accepted'],true)): ?><details><summary>Scarica worklist</summary><form method="post" action="<?= esc($demoAction.'/worklist','attr') ?>"><?= csrf_field() ?><input type="hidden" name="order" value="<?= esc($ds['id'],'attr') ?>"><input type="hidden" name="revision" value="<?= (int)$ds['revision'] ?>"><button name="format" value="wl">Scarica DICOM</button> <button name="format" value="json">Scarica JSON</button></form><p>Il download non invia l’esame alla macchina. <a href="<?= $base.'?demo_order='.$ds['id'].'#pacs-demo' ?>">Aggiorna la richiesta dopo il download</a> prima di procedere.</p></details><?php endif ?>
<?php if($ds['state']==='performed'): ?>
<h3>Immagini e referto</h3>
<?php if(empty($ds['sample_images'])): ?><form method="post" action="<?= esc($demoAction,'attr') ?>"><?= csrf_field() ?><input type="hidden" name="order" value="<?= esc($ds['id'],'attr') ?>"><input type="hidden" name="revision" value="<?= (int)$ds['revision'] ?>"><button name="action" value="images">Apri immagini campione</button></form><?php else: ?>
<p class="notice">Immagini campione del PACS di test: non sono immagini di questo paziente né il risultato dell’esame appena creato. Questo passaggio simula la consultazione del risultato.</p>
<img id="chartDemoImage" src="<?= site_url('cartella-clinica/demo-pacs/immagini/1') ?>" alt="Phantom sintetico, nessun valore diagnostico" width="512" height="512" style="display:block;max-width:100%;height:auto;background:#111">
<label for="chartDemoSlice">Immagine campione<select id="chartDemoSlice"><?php for($i=1;$i<=16;$i++): ?><option value="<?= $i ?>">Immagine <?= $i ?> di 16</option><?php endfor ?></select></label><p id="chartDemoImageStatus" role="status">Caricamento dal PACS di test…</p>
<script>(()=>{const image=document.getElementById('chartDemoImage'),select=document.getElementById('chartDemoSlice'),status=document.getElementById('chartDemoImageStatus'),url=<?= json_encode(site_url('cartella-clinica/demo-pacs/immagini'),JSON_HEX_TAG|JSON_HEX_AMP) ?>;function loaded(){status.textContent=image.naturalWidth?'Immagine campione disponibile.':'PACS non disponibile: riprova più tardi.';}image.addEventListener('load',loaded);image.addEventListener('error',loaded);if(image.complete)loaded();select.addEventListener('change',()=>{status.textContent='Caricamento…';image.src=url+'/'+select.value;});})();</script>
<?php endif ?>
<?php if($ds['report']!==''): ?><h3>Referto salvato</h3><p class="text"><?= esc($ds['report']) ?></p><p class="help">Non firmato · senza valore clinico.</p><?php endif ?>
<form method="post" action="<?= esc($demoAction,'attr') ?>"><?= csrf_field() ?><input type="hidden" name="order" value="<?= esc($ds['id'],'attr') ?>"><input type="hidden" name="revision" value="<?= (int)$ds['revision'] ?>"><label>Referto<textarea name="report" required maxlength="5000"><?= esc($ds['report']) ?></textarea></label><button name="action" value="report">Salva referto</button></form>
<?php endif ?>
<details><summary>Storico dell’esame</summary><ul><?php foreach($ds['history'] as $event): ?><li><?= esc($event['action']) ?> · <?= esc((new DateTimeImmutable($event['at']))->setTimezone(new DateTimeZone('Europe/Rome'))->format('d/m/Y H:i')) ?></li><?php endforeach ?></ul></details>
<?php else: ?>
<h3>Nuovo esame per <?= esc($patient['patient_name']??'questo paziente') ?></h3>
<form method="post" action="<?= esc($demoAction,'attr') ?>"><?= csrf_field() ?><input type="hidden" name="action" value="create"><input type="hidden" name="request_key" value="<?= bin2hex(random_bytes(16)) ?>"><div class="grid">
<label>Esame<select id="chartDemoExam" name="exam" required><?php foreach($demoCatalog as $key=>$exam): ?><option value="<?= esc($key,'attr') ?>" data-modality="<?= esc($exam['modality'],'attr') ?>"><?= esc($exam['label']) ?></option><?php endforeach ?></select></label>
<label>Apparecchiatura<select id="chartDemoStation" name="station" required><?php foreach($demoStations as $ae=>$station): ?><option value="<?= esc($ae,'attr') ?>" data-modality="<?= esc($station['modality'],'attr') ?>"><?= esc($station['label']) ?></option><?php endforeach ?></select></label>
<label>Data e ora<input type="datetime-local" name="scheduled_at" value="<?= (new DateTimeImmutable('now',new DateTimeZone('Europe/Rome')))->format('Y-m-d\TH:i') ?>" required></label><label>Note<input name="reason" maxlength="1000"></label></div><button>Salva nuovo esame</button></form>
<script>(()=>{const exam=document.getElementById('chartDemoExam'),station=document.getElementById('chartDemoStation');function sync(){for(const option of station.options){option.disabled=option.dataset.modality!==exam.selectedOptions[0].dataset.modality;option.hidden=option.disabled;}if(station.selectedOptions[0].disabled)station.value=Array.from(station.options).find(o=>!o.disabled).value;}exam.addEventListener('change',sync);sync();})();</script>
<?php endif ?>
<h3>Esami del paziente</h3>
<?php if(!$demoOrders): ?><p>Nessun esame salvato.</p><?php endif ?>
<?php foreach($demoOrders as $item): ?><div class="entry"><a href="<?= $base.'?demo_order='.$item['id'].'#pacs-demo' ?>"><?= esc($item['payload']['description']) ?></a> · <span class="tag"><?= esc($demoLabels[$item['state']]) ?></span><br><small><?= esc($item['accession']) ?> · <?= !empty($item['sample_images'])?'Immagini campione disponibili':'Nessuna immagine campione aggiunta' ?> · <?= $item['report']!==''?'Referto presente':'Referto non compilato' ?></small></div><?php endforeach ?>
</section>

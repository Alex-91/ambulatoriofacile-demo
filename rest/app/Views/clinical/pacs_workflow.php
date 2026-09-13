<?php
$stages=\App\Services\Pacs\PacsOrderService::STAGES;
$stage=$order['workflow_stage'] ?? 'awaiting';
$report=$report ?? null;
$reportStates=['draft'=>'Bozza','final'=>'Definitivo · da firmare','signed'=>'Firma verificata'];
?>
<section class="card"><h2>Percorso dell’esame</h2>
<p><?php if($order['state']==='cancelled'): ?>Richiesta annullata. Ultimo avanzamento registrato: <?php endif ?><span class="badge"><?= esc($stages[$stage]) ?></span><?php if(!empty($order['appointment_id'])): ?> · Appuntamento #<?= (int)$order['appointment_id'] ?><?php endif ?></p>
<p><a href="<?= site_url('cartella-clinica/diagnostica').'?'.http_build_query(['date'=>substr($v['scheduled_at'],0,10),'completed'=>'1']) ?>">Apri la lista di lavoro</a></p>
<?php if($owner && $order['state']==='ready' && $stage!=='performed'): $next=['awaiting'=>'accepted','accepted'=>'in_progress','in_progress'=>'performed'][$stage]; ?>
<form method="post" action="<?= $base.'/'.$order['id'].'/avanzamento' ?>"><?= csrf_field() ?><input type="hidden" name="revision" value="<?= (int)$order['revision'] ?>"><input type="hidden" name="stage" value="<?= $next ?>"><input type="hidden" name="queue_date" value="<?= esc(substr($v['scheduled_at'],0,10),'attr') ?>"><button><?= esc(['accepted'=>'Registra accettazione','in_progress'=>'Avvia esame','performed'=>'Registra esame eseguito'][$next]) ?></button></form>
<?php endif ?>
<h3>Immagini</h3>
<?php if(!empty($order['study_link_id'])): ?><p><a href="<?= site_url('cartella-clinica/pazienti/'.$patientId.'/pacs/studi/'.$order['study_link_id']) ?>">Apri lo studio collegato</a></p><?php else: ?><p>Nessuno studio collegato alla richiesta.</p><?php endif ?>
<?php if($owner && $order['state']==='ready' && $stage==='performed'): ?><form method="post" action="<?= $base.'/'.$order['id'].'/immagini' ?>"><?= csrf_field() ?><input type="hidden" name="revision" value="<?= (int)$order['revision'] ?>"><button class="secondary">Cerca e collega le immagini</button></form><p class="muted">Il collegamento avviene solo se paziente, identificativo dello studio e numero richiesta coincidono.</p><?php endif ?>
<h3>Referto</h3>
<?php if(!empty($reportUnavailable)): ?><p>Il referto non è disponibile per il profilo corrente o richiede la verifica del collegamento.</p>
<?php elseif($report): ?><p><span class="badge"><?= esc($reportStates[$report['state']] ?? $report['state']) ?></span> · Documento #<?= (int)$report['id'] ?></p><p><?= esc($report['content']['title']) ?></p><p><a href="<?= site_url('cartella-clinica/pazienti/'.$patientId).'?document='.(int)$report['id'].'#documenti' ?>">Apri il referto nella cartella</a></p>
<?php else: ?><p>Nessun referto collegato.</p><?php endif ?>
<?php if($owner && $order['state']==='ready' && $stage==='performed' && empty($reportUnavailable) && (!$report || $report['state']==='draft')): ?>
<form method="post" action="<?= $base.'/'.$order['id'].'/referto' ?>"><?= csrf_field() ?><input type="hidden" name="revision" value="<?= (int)$order['revision'] ?>"><input type="hidden" name="entry_revision" value="<?= (int)($report['revision'] ?? 0) ?>">
<label>Data e ora della refertazione<input type="datetime-local" name="occurred_at" required value="<?= esc(isset($report['occurred_at']) ? str_replace(' ','T',substr($report['occurred_at'],0,16)) : (new \DateTimeImmutable('now',new \DateTimeZone('Europe/Rome')))->format('Y-m-d\TH:i'),'attr') ?>"></label>
<label>Testo del referto<textarea name="body" rows="7" maxlength="40000" required><?= esc($report['content']['body'] ?? '') ?></textarea></label><button>Salva bozza del referto</button></form><p class="muted">Finalizzazione, PDF e firma sono disponibili nella cartella del paziente.</p>
<?php endif ?>
</section>
<?php if(isset($history)): $events=['pacs_order_created'=>'Richiesta creata','pacs_order_updated'=>'Bozza aggiornata','pacs_order_approved'=>'Richiesta confermata','pacs_order_exported'=>'Copia esportata','pacs_order_cancelled'=>'Richiesta annullata','pacs_stage_accepted'=>'Paziente accettato','pacs_stage_in_progress'=>'Esame avviato','pacs_stage_performed'=>'Esecuzione registrata','pacs_report_saved'=>'Bozza referto salvata','pacs_order_images_linked'=>'Immagini collegate']; ?>
<section class="card"><h2>Storico della richiesta</h2>
<?php foreach($history['rows'] as $event): ?><div class="row"><?= esc($events[$event['event']] ?? 'Operazione registrata') ?><br><small><?= esc((new \DateTimeImmutable($event['recorded_at'],new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone('Europe/Rome'))->format('d/m/Y H:i:s')) ?> · Operatore #<?= (int)$event['actor_user_id'] ?></small></div><?php endforeach ?>
<div class="actions"><?php if($history['page']>1): ?><a href="<?= $base.'/'.$order['id'].'?history_page='.($history['page']-1) ?>">Più recenti</a><?php endif ?><?php if($history['more']): ?><a href="<?= $base.'/'.$order['id'].'?history_page='.($history['page']+1) ?>">Precedenti</a><?php endif ?></div></section>
<?php endif ?>

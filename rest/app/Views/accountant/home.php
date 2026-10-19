<?= view('accountant/header') ?>
<?php if (!$email): ?>
<h1>Accedi all’area commercialista</h1><p class="muted">Consulta le fatture e scarica i report dei medici che ti hanno delegato.</p>
<section class="card" style="max-width:580px"><h2>Ricevi un link personale</h2><p>Inserisci l’email sulla quale hai accettato l’invito. Ti invieremo un link monouso: non serve una password.</p>
<form method="post" action="<?= site_url('commercialista/accesso') ?>"><?= csrf_field() ?>
<label>Email professionale<input name="email" type="email" maxlength="254" autocomplete="email" required></label><p></p><button>Invia link di accesso</button></form>
<p class="muted small">Il link scade dopo 15 minuti. Per il primo accesso, apri e accetta l’invito ricevuto dal medico.</p></section>
<?php else: ?>
<h1>I tuoi medici</h1><p class="muted">Accesso personale: <?= esc($email) ?>. Seleziona il medico di cui consultare i documenti.</p>
<?php if (!$grants): ?><div class="card">Non ci sono deleghe attive disponibili. Chiedi al medico di invitarti o di verificare l’attivazione della fatturazione.</div><?php endif ?>
<div class="grid"><?php foreach ($grants as $grant): ?><article class="card"><span class="badge">Sola consultazione</span><h2 style="margin-top:14px"><?= esc($grant['tenant_label']) ?></h2><p>Documenti dal <?= esc(substr($grant['from_date'], 0, 4)) ?></p><a class="button" href="<?= site_url('commercialista/medico/' . (int) $grant['tenant_id']) ?>">Fatture e report</a></article><?php endforeach ?></div>
<?php endif ?>
<?= view('accountant/footer') ?>

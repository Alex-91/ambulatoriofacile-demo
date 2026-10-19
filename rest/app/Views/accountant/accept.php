<?= view('accountant/header') ?>
<section class="card" style="max-width:650px">
<?php if (is_array($pending)): ?><h1><?= $pending['kind'] === 'invito' ? 'Accetta la delega' : 'Conferma l’accesso' ?></h1>
<p>Entrerai con l’indirizzo email che ha ricevuto il link. Potrai consultare e scaricare esclusivamente le fatture e i report autorizzati.</p>
<p class="muted">La sessione dura due ore. Il medico può revocare la delega in qualsiasi momento.</p>
<form method="post" action="<?= site_url('commercialista/accetta') ?>"><?= csrf_field() ?><button><?= $pending['kind'] === 'invito' ? 'Accetta e accedi' : 'Accedi' ?></button></form>
<?php else: ?><h1>Link non disponibile</h1><p>Apri il link ricevuto via email oppure richiedi un nuovo accesso.</p><a class="button" href="<?= site_url('commercialista') ?>">Richiedi accesso</a><?php endif ?>
</section><?= view('accountant/footer') ?>

<!DOCTYPE html>
<html lang="it">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>AmbulatorioFacile | Commercialista</title>
  <link rel="icon" href="<?= base_url('public/assets/images/logonew.jpg') ?>" type="image/x-icon">
  <link href="<?= base_url('public/bootstrap/css/bootstrap.min.css') ?>" rel="stylesheet">
  <link href="https://maxcdn.bootstrapcdn.com/font-awesome/4.3.0/css/font-awesome.min.css" rel="stylesheet">
  <link href="<?= base_url('public/dist/css/AdminLTE.css') ?>" rel="stylesheet">
  <link href="<?= base_url('public/dist/css/skins/_all-skins.min.css') ?>" rel="stylesheet">
  <link href="<?= base_url('public/assets/css/billing-ts-ui.css') ?>" rel="stylesheet">
  <style>
    .billing-accountant-page .card { background:#fff; border:1px solid #dfe9eb; border-radius:16px; padding:20px; margin-bottom:18px; box-shadow:0 8px 24px rgba(31,66,73,.06); }
    .billing-accountant-page .card h2 { margin:0 0 14px; font-size:20px; font-weight:700; }
    .billing-accountant-page .muted { color:#70858a; }
    .billing-accountant-page .small { font-size:12px; margin-top:14px; }
    .billing-accountant-page .actions { display:flex; flex-wrap:wrap; align-items:flex-end; gap:14px; margin-top:18px; }
    .billing-accountant-page .actions label { flex:1; min-width:180px; margin:0; }
    .billing-accountant-page input { display:block; width:100%; height:42px; padding:10px; margin-top:6px; border:1px solid #d9e5e7; border-radius:9px; font-weight:400; }
    .billing-accountant-page button { border:1px solid #176875; border-radius:9px; background:#176875; color:#fff; padding:10px 16px; font-weight:600; }
    .billing-accountant-page button.danger { background:#fff; color:#a62929; border-color:#d9a1a1; }
    .billing-accountant-page .scroll { overflow-x:auto; }
    .billing-accountant-page table { width:100%; border-collapse:collapse; }
    .billing-accountant-page th, .billing-accountant-page td { padding:12px; border-bottom:1px solid #e2eaec; text-align:left; }
    .billing-accountant-page th { background:#f7fafb; color:#60777c; }
    .billing-accountant-page .badge { background:#e5f2ee; color:#176875; padding:6px 10px; }
    .billing-accountant-page .notice { padding:14px 18px; border-radius:9px; background:#e2f3ec; margin-bottom:18px; }
    .billing-accountant-page .notice.error { background:#fff0ed; color:#8b3027; }
  </style>
</head>
<body class="skin-blue sidebar-mini billing-ts-ui module-fatturazione">
<div class="wrapper">
  <?= view('partials/header', ['menu_items' => $menu_items ?? []]) ?>
  <div class="content-wrapper">
    <section class="content billing-dashboard-content">
      <div class="row billing-dashboard-layout">
        <aside class="col-md-3 billing-dashboard-nav">
          <?= view('partials/sidebar_admin', ['menu_items' => $menu_items ?? []]) ?>
        </aside>
        <main class="col-md-9">
          <div class="billing-dashboard billing-accountant-page">
            <header class="billing-modulebar">
              <div class="billing-modulebar-copy">
                <div class="billing-eyebrow">Fatturazione</div>
                <div class="billing-title-row">
                  <span class="billing-module-icon"><i class="fa fa-user"></i></span>
                  <div>
                    <h1>Il tuo commercialista</h1>
                    <p>Invita il consulente a consultare fatture e report di <?= esc($doctor->tenantName) ?>.</p>
                  </div>
                </div>
              </div>
            </header>
<?php foreach (['success', 'error'] as $flash): if ($message = session()->getFlashdata($flash)): ?>
<div role="status" class="notice <?= $flash === 'error' ? 'error' : '' ?>"><?= esc($message) ?></div>
<?php endif; endforeach ?>
<?php if (!$ready): ?><div class="notice error">La funzione deve essere attivata dall’amministratore con l’aggiornamento del database.</div>
<?php else: ?>
<section class="card"><h2>Invita un commercialista</h2><p>Potrà scaricare fatture, ricevute e parcelle definitive e i relativi report. Non avrà accesso ad agenda, cartelle cliniche o rubrica pazienti e non potrà modificare i documenti.</p>
<form class="actions" method="post" action="<?= site_url('admin/fatturazione-commercialista/invita') ?>"><?= csrf_field() ?>
<label>Email del commercialista<input type="email" name="email" maxlength="254" required autocomplete="email"></label>
<label>Consenti documenti dall’anno<input type="number" name="year" min="2000" max="<?= date('Y') ?>" value="<?= date('Y') ?>" required></label><button>Invia invito</button></form>
<p class="muted small">L’invito scade dopo 7 giorni. Le fatture includono i dati fiscali dei pazienti. Per reinviare un invito in attesa, inserisci nuovamente la stessa email.</p></section>
<section class="card"><h2>Deleghe</h2><div class="scroll"><table><thead><tr><th>Email</th><th>Documenti dal</th><th>Stato</th><th>Ultima consultazione</th><th>Azione</th></tr></thead><tbody>
<?php foreach ($delegations as $grant): $labels = ['active'=>'Attiva','pending'=>'In attesa','revoked'=>'Revocata']; $expired = $grant['status'] === 'pending' && strtotime($grant['expires_at']) < time(); ?>
<tr><td><?= esc($grant['email']) ?></td><td><?= esc(substr($grant['from_date'],0,4)) ?></td><td><span class="badge"><?= $expired ? 'Invito scaduto' : esc($labels[$grant['status']] ?? '') ?></span></td><td><?= esc($grant['last_access_at'] ?? '—') ?></td><td>
<?php if ($grant['status'] !== 'revoked'): ?><form method="post" action="<?= site_url('admin/fatturazione-commercialista/revoca/' . (int) $grant['id']) ?>"><?= csrf_field() ?><button class="danger">Revoca accesso</button></form><?php endif ?></td></tr>
<?php endforeach; if (!$delegations): ?><tr><td colspan="5">Non hai ancora invitato un commercialista.</td></tr><?php endif ?>
</tbody></table></div></section>
<section class="card"><h2>Attività recenti</h2><p class="muted small">Ultimi 100 eventi, inclusi consultazioni ed esportazioni.</p><div class="scroll"><table><thead><tr><th>Data</th><th>Email</th><th>Attività</th><th>Dettaglio</th></tr></thead><tbody>
<?php $events=['invited'=>'Invito creato','accepted'=>'Delega accettata','revoked'=>'Accesso revocato','view'=>'Consultazione','invoice_pdf'=>'Download fattura','export_csv'=>'Report CSV','export_pdf'=>'Report PDF','export_zip'=>'Pacchetto fatture ZIP']; foreach ($history as $event): ?>
<tr><td><?= esc($event['created_at']) ?></td><td><?= esc($event['email']) ?></td><td><?= esc($events[$event['event']] ?? $event['event']) ?></td><td><?= esc($event['detail']) ?></td></tr><?php endforeach ?>
<?php if (!$history): ?><tr><td colspan="4">Nessuna attività registrata.</td></tr><?php endif ?></tbody></table></div></section>
<?php endif ?>
          </div>
        </main>
      </div>
    </section>
  </div>
  <footer class="main-footer">
    <div class="pull-right hidden-xs"><b>Version</b> 2.0</div>
    <strong>&copy; AmbulatorioFacile</strong>
  </footer>
</div>
<script src="<?= base_url('public/plugins/jQuery/jQuery-2.1.4.min.js') ?>"></script>
<script src="<?= base_url('public/bootstrap/js/bootstrap.min.js') ?>"></script>
</body>
</html>

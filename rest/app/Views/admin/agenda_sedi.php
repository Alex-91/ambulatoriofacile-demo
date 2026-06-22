<?php
if (empty($menu_items) || !is_array($menu_items)) {
    $menu_items = session()->get('header_menu_items') ?? [];
}

$catalog = $catalog ?? [];
$selectedAmbId = (int)($selectedAmbId ?? 0);
$selectedAmbulatorio = $selectedAmbulatorio ?? null;
$editingStanza = $editingStanza ?? null;
$success = $success ?? null;
$errors = $errors ?? [];
$activeSedi = (int)($activeSedi ?? 0);
$totalSedi = (int)($totalSedi ?? 0);
$activeStanze = (int)($activeStanze ?? 0);
$totalStanze = (int)($totalStanze ?? 0);
$baseRoute = trim((string)($baseRoute ?? 'agenda/gestione-sedi'), '/');
$baseUrl = site_url($baseRoute);
?>
<!DOCTYPE html>
<html>
<head>
  <meta charset="UTF-8">
  <title>AmbulatorioFacile | Gestione sedi agenda</title>
  <meta content="width=device-width, initial-scale=1" name="viewport">
  <link href="<?= base_url('public/css/agenda-menu.css') ?>" rel="stylesheet" />
  <link href="<?= base_url('public/bootstrap/css/bootstrap.min.css') ?>" rel="stylesheet" />
  <link href="https://maxcdn.bootstrapcdn.com/font-awesome/4.3.0/css/font-awesome.min.css" rel="stylesheet" />
  <link href="<?= base_url('public/dist/css/AdminLTE.css') ?>" rel="stylesheet" />
  <link href="<?= base_url('public/dist/css/skins/_all-skins.min.css') ?>" rel="stylesheet" />
  <style>
    .summary-box {
      border: 1px solid #e5e5e5;
      border-radius: 6px;
      padding: 12px 14px;
      background: #fff;
      margin-bottom: 12px;
      min-height: 96px;
    }
    .summary-box .value {
      font-size: 24px;
      font-weight: 700;
      line-height: 1.2;
    }
    .room-table td,
    .room-table th {
      vertical-align: middle !important;
    }
    .muted-meta {
      color: #7f8c8d;
      font-size: 12px;
    }
    .box-tools-inline .btn {
      margin-left: 6px;
      margin-bottom: 6px;
    }
  </style>
</head>
<body class="skin-blue sidebar-mini">
<div class="wrapper">

  <?= view('partials/header', ['menu_items' => $menu_items]) ?>

  <aside class="main-sidebar" style="display:none">
    <section class="sidebar"></section>
  </aside>

  <div class="content-wrapper">
    <section class="content-header">
      <h1>Gestione sedi agenda</h1>
      <p class="text-muted" style="margin:8px 0 0 0;">
        Qui gestisci l'anagrafica sedi e le stanze usate dai menu a tendina della configurazione agenda.
      </p>
    </section>

    <section class="content">
      <div class="row">
        <div class="col-md-3">
          <div class="box box-solid">
            <div class="box-header with-border">
              <h3 class="box-title">Menu</h3>
            </div>
            <div class="box-body no-padding">
              <?= view('agenda/partials/menu_laterale', ['menuAgenda' => $menuAgenda ?? []]) ?>
            </div>
          </div>
        </div>

        <div class="col-md-9">
          <?php if ($success): ?>
            <div class="alert alert-success"><?= esc($success) ?></div>
          <?php endif; ?>
          <?php if (!empty($errors['generic'])): ?>
            <div class="alert alert-danger"><?= esc($errors['generic']) ?></div>
          <?php endif; ?>

          <div class="row">
            <div class="col-md-4">
              <div class="summary-box">
                <div class="text-muted">Sedi attive</div>
                <div class="value"><?= $activeSedi ?></div>
                <div class="text-muted">Totale sedi: <?= $totalSedi ?></div>
              </div>
            </div>
            <div class="col-md-4">
              <div class="summary-box">
                <div class="text-muted">Stanze attive</div>
                <div class="value"><?= $activeStanze ?></div>
                <div class="text-muted">Totale stanze: <?= $totalStanze ?></div>
              </div>
            </div>
            <div class="col-md-4">
              <div class="summary-box">
                <div class="text-muted">Sede selezionata</div>
                <div class="value" style="font-size:18px;">
                  <?= $selectedAmbulatorio ? esc($selectedAmbulatorio['nome']) : 'Nuova sede' ?>
                </div>
                <div class="text-muted">Usa la lista sotto per modificare o attivare/disattivare.</div>
              </div>
            </div>
          </div>

          <div class="box box-primary">
            <div class="box-header with-border">
              <h3 class="box-title"><?= $selectedAmbulatorio ? 'Modifica sede' : 'Nuova sede' ?></h3>
            </div>
            <form method="post" action="<?= site_url($baseRoute . '/save') ?>">
              <?= csrf_field() ?>
              <input type="hidden" name="id_amb_legacy" value="<?= (int)($selectedAmbulatorio['id_amb_legacy'] ?? 0) ?>">

              <div class="box-body">
                <div class="row">
                  <div class="col-md-4 form-group">
                    <label>Nome sede</label>
                    <input type="text" name="nome" class="form-control" value="<?= esc($selectedAmbulatorio['nome'] ?? '') ?>" required>
                  </div>
                  <div class="col-md-4 form-group">
                    <label>Indirizzo</label>
                    <input type="text" name="indirizzo" class="form-control" value="<?= esc($selectedAmbulatorio['indirizzo'] ?? '') ?>">
                  </div>
                  <div class="col-md-2 form-group">
                    <label>Citta</label>
                    <input type="text" name="citta" class="form-control" value="<?= esc($selectedAmbulatorio['citta'] ?? '') ?>">
                  </div>
                  <div class="col-md-2 form-group">
                    <label>Telefono</label>
                    <input type="text" name="telefono" class="form-control" value="<?= esc($selectedAmbulatorio['telefono'] ?? '') ?>">
                  </div>
                </div>

                <div class="row">
                  <div class="col-md-2 form-group">
                    <label>Ordine</label>
                    <input type="number" min="0" step="1" name="ordinamento" class="form-control" value="<?= esc((string)($selectedAmbulatorio['ordinamento'] ?? 0)) ?>">
                  </div>
                  <div class="col-md-3 form-group" style="padding-top:25px;">
                    <label style="font-weight:600;">
                      <input type="checkbox" name="attiva" value="1" <?= !isset($selectedAmbulatorio['attiva']) || !empty($selectedAmbulatorio['attiva']) ? 'checked' : '' ?>>
                      Sede attiva
                    </label>
                  </div>
                </div>
              </div>

              <div class="box-footer">
                <button class="btn btn-success" type="submit">
                  <i class="fa fa-save"></i> Salva sede
                </button>
                <?php if ($selectedAmbulatorio): ?>
                  <a class="btn btn-default" href="<?= $baseUrl ?>">
                    <i class="fa fa-plus"></i> Nuova sede
                  </a>
                <?php endif; ?>
              </div>
            </form>
          </div>

          <div class="box box-info">
            <div class="box-header with-border">
              <h3 class="box-title"><?= $editingStanza ? 'Modifica stanza' : 'Nuova stanza' ?></h3>
            </div>
            <form method="post" action="<?= site_url($baseRoute . '/stanza/save') ?>">
              <?= csrf_field() ?>
              <input type="hidden" name="id_stanza" value="<?= (int)($editingStanza['id_stanza'] ?? 0) ?>">

              <div class="box-body">
                <div class="row">
                  <div class="col-md-4 form-group">
                    <label>Sede</label>
                    <select name="id_amb_legacy" class="form-control" required>
                      <option value="">Seleziona...</option>
                      <?php foreach ($catalog as $ambulatorio): ?>
                        <?php
                        $idAmb = (int)($ambulatorio['id_amb_legacy'] ?? 0);
                        $selectedRoomAmb = (int)($editingStanza['id_amb_legacy'] ?? $selectedAmbId);
                        ?>
                        <option value="<?= $idAmb ?>" <?= $selectedRoomAmb === $idAmb ? 'selected' : '' ?>>
                          <?= esc($ambulatorio['nome'] ?? '') ?>
                        </option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <div class="col-md-4 form-group">
                    <label>Nome stanza</label>
                    <input type="text" name="nome" class="form-control" value="<?= esc($editingStanza['nome'] ?? '') ?>" required>
                  </div>
                  <div class="col-md-2 form-group">
                    <label>Ordine</label>
                    <input type="number" min="0" step="1" name="ordinamento" class="form-control" value="<?= esc((string)($editingStanza['ordinamento'] ?? 0)) ?>">
                  </div>
                  <div class="col-md-2 form-group" style="padding-top:25px;">
                    <label style="font-weight:600;">
                      <input type="checkbox" name="attiva" value="1" <?= !isset($editingStanza['attiva']) || !empty($editingStanza['attiva']) ? 'checked' : '' ?>>
                      Stanza attiva
                    </label>
                  </div>
                </div>
              </div>

              <div class="box-footer">
                <button class="btn btn-primary" type="submit">
                  <i class="fa fa-save"></i> Salva stanza
                </button>
              </div>
            </form>
          </div>

          <div class="box box-success">
            <div class="box-header with-border">
              <h3 class="box-title">Elenco sedi e stanze</h3>
            </div>
            <div class="box-body">
              <?php if (empty($catalog)): ?>
                <div class="alert alert-info" style="margin-bottom:0;">
                  Nessuna sede presente.
                </div>
              <?php else: ?>
                <?php foreach ($catalog as $ambulatorio): ?>
                  <?php $idAmb = (int)($ambulatorio['id_amb_legacy'] ?? 0); ?>
                  <div class="panel panel-default" style="margin-bottom:16px;">
                    <div class="panel-heading">
                      <div class="row">
                        <div class="col-md-7">
                          <strong><?= esc($ambulatorio['nome'] ?? '') ?></strong>
                          <?php if (empty($ambulatorio['attiva'])): ?>
                            <span class="label label-default" style="margin-left:6px;">Disattiva</span>
                          <?php endif; ?>
                          <div class="muted-meta">
                            ID sede: <?= $idAmb ?>
                            <?php if (!empty($ambulatorio['indirizzo'])): ?> | <?= esc($ambulatorio['indirizzo']) ?><?php endif; ?>
                            <?php if (!empty($ambulatorio['citta'])): ?> | <?= esc($ambulatorio['citta']) ?><?php endif; ?>
                            <?php if (!empty($ambulatorio['telefono'])): ?> | Tel. <?= esc($ambulatorio['telefono']) ?><?php endif; ?>
                          </div>
                        </div>
                        <div class="col-md-5 text-right box-tools-inline">
                          <a class="btn btn-xs btn-primary" href="<?= $baseUrl . '?id_amb_legacy=' . $idAmb ?>">
                            <i class="fa fa-pencil"></i> Modifica sede
                          </a>
                          <form method="post" action="<?= site_url($baseRoute . '/toggle') ?>" style="display:inline-block;">
                            <?= csrf_field() ?>
                            <input type="hidden" name="id_amb_legacy" value="<?= $idAmb ?>">
                            <input type="hidden" name="attiva" value="<?= !empty($ambulatorio['attiva']) ? '0' : '1' ?>">
                            <button class="btn btn-xs <?= !empty($ambulatorio['attiva']) ? 'btn-default' : 'btn-success' ?>" type="submit">
                              <i class="fa <?= !empty($ambulatorio['attiva']) ? 'fa-eye-slash' : 'fa-eye' ?>"></i>
                              <?= !empty($ambulatorio['attiva']) ? 'Disattiva' : 'Attiva' ?>
                            </button>
                          </form>
                        </div>
                      </div>
                    </div>
                    <div class="panel-body" style="padding-bottom:0;">
                      <div class="table-responsive">
                        <table class="table table-bordered table-striped room-table">
                          <thead>
                            <tr>
                              <th style="width:80px;">ID</th>
                              <th>Stanza</th>
                              <th style="width:120px;">Ordine</th>
                              <th style="width:120px;">Stato</th>
                              <th style="width:220px;">Azioni</th>
                            </tr>
                          </thead>
                          <tbody>
                            <?php if (empty($ambulatorio['stanze'])): ?>
                              <tr>
                                <td colspan="5" class="text-center text-muted">Nessuna stanza configurata per questa sede.</td>
                              </tr>
                            <?php else: ?>
                              <?php foreach ($ambulatorio['stanze'] as $stanza): ?>
                                <tr>
                                  <td><?= (int)($stanza['id_stanza'] ?? 0) ?></td>
                                  <td><?= esc($stanza['nome'] ?? '') ?></td>
                                  <td><?= (int)($stanza['ordinamento'] ?? 0) ?></td>
                                  <td>
                                    <?php if (!empty($stanza['attiva'])): ?>
                                      <span class="label label-success">Attiva</span>
                                    <?php else: ?>
                                      <span class="label label-default">Disattiva</span>
                                    <?php endif; ?>
                                  </td>
                                  <td>
                                    <a class="btn btn-xs btn-primary" href="<?= $baseUrl . '?id_amb_legacy=' . $idAmb . '&id_stanza=' . (int)($stanza['id_stanza'] ?? 0) ?>">
                                      <i class="fa fa-pencil"></i> Modifica
                                    </a>
                                    <form method="post" action="<?= site_url($baseRoute . '/stanza/toggle') ?>" style="display:inline-block;">
                                      <?= csrf_field() ?>
                                      <input type="hidden" name="id_amb_legacy" value="<?= $idAmb ?>">
                                      <input type="hidden" name="id_stanza" value="<?= (int)($stanza['id_stanza'] ?? 0) ?>">
                                      <input type="hidden" name="attiva" value="<?= !empty($stanza['attiva']) ? '0' : '1' ?>">
                                      <button class="btn btn-xs <?= !empty($stanza['attiva']) ? 'btn-default' : 'btn-success' ?>" type="submit">
                                        <i class="fa <?= !empty($stanza['attiva']) ? 'fa-eye-slash' : 'fa-eye' ?>"></i>
                                        <?= !empty($stanza['attiva']) ? 'Disattiva' : 'Attiva' ?>
                                      </button>
                                    </form>
                                  </td>
                                </tr>
                              <?php endforeach; ?>
                            <?php endif; ?>
                          </tbody>
                        </table>
                      </div>
                    </div>
                  </div>
                <?php endforeach; ?>
              <?php endif; ?>
            </div>
          </div>
        </div>
      </div>
    </section>
  </div>
</div>

<script src="<?= base_url('public/plugins/jQuery/jQuery-2.1.4.min.js') ?>"></script>
<script src="<?= base_url('public/bootstrap/js/bootstrap.min.js') ?>"></script>
<script src="<?= base_url('public/dist/js/app.min.js') ?>"></script>
<script src="<?= base_url('public/js/agenda-menu.js') ?>"></script>
</body>
</html>

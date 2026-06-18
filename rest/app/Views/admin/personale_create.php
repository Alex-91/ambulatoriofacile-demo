<?php
$result = session()->get('menuDataAdmin');
$menu_items = $menu_items ?? ($result['result'] ?? []);

$errors  = $errors ?? [];
$old     = $old ?? [];
$success = $success ?? null;

function oldv($k, $old) { return esc($old[$k] ?? ''); }
function hasErr($k, $errors) { return !empty($errors[$k]); }
$oldLuoghiRaw = $old['luoghi'] ?? ($old['luogo'] ?? []);
$oldLuoghi = is_array($oldLuoghiRaw) ? array_map('strval', $oldLuoghiRaw) : [(string)$oldLuoghiRaw];
$oldAllLuoghi = in_array('__all__', $oldLuoghi, true);
$oldShowInAgenda = array_key_exists('show_in_agenda', $old) ? !empty($old['show_in_agenda']) : true;
$oldShowInPosta  = array_key_exists('show_in_posta', $old) ? !empty($old['show_in_posta']) : true;
$oldShowInChat   = array_key_exists('show_in_chat', $old) ? !empty($old['show_in_chat']) : true;
?>
<html>
<head>
  <meta charset="UTF-8">
  <title>AmbulatoriCLOUD | Inserisci Personale</title>
  <link rel="icon" href="<?= base_url('public/assets/images/logonew.jpg') ?>" type="image/x-icon" sizes="any">
  <meta content='width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no' name='viewport'>

  <link href="<?= base_url('public/bootstrap/css/bootstrap.min.css') ?>" rel="stylesheet" type="text/css" />
  <link href="https://maxcdn.bootstrapcdn.com/font-awesome/4.3.0/css/font-awesome.min.css" rel="stylesheet" type="text/css" />
  <link href="https://code.ionicframework.com/ionicons/2.0.1/css/ionicons.min.css" rel="stylesheet" type="text/css" />
  <link href="<?= base_url('public/dist/css/AdminLTE.css') ?>" rel="stylesheet" type="text/css" />
  <link href="<?= base_url('public/dist/css/skins/_all-skins.min.css') ?>" rel="stylesheet" type="text/css" />

  <style>
    .nav-pills.nav-stacked > li.active > a { background-color:#2c8895; color:#fff; }
  </style>
</head>

<body class="skin-blue sidebar-mini">
<div class="wrapper">

  <?= view('partials/header', ['menu_items' => $menu_items ?? []]) ?>

  <div class="content-wrapper">
    <section class="content-header">
      <h1>Inserisci Personale <small></small></h1>
      <ol class="breadcrumb">
        <li><a href="<?= site_url('admin') ?>"><i class="fa fa-dashboard"></i> Home</a></li>
        <li class="active">Inserisci Personale</li>
      </ol>
    </section>

    <section class="content">
      <div class="row">

        <!-- COLONNA SINISTRA -->
        <div class="col-md-3">
          <?= view('partials/sidebar_admin', ['menu_items' => $menu_items ?? []]) ?>
        </div>

        <!-- COLONNA DESTRA -->
        <div class="col-md-9">
          <?php if ($success): ?>
            <div class="alert alert-success"><?= esc($success) ?></div>
          <?php endif; ?>

          <?php if (!empty($errors['generic'])): ?>
            <div class="alert alert-danger"><?= esc($errors['generic']) ?></div>
          <?php endif; ?>

          <div class="box box-primary">
            <div class="box-header with-border">
              <h3 class="box-title">Nuovo Personale</h3>
            </div>

            <form method="post" action="<?= site_url('admin/personale/salva') ?>">
              <?= csrf_field() ?>

              <div class="box-body">

                <div class="row">
                  <div class="col-md-6">
                    <div class="form-group <?= hasErr('nome',$errors)?'has-error':'' ?>">
                      <label>Nome *</label>
                      <input class="form-control" id="nome" name="nome" value="<?= oldv('nome',$old) ?>" required>
                      <?php if (hasErr('nome',$errors)): ?><span class="help-block"><?= esc($errors['nome']) ?></span><?php endif; ?>
                    </div>
                  </div>

                  <div class="col-md-6">
                    <div class="form-group <?= hasErr('cognome',$errors)?'has-error':'' ?>">
                      <label>Cognome *</label>
                      <input class="form-control" id="cognome" name="cognome" value="<?= oldv('cognome',$old) ?>" required>
                      <?php if (hasErr('cognome',$errors)): ?><span class="help-block"><?= esc($errors['cognome']) ?></span><?php endif; ?>
                    </div>
                  </div>
                </div>

                <div class="row">
                  <div class="col-md-6">
                    <div class="form-group <?= hasErr('username',$errors)?'has-error':'' ?>">
                      <label>Username *</label>
                      <input class="form-control" id="username" name="username" value="<?= oldv('username',$old) ?>" required>
                      <?php if (hasErr('username',$errors)): ?><span class="help-block"><?= esc($errors['username']) ?></span><?php endif; ?>
                    </div>
                  </div>

                  <div class="col-md-6">
                    <div class="form-group <?= hasErr('qualifica',$errors)?'has-error':'' ?>">
                      <label>Qualifica *</label>
                      <input class="form-control" name="qualifica" value="<?= oldv('qualifica',$old) ?>" required>
                      <?php if (hasErr('qualifica',$errors)): ?><span class="help-block"><?= esc($errors['qualifica']) ?></span><?php endif; ?>
                    </div>
                  </div>
                </div>

                <div class="row">
                  <div class="col-md-6">
                    <div class="form-group <?= hasErr('password',$errors)?'has-error':'' ?>">
                      <label>Password *</label>
                      <input type="password" class="form-control" name="password" required>
                      <?php if (hasErr('password',$errors)): ?><span class="help-block"><?= esc($errors['password']) ?></span><?php endif; ?>
                    </div>
                  </div>

                  <div class="col-md-6">
                    <div class="form-group <?= hasErr('password2',$errors)?'has-error':'' ?>">
                      <label>Conferma Password *</label>
                      <input type="password" class="form-control" name="password2" required>
                      <?php if (hasErr('password2',$errors)): ?><span class="help-block"><?= esc($errors['password2']) ?></span><?php endif; ?>
                    </div>
                  </div>
                </div>

                <div class="row">
                  <div class="col-md-6">
                    <div class="form-group <?= hasErr('cellulare',$errors)?'has-error':'' ?>">
                      <label>Cellulare *</label>
                      <input class="form-control" name="cellulare" value="<?= oldv('cellulare',$old) ?>" required>
                      <?php if (hasErr('cellulare',$errors)): ?><span class="help-block"><?= esc($errors['cellulare']) ?></span><?php endif; ?>
                    </div>
                  </div>

                  <div class="col-md-6">
                    <div class="form-group <?= hasErr('email',$errors)?'has-error':'' ?>">
                      <label>Email (opzionale)</label>
                      <input type="email" class="form-control" name="email" value="<?= oldv('email',$old) ?>">
                      <?php if (hasErr('email',$errors)): ?><span class="help-block"><?= esc($errors['email']) ?></span><?php endif; ?>
                    </div>
                  </div>
                </div>

                <div class="row">
                  <div class="col-md-6">
                    <div class="form-group <?= hasErr('tipo',$errors)?'has-error':'' ?>">
                      <label>Tipo *</label>
                      <select class="form-control" name="tipo" required>
                        <option value="">Seleziona...</option>
                        <?php foreach (($tipi ?? []) as $t): ?>
                          <option value="<?= (int)$t['id_type_doctors'] ?>"
                            <?= ((string)($old['tipo'] ?? '') === (string)$t['id_type_doctors']) ? 'selected' : '' ?>>
                            <?= esc($t['des_tipo'] ?? ('Tipo #' . $t['id_type_doctors'])) ?>
                          </option>
                        <?php endforeach; ?>
                      </select>
                      <?php if (hasErr('tipo',$errors)): ?><span class="help-block"><?= esc($errors['tipo']) ?></span><?php endif; ?>
                    </div>
                  </div>

                  <div class="col-md-6">
                    <div class="form-group <?= (hasErr('luoghi',$errors) || hasErr('luogo',$errors))?'has-error':'' ?>">
                      <label>Luogo *</label>
                      <input type="hidden" name="luogo" id="luogo_primary" value="<?= esc($oldLuoghi[0] ?? '') ?>">
                      <select class="form-control" name="luoghi[]" id="luoghi" multiple size="4" required>
                        <option value="__all__" <?= $oldAllLuoghi ? 'selected' : '' ?>>Tutti i luoghi</option>
                        <?php foreach (($gruppi ?? []) as $g): ?>
                          <option value="<?= (int)$g['id_gruppo'] ?>"
                            <?= ($oldAllLuoghi || in_array((string)$g['id_gruppo'], $oldLuoghi, true)) ? 'selected' : '' ?>>
                            <?= esc($g['nome']) ?>
                          </option>
                        <?php endforeach; ?>
                      </select>
                      <p class="text-muted" style="margin:6px 0 0 0;">
                        Per segretaria e infermiera puoi selezionare piu luoghi o "Tutti i luoghi".
                      </p>
                      <?php if (hasErr('luoghi',$errors)): ?><span class="help-block"><?= esc($errors['luoghi']) ?></span><?php endif; ?>
                      <?php if (hasErr('luogo',$errors)): ?><span class="help-block"><?= esc($errors['luogo']) ?></span><?php endif; ?>
                    </div>
                  </div>
                </div>

                <div class="row">
                  <div class="col-md-6">
                    <div class="checkbox">
                      <label>
                        <input type="checkbox" name="sostituto" value="1" <?= !empty($old['sostituto']) ? 'checked' : '' ?>>
                        Flag sostituto
                      </label>
                    </div>
                  </div>
                  <div class="col-md-6">
                    <div class="checkbox">
                      <label>
                        <input type="checkbox" name="titolare" value="1" <?= !empty($old['titolare']) ? 'checked' : '' ?>>
                        Flag titolare
                      </label>
                    </div>
                  </div>
                </div>

                <div class="row" style="margin-top:6px;">
                  <div class="col-md-12">
                    <label>Visibilita moduli</label>
                    <p class="text-muted" style="margin:4px 0 8px 0;">
                      Decide in quali moduli il personale deve comparire. Per i dottori puoi tenerlo visibile in agenda ma nasconderlo da posta e chat.
                    </p>
                  </div>
                  <div class="col-md-4">
                    <div class="checkbox">
                      <label>
                        <input type="hidden" name="show_in_agenda" value="0">
                        <input type="checkbox" name="show_in_agenda" value="1" <?= $oldShowInAgenda ? 'checked' : '' ?>>
                        Visibile in agenda
                      </label>
                    </div>
                  </div>
                  <div class="col-md-4">
                    <div class="checkbox">
                      <label>
                        <input type="hidden" name="show_in_posta" value="0">
                        <input type="checkbox" name="show_in_posta" value="1" <?= $oldShowInPosta ? 'checked' : '' ?>>
                        Visibile in posta
                      </label>
                    </div>
                  </div>
                  <div class="col-md-4">
                    <div class="checkbox">
                      <label>
                        <input type="hidden" name="show_in_chat" value="0">
                        <input type="checkbox" name="show_in_chat" value="1" <?= $oldShowInChat ? 'checked' : '' ?>>
                        Visibile in chat
                      </label>
                    </div>
                  </div>
                </div>

                <p class="text-muted" style="margin:8px 0 0 0;">
                  * Campi obbligatori (email opzionale). Username NON cifrato, tutti gli altri campi cifrati.
                </p>

              </div>

              <div class="box-footer">
                <button class="btn btn-primary" type="submit">
                  <i class="fa fa-save"></i> Salva
                </button>
                <a class="btn btn-default" href="<?= site_url('admin') ?>">Annulla</a>
              </div>

            </form>
          </div>
        </div>

      </div>
    </section>
  </div>

  <footer class="main-footer">
    <div class="pull-right hidden-xs"><b>Version</b> 2.0</div>
    <strong>&copy; AmbulatoriCLOUD</strong>
  </footer>

  <aside class="control-sidebar control-sidebar-dark"></aside>
  <div class='control-sidebar-bg'></div>
</div>

<script src="<?= base_url('public/plugins/jQuery/jQuery-2.1.4.min.js') ?>"></script>
<script src="<?= base_url('public/bootstrap/js/bootstrap.min.js') ?>"></script>
<script src="<?= base_url('public/plugins/slimScroll/jquery.slimscroll.min.js') ?>"></script>
<script src="<?= base_url('public/plugins/fastclick/fastclick.min.js') ?>"></script>
<script src="<?= base_url('public/dist/js/app.min.js') ?>"></script>
</body>
</html>
<script>
(function(){
  function slug(s){
    return String(s || '')
      .trim()
      .toLowerCase()
      .replace(/Ã /g,'a').replace(/Ã¨/g,'e').replace(/Ã©/g,'e').replace(/Ã¬/g,'i').replace(/Ã²/g,'o').replace(/Ã¹/g,'u')
      .replace(/[^a-z0-9]+/g,'.')
      .replace(/^\.+|\.+$/g,'')
      .replace(/\.+/g,'.');
  }

  var $nome = document.getElementById('nome');
  var $cognome = document.getElementById('cognome');
  var $user = document.getElementById('username');
  if (!$nome || !$cognome || !$user) return;

  // stato: true = username deve seguire nome+cognome
  var auto = true;

  function buildUsername(){
    var n = slug($nome.value);
    var c = slug($cognome.value);
    if (!n && !c) return '';
    if (n && c) return (n + '_' + c);
    return (n || c);
  }

  function applyAuto(){
    if (!auto) return;
    $user.value = buildUsername();
  }

  // Se l'utente modifica username a mano => auto = false (finchÃ© non cambia nome/cognome)
  $user.addEventListener('input', function(){
    auto = false;
  });

  // Se cambia nome o cognome => torna auto e rigenera
  function onNameChange(){
    auto = true;
    applyAuto();
  }

  $nome.addEventListener('input', onNameChange);
  $cognome.addEventListener('input', onNameChange);

  // init: se username Ã¨ vuoto => auto
  // se username giÃ  valorizzato (old) => lascialo, ma appena tocchi nome/cognome si rigenera
  if (!$user.value) applyAuto();
})();
</script>
<script>
(function(){
  var sel = document.getElementById('luoghi');
  var primary = document.getElementById('luogo_primary');
  if (!sel || !primary) return;

  function syncLuoghi(){
    var allOption = Array.prototype.find.call(sel.options, function(opt){ return opt.value === '__all__'; });
    if (allOption && allOption.selected) {
      Array.prototype.forEach.call(sel.options, function(opt){
        opt.selected = opt.value !== '__all__';
      });
    }

    var selected = Array.prototype.filter.call(sel.options, function(opt){
      return opt.selected && opt.value !== '__all__';
    }).map(function(opt){ return opt.value; });

    primary.value = selected[0] || '';
  }

  sel.addEventListener('change', syncLuoghi);
  syncLuoghi();
})();
</script>


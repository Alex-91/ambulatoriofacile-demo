<?php

/**
 * posta.php - Lista messaggi con:
 * - paginazione (page)
 * - scelta risultati per pagina (per_page: 5/10/25/50/100)
 * - mantenimento contesto (q/status/id_dottore/page/per_page) in tutti i link e form
 *
 * @var array       $rows
 * @var string      $folder   'inbox'|'sent'|'drafts'
 * @var string|null $q
 * @var array|null  $pager    ['page'=>1,'perPage'=>25,'total'=>0,'pages'=>0]
 * @var string|null $status   'unhandled'|'handled'|'all' (solo inbox)
 */

$result = session()->get('menuData');
$menu_items = $menu_items ?? ($result['result'] ?? []);
$dottori = $dottori ?? [];
$contDott = (int)($contDott ?? 0);
$selectedDoctorId = $selectedDoctorId ?? null;
$showDoctorsFilter = (bool)($showDoctorsFilter ?? false);

$isSentFolder  = ($folder === 'sent');
$isDraftFolder = ($folder === 'drafts');
$isPatientMailbox = strtoupper(trim((string)($roleLabel ?? ''))) === 'PAZIENTE';

$boxTitle = $isDraftFolder ? 'Bozze' : ($isSentFolder ? 'Posta inviata' : 'Posta in Arrivo');

// Leggi i filtri da GET se non passati
try {
  $req = service('request');
  $qGet = (string)($req->getGet('q') ?? '');
  if (($q ?? '') === '' && $qGet !== '') $q = $qGet;
} catch (\Throwable $e) {
  if (($q ?? '') === '' && isset($_GET['q'])) $q = (string)$_GET['q'];
}

// doctorFilter (solo inbox segreteria/infermiere)
$doctorFilter = (int)($doctorFilter ?? 0);
try {
  $doctorFilterGet = (int)(service('request')->getGet('id_dottore') ?? 0);
  if ($doctorFilterGet > 0) {
    $doctorFilter = $doctorFilterGet;
  }
} catch (\Throwable $e) {
  $doctorFilterGet = (int)($_GET['id_dottore'] ?? 0);
  if ($doctorFilterGet > 0) {
    $doctorFilter = $doctorFilterGet;
  }
}

// status (solo inbox)
$defaultStatus = $isPatientMailbox ? 'all' : 'unhandled';
$status = strtolower(trim((string)($status ?? $defaultStatus)));
try {
  $stGet = (string)(service('request')->getGet('status') ?? '');
  if ($stGet !== '') $status = strtolower(trim($stGet));
} catch (\Throwable $e) {
  if (!empty($_GET['status'])) $status = strtolower(trim((string)$_GET['status']));
}
if (!in_array($status, ['unhandled','handled','all'], true)) $status = $defaultStatus;
if ($isPatientMailbox && !$isDraftFolder && !$isSentFolder) $status = 'all';

// pager
$pager  = $pager ?? ['page'=>1,'perPage'=>25,'total'=>0,'pages'=>0];
$page   = max(1, (int)($pager['page'] ?? 1));
$perPage= (int)($pager['perPage'] ?? 25);
$total  = (int)($pager['total'] ?? 0);
$pages  = (int)($pager['pages'] ?? 0);

$allowedPerPage = [5,10,25,50,100];
if (!in_array($perPage, $allowedPerPage, true)) $perPage = 25;

// base params da portarsi dietro ovunque
$baseParams = [
  'page'     => $page,
  'per_page' => $perPage,
  'q'        => $q ?? '',
];

if (!$isDraftFolder && !$isSentFolder) {
  $baseParams['status'] = $status ?? 'all';
  if ($doctorFilter > 0) $baseParams['id_dottore'] = (int)$doctorFilter;
}

// route base per la lista corrente
$listUrl = $isDraftFolder ? site_url('messaggi/bozze') : ($isSentFolder ? site_url('messaggi/inviati') : site_url('messaggi/inbox'));

/**
 * REGOLA UNICA:
 * - diretto: Nome Cognome mittente (sender)
 * - inoltrato: "Inoltro da X per conto di Nome Cognome (root author)"
 * - niente ID in pagina
 */
function fullName($nome, $cognome): string {
  $nome = trim((string)$nome);
  $cognome = trim((string)$cognome);
  return trim($nome . ' ' . $cognome);
}

function forwardLabelFromRole(string $role): string {
  $r = strtoupper(trim($role));
  if ($r === 'SEGRETERIA') return 'Inoltro da segreteria per conto di ';
  if ($r === 'INFERMIERE') return 'Inoltro da infermiere per conto di ';
  if ($r === 'DOTTORE')    return 'Inoltro da dottore per conto di ';
  return 'Inoltro per conto di ';
}

function buildFromText(array $row, bool $isDraftFolder): string {
  if ($isDraftFolder) return 'Bozza';

  $isForward     = (strtoupper((string)($row['message_type'] ?? '')) === 'FORWARD');
  $threadLabel   = trim((string)($row['thread_counterpart_display'] ?? ''));
  $senderDisplay = trim((string)($row['sender_display'] ?? ''));
  $senderRole    = strtoupper(trim((string)($row['sender_role'] ?? '')));

  if (!$isForward) {
    if ($threadLabel !== '') return $threadLabel;
    if ($senderDisplay !== '') return $senderDisplay;

    if ($senderRole === 'SEGRETERIA') return 'Segreteria';
    if ($senderRole === 'INFERMIERE') return 'Infermiere';

    $senderName = fullName($row['sender_nome'] ?? '', $row['sender_cognome'] ?? '');
    return $senderName !== '' ? $senderName : 'Mittente';
  }

  $prefix   = forwardLabelFromRole((string)($row['sender_role'] ?? ''));
  $rootName = fullName($row['root_nome'] ?? '', $row['root_cognome'] ?? '');

  if ($rootName !== '') return $prefix . $rootName;

  return $prefix . 'utente';
}

// helper url builder
$mkListUrl = function(array $params) use ($listUrl): string {
  // pulizia valori null / '' (ma lascia 0 dove serve)
  $clean = [];
  foreach ($params as $k => $v) {
    if ($v === null) continue;
    if ($v === '' && $k !== 'q') continue; // q puÃ² essere ''
    $clean[$k] = $v;
  }
  return $listUrl . (empty($clean) ? '' : ('?' . http_build_query($clean)));
};

// query string per thread (include folder)
$threadParams = $baseParams;
$threadParams['folder'] = $folder;
$threadQs = http_build_query($threadParams);
?>
<!doctype html>
<html>
<head>
  <meta charset="UTF-8">
  <title><?= esc('AmbulatoriCLOUD') ?> | Posta</title>
  <link rel="icon" href="<?= base_url('public/assets/images/logonew.jpg') ?>" type="image/x-icon" sizes="any">
  <meta content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no" name="viewport">

  <link href="<?= base_url('public/bootstrap/css/bootstrap.min.css') ?>" rel="stylesheet" type="text/css" />
  <link href="https://maxcdn.bootstrapcdn.com/font-awesome/4.3.0/css/font-awesome.min.css" rel="stylesheet" type="text/css" />
  <link href="https://code.ionicframework.com/ionicons/2.0.1/css/ionicons.min.css" rel="stylesheet" type="text/css" />
  <link href="<?= base_url('public/dist/css/AdminLTE.css') ?>" rel="stylesheet" type="text/css" />
  <link href="<?= base_url('public/dist/css/skins/_all-skins.min.css') ?>" rel="stylesheet" type="text/css" />
  <link href="<?= base_url('public/plugins/iCheck/flat/blue.css') ?>" rel="stylesheet" type="text/css" />

  <style>
    .nav-pills.nav-stacked > li.active > a { background-color:#2c8895; color:#fff; }
    .pagination > li > a, .pagination > li > span { padding: 4px 8px; }
  </style>
</head>

<body class="skin-blue sidebar-mini">
<div class="wrapper">

  <?= view('partials/header', ['menu_items' => $menu_items ?? []]) ?>

  <aside class="main-sidebar" style="display:none">
    <section class="sidebar"></section>
  </aside>

  <div class="content-wrapper">
    <section class="content-header">
      <h1>Posta <small></small></h1>
      <ol class="breadcrumb">
        <li><a href="#"><i class="fa fa-dashboard"></i> Home</a></li>
        <li class="active">Posta</li>
      </ol>
    </section>

    <section class="content">
      <div class="row">

        <div class="col-md-3">
          <a href="<?= site_url('messaggi/scrivi') ?>" class="btn btn-primary btn-block margin-bottom">
            Scrivi nuovo messaggio
          </a>

          <?= view('partials/sidebar_posta', [
            'menu_items' => $menu_items ?? [],
            'dottori' => $dottori,
            'contDott' => $contDott,
            'selectedDoctorId' => $selectedDoctorId,
            'showDoctorsFilter' => $showDoctorsFilter,
          ]) ?>
        </div>

        <div class="col-md-9">
          <div class="box box-primary">
            <div class="box-header with-border">
              <h3 class="box-title"><?= esc($boxTitle) ?></h3>

              <div class="box-tools pull-right">
                <!-- SEARCH: mantiene per_page e resetta page=1 -->
                <form method="get"
                      action="<?= esc($listUrl) ?>"
                      style="display:flex; gap:6px; align-items:center; margin:0;">
                  <?php
                    $searchParams = $baseParams;
                    $searchParams['page'] = 1;
                    // q sarÃ  l'input visibile, gli altri hidden
                    foreach ($searchParams as $k => $v) {
                      if ($k === 'q') continue;
                      echo '<input type="hidden" name="'.esc($k).'" value="'.esc((string)$v).'">';
                    }
                  ?>
                  <div class="has-feedback" style="max-width:180px;">
                    <input type="text" name="q" class="form-control input-sm" placeholder="Cercaâ€¦"
                           value="<?= esc($q ?? '') ?>">
                    <span class="glyphicon glyphicon-search form-control-feedback"></span>
                  </div>
                </form>
              </div>
            </div>

            <div class="box-body no-padding">

              <!-- FORM AZIONI MULTIPLE -->
              <form id="bulkForm" method="post" action="<?= site_url('messaggi/elimina-multiplo') ?>" style="margin:0;">
                <?= csrf_field() ?>

                <!-- contesto da mantenere per redirect -->
                <input type="hidden" name="folder" value="<?= esc($folder) ?>">
                <input type="hidden" name="page" value="<?= (int)$page ?>">
                <input type="hidden" name="per_page" value="<?= (int)$perPage ?>">
                <input type="hidden" name="q" value="<?= esc((string)($q ?? '')) ?>">

                <?php if (!$isDraftFolder && !$isSentFolder): ?>
                  <input type="hidden" name="status" value="<?= esc($status) ?>">
                <?php endif; ?>

                <?php if (!$isDraftFolder && !$isSentFolder && $doctorFilter > 0): ?>
                  <input type="hidden" name="id_dottore" value="<?= (int)$doctorFilter ?>">
                <?php endif; ?>

                <!-- usati per bulk gestita/non gestita -->
                <input type="hidden" name="handled" id="bulkHandledVal" value="1">
                <input type="hidden" name="bulkAction" id="bulkAction" value="delete">

                <div class="mailbox-controls">

                  <button class="btn btn-default btn-sm checkbox-toggle" type="button">
                    <i class="fa fa-square-o"></i>
                  </button>

                  <?php if (!$isDraftFolder): ?>
                    <div class="btn-group">
                      <button id="bulkDeleteBtn" class="btn btn-default btn-sm" type="submit"
                              title="Elimina selezionati" disabled>
                        <i class="fa fa-trash-o"></i>
                      </button>
                    </div>
                  <?php endif; ?>

                  <button class="btn btn-default btn-sm" type="button" onclick="location.reload()">
                    <i class="fa fa-refresh"></i>
                  </button>

                  <div class="pull-right" style="display:flex; gap:8px; align-items:center;">

                    <?php if (!$isDraftFolder && !$isSentFolder && !$isPatientMailbox): ?>
                      <div class="btn-group">
                        <button id="bulkHandledBtn" class="btn btn-default btn-sm" type="button" title="Segna gestite" disabled>
                          <i class="fa fa-check"></i>
                        </button>
                        <button id="bulkUnhandledBtn" class="btn btn-default btn-sm" type="button" title="Segna non gestite" disabled>
                          <i class="fa fa-undo"></i>
                        </button>
                      </div>
                    <?php endif; ?>

                    <!-- PER PAGE: mantiene filtri e resetta page=1 -->
                    <form method="get"
                          action="<?= esc($listUrl) ?>"
                          style="display:flex; gap:6px; align-items:center; margin:0;">
                      <?php
                        $ppParams = $baseParams;
                        $ppParams['page'] = 1;
                        foreach ($ppParams as $k=>$v) {
                          if ($k === 'per_page') continue;
                          echo '<input type="hidden" name="'.esc($k).'" value="'.esc((string)$v).'">';
                        }
                      ?>
                      <label style="margin:0; font-weight:normal;">Mostra</label>
                      <select name="per_page" class="form-control input-sm" style="width:90px;" onchange="this.form.submit()">
                        <?php foreach ($allowedPerPage as $n): ?>
                          <option value="<?= $n ?>" <?= $n===$perPage ? 'selected' : '' ?>><?= $n ?></option>
                        <?php endforeach; ?>
                      </select>
                    </form>

                  </div>
                </div>

                <div class="table-responsive mailbox-messages">
                  <table class="table table-hover table-striped">
                    <tbody>
                    <?php if (!empty($rows)): ?>
                      <?php foreach ($rows as $row): ?>
                        <?php
                          $threadId = (int)($row['id_thread'] ?? 0);
                          $fromText = buildFromText($row, $isDraftFolder);

                          $preview = '';
                          if (!empty($row['body_plain'])) {
                            $preview = mb_strimwidth(trim((string)$row['body_plain']), 0, 80, '...');
                          }

                          $isRead = $isSentFolder || $isDraftFolder ? true : ((int)($row['is_read'] ?? 0) === 1);
                          $date = $row['created_human'] ?? ($row['created_at'] ?? '');
                          $messageId = (int)($row['id_message'] ?? 0);
                        ?>
                        <tr data-thread="<?= $threadId ?>" style="cursor:pointer">
                          <td style="width:34px;">
                            <?php if (!$isDraftFolder && $messageId > 0): ?>
                              <input type="checkbox" class="row-check" name="ids[]" value="<?= $messageId ?>" />
                            <?php else: ?>
                              <input type="checkbox" class="row-check" disabled />
                            <?php endif; ?>
                          </td>

                          <td class="mailbox-name" style="white-space:nowrap;">
                            <?= esc($fromText) ?>
                          </td>

                          <td class="mailbox-subject">
                            <?php if (!$isRead): ?><b><?php endif; ?>
                            <?= $preview !== '' ? esc($preview) : '<span class="text-muted">(nessun testo)</span>' ?>
                            <?php if (!$isRead): ?></b><?php endif; ?>
                          </td>

                          <td class="mailbox-attachment" style="width:28px; text-align:center;">
                            <?php if (!empty($row['has_attachments'])): ?><i class="fa fa-paperclip"></i><?php endif; ?>
                          </td>

                          <td class="mailbox-date" style="white-space:nowrap;">
                            <?= esc((string)$date) ?>
                          </td>

                          <?php if (!$isDraftFolder && !$isSentFolder && !$isPatientMailbox): ?>
                            <?php
                              $isHandled = (int)($row['is_handled'] ?? 0);
                              $badgeClass = $isHandled ? 'label-success' : 'label-danger';
                              $badgeText  = $isHandled ? 'Gestita' : 'Non gestita';
                              $nextHandled = $isHandled ? 0 : 1;
                            ?>
                            <td style="width:90px; white-space:nowrap;">
                              <form method="post" action="<?= site_url('messaggi/gestita/' . (int)$messageId) ?>" style="display:inline;">
                                <?= csrf_field() ?>
                                <input type="hidden" name="handled" value="<?= $nextHandled ?>">

                                <!-- contesto per redirect -->
                                <input type="hidden" name="folder" value="<?= esc($folder) ?>">
                                <input type="hidden" name="page" value="<?= (int)$page ?>">
                                <input type="hidden" name="per_page" value="<?= (int)$perPage ?>">
                                <input type="hidden" name="status" value="<?= esc($status) ?>">
                                <input type="hidden" name="q" value="<?= esc((string)($q ?? '')) ?>">
                                <?php if ($doctorFilter > 0): ?>
                                  <input type="hidden" name="id_dottore" value="<?= (int)$doctorFilter ?>">
                                <?php endif; ?>

                                <button type="submit" class="label <?= $badgeClass ?>" style="border:0; cursor:pointer;"
                                        onclick="event.stopPropagation();">
                                  <?= esc($badgeText) ?>
                                </button>
                              </form>
                            </td>
                          <?php endif; ?>

                        </tr>
                      <?php endforeach; ?>
                    <?php else: ?>
                      <tr>
                        <?php $emptyColspan = (!$isDraftFolder && !$isSentFolder && !$isPatientMailbox) ? 8 : 7; ?>
                        <td colspan="<?= $emptyColspan ?>" class="text-center text-muted">Nessun messaggio</td>
                      </tr>
                    <?php endif; ?>
                    </tbody>
                  </table>
                </div>

                <!-- PAGINAZIONE -->
                <?php if ($pages > 1): ?>
                  <div style="padding:10px 15px; display:flex; justify-content:space-between; align-items:center; border-top:1px solid #f4f4f4;">
                    <div style="color:#777;">
                      Totale: <?= (int)$total ?> â€” Pagina <?= (int)$page ?> di <?= (int)$pages ?>
                    </div>

                    <ul class="pagination pagination-sm" style="margin:0;">
                      <?php
                        $prev = max(1, $page-1);
                        $next = min($pages, $page+1);

                        $start = max(1, $page-2);
                        $end   = min($pages, $page+2);

                        // se ci sono molte pagine, allarga un po' la finestra
                        if ($pages <= 7) { $start = 1; $end = $pages; }
                      ?>

                      <li class="<?= $page<=1 ? 'disabled' : '' ?>">
                        <a href="<?= esc($mkListUrl(array_merge($baseParams, ['page'=>$prev]))) ?>">&laquo;</a>
                      </li>

                      <?php if ($start > 1): ?>
                        <li><a href="<?= esc($mkListUrl(array_merge($baseParams, ['page'=>1]))) ?>">1</a></li>
                        <?php if ($start > 2): ?><li class="disabled"><span>â€¦</span></li><?php endif; ?>
                      <?php endif; ?>

                      <?php for ($p=$start; $p<=$end; $p++): ?>
                        <li class="<?= $p===$page ? 'active' : '' ?>">
                          <a href="<?= esc($mkListUrl(array_merge($baseParams, ['page'=>$p]))) ?>"><?= (int)$p ?></a>
                        </li>
                      <?php endfor; ?>

                      <?php if ($end < $pages): ?>
                        <?php if ($end < $pages-1): ?><li class="disabled"><span>â€¦</span></li><?php endif; ?>
                        <li><a href="<?= esc($mkListUrl(array_merge($baseParams, ['page'=>$pages]))) ?>"><?= (int)$pages ?></a></li>
                      <?php endif; ?>

                      <li class="<?= $page>=$pages ? 'disabled' : '' ?>">
                        <a href="<?= esc($mkListUrl(array_merge($baseParams, ['page'=>$next]))) ?>">&raquo;</a>
                      </li>
                    </ul>
                  </div>
                <?php endif; ?>

              </form>
              <!-- /FORM AZIONI MULTIPLE -->

            </div>

            <div class="box-footer no-padding">
              <div class="mailbox-controls">

                <button class="btn btn-default btn-sm checkbox-toggle" type="button">
                  <i class="fa fa-square-o"></i>
                </button>

                <?php if (!$isDraftFolder): ?>
                  <button class="btn btn-default btn-sm" type="button" title="Elimina selezionati" disabled id="bulkDeleteBtnFooter">
                    <i class="fa fa-trash-o"></i>
                  </button>
                <?php endif; ?>

                <button class="btn btn-default btn-sm" type="button" onclick="location.reload()">
                  <i class="fa fa-refresh"></i>
                </button>

                <div class="pull-right" style="display:flex; gap:10px; align-items:center;">

                  <?php if (!$isDraftFolder && !$isSentFolder && !$isPatientMailbox): ?>
                    <?php
                      // link filtri stato: mantieni per_page, q, id_dottore e resetta page=1
                      $qsCommon = [
                        'page'     => 1,
                        'per_page' => $perPage,
                        'q'        => (string)($q ?? ''),
                      ];
                      if ($doctorFilter > 0) $qsCommon['id_dottore'] = (int)$doctorFilter;

                      $urlAll       = site_url('messaggi/inbox') . '?' . http_build_query(array_merge($qsCommon, ['status'=>'all']));
                      $urlHandled   = site_url('messaggi/inbox') . '?' . http_build_query(array_merge($qsCommon, ['status'=>'handled']));
                      $urlUnhandled = site_url('messaggi/inbox') . '?' . http_build_query(array_merge($qsCommon, ['status'=>'unhandled']));
                    ?>
                    <div class="btn-group" style="margin-left:10px;">
                      <a class="btn btn-default btn-sm <?= $status==='all'?'active':'' ?>" href="<?= esc($urlAll) ?>">Tutte</a>
                      <a class="btn btn-default btn-sm <?= $status==='handled'?'active':'' ?>" href="<?= esc($urlHandled) ?>">Gestite</a>
                      <a class="btn btn-default btn-sm <?= $status==='unhandled'?'active':'' ?>" href="<?= esc($urlUnhandled) ?>">Non gestite</a>
                    </div>
                  <?php endif; ?>

                  <!-- PER PAGE anche nel footer: reset page=1 -->
                  <form method="get"
                        action="<?= esc($listUrl) ?>"
                        style="display:flex; gap:6px; align-items:center; margin:0;">
                    <?php
                      $ppParams2 = $baseParams;
                      $ppParams2['page'] = 1;
                      foreach ($ppParams2 as $k=>$v) {
                        if ($k === 'per_page') continue;
                        echo '<input type="hidden" name="'.esc($k).'" value="'.esc((string)$v).'">';
                      }
                    ?>
                    <label style="margin:0; font-weight:normal;">Mostra</label>
                    <select name="per_page" class="form-control input-sm" style="width:90px;" onchange="this.form.submit()">
                      <?php foreach ($allowedPerPage as $n): ?>
                        <option value="<?= $n ?>" <?= $n===$perPage ? 'selected' : '' ?>><?= $n ?></option>
                      <?php endforeach; ?>
                    </select>
                  </form>

                </div>

              </div>
            </div>

          </div>
        </div>

      </div>
    </section>
  </div>
</div>


<script src="<?= base_url('public/plugins/iCheck/icheck.min.js') ?>"></script>
<script src="<?= base_url('public/dist/js/app.min.js') ?>"></script>

<script>
  $(function () {
    $('.mailbox-messages input[type="checkbox"]').iCheck({
      checkboxClass: 'icheckbox_flat-blue'
    });

    // open thread on row click (mantieni page/per_page/q/status/id_dottore/folder)
    $(".mailbox-messages").on("click", "tr[data-thread]", function(e){
      if ($(e.target).is('input, a, button, i, form, label')) return;
      var tid = $(this).data('thread');
      if (!tid) return;
      window.location.href = "<?= site_url('messaggi/thread') ?>/" + tid + "?<?= esc($threadQs) ?>";
    });

    function updateBulkButtonsState() {
      var any = $(".mailbox-messages input[name='ids[]']:checked").length > 0;
      $("#bulkDeleteBtn, #bulkDeleteBtnFooter").prop("disabled", !any);
      $("#bulkHandledBtn, #bulkUnhandledBtn").prop("disabled", !any);
    }

    // eventi iCheck
    $(".mailbox-messages").on("ifChecked ifUnchecked", "input[name='ids[]']", function(){
      updateBulkButtonsState();
    });

    // fallback change
    $(".mailbox-messages").on("change", "input[name='ids[]']", function(){
      updateBulkButtonsState();
    });

    updateBulkButtonsState();

    // Footer delete -> submit bulkForm
    $("#bulkDeleteBtnFooter").on("click", function(){
      $("#bulkForm").attr("action", "<?= site_url('messaggi/elimina-multiplo') ?>").submit();
    });

    // submit bulkForm: conferma solo quando Ã¨ delete multiplo
    $("#bulkForm").on("submit", function(e){
      if ($(".mailbox-messages input[name='ids[]']:checked").length === 0){
        e.preventDefault();
        return;
      }
      var action = $(this).attr("action") || "";
      if (action.indexOf("elimina-multiplo") !== -1) {
        if (!confirm("Eliminare i messaggi selezionati?")){
          e.preventDefault();
        }
      }
    });

    // bulk: segna gestite
    $("#bulkHandledBtn").on("click", function(){
      if ($(".mailbox-messages input[name='ids[]']:checked").length === 0) return;
      $("#bulkHandledVal").val("1");
      $("#bulkForm")
        .attr("action", "<?= site_url('messaggi/gestita-multiplo') ?>")
        .submit();
    });

    // bulk: segna non gestite
    $("#bulkUnhandledBtn").on("click", function(){
      if ($(".mailbox-messages input[name='ids[]']:checked").length === 0) return;
      $("#bulkHandledVal").val("0");
      $("#bulkForm")
        .attr("action", "<?= site_url('messaggi/gestita-multiplo') ?>")
        .submit();
    });

    // checkbox toggle
    $(".checkbox-toggle").click(function () {
      var clicks = $(this).data('clicks');
      if (clicks) {
        $(".mailbox-messages input[type='checkbox']").iCheck("uncheck");
        $(".fa", this).removeClass("fa-check-square-o").addClass('fa-square-o');
      } else {
        $(".mailbox-messages input[type='checkbox']").iCheck("check");
        $(".fa", this).removeClass("fa-square-o").addClass('fa-check-square-o');
      }
      $(this).data("clicks", !clicks);
      updateBulkButtonsState();
    });
  });
</script>

</body>
</html>


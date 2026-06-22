<?php
/**
 * read.php - Thread view (stile read.php_old) - FIX: inoltro/risposta toggle + elimina POST
 *
 * @var array  $messages (ASC)
 * @var string $box      'inbox'|'sent'
 */

use CodeIgniter\I18n\Time;

$tz = 'Europe/Rome';
$threadMsgs = $messages ?? [];
$current = !empty($threadMsgs) ? $threadMsgs[count($threadMsgs)-1] : null; // ultimo messaggio

$threadMsgById = [];
$forwardParentIds = [];
foreach ($threadMsgs as $threadMsg) {
  $threadMsgId = (int)($threadMsg['id_message'] ?? 0);
  if ($threadMsgId > 0) {
    $threadMsgById[$threadMsgId] = $threadMsg;
  }

  $threadMsgType = strtoupper((string)($threadMsg['message_type'] ?? ''));
  $parentMsgId = (int)($threadMsg['parent_message_id'] ?? 0);
  if ($threadMsgType === 'FORWARD' && $parentMsgId > 0) {
    $forwardParentIds[$parentMsgId] = true;
  }
}

$tipoUser = (int)(session()->get('tipoUser') ?? 0);
$isClient = ($tipoUser === 3);
$dottori = $dottori ?? [];
$contDott = (int)($contDott ?? 0);
$selectedDoctorId = $selectedDoctorId ?? null;
$showDoctorsFilter = (bool)($showDoctorsFilter ?? false);

// ============================
// ID utente corrente (coerente con service: id_utente = id_client per paziente, id_personale per staff)
// ============================
$meObj    = session()->get('utente_sess');
$meUserId = (int)(is_object($meObj) ? ($meObj->id_utente ?? 0) : 0);

// ============================
// Ruolo logico (coerente col service)
// ============================
$myRole = strtoupper(trim((string)($roleLabel ?? '')));
if (!in_array($myRole, ['PAZIENTE', 'DOTTORE', 'SEGRETERIA', 'INFERMIERE'], true)) {
  if ($tipoUser === 3) {
    $myRole = 'PAZIENTE';
  } elseif ($tipoUser === 2 && is_object($meObj)) {
    $ts = strtoupper((string)($meObj->tipo_stringa ?? ''));
    if ($ts === 'S') $myRole = 'SEGRETERIA';
    elseif ($ts === 'I') $myRole = 'INFERMIERE';
    else $myRole = 'DOTTORE';
  } else {
    $myRole = 'DOTTORE';
  }
}

// ============================
// Doctor context (se ti arriva dalla view ok, altrimenti 0)
// ============================
$doctorContextId = (int)($doctorContextId ?? 0);

// ============================
// ID messaggio da eliminare
// - staff: elimina l'ultimo (current)
// - paziente: elimina l'ultimo msg "accessibile" (mittente=paziente o destinatario USER=paziente),
//             altrimenti fallback ROOT
// ============================
$deleteMessageId = (int)($current['id_message'] ?? 0);

if ($isClient) {
  $deleteMessageId = 0;

  // scegli l'ultimo messaggio "accessibile" al paziente
  for ($i = count($threadMsgs) - 1; $i >= 0; $i--) {
    $m = $threadMsgs[$i];

    $senderOk = ((int)($m['sender_user_id'] ?? 0) === $meUserId);
    $recOk    = (
      (($m['recipient_type'] ?? '') === 'USER')
      && ((int)($m['recipient_user_id'] ?? 0) === $meUserId)
    );

    if ($senderOk || $recOk) {
      $deleteMessageId = (int)$m['id_message'];
      break;
    }
  }

  // fallback: ROOT se non trovato
  if ($deleteMessageId <= 0) {
    foreach ($threadMsgs as $m) {
      if (($m['msg_kind'] ?? '') === 'ROOT') {
        $deleteMessageId = (int)$m['id_message'];
        break;
      }
    }
  }

  // fallback estremo
  if ($deleteMessageId <= 0) {
    $deleteMessageId = (int)($current['id_message'] ?? 0);
  }
}

/**
 * Label mittente per messaggi diretti/forward (mai ID in pagina)
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
  if ($r === 'DOTTORE')    return 'Inoltro da medico per conto di ';
  return 'Inoltro per conto di ';
}
function recipientRoleLabel(string $role): string {
  $r = strtoupper(trim($role));
  if ($r === 'SEGRETERIA') return 'Segreteria';
  if ($r === 'INFERMIERE') return 'Infermieri';
  if ($r === 'DOTTORE')    return 'Medico';
  return 'Destinatario';
}

function displayTo(array $m): string {
  $type = strtoupper((string)($m['recipient_type'] ?? ''));
  $role = strtoupper(trim((string)($m['recipient_role'] ?? '')));

  if ($role !== '') {
    return recipientRoleLabel($role);
  }

  if ($type === 'USER') {
    $to = fullName($m['recipient_nome'] ?? '', $m['recipient_cognome'] ?? '');
    return $to !== '' ? $to : 'Destinatario';
  }

  if ($type === 'ROLE') {
    return recipientRoleLabel($role);
  }

  return 'Destinatario';
}

function displayFrom(array $m): string {

  // âœ… se il service ha giÃ  calcolato la label (SEGRETERIA/INFERMIERE o Nome Cognome)
  $senderDisplay = trim((string)($m['sender_display'] ?? ''));

  $type = strtoupper((string)($m['message_type'] ?? ''));

  // Messaggio normale (ROOT/REPLY): usa sender_display se presente
  if ($type !== 'FORWARD') {
    if ($senderDisplay !== '') return $senderDisplay;

    // fallback vecchio
    $sender = fullName($m['sender_nome'] ?? '', $m['sender_cognome'] ?? '');
    return $sender !== '' ? $sender : 'Mittente';
  }

  // FORWARD: tieni la tua logica "Inoltro da X per conto di Y"
  $prefix = forwardLabelFromRole((string)($m['sender_role'] ?? ''));
  $root   = fullName($m['root_nome'] ?? '', $m['root_cognome'] ?? '');
  if ($root !== '') return $prefix . $root;
  return $prefix . 'utente';
}

function splitForwardBody(string $body): array {
  $body = trim($body);
  $parts = preg_split('/\R\R---\R/u', $body, 2);

  if (is_array($parts) && count($parts) === 2) {
    return [
      'note' => trim((string)$parts[0]),
      'body' => trim((string)$parts[1]),
    ];
  }

  return ['note' => '', 'body' => $body];
}

function attachmentSignature(array $att): string {
  $stored = strtolower(trim((string)($att['stored_name'] ?? '')));
  $original = strtolower(trim((string)($att['original_name'] ?? ($att['nome'] ?? ($att['name'] ?? '')))));
  $mime = strtolower(trim((string)($att['mime_type'] ?? ($att['tipo'] ?? ''))));
  $size = (int)($att['file_size'] ?? 0);

  if ($stored !== '' || $original !== '' || $mime !== '' || $size > 0) {
    return $stored . '|' . $original . '|' . $mime . '|' . $size;
  }

  return strtolower(trim((string)($att['url'] ?? '')));
}

// Data header
$when = $human = '';
if ($current && !empty($current['created_at'])) {
  $t = Time::parse((string)$current['created_at'], $tz);
  $when = $t->toLocalizedString('d MMM y HH:mm');
  $human = $t->humanize();
}

// Menu (header)
$result     = session()->get('menuData');
$menu_items = $menu_items ?? ($result['result'] ?? []);
$patientSendNoticeBody = (string)(session()->getFlashdata('patient_send_notice_body') ?? '');
$flashOk = (string)((session()->getFlashdata('ok') ?: session()->getFlashdata('success')) ?? '');
$flashErr = (string)((session()->getFlashdata('err') ?: session()->getFlashdata('error')) ?? '');
$replyFormOpenId = (int)(session()->getFlashdata('reply_form_open') ?? 0);

$box = $box ?? 'inbox';
$folder  = strtolower(trim((string)($_GET['folder'] ?? ($box ?? 'inbox'))));
if (!in_array($folder, ['inbox','sent','drafts'], true)) $folder = 'inbox';

$page    = (int)($_GET['page'] ?? 1);
$perPage = (int)($_GET['per_page'] ?? 25);
$q       = (string)($_GET['q'] ?? '');
$status  = (string)($_GET['status'] ?? (($folder === 'inbox') ? 'unhandled' : 'all'));
$idDott  = (int)($_GET['id_dottore'] ?? ($selectedDoctorId ?? $doctorContextId ?? 0));

$base = ($folder === 'sent') ? site_url('messaggi/inviati') : (($folder === 'drafts') ? site_url('messaggi/bozze') : site_url('messaggi/inbox'));

$params = [
  'page' => max(1,$page),
  'per_page' => $perPage,
  'q' => $q,
];

if ($folder === 'inbox') {
  $params['status'] = $status;
  if ($idDott > 0) $params['id_dottore'] = $idDott;
}

$backUrl = $base . '?' . http_build_query($params);

// Header â€œDa/Aâ€
$fromName = $current ? displayFrom($current) : 'Mittente';
$toName = $current ? displayTo($current) : 'Destinatario';
$brandName = 'AmbulatorioFacile';
?>
<!DOCTYPE html>
<html>
<head>
  <meta charset="UTF-8">
  <title><?= esc($brandName) ?> | Leggi messaggio</title>
  <meta content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no" name="viewport">
  <link rel="icon" href="<?= base_url('public/assets/images/logonew.jpg') ?>" type="image/x-icon" sizes="any">

  <link href="<?= base_url('public/bootstrap/css/bootstrap.min.css') ?>" rel="stylesheet" type="text/css" />
  <link href="https://maxcdn.bootstrapcdn.com/font-awesome/4.3.0/css/font-awesome.min.css" rel="stylesheet" />
  <link href="https://code.ionicframework.com/ionicons/2.0.1/css/ionicons.min.css" rel="stylesheet" />
  <link href="<?= base_url('public/dist/css/AdminLTE.css') ?>" rel="stylesheet" type="text/css" />
  <link href="<?= base_url('public/dist/css/skins/_all-skins.min.css') ?>" rel="stylesheet" type="text/css" />
  <link href="<?= base_url('public/plugins/iCheck/flat/blue.css') ?>" rel="stylesheet" type="text/css" />

  <style>
    #page-loader { position: fixed; inset: 0; background: rgba(255,255,255,.85); z-index: 9999; display: none; }
    #page-loader .spinner { position: absolute; top: 50%; left: 50%; transform: translate(-50%,-50%); text-align: center; }
    #page-loader .spinner .msg { margin-top: 10px; font-size: 13px; color: #444; letter-spacing: .2px; }

    .mailbox-read-info h3 { margin: 0 0 5px; }
    .mailbox-read-info h5 { margin: 0; color: #777; }
    pre.mail-plain { white-space: pre-wrap; background: #fff; border: none; padding: 0; margin: 0; }

    .thread-wrap { padding: 10px 15px 20px; }
    .thread-item { margin-bottom: 16px; }
    .thread-head { display:flex; justify-content:space-between; align-items:center; margin-bottom:6px; }
    .thread-from { font-weight: 600; }
    .thread-time { font-size: 12px; color: #777; }

    .bubble {
      border: 1px solid #e6e6e6;
      background: #fafafa;
      border-radius: 10px;
      padding: 10px 12px;
    }
    .bubble.me { background: #e6f4ff; border-color: #cfe7ff; }
    .bubble.forward {
      background: #f8fcfb;
      border-color: #cfe7e3;
    }
    .forward-card {
      border-left: 4px solid #2c8895;
      background: #fff;
      border-radius: 4px;
      padding: 9px 11px;
    }
    .forward-card-title {
      color: #2c8895;
      font-weight: 600;
      margin-bottom: 6px;
    }
    .forward-card-title i { margin-right: 4px; }
    .forward-card-note {
      background: #f4fffb;
      border: 1px solid #d9efea;
      border-radius: 4px;
      margin-bottom: 8px;
      padding: 8px;
      white-space: pre-wrap;
    }
    .forward-card-body {
      white-space: pre-wrap;
      word-break: break-word;
    }

    .day-sep {
      text-align: center;
      margin: 14px 0;
      color: #888;
      font-size: 12px;
      position: relative;
    }
    .day-sep:before, .day-sep:after {
      content: '';
      position: absolute;
      top: 50%;
      width: 30%;
      height: 1px;
      background: #ddd;
    }
    .day-sep:before { left: 0; }
    .day-sep:after  { right: 0; }

    .thread-att { margin-top: 8px; display:flex; flex-wrap:wrap; gap:8px; }
    .thread-att-item { border:1px solid #ddd; border-radius:4px; background:#fff; padding:6px 8px; max-width:190px; font-size:12px; }
    .thread-att-item:hover { background:#f5f5f5; }
    .thread-att-item-icon { font-size:24px; margin-bottom:4px; }
    .thread-att-item-name { word-break: break-all; }
    .thread-att-actions { margin-top:4px; text-align:right; }

    .btn-download-file {
      border-radius: 20px;
      border: 1px solid #2c8895;
      color: #2c8895;
      padding: 3px 10px;
      font-size: 11px;
      background: #fff;
    }
    .btn-download-file:hover { background: #2c8895; color:#fff; text-decoration:none; }

    .nav-pills.nav-stacked > li.active > a { background-color:#2c8895; color:#fff; }

    .hidden { display:none !important; }

    body.patient-notice-lock { overflow: hidden; }
    .patient-notice-modal {
      position: fixed;
      inset: 0;
      z-index: 12000;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 16px;
      background: rgba(0, 0, 0, .55);
    }
    .patient-notice-modal__dialog {
      width: 100%;
      max-width: 560px;
    }
    .patient-notice-modal__card {
      background: #fff;
      border-radius: 14px;
      box-shadow: 0 18px 50px rgba(0, 0, 0, .25);
      padding: 22px 22px 18px;
    }
    .patient-notice-modal__title {
      margin: 0 0 14px;
      font-size: 24px;
      line-height: 1.2;
      color: #1f2d3d;
    }
    .patient-notice-modal__body {
      white-space: pre-line;
      font-size: 16px;
      line-height: 1.6;
      color: #334;
    }
    .patient-notice-modal__actions {
      margin-top: 20px;
      text-align: right;
    }
    .patient-notice-modal__ok {
      min-width: 120px;
      border-radius: 999px;
      padding: 10px 20px;
      font-weight: 600;
    }
    @media (max-width: 767px) {
      .patient-notice-modal { padding: 12px; }
      .patient-notice-modal__card { padding: 18px 16px 16px; border-radius: 12px; }
      .patient-notice-modal__title { font-size: 21px; }
      .patient-notice-modal__body { font-size: 15px; }
      .patient-notice-modal__actions { text-align: center; }
      .patient-notice-modal__ok { width: 100%; }
    }
  </style>
</head>

<body class="skin-blue sidebar-mini<?= $patientSendNoticeBody !== '' ? ' patient-notice-lock' : '' ?>">
<div id="page-loader" aria-hidden="true">
  <div class="spinner">
    <i class="fa fa-spinner fa-pulse fa-3x fa-fw"></i>
    <div class="msg">Caricamentoâ€¦</div>
  </div>
</div>

<div class="wrapper">
  <?= view('partials/header', ['menu_items' => $menu_items ?? []]) ?>
  <aside class="main-sidebar" style="display:none"><section class="sidebar"></section></aside>

  <div class="content-wrapper">
    <section class="content-header">
      <h1>Posta <small>lettura</small></h1>
      <ol class="breadcrumb">
        <li><a href="<?= site_url('dashboard') ?>"><i class="fa fa-dashboard"></i> Home</a></li>
        <li><a href="<?= $backUrl ?>">Posta</a></li>
        <li class="active">Leggi</li>
      </ol>
    </section>

    <section class="content">
      <?php if ($flashOk !== ''): ?>
        <div class="alert alert-success"><?= esc($flashOk) ?></div>
      <?php endif; ?>
      <?php if ($flashErr !== ''): ?>
        <div class="alert alert-danger"><?= esc($flashErr) ?></div>
      <?php endif; ?>

      <div class="row">
        <div class="col-md-3">
          <a href="<?= site_url('messaggi/scrivi') ?>" class="btn btn-primary btn-block margin-bottom">Scrivi nuovo messaggio</a>

          <?= view('partials/sidebar_posta', [
            'activeFolder' => ($box === 'sent') ? 'sent' : 'inbox',
            'dottori' => $dottori,
            'contDott' => $contDott,
            'selectedDoctorId' => $selectedDoctorId,
            'showDoctorsFilter' => $showDoctorsFilter,
          ]) ?>
        </div>

        <div class="col-md-9">
          <a href="<?= $backUrl ?>" class="btn btn-default btn-back no-loader">
            <i class="fa fa-chevron-left"></i> Torna alla posta
          </a>

          <div class="box box-primary">
            <div class="box-header with-border">
              <h3 class="box-title">Leggi messaggio</h3>
            </div>

            <div class="box-body no-padding">

              <?php if ($current): ?>
                <div class="mailbox-read-info">
                  <h3>Conversazione</h3>
                  <h5>
                    Da: <?= esc($fromName) ?><br>
                    A: <?= esc($toName) ?>
                    <?php if ($when): ?>
                      <span class="mailbox-read-time pull-right" title="<?= esc($when) ?>">
                        <?= esc($human ?: $when) ?>
                      </span>
                    <?php endif; ?>
                  </h5>
                </div>

                <!-- CONTROLLI -->
                <div class="mailbox-controls with-border text-center">
                  <div class="btn-group">

                    <!-- ELIMINA (POST) -->
                    <form method="post"
                          action="<?= site_url('messaggi/elimina/' . (int)$deleteMessageId) ?>"
                          style="display:inline"
                          class="no-loader">
                          <input type="hidden" name="folder" value="<?= esc($folder) ?>">
<input type="hidden" name="page" value="<?= (int)$page ?>">
<input type="hidden" name="per_page" value="<?= (int)$perPage ?>">
<input type="hidden" name="q" value="<?= esc($q) ?>">
<input type="hidden" name="status" value="<?= esc($status) ?>">
<input type="hidden" name="id_dottore" value="<?= (int)$idDott ?>">

                      <?= csrf_field() ?>
                      <button type="submit" class="btn btn-default btn-sm btn-delete">
                        <i class="fa fa-trash-o"></i> Elimina
                      </button>
                    </form>

                    <!-- RISPOSTA (toggle hidden) -->
                    <button type="button" class="btn btn-default btn-sm"
                            onclick="document.getElementById('replyBox<?= (int)$current['id_message'] ?>').classList.toggle('hidden')">
                      <i class="fa fa-reply"></i> Rispondi
                    </button>

                    <!-- INOLTRO (solo staff) -->
                    <?php if (!$isClient): ?>
                      <button type="button" class="btn btn-default btn-sm"
                              onclick="document.getElementById('fwdBox<?= (int)$current['id_message'] ?>').classList.toggle('hidden')">
                        <i class="fa fa-share"></i> Inoltra
                      </button>
                    <?php endif; ?>
<a href="<?= site_url('messaggi/thread/' . (int)$threadId . '/stampa') ?>"
   class="btn btn-default btn-sm no-loader"
   target="_blank"
   title="Apri PDF della conversazione">
  <i class="fa fa-print"></i> Stampa
</a>
                  </div>
                </div>

                <!-- BOX RISPOSTA -->
                <?php $replyIsOpen = $replyFormOpenId === (int)$current['id_message']; ?>
                <div id="replyBox<?= (int)$current['id_message'] ?>"
                     class="<?= $replyIsOpen ? '' : 'hidden' ?>"
                     style="margin: 15px 15px 0;">
                  <div class="box box-solid" style="margin-bottom:0;">
                    <div class="box-header with-border">
                      <h3 class="box-title"><i class="fa fa-reply"></i> Risposta</h3>
                      <div class="box-tools pull-right">
                        <button type="button" class="btn btn-box-tool"
                                onclick="document.getElementById('replyBox<?= (int)$current['id_message'] ?>').classList.add('hidden')">
                          <i class="fa fa-times"></i>
                        </button>
                      </div>
                    </div>

                    <div class="box-body">
                      <form method="post"
                            enctype="multipart/form-data"
                            action="<?= site_url('messaggi/rispondi/' . (int)$current['id_message']) ?>">
                        <?= csrf_field() ?>

                        <div class="form-group">
                          <textarea name="body"
                                    class="form-control"
                                    rows="4"
                                    placeholder="Scrivi una risposta..."><?= esc((string)old('body')) ?></textarea>
                        </div>

                        <div class="form-group">
                          <label>Allegati</label>
                          <input type="file"
                                 name="files[]"
                                 class="form-control"
                                 multiple
                                 accept=".pdf,.jpg,.jpeg,.png,.doc,.docx,.txt">
                          <small class="text-muted">Puoi inviare testo, allegati o entrambi. Formati: PDF/JPG/PNG/DOC/DOCX/TXT, max 3MB ciascuno.</small>
                        </div>

                        <button class="btn btn-primary btn-sm">
                          <i class="fa fa-send"></i> Invia risposta
                        </button>
                      </form>

                      <small class="text-muted">
                        La risposta verrÃ  inviata al mittente del messaggio selezionato (se Ã¨ un forward, rispondi al forwarder).
                      </small>
                    </div>
                  </div>
                </div>

                <!-- BOX INOLTRO -->
                <?php if (!$isClient): ?>
                  <div id="fwdBox<?= (int)$current['id_message'] ?>"
                       class="hidden"
                       style="margin: 15px 15px 0;">
                    <div class="box box-solid" style="margin-bottom:0;">
                      <div class="box-header with-border">
                        <h3 class="box-title"><i class="fa fa-share"></i> Inoltra</h3>
                        <div class="box-tools pull-right">
                          <button type="button" class="btn btn-box-tool"
                                  onclick="document.getElementById('fwdBox<?= (int)$current['id_message'] ?>').classList.add('hidden')">
                            <i class="fa fa-times"></i>
                          </button>
                        </div>
                      </div>

                      <div class="box-body">
                        <form method="post"
                              action="<?= site_url('messaggi/inoltra/' . (int)$current['id_message']) ?>">
                          <?= csrf_field() ?>

                          <div class="form-group">
                            <label>Destinazione inoltro</label>
                            <select class="form-control" name="dest" required>
                              <option value="" selected disabled hidden>Seleziona...</option>

                              <?php if ($myRole === 'DOTTORE'): ?>
                                <option value="ROLE:SEGRETERIA">Segreteria</option>
                                <option value="ROLE:INFERMIERE">Infermieri</option>

                              <?php elseif ($myRole === 'SEGRETERIA'): ?>
                                <option value="ROLE:INFERMIERE">Infermieri</option>
                                <?php if ($doctorContextId > 0): ?>
                                  <option value="USER:<?= (int)$doctorContextId ?>">Medico del paziente</option>
                                <?php endif; ?>

                              <?php elseif ($myRole === 'INFERMIERE'): ?>
                                <option value="ROLE:SEGRETERIA">Segreteria</option>
                                <?php if ($doctorContextId > 0): ?>
                                  <option value="USER:<?= (int)$doctorContextId ?>">Medico del paziente</option>
                                <?php endif; ?>

                              <?php else: ?>
                                <option value="ROLE:SEGRETERIA">Segreteria</option>
                                <option value="ROLE:INFERMIERE">Infermieri</option>
                              <?php endif; ?>
                            </select>
                          </div>

                          <div class="form-group">
                            <textarea name="note"
                                      class="form-control"
                                      rows="2"
                                      placeholder="Nota (opzionale)"></textarea>
                          </div>

                          <button class="btn btn-default btn-sm">
                            <i class="fa fa-share"></i> Conferma inoltro
                          </button>
                        </form>
                      </div>
                    </div>
                  </div>
                <?php endif; ?>

              <?php endif; ?>

              <!-- THREAD -->
              <div class="thread-wrap">
                <?php if (!empty($threadMsgs)): ?>
                  <?php
                    $prevDay = null;
                    foreach ($threadMsgs as $m):
                      $messageId = (int)($m['id_message'] ?? 0);
                      if ($messageId > 0 && isset($forwardParentIds[$messageId])) {
                        continue;
                      }

                      $mDT   = !empty($m['created_at']) ? Time::parse((string)$m['created_at'], $tz) : null;
                      $mWhen = $mDT ? $mDT->toLocalizedString('d MMM y HH:mm') : '';
                      $dayKey = $mDT ? $mDT->toDateString() : '';

                      if ($dayKey && $dayKey !== $prevDay):
                        $prevDay = $dayKey; ?>
                        <div class="day-sep"><?= esc($mDT->toLocalizedString('EEEE d MMM y')) ?></div>
                      <?php endif;

                      $fromM = displayFrom($m);
                      $isMe  = $meUserId && ((int)($m['sender_user_id'] ?? 0) === $meUserId);
                      $isForward = (strtoupper((string)($m['message_type'] ?? '')) === 'FORWARD');
                      $forwardBody = $isForward ? splitForwardBody((string)($m['body_plain'] ?? '')) : ['note' => '', 'body' => ''];
                      $allegM = $m['attachments'] ?? ($m['allegati'] ?? []);
                      if (!is_array($allegM)) $allegM = [];
                      if ($isForward) {
                        $parentMsgId = (int)($m['parent_message_id'] ?? 0);
                        $parentMsg = $parentMsgId > 0 ? ($threadMsgById[$parentMsgId] ?? null) : null;
                        $parentAlleg = is_array($parentMsg) ? ($parentMsg['attachments'] ?? ($parentMsg['allegati'] ?? [])) : [];
                        if (!is_array($parentAlleg)) $parentAlleg = [];

                        if (!empty($parentAlleg)) {
                          $seenAttachments = [];
                          $seenAttachmentSignatures = [];
                          foreach ($allegM as $attSeen) {
                            $seenId = (int)($attSeen['id_attachment'] ?? 0);
                            if ($seenId > 0) $seenAttachments[$seenId] = true;
                            $seenSignature = attachmentSignature((array)$attSeen);
                            if ($seenSignature !== '') $seenAttachmentSignatures[$seenSignature] = true;
                          }

                          foreach ($parentAlleg as $parentAtt) {
                            $parentAttId = (int)($parentAtt['id_attachment'] ?? 0);
                            $parentSignature = attachmentSignature((array)$parentAtt);
                            if ($parentAttId > 0 && isset($seenAttachments[$parentAttId])) {
                              continue;
                            }
                            if ($parentSignature !== '' && isset($seenAttachmentSignatures[$parentSignature])) {
                              continue;
                            }
                            $allegM[] = $parentAtt;
                            if ($parentAttId > 0) $seenAttachments[$parentAttId] = true;
                            if ($parentSignature !== '') $seenAttachmentSignatures[$parentSignature] = true;
                          }
                        }
                      }
                  ?>
                    <div class="thread-item">
                      <div class="thread-head">
                        <div class="thread-from"><?= esc($fromM) ?></div>
                        <div class="thread-time"><?= esc($mWhen) ?></div>
                      </div>

                      <div class="bubble <?= $isMe ? 'me' : '' ?> <?= $isForward ? 'forward' : '' ?>">
                        <?php if ($isForward): ?>
                          <div class="forward-card">
                            <div class="forward-card-title">
                              <i class="fa fa-share"></i> Messaggio inoltrato
                            </div>
                            <?php if ($forwardBody['note'] !== ''): ?>
                              <div class="forward-card-note"><?= esc($forwardBody['note']) ?></div>
                            <?php endif; ?>
                            <div class="forward-card-body"><?= esc($forwardBody['body']) ?></div>
                          </div>
                        <?php else: ?>
                          <div style="white-space: pre-wrap;"><?= esc((string)($m['body_plain'] ?? '')) ?></div>
                        <?php endif; ?>

                        <?php if (!empty($allegM)): ?>
                          <div class="thread-att">
                            <?php foreach ($allegM as $att): ?>
                              <?php
                                $mime = strtolower((string)($att['mime_type'] ?? ''));
                                $isImg = (strpos($mime, 'image/') === 0);
                                $attId = (int)($att['id_attachment'] ?? 0);
                                $name  = (string)($att['original_name'] ?? 'allegato');

                                $viewUrl = site_url('messaggi/allegato/' . $attId);
                                $downloadUrl = site_url('messaggi/allegato/' . $attId . '?download=1');
                              ?>
                              <div class="thread-att-item">
                                <a href="<?= esc($viewUrl) ?>" target="_blank" class="no-loader" style="color:#333; text-decoration:none;">
                                  <?php if ($isImg): ?>
                                    <img src="<?= esc($viewUrl) ?>" alt="<?= esc($name) ?>" style="max-width:170px; max-height:140px; display:block; margin-bottom:4px; border-radius:3px;">
                                  <?php else: ?>
                                    <div class="thread-att-item-icon"><i class="fa fa-file-o"></i></div>
                                  <?php endif; ?>
                                  <div class="thread-att-item-name"><?= esc($name) ?></div>
                                </a>
                                <div class="thread-att-actions">
                                  <a href="<?= esc($downloadUrl) ?>" class="btn btn-download-file no-loader" target="_blank">
                                    <i class="fa fa-download"></i> Scarica
                                  </a>
                                </div>
                              </div>
                            <?php endforeach; ?>
                          </div>
                        <?php endif; ?>
                      </div>
                    </div>
                  <?php endforeach; ?>
                <?php else: ?>
                  <p class="text-center" style="padding:20px;">Nessun contenuto da mostrare.</p>
                <?php endif; ?>
              </div>

            </div>

            <!-- FOOTER -->
            <div class="box-footer">
              <div class="pull-right">
                <?php if ($current): ?>
                  <button type="button" class="btn btn-default btn-sm"
                          onclick="document.getElementById('replyBox<?= (int)$current['id_message'] ?>').classList.toggle('hidden')">
                    <i class="fa fa-reply"></i> Rispondi
                  </button>

                  <?php if (!$isClient): ?>
                    <button type="button" class="btn btn-default btn-sm"
                            onclick="document.getElementById('fwdBox<?= (int)$current['id_message'] ?>').classList.toggle('hidden')">
                      <i class="fa fa-share"></i> Inoltra
                    </button>
                  <?php endif; ?>
                <?php endif; ?>
              </div>
            </div>

          </div>
        </div>

      </div>
    </section>
  </div>
</div>

<?php if ($patientSendNoticeBody !== ''): ?>
  <div id="patientSendNoticeModal" class="patient-notice-modal" role="dialog" aria-modal="true" aria-labelledby="patientSendNoticeTitle">
    <div class="patient-notice-modal__dialog">
      <div class="patient-notice-modal__card">
        <h2 id="patientSendNoticeTitle" class="patient-notice-modal__title">Messaggio inviato</h2>
        <div class="patient-notice-modal__body"><?= esc($patientSendNoticeBody) ?></div>
        <div class="patient-notice-modal__actions">
          <button type="button" id="patientSendNoticeOk" class="btn btn-primary patient-notice-modal__ok">OK</button>
        </div>
      </div>
    </div>
  </div>
<?php endif; ?>

<script src="<?= base_url('public/dist/js/app.min.js') ?>"></script>

<script>
  // loader minimale come old
  (function() {
    var loader = $('#page-loader');
    function show(){ loader.stop(true,true).fadeIn(100); }
    function hide(){ loader.stop(true,true).fadeOut(150); }
    $(document).on('click', 'a[href]:not([href^="#"]):not([target="_blank"]):not(.no-loader)', function(e){
      if (e.ctrlKey || e.shiftKey || e.metaKey) return;
      show();
    });
    $(document).on('submit', 'form:not(.no-loader)', function(){ show(); });
    window.addEventListener('beforeunload', function(){ show(); });
    $(window).on('load', hide);
  })();

  // conferma elimina
  $(document).on('click', '.btn-delete', function(e){
    if(!confirm('Eliminare questo messaggio?')) {
      e.preventDefault();
      return false;
    }
  });

  (function() {
    var modal = document.getElementById('patientSendNoticeModal');
    if (!modal) return;

    var okBtn = document.getElementById('patientSendNoticeOk');

    function closeModal() {
      modal.remove();
      document.body.classList.remove('patient-notice-lock');
    }

    if (okBtn) {
      okBtn.addEventListener('click', closeModal);
      window.setTimeout(function() { okBtn.focus(); }, 50);
    }

    document.addEventListener('keydown', function(e) {
      if (!document.getElementById('patientSendNoticeModal')) return;
      if (e.key === 'Escape') {
        e.preventDefault();
        e.stopPropagation();
      }
    }, true);
  })();

  (function() {
    var maxUploadBytes = <?= (int) APP_UPLOAD_MAX_FILE_SIZE_BYTES ?>;
    var maxUploadLabel = '<?= (int) APP_UPLOAD_MAX_FILE_SIZE_MB ?>MB';

    function validateFileInput(input) {
      if (!input || !input.files || !input.files.length) {
        return true;
      }

      for (var i = 0; i < input.files.length; i++) {
        var file = input.files[i];
        if (file && file.size > maxUploadBytes) {
          alert('AVVISO: il file "' + file.name + '" e troppo grosso. Il limite massimo e ' + maxUploadLabel + '.');
          input.value = '';
          return false;
        }
      }

      return true;
    }

    Array.prototype.forEach.call(
      document.querySelectorAll('input[type="file"][name="files[]"]'),
      function(input) {
        input.addEventListener('change', function() {
          validateFileInput(input);
        });

        if (input.form) {
          input.form.addEventListener('submit', function(e) {
            if (!validateFileInput(input)) {
              e.preventDefault();
              e.stopPropagation();
            }
          });
        }
      }
    );
  })();
</script>
</body>
</html>


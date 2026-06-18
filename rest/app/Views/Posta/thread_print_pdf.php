<?php
/**
 * PDF Thread print view
 * @var int   $threadId
 * @var array $messages (ASC)
 */

use CodeIgniter\I18n\Time;

$tz = 'Europe/Rome';
$threadMsgs = $messages ?? [];

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

$printMsgs = [];
foreach ($threadMsgs as $threadMsg) {
  $threadMsgId = (int)($threadMsg['id_message'] ?? 0);
  if ($threadMsgId > 0 && isset($forwardParentIds[$threadMsgId])) {
    continue;
  }

  $threadMsgType = strtoupper((string)($threadMsg['message_type'] ?? ''));
  if ($threadMsgType === 'FORWARD') {
    $allegati = $threadMsg['attachments'] ?? [];
    if (!is_array($allegati)) $allegati = [];

    $parentMsgId = (int)($threadMsg['parent_message_id'] ?? 0);
    $parentMsg = $parentMsgId > 0 ? ($threadMsgById[$parentMsgId] ?? null) : null;
    $parentAllegati = is_array($parentMsg) ? ($parentMsg['attachments'] ?? []) : [];
    if (!is_array($parentAllegati)) $parentAllegati = [];

    if (!empty($parentAllegati)) {
      $seenAttachments = [];
      $seenAttachmentSignatures = [];
      foreach ($allegati as $attSeen) {
        $seenId = (int)($attSeen['id_attachment'] ?? 0);
        if ($seenId > 0) $seenAttachments[$seenId] = true;
        $seenSignature = attachmentSignature((array)$attSeen);
        if ($seenSignature !== '') $seenAttachmentSignatures[$seenSignature] = true;
      }

      foreach ($parentAllegati as $parentAtt) {
        $parentAttId = (int)($parentAtt['id_attachment'] ?? 0);
        $parentSignature = attachmentSignature((array)$parentAtt);
        if ($parentAttId > 0 && isset($seenAttachments[$parentAttId])) {
          continue;
        }
        if ($parentSignature !== '' && isset($seenAttachmentSignatures[$parentSignature])) {
          continue;
        }
        $allegati[] = $parentAtt;
        if ($parentAttId > 0) $seenAttachments[$parentAttId] = true;
        if ($parentSignature !== '') $seenAttachmentSignatures[$parentSignature] = true;
      }
    }

    $threadMsg['attachments'] = $allegati;
  }

  $printMsgs[] = $threadMsg;
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

function esc_pdf($s): string {
  return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function bytesHuman(int $bytes): string {
  if ($bytes <= 0) return '0 B';
  $units = ['B','KB','MB','GB','TB'];
  $i = 0;
  $v = (float)$bytes;
  while ($v >= 1024 && $i < count($units)-1) { $v /= 1024; $i++; }
  return rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.') . ' ' . $units[$i];
}

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

function displayFrom(array $m): string {
  $senderDisplay = trim((string)($m['sender_display'] ?? ''));
  $type = strtoupper((string)($m['message_type'] ?? ''));

  if ($type !== 'FORWARD') {
    if ($senderDisplay !== '') return $senderDisplay;
    $sender = fullName($m['sender_nome'] ?? '', $m['sender_cognome'] ?? '');
    return $sender !== '' ? $sender : 'Mittente';
  }

  $prefix = forwardLabelFromRole((string)($m['sender_role'] ?? ''));
  $root   = fullName($m['root_nome'] ?? '', $m['root_cognome'] ?? '');
  if ($root === '') $root = 'Mittente originale';

  // senderDisplay (se presente) è “Segreteria” / “Infermiere” ecc.
  $who = $senderDisplay !== '' ? $senderDisplay : 'Staff';
  return $prefix . $root . ' (' . $who . ')';
}

function displayTo(array $m): string {
  $rt = strtoupper((string)($m['recipient_type'] ?? ''));

  if ($rt === 'USER') {
    $to = fullName($m['recipient_nome'] ?? '', $m['recipient_cognome'] ?? '');
    return $to !== '' ? $to : 'Destinatario';
  }

  if ($rt === 'ROLE') {
    $r = strtoupper((string)($m['recipient_role'] ?? ''));
    return $r !== '' ? $r : 'Ruolo';
  }

  // per i casi tipo PATIENT_TARGET ecc.
  $code = strtoupper((string)($m['patient_target_code'] ?? ''));
  return $code !== '' ? $code : 'Destinatario';
}

function splitForwardBodyPdf(string $body): array {
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

// Meta thread
$first = !empty($printMsgs) ? $printMsgs[0] : null;
$last  = !empty($printMsgs) ? $printMsgs[count($printMsgs)-1] : null;

$printedAt = Time::now($tz)->toDateTimeString();
$rangeFrom = $first ? (string)($first['created_at'] ?? '') : '';
$rangeTo   = $last  ? (string)($last['created_at'] ?? '') : '';

$convFrom = $first ? displayFrom($first) : '';
$convTo   = $first ? displayTo($first)   : '';
?>
<!doctype html>
<html lang="it">
<head>
  <meta charset="utf-8">
  <style>
    @page { margin: 22mm 16mm 18mm 16mm; }
    body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #111; }
    .header { border-bottom: 2px solid #1f2937; padding-bottom: 10px; margin-bottom: 14px; }
    .title { font-size: 18px; font-weight: 700; margin: 0; }
    .subtitle { margin: 4px 0 0; color: #374151; font-size: 11px; }
    .meta { margin-top: 10px; }
    .meta table { width: 100%; border-collapse: collapse; }
    .meta td { padding: 4px 6px; vertical-align: top; }
    .meta .k { width: 20%; color: #374151; font-weight: 700; }
    .meta .v { width: 80%; }
    .msg { border: 1px solid #cbd5e1; border-radius: 6px; margin: 10px 0; }
    .msg-head { background: #f1f5f9; padding: 8px 10px; border-bottom: 1px solid #cbd5e1; }
    .msg.forward { border-color: #9fd5cf; }
    .msg.forward .msg-head { background: #effaf8; }
    .msg-title { font-weight: 700; font-size: 12px; margin: 0; }
    .msg-sub { margin: 2px 0 0; color: #374151; font-size: 10.5px; }
    .msg-body { padding: 10px; line-height: 1.45; }
    .msg-body pre { margin: 0; white-space: pre-wrap; word-wrap: break-word; font-family: DejaVu Sans, sans-serif; }
    .forward-box { border-left: 4px solid #2c8895; background: #fff; padding: 8px 10px; }
    .forward-label { color: #2c8895; font-weight: 700; margin: 0 0 6px; }
    .forward-note { border: 1px solid #d9efea; background: #f4fffb; padding: 7px; margin: 0 0 8px; }
    .forward-text { margin: 0; }
    .att { margin-top: 8px; padding-top: 8px; border-top: 1px dashed #cbd5e1; }
    .att-title { font-weight: 700; margin: 0 0 4px; }
    .att ul { margin: 0; padding-left: 16px; }
    .att li { margin: 2px 0; }
    .footer-note { margin-top: 14px; color: #6b7280; font-size: 10px; }
  </style>
</head>
<body>

<div class="header">
  <p class="title">Stampa conversazione</p>
  <p class="subtitle">
    Thread #<?= (int)$threadId ?> — Generato il <?= esc_pdf($printedAt) ?>
  </p>

  <div class="meta">
    <table>
      <tr>
        <td class="k">Da</td>
        <td class="v"><?= esc_pdf($convFrom) ?></td>
      </tr>
      <tr>
        <td class="k">A</td>
        <td class="v"><?= esc_pdf($convTo) ?></td>
      </tr>
      <tr>
        <td class="k">Periodo</td>
        <td class="v">
          <?php if ($rangeFrom && $rangeTo): ?>
            <?= esc_pdf($rangeFrom) ?> → <?= esc_pdf($rangeTo) ?>
          <?php else: ?>
            —
          <?php endif; ?>
        </td>
      </tr>
      <tr>
        <td class="k">Totale messaggi</td>
        <td class="v"><?= (int)count($printMsgs) ?></td>
      </tr>
    </table>
  </div>
</div>

<?php if (empty($printMsgs)): ?>
  <p>Nessun messaggio disponibile per questo thread.</p>
<?php else: ?>

  <?php foreach ($printMsgs as $i => $m): ?>
    <?php
      $when = (string)($m['created_at'] ?? '');
      $from = displayFrom($m);
      $to   = displayTo($m);
      $kind = strtoupper((string)($m['msg_kind'] ?? ''));
      $type = strtoupper((string)($m['message_type'] ?? ''));
      $body = (string)($m['body_plain'] ?? '');
      $isForward = ($type === 'FORWARD');
      $forwardBody = $isForward ? splitForwardBodyPdf($body) : ['note' => '', 'body' => ''];
      $atts = $m['attachments'] ?? [];
      if (!is_array($atts)) $atts = [];
    ?>

    <div class="msg<?= $isForward ? ' forward' : '' ?>">
      <div class="msg-head">
        <p class="msg-title">Messaggio <?= ($i+1) ?> / <?= count($printMsgs) ?></p>
        <p class="msg-sub">
          <strong>Data/Ora:</strong> <?= esc_pdf($when) ?>
          &nbsp;|&nbsp; <strong>Tipo:</strong> <?= esc_pdf($type ?: $kind ?: '—') ?>
          <br>
          <strong>Da:</strong> <?= esc_pdf($from) ?>
          <br>
          <strong>A:</strong> <?= esc_pdf($to) ?>
        </p>
      </div>

      <div class="msg-body">
        <?php if ($isForward): ?>
          <div class="forward-box">
            <p class="forward-label">Messaggio inoltrato</p>
            <?php if ($forwardBody['note'] !== ''): ?>
              <pre class="forward-note"><?= esc_pdf($forwardBody['note']) ?></pre>
            <?php endif; ?>
            <pre class="forward-text"><?= esc_pdf($forwardBody['body']) ?></pre>
          </div>
        <?php else: ?>
          <pre><?= esc_pdf($body) ?></pre>
        <?php endif; ?>

        <?php if (!empty($atts)): ?>
          <div class="att">
            <p class="att-title">Allegati</p>
            <ul>
              <?php foreach ($atts as $a): ?>
                <?php
                  $n = (string)($a['original_name'] ?? 'file');
                  $s = (int)($a['file_size'] ?? 0);
                  $t = (string)($a['mime_type'] ?? '');
                ?>
                <li>
                  <?= esc_pdf($n) ?>
                  <?php if ($t !== ''): ?> — <?= esc_pdf($t) ?><?php endif; ?>
                  <?php if ($s > 0): ?> — <?= esc_pdf(bytesHuman($s)) ?><?php endif; ?>
                </li>
              <?php endforeach; ?>
            </ul>
          </div>
        <?php endif; ?>

      </div>
    </div>
  <?php endforeach; ?>

<?php endif; ?>

<div class="footer-note">
  Documento generato automaticamente dal sistema. Valido per archiviazione e consultazione interna.
</div>

<script type="text/php">
if (isset($pdf)) {
  $pdf->page_script('
    $font = $fontMetrics->get_font("DejaVu Sans", "normal");
    $size = 9;
    $text = "Pagina " . $PAGE_NUM . " di " . $PAGE_COUNT;
    $pdf->text(520, 820, $text, $font, $size);
  ');
}
</script>

</body>
</html>

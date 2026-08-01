<?php
$tenantScope = is_array($tenantScope ?? null) ? $tenantScope : [];
$report = is_array($report ?? null) ? $report : [];
$filters = is_array($report['filters'] ?? null) ? $report['filters'] : [];
$summary = is_array($report['summary'] ?? null) ? $report['summary'] : [];
$documents = is_array($report['documents'] ?? null) ? $report['documents'] : [];
$formatDate = static function (string $value): string {
    $date = \DateTimeImmutable::createFromFormat('!Y-m-d', trim($value));

    return $date !== false ? $date->format('d/m/Y') : '-';
};
$periodText = 'Tutto lo storico';
if (trim((string) ($filters['date_from'] ?? '')) !== '') {
    $periodText = 'Dal ' . $formatDate((string) $filters['date_from']) . ' al ' . $formatDate((string) ($filters['date_to'] ?? ''));
}
?>
<!DOCTYPE html>
<html>
<head>
  <meta charset="UTF-8">
  <style>
    @page { margin:28px 30px 42px; }
    * { box-sizing:border-box; }
    body { margin:0; color:#263b40; font-family:DejaVu Sans, sans-serif; font-size:9px; }
    h1 { margin:0 0 5px; color:#176875; font-size:22px; }
    .subtitle { color:#6d8085; font-size:10px; }
    .header { padding-bottom:14px; border-bottom:2px solid #2c8895; }
    .header-table { width:100%; border-collapse:collapse; }
    .header-table td { vertical-align:top; }
    .header-meta { text-align:right; line-height:1.55; }
    .summary { width:100%; margin:14px 0; border-collapse:separate; border-spacing:7px 0; }
    .summary td { width:25%; padding:10px 12px; border:1px solid #dbe7e9; border-radius:7px; background:#f6fafa; }
    .summary span { display:block; margin-bottom:5px; color:#6f8387; font-size:8px; font-weight:bold; text-transform:uppercase; }
    .summary strong { color:#174f58; font-size:15px; }
    .filters { margin:0 0 12px; padding:8px 10px; border:1px solid #dbe7e9; background:#fbfdfd; }
    .filters strong { color:#175f69; }
    table.documents { width:100%; border-collapse:collapse; table-layout:fixed; }
    .documents thead { display:table-header-group; }
    .documents tr { page-break-inside:avoid; }
    .documents th { padding:7px 6px; color:#fff; background:#2c8895; font-size:8px; text-align:left; text-transform:uppercase; }
    .documents td { padding:6px; border-bottom:1px solid #e3eaec; vertical-align:top; }
    .documents tbody tr:nth-child(even) { background:#f8fbfb; }
    .right { text-align:right !important; }
    .muted { color:#73878b; font-size:8px; }
    .total { color:#155d67; font-weight:bold; white-space:nowrap; }
    .footer { position:fixed; right:0; bottom:-28px; left:0; color:#7c8e92; font-size:8px; text-align:center; }
    .footer .page-number:after { content:counter(page); }
  </style>
</head>
<body>
  <div class="footer">AmbulatorioFacile &middot; Report fatturato &middot; Pagina <span class="page-number"></span></div>

  <div class="header">
    <table class="header-table">
      <tr>
        <td>
          <h1>Statistiche e report del fatturato</h1>
          <div class="subtitle"><?= esc((string) ($tenantScope['tenant_name'] ?? 'Spazio attivo')) ?></div>
        </td>
        <td class="header-meta">
          <strong><?= esc($periodText) ?></strong><br>
          Generato il <?= esc((string) ($generatedAt ?? date('d/m/Y H:i'))) ?>
        </td>
      </tr>
    </table>
  </div>

  <table class="summary">
    <tr>
      <td><span>Fatturato</span><strong>&euro; <?= number_format((float) ($summary['total_amount'] ?? 0), 2, ',', '.') ?></strong></td>
      <td><span>Documenti</span><strong><?= (int) ($summary['document_count'] ?? 0) ?></strong></td>
      <td><span>Valore medio</span><strong>&euro; <?= number_format((float) ($summary['average_amount'] ?? 0), 2, ',', '.') ?></strong></td>
      <td><span>Imponibile</span><strong>&euro; <?= number_format((float) ($summary['subtotal_amount'] ?? 0), 2, ',', '.') ?></strong></td>
    </tr>
  </table>

  <div class="filters">
    <strong>Filtri:</strong>
    <?= esc($periodText) ?>
    <?php if (trim((string) ($filters['document_type'] ?? '')) !== ''): ?>
      &middot; Tipo <?= esc((string) ($filters['document_type'] ?? '')) ?>
    <?php endif; ?>
    <?php if (trim((string) ($filters['payment_method'] ?? '')) !== ''): ?>
      &middot; Pagamento <?= esc((string) ($filters['payment_method'] ?? '')) ?>
    <?php endif; ?>
    <?php if (trim((string) ($filters['patient'] ?? '')) !== ''): ?>
      &middot; Ricerca <?= esc((string) ($filters['patient'] ?? '')) ?>
    <?php endif; ?>
  </div>

  <table class="documents">
    <thead>
      <tr>
        <th style="width:8%;">Data</th>
        <th style="width:12%;">Documento</th>
        <th style="width:11%;">Tipo</th>
        <th style="width:20%;">Cliente</th>
        <th style="width:21%;">Prestazioni</th>
        <th style="width:10%;">Pagamento</th>
        <th class="right" style="width:9%;">Imponibile</th>
        <th class="right" style="width:9%;">Totale</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($documents as $row): ?>
        <?php
          $services = implode(', ', (array) ($row['service_descriptions'] ?? []));
          if (mb_strlen($services) > 90) {
              $services = mb_substr($services, 0, 87) . '...';
          }
        ?>
        <tr>
          <td><?= esc($formatDate((string) ($row['issue_date'] ?? ''))) ?></td>
          <td><strong><?= esc((string) ($row['document_number'] ?? '-')) ?></strong></td>
          <td><?= esc((string) ($row['document_type_label'] ?? '-')) ?></td>
          <td>
            <strong><?= esc((string) ($row['patient_name'] ?? '-')) ?></strong><br>
            <span class="muted"><?= esc((string) ($row['patient_tax_code'] ?? '')) ?></span>
          </td>
          <td><?= esc($services !== '' ? $services : '-') ?></td>
          <td><?= esc((string) ($row['payment_method_label'] ?? '-')) ?></td>
          <td class="right">&euro; <?= number_format((float) ($row['subtotal_amount'] ?? 0), 2, ',', '.') ?></td>
          <td class="right total">&euro; <?= number_format((float) ($row['amount_total'] ?? 0), 2, ',', '.') ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</body>
</html>

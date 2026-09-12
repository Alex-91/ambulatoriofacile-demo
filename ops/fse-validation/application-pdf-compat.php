<?php
/** Actual application views; synthetic values only. Outputs are QA fixtures, not fiscal/clinical documents. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/application-compat-bootstrap.php';
$run = $fseApplicationCompat['run'];
mkdir($run . '/pdf', 0700);
$cases = [];

foreach ([2, 60] as $count) {
    $items = $tokens = [];
    for ($index = 1; $index <= $count; $index++) {
        $token = sprintf('S%03d', $index);
        $tokens[] = $token;
        $items[] = ['description' => $token . ' - Prestazione sintetica qualità'
            . ($index === 1 ? ' <b>NON HTML</b>' : ''), 'quantity' => 1, 'unit_amount' => 40, 'line_total' => 40];
    }
    $preview = [
        'tenant' => ['tenant_name' => 'STUDIO SINTETICO - NON VALIDO'],
        'document_type_label' => 'Documento di prova', 'payment_method_label' => 'Simulato',
        'generated_at' => '2026-09-11 12:00:00',
        'document' => ['document_number' => 'TEST-2026-001', 'issue_date' => '2026-09-11',
            'patient_name' => 'PAZIENTE SINTETICO', 'subtotal_amount' => $count * 40,
            'amount_total' => $count * 40, 'notes' => 'PROVA TECNICA SENZA VALORE FISCALE'],
        'line_items' => $items,
        'template' => ['document_title' => 'DOCUMENTO DI PROVA',
            'layout' => ['show_header' => true, 'show_patient_box' => true, 'show_payment_box' => true, 'show_footer' => true],
            'fields' => ['show_document_number' => true, 'show_issue_date' => true, 'show_patient_name' => true,
                'show_notes' => true, 'show_payment_method' => true],
            'branding' => ['logo_mode' => 'none', 'accent_color' => '#2c8895', 'footer_note' => 'COLLAUDO LOCALE - DATI FITTIZI']],
    ];
    $html = view('admin/billing/document_pdf', ['preview' => $preview], ['saveData' => false]);
    if (!str_contains($html, '&lt;b&gt;NON HTML&lt;/b&gt;')) throw new RuntimeException('Billing escaping failed.');
    $cases['billing-' . $count] = ['html' => $html, 'paper' => 'A4', 'orientation' => 'portrait',
        'required_tokens' => $tokens, 'required_text' => ['PAZIENTE SINTETICO', 'PROVA TECNICA SENZA VALORE FISCALE',
            'EUR ' . number_format($count * 40, 2, ',', '.')], 'min_pages' => $count === 2 ? 1 : 2];
}

foreach (['team_day', 'week'] as $mode) {
    $columns = $rows = $tokens = [];
    $columnCount = $mode === 'team_day' ? 3 : 5;
    for ($column = 0; $column < $columnCount; $column++) {
        $columns[] = ['label' => ($mode === 'team_day' ? 'Professionista prova ' : 'Giorno prova ') . ($column + 1),
            'sub_label' => 'DATI FITTIZI', 'header_badges' => []];
    }
    for ($row = 0; $row < 32; $row++) {
        $minutes = 9 * 60 + $row * 15;
        $time = sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
        $cells = [];
        for ($column = 0; $column < $columnCount; $column++) {
            if ($row % 4 !== 0) { $cells[] = null; continue; }
            $token = sprintf('A%02dC%d', intdiv($row, 4) + 1, $column + 1);
            $tokens[] = $token;
            $colors = ['#F39C12', '#123456', '#3c8dbc'];
            $cells[] = ['rowspan' => 4, 'class' => 'is-booked', 'time_range' => $time . ' - ' . sprintf('%02d:00', intdiv($minutes, 60) + 1),
                'primary_label' => $token . ' - Paziente sintetico', 'secondary_label' => 'Prova qualità - nessun appuntamento reale',
                'cell_style' => 'background-color:' . $colors[$column % 3] . ';color:' . ($column % 3 === 0 ? '#1F2D3D' : '#FFFFFF') . ';'];
        }
        $rows[] = ['time_label' => $time, 'cells' => $cells];
    }
    $html = view('agenda/timeline_pdf', ['title' => 'AGENDA DI PROVA - ' . ($mode === 'team_day' ? 'TEAM' : 'SETTIMANA'),
        'subtitle' => 'COLLAUDO LOCALE - DATI FITTIZI', 'contextLabel' => 'Nessuna prenotazione reale',
        'generatedAt' => '11/09/2026 12:00', 'columns' => $columns, 'rows' => $rows,
        'rowHeightPx' => $mode === 'team_day' ? 26 : 14, 'pageMode' => $mode], ['saveData' => false]);
    $cases['agenda-' . $mode] = ['html' => $html, 'paper' => 'A3', 'orientation' => 'landscape',
        'required_tokens' => $tokens, 'required_text' => ['COLLAUDO LOCALE - DATI FITTIZI'], 'min_pages' => 1];
}

$outputs = [];
foreach ($cases as $name => $case) {
    $options = new Dompdf\Options();
    $options->set('isRemoteEnabled', false);
    $options->set('isPhpEnabled', false);
    $options->set('isJavascriptEnabled', false);
    $options->set('chroot', [$run]);
    foreach (['fontDir', 'fontCache', 'tempDir'] as $option) $options->set($option, WRITEPATH);
    $pdf = new Dompdf\Dompdf($options);
    $pdf->loadHtml($case['html'], 'UTF-8');
    $pdf->setPaper($case['paper'], $case['orientation']);
    $pdf->render();
    $bytes = $pdf->output();
    $pages = $pdf->getCanvas()->get_page_count();
    if (!str_starts_with($bytes, '%PDF-') || $pages < $case['min_pages']) throw new RuntimeException('Invalid PDF fixture.');
    file_put_contents($run . '/pdf/' . $name . '.pdf', $bytes);
    $outputs[$name] = ['file' => 'pdf/' . $name . '.pdf', 'sha256' => hash('sha256', $bytes), 'pages' => $pages,
        'required_tokens' => $case['required_tokens'], 'required_text' => $case['required_text'],
        'view_html_sha256' => hash('sha256', $case['html'])];
}
$report = ['mode' => 'SYNTHETIC_PDF_RENDERING_ONLY', 'variant' => $fseApplicationCompat['variant'],
    'framework_version' => \CodeIgniter\CodeIgniter::CI_VERSION, 'status' => 'rendered_not_visually_verified', 'outputs' => $outputs,
    'limitations' => ['Actual billing and timeline views, hand-built synthetic view models; no controller endpoint or production DB.',
        'Remote resources, PHP and JavaScript disabled. No clinical/fiscal validity. Pixel/text comparison and manual review still required.']];
file_put_contents($run . '/pdf-report.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
echo json_encode(['run' => $run, 'status' => $report['status'], 'pages' => array_column($outputs, 'pages')]) . PHP_EOL;

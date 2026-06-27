<?php
if (!function_exists('timeline_pdf_text')) {
    function timeline_pdf_text(?string $value, string $fallback = ''): string
    {
        $value = trim((string)$value);
        return $value !== '' ? $value : $fallback;
    }
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <style>
        @page {
            margin: 10mm 9mm 10mm 9mm;
        }

        body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 9px;
            color: #1f2937;
            margin: 0;
        }

        h1 {
            font-size: 18px;
            margin: 0 0 4px 0;
            color: #111827;
        }

        .summary {
            margin-bottom: 10px;
        }

        .summary-row {
            margin: 2px 0;
        }

        .mode-note {
            margin-top: 4px;
            font-size: 8px;
            color: #52606d;
        }

        .timeline-table {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
        }

        .timeline-table th,
        .timeline-table td {
            border: 1px solid #cfd8e3;
        }

        .timeline-table thead th {
            background: #eaf1f8;
            padding: 5px 4px;
            text-align: center;
            vertical-align: top;
        }

        .timeline-table .time-col {
            width: 58px;
            text-align: center;
            background: #f8fafc;
            font-weight: bold;
        }

        .timeline-table tbody .time-col {
            padding: 3px 2px;
            font-size: 8px;
            color: #334155;
        }

        .column-label {
            font-size: 10px;
            font-weight: bold;
            line-height: 1.15;
            color: #0f172a;
        }

        .column-sub {
            margin-top: 2px;
            font-size: 8px;
            color: #475569;
        }

        .column-badges {
            margin-top: 4px;
        }

        .badge {
            display: inline-block;
            margin: 1px 2px 0 0;
            padding: 1px 4px;
            border-radius: 8px;
            font-size: 7px;
            font-weight: bold;
            letter-spacing: 0.02em;
        }

        .badge.is-primary {
            background: #dbeafe;
            color: #1d4ed8;
        }

        .badge.is-danger {
            background: #fee2e2;
            color: #b91c1c;
        }

        .badge.is-muted {
            background: #e5e7eb;
            color: #4b5563;
        }

        .timeline-cell {
            padding: 0;
            vertical-align: top;
        }

        .timeline-cell .cell-inner {
            padding: 4px 5px;
        }

        .timeline-cell.is-gap .cell-inner {
            background: #f8fafc;
            color: transparent;
        }

        .timeline-cell.is-free .cell-inner {
            background: #ffffff;
        }

        .timeline-cell.is-blocked .cell-inner {
            background: #fde2e2;
            color: #991b1b;
        }

        .timeline-cell.is-no-agenda .cell-inner {
            background: #f3f4f6;
            color: #4b5563;
        }

        .timeline-cell.is-booked .cell-inner {
            background: #3c8dbc;
            color: #ffffff;
        }

        .timeline-cell.is-booked-locked .cell-inner {
            background: #d9534f;
            color: #ffffff;
        }

        .timeline-cell.is-empty-column .cell-inner {
            text-align: center;
            vertical-align: middle;
            font-weight: bold;
            padding-top: 18px;
        }

        .entry-time {
            font-size: 7px;
            font-weight: bold;
            line-height: 1.1;
            letter-spacing: 0.02em;
        }

        .entry-title {
            margin-top: 2px;
            font-size: 9px;
            font-weight: bold;
            line-height: 1.15;
        }

        .entry-note {
            margin-top: 3px;
            font-size: 7px;
            line-height: 1.25;
        }
    </style>
</head>
<body>
    <h1><?= esc(timeline_pdf_text($title ?? '', 'Agenda')) ?></h1>

    <div class="summary">
        <?php if (timeline_pdf_text($subtitle ?? '') !== ''): ?>
            <div class="summary-row"><strong>Periodo:</strong> <?= esc(timeline_pdf_text($subtitle ?? '')) ?></div>
        <?php endif; ?>
        <?php if (timeline_pdf_text($contextLabel ?? '') !== ''): ?>
            <div class="summary-row"><strong>Contesto:</strong> <?= esc(timeline_pdf_text($contextLabel ?? '')) ?></div>
        <?php endif; ?>
        <?php if (timeline_pdf_text($generatedAt ?? '') !== ''): ?>
            <div class="summary-row"><strong>Generato il:</strong> <?= esc(timeline_pdf_text($generatedAt ?? '')) ?></div>
        <?php endif; ?>
        <div class="mode-note">
            <?php if (($pageMode ?? '') === 'team_day'): ?>
                Gli appuntamenti che coprono piu slot vengono stampati come un unico blocco continuo per tutto il loro orario.
            <?php else: ?>
                La timeline stampa un unico riquadro per ogni appuntamento che copre slot consecutivi.
            <?php endif; ?>
        </div>
    </div>

    <table class="timeline-table">
        <thead>
            <tr>
                <th class="time-col">Ora</th>
                <?php foreach (($columns ?? []) as $column): ?>
                    <th>
                        <div class="column-label"><?= esc(timeline_pdf_text($column['label'] ?? '', 'Colonna')) ?></div>
                        <?php if (timeline_pdf_text($column['sub_label'] ?? '') !== ''): ?>
                            <div class="column-sub"><?= esc(timeline_pdf_text($column['sub_label'] ?? '')) ?></div>
                        <?php endif; ?>
                        <?php if (!empty($column['header_badges']) && is_array($column['header_badges'])): ?>
                            <div class="column-badges">
                                <?php foreach ($column['header_badges'] as $badge): ?>
                                    <?php
                                    $tone = timeline_pdf_text($badge['tone'] ?? 'muted', 'muted');
                                    $label = timeline_pdf_text($badge['label'] ?? '');
                                    if ($label === '') {
                                        continue;
                                    }
                                    ?>
                                    <span class="badge is-<?= esc($tone) ?>"><?= esc($label) ?></span>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </th>
                <?php endforeach; ?>
            </tr>
        </thead>
        <tbody>
            <?php foreach (($rows ?? []) as $row): ?>
                <tr style="height:<?= (int)($rowHeightPx ?? 14) ?>px;">
                    <td class="time-col"><?= esc(timeline_pdf_text($row['time_label'] ?? '')) ?></td>
                    <?php foreach (($row['cells'] ?? []) as $cell): ?>
                        <?php if ($cell === null): ?>
                            <?php continue; ?>
                        <?php endif; ?>
                        <td rowspan="<?= max(1, (int)($cell['rowspan'] ?? 1)) ?>" class="timeline-cell <?= esc(timeline_pdf_text($cell['class'] ?? '')) ?>">
                            <div class="cell-inner">
                                <?php if (timeline_pdf_text($cell['time_range'] ?? '') !== ''): ?>
                                    <div class="entry-time"><?= esc(timeline_pdf_text($cell['time_range'] ?? '')) ?></div>
                                <?php endif; ?>
                                <?php if (timeline_pdf_text($cell['primary_label'] ?? '') !== ''): ?>
                                    <div class="entry-title"><?= esc(timeline_pdf_text($cell['primary_label'] ?? '')) ?></div>
                                <?php endif; ?>
                                <?php if (timeline_pdf_text($cell['secondary_label'] ?? '') !== ''): ?>
                                    <div class="entry-note"><?= esc(timeline_pdf_text($cell['secondary_label'] ?? '')) ?></div>
                                <?php endif; ?>
                            </div>
                        </td>
                    <?php endforeach; ?>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</body>
</html>

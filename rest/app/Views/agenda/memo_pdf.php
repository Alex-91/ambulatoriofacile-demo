<?php
if (!function_exists('memo_pdf_value')) {
    function memo_pdf_value(?string $value, string $fallback = '-'): string
    {
        $value = trim((string)$value);
        return $value !== '' ? esc($value) : $fallback;
    }
}

$agendaMemoFieldVisibilitySettings = is_array($agendaMemoFieldVisibilitySettings ?? null)
    ? $agendaMemoFieldVisibilitySettings
    : [];
$agendaMemoFieldVisibility = array_fill_keys([
    'validity_date',
    'phone',
    'mobile',
    'address',
    'city',
    'patient_registry',
    'notes',
    'completed',
], true);
foreach ((array) ($agendaMemoFieldVisibilitySettings['effective_field_visibility'] ?? []) as $fieldKey => $visible) {
    $fieldKey = trim((string) $fieldKey);
    if (array_key_exists($fieldKey, $agendaMemoFieldVisibility)) {
        $agendaMemoFieldVisibility[$fieldKey] = (bool) $visible;
    }
}
$agendaMemoFieldIsVisible = static function (string $fieldKey) use ($agendaMemoFieldVisibility): bool {
    return (bool) ($agendaMemoFieldVisibility[$fieldKey] ?? true);
};
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <title>Memo del dottore</title>
    <style>
        body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 11px;
            color: #1f2933;
            margin: 18px 22px;
        }

        .header {
            border-bottom: 2px solid #d9e2ec;
            padding-bottom: 10px;
            margin-bottom: 16px;
        }

        .header h1 {
            margin: 0 0 6px;
            font-size: 22px;
        }

        .meta {
            margin: 0;
            color: #52606d;
            line-height: 1.5;
        }

        .legend {
            margin-top: 10px;
            font-size: 10px;
            color: #52606d;
        }

        .legend-item {
            display: inline-block;
            margin-right: 14px;
        }

        .legend-dot {
            display: inline-block;
            width: 9px;
            height: 9px;
            margin-right: 4px;
            border-radius: 50%;
        }

        .legend-dot.scaduta { background: #d9534f; }
        .legend-dot.oggi { background: #7b8794; }
        .legend-dot.futura { background: #2b7fc2; }

        .empty {
            margin-top: 22px;
            padding: 14px;
            border: 1px dashed #bcccdc;
            border-radius: 8px;
            background: #f8fbff;
            color: #52606d;
        }

        .memo-card {
            margin-bottom: 14px;
            padding: 12px 12px 10px;
            border: 1px solid #d9e2ec;
            border-left-width: 6px;
            border-radius: 8px;
            page-break-inside: avoid;
        }

        .memo-card.status-scaduta {
            background: #fff5f5;
            border-left-color: #d9534f;
        }

        .memo-card.status-oggi {
            background: #ffffff;
            border-left-color: #7b8794;
        }

        .memo-card.status-futura {
            background: #f1f7ff;
            border-left-color: #2b7fc2;
        }

        .memo-head {
            width: 100%;
            margin-bottom: 8px;
        }

        .memo-title {
            font-size: 15px;
            font-weight: bold;
            margin-bottom: 5px;
        }

        .badge {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 999px;
            font-size: 10px;
            font-weight: bold;
            text-transform: uppercase;
            color: #fff;
        }

        .badge-scaduta { background: #d9534f; }
        .badge-oggi { background: #7b8794; }
        .badge-futura { background: #2b7fc2; }

        .memo-meta {
            font-size: 10px;
            color: #52606d;
            line-height: 1.5;
            margin-bottom: 8px;
        }

        .memo-grid {
            width: 100%;
            border-collapse: collapse;
        }

        .memo-grid th,
        .memo-grid td {
            border: 1px solid #d9e2ec;
            padding: 6px 7px;
            vertical-align: top;
        }

        .memo-grid th {
            width: 90px;
            background: #f8fafc;
            text-align: left;
            color: #334e68;
        }

        .notes-cell {
            white-space: pre-line;
        }

        .page-footer {
            position: fixed;
            bottom: -8px;
            left: 0;
            right: 0;
            font-size: 9px;
            color: #7b8794;
            text-align: center;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>Memo del dottore</h1>
        <p class="meta">
            <strong>Dottore:</strong> <?= esc($doctorLabel ?? '') ?><br>
            <strong>Memo attive:</strong> <?= (int)($totalNotes ?? 0) ?>
            <?php if (!empty($generatedAt)): ?>
                | <strong>Generato il:</strong> <?= esc($generatedAt) ?>
            <?php endif; ?>
            <?php if ($agendaMemoFieldIsVisible('validity_date') && !empty($todayLabel)): ?>
                | <strong>Riferimento stato:</strong> <?= esc($todayLabel) ?>
            <?php endif; ?>
        </p>

        <?php if ($agendaMemoFieldIsVisible('validity_date')): ?>
        <div class="legend">
            <span class="legend-item"><span class="legend-dot scaduta"></span>Scaduta</span>
            <span class="legend-item"><span class="legend-dot oggi"></span>Valida oggi</span>
            <span class="legend-item"><span class="legend-dot futura"></span>Futura</span>
        </div>
        <?php endif; ?>
    </div>

    <?php if (empty($notes)): ?>
        <div class="empty">Nessuna memo attiva presente per il dottore selezionato.</div>
    <?php else: ?>
        <?php foreach ($notes as $note): ?>
            <?php
                $memoMetaParts = [];
                if ($agendaMemoFieldIsVisible('validity_date')) {
                    $memoMetaParts[] = '<strong>Valida dal:</strong> '
                        . memo_pdf_value($note['data_validita_label'] ?? '');
                }
                if (!empty($note['created_at_label'])) {
                    $memoMetaParts[] = '<strong>Inserita il:</strong> ' . esc($note['created_at_label']);
                }
                if (!empty($note['created_by_username'])) {
                    $memoMetaParts[] = '<strong>Utente:</strong> ' . esc($note['created_by_username']);
                }
                $phoneVisible = $agendaMemoFieldIsVisible('phone');
                $mobileVisible = $agendaMemoFieldIsVisible('mobile');
                $addressVisible = $agendaMemoFieldIsVisible('address');
                $cityVisible = $agendaMemoFieldIsVisible('city');
                $notesVisible = $agendaMemoFieldIsVisible('notes');
                $hasMemoDetails = $phoneVisible || $mobileVisible || $addressVisible || $cityVisible || $notesVisible;
            ?>
            <div class="memo-card <?= $agendaMemoFieldIsVisible('validity_date') ? esc($note['status_class'] ?? 'status-oggi') : 'status-oggi' ?>">
                <div class="memo-head">
                    <div class="memo-title"><?= memo_pdf_value($note['cliente_label'] ?? '', 'Senza cliente') ?></div>
                    <?php if ($agendaMemoFieldIsVisible('validity_date')): ?>
                    <span class="badge <?= esc($note['status_badge_class'] ?? 'badge-oggi') ?>">
                        <?= esc($note['status_label'] ?? 'Oggi') ?>
                    </span>
                    <?php endif; ?>
                </div>

                <?php if ($memoMetaParts !== []): ?>
                <div class="memo-meta">
                    <?= implode(' | ', $memoMetaParts) ?>
                </div>
                <?php endif; ?>

                <?php if ($hasMemoDetails): ?>
                <table class="memo-grid">
                    <?php if ($phoneVisible || $mobileVisible): ?>
                    <tr>
                        <?php if ($phoneVisible): ?>
                        <th>Telefono</th>
                        <td<?= $mobileVisible ? '' : ' colspan="3"' ?>><?= memo_pdf_value($note['telefono'] ?? '') ?></td>
                        <?php endif; ?>
                        <?php if ($mobileVisible): ?>
                        <th>Cellulare</th>
                        <td<?= $phoneVisible ? '' : ' colspan="3"' ?>><?= memo_pdf_value($note['cellulare'] ?? '') ?></td>
                        <?php endif; ?>
                    </tr>
                    <?php endif; ?>
                    <?php if ($addressVisible || $cityVisible): ?>
                    <tr>
                        <?php if ($addressVisible): ?>
                        <th>Indirizzo</th>
                        <td<?= $cityVisible ? '' : ' colspan="3"' ?>><?= memo_pdf_value($note['indirizzo'] ?? '') ?></td>
                        <?php endif; ?>
                        <?php if ($cityVisible): ?>
                        <th>Città</th>
                        <td<?= $addressVisible ? '' : ' colspan="3"' ?>><?= memo_pdf_value($note['citta'] ?? '') ?></td>
                        <?php endif; ?>
                    </tr>
                    <?php endif; ?>
                    <?php if ($notesVisible): ?>
                    <tr>
                        <th>Note</th>
                        <td colspan="3" class="notes-cell"><?= nl2br(esc((string)($note['note'] ?? ''))) ?></td>
                    </tr>
                    <?php endif; ?>
                </table>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>

    <div class="page-footer">Memo dottore</div>

    <?php if (isset($pdf)): ?>
        <?php
        $pdf->page_script('
            $font = $fontMetrics->get_font("Helvetica", "normal");
            $size = 8;
            $text = "Pagina " . $PAGE_NUM . " di " . $PAGE_COUNT;
            $pdf->text(500, 820, $text, $font, $size);
        ');
        ?>
    <?php endif; ?>
</body>
</html>

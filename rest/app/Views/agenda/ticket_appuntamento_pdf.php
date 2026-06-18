<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <style>
        @page {
            margin: 0 7pt 2pt 8pt;
        }

        body {
            margin: 0;
            padding: 0;
            color: #000;
            font-family: DejaVu Sans, sans-serif;
            font-size: 10pt;
            line-height: 1.08;
        }

        .ticket {
            width: 100%;
        }

        .logo-wrap {
            margin: 0 0 3pt 0;
        }

        .logo-wrap img {
            display: block;
            width: 100%;
            height: auto;
        }

        .line {
            margin: 0 0 1pt 0;
            font-size: 10pt;
            font-weight: 700;
        }

        .title {
            margin: 10pt 0 8pt 0;
            font-size: 7pt;
            font-weight: 700;
            letter-spacing: .35pt;
        }

        .big {
            margin: 0 0 7pt 0;
            font-size: 12pt;
            font-weight: 700;
            line-height: 1.18;
        }

        .big.tight {
            margin-bottom: 4pt;
        }
    </style>
</head>
<body>
    <div class="ticket">
        <?php if (!empty($logo_data_uri)): ?>
            <div class="logo-wrap">
                <img src="<?= esc($logo_data_uri) ?>" alt="Logo">
            </div>
        <?php endif; ?>

        <?php if (!empty($indirizzo)): ?>
            <div class="line"><?= esc($indirizzo) ?></div>
        <?php endif; ?>

        <?php if (!empty($citta)): ?>
            <div class="line"><?= esc($citta) ?></div>
        <?php endif; ?>

        <?php if (!empty($telefono)): ?>
            <div class="line"><?= esc($telefono) ?></div>
        <?php endif; ?>

        <div class="title">PROMEMORIA APPUNTAMENTO</div>

        <div class="big">di <?= esc(mb_strtoupper((string)($patient_label ?? ''), 'UTF-8')) ?></div>
        <div class="big"><?= esc(mb_strtoupper((string)($doctor_label ?? ''), 'UTF-8')) ?></div>
        <div class="big tight"><?= esc(trim((string)($weekday_label ?? '') . ' ' . (string)($date_label ?? ''))) ?></div>
        <div class="big tight">alle ore <?= esc((string)($time_label ?? '')) ?></div>

        <?php if (!empty($ambulatorio_label)): ?>
            <div class="line"><?= esc($ambulatorio_label) ?></div>
        <?php endif; ?>

        <?php if (!empty($stanza)): ?>
            <div class="line">Stanza <?= esc($stanza) ?></div>
        <?php endif; ?>
    </div>
</body>
</html>

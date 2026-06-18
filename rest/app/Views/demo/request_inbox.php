<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= esc($brandName) ?> | Archivio richieste demo</title>
    <meta name="description" content="Archivio locale richieste demo guidata">
    <link rel="stylesheet" href="<?= base_url('public/assets/css/demo-showcase.css') ?>">
</head>
<body>
<div class="demo-shell">
    <div class="demo-orb demo-orb-a"></div>
    <div class="demo-orb demo-orb-b"></div>
    <?php
    $filters = is_array($filters ?? null) ? $filters : [];
    $requestStats = is_array($requestStats ?? null) ? $requestStats : [];
    $verticalOptions = is_array($verticalOptions ?? null) ? $verticalOptions : [];
    $notificationOptions = is_array($notificationOptions ?? null) ? $notificationOptions : [];
    $activeFilters = (int) ($requestStats['active_filters'] ?? 0);
    ?>

    <main class="demo-page">
        <section class="hero-card hero-card-vertical">
            <div class="hero-stack">
                <div class="hero-main">
                    <a class="back-link" href="<?= site_url('demo/richiesta') ?>">Torna alla richiesta demo</a>
                    <p class="eyebrow">Archivio locale</p>
                    <h1>Richieste demo salvate</h1>
                    <p class="hero-copy">
                        Questa pagina e visibile solo in locale e serve a controllare rapidamente i lead raccolti dalla linea commerciale.
                    </p>
                    <p class="hero-copy hero-copy-secondary">
                        I file restano salvati separatamente in <strong><?= esc((string) $storagePath) ?></strong>.
                    </p>
                    <div class="hero-actions">
                        <a class="btn btn-primary" href="<?= site_url('demo/richiesta') ?>">Nuova richiesta demo</a>
                        <a class="btn btn-secondary" href="<?= site_url('demo') ?>">Torna alla showcase</a>
                    </div>
                </div>

                <aside class="hero-side">
                    <div class="hero-side-card">
                        <p class="status-label">Lead mostrati</p>
                        <h3><?= esc((string) ($requestStats['filtered'] ?? count((array) $requests))) ?></h3>
                        <p class="status-note">
                            <?= $activeFilters > 0 ? esc((string) ('Filtri attivi: ' . $activeFilters . '. ')) : 'Nessun filtro attivo. ' ?>
                            Archivio totale: <?= esc((string) ($requestStats['total'] ?? count((array) $requests))) ?> lead.
                        </p>
                    </div>
                </aside>
            </div>
        </section>

        <section class="panel">
            <div class="panel-head">
                <p class="eyebrow">Filtro operativo</p>
                <h2>Seleziona i lead da lavorare</h2>
            </div>
            <div class="demo-form-card">
                <form method="get" action="<?= site_url('demo/richieste-locali') ?>" class="filter-toolbar">
                    <div class="filter-grid">
                        <div class="form-field">
                            <label for="vertical">Verticale</label>
                            <select name="vertical" id="vertical">
                                <option value="">Tutte le verticali</option>
                                <?php foreach ($verticalOptions as $option): ?>
                                    <option value="<?= esc((string) ($option['value'] ?? '')) ?>" <?= ((string) ($filters['vertical'] ?? '') === (string) ($option['value'] ?? '')) ? 'selected' : '' ?>>
                                        <?= esc((string) ($option['label'] ?? $option['value'] ?? '')) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-field">
                            <label for="notification_status">Notifica email</label>
                            <select name="notification_status" id="notification_status">
                                <option value="">Tutti gli stati</option>
                                <?php foreach ($notificationOptions as $option): ?>
                                    <option value="<?= esc((string) ($option['value'] ?? '')) ?>" <?= ((string) ($filters['notification_status'] ?? '') === (string) ($option['value'] ?? '')) ? 'selected' : '' ?>>
                                        <?= esc((string) ($option['label'] ?? $option['value'] ?? '')) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-field form-field-full">
                            <label for="q">Cerca nei lead</label>
                            <input
                                type="text"
                                name="q"
                                id="q"
                                value="<?= esc((string) ($filters['q'] ?? '')) ?>"
                                placeholder="Struttura, referente, email, telefono, note"
                            >
                        </div>
                    </div>
                    <div class="hero-actions hero-actions-compact">
                        <button class="btn btn-primary btn-button" type="submit">Applica filtri</button>
                        <a class="btn btn-secondary" href="<?= site_url('demo/richieste-locali') ?>">Reset</a>
                        <a class="btn btn-secondary" href="<?= esc((string) $exportUrl) ?>">Esporta CSV</a>
                    </div>
                </form>

                <div class="inbox-stats">
                    <article class="inbox-stat-card">
                        <p class="status-label">Archivio totale</p>
                        <h3><?= esc((string) ($requestStats['total'] ?? 0)) ?></h3>
                        <p class="status-note">Richieste presenti nei file locali.</p>
                    </article>
                    <article class="inbox-stat-card">
                        <p class="status-label">Risultati correnti</p>
                        <h3><?= esc((string) ($requestStats['filtered'] ?? 0)) ?></h3>
                        <p class="status-note">Lead inclusi dai filtri attuali.</p>
                    </article>
                    <article class="inbox-stat-card">
                        <p class="status-label">Notifiche inviate</p>
                        <h3><?= esc((string) ($requestStats['sent'] ?? 0)) ?></h3>
                        <p class="status-note">Lead gia notificati via email.</p>
                    </article>
                    <article class="inbox-stat-card">
                        <p class="status-label">Da verificare</p>
                        <h3><?= esc((string) ($requestStats['attention'] ?? 0)) ?></h3>
                        <p class="status-note">Lead con notifica non inviata o non attiva.</p>
                    </article>
                </div>
            </div>
        </section>

        <?php if ($requests === []): ?>
            <section class="panel">
                <div class="note-box">
                    <p>
                        <?= $activeFilters > 0
                            ? 'Nessun lead corrisponde ai filtri attuali. Puoi resettarli o esportare l archivio completo.'
                            : 'Non ci sono ancora richieste demo salvate in locale.' ?>
                    </p>
                </div>
            </section>
        <?php else: ?>
            <section class="panel">
                <div class="panel-head">
                    <p class="eyebrow">Lead recenti</p>
                    <h2>Contatti pronti da lavorare</h2>
                </div>
                <div class="access-grid access-grid-wide">
                    <?php foreach ($requests as $request): ?>
                        <article class="access-card">
                            <div class="access-card-topline">
                                <p class="status-label"><?= esc((string) ($request['vertical_label'] ?? $request['vertical'] ?? 'verticale')) ?></p>
                                <span class="status-pill status-pill-<?= esc((string) ($request['notification_tone'] ?? 'neutral')) ?>">
                                    <?= esc((string) ($request['notification_label'] ?? 'In attesa')) ?>
                                </span>
                            </div>
                            <h3><?= esc((string) ($request['business_name'] ?? '-')) ?></h3>
                            <p class="access-note"><strong>ID:</strong> <?= esc((string) ($request['request_id'] ?? '-')) ?></p>
                            <p class="access-note"><strong>Referente:</strong> <?= esc((string) ($request['full_name'] ?? '-')) ?></p>
                            <p class="access-note"><strong>Email:</strong> <?= esc((string) ($request['email'] ?? '-')) ?></p>
                            <p class="access-note"><strong>Telefono:</strong> <?= esc((string) (($request['phone'] ?? '') !== '' ? $request['phone'] : '-')) ?></p>
                            <p class="access-note"><strong>Ruolo:</strong> <?= esc((string) (($request['contact_role'] ?? '') !== '' ? $request['contact_role'] : '-')) ?></p>
                            <p class="access-note"><strong>Team:</strong> <?= esc((string) (($request['team_size'] ?? '') !== '' ? $request['team_size'] : '-')) ?></p>
                            <p class="access-note"><strong>Fascia:</strong> <?= esc((string) (($request['preferred_slot'] ?? '') !== '' ? $request['preferred_slot'] : '-')) ?></p>
                            <p class="access-note"><strong>Data:</strong> <?= esc((string) ($request['created_at'] ?? '-')) ?></p>
                            <?php $notification = (array) ($request['notification'] ?? []); ?>
                            <p class="access-note"><strong>Notifica email:</strong> <?= esc((string) (($notification['message'] ?? '') !== '' ? $notification['message'] : 'Nessuna informazione disponibile.')) ?></p>
                            <?php if (!empty($request['notes'])): ?>
                                <div class="note-box">
                                    <p><?= esc((string) $request['notes']) ?></p>
                                </div>
                            <?php endif; ?>
                            <p class="access-lockline">File: <?= esc((string) ($request['file_name'] ?? '-')) ?></p>
                        </article>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endif; ?>
    </main>
</div>
</body>
</html>

<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= esc($brandName) ?> Demo Showcase</title>
    <meta name="description" content="<?= esc($brandDescription) ?>">
    <link rel="stylesheet" href="<?= base_url('public/assets/css/demo-showcase.css') ?>">
</head>
<body>
<div class="demo-shell">
    <div class="demo-orb demo-orb-a"></div>
    <div class="demo-orb demo-orb-b"></div>

    <main class="demo-page">
        <section class="hero-card">
            <p class="eyebrow">Demo commerciale separata</p>
            <h1><?= esc($brandName) ?></h1>
            <p class="hero-copy">
                <?= esc($brandDescription) ?>
                <?php if ($showLocalAccess): ?>
                    La demo locale usa dati finti, database dedicato e una runtime separata dalla farmacia.
                <?php else: ?>
                    Due percorsi verticali gia pronti per una presentazione commerciale guidata, costruiti per mostrare valore operativo senza esporre dati reali.
                <?php endif; ?>
            </p>
            <div class="hero-actions">
                <a class="btn btn-primary" href="<?= site_url('demo/vertical/medical') ?>">Apri percorso medical</a>
                <a class="btn btn-secondary" href="<?= site_url('demo/vertical/sport-rehab') ?>">Apri percorso sport rehab</a>
                <a class="btn btn-secondary" href="<?= site_url('demo/richiesta') ?>">Richiedi demo guidata</a>
            </div>
        </section>

        <section class="panel">
            <div class="panel-head">
                <p class="eyebrow">Verticali scelti</p>
                <h2>Dove il prodotto e gia forte e vendibile</h2>
            </div>
            <div class="profile-grid">
                <?php foreach ($profiles as $index => $profile): ?>
                    <article class="profile-card" style="--delay: <?= (int) $index ?>">
                        <p class="profile-kicker"><?= esc((string) ($profile['profile_id'] ?? 'vertical')) ?></p>
                        <h3><?= esc((string) ($profile['label'] ?? 'Profilo')) ?></h3>
                        <p class="profile-positioning"><?= esc((string) ($profile['positioning'] ?? '')) ?></p>

                        <?php $audience = (array) ($profile['audience'] ?? []); ?>
                        <?php if ($audience !== []): ?>
                            <div class="chip-row">
                                <?php foreach ($audience as $segment): ?>
                                    <span class="chip"><?= esc((string) $segment) ?></span>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>

                        <div class="metrics">
                            <?php foreach ((array) ($profile['demo_entities'] ?? []) as $key => $value): ?>
                                <div class="metric">
                                    <span class="metric-value"><?= esc((string) $value) ?></span>
                                    <span class="metric-label"><?= esc(str_replace('_', ' ', (string) $key)) ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <div class="split-list">
                            <div>
                                <h4>Storyline demo</h4>
                                <ul>
                                    <?php foreach ((array) ($profile['demo_storylines'] ?? []) as $story): ?>
                                        <li><?= esc((string) $story) ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                            <div>
                                <h4>Moduli valorizzati</h4>
                                <ul>
                                    <?php foreach ((array) ($profile['modules'] ?? []) as $module): ?>
                                        <li><?= esc((string) $module) ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        </div>
                        <div class="card-actions">
                            <a class="btn btn-secondary btn-inline" href="<?= esc((string) ($profileLinks[(string) ($profile['profile_id'] ?? '')] ?? site_url('demo'))) ?>">Apri percorso guidato</a>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>

        <section class="panel">
            <div class="panel-head">
                <p class="eyebrow">Perche entra adesso</p>
                <h2>Cosa rende il prodotto gia vendibile</h2>
            </div>
            <div class="feature-grid">
                <?php foreach ((array) ($commercialHighlights ?? []) as $item): ?>
                    <article class="detail-card feature-card">
                        <p class="status-label"><?= esc((string) ($item['eyebrow'] ?? 'Valore')) ?></p>
                        <h3><?= esc((string) ($item['title'] ?? 'Punto di forza')) ?></h3>
                        <p class="access-note"><?= esc((string) ($item['body'] ?? '')) ?></p>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>

        <section class="panel">
            <div class="panel-head">
                <p class="eyebrow">Packaging commerciale</p>
                <h2>Come proporlo senza stravolgere il core</h2>
            </div>
            <div class="package-grid">
                <?php foreach ((array) ($commercialPackages ?? []) as $package): ?>
                    <article class="detail-card package-card">
                        <p class="status-label"><?= esc((string) ($package['tag'] ?? 'Pacchetto')) ?></p>
                        <h3><?= esc((string) ($package['title'] ?? 'Offerta')) ?></h3>
                        <p class="access-note"><?= esc((string) ($package['fit'] ?? '')) ?></p>
                        <ul class="detail-list">
                            <?php foreach ((array) ($package['items'] ?? []) as $item): ?>
                                <li><?= esc((string) $item) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>

        <?php if ($showTechnicalStatus): ?>
        <section class="panel panel-status">
            <div class="panel-head">
                <p class="eyebrow">Stato demo</p>
                <h2>Base separata pronta per la commercializzazione</h2>
            </div>
            <div class="status-grid">
                <article class="status-card">
                    <p class="status-label">Database demo</p>
                    <h3><?= esc((string) ($seedStatus['database'] ?? 'dottorapp_demo')) ?></h3>
                    <p class="status-note">Seed piu recente: <?= esc((string) ($seedStatus['finished_at'] ?: 'non disponibile')) ?></p>
                    <ul class="status-list">
                        <?php foreach (array_slice((array) ($seedStatus['counts'] ?? []), 0, 8, true) as $key => $value): ?>
                            <li><strong><?= esc(str_replace('_', ' ', (string) $key)) ?>:</strong> <?= esc((string) (is_array($value) ? json_encode($value) : $value)) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </article>

                <article class="status-card">
                    <p class="status-label">Runtime separata</p>
                    <h3><?= esc((string) strtoupper((string) ($runtimeStatus['status'] ?? 'missing'))) ?></h3>
                    <p class="status-note">Ultimo build: <?= esc((string) ($runtimeStatus['finished_at'] ?: 'non disponibile')) ?></p>
                    <p class="status-note">Path: <span class="path-text"><?= esc((string) ($runtimeStatus['destination'] ?? '')) ?></span></p>
                    <p class="status-note">Asset legacy mancanti rilevati: <?= esc((string) ($runtimeStatus['missing_assets'] ?? 0)) ?></p>
                </article>
            </div>

            <?php if (! empty($runtimeStatus['missing_assets_summary'])): ?>
                <div class="summary-strip">
                    <?php foreach ((array) $runtimeStatus['missing_assets_summary'] as $bucket): ?>
                        <div class="summary-pill">
                            <span><?= esc((string) ($bucket['bucket'] ?? 'bucket')) ?></span>
                            <strong><?= esc((string) ($bucket['count'] ?? 0)) ?></strong>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if (! empty($runtimeStatus['notes'])): ?>
                <div class="note-box">
                    <?php foreach ((array) $runtimeStatus['notes'] as $note): ?>
                        <p><?= esc((string) $note) ?></p>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
        <?php endif; ?>

        <?php if ($showLocalAccess): ?>
            <section class="panel panel-access">
                <div class="panel-head">
                    <p class="eyebrow">Accessi locali</p>
                    <h2>Solo per la demo interna su localhost</h2>
                </div>
                <div class="access-grid">
                    <?php foreach ($demoAccounts as $account): ?>
                        <article class="access-card">
                            <p class="status-label"><?= esc($account['role']) ?></p>
                            <h3><?= esc($account['username']) ?></h3>
                            <p class="access-secret">Password: <strong><?= esc($account['password']) ?></strong></p>
                            <p class="access-note"><?= esc($account['note']) ?></p>
                        </article>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endif; ?>
    </main>
</div>
</body>
</html>

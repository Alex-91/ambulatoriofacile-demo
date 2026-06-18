<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= esc($brandName) ?> | <?= esc((string) ($profile['label'] ?? 'Verticale demo')) ?></title>
    <meta name="description" content="<?= esc((string) (($playbook['subheadline'] ?? '') ?: $brandDescription)) ?>">
    <link rel="stylesheet" href="<?= base_url('public/assets/css/demo-showcase.css') ?>">
</head>
<body>
<div class="demo-shell">
    <div class="demo-orb demo-orb-a"></div>
    <div class="demo-orb demo-orb-b"></div>

    <main class="demo-page">
        <section class="hero-card hero-card-vertical">
            <div class="hero-stack">
                <div class="hero-main">
                    <a class="back-link" href="<?= site_url('demo') ?>">Torna alla demo overview</a>
                    <p class="eyebrow">Percorso guidato</p>
                    <h1><?= esc((string) ($profile['label'] ?? 'Verticale')) ?></h1>
                    <p class="hero-copy">
                        <?= esc((string) ($playbook['headline'] ?? '')) ?>
                    </p>
                    <p class="hero-copy hero-copy-secondary">
                        <?= esc((string) ($playbook['subheadline'] ?? '')) ?>
                    </p>
                    <div class="hero-actions">
                        <a class="btn btn-primary" href="<?= site_url('demo/access/' . $profileSlug) ?>">Scegli accesso demo</a>
                        <a class="btn btn-secondary" href="<?= site_url('demo') ?>">Torna ai due verticali</a>
                        <a class="btn btn-secondary" href="<?= site_url('demo/richiesta?profile=' . $profileSlug) ?>">Richiedi demo guidata</a>
                    </div>
                </div>

                <aside class="hero-side">
                    <div class="hero-side-card">
                        <p class="status-label">Target ideale</p>
                        <div class="chip-row">
                            <?php foreach ((array) ($profile['audience'] ?? []) as $segment): ?>
                                <span class="chip"><?= esc((string) $segment) ?></span>
                            <?php endforeach; ?>
                        </div>
                        <div class="metrics">
                            <?php foreach ((array) ($profile['demo_entities'] ?? []) as $key => $value): ?>
                                <div class="metric">
                                    <span class="metric-value"><?= esc((string) $value) ?></span>
                                    <span class="metric-label"><?= esc(str_replace('_', ' ', (string) $key)) ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </aside>
            </div>
        </section>

        <section class="panel">
            <div class="panel-head">
                <p class="eyebrow">Messaggio commerciale</p>
                <h2>Perche questo verticale e una buona entrata di mercato</h2>
            </div>
            <div class="dual-grid">
                <article class="detail-card">
                    <h3>Segnali di acquisto</h3>
                    <ul class="detail-list">
                        <?php foreach ((array) ($playbook['buyer_signals'] ?? []) as $item): ?>
                            <li><?= esc((string) $item) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </article>
                <article class="detail-card">
                    <h3>Problemi che la demo risolve</h3>
                    <ul class="detail-list">
                        <?php foreach ((array) ($playbook['pain_points'] ?? []) as $item): ?>
                            <li><?= esc((string) $item) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </article>
            </div>
        </section>

        <section class="panel">
            <div class="panel-head">
                <p class="eyebrow">Percorso di vendita</p>
                <h2>Come raccontare la demo in 7 minuti</h2>
            </div>
            <div class="flow-grid">
                <?php foreach ((array) ($playbook['demo_flow'] ?? []) as $index => $flow): ?>
                    <article class="flow-card">
                        <div class="flow-index"><?= esc((string) ($index + 1)) ?></div>
                        <div class="flow-body">
                            <h3><?= esc((string) ($flow['title'] ?? 'Flusso')) ?></h3>
                            <ul class="detail-list">
                                <?php foreach ((array) ($flow['steps'] ?? []) as $step): ?>
                                    <li><?= esc((string) $step) ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>

        <section class="panel">
            <div class="panel-head">
                <p class="eyebrow">Valore operativo</p>
                <h2>Cosa valorizzare durante la demo</h2>
            </div>
            <div class="dual-grid">
                <article class="detail-card">
                    <h3>Outcome da ribadire</h3>
                    <ul class="detail-list">
                        <?php foreach ((array) ($playbook['outcomes'] ?? []) as $item): ?>
                            <li><?= esc((string) $item) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </article>
                <article class="detail-card">
                    <h3>Moduli da enfatizzare</h3>
                    <ul class="detail-list">
                        <?php foreach ((array) ($profile['modules'] ?? []) as $module): ?>
                            <li><?= esc((string) $module) ?></li>
                        <?php endforeach; ?>
                    </ul>
                    <p class="detail-note"><?= esc((string) ($playbook['pricing_hint'] ?? '')) ?></p>
                </article>
            </div>
        </section>

        <section class="panel">
            <div class="panel-head">
                <p class="eyebrow">Packaging suggerito</p>
                <h2>Come confezionare l offerta per questo verticale</h2>
            </div>
            <div class="dual-grid">
                <article class="detail-card">
                    <h3>Entrata commerciale consigliata</h3>
                    <p class="detail-note"><?= esc((string) ($playbook['pricing_hint'] ?? '')) ?></p>
                    <ul class="detail-list">
                        <?php foreach ((array) ($profile['audience'] ?? []) as $segment): ?>
                            <li><?= esc('Target: ' . (string) $segment) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </article>
                <article class="detail-card">
                    <h3>Pacchetti proponibili</h3>
                    <div class="package-grid package-grid-compact">
                        <?php foreach ((array) ($commercialPackages ?? []) as $package): ?>
                            <article class="package-card package-card-mini">
                                <p class="status-label"><?= esc((string) ($package['tag'] ?? 'Pacchetto')) ?></p>
                                <h4><?= esc((string) ($package['title'] ?? 'Offerta')) ?></h4>
                                <p class="access-note"><?= esc((string) ($package['fit'] ?? '')) ?></p>
                            </article>
                        <?php endforeach; ?>
                    </div>
                </article>
            </div>
        </section>

        <?php if ($showTechnicalStatus): ?>
        <section class="panel panel-status">
            <div class="panel-head">
                <p class="eyebrow">Stato tecnico</p>
                <h2>Base demo separata su cui vendere</h2>
            </div>
            <div class="status-grid">
                <article class="status-card">
                    <p class="status-label">Dataset demo</p>
                    <h3><?= esc((string) ($seedStatus['database'] ?? 'dottorapp_demo')) ?></h3>
                    <p class="status-note">Ultimo seed: <?= esc((string) ($seedStatus['finished_at'] ?: 'non disponibile')) ?></p>
                    <ul class="status-list">
                        <?php foreach (array_slice((array) ($seedStatus['counts'] ?? []), 0, 8, true) as $key => $value): ?>
                            <li><strong><?= esc(str_replace('_', ' ', (string) $key)) ?>:</strong> <?= esc((string) (is_array($value) ? json_encode($value) : $value)) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </article>
                <article class="status-card">
                    <p class="status-label">Runtime demo</p>
                    <h3><?= esc((string) strtoupper((string) ($runtimeStatus['status'] ?? 'missing'))) ?></h3>
                    <p class="status-note">Ultimo build: <?= esc((string) ($runtimeStatus['finished_at'] ?: 'non disponibile')) ?></p>
                    <p class="status-note">Asset legacy mancanti: <?= esc((string) ($runtimeStatus['missing_assets'] ?? 0)) ?></p>
                    <div class="summary-strip summary-strip-compact">
                        <?php foreach ((array) ($runtimeStatus['missing_assets_summary'] ?? []) as $bucket): ?>
                            <div class="summary-pill">
                                <span><?= esc((string) ($bucket['bucket'] ?? 'bucket')) ?></span>
                                <strong><?= esc((string) ($bucket['count'] ?? 0)) ?></strong>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </article>
            </div>
        </section>
        <?php endif; ?>

        <?php if ($showLocalAccess && $profileAccounts !== []): ?>
            <section class="panel panel-access">
                <div class="panel-head">
                    <p class="eyebrow">Accessi demo interni</p>
                    <h2>Account utili per questo verticale su localhost</h2>
                </div>
                <div class="access-grid">
                    <?php foreach ($profileAccounts as $account): ?>
                        <article class="access-card">
                            <p class="status-label"><?= esc($account['role']) ?></p>
                            <h3><?= esc($account['username']) ?></h3>
                            <p class="access-secret">Password: <strong><?= esc($account['password']) ?></strong></p>
                            <p class="access-note"><?= esc($account['label']) ?></p>
                            <p class="access-note"><?= esc($account['note']) ?></p>
                        </article>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endif; ?>

        <section class="panel">
            <div class="panel-head">
                <p class="eyebrow">Accesso guidato</p>
                <h2>Porta la conversazione sulla demo operativa</h2>
            </div>
            <div class="dual-grid">
                <article class="detail-card">
                    <h3>Prossimo passaggio naturale</h3>
                    <p class="access-note">
                        Dopo questa overview puoi entrare nel flusso demo del verticale e mostrare login, agenda, reminder e coordinamento team con un percorso gia preparato.
                    </p>
                </article>
                <article class="detail-card">
                    <h3>Apri il percorso</h3>
                    <div class="hero-actions hero-actions-compact">
                        <a class="btn btn-primary btn-inline" href="<?= site_url('demo/access/' . $profileSlug) ?>">Apri accesso demo</a>
                        <a class="btn btn-secondary btn-inline" href="<?= site_url('demo') ?>">Torna alla panoramica</a>
                        <a class="btn btn-secondary btn-inline" href="<?= site_url('demo/richiesta?profile=' . $profileSlug) ?>">Richiedi demo</a>
                    </div>
                </article>
            </div>
        </section>

        <?php if ($showTechnicalStatus): ?>
        <section class="panel">
            <div class="panel-head">
                <p class="eyebrow">Prossimi passi</p>
                <h2>Cosa fare per trasformarlo in pacchetto vendibile</h2>
            </div>
            <div class="dual-grid">
                <article class="detail-card">
                    <h3>Storyline gia pronta</h3>
                    <ul class="detail-list">
                        <?php foreach ((array) ($profile['demo_storylines'] ?? []) as $story): ?>
                            <li><?= esc((string) $story) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </article>
                <article class="detail-card">
                    <h3>Azioni consigliate</h3>
                    <ul class="detail-list">
                        <?php foreach ((array) ($playbook['next_moves'] ?? []) as $item): ?>
                            <li><?= esc((string) $item) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </article>
            </div>
        </section>
        <?php endif; ?>
    </main>
</div>
</body>
</html>

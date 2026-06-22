<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= esc($brandName) ?> Demo</title>
    <meta name="description" content="<?= esc($brandDescription) ?>">
    <link rel="stylesheet" href="<?= base_url('public/assets/css/demo-showcase.css') ?>">
</head>
<body>
<div class="demo-shell">
    <div class="demo-orb demo-orb-a"></div>
    <div class="demo-orb demo-orb-b"></div>

    <main class="demo-page">
        <section class="hero-card">
            <p class="eyebrow">Demo unica</p>
            <h1><?= esc($brandName) ?></h1>
            <p class="hero-copy">
                <?= esc($brandDescription) ?>
                La demo e una sola, usa dati separati dalla produzione e porta sempre allo stesso login operativo del prodotto.
            </p>
            <p class="hero-copy hero-copy-secondary">
                Il sito vetrina resta commerciale, la demo serve a provare il prodotto con account di test, mentre clienti e master entrano sempre dal login ufficiale.
            </p>
            <div class="hero-actions">
                <a class="btn btn-primary" href="<?= esc((string) ($demoCredentials['demo_access_url'] ?? site_url('access'))) ?>">Apri la demo</a>
                <a class="btn btn-secondary" href="<?= esc((string) ($demoCredentials['official_login_url'] ?? site_url('login'))) ?>">Login ufficiale</a>
                <a class="btn btn-secondary" href="<?= esc((string) ($demoCredentials['demo_request_url'] ?? site_url('richiesta'))) ?>">Richiedi demo guidata</a>
            </div>
        </section>

        <section class="panel">
            <div class="panel-head">
                <p class="eyebrow">Tre strade ordinate</p>
                <h2>Cosa vede il cliente e cosa usate voi</h2>
            </div>
            <div class="feature-grid">
                <article class="detail-card feature-card">
                    <p class="status-label">Vetrina</p>
                    <h3>ambulatoriofacile.it</h3>
                    <p class="access-note">Resta il sito pubblico di presentazione, senza account di lavoro e senza percorsi tecnici.</p>
                </article>
                <article class="detail-card feature-card">
                    <p class="status-label">Demo</p>
                    <h3>Un solo percorso prova</h3>
                    <p class="access-note">Da qui si entra nella demo con account test gia pronti, password comune e dati finti separati dalla produzione.</p>
                </article>
                <article class="detail-card feature-card">
                    <p class="status-label">Produzione</p>
                    <h3>Login unico sotto /login</h3>
                    <p class="access-note">Master piattaforma, tenant master e clienti reali usano sempre lo stesso ingresso ufficiale.</p>
                </article>
            </div>
        </section>

        <section class="panel">
            <div class="panel-head">
                <p class="eyebrow">Ordine di prova</p>
                <h2>Come testare la demo senza perderti pezzi</h2>
            </div>
            <div class="flow-grid">
                <?php foreach ((array) ($demoChecklist ?? []) as $index => $item): ?>
                    <article class="flow-card">
                        <div class="flow-index"><?= esc((string) ($index + 1)) ?></div>
                        <div class="flow-body">
                            <h3><?= esc((string) ($item['title'] ?? 'Passo demo')) ?></h3>
                            <p class="access-note">Entra con <strong><?= esc((string) ($item['username'] ?? '')) ?></strong></p>
                            <p class="access-note"><?= esc((string) ($item['goal'] ?? '')) ?></p>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>

        <section class="panel">
            <div class="panel-head">
                <p class="eyebrow">Valore prodotto</p>
                <h2>Cosa conviene far emergere durante la prova</h2>
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
                <p class="eyebrow">Pacchetti</p>
                <h2>Come presentarlo in modo semplice</h2>
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
                <h2>Base separata pronta per essere provata</h2>
            </div>
            <div class="status-grid">
                <article class="status-card">
                    <p class="status-label">Database demo</p>
                    <h3><?= esc((string) ($seedStatus['database'] ?? 'dottorapp_demo')) ?></h3>
                    <p class="status-note">Seed piu recente: <?= esc((string) ($seedStatus['finished_at'] ?: 'non disponibile')) ?></p>
                </article>
                <article class="status-card">
                    <p class="status-label">Runtime separata</p>
                    <h3><?= esc((string) strtoupper((string) ($runtimeStatus['status'] ?? 'missing'))) ?></h3>
                    <p class="status-note">Ultimo build: <?= esc((string) ($runtimeStatus['finished_at'] ?: 'non disponibile')) ?></p>
                    <p class="status-note">Path: <span class="path-text"><?= esc((string) ($runtimeStatus['destination'] ?? '')) ?></span></p>
                </article>
            </div>
        </section>
        <?php endif; ?>

        <section class="panel panel-access">
            <div class="panel-head">
                <p class="eyebrow">Account demo</p>
                <h2>Lista pronta per provare tutto</h2>
            </div>
            <div class="note-box">
                <p>Password demo comune: <strong><?= esc((string) ($demoCredentials['password'] ?? 'Demo2026')) ?></strong></p>
                <p>Quando un account o un alias richiede OTP, usa sempre <strong><?= esc((string) ($demoCredentials['otp'] ?? '2510')) ?></strong>.</p>
                <p>Tutti gli account qui sotto aprono lo stesso form di accesso sotto <strong>/login</strong>.</p>
            </div>

            <?php foreach ((array) ($demoAccountGroups ?? []) as $group): ?>
                <div class="account-section">
                    <div class="account-section-head">
                        <p class="status-label"><?= esc((string) ($group['title'] ?? 'Account demo')) ?></p>
                        <p class="access-note"><?= esc((string) ($group['note'] ?? '')) ?></p>
                    </div>
                    <div class="access-grid access-grid-wide">
                        <?php foreach ((array) ($group['accounts'] ?? []) as $account): ?>
                            <article class="access-card">
                                <div class="access-card-topline">
                                    <p class="status-label"><?= esc((string) ($account['role'] ?? 'Account demo')) ?></p>
                                    <?php if ((string) ($account['otp'] ?? '') !== ''): ?>
                                        <span class="status-pill status-pill-warning">OTP <?= esc((string) $account['otp']) ?></span>
                                    <?php endif; ?>
                                </div>
                                <h3><?= esc((string) ($account['username'] ?? '')) ?></h3>
                                <p class="access-note"><?= esc((string) ($account['label'] ?? '')) ?></p>
                                <p class="access-note"><?= esc((string) ($account['note'] ?? '')) ?></p>
                                <p class="access-secret">Password: <strong><?= esc((string) ($account['password'] ?? '')) ?></strong></p>
                                <?php if (!empty($account['scenarios']) && is_array($account['scenarios'])): ?>
                                    <div class="chip-row">
                                        <?php foreach ($account['scenarios'] as $scenario): ?>
                                            <span class="chip"><?= esc((string) $scenario) ?></span>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                                <div class="card-actions">
                                    <a class="btn btn-secondary btn-inline" href="<?= esc((string) ($account['login_url'] ?? ($demoCredentials['login_url'] ?? site_url('login')))) ?>">Apri /login precompilato</a>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </section>
    </main>
</div>
</body>
</html>

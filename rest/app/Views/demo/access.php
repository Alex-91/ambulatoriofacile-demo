<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= esc($brandName) ?> | Accesso demo</title>
    <meta name="description" content="Accesso guidato alla demo unica di AmbulatorioFacile">
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
                    <p class="eyebrow">Accesso demo guidato</p>
                    <h1><?= esc((string) ($demoLabel ?? 'Demo AmbulatorioFacile')) ?></h1>
                    <p class="hero-copy">
                        Qui trovi tutti gli account di prova utili per testare il prodotto senza cambiare percorso o dover passare tra demo diverse.
                    </p>
                    <p class="hero-copy hero-copy-secondary">
                        La demo usa dati separati dalla produzione e ti porta sempre allo stesso login operativo del prodotto.
                    </p>
                    <div class="hero-actions">
                        <a class="btn btn-primary" href="<?= esc((string) ($demoCredentials['login_url'] ?? site_url('login?demo=1'))) ?>">Apri login demo</a>
                        <a class="btn btn-secondary" href="<?= esc((string) ($demoCredentials['official_login_url'] ?? site_url('login'))) ?>">Apri login ufficiale</a>
                        <a class="btn btn-secondary" href="<?= site_url('demo/richiesta') ?>">Richiedi demo guidata</a>
                    </div>
                </div>

                <aside class="hero-side">
                    <div class="hero-side-card">
                        <p class="status-label">Credenziali rapide</p>
                        <h3>Password unica demo</h3>
                        <p class="status-note"><strong><?= esc((string) ($demoCredentials['password'] ?? 'Demo2026')) ?></strong></p>
                        <div class="note-box">
                            <p>Quando un account o un alias mostra il badge OTP, usa sempre <strong><?= esc((string) ($demoCredentials['otp'] ?? '2510')) ?></strong>.</p>
                            <p>Gli account demo non toccano dati reali e servono solo per prove commerciali e operative.</p>
                        </div>
                    </div>
                </aside>
            </div>
        </section>

        <section class="panel">
            <div class="panel-head">
                <p class="eyebrow">Checklist prova</p>
                <h2>Ordine consigliato per controllare tutto</h2>
            </div>
            <div class="flow-grid">
                <?php foreach ((array) ($demoChecklist ?? []) as $index => $item): ?>
                    <article class="flow-card">
                        <div class="flow-index"><?= esc((string) ($index + 1)) ?></div>
                        <div class="flow-body">
                            <h3><?= esc((string) ($item['title'] ?? 'Passo demo')) ?></h3>
                            <p class="access-note">Utente: <strong><?= esc((string) ($item['username'] ?? '')) ?></strong></p>
                            <p class="access-note"><?= esc((string) ($item['goal'] ?? '')) ?></p>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>

        <section class="panel panel-access">
            <div class="panel-head">
                <p class="eyebrow">Account disponibili</p>
                <h2>Ingressi utili per il test completo</h2>
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
                                    <a class="btn btn-secondary btn-inline" href="<?= esc((string) ($account['login_url'] ?? ($demoCredentials['login_url'] ?? site_url('login')))) ?>">Apri login precompilato</a>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </section>

        <section class="panel">
            <div class="panel-head">
                <p class="eyebrow">Promemoria operativo</p>
                <h2>Cosa tenere a mente durante la prova</h2>
            </div>
            <div class="dual-grid">
                <article class="detail-card">
                    <h3>Login unico</h3>
                    <p class="access-note">La demo non ha un motore separato di autenticazione: usa sempre lo stesso form di accesso che poi useranno clienti e master.</p>
                </article>
                <article class="detail-card">
                    <h3>Dati separati</h3>
                    <p class="access-note">Tutto quello che provi qui resta nella base demo e non interferisce con la produzione reale.</p>
                </article>
            </div>
        </section>
    </main>
</div>
</body>
</html>

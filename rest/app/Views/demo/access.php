<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= esc($brandName) ?> | Accesso demo</title>
    <meta name="description" content="Accesso guidato alla demo unica di AmbulatorioFacile">
    <link rel="stylesheet" href="<?= base_url('rest/public/assets/css/demo-showcase.css') ?>">
</head>
<body>
<div class="demo-shell">
    <div class="demo-orb demo-orb-a"></div>
    <div class="demo-orb demo-orb-b"></div>

    <main class="demo-page">
        <section class="hero-card hero-card-vertical">
            <div class="hero-stack">
                <div class="hero-main">
                    <p class="eyebrow">Accesso demo pubblico</p>
                    <h1>Scegli subito il ruolo da provare</h1>
                    <p class="hero-copy">
                        La demo parte da qui: scegli il ruolo, entri subito e puoi passare da tenant master a utente normale, segreteria, dottore o paziente senza usare il login iniziale.
                    </p>
                    <p class="hero-copy hero-copy-secondary">
                        Tutto gira su dati fittizi separati dalla produzione e il cambio ruolo resta sempre disponibile dal menu utente dentro la demo.
                    </p>
                    <div class="hero-actions">
                        <a class="btn btn-primary" href="#ruoli-demo">Scegli un ruolo</a>
                        <a class="btn btn-secondary" href="<?= esc((string) ($demoCredentials['official_login_url'] ?? site_url('login'))) ?>">Apri login ufficiale</a>
                    </div>
                </div>

                <aside class="hero-side">
                    <div class="hero-side-card">
                        <?php if (!empty($demoPublicAccessEnabled)): ?>
                            <p class="status-label">Nessun login iniziale</p>
                            <h3>Ruoli demo gia pronti</h3>
                            <div class="note-box">
                                <p>Le card tenant aprono la nuova area spazio cliente senza passare dal form di accesso.</p>
                                <p>Le card operative coprono agenda come dottore e segreteria, posta come dottore, paziente e segreteria, chat come dottore e segreteria.</p>
                            </div>
                        <?php else: ?>
                            <p class="status-label">Credenziali rapide</p>
                            <h3>Password unica demo</h3>
                            <p class="status-note"><strong><?= esc((string) ($demoCredentials['password'] ?? 'Demo2026')) ?></strong></p>
                            <div class="note-box">
                                <p>Quando un account o un alias mostra il badge OTP, usa sempre <strong><?= esc((string) ($demoCredentials['otp'] ?? '2510')) ?></strong>.</p>
                                <p>Gli account demo non toccano dati reali e servono solo per prove commerciali e operative.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </aside>
            </div>
        </section>

        <?php if (!empty($demoAccessFeedback) && is_array($demoAccessFeedback)): ?>
            <section class="panel">
                <div class="note-box" style="<?= !empty($demoAccessFeedback['ok']) ? '' : 'border-color:rgba(220,53,69,.2); color:#8f2130;' ?>">
                    <p><?= esc((string) ($demoAccessFeedback['message'] ?? '')) ?></p>
                </div>
            </section>
        <?php endif; ?>

        <section class="panel">
            <div class="panel-head">
                <p class="eyebrow">Checklist prova</p>
                <h2>Ordine consigliato per provare tutto</h2>
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

        <section class="panel panel-access" id="ruoli-demo">
            <div class="panel-head">
                <p class="eyebrow">Account disponibili</p>
                <h2>Ruoli utili per il test completo</h2>
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
                                    <?php if (empty($demoPublicAccessEnabled) && (string) ($account['otp'] ?? '') !== ''): ?>
                                        <span class="status-pill status-pill-warning">OTP <?= esc((string) $account['otp']) ?></span>
                                    <?php endif; ?>
                                </div>
                                <h3><?= esc((string) ($account['label'] ?? ($account['username'] ?? ''))) ?></h3>
                                <?php if (empty($demoPublicAccessEnabled)): ?>
                                    <p class="access-note"><?= esc((string) ($account['username'] ?? '')) ?></p>
                                <?php endif; ?>
                                <p class="access-note"><?= esc((string) ($account['note'] ?? '')) ?></p>
                                <?php if (empty($demoPublicAccessEnabled)): ?>
                                    <p class="access-secret">Password: <strong><?= esc((string) ($account['password'] ?? '')) ?></strong></p>
                                <?php endif; ?>
                                <?php if (!empty($account['scenarios']) && is_array($account['scenarios'])): ?>
                                    <div class="chip-row">
                                        <?php foreach ($account['scenarios'] as $scenario): ?>
                                            <span class="chip"><?= esc((string) $scenario) ?></span>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                                <div class="card-actions">
                                    <?php if (!empty($demoPublicAccessEnabled)): ?>
                                        <a class="btn btn-primary btn-inline" href="<?= esc((string) ($account['entry_url'] ?? ($demoCredentials['direct_access_url'] ?? site_url('access')))) ?>">Entra come questo ruolo</a>
                                    <?php else: ?>
                                        <a class="btn btn-secondary btn-inline" href="<?= esc((string) ($account['login_url'] ?? ($demoCredentials['login_url'] ?? site_url('login')))) ?>">Apri login precompilato</a>
                                    <?php endif; ?>
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
                    <h3>Switch ruolo</h3>
                    <p class="access-note">Durante la demo puoi cambiare vista da tenant master a utente normale, segreteria, dottore o paziente senza rifare il login.</p>
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

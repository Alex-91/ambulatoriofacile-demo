<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= esc($brandName) ?> | Accesso demo <?= esc((string) ($profile['label'] ?? '')) ?></title>
    <meta name="description" content="Accesso guidato alla demo <?= esc((string) ($profile['label'] ?? 'verticale')) ?>">
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
                    <a class="back-link" href="<?= site_url('demo/vertical/' . $profileSlug) ?>">Torna al percorso verticale</a>
                    <p class="eyebrow">Accesso demo guidato</p>
                    <h1><?= esc((string) ($profile['label'] ?? 'Verticale demo')) ?></h1>
                    <p class="hero-copy">
                        <?php if ($showLocalAccess): ?>
                            Scegli l account piu adatto alla conversazione commerciale e apri il login con username gia precompilato.
                        <?php else: ?>
                            Questa pagina accompagna il passaggio dalla presentazione del verticale alla demo operativa, mantenendo separati accessi interni e materiali pubblici.
                        <?php endif; ?>
                    </p>
                    <p class="hero-copy hero-copy-secondary">
                        <?php if ($showLocalAccess): ?>
                            La demo continua a girare su database e runtime separati. Fuori da localhost non mostriamo la password pubblicamente.
                        <?php else: ?>
                            Il login resta quello standard, ma gli accessi completi vengono gestiti solo negli ambienti interni o durante una sessione guidata.
                        <?php endif; ?>
                    </p>
                    <div class="hero-actions">
                        <a class="btn btn-primary" href="<?= site_url('login?demo=1&profile=' . $profileSlug) ?>">Apri login demo</a>
                        <a class="btn btn-secondary" href="<?= site_url('demo') ?>">Torna alla showcase</a>
                        <a class="btn btn-secondary" href="<?= site_url('demo/richiesta?profile=' . $profileSlug) ?>">Richiedi demo guidata</a>
                    </div>
                </div>

                <aside class="hero-side">
                    <div class="hero-side-card">
                        <?php if ($showTechnicalStatus): ?>
                            <p class="status-label">Dataset demo</p>
                            <h3><?= esc((string) ($seedStatus['database'] ?? 'dottorapp_demo')) ?></h3>
                            <p class="status-note">Ultimo seed: <?= esc((string) ($seedStatus['finished_at'] ?: 'non disponibile')) ?></p>
                        <?php else: ?>
                            <p class="status-label">Demo separata</p>
                            <h3>Percorso commerciale protetto</h3>
                            <p class="status-note">Nessun dato reale, nessun riferimento farmacia, materiali visibili calibrati per uso pubblico.</p>
                        <?php endif; ?>
                        <div class="note-box">
                            <p>Questa pagina serve solo alla linea demo/commerciale e non tocca la copia farmacia.</p>
                            <?php if ($showLocalAccess): ?>
                                <p>Seleziona un ruolo, apri il login precompilato e poi completa il flusso MFA solo quando serve mostrarlo.</p>
                            <?php else: ?>
                                <p>La parte pubblica racconta il percorso; gli accessi completi restano riservati alle prove interne o alle demo assistite.</p>
                            <?php endif; ?>
                        </div>
                    </div>
                </aside>
            </div>
        </section>

        <section class="panel panel-access">
            <div class="panel-head">
                <p class="eyebrow">Account consigliati</p>
                <h2><?= $showLocalAccess ? 'Ingressi utili per questo verticale' : 'Ruoli inclusi nel percorso demo' ?></h2>
            </div>
            <?php if ($profileAccounts === []): ?>
                <div class="note-box">
                    <p>Gli account demo di questo verticale non sono ancora pronti nel seed locale.</p>
                </div>
            <?php else: ?>
                <div class="access-grid access-grid-wide">
                    <?php foreach ($profileAccounts as $account): ?>
                        <article class="access-card">
                            <p class="status-label"><?= esc($account['role']) ?></p>
                            <h3><?= esc($showLocalAccess ? $account['username'] : $account['label']) ?></h3>
                            <?php if ($showLocalAccess): ?>
                                <p class="access-note"><?= esc($account['label']) ?></p>
                            <?php endif; ?>
                            <p class="access-note"><?= esc($account['note']) ?></p>

                            <?php if ($showLocalAccess): ?>
                                <p class="access-secret">Password: <strong><?= esc($account['password']) ?></strong></p>
                                <div class="card-actions">
                                    <a class="btn btn-secondary btn-inline" href="<?= esc($account['login_url']) ?>">Apri login precompilato</a>
                                </div>
                            <?php else: ?>
                                <p class="access-lockline">Accessi completi e credenziali condivisi solo in ambiente interno o durante demo guidata.</p>
                            <?php endif; ?>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

        <section class="panel">
            <div class="panel-head">
                <p class="eyebrow">Uso consigliato</p>
                <h2>Come condurre la demo in modo fluido</h2>
            </div>
            <div class="dual-grid">
                <article class="detail-card">
                    <h3>Ordine suggerito</h3>
                    <ul class="detail-list">
                        <li>Apri un account front office o admin per il primo giro di prodotto.</li>
                        <li>Passa al ruolo portale o OTP solo quando vuoi mostrare l accesso sicuro al cliente.</li>
                        <li>Chiudi la presentazione tornando su moduli, agenda e coordinamento team.</li>
                    </ul>
                </article>
                <article class="detail-card">
                    <h3>Promemoria operativo</h3>
                    <ul class="detail-list">
                        <li>Il login resta quello standard: qui aggiungiamo solo un percorso guidato e precompilato.</li>
                        <li>Gli account demo sono separati dai dati farmacia e pronti per bugfix condivisi sul ramo commerciale.</li>
                        <li>Se devi mostrare MFA con account dedicato, usa il passaggio OTP nel flusso successivo.</li>
                    </ul>
                </article>
            </div>
        </section>

        <?php if ($showLocalAccess): ?>
            <section class="panel">
                <div class="panel-head">
                    <p class="eyebrow">Note locali</p>
                    <h2>Informazioni interne per la demo assistita</h2>
                </div>
                <div class="dual-grid">
                    <article class="detail-card">
                        <h3>OTP demo fisso</h3>
                        <p class="access-note">Per l account <strong>alessio2</strong> e per l impersonation rapida puoi raccontare il passaggio MFA usando OTP fisso <strong>2510</strong>.</p>
                    </article>
                    <article class="detail-card">
                        <h3>Sicurezza del setup</h3>
                        <p class="access-note">Questa sezione compare su localhost per aiutare le prove interne e non cambia il flusso operativo della versione farmacia.</p>
                    </article>
                </div>
            </section>
        <?php endif; ?>
    </main>
</div>
</body>
</html>

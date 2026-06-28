<?php
$publicAccessEnabled = !empty($demoPublicAccessEnabled);
$accountGroups = (array) ($demoAccountGroups ?? []);
$checklistItems = (array) ($demoChecklist ?? []);
$accountsByUsername = [];
$totalAccounts = 0;

foreach ($accountGroups as $group) {
    foreach ((array) ($group['accounts'] ?? []) as $account) {
        $username = trim((string) ($account['username'] ?? ''));
        if ($username !== '') {
            $accountsByUsername[$username] = $account;
        }
        $totalAccounts++;
    }
}

$slugify = static function (string $value): string {
    $value = strtolower(trim($value));
    $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';
    $value = trim($value, '-');

    return $value !== '' ? $value : 'sezione-demo';
};

$resolveGroupTone = static function (array $group): string {
    $rawKey = strtolower(trim((string) ($group['key'] ?? $group['group_key'] ?? $group['title'] ?? '')));

    if (str_contains($rawKey, 'sport')) {
        return 'sport';
    }

    if (str_contains($rawKey, 'studio') || str_contains($rawKey, 'tenant')) {
        return 'studio';
    }

    return 'core';
};

$groupToneMeta = [
    'studio' => [
        'badge' => 'Accesso immediato',
        'summary' => 'Nuova area studio senza login iniziale.',
    ],
    'core' => [
        'badge' => 'Ruoli operativi',
        'summary' => 'Agenda, posta, chat e vista prodotto.',
    ],
    'sport' => [
        'badge' => 'Scenario verticale',
        'summary' => 'Secondo contesto demo gia popolato.',
    ],
];

$quickJourneyDefinitions = [
    [
        'username' => 'demo.tenant.master',
        'eyebrow' => 'Percorso studio',
        'title' => 'Mostra struttura e funzioni',
        'summary' => 'Entri nel ruolo piu forte per aprire lo studio demo, funzioni attive e gestione utenti.',
        'duration' => '2 min',
        'scenarios' => ['funzioni studio', 'gestione utenti', 'responsabile studio'],
        'cta' => 'Apri spazio studio',
        'tone' => 'studio',
    ],
    [
        'username' => 'demo.tenant.agenda',
        'eyebrow' => 'Effetto wow',
        'title' => 'Apri subito l agenda team',
        'summary' => 'Porta il cliente direttamente nella vista Giorno Team con tre professionisti gia visibili.',
        'duration' => '1 min',
        'scenarios' => ['agenda condivisa', '3 professionisti', 'giorno team'],
        'cta' => 'Apri agenda condivisa',
        'tone' => 'core',
    ],
    [
        'username' => 'demo.segreteria',
        'eyebrow' => 'Uso quotidiano',
        'title' => 'Fai vedere il lavoro operativo',
        'summary' => 'Segreteria, conferme, spostamenti, posta interna e attivita pratiche da front desk.',
        'duration' => '2 min',
        'scenarios' => ['agenda segreteria', 'chat segreteria', 'presa appuntamenti'],
        'cta' => 'Entra come segreteria',
        'tone' => 'core',
    ],
    [
        'username' => 'demo.portal.nutri',
        'eyebrow' => 'Chiusura demo',
        'title' => 'Chiudi dal lato paziente',
        'summary' => 'Mostra l esperienza esterna del cliente finale con area personale e messaggi.',
        'duration' => '1 min',
        'scenarios' => ['portale paziente', 'posta paziente', 'area utente'],
        'cta' => 'Apri area paziente',
        'tone' => 'sport',
    ],
];

$quickJourneys = [];
foreach ($quickJourneyDefinitions as $journeyDefinition) {
    $account = $accountsByUsername[$journeyDefinition['username']] ?? null;
    if (!is_array($account)) {
        continue;
    }

    $actionUrl = $publicAccessEnabled
        ? (string) ($account['entry_url'] ?? ($demoCredentials['direct_access_url'] ?? site_url('access')))
        : (string) ($account['login_url'] ?? ($demoCredentials['login_url'] ?? site_url('login')));

    $quickJourneys[] = array_merge($journeyDefinition, [
        'account' => $account,
        'action_url' => $actionUrl,
        'action_label' => $publicAccessEnabled ? (string) $journeyDefinition['cta'] : 'Apri login rapido',
    ]);
}

$catalogGroups = [];
foreach ($accountGroups as $group) {
    $tone = $resolveGroupTone($group);
    $title = (string) ($group['title'] ?? 'Ruoli demo');
    $catalogGroups[] = [
        'anchor_id' => $slugify($title),
        'title' => $title,
        'note' => (string) ($group['note'] ?? ''),
        'accounts' => (array) ($group['accounts'] ?? []),
        'tone' => $tone,
        'meta' => $groupToneMeta[$tone] ?? $groupToneMeta['core'],
    ];
}
?>
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
            <div class="hero-stack hero-stack-enhanced">
                <div class="hero-main">
                    <p class="eyebrow">Accesso demo pubblico</p>
                    <h1>Scegli come far entrare il cliente</h1>
                    <p class="hero-copy">
                        La demo parte da qui: scegli l obiettivo che vuoi mostrare, entri subito nel ruolo giusto e puoi cambiare vista in pochi secondi.
                    </p>
                    <p class="hero-copy hero-copy-secondary">
                        <?= $publicAccessEnabled
                            ? 'Nessun login iniziale per i ruoli studio. Tutto gira su dati demo separati dalla produzione.'
                            : 'Accesso demo rapido con credenziali condivise e ruoli gia pronti per la prova.' ?>
                    </p>
                    <div class="hero-actions">
                        <a class="btn btn-primary" href="#percorsi-rapidi">Avvia percorso rapido</a>
                        <a class="btn btn-secondary" href="#catalogo-ruoli">Vedi tutti i ruoli</a>
                    </div>
                </div>

                <aside class="hero-side">
                    <div class="hero-side-card hero-dashboard">
                        <p class="status-label"><?= $publicAccessEnabled ? 'Ingresso in 1 click' : 'Credenziali rapide' ?></p>
                        <h3>Pannello demo pronto per la presentazione</h3>
                        <div class="hero-mini-grid">
                            <article class="mini-stat">
                                <strong><?= esc((string) count($quickJourneys)) ?></strong>
                                <span>Percorsi rapidi</span>
                            </article>
                            <article class="mini-stat">
                                <strong><?= esc((string) $totalAccounts) ?></strong>
                                <span>Ruoli disponibili</span>
                            </article>
                            <article class="mini-stat">
                                <strong><?= esc((string) count($checklistItems)) ?></strong>
                                <span>Tappe consigliate</span>
                            </article>
                        </div>

                        <?php if ($publicAccessEnabled): ?>
                            <div class="note-box">
                                <p>Per iniziare bene apri prima <strong>Responsabile studio</strong> o <strong>Agenda condivisa</strong>.</p>
                                <p>Da li fai capire subito struttura, team e valore operativo.</p>
                            </div>
                        <?php else: ?>
                            <div class="note-box">
                                <p>Password demo unica: <strong><?= esc((string) ($demoCredentials['password'] ?? 'Demo2026')) ?></strong></p>
                                <p>Se compare OTP usa <strong><?= esc((string) ($demoCredentials['otp'] ?? '2510')) ?></strong>.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </aside>
            </div>
        </section>

        <?php if (!empty($demoAccessFeedback) && is_array($demoAccessFeedback)): ?>
            <section class="panel">
                <div class="feedback-banner <?= !empty($demoAccessFeedback['ok']) ? 'feedback-banner-success' : 'feedback-banner-error' ?>">
                    <p><?= esc((string) ($demoAccessFeedback['message'] ?? '')) ?></p>
                </div>
            </section>
        <?php endif; ?>

        <?php if ($quickJourneys !== []): ?>
            <section class="panel" id="percorsi-rapidi">
                <div class="panel-head panel-head-split">
                    <div>
                        <p class="eyebrow">Percorsi rapidi</p>
                        <h2>Apri il punto giusto in meno di un minuto</h2>
                    </div>
                    <p class="section-intro">Le quattro entrate qui sotto coprono quasi tutte le demo commerciali senza dover cercare il ruolo giusto.</p>
                </div>

                <div class="quick-grid">
                    <?php foreach ($quickJourneys as $journey): ?>
                        <article class="quick-card quick-card-<?= esc((string) ($journey['tone'] ?? 'core')) ?>">
                            <div class="quick-card-topline">
                                <span class="quick-badge"><?= esc((string) ($journey['eyebrow'] ?? 'Percorso demo')) ?></span>
                                <span class="status-pill status-pill-neutral"><?= esc((string) ($journey['duration'] ?? 'Subito')) ?></span>
                            </div>
                            <h3><?= esc((string) ($journey['title'] ?? 'Apri la demo')) ?></h3>
                            <p class="access-note quick-summary"><?= esc((string) ($journey['summary'] ?? '')) ?></p>
                            <p class="quick-target">
                                Entri come <strong><?= esc((string) (($journey['account']['label'] ?? ($journey['account']['username'] ?? '')))) ?></strong>
                            </p>
                            <?php if (!empty($journey['scenarios']) && is_array($journey['scenarios'])): ?>
                                <div class="chip-row">
                                    <?php foreach ($journey['scenarios'] as $scenario): ?>
                                        <span class="chip"><?= esc((string) $scenario) ?></span>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                            <div class="card-actions card-actions-spread">
                                <span class="quick-helper"><?= $publicAccessEnabled ? 'Accesso diretto' : 'Login demo precompilato' ?></span>
                                <a class="btn btn-primary btn-inline" href="<?= esc((string) ($journey['action_url'] ?? '#')) ?>"><?= esc((string) ($journey['action_label'] ?? 'Apri')) ?></a>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endif; ?>

        <?php if ($catalogGroups !== []): ?>
            <section class="panel">
                <div class="panel-head panel-head-split">
                    <div>
                        <p class="eyebrow">Catalogo demo</p>
                        <h2>Vai subito nella famiglia di ruoli giusta</h2>
                    </div>
                    <p class="section-intro">Se vuoi saltare al blocco giusto, usa questi filtri veloci invece di scorrere tutta la pagina.</p>
                </div>

                <nav class="section-nav" aria-label="Sezioni ruoli demo">
                    <?php foreach ($catalogGroups as $group): ?>
                        <a class="section-link section-link-<?= esc((string) ($group['tone'] ?? 'core')) ?>" href="#<?= esc((string) ($group['anchor_id'] ?? 'catalogo-ruoli')) ?>">
                            <span><?= esc((string) ($group['title'] ?? 'Ruoli demo')) ?></span>
                            <strong><?= esc((string) count((array) ($group['accounts'] ?? []))) ?></strong>
                        </a>
                    <?php endforeach; ?>
                </nav>
            </section>
        <?php endif; ?>

        <?php if ($checklistItems !== []): ?>
            <section class="panel">
                <div class="panel-head panel-head-split">
                    <div>
                        <p class="eyebrow">Roadmap demo</p>
                        <h2>Ordine consigliato se vuoi far vedere tutto</h2>
                    </div>
                    <p class="section-intro">Una traccia semplice da seguire quando vuoi fare una presentazione completa senza perdere ritmo.</p>
                </div>

                <div class="timeline-grid">
                    <?php foreach ($checklistItems as $index => $item): ?>
                        <?php $checklistLoginLabel = (string) ($item['display_username'] ?? ($item['username'] ?? '')); ?>
                        <article class="timeline-card">
                            <div class="timeline-index"><?= esc((string) ($index + 1)) ?></div>
                            <div class="timeline-body">
                                <h3><?= esc((string) ($item['title'] ?? 'Passo demo')) ?></h3>
                                <p class="access-note">Apri con <strong><?= esc($checklistLoginLabel) ?></strong></p>
                                <p class="access-note"><?= esc((string) ($item['goal'] ?? '')) ?></p>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endif; ?>

        <section class="panel panel-access" id="catalogo-ruoli">
            <div class="panel-head panel-head-split">
                <div>
                    <p class="eyebrow">Ruoli disponibili</p>
                    <h2>Catalogo completo degli ingressi demo</h2>
                </div>
                <p class="section-intro">Ogni card porta direttamente dentro la demo nel ruolo scelto. La produzione e il login reale non vengono toccati.</p>
            </div>

            <?php foreach ($catalogGroups as $group): ?>
                <section class="account-section account-section-<?= esc((string) ($group['tone'] ?? 'core')) ?>" id="<?= esc((string) ($group['anchor_id'] ?? 'catalogo-ruoli')) ?>">
                    <div class="account-section-head account-section-head-wide">
                        <div>
                            <p class="status-label"><?= esc((string) ($group['title'] ?? 'Ruoli demo')) ?></p>
                            <p class="access-note"><?= esc((string) ($group['note'] ?? '')) ?></p>
                        </div>
                        <div class="account-section-meta">
                            <span class="status-pill status-pill-neutral"><?= esc((string) (($group['meta']['badge'] ?? 'Ruoli demo'))) ?></span>
                            <span class="account-count"><?= esc((string) count((array) ($group['accounts'] ?? []))) ?> ruoli</span>
                        </div>
                    </div>

                    <div class="access-grid access-grid-wide access-grid-catalog">
                        <?php foreach ((array) ($group['accounts'] ?? []) as $account): ?>
                            <?php
                            $actionUrl = $publicAccessEnabled
                                ? (string) ($account['entry_url'] ?? ($demoCredentials['direct_access_url'] ?? site_url('access')))
                                : (string) ($account['login_url'] ?? ($demoCredentials['login_url'] ?? site_url('login')));
                            $actionLabel = $publicAccessEnabled ? 'Entra subito' : 'Apri login rapido';
                            ?>
                            <article class="access-card access-card-compact">
                                <div class="access-card-topline">
                                    <p class="status-label"><?= esc((string) ($account['role'] ?? 'Account demo')) ?></p>
                                    <?php if ($publicAccessEnabled): ?>
                                        <span class="status-pill status-pill-success">Accesso diretto</span>
                                    <?php elseif ((string) ($account['otp'] ?? '') !== ''): ?>
                                        <span class="status-pill status-pill-warning">OTP <?= esc((string) $account['otp']) ?></span>
                                    <?php else: ?>
                                        <span class="status-pill status-pill-neutral">Login demo</span>
                                    <?php endif; ?>
                                </div>

                                <h3><?= esc((string) ($account['label'] ?? ($account['username'] ?? ''))) ?></h3>

                                <p class="access-note access-note-emphasis">
                                    <?= $publicAccessEnabled
                                        ? 'Uso consigliato: accesso immediato da vetrina demo.'
                                        : esc((string) ($account['username'] ?? '')) ?>
                                </p>

                                <p class="access-note"><?= esc((string) ($account['note'] ?? '')) ?></p>

                                <?php if (!$publicAccessEnabled): ?>
                                    <p class="access-secret">Password: <strong><?= esc((string) ($account['password'] ?? '')) ?></strong></p>
                                <?php endif; ?>

                                <?php if (!empty($account['scenarios']) && is_array($account['scenarios'])): ?>
                                    <div class="chip-row">
                                        <?php foreach ($account['scenarios'] as $scenario): ?>
                                            <span class="chip"><?= esc((string) $scenario) ?></span>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>

                                <div class="card-actions card-actions-spread">
                                    <span class="quick-helper"><?= esc((string) (($group['meta']['summary'] ?? 'Ruolo demo pronto.'))) ?></span>
                                    <a class="btn <?= $publicAccessEnabled ? 'btn-primary' : 'btn-secondary' ?> btn-inline" href="<?= esc($actionUrl) ?>"><?= esc($actionLabel) ?></a>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endforeach; ?>
        </section>

        <section class="panel">
            <div class="panel-head panel-head-split">
                <div>
                    <p class="eyebrow">Promemoria operativo</p>
                    <h2>Cosa conviene ricordare durante la prova</h2>
                </div>
                <p class="section-intro">Tre messaggi semplici da usare mentre presenti la piattaforma al cliente.</p>
            </div>

            <div class="dual-grid">
                <article class="detail-card detail-card-highlight">
                    <h3>Switch ruolo</h3>
                    <p class="access-note">Puoi cambiare prospettiva senza rifare il login e senza uscire dal flusso demo.</p>
                </article>
                <article class="detail-card detail-card-highlight">
                    <h3>Dati separati</h3>
                    <p class="access-note">Tutto quello che si prova qui resta nella base demo e non tocca la produzione reale.</p>
                </article>
                <article class="detail-card detail-card-highlight">
                    <h3>Percorso rapido</h3>
                    <p class="access-note">Se hai poco tempo, usa Responsabile studio, Agenda condivisa e Paziente: coprono quasi tutta la demo.</p>
                </article>
            </div>
        </section>
    </main>
</div>
</body>
</html>

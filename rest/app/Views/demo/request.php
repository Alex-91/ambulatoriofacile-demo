<!DOCTYPE html>
<?php
$requestFeedback = $requestFeedback ?? null;
$requestErrors = is_array($requestErrors ?? null) ? $requestErrors : [];
$requestOld = is_array($requestOld ?? null) ? $requestOld : [];
$preferredSlot = (string) ($requestOld['preferred_slot'] ?? 'flessibile');
$demoRequestContext = is_array($demoRequestContext ?? null) ? $demoRequestContext : [];
?>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= esc($brandName) ?> | Richiedi demo guidata</title>
    <meta name="description" content="Richiedi una demo guidata di AmbulatorioFacile">
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
                    <p class="eyebrow">Richiedi demo guidata</p>
                    <h1><?= esc((string) ($demoRequestContext['label'] ?? 'Demo AmbulatorioFacile')) ?></h1>
                    <p class="hero-copy">
                        Compila i dati e registra una richiesta demo sul percorso unico del prodotto, senza distinzione tra verticali diversi.
                    </p>
                    <p class="hero-copy hero-copy-secondary">
                        Il lead resta nella linea demo/commerciale e non tocca dati o database della produzione reale.
                    </p>
                    <div class="hero-actions">
                        <a class="btn btn-primary" href="<?= site_url('demo/access') ?>">Apri account demo</a>
                        <a class="btn btn-secondary" href="<?= esc(site_url('login')) ?>">Vai al login ufficiale</a>
                        <?php if ($showLocalAccess): ?>
                            <a class="btn btn-secondary" href="<?= site_url('demo/richieste-locali') ?>">Apri archivio lead</a>
                        <?php endif; ?>
                    </div>
                </div>

                <aside class="hero-side">
                    <div class="hero-side-card">
                        <p class="status-label">Cosa raccogliamo</p>
                        <ul class="detail-list">
                            <li>Contatto referente e struttura</li>
                            <li>Dimensione team e fascia preferita</li>
                            <li>Note utili per preparare una prova mirata</li>
                        </ul>
                        <div class="note-box">
                            <p>La richiesta viene salvata nella copia commerciale separata dalla produzione.</p>
                            <?php if ($showLocalAccess && is_array($requestFeedback) && !empty($requestFeedback['storage_label'])): ?>
                                <p>Ultimo salvataggio locale: <?= esc((string) $requestFeedback['storage_label']) ?></p>
                            <?php endif; ?>
                        </div>
                    </div>
                </aside>
            </div>
        </section>

        <?php if (is_array($requestFeedback) && !empty($requestFeedback['message'])): ?>
            <section class="panel">
                <div class="feedback-banner <?= !empty($requestFeedback['ok']) ? 'feedback-banner-success' : 'feedback-banner-error' ?>">
                    <p class="status-label"><?= !empty($requestFeedback['ok']) ? 'Richiesta registrata' : 'Controllo campi' ?></p>
                    <h3><?= esc((string) $requestFeedback['message']) ?></h3>
                    <?php if (!empty($requestFeedback['request_id'])): ?>
                        <p class="access-note">ID richiesta: <strong><?= esc((string) $requestFeedback['request_id']) ?></strong></p>
                    <?php endif; ?>
                    <?php if ($showLocalAccess && !empty($requestFeedback['notification_note'])): ?>
                        <p class="access-note"><?= esc((string) $requestFeedback['notification_note']) ?></p>
                    <?php endif; ?>
                </div>
            </section>
        <?php endif; ?>

        <section class="panel">
            <div class="panel-head">
                <p class="eyebrow">Modulo contatto</p>
                <h2>Prepariamo una demo guidata piu precisa</h2>
            </div>

            <form method="post" action="<?= site_url('demo/richiesta/invia') ?>" class="demo-form-card">
                <?php if (function_exists('csrf_field')): ?>
                    <?= csrf_field() ?>
                <?php endif; ?>

                <input type="hidden" name="vertical" value="<?= esc((string) ($demoRequestContext['id'] ?? 'ambulatoriofacile_demo')) ?>">

                <div class="honeypot-field" aria-hidden="true">
                    <label for="website">Sito web</label>
                    <input type="text" id="website" name="website" tabindex="-1" autocomplete="off" value="">
                </div>

                <div class="form-grid">
                    <div class="form-field">
                        <label for="full_name">Nome e cognome</label>
                        <input type="text" id="full_name" name="full_name" value="<?= esc((string) ($requestOld['full_name'] ?? '')) ?>" required>
                        <?php if (!empty($requestErrors['full_name'])): ?><p class="field-error"><?= esc((string) $requestErrors['full_name']) ?></p><?php endif; ?>
                    </div>

                    <div class="form-field">
                        <label for="business_name">Struttura o attivita</label>
                        <input type="text" id="business_name" name="business_name" value="<?= esc((string) ($requestOld['business_name'] ?? '')) ?>" required>
                        <?php if (!empty($requestErrors['business_name'])): ?><p class="field-error"><?= esc((string) $requestErrors['business_name']) ?></p><?php endif; ?>
                    </div>

                    <div class="form-field">
                        <label for="email">Email</label>
                        <input type="email" id="email" name="email" value="<?= esc((string) ($requestOld['email'] ?? '')) ?>" required>
                        <?php if (!empty($requestErrors['email'])): ?><p class="field-error"><?= esc((string) $requestErrors['email']) ?></p><?php endif; ?>
                    </div>

                    <div class="form-field">
                        <label for="phone">Telefono</label>
                        <input type="text" id="phone" name="phone" value="<?= esc((string) ($requestOld['phone'] ?? '')) ?>" placeholder="+39 333 1234567">
                        <?php if (!empty($requestErrors['phone'])): ?><p class="field-error"><?= esc((string) $requestErrors['phone']) ?></p><?php endif; ?>
                    </div>

                    <div class="form-field">
                        <label for="contact_role">Ruolo del referente</label>
                        <input type="text" id="contact_role" name="contact_role" value="<?= esc((string) ($requestOld['contact_role'] ?? '')) ?>" placeholder="titolare, coordinatore, direzione">
                        <?php if (!empty($requestErrors['contact_role'])): ?><p class="field-error"><?= esc((string) $requestErrors['contact_role']) ?></p><?php endif; ?>
                    </div>

                    <div class="form-field">
                        <label for="team_size">Dimensione team</label>
                        <input type="text" id="team_size" name="team_size" value="<?= esc((string) ($requestOld['team_size'] ?? '')) ?>" placeholder="es. 4 operatori, 2 sedi">
                        <?php if (!empty($requestErrors['team_size'])): ?><p class="field-error"><?= esc((string) $requestErrors['team_size']) ?></p><?php endif; ?>
                    </div>

                    <div class="form-field">
                        <label for="preferred_slot">Fascia preferita</label>
                        <select id="preferred_slot" name="preferred_slot">
                            <option value="flessibile" <?= $preferredSlot === 'flessibile' ? 'selected' : '' ?>>Flessibile</option>
                            <option value="mattina" <?= $preferredSlot === 'mattina' ? 'selected' : '' ?>>Mattina</option>
                            <option value="pomeriggio" <?= $preferredSlot === 'pomeriggio' ? 'selected' : '' ?>>Pomeriggio</option>
                            <option value="sera" <?= $preferredSlot === 'sera' ? 'selected' : '' ?>>Sera</option>
                        </select>
                        <?php if (!empty($requestErrors['preferred_slot'])): ?><p class="field-error"><?= esc((string) $requestErrors['preferred_slot']) ?></p><?php endif; ?>
                    </div>

                    <div class="form-field form-field-full">
                        <label for="notes">Cosa vuoi vedere in demo</label>
                        <textarea id="notes" name="notes" rows="6" placeholder="Agenda, reminder, notifiche appuntamenti, ruoli, OTP, comunicazione interna, portale paziente..."><?= esc((string) ($requestOld['notes'] ?? '')) ?></textarea>
                        <?php if (!empty($requestErrors['notes'])): ?><p class="field-error"><?= esc((string) $requestErrors['notes']) ?></p><?php endif; ?>
                    </div>
                </div>

                <div class="consent-row">
                    <label class="consent-check">
                        <input type="checkbox" name="privacy" value="1" <?= ((string) ($requestOld['privacy'] ?? '')) === '1' ? 'checked' : '' ?>>
                        <span>Confermo il consenso al trattamento dei dati per essere ricontattato in merito alla demo richiesta.</span>
                    </label>
                    <?php if (!empty($requestErrors['privacy'])): ?><p class="field-error"><?= esc((string) $requestErrors['privacy']) ?></p><?php endif; ?>
                </div>

                <div class="hero-actions">
                    <button type="submit" class="btn btn-primary btn-button">Registra richiesta demo</button>
                    <a class="btn btn-secondary" href="<?= site_url('demo') ?>">Torna alla panoramica</a>
                </div>
            </form>
        </section>
    </main>
</div>
</body>
</html>

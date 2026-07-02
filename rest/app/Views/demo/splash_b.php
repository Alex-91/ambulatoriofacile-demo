<?php
/**
 * Splash demo — Variante B (card affiancate). Resa da demo/access.php.
 * Variabili: $roleCards, $siteUrl, $prenotaUrl, $whatsappUrl, $logoUrl, $iconSvg, $appleIcon, $ogImage, $canonical.
 */
$icon = static function (string $name): string {
    switch ($name) {
        case 'sliders':
            return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="21" x2="14" y1="4" y2="4"></line><line x1="10" x2="3" y1="4" y2="4"></line><line x1="21" x2="12" y1="12" y2="12"></line><line x1="8" x2="3" y1="12" y2="12"></line><line x1="21" x2="16" y1="20" y2="20"></line><line x1="12" x2="3" y1="20" y2="20"></line><line x1="14" x2="14" y1="2" y2="6"></line><line x1="8" x2="8" y1="10" y2="14"></line><line x1="16" x2="16" y1="18" y2="22"></line></svg>';
        case 'calendar':
            return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M8 2v4"></path><path d="M16 2v4"></path><rect width="18" height="18" x="3" y="4" rx="2"></rect><path d="M3 10h18"></path><path d="M8 14h.01"></path><path d="M12 14h.01"></path><path d="M16 14h.01"></path><path d="M8 18h.01"></path><path d="M12 18h.01"></path><path d="M16 18h.01"></path></svg>';
        case 'user':
            return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="8" r="5"></circle><path d="M20 21a8 8 0 0 0-16 0"></path></svg>';
    }

    return '';
};
$checkSvg = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6 9 17l-5-5"></path></svg>';
$waSvg = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M7.9 20A9 9 0 1 0 4 16.1L2 22Z"></path></svg>';
$backSvg = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m12 19-7-7 7-7"></path><path d="M19 12H5"></path></svg>';
?>
<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Demo di Ambulatorio Facile: provala dal tuo ruolo</title>
<meta name="description" content="Prova gratis la demo di Ambulatorio Facile senza registrazione. Entra come responsabile dello studio, segreteria o professionista e scopri agenda, prenotazioni e gestione del personale in un clic.">
<link rel="canonical" href="<?= esc($canonical, 'attr') ?>">
<meta name="robots" content="index, follow">
<meta property="og:title" content="Demo di Ambulatorio Facile: provala dal tuo ruolo">
<meta property="og:description" content="Prova gratis la demo di Ambulatorio Facile senza registrazione. Entra come responsabile dello studio, segreteria o professionista e scopri agenda, prenotazioni e gestione del personale in un clic.">
<meta property="og:type" content="website">
<meta property="og:locale" content="it_IT">
<meta property="og:url" content="<?= esc($canonical, 'attr') ?>">
<meta property="og:image" content="<?= esc($ogImage, 'attr') ?>">
<meta name="twitter:card" content="summary_large_image">
<meta name="theme-color" content="#00795f">
<link rel="icon" type="image/svg+xml" href="<?= esc($iconSvg, 'attr') ?>">
<link rel="apple-touch-icon" sizes="180x180" href="<?= esc($appleIcon, 'attr') ?>">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
  :root{
    --primary:#08364F; --accent:#00ac8d; --cta:#00795f; --cta-hover:#006a51;
    --bg:#f6f8fa; --white:#fff; --text:#1a1a2e; --text-light:#475569; --border:#e2e8f0;
    --memo-line:#0067a1; --agenda-line:#9a6a00; --assistenza-line:#6d5bb8;
  }
  *{box-sizing:border-box}
  html,body{margin:0}
  body{font-family:'Inter',system-ui,-apple-system,sans-serif;background:var(--bg);color:var(--text);-webkit-font-smoothing:antialiased;line-height:1.5;min-height:100vh;display:flex;flex-direction:column;padding-bottom:88px}
  a{color:inherit;text-decoration:none}
  svg{display:block}
  a:focus-visible,button:focus-visible{outline:2px solid var(--cta);outline-offset:2px;border-radius:4px}
  .topbar{display:flex;align-items:center;justify-content:space-between;gap:12px;height:60px;padding:0 18px;background:var(--white);border-bottom:1px solid var(--border);position:sticky;top:0;z-index:10}
  .logo{display:inline-flex;align-items:center}
  .logo img{height:27px;width:auto}
  .topbar-actions{display:none;align-items:center;gap:10px}
  .topbar-back{display:inline-flex;align-items:center;gap:6px;font-weight:600;font-size:13px;color:var(--primary)}
  .topbar-back svg{width:15px;height:15px}
  .btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;font-weight:600;text-decoration:none;border:0;cursor:pointer;transition:background .15s,border-color .15s}
  .btn-primary{background:var(--cta);color:#fff;border-radius:10px;height:42px;padding:0 18px;font-size:14px}
  .btn-primary:hover{background:var(--cta-hover)}
  .btn-ghost{background:var(--white);color:var(--primary);border:1px solid var(--border);border-radius:10px;height:42px;padding:0 15px;font-size:14px}
  .btn-ghost:hover{background:var(--bg);border-color:#cbd5e1}
  .btn-ghost svg{width:18px;height:18px}
  main{flex:1;width:100%;max-width:1080px;margin:0 auto;padding:40px 18px 8px;display:flex;flex-direction:column;align-items:center}
  h1{margin:0;max-width:660px;text-align:center;font-size:28px;line-height:1.15;font-weight:700;letter-spacing:-.02em;color:var(--primary)}
  .subtitle{margin:13px 0 0;max-width:560px;text-align:center;font-size:16px;color:var(--text-light)}
  .roles{list-style:none;margin:30px 0 0;padding:0;display:grid;grid-template-columns:1fr;gap:16px;width:100%}
  .role-card{display:flex;flex-direction:column;background:var(--white);border:1px solid var(--border);border-radius:16px;padding:22px;box-shadow:0 1px 2px rgba(8,54,79,.04);transition:border-color .15s,box-shadow .15s}
  .role-card:hover{border-color:var(--cta);box-shadow:0 6px 20px rgba(8,54,79,.08)}
  .role-head{display:flex;align-items:center;gap:13px}
  .chip{flex:none;width:46px;height:46px;border-radius:13px;display:inline-flex;align-items:center;justify-content:center}
  .chip svg{width:22px;height:22px}
  .chip-memo{background:rgba(0,103,161,.08);border:1px solid rgba(0,103,161,.22);color:var(--memo-line)}
  .chip-agenda{background:rgba(235,155,0,.12);border:1px solid rgba(235,155,0,.30);color:var(--agenda-line)}
  .chip-assistenza{background:rgba(130,110,210,.10);border:1px solid rgba(130,110,210,.26);color:var(--assistenza-line)}
  .eyebrow{font-weight:600;font-size:11px;line-height:1.3;letter-spacing:.08em;text-transform:uppercase}
  .eyebrow-memo{color:var(--memo-line)} .eyebrow-agenda{color:var(--agenda-line)} .eyebrow-assistenza{color:var(--assistenza-line)}
  .role-title{margin:16px 0 0;font-size:20px;line-height:1.25;font-weight:600;color:var(--primary)}
  .role-desc{margin:8px 0 0;font-size:14.5px;color:var(--text-light)}
  .divider{height:1px;background:#eef1f5;margin:16px 0}
  .points{list-style:none;margin:0 0 20px;padding:0;display:flex;flex-direction:column;gap:9px}
  .points li{display:flex;align-items:flex-start;gap:9px;font-size:13px;font-weight:500;color:var(--text-light)}
  .points svg{flex:none;width:16px;height:16px;margin-top:1px;color:var(--cta)}
  .btn-enter{margin-top:auto;min-height:48px;border-radius:12px;font-size:15px;background:var(--cta);color:#fff}
  .btn-enter:hover{background:var(--cta-hover)}
  .reassure{display:flex;flex-wrap:wrap;align-items:center;justify-content:center;gap:10px 22px;margin:26px 0 8px}
  .reassure span{display:inline-flex;align-items:center;gap:7px;font-size:13px;font-weight:500;color:var(--text-light)}
  .reassure svg{flex:none;width:15px;height:15px;color:var(--cta)}
  .sticky-bar{position:fixed;left:0;right:0;bottom:0;z-index:20;display:flex;gap:10px;padding:12px 16px;background:rgba(246,248,250,.93);backdrop-filter:blur(8px);-webkit-backdrop-filter:blur(8px);border-top:1px solid var(--border)}
  .sticky-bar .btn-primary{flex:1;height:48px;font-size:15px}
  .sticky-bar .wa{flex:none;width:48px;height:48px;border-radius:12px;padding:0}
  .sticky-bar .wa svg{width:20px;height:20px}
  @media (min-width:768px){
    h1{font-size:38px}
    .subtitle{font-size:18px;margin-top:14px}
    main{padding:52px 24px 8px}
    .roles{grid-template-columns:repeat(2,1fr);gap:20px}
    .role-card{padding:24px}
  }
  @media (min-width:1024px){
    body{padding-bottom:0}
    .topbar{height:64px;padding:0 32px}
    .topbar-actions{display:flex}
    .topbar-back{display:none}
    .roles{grid-template-columns:repeat(3,1fr);gap:22px}
    .sticky-bar{display:none}
  }
  @media (prefers-reduced-motion:reduce){*{transition:none!important}}
</style>
</head>
<body>

<header class="topbar">
  <a class="logo" href="<?= esc($siteUrl, 'attr') ?>" aria-label="Ambulatorio Facile, vai al sito">
    <img src="<?= esc($logoUrl, 'attr') ?>" alt="Ambulatorio Facile">
  </a>
  <nav class="topbar-actions" aria-label="Azioni">
    <a class="btn btn-ghost" href="<?= esc($whatsappUrl, 'attr') ?>" target="_blank" rel="noopener nofollow" aria-label="Scrivici su WhatsApp">
      <?= $waSvg ?>
      WhatsApp
    </a>
    <a class="btn btn-primary" href="<?= esc($prenotaUrl, 'attr') ?>">Prenota una dimostrazione</a>
  </nav>
  <a class="topbar-back" href="<?= esc($siteUrl, 'attr') ?>">
    <?= $backSvg ?>
    Torna al sito
  </a>
</header>

<main>
  <h1>La demo è pronta: entra dal tuo ruolo.</h1>
  <p class="subtitle">Scegli il ruolo più vicino al tuo lavoro e prova tutto dal vivo.</p>

  <ul class="roles">
    <?php foreach ($roleCards as $card): ?>
    <li class="role-card">
      <div class="role-head">
        <span class="chip chip-<?= esc($card['chip'], 'attr') ?>"><?= $icon($card['icon']) ?></span>
        <span class="eyebrow eyebrow-<?= esc($card['chip'], 'attr') ?>"><?= esc($card['eyebrow']) ?></span>
      </div>
      <h2 class="role-title"><?= esc($card['title']) ?></h2>
      <p class="role-desc"><?= esc($card['desc']) ?></p>
      <div class="divider"></div>
      <ul class="points">
        <?php foreach ($card['points'] as $point): ?>
        <li><?= $checkSvg ?><?= esc($point) ?></li>
        <?php endforeach; ?>
      </ul>
      <a class="btn btn-enter" href="<?= esc($card['url'], 'attr') ?>" rel="nofollow"><?= esc($card['cta']) ?></a>
    </li>
    <?php endforeach; ?>
  </ul>

  <div class="reassure">
    <span><?= $checkSvg ?>Nessun login richiesto</span>
    <span><?= $checkSvg ?>Solo dati demo, mai reali</span>
    <span><?= $checkSvg ?>Cambi ruolo quando vuoi</span>
  </div>
</main>

<nav class="sticky-bar" aria-label="Azioni rapide">
  <a class="btn btn-primary" href="<?= esc($prenotaUrl, 'attr') ?>">Prenota una dimostrazione</a>
  <a class="btn btn-ghost wa" href="<?= esc($whatsappUrl, 'attr') ?>" target="_blank" rel="noopener nofollow" aria-label="Scrivici su WhatsApp">
    <?= $waSvg ?>
  </a>
</nav>

</body>
</html>

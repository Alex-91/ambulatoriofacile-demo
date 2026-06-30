<?php
/**
 * Splash demo — Variante C (split editoriale: rail navy + righe-porta). Resa da demo/access.php.
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
$arrowSvg = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14"></path><path d="m12 5 7 7-7 7"></path></svg>';
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
  body{font-family:'Inter',system-ui,-apple-system,sans-serif;background:var(--bg);color:var(--text);-webkit-font-smoothing:antialiased;line-height:1.5;padding-bottom:88px}
  a{color:inherit;text-decoration:none}
  svg{display:block}
  a:focus-visible,button:focus-visible{outline:2px solid var(--cta);outline-offset:2px;border-radius:4px}
  .btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;font-weight:600;text-decoration:none;border:0;cursor:pointer;transition:background .15s,border-color .15s}
  .btn-primary{background:var(--cta);color:#fff;border-radius:11px;height:44px;padding:0 18px;font-size:14px}
  .btn-primary:hover{background:var(--cta-hover)}
  .csplit{display:grid;grid-template-columns:1fr}
  .rail{position:relative;overflow:hidden;background:var(--primary);color:#fff;padding:22px 20px 24px}
  .rail-top{position:relative;z-index:1;display:flex;align-items:center;justify-content:space-between;gap:12px}
  .rail .logo{display:inline-flex}
  .rail .logo img{height:24px;width:auto;filter:brightness(0) invert(1)}
  .rail-back{display:inline-flex;align-items:center;gap:5px;font-weight:600;font-size:12px;color:rgba(255,255,255,.85)}
  .rail-back svg{width:13px;height:13px}
  .rail-mid{position:relative;z-index:1}
  .rail-h1{margin:18px 0 0;font-size:23px;line-height:1.2;font-weight:700;letter-spacing:-.02em;color:#fff}
  .rail-sub{margin:9px 0 0;font-size:14px;line-height:1.5;color:rgba(255,255,255,.74);max-width:38ch}
  .watermark{position:absolute;right:-30px;bottom:-34px;width:150px;height:auto;color:var(--accent);opacity:.08;pointer-events:none;z-index:0}
  .rail-bottom{display:none}
  .reassure-rail{list-style:none;margin:0 0 18px;padding:0;display:flex;flex-direction:column;gap:8px}
  .reassure-rail li{display:flex;align-items:center;gap:9px;font-size:13px;font-weight:500;color:rgba(255,255,255,.82)}
  .reassure-rail .dot{flex:none;width:6px;height:6px;border-radius:50%;background:var(--accent)}
  .rail-actions{display:flex;flex-wrap:wrap;gap:10px}
  .btn-navy-ghost{background:transparent;border:1px solid rgba(255,255,255,.28);color:#fff;border-radius:11px;height:44px;padding:0 16px;font-size:14px}
  .btn-navy-ghost:hover{background:rgba(255,255,255,.08);border-color:rgba(255,255,255,.5)}
  .btn-navy-ghost svg{width:18px;height:18px}
  .choice{padding:24px 20px 8px;display:flex;flex-direction:column;gap:12px}
  .choice-eyebrow{margin:0;font-weight:600;font-size:11px;letter-spacing:.11em;text-transform:uppercase;color:var(--text-light)}
  .crows{list-style:none;margin:0;padding:0;display:flex;flex-direction:column;gap:12px}
  .crow{display:flex;align-items:center;gap:14px;background:var(--white);border:1px solid var(--border);border-radius:15px;padding:15px 15px;box-shadow:0 1px 2px rgba(8,54,79,.04);transition:border-color .15s,box-shadow .15s}
  .crow:hover{border-color:var(--cta);box-shadow:0 6px 20px rgba(8,54,79,.08)}
  .chip{flex:none;width:44px;height:44px;border-radius:12px;display:inline-flex;align-items:center;justify-content:center}
  .chip svg{width:21px;height:21px}
  .chip-memo{background:rgba(0,103,161,.08);border:1px solid rgba(0,103,161,.22);color:var(--memo-line)}
  .chip-agenda{background:rgba(235,155,0,.12);border:1px solid rgba(235,155,0,.30);color:var(--agenda-line)}
  .chip-assistenza{background:rgba(130,110,210,.10);border:1px solid rgba(130,110,210,.26);color:var(--assistenza-line)}
  .crow-body{flex:1;min-width:0}
  .crow-title{margin:0;font-size:16px;line-height:1.25;font-weight:600;color:var(--primary)}
  .crow-desc{margin:4px 0 0;font-size:13px;line-height:1.4;color:var(--text-light)}
  .crow-cta{flex:none;width:48px;min-height:48px;background:var(--cta);color:#fff;border-radius:11px;font-size:14px;padding:0}
  .crow-cta:hover{background:var(--cta-hover)}
  .crow-cta svg{width:18px;height:18px}
  .cta-label{display:none}
  .reassure-m{display:flex;flex-wrap:wrap;align-items:center;gap:10px 20px;margin:6px 2px 8px}
  .reassure-m span{display:inline-flex;align-items:center;gap:7px;font-size:13px;font-weight:500;color:var(--text-light)}
  .reassure-m svg{flex:none;width:15px;height:15px;color:var(--cta)}
  .sticky-bar{position:fixed;left:0;right:0;bottom:0;z-index:20;display:flex;gap:10px;padding:12px 16px;background:rgba(246,248,250,.93);backdrop-filter:blur(8px);-webkit-backdrop-filter:blur(8px);border-top:1px solid var(--border)}
  .sticky-bar .btn-primary{flex:1;height:48px;font-size:15px;border-radius:12px}
  .sticky-bar .wa{flex:none;width:48px;height:48px;border-radius:12px;padding:0;background:var(--white);color:var(--primary);border:1px solid var(--border)}
  .sticky-bar .wa:hover{background:var(--bg)}
  .sticky-bar .wa svg{width:20px;height:20px}
  @media (min-width:768px){
    .rail-h1{font-size:27px}
    .crow{padding:18px 20px;gap:18px}
    .crow-cta{width:auto;padding:0 18px;gap:7px}
    .cta-label{display:inline}
  }
  @media (min-width:1024px){
    body{padding-bottom:0}
    .csplit{grid-template-columns:392px 1fr;min-height:100vh}
    .rail{display:flex;flex-direction:column;min-height:100vh;padding:34px 34px}
    .rail-back{display:none}
    .rail-mid{margin:auto 0}
    .rail-h1{font-size:32px}
    .rail-sub{font-size:16px;max-width:30ch}
    .watermark{right:-44px;bottom:-46px;width:300px}
    .rail-bottom{display:block;position:relative;z-index:1}
    .choice{padding:40px 38px;justify-content:center;gap:14px}
    .crow-title{font-size:17px}
    .crow .chip{width:50px;height:50px;border-radius:14px}
    .crow .chip svg{width:23px;height:23px}
    .reassure-m{display:none}
    .sticky-bar{display:none}
  }
  @media (prefers-reduced-motion:reduce){*{transition:none!important}}
</style>
</head>
<body>

<div class="csplit">

  <section class="rail">
    <svg class="watermark" viewBox="0 0 46 47" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" clip-rule="evenodd" d="M23.9323 24.7559H44.0873C44.7823 24.7559 45.3456 25.3192 45.3456 26.0142V28.5308C45.3456 29.2257 44.7823 29.7891 44.0873 29.7891H32.7582L44.4806 41.4994C44.9722 41.9906 44.9722 42.7869 44.4806 43.278L42.4756 45.2809C41.984 45.772 41.1868 45.772 40.6952 45.2809L28.9711 33.5689L28.9711 44.889C28.9711 45.5839 28.4077 46.1473 27.7128 46.1473H25.191C24.496 46.1473 23.9326 45.5839 23.9326 44.889L23.9326 29.7891H23.9323V24.7559Z"></path><path d="M28.9747 0.847656H16.3736C15.6787 0.847656 15.1153 1.41102 15.1153 2.10597V15.9525H1.25831C0.563365 15.9525 0 16.5159 0 17.2108V29.7888C0 30.4838 0.563365 31.0471 1.25831 31.0471H15.1153L15.1175 44.8939C15.1176 45.5887 15.681 46.152 16.3758 46.152H19.5253C20.2202 46.152 20.7836 45.5886 20.7836 44.8937V21.6098H44.0901C44.785 21.6098 45.3484 21.0464 45.3484 20.3515V17.2108C45.3484 16.5159 44.785 15.9525 44.0901 15.9525H30.233V2.10597C30.233 1.41102 29.6697 0.847656 28.9747 0.847656Z"></path></svg>

    <div class="rail-top">
      <a class="logo" href="<?= esc($siteUrl, 'attr') ?>" aria-label="Ambulatorio Facile, vai al sito">
        <img src="<?= esc($logoUrl, 'attr') ?>" alt="Ambulatorio Facile">
      </a>
      <a class="rail-back" href="<?= esc($siteUrl, 'attr') ?>">
        <?= $backSvg ?>
        Sito
      </a>
    </div>

    <div class="rail-mid">
      <h1 class="rail-h1">Entra nella demo dal ruolo che ti interessa.</h1>
      <p class="rail-sub">Scegli un ruolo e provi subito Ambulatorio Facile dal suo punto di vista.</p>
    </div>

    <div class="rail-bottom">
      <ul class="reassure-rail">
        <li><span class="dot"></span>Nessun login</li>
        <li><span class="dot"></span>Dati demo separati dalla produzione</li>
        <li><span class="dot"></span>Cambi ruolo quando vuoi</li>
      </ul>
      <div class="rail-actions">
        <a class="btn btn-primary" href="<?= esc($prenotaUrl, 'attr') ?>">Prenota una demo</a>
        <a class="btn btn-navy-ghost" href="<?= esc($whatsappUrl, 'attr') ?>" target="_blank" rel="noopener nofollow" aria-label="Scrivici su WhatsApp">
          <?= $waSvg ?>
          WhatsApp
        </a>
      </div>
    </div>
  </section>

  <main class="choice">
    <p class="choice-eyebrow">Scegli come entrare</p>
    <ul class="crows">
      <?php foreach ($roleCards as $card): ?>
      <li class="crow">
        <span class="chip chip-<?= esc($card['chip'], 'attr') ?>"><?= $icon($card['icon']) ?></span>
        <div class="crow-body">
          <h2 class="crow-title"><?= esc($card['title']) ?></h2>
          <p class="crow-desc"><?= esc($card['short']) ?></p>
        </div>
        <a class="btn crow-cta" href="<?= esc($card['url'], 'attr') ?>" rel="nofollow" aria-label="<?= esc($card['cta'], 'attr') ?>">
          <span class="cta-label">Entra</span>
          <?= $arrowSvg ?>
        </a>
      </li>
      <?php endforeach; ?>
    </ul>

    <div class="reassure-m">
      <span><?= $checkSvg ?>Nessun login</span>
      <span><?= $checkSvg ?>Dati demo separati</span>
      <span><?= $checkSvg ?>Cambi ruolo quando vuoi</span>
    </div>
  </main>

</div>

<nav class="sticky-bar" aria-label="Azioni rapide">
  <a class="btn btn-primary" href="<?= esc($prenotaUrl, 'attr') ?>">Prenota una demo</a>
  <a class="btn wa" href="<?= esc($whatsappUrl, 'attr') ?>" target="_blank" rel="noopener nofollow" aria-label="Scrivici su WhatsApp">
    <?= $waSvg ?>
  </a>
</nav>

</body>
</html>

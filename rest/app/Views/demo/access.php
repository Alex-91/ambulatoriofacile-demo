<?php
/**
 * Splash di ingresso alla demo (/demo).
 * Redesign 2026-06: smistamento a 3 ruoli (Responsabile, Segreteria, Professionista).
 * Due varianti grafiche (B = card affiancate, C = split editoriale). Priorita:
 *   1. ?variant=B|C  -> anteprima istantanea (vince sempre)
 *   2. scelta "live" di Simone dall'admin del sito (sezione Impostazioni),
 *      letta a runtime da https://ambulatoriofacile.it/api/demo/variant
 *      (cache breve + fallback sicuro: se non risponde si usa env/B)
 *   3. env DEMO_SPLASH_VARIANT = B | C
 *   4. default B
 * Questa view prepara i dati condivisi e delega alla variante scelta.
 * Non tocca login/app di produzione.
 */
helper('url');

$publicAccessEnabled = !empty($demoPublicAccessEnabled);

$accountsByUsername = [];
foreach ((array) ($demoAccountGroups ?? []) as $group) {
    foreach ((array) ($group['accounts'] ?? []) as $account) {
        $username = trim((string) ($account['username'] ?? ''));
        if ($username !== '') {
            $accountsByUsername[$username] = $account;
        }
    }
}

$actionUrlFor = static function (string $username) use ($accountsByUsername, $publicAccessEnabled): string {
    $account = $accountsByUsername[$username] ?? null;
    if (is_array($account)) {
        $url = $publicAccessEnabled
            ? (string) ($account['entry_url'] ?? '')
            : (string) ($account['login_url'] ?? '');
        if ($url !== '') {
            return $url;
        }
    }

    return $publicAccessEnabled
        ? site_url('access/entra') . '?' . http_build_query(['u' => $username])
        : site_url('login') . '?' . http_build_query(['demo' => '1', 'u' => $username]);
};

$roleCards = [
    [
        'username' => 'demo.tenant.master',
        'chip'     => 'memo',
        'icon'     => 'sliders',
        'eyebrow'  => 'Accesso completo',
        'title'    => 'Responsabile dello studio',
        'desc'     => 'La visione completa del titolare: attivi le funzioni, gestisci gli utenti e imposti tutto lo studio.',
        'short'    => 'Attivi le funzioni, gestisci gli utenti e imposti tutto lo studio.',
        'points'   => ['Funzioni e moduli da attivare', 'Gestione utenti e permessi', 'Impostazioni dello studio'],
        'cta'      => 'Entra come responsabile',
    ],
    [
        'username' => 'demo.tenant.agenda',
        'chip'     => 'agenda',
        'icon'     => 'calendar',
        'eyebrow'  => 'Tutte le agende',
        'title'    => 'Segreteria',
        'desc'     => 'Gestisci le agende di tutti i professionisti: prendi e sposti appuntamenti, confermi, usi la posta interna. Tutto tranne le impostazioni.',
        'short'    => 'Agende di tutti i professionisti, prenotazioni e posta interna. Tutto tranne le impostazioni.',
        'points'   => ['Agenda di tutto lo studio', 'Prenotazioni, conferme e spostamenti', 'Posta interna col team'],
        'cta'      => 'Entra come segreteria',
    ],
    [
        'username' => 'demo.dietista',
        'chip'     => 'assistenza',
        'icon'     => 'user',
        'eyebrow'  => 'Il tuo account',
        'title'    => 'Professionista',
        'desc'     => 'Il tuo account personale: la tua agenda, la posta e il follow-up dei tuoi pazienti.',
        'short'    => 'Il tuo account personale: la tua agenda, la posta e il follow-up dei tuoi pazienti.',
        'points'   => ['La tua agenda giornaliera', 'Posta e note', 'Continuità coi pazienti'],
        'cta'      => 'Entra come professionista',
    ],
];

foreach ($roleCards as $index => $card) {
    $roleCards[$index]['url'] = $actionUrlFor((string) $card['username']);
}

$request = service('request');
$queryVariant = strtoupper(trim((string) ($request->getGet('variant') ?? '')));

// Variante "live" scelta da Simone nell'admin del sito (sezione Impostazioni).
// La sorgente di verita e' il Postgres dell'admin; qui la leggiamo a runtime
// dall'endpoint pubblico, con cache breve (anche negativa, per non martellare)
// e fallback sicuro: se non risponde si ripiega su env/B e la splash non si
// rompe mai. Ritorna 'B'|'C' oppure null (= usa il fallback env/B).
$resolveAdminVariant = static function (): ?string {
    try {
        $cache  = service('cache');
        $cached = $cache?->get('demo_splash_variant');
        if ($cached === 'B' || $cached === 'C') {
            return $cached;
        }
        if ($cached === '-') {
            return null; // cache negativa: salta il remoto, usa il fallback
        }

        $client   = service('curlrequest', ['timeout' => 3, 'connect_timeout' => 2]);
        $response = $client->get('https://ambulatoriofacile.it/api/demo/variant', [
            'http_errors'   => false,
            'allow_redirects' => true,
        ]);

        if ($response->getStatusCode() === 200) {
            $data = json_decode((string) $response->getBody(), true);
            $v = isset($data['variant']) ? strtoupper(trim((string) $data['variant'])) : '';
            if ($v === 'B' || $v === 'C') {
                $cache?->save('demo_splash_variant', $v, 20);
                return $v;
            }
        }
        $cache?->save('demo_splash_variant', '-', 10);
    } catch (\Throwable $e) {
        // fallback silenzioso: la splash non deve mai rompersi
    }
    return null;
};

$envVariant = strtoupper(trim((string) (env('DEMO_SPLASH_VARIANT') ?: '')));

if (in_array($queryVariant, ['B', 'C'], true)) {
    $variant = $queryVariant;                       // anteprima: vince sempre
} else {
    $adminVariant = $resolveAdminVariant();
    if ($adminVariant !== null) {
        $variant = $adminVariant;                   // scelta admin (live)
    } elseif (in_array($envVariant, ['B', 'C'], true)) {
        $variant = $envVariant;                     // fallback env
    } else {
        $variant = 'B';                             // default
    }
}

$site = 'https://ambulatoriofacile.it';

echo view('demo/splash_' . strtolower($variant), [
    'roleCards'   => $roleCards,
    'siteUrl'     => $site,
    'prenotaUrl'  => $site . '/contatti#prenota',
    'whatsappUrl' => 'https://wa.me/393347444795',
    'logoUrl'     => $site . '/images/logo.svg',
    'iconSvg'     => $site . '/icon.svg',
    'appleIcon'   => $site . '/apple-icon',
    'ogImage'     => $site . '/images/og-image.png',
    'canonical'   => $site . '/demo',
]);

<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;

class Dashboard extends BaseController
{
    public function index()
    {
        helper('portal');

        $portalRedirect = portal_session_console_url();
        if ($portalRedirect !== null) {
            return redirect()->to($portalRedirect);
        }

        $me = session()->get('utente_sess');
        if (!$me || empty($me->id_user)) {
            return redirect()->to('/login');
        }

        if (session()->get('is_admin') !== true && (int)($me->tipo ?? 0) !== 1) {
            return redirect()->to('/');
        }

        $menuAdmin = session()->get('menuDataAdmin');
        $menuItems = $menuAdmin['result'] ?? [];
        $seedReport = $this->loadLatestDemoSeedReport();

        return view('admin/dashboard', [
            'menu_items' => $menuItems,
            'pageTitle' => 'Admin',
            'demoScenario' => $this->demoScenario(),
            'demoSeedStatus' => [
                'finished_at' => (string)($seedReport['finished_at'] ?? ''),
                'database' => (string)($seedReport['target_db'] ?? ''),
                'password' => (string)($seedReport['password'] ?? 'Demo2026'),
                'summary' => (array)($seedReport['summary'] ?? []),
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function demoScenario(): array
    {
        return [
            'title' => 'Studio Nutrizione Equilibrio',
            'subtitle' => 'Demo locale costruita per uno studio di dietistica con 2 professionisti e 1 segreteria.',
            'metrics' => [
                ['value' => '2', 'label' => 'professionisti'],
                ['value' => '1', 'label' => 'segreteria'],
                ['value' => '3', 'label' => 'stanze demo'],
                ['value' => '1', 'label' => 'accesso admin'],
            ],
            'focus' => [
                'Agenda condivisa per prime visite, controlli e follow-up ricorrenti.',
                'Segreteria con visione operativa chiara su conferme, spostamenti e reminder.',
                'Continuita tra professionisti, chat interna e comunicazione strutturata col paziente.',
            ],
            'roles' => [
                [
                    'title' => 'Admin',
                    'account' => 'demo.admin',
                    'goal' => 'Apri la demo spiegando struttura, ruoli, visibilita moduli e relazione tra segreteria e professionisti.',
                ],
                [
                    'title' => 'Segreteria',
                    'account' => 'demo.admin->demo.segreteria',
                    'goal' => 'Mostra agenda del giorno, ricerca disponibilita, inserimento visita e gestione conferme.',
                ],
                [
                    'title' => 'Dietista',
                    'account' => 'demo.dietista',
                    'goal' => 'Fai vedere follow-up, posta, chat interna e passaggio MFA con OTP fisso 2510.',
                ],
                [
                    'title' => 'Collaboratrice',
                    'account' => 'demo.nutrizionista',
                    'goal' => 'Chiudi facendo vedere come piu professionisti convivono senza perdere ordine o responsabilita.',
                ],
            ],
            'timeline' => [
                '1. Parti dall admin e spiega in 60 secondi che la demo usa database separato e dati anonimi.',
                '2. Passa alla segreteria e fai vedere come si riempie o si sposta un appuntamento.',
                '3. Entra come dietista e collega agenda, follow-up, messaggi e continuita col paziente.',
                '4. Se serve, fai il passaggio OTP per far vedere che l accesso e protetto ma semplice.',
                '5. Chiudi tornando sul valore: meno caos operativo, meno no-show, piu controllo del team.',
            ],
            'talkingPoints' => [
                'Non vi sto facendo vedere una semplice agenda: vi sto facendo vedere come lavora lo studio quando ci sono piu persone coinvolte.',
                'La segreteria guadagna velocita, ma ogni professionista mantiene il proprio contesto clinico e operativo.',
                'Il vantaggio vero non e solo prenotare: e tenere insieme conferme, spostamenti, comunicazione e sicurezza di accesso.',
            ],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function loadLatestDemoSeedReport(): ?array
    {
        $matches = glob(WRITEPATH . 'demo_setup' . DIRECTORY_SEPARATOR . 'demo_seed_*.json') ?: [];
        if ($matches === []) {
            return null;
        }

        usort(
            $matches,
            static fn(string $a, string $b): int => filemtime($b) <=> filemtime($a)
        );

        $content = file_get_contents($matches[0]);
        if ($content === false) {
            return null;
        }

        $decoded = json_decode($content, true);
        return is_array($decoded) ? $decoded : null;
    }
}

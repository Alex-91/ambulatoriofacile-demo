<?php

namespace App\Services;

use CodeIgniter\Database\BaseConnection;

class AgendaFontSizeService
{
    public const TABLE = 'dap_user_agenda_font_preferences';

    /**
     * The defaults mirror the sizes currently used by the agenda. Users without
     * a saved row therefore keep the existing rendering unchanged.
     *
     * @var array<string, array<string, mixed>>
     */
    private const DEFINITIONS = [
        'day_headings' => [
            'label' => 'Giorni e date',
            'description' => 'Intestazioni delle colonne e data della vista giorno.',
            'default' => 14,
            'min' => 12,
            'max' => 22,
            'css_var' => '--agenda-font-day-heading',
            'group' => 'Calendario',
        ],
        'time_labels' => [
            'label' => 'Fasce orarie',
            'description' => 'Ore mostrate sul lato della griglia agenda.',
            'default' => 14,
            'min' => 11,
            'max' => 20,
            'css_var' => '--agenda-font-time-label',
            'group' => 'Calendario',
        ],
        'appointment_title' => [
            'label' => 'Nome paziente o appuntamento',
            'description' => 'Testo principale dentro gli appuntamenti e gli slot.',
            'default' => 12,
            'min' => 10,
            'max' => 20,
            'css_var' => '--agenda-font-appointment-title',
            'group' => 'Appuntamenti',
        ],
        'appointment_time' => [
            'label' => 'Orario appuntamento',
            'description' => 'Ora di inizio e fine mostrata nel blocco.',
            'default' => 11,
            'min' => 9,
            'max' => 18,
            'css_var' => '--agenda-font-appointment-time',
            'group' => 'Appuntamenti',
        ],
        'appointment_details' => [
            'label' => 'Dettagli appuntamento',
            'description' => 'Tipo visita, telefono, sede, stanza e note brevi.',
            'default' => 10,
            'min' => 9,
            'max' => 18,
            'css_var' => '--agenda-font-appointment-details',
            'group' => 'Appuntamenti',
        ],
        'team_professionals' => [
            'label' => 'Nomi professionisti',
            'description' => 'Intestazioni e nomi nella vista Giorno Team.',
            'default' => 14,
            'min' => 12,
            'max' => 22,
            'css_var' => '--agenda-font-team-professional',
            'group' => 'Strumenti',
        ],
        'controls' => [
            'label' => 'Comandi, filtri e menu',
            'description' => 'Pulsanti, selettori, filtri e voci laterali dell’agenda.',
            'default' => 12,
            'min' => 11,
            'max' => 18,
            'css_var' => '--agenda-font-controls',
            'group' => 'Strumenti',
        ],
        'mini_calendar' => [
            'label' => 'Mini calendario',
            'description' => 'Mese, giorni e legenda del calendario laterale.',
            'default' => 13,
            'min' => 11,
            'max' => 19,
            'css_var' => '--agenda-font-mini-calendar',
            'group' => 'Strumenti',
        ],
        'notes' => [
            'label' => 'Note e memo',
            'description' => 'Titoli, contenuto e informazioni secondarie di note e memo.',
            'default' => 12,
            'min' => 11,
            'max' => 20,
            'css_var' => '--agenda-font-notes',
            'group' => 'Strumenti',
        ],
    ];

    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? \Config\Database::connect();
    }

    /**
     * @return array<string, mixed>
     */
    public function resolveForUser(int $userId): array
    {
        $savedSizes = [];
        $hasSavedPreferences = false;

        if ($userId > 0 && $this->db->tableExists(self::TABLE)) {
            $row = $this->db->table(self::TABLE)
                ->where('id_user', $userId)
                ->get(1)
                ->getRowArray();

            if (is_array($row)) {
                $savedSizes = $this->decodeSizes((string) ($row['config_json'] ?? ''));
                $hasSavedPreferences = true;
            }
        }

        $effectiveSizes = $this->sanitizeSizes($savedSizes);
        $rows = [];

        foreach (self::DEFINITIONS as $key => $definition) {
            $rows[] = array_merge($definition, [
                'key' => $key,
                'value' => (int) ($effectiveSizes[$key] ?? $definition['default']),
                'options' => range((int) $definition['min'], (int) $definition['max']),
            ]);
        }

        return [
            'rows' => $rows,
            'sizes' => $effectiveSizes,
            'defaults' => $this->defaultSizes(),
            'has_saved_preferences' => $hasSavedPreferences,
        ];
    }

    /**
     * @param array<int|string, mixed> $rawSizes
     */
    public function saveForUser(int $userId, array $rawSizes): bool
    {
        if ($userId <= 0) {
            throw new \InvalidArgumentException('Utente non valido.');
        }

        if (!$this->db->tableExists(self::TABLE)) {
            throw new \RuntimeException('La tabella delle preferenze agenda non è ancora disponibile.');
        }

        $sizes = $this->sanitizeSizes($rawSizes);
        $payload = [
            'config_json' => json_encode([
                'version' => 1,
                'sizes' => $sizes,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'updated_at' => date('Y-m-d H:i:s'),
        ];

        $builder = $this->db->table(self::TABLE);
        $existing = $builder
            ->select('id_user')
            ->where('id_user', $userId)
            ->get(1)
            ->getRowArray();

        if ($existing) {
            return (bool) $this->db->table(self::TABLE)
                ->where('id_user', $userId)
                ->update($payload);
        }

        $payload['id_user'] = $userId;
        $payload['created_at'] = $payload['updated_at'];

        return (bool) $this->db->table(self::TABLE)->insert($payload);
    }

    /**
     * @return array<string, string>
     */
    public function resolveCssVariables(int $userId): array
    {
        $settings = $this->resolveForUser($userId);
        $sizes = (array) ($settings['sizes'] ?? []);
        $variables = [];

        foreach (self::DEFINITIONS as $key => $definition) {
            $cssVar = trim((string) ($definition['css_var'] ?? ''));
            if ($cssVar === '') {
                continue;
            }

            $variables[$cssVar] = (int) ($sizes[$key] ?? $definition['default']) . 'px';
        }

        return $variables;
    }

    /**
     * @param array<int|string, mixed> $rawSizes
     * @return array<string, int>
     */
    public function sanitizeSizes(array $rawSizes): array
    {
        $sizes = [];

        foreach (self::DEFINITIONS as $key => $definition) {
            $default = (int) $definition['default'];
            $value = filter_var($rawSizes[$key] ?? $default, FILTER_VALIDATE_INT);
            $value = $value === false ? $default : (int) $value;
            $sizes[$key] = max((int) $definition['min'], min((int) $definition['max'], $value));
        }

        return $sizes;
    }

    /**
     * @return array<string, int>
     */
    public function defaultSizes(): array
    {
        $defaults = [];
        foreach (self::DEFINITIONS as $key => $definition) {
            $defaults[$key] = (int) $definition['default'];
        }

        return $defaults;
    }

    /**
     * @return array<string, int>
     */
    private function decodeSizes(string $json): array
    {
        $json = trim($json);
        if ($json === '') {
            return [];
        }

        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return [];
        }

        $sizes = $decoded['sizes'] ?? $decoded;
        return is_array($sizes) ? $sizes : [];
    }
}

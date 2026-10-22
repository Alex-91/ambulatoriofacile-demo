<?php

namespace App\Services;

/** Stamp liability is independent from reimbursement and actual payment of the tax. */
final class BillingStampDuty
{
    public static function lineTreatments(): array
    {
        return [''=>'Come il documento', 'IVA22'=>'IVA 22%', 'IVA10'=>'IVA 10%', 'IVA5'=>'IVA 5%', 'IVA4'=>'IVA 4%'] + self::NATURES;
    }

    public static function lineTax(string $treatment): array
    {
        if (!array_key_exists($treatment, self::lineTreatments())) throw new \InvalidArgumentException('Trattamento fiscale della riga non valido.');
        if ($treatment === '') return [];
        return ['tax_treatment'=>$treatment, 'vat_rate'=>str_starts_with($treatment, 'IVA') ? (float) substr($treatment, 3) : 0,
            'stamp_nature'=>str_starts_with($treatment, 'IVA') ? 'EXEMPT' : $treatment];
    }

    public static function taxAmount(array $items, float $defaultRate): float
    {
        $groups = [];
        foreach ($items as $item) {
            $rate = (string) ($item['vat_rate'] ?? $defaultRate);
            $groups[$rate] = ($groups[$rate] ?? 0) + self::cents($item['line_total']);
        }
        $tax = 0;
        foreach ($groups as $rate=>$base) $tax += (int) round($base * (float) $rate / 100);
        return $tax / 100;
    }

    public static function lineLabel(array $item, array $document): string
    {
        if (!empty($item['tax_treatment'])) return self::lineTreatments()[$item['tax_treatment']] ?? '';
        return number_format((float) ($document['vat_rate'] ?? 0), 2, ',', '.') . '% ' . ($document['vat_nature'] ?? '');
    }

    public const NATURES = [
        'N4' => 'Esente IVA (art. 10)',
        'N2.2' => 'Non soggetta / regime forfettario',
        'N2.1' => 'Fuori campo per territorialità',
        'N3.5' => 'Non imponibile con lettera di intento',
        'N3.6' => 'Altre operazioni non imponibili soggette a bollo',
        'EXEMPT' => 'Operazione esente anche da bollo',
        'MANUAL' => 'Altro caso: verifica manuale del bollo',
    ];

    public static function cents($value): int
    {
        return (int) round((float) str_replace(',', '.', (string) $value) * 100);
    }

    public static function settings(array $config): array
    {
        // A saved legacy configuration keeps its original flat-amount behaviour.
        return array_replace([
            'automatic' => $config === [], 'charge_customer' => true, 'nature' => 'N4',
            'method' => 'adhesive', 'authorization_number' => '',
            'authorization_date' => '', 'authorization_office' => '',
        ], (array) ($config['stamp_duty'] ?? []));
    }

    public static function document(array $document): array
    {
        $snapshot = json_decode((string) ($document['template_snapshot_json'] ?? '{}'), true);
        return array_replace([
            'stamp_mode' => 'manual', 'stamp_charge_customer' => true,
            'stamp_nature' => 'N4', 'stamp_method' => 'unrecorded', 'stamp_identifier' => '',
            'stamp_authorization_number' => '', 'stamp_authorization_date' => '',
            'stamp_authorization_office' => '',
        ], (array) ($snapshot['document_stamp'] ?? []));
    }

    public static function defaults(array $template): array
    {
        $s = self::settings($template);
        return [
            'stamp_mode' => $s['automatic'] ? 'automatic' : 'manual',
            'stamp_charge_customer' => $s['charge_customer'], 'stamp_nature' => $s['nature'],
            'stamp_method' => $s['method'], 'stamp_identifier' => '',
            'stamp_authorization_number' => $s['authorization_number'],
            'stamp_authorization_date' => $s['authorization_date'],
            'stamp_authorization_office' => $s['authorization_office'],
        ];
    }

    public static function normalize(array $payload, array $template, array $items): array
    {
        $data = array_intersect_key($payload, self::defaults($template)) + self::defaults($template);
        if (!in_array($data['stamp_mode'], ['automatic', 'manual'], true)
            || !array_key_exists($data['stamp_nature'], self::NATURES)
            || !in_array($data['stamp_method'], ['adhesive', 'virtual', 'sdi', 'unrecorded'], true)) {
            throw new \InvalidArgumentException('Impostazioni del bollo non valide.');
        }
        $data['stamp_charge_customer'] = filter_var($data['stamp_charge_customer'], FILTER_VALIDATE_BOOLEAN);
        foreach (['stamp_identifier', 'stamp_authorization_number', 'stamp_authorization_date', 'stamp_authorization_office'] as $key) {
            $data[$key] = trim((string) $data[$key]);
        }
        if ($data['stamp_identifier'] !== '' && !preg_match('/^\d{14}$/D', $data['stamp_identifier'])) {
            throw new \InvalidArgumentException('Il numero identificativo della marca da bollo deve contenere 14 cifre.');
        }
        if ($data['stamp_method'] !== 'adhesive') $data['stamp_identifier'] = '';
        if ($data['stamp_method'] !== 'virtual') {
            foreach (['stamp_authorization_number', 'stamp_authorization_date', 'stamp_authorization_office'] as $key) $data[$key] = '';
        } elseif ($data['stamp_authorization_number'] === '' || !self::validDate($data['stamp_authorization_date'])) {
            throw new \InvalidArgumentException('Per il bollo virtuale indica numero e data validi dell’autorizzazione.');
        }
        $base = 0;
        foreach ($items as $item) {
            $rate = $item['vat_rate'] ?? $payload['vat_rate'] ?? 0;
            $nature = $item['stamp_nature'] ?? $data['stamp_nature'];
            if (self::cents($rate) === 0 && $nature === 'MANUAL' && $data['stamp_mode'] === 'automatic') {
                throw new \InvalidArgumentException('Per il trattamento Altro caso seleziona Importo manuale e verifica il bollo.');
            }
            if (self::cents($rate) === 0 && in_array($nature, ['N4', 'N2.1', 'N2.2', 'N3.5', 'N3.6'], true)) {
                $base += self::cents($item['line_total']);
            }
        }
        $data['stamp_base_amount'] = $base / 100;
        $data['stamp_duty_amount'] = $data['stamp_mode'] === 'automatic'
            ? ($base > 7747 ? 2.0 : 0.0) : (float) (max(0, self::cents($payload['stamp_duty_amount'] ?? 0)) / 100);
        return $data;
    }

    public static function validDate(string $date): bool
    {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        return $parsed !== false && $parsed->format('Y-m-d') === $date;
    }

    public static function chargedAmount(array $document): float
    {
        return self::document($document)['stamp_charge_customer'] ? (float) ($document['stamp_duty_amount'] ?? 0) : 0.0;
    }

    public static function wording(array $document): string
    {
        if (self::cents($document['stamp_duty_amount'] ?? 0) <= 0) return '';
        $s = self::document($document);
        $text = 'Imposta di bollo: EUR ' . number_format((float) $document['stamp_duty_amount'], 2, ',', '.')
            . ($s['stamp_charge_customer'] ? ' addebitata al cliente.' : ' a carico dell’emittente (non addebitata).');
        if ($s['stamp_method'] === 'adhesive' && $s['stamp_identifier'] !== '') {
            $text .= ' Imposta di bollo assolta sull’originale, identificativo n. ' . $s['stamp_identifier'] . '.';
        } elseif ($s['stamp_method'] === 'virtual' && $s['stamp_authorization_number'] !== '' && self::validDate($s['stamp_authorization_date'])) {
            $text .= ' Imposta di bollo assolta in modo virtuale, autorizzazione n. ' . $s['stamp_authorization_number']
                . ' del ' . date('d/m/Y', strtotime($s['stamp_authorization_date']))
                . ($s['stamp_authorization_office'] !== '' ? ', ufficio ' . $s['stamp_authorization_office'] : '') . '.';
        } elseif ($s['stamp_method'] === 'sdi') {
            $text .= ' Imposta di bollo da assolvere secondo le modalità previste per le fatture elettroniche SdI.';
        }
        return $text;
    }
}

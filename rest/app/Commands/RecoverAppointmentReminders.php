<?php

namespace App\Commands;

use App\Services\AppointmentReminderDispatchService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

final class RecoverAppointmentReminders extends BaseCommand
{
    protected $group = 'Notifications';
    protected $name = 'appointment-reminders:recover';
    protected $description = 'Recupera reminder per un tenant e una data con filtri espliciti e output senza dati paziente.';
    protected $usage = 'appointment-reminders:recover --tenant-id=N --target-date=YYYY-MM-DD [--created-date=YYYY-MM-DD] [--slot-origin=EXTRA] [--channel=sms|email|wa|otp] [--send]';
    protected $options = [
        '--tenant-id=' => 'Tenant da elaborare (obbligatorio).',
        '--target-date=' => 'Data degli appuntamenti da elaborare (obbligatoria).',
        '--created-date=' => 'Limita agli appuntamenti creati nella data indicata.',
        '--slot-origin=' => 'Limita all’origine slot indicata, per esempio EXTRA.',
        '--channel=' => 'Limita l’elaborazione a un singolo canale.',
        '--send' => 'Invia realmente; senza questa opzione esegue solo la verifica.',
    ];

    public function run(array $params)
    {
        try {
            $tenantId = (int) ($this->readOptionValue($params, '--tenant-id') ?? 0);
            $targetDate = $this->normalizeDate(
                (string) ($this->readOptionValue($params, '--target-date') ?? ''),
                'degli appuntamenti'
            );
            if ($tenantId <= 0) {
                throw new \InvalidArgumentException('Indica un tenant valido con --tenant-id.');
            }

            $createdDate = trim((string) ($this->readOptionValue($params, '--created-date') ?? ''));
            if ($createdDate !== '') {
                $createdDate = $this->normalizeDate($createdDate, 'di creazione');
            }
            $slotOrigin = strtoupper(trim((string) ($this->readOptionValue($params, '--slot-origin') ?? '')));
            if ($slotOrigin !== '' && preg_match('/^[A-Z0-9_-]{1,40}$/', $slotOrigin) !== 1) {
                throw new \InvalidArgumentException('Origine slot non valida.');
            }
            $channel = strtolower(trim((string) ($this->readOptionValue($params, '--channel') ?? 'auto')));
            if (!in_array($channel, ['auto', 'sms', 'email', 'wa', 'otp'], true)) {
                throw new \InvalidArgumentException('Canale non valido.');
            }

            $result = (new AppointmentReminderDispatchService())->run([
                'send' => $this->hasFlag($params, 'send'),
                'tenant_id' => $tenantId,
                'target_date' => $targetDate,
                'created_date' => $createdDate,
                'slot_origin' => $slotOrigin,
                'channel' => $channel,
            ]);
            foreach ($result['tenants'] as &$tenant) {
                unset($tenant['preview']);
            }
            unset($tenant);

            $json = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            CLI::write(is_string($json) ? $json : '{"mode":"error","failed":1}', (int) ($result['failed'] ?? 0) === 0 ? 'green' : 'yellow');

            return (int) ($result['failed'] ?? 0) === 0 ? EXIT_SUCCESS : EXIT_ERROR;
        } catch (\Throwable $e) {
            CLI::error('Recupero reminder fallito: ' . $e->getMessage());
            return EXIT_ERROR;
        }
    }

    private function normalizeDate(string $value, string $label): string
    {
        $value = trim($value);
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, new \DateTimeZone('Europe/Rome'));
        if (!$date || $date->format('Y-m-d') !== $value) {
            throw new \InvalidArgumentException('La data ' . $label . ' deve essere nel formato YYYY-MM-DD.');
        }

        return $value;
    }

    private function readOptionValue(array $params, string $option): ?string
    {
        $normalizedOption = rtrim(ltrim($option, '-'), '=');
        $cliValue = CLI::getOption($normalizedOption);
        if ($cliValue !== null && $cliValue !== false && $cliValue !== true) {
            return trim((string) $cliValue);
        }

        $prefix = $option . '=';
        foreach (array_merge($params, (array) ($_SERVER['argv'] ?? [])) as $param) {
            if (str_starts_with((string) $param, $prefix)) {
                return substr((string) $param, strlen($prefix));
            }
        }

        return null;
    }

    private function hasFlag(array $params, string $option): bool
    {
        if (CLI::getOption($option) !== null) {
            return true;
        }

        return in_array('--' . ltrim($option, '-'), array_merge($params, (array) ($_SERVER['argv'] ?? [])), true);
    }
}

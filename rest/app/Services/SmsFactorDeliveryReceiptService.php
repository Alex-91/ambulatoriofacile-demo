<?php

namespace App\Services;

use CodeIgniter\Database\BaseConnection;
use Config\Database;

class SmsFactorDeliveryReceiptService
{
    private const TABLE = 'platform_sms_delivery_receipts';

    /** @var array<int, string> */
    private const STATUS_LABELS = [
        0 => 'not_sent',
        1 => 'sent',
        2 => 'not_delivered',
        3 => 'delivered',
        4 => 'not_allowed',
        5 => 'invalid_destination',
        6 => 'invalid_sender',
        7 => 'route_not_available',
        9 => 'rejected',
        11 => 'network_error',
        12 => 'expired',
    ];

    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect('platform');
    }

    public static function signatureMatches(string $provided, string $expected): bool
    {
        $provided = trim($provided);
        $expected = trim($expected);

        return $provided !== '' && $expected !== '' && hash_equals($expected, $provided);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public static function normalizePayload(array $payload): array
    {
        $statusCode = filter_var($payload['status'] ?? null, FILTER_VALIDATE_INT);
        if ($statusCode === false || !isset(self::STATUS_LABELS[(int) $statusCode])) {
            throw new \InvalidArgumentException('Stato DLR SMSFactor non valido.');
        }

        $campaignId = trim((string) ($payload['campaign_id'] ?? ''));
        $destination = preg_replace('/\D+/', '', (string) ($payload['destination'] ?? '')) ?? '';
        if ($campaignId === '' || preg_match('/^[A-Za-z0-9._-]{1,64}$/', $campaignId) !== 1) {
            throw new \InvalidArgumentException('Campaign ID SMSFactor non valido.');
        }
        if (preg_match('/^[1-9][0-9]{7,14}$/', $destination) !== 1) {
            throw new \InvalidArgumentException('Destinazione DLR SMSFactor non valida.');
        }

        $clientMessageId = preg_replace('/[^A-Za-z0-9._-]/', '', trim((string) ($payload['message_id'] ?? ''))) ?? '';
        $clientMessageId = substr($clientMessageId, 0, 64);
        $tenantId = 0;
        if (preg_match('/^af-(\d+)-[A-Za-z0-9._-]+$/', $clientMessageId, $matches) === 1) {
            $tenantId = max(0, (int) $matches[1]);
        }

        $occurredAt = null;
        $rawDate = trim((string) ($payload['date'] ?? ''));
        if ($rawDate !== '') {
            try {
                $occurredAt = (new \DateTimeImmutable($rawDate))->format('Y-m-d H:i:s');
            } catch (\Throwable $e) {
                throw new \InvalidArgumentException('Data DLR SMSFactor non valida.');
            }
        }

        $countryCode = strtoupper(trim((string) ($payload['country_code'] ?? '')));
        if ($countryCode !== '' && preg_match('/^[A-Z]{2}$/', $countryCode) !== 1) {
            throw new \InvalidArgumentException('Country code DLR SMSFactor non valido.');
        }

        return [
            'tenant_id' => $tenantId,
            'client_message_id' => $clientMessageId,
            'campaign_id' => $campaignId,
            'destination' => $destination,
            'country_code' => $countryCode,
            'status_code' => (int) $statusCode,
            'delivery_status' => self::STATUS_LABELS[(int) $statusCode],
            'occurred_at' => $occurredAt,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function record(array $payload): array
    {
        if (!$this->db->tableExists(self::TABLE)) {
            throw new \RuntimeException('Migration ricevute SMSFactor non applicata.');
        }

        $normalized = self::normalizePayload($payload);
        $tenantId = (int) ($normalized['tenant_id'] ?? 0);
        if ($tenantId > 0 && !$this->tenantExists($tenantId)) {
            $tenantId = 0;
        }

        $receiptKey = hash('sha256', implode('|', [
            (string) $normalized['campaign_id'],
            (string) $normalized['client_message_id'],
            (string) $normalized['destination'],
        ]));
        $now = date('Y-m-d H:i:s');
        $data = [
            'receipt_key' => $receiptKey,
            'id_tenant' => $tenantId > 0 ? $tenantId : null,
            'provider' => 'smsfactor',
            'client_message_id' => (string) $normalized['client_message_id'] !== ''
                ? (string) $normalized['client_message_id']
                : null,
            'campaign_id' => (string) $normalized['campaign_id'],
            'destination' => (string) $normalized['destination'],
            'country_code' => (string) $normalized['country_code'] !== ''
                ? (string) $normalized['country_code']
                : null,
            'status_code' => (int) $normalized['status_code'],
            'delivery_status' => (string) $normalized['delivery_status'],
            'occurred_at' => $normalized['occurred_at'],
            'payload_json' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}',
            'updated_at' => $now,
        ];

        $existing = $this->db->table(self::TABLE)
            ->select('id_sms_delivery_receipt')
            ->where('receipt_key', $receiptKey)
            ->get(1)
            ->getRowArray();
        if ($existing) {
            $this->db->table(self::TABLE)->where('receipt_key', $receiptKey)->update($data);
        } else {
            $data['created_at'] = $now;
            $this->db->table(self::TABLE)->insert($data);
        }

        return [
            'ok' => true,
            'receipt_key' => $receiptKey,
            'tenant_id' => $tenantId,
            'campaign_id' => (string) $normalized['campaign_id'],
            'client_message_id' => (string) $normalized['client_message_id'],
            'delivery_status' => (string) $normalized['delivery_status'],
        ];
    }

    private function tenantExists(int $tenantId): bool
    {
        return $this->db->tableExists('platform_tenants')
            && $this->db->table('platform_tenants')
                ->where('id_tenant', $tenantId)
                ->countAllResults() > 0;
    }
}

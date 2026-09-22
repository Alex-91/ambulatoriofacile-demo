<?php

namespace App\Services;

/** Campaign channels deliberately do not use WhatsApp readiness or rate limits. */
class MassCampaignDeliveryService
{
    public function __construct(
        private ?TenantFeatureService $features = null,
        private ?AppointmentNotificationChannelService $channels = null
    ) {}

    public function smsEnabled(int $tenantId): bool
    {
        $features = ($this->features ??= new TenantFeatureService())->resolveEffectiveFeatureMapForTenant($tenantId);
        return !empty($features[AppointmentNotificationSettingsService::FEATURE_SMS]);
    }

    /** @return list<string> */
    public function channelOrder(array $payload, bool $smsEnabled): array
    {
        $email = !empty($payload['email_enabled']);
        $sms = !empty($payload['sms_enabled']);
        if ($sms && !$smsEnabled) {
            throw new \InvalidArgumentException('Gli SMS non sono abilitati dal Super Master per questo spazio.');
        }
        if (!$email && !$sms) {
            throw new \InvalidArgumentException('Seleziona almeno un canale: email o SMS.');
        }
        if ($email && $sms) {
            $first = (string) ($payload['first_channel'] ?? 'email');
            if (!in_array($first, ['email', 'sms'], true)) {
                throw new \InvalidArgumentException('Ordine dei canali non valido.');
            }
            return $first === 'sms' ? ['sms', 'email'] : ['email', 'sms'];
        }
        return $email ? ['email'] : ['sms'];
    }

    /** One attempt only. The queue schedules a failed attempt's fallback after 60 seconds. */
    public function send(int $tenantId, string $channel, array $recipient, string $message): array
    {
        if (!in_array($channel, ['email', 'sms'], true)) {
            return ['ok' => false, 'error' => 'Canale non ammesso per gli invii massivi.'];
        }
        if ($channel === 'sms' && !$this->smsEnabled($tenantId)) {
            return ['ok' => false, 'error' => 'SMS disabilitati per questo spazio.'];
        }
        return ($this->channels ??= new AppointmentNotificationChannelService())->send(
            $channel,
            ['mobile' => $recipient['recipient_phone'] ?? '', 'email' => $recipient['recipient_email'] ?? ''],
            $message,
            ['tenant_id' => $tenantId, 'subject' => 'Comunicazione dello studio', 'message_type' => 'mass_campaign']
        );
    }
}

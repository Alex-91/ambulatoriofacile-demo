<?php

namespace App\Services;

final class WhatsAppChatbotRuleEngine
{
    public const ACTION_CONFIRM = 'confirm_appointment';
    public const ACTION_CANCEL = 'cancel_appointment';
    public const ACTION_REPLY = 'send_reply';

    public const MAX_RULES = 30;
    public const MAX_REPLY_LENGTH = 2000;

    /**
     * @return array<string, mixed>
     */
    public function defaultConfig(): array
    {
        return [
            'enabled' => false,
            'response_window_hours' => 168,
            'prompt_text' => 'Rispondi 1 per confermare o 2 per annullare.',
            'fallback_reply' => '',
            'open_on' => [AppointmentNotificationSettingsService::TYPE_REMINDER],
            'rules' => [
                [
                    'id' => 'confirm',
                    'name' => 'Conferma appuntamento',
                    'enabled' => true,
                    'answers' => ['1', 'si', 'sì', 'confermo', 'conferma'],
                    'action' => self::ACTION_CONFIRM,
                    'reply' => 'Grazie {{paziente}}, l’appuntamento del {{data_ora}} è confermato.',
                ],
                [
                    'id' => 'cancel',
                    'name' => 'Annulla appuntamento',
                    'enabled' => true,
                    'answers' => ['2', 'no', 'annullo', 'annulla'],
                    'action' => self::ACTION_CANCEL,
                    'reply' => 'L’appuntamento del {{data_ora}} è stato annullato. Per una nuova data contatta lo studio.',
                ],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $source
     * @return array<string, mixed>
     */
    public function sanitizeConfig(array $source): array
    {
        $defaults = $this->defaultConfig();
        $rules = [];

        foreach (array_slice((array) ($source['rules'] ?? []), 0, self::MAX_RULES) as $index => $rawRule) {
            if (!is_array($rawRule)) {
                continue;
            }

            $action = strtolower(trim((string) ($rawRule['action'] ?? '')));
            if (!in_array($action, $this->supportedActions(), true)) {
                continue;
            }

            $answers = is_array($rawRule['answers'] ?? null)
                ? (array) $rawRule['answers']
                : preg_split('/[,;\r\n]+/u', (string) ($rawRule['answers'] ?? ''));
            $answers = array_values(array_unique(array_filter(array_map(
                fn($answer): string => $this->normalizeAnswer((string) $answer),
                is_array($answers) ? $answers : []
            ), static fn(string $answer): bool => $answer !== '')));

            if ($answers === []) {
                continue;
            }

            $name = $this->cleanSingleLine((string) ($rawRule['name'] ?? ''), 120);
            $ruleId = preg_replace('/[^a-z0-9_-]+/', '-', strtolower(trim((string) ($rawRule['id'] ?? '')))) ?? '';
            $ruleId = trim($ruleId, '-');
            if ($ruleId === '') {
                $ruleId = 'rule-' . ($index + 1) . '-' . substr(hash('sha256', implode('|', $answers) . '|' . $action), 0, 8);
            }

            $rules[] = [
                'id' => substr($ruleId, 0, 80),
                'name' => $name !== '' ? $name : 'Regola ' . (count($rules) + 1),
                'enabled' => $this->toBool($rawRule['enabled'] ?? false),
                'answers' => $answers,
                'action' => $action,
                'reply' => $this->cleanMultiline((string) ($rawRule['reply'] ?? ''), self::MAX_REPLY_LENGTH),
            ];
        }

        $openOn = array_values(array_unique(array_intersect(
            array_map(static fn($value): string => strtolower(trim((string) $value)), (array) ($source['open_on'] ?? [])),
            [AppointmentNotificationSettingsService::TYPE_PATIENT_BOOKING, AppointmentNotificationSettingsService::TYPE_REMINDER]
        )));

        return [
            'enabled' => $this->toBool($source['enabled'] ?? false),
            'response_window_hours' => max(1, min(720, (int) ($source['response_window_hours'] ?? $defaults['response_window_hours']))),
            'prompt_text' => $this->cleanMultiline(
                (string) ($source['prompt_text'] ?? $defaults['prompt_text']),
                self::MAX_REPLY_LENGTH
            ),
            'fallback_reply' => $this->cleanMultiline((string) ($source['fallback_reply'] ?? ''), self::MAX_REPLY_LENGTH),
            'open_on' => $openOn !== [] ? $openOn : [AppointmentNotificationSettingsService::TYPE_REMINDER],
            'rules' => $rules !== [] ? $rules : $defaults['rules'],
        ];
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>|null
     */
    public function match(array $config, string $incomingText): ?array
    {
        $answer = $this->normalizeAnswer($incomingText);
        if ($answer === '') {
            return null;
        }

        foreach ((array) ($config['rules'] ?? []) as $rule) {
            if (!is_array($rule) || empty($rule['enabled'])) {
                continue;
            }

            $answers = array_map(
                fn($candidate): string => $this->normalizeAnswer((string) $candidate),
                (array) ($rule['answers'] ?? [])
            );
            if (in_array($answer, $answers, true)) {
                return $rule;
            }
        }

        return null;
    }

    public function normalizeAnswer(string $value): string
    {
        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? '');
        $value = trim($value, " \t\n\r\0\x0B.,;:!?¡¿");

        return function_exists('mb_strtolower')
            ? mb_strtolower($value, 'UTF-8')
            : strtolower($value);
    }

    /**
     * @return list<string>
     */
    public function supportedActions(): array
    {
        return [self::ACTION_CONFIRM, self::ACTION_CANCEL, self::ACTION_REPLY];
    }

    private function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true);
    }

    private function cleanSingleLine(string $value, int $maxLength): string
    {
        $value = trim(preg_replace('/\s+/u', ' ', str_replace("\0", '', $value)) ?? '');
        return mb_substr($value, 0, $maxLength);
    }

    private function cleanMultiline(string $value, int $maxLength): string
    {
        $value = str_replace("\0", '', $value);
        $value = str_replace(["\r\n", "\r"], "\n", $value);
        $value = trim((string) preg_replace("/\n{3,}/", "\n\n", $value));

        return mb_substr($value, 0, $maxLength);
    }
}

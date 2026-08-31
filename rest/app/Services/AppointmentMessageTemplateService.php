<?php

namespace App\Services;

class AppointmentMessageTemplateService
{
    public const MAX_TEMPLATE_LENGTH = 2000;

    /**
     * @return array<string, array{label: string, sample: string}>
     */
    public function tokenDefinitions(string $messageType): array
    {
        $common = [
            'paziente' => ['label' => 'Paziente', 'sample' => 'Mario Rossi'],
            'dottore' => ['label' => 'Dottore', 'sample' => 'Dott.ssa Laura Bianchi'],
            'data' => ['label' => 'Data', 'sample' => '15/09/2026'],
            'ora' => ['label' => 'Ora', 'sample' => '10:30'],
            'data_ora' => ['label' => 'Data e ora', 'sample' => '15/09/2026 10:30'],
            'sede' => ['label' => 'Sede', 'sample' => 'Ambulatorio centrale'],
            'indirizzo' => ['label' => 'Indirizzo', 'sample' => 'Via Roma 10, Firenze'],
            'note' => ['label' => 'Note', 'sample' => 'Portare gli esami precedenti'],
            'nome_spazio' => ['label' => 'Nome dello spazio', 'sample' => 'Poliambulatorio Verdi'],
        ];

        if ($messageType === AppointmentNotificationSettingsService::TYPE_REMINDER) {
            $common['istruzioni_conferma'] = [
                'label' => 'Istruzioni conferma',
                'sample' => 'Rispondi 1 per confermare o 2 per annullare.',
            ];
        }

        return $common;
    }

    public function supports(string $messageType): bool
    {
        return in_array($messageType, [
            AppointmentNotificationSettingsService::TYPE_PATIENT_BOOKING,
            AppointmentNotificationSettingsService::TYPE_REMINDER,
        ], true);
    }

    public function defaultTemplate(string $messageType): string
    {
        return match ($messageType) {
            AppointmentNotificationSettingsService::TYPE_PATIENT_BOOKING => implode("\n", [
                'Gentile {{paziente}},',
                'il suo appuntamento è stato registrato con {{dottore}}.',
                'Data e ora: {{data_ora}}.',
                'Note appuntamento: {{note}}',
                'AmbulatorioFacile',
            ]),
            AppointmentNotificationSettingsService::TYPE_REMINDER => implode("\n", [
                'Promemoria appuntamento',
                'Data: {{data}} ore {{ora}}',
                'Dottore: {{dottore}}',
                'Sede: {{sede}}',
                '{{istruzioni_conferma}}',
            ]),
            default => '',
        };
    }

    public function normalizeTemplate(string $messageType, mixed $template): string
    {
        if (!$this->supports($messageType)) {
            return '';
        }

        $template = $this->normalizeLineEndings((string) $template);
        if ($template === '') {
            return $this->defaultTemplate($messageType);
        }

        if (mb_strlen($template) > self::MAX_TEMPLATE_LENGTH) {
            $template = mb_substr($template, 0, self::MAX_TEMPLATE_LENGTH);
        }

        return $template;
    }

    public function assertValid(string $messageType, mixed $template): void
    {
        if (!$this->supports($messageType)) {
            return;
        }

        $template = $this->normalizeLineEndings((string) $template);
        if ($template === '') {
            throw new \RuntimeException('Il testo del messaggio non può essere vuoto.');
        }

        if (mb_strlen($template) > self::MAX_TEMPLATE_LENGTH) {
            throw new \RuntimeException('Il testo del messaggio non può superare ' . self::MAX_TEMPLATE_LENGTH . ' caratteri.');
        }

        $unknownTokens = array_values(array_diff(
            $this->extractTokens($template),
            array_keys($this->tokenDefinitions($messageType))
        ));

        if ($unknownTokens !== []) {
            throw new \RuntimeException(
                'Segnaposto non riconosciuti: ' . implode(', ', array_map(
                    static fn(string $token): string => '{{' . $token . '}}',
                    $unknownTokens
                )) . '.'
            );
        }
    }

    /**
     * @param array<string, mixed> $context
     */
    public function render(string $messageType, mixed $template, array $context): string
    {
        $template = $this->normalizeTemplate($messageType, $template);
        $definitions = $this->tokenDefinitions($messageType);
        $values = [];

        foreach ($definitions as $token => $definition) {
            $values[$token] = trim((string) ($context[$token] ?? ''));
        }

        $renderedLines = [];
        foreach (explode("\n", $template) as $line) {
            $lineTokens = $this->extractTokens($line);
            if ($lineTokens !== []) {
                $hasValue = false;
                foreach ($lineTokens as $token) {
                    if (($values[$token] ?? '') !== '') {
                        $hasValue = true;
                        break;
                    }
                }

                if (!$hasValue) {
                    continue;
                }
            }

            $renderedLines[] = preg_replace_callback(
                '/\{\{\s*([a-z0-9_]+)\s*\}\}/iu',
                static fn(array $matches): string => $values[strtolower((string) ($matches[1] ?? ''))] ?? '',
                $line
            ) ?? $line;
        }

        return trim((string) preg_replace("/\n{3,}/", "\n\n", implode("\n", $renderedLines)));
    }

    /**
     * @return array<string, string>
     */
    public function previewValues(string $messageType): array
    {
        $values = [];
        foreach ($this->tokenDefinitions($messageType) as $token => $definition) {
            $values[$token] = (string) ($definition['sample'] ?? '');
        }

        return $values;
    }

    /**
     * @return array<int, string>
     */
    private function extractTokens(string $template): array
    {
        preg_match_all('/\{\{\s*([^{}]+?)\s*\}\}/u', $template, $matches);

        return array_values(array_unique(array_map(
            static fn($token): string => strtolower(trim((string) $token)),
            (array) ($matches[1] ?? [])
        )));
    }

    private function normalizeLineEndings(string $template): string
    {
        $template = str_replace("\0", '', $template);
        $template = str_replace(["\r\n", "\r"], "\n", $template);

        return trim($template);
    }
}

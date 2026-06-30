<?php

namespace App\Services;

use Config\Services;

class LoginEmailService
{
    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function send(string $to, string $subject, string $messageText, ?string $messageHtml = null, array $options = []): array
    {
        $config = config('Email');
        $baseConfig = get_object_vars($config);
        $transportAttempts = [];

        $fromEmail = trim((string) ($options['fromEmail'] ?? ($config->fromEmail ?? '')));
        $fromName = trim((string) ($options['fromName'] ?? ($config->fromName ?? '')));
        $logContext = trim((string) ($options['logContext'] ?? 'login email'));

        if ($fromEmail === '') {
            $fromEmail = (string) (env('email.fromEmail') ?: 'noreply@ambulatoriofacile.it');
        }

        if ($fromName === '') {
            $fromName = (string) (env('email.fromName') ?: 'AmbulatorioFacile');
        }

        $transportConfigs = [[
            'label' => 'primary',
            'config' => $baseConfig,
        ]];

        if ($this->shouldRetryWithSubmissionPort($baseConfig)) {
            $fallbackConfig = $baseConfig;
            $fallbackConfig['SMTPPort'] = 587;
            $fallbackConfig['SMTPCrypto'] = 'tls';
            $transportConfigs[] = [
                'label' => 'fallback-587-tls',
                'config' => $fallbackConfig,
            ];
        }

        foreach ($transportConfigs as $transportConfig) {
            $attempt = $this->attemptTransport(
                (string) $transportConfig['label'],
                (array) $transportConfig['config'],
                $fromEmail,
                $fromName,
                $to,
                $subject,
                $messageText,
                $messageHtml
            );

            $transportAttempts[] = $attempt;

            if (!empty($attempt['ok'])) {
                if (($attempt['label'] ?? 'primary') !== 'primary') {
                    log_message('warning', '{context} recovered via SMTP fallback for {email}. transport={transport}', [
                        'context' => $logContext,
                        'email' => $to,
                        'transport' => (string) ($attempt['label'] ?? 'fallback'),
                    ]);
                }

                return $attempt;
            }
        }

        $debugSummary = $this->buildDebugSummary($transportAttempts);
        log_message('error', '{context} failed for {email}. Debugger: {debugger}', [
            'context' => $logContext,
            'email' => $to,
            'debugger' => $debugSummary,
        ]);

        return [
            'ok' => false,
            'error' => $debugSummary,
            'attempts' => $transportAttempts,
        ];
    }

    /**
     * @param array<string, mixed> $transportConfig
     * @return array<string, mixed>
     */
    private function attemptTransport(
        string $label,
        array $transportConfig,
        string $fromEmail,
        string $fromName,
        string $to,
        string $subject,
        string $messageText,
        ?string $messageHtml = null
    ): array {
        $trimmedHtml = trim((string) $messageHtml);

        if ($trimmedHtml !== '') {
            $htmlAttempt = $this->attemptSend(
                $transportConfig,
                $fromEmail,
                $fromName,
                $to,
                $subject,
                $trimmedHtml,
                'html',
                $messageText
            );

            if (!empty($htmlAttempt['ok'])) {
                $htmlAttempt['label'] = $label;
                return $htmlAttempt;
            }

            $textAttempt = $this->attemptSend(
                $transportConfig,
                $fromEmail,
                $fromName,
                $to,
                $subject,
                $messageText,
                'text'
            );

            if (!empty($textAttempt['ok'])) {
                $textAttempt['label'] = $label;
                $textAttempt['degraded_from_html'] = true;
                return $textAttempt;
            }

            return [
                'ok' => false,
                'label' => $label,
                'transport' => $this->describeTransport($transportConfig),
                'error' => 'html=' . ($htmlAttempt['error'] ?? 'n/a') . ' | text=' . ($textAttempt['error'] ?? 'n/a'),
            ];
        }

        $textAttempt = $this->attemptSend(
            $transportConfig,
            $fromEmail,
            $fromName,
            $to,
            $subject,
            $messageText,
            'text'
        );

        $textAttempt['label'] = $label;

        return $textAttempt;
    }

    /**
     * @param array<string, mixed> $transportConfig
     * @return array<string, mixed>
     */
    private function attemptSend(
        array $transportConfig,
        string $fromEmail,
        string $fromName,
        string $to,
        string $subject,
        string $messageBody,
        string $mailType,
        ?string $altMessage = null
    ): array {
        try {
            $mailer = Services::email($transportConfig, false);
            $mailer->clear(true);
            $mailer->setFrom($fromEmail, $fromName);
            $mailer->setTo($to);
            $mailer->setSubject($subject);
            $mailer->setMailType($mailType);
            $mailer->setMessage($messageBody);

            if ($mailType === 'html' && $altMessage !== null && trim($altMessage) !== '') {
                $mailer->setAltMessage($altMessage);
            }

            if ($mailer->send()) {
                return [
                    'ok' => true,
                    'mailType' => $mailType,
                    'transport' => $this->describeTransport($transportConfig),
                ];
            }

            $debugMessage = trim(strip_tags((string) $mailer->printDebugger(['headers', 'subject'])));
            $debugRaw = $this->extractMailerDebugRaw($mailer);
            $error = $debugMessage !== '' ? $debugMessage : 'Unable to send email using SMTP.';

            if ($debugRaw !== '') {
                $error .= ' | raw=' . $debugRaw;
            }

            return [
                'ok' => false,
                'mailType' => $mailType,
                'transport' => $this->describeTransport($transportConfig),
                'error' => $error,
            ];
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'mailType' => $mailType,
                'transport' => $this->describeTransport($transportConfig),
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * @param array<string, mixed> $transportConfig
     */
    private function shouldRetryWithSubmissionPort(array $transportConfig): bool
    {
        $protocol = strtolower(trim((string) ($transportConfig['protocol'] ?? '')));
        $host = trim((string) ($transportConfig['SMTPHost'] ?? ''));
        $port = (int) ($transportConfig['SMTPPort'] ?? 0);

        return $protocol === 'smtp' && $host !== '' && $port === 465;
    }

    /**
     * @param array<string, mixed> $transportConfig
     */
    private function describeTransport(array $transportConfig): string
    {
        return 'protocol=' . trim((string) ($transportConfig['protocol'] ?? ''))
            . ' host=' . trim((string) ($transportConfig['SMTPHost'] ?? ''))
            . ' port=' . (string) ((int) ($transportConfig['SMTPPort'] ?? 0))
            . ' crypto=' . trim((string) ($transportConfig['SMTPCrypto'] ?? ''));
    }

    /**
     * @param array<int, array<string, mixed>> $attempts
     */
    private function buildDebugSummary(array $attempts): string
    {
        $parts = [];

        foreach ($attempts as $attempt) {
            $parts[] = trim((string) ($attempt['label'] ?? 'attempt'))
                . ' [' . trim((string) ($attempt['transport'] ?? '')) . '] '
                . trim((string) ($attempt['error'] ?? 'unknown error'));
        }

        return $parts !== [] ? implode(' || ', $parts) : 'unknown error';
    }

    private function extractMailerDebugRaw(\CodeIgniter\Email\Email $mailer): string
    {
        try {
            $property = new \ReflectionProperty(\CodeIgniter\Email\Email::class, 'debugMessageRaw');

            if (method_exists($property, 'setAccessible')) {
                $property->setAccessible(true);
            }

            $rawMessages = $property->getValue($mailer);
            if (!is_array($rawMessages) || $rawMessages === []) {
                return '';
            }

            $normalized = array_map(static function ($message): string {
                return trim(str_replace(["\r", "\n"], ' ', (string) $message));
            }, $rawMessages);

            return trim(implode(' || ', array_filter($normalized, static fn(string $message): bool => $message !== '')));
        } catch (\Throwable $e) {
            return '';
        }
    }
}

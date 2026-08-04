<?php

namespace App\Services;

use Config\Email as EmailConfig;
use Config\Services;
use Dompdf\Dompdf;
use Dompdf\Options;

class BillingDocumentEmailService
{
    private BillingDocumentService $documents;
    private BillingDocumentSettingsService $settings;
    private TenantPatientLookupService $patientLookup;

    public function __construct(
        ?BillingDocumentService $documents = null,
        ?BillingDocumentSettingsService $settings = null,
        ?TenantPatientLookupService $patientLookup = null
    ) {
        $this->documents = $documents ?? new BillingDocumentService();
        $this->settings = $settings ?? new BillingDocumentSettingsService();
        $this->patientLookup = $patientLookup ?? new TenantPatientLookupService();
    }

    /**
     * @return array<string, mixed>
     */
    public function buildComposerContext(int $tenantId, int $documentId, string $deliveryType = 'invoice'): array
    {
        $deliveryType = $deliveryType === 'reminder' ? 'reminder' : 'invoice';
        $preview = $this->documents->buildPreviewContext($tenantId, $documentId);
        $document = is_array($preview['document'] ?? null) ? $preview['document'] : [];
        if (trim((string) ($document['local_state'] ?? '')) !== 'issued') {
            throw new \RuntimeException('La fattura deve essere definitiva prima di poter essere inviata via email.');
        }
        if ($deliveryType === 'reminder' && trim((string) ($document['payment_status'] ?? 'unpaid')) === 'paid') {
            throw new \RuntimeException('La fattura risulta già pagata: non è necessario inviare un sollecito.');
        }

        $settings = $this->settings->resolveTenantSettings($tenantId);
        $config = is_array($settings['config'] ?? null) ? $settings['config'] : [];
        $emailDelivery = is_array($config['email_delivery'] ?? null) ? $config['email_delivery'] : [];
        $recipient = strtolower(trim((string) ($document['patient_email'] ?? '')));
        if ($recipient === '') {
            $recipient = strtolower(trim((string) ($document['email_last_recipient'] ?? '')));
        }

        if ($recipient === '' && (int) ($document['id_client'] ?? 0) > 0) {
            try {
                $patient = $this->patientLookup->getPatientByIdForTenant($tenantId, (int) $document['id_client']);
                $recipient = strtolower(trim((string) ($patient['patient_email'] ?? $patient['email'] ?? '')));
            } catch (\Throwable $e) {
                log_message('warning', 'BillingDocumentEmailService patient email lookup failed: {message}', [
                    'message' => $e->getMessage(),
                    'tenant_id' => $tenantId,
                    'document_id' => $documentId,
                ]);
            }
        }

        $subjectTemplate = (string) ($emailDelivery[$deliveryType . '_subject'] ?? '');
        $bodyTemplate = (string) ($emailDelivery[$deliveryType . '_body'] ?? '');
        $replacements = $this->templateReplacements($preview, $config);

        return [
            'delivery_type' => $deliveryType,
            'delivery_type_label' => $deliveryType === 'reminder' ? 'Sollecito di pagamento' : 'Invio fattura',
            'recipient' => $recipient,
            'subject' => $this->replacePlaceholders($subjectTemplate, $replacements),
            'message_body' => $this->replacePlaceholders($bodyTemplate, $replacements),
            'attach_pdf' => $deliveryType === 'invoice' && !empty($emailDelivery['attach_pdf']),
            'preview' => $preview,
            'placeholders' => array_keys($replacements),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function send(
        int $tenantId,
        int $documentId,
        string $deliveryType,
        string $recipient,
        string $subject,
        string $messageBody,
        int $userId = 0
    ): array {
        $context = $this->buildComposerContext($tenantId, $documentId, $deliveryType);
        $deliveryType = (string) $context['delivery_type'];
        $recipient = strtolower(trim($recipient));
        $subject = trim((string) (preg_replace('/[\r\n]+/', ' ', $subject) ?? ''));
        $messageBody = trim($messageBody);

        if (filter_var($recipient, FILTER_VALIDATE_EMAIL) === false) {
            throw new \RuntimeException('Indirizzo email del paziente non valido.');
        }
        if ($subject === '') {
            throw new \RuntimeException('Oggetto email obbligatorio.');
        }
        if ($messageBody === '') {
            throw new \RuntimeException('Messaggio email obbligatorio.');
        }

        $preview = is_array($context['preview'] ?? null) ? $context['preview'] : [];
        $settings = $this->settings->resolveTenantSettings($tenantId);
        $config = is_array($settings['config'] ?? null) ? $settings['config'] : [];
        $replacements = $this->templateReplacements($preview, $config);
        $subject = substr($this->replacePlaceholders($subject, $replacements), 0, 255);
        $messageBody = substr($this->replacePlaceholders($messageBody, $replacements), 0, 5000);

        try {
            $mailer = Services::email(null, false);
            $mailer->clear(true);
            $emailConfig = config(EmailConfig::class);
            $fromEmail = trim((string) ($emailConfig->fromEmail ?? '')) ?: 'noreply@ambulatoriofacile.it';
            $fromName = trim((string) ($emailConfig->fromName ?? '')) ?: 'AmbulatorioFacile';
            $mailer->setFrom($fromEmail, $fromName);
            $mailer->setTo($recipient);
            $mailer->setSubject($subject);
            $mailer->setMailType('html');
            $mailer->setMessage($this->buildHtmlMessage($messageBody, $preview));
            $mailer->setAltMessage($messageBody);

            if (!empty($context['attach_pdf'])) {
                $pdf = $this->renderPdf($preview);
                $documentNumber = preg_replace('/[^A-Za-z0-9_-]+/', '_', (string) ($preview['document']['document_number'] ?? 'fattura'));
                $mailer->attach($pdf, 'attachment', 'fattura_' . trim((string) $documentNumber, '_') . '.pdf', 'application/pdf');
            }

            if (!$mailer->send()) {
                $debug = trim(strip_tags((string) $mailer->printDebugger(['headers', 'subject'])));
                throw new \RuntimeException($debug !== '' ? $debug : 'Invio SMTP non riuscito.');
            }

            $this->documents->recordEmailDeliveryForTenant(
                $tenantId,
                $documentId,
                $deliveryType,
                $recipient,
                $subject,
                $messageBody,
                true,
                '',
                $userId
            );

            return [
                'sent' => true,
                'recipient' => $recipient,
                'delivery_type' => $deliveryType,
            ];
        } catch (\Throwable $e) {
            try {
                $this->documents->recordEmailDeliveryForTenant(
                    $tenantId,
                    $documentId,
                    $deliveryType,
                    $recipient,
                    $subject,
                    $messageBody,
                    false,
                    $e->getMessage(),
                    $userId
                );
            } catch (\Throwable $logError) {
                log_message('error', 'BillingDocumentEmailService email log failed: {message}', [
                    'message' => $logError->getMessage(),
                    'tenant_id' => $tenantId,
                    'document_id' => $documentId,
                ]);
            }

            log_message('error', 'BillingDocumentEmailService send failed: {message}', [
                'message' => $e->getMessage(),
                'tenant_id' => $tenantId,
                'document_id' => $documentId,
                'delivery_type' => $deliveryType,
            ]);

            throw new \RuntimeException('Invio email non riuscito. Verifica la configurazione email dello spazio e riprova.');
        }
    }

    /**
     * @param array<string, mixed> $preview
     * @param array<string, mixed> $config
     * @return array<string, string>
     */
    private function templateReplacements(array $preview, array $config): array
    {
        $document = is_array($preview['document'] ?? null) ? $preview['document'] : [];
        $tenant = is_array($preview['tenant'] ?? null) ? $preview['tenant'] : [];
        $fiscalData = is_array($config['fiscal_data'] ?? null) ? $config['fiscal_data'] : [];
        $studio = trim((string) ($fiscalData['business_name'] ?? ''));
        if ($studio === '') {
            $studio = trim((string) ($tenant['tenant_name'] ?? 'AmbulatorioFacile'));
        }

        return [
            '{paziente}' => trim((string) ($document['patient_name'] ?? 'Paziente')),
            '{numero}' => trim((string) ($document['document_number'] ?? '')),
            '{data_emissione}' => $this->formatDate((string) ($document['issue_date'] ?? '')),
            '{scadenza}' => $this->formatDate((string) ($document['due_date'] ?? ''), 'non indicata'),
            '{totale}' => '€ ' . number_format((float) ($document['amount_total'] ?? 0), 2, ',', '.'),
            '{modalita_pagamento}' => trim((string) ($preview['payment_method_label'] ?? '')),
            '{studio}' => $studio,
        ];
    }

    /**
     * @param array<string, string> $replacements
     */
    private function replacePlaceholders(string $text, array $replacements): string
    {
        return strtr($text, $replacements);
    }

    private function formatDate(string $date, string $fallback = ''): string
    {
        $date = trim($date);
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);

        return $parsed instanceof \DateTimeImmutable ? $parsed->format('d/m/Y') : $fallback;
    }

    /**
     * @param array<string, mixed> $preview
     */
    private function buildHtmlMessage(string $messageBody, array $preview): string
    {
        $document = is_array($preview['document'] ?? null) ? $preview['document'] : [];
        $safeBody = nl2br(htmlspecialchars($messageBody, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
        $documentNumber = htmlspecialchars((string) ($document['document_number'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return '<div style="font-family:Arial,sans-serif;color:#263c42;line-height:1.6;max-width:680px">'
            . '<div style="border-left:4px solid #2c8895;padding:4px 0 4px 16px;margin-bottom:22px">'
            . '<strong style="color:#1d6770">Documento ' . $documentNumber . '</strong>'
            . '</div><div>' . $safeBody . '</div></div>';
    }

    /**
     * @param array<string, mixed> $preview
     */
    private function renderPdf(array $preview): string
    {
        if (!class_exists(Dompdf::class)) {
            throw new \RuntimeException('Generatore PDF non disponibile.');
        }

        $options = new Options();
        $options->set('isRemoteEnabled', true);
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml(view('admin/billing/document_pdf', ['preview' => $preview]), 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        return $dompdf->output();
    }
}

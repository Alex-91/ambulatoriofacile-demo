<?php

namespace App\Services;

use App\Config\Fse2;

/** Fail-closed local validation. The worker receives no private signing keys. */
class FseArtifactValidationService
{
    public function __construct(private ?Fse2 $config = null)
    {
        $this->config ??= config(Fse2::class);
    }

    public function buildPdf(string $cda): string
    {
        $result = $this->run(['operation' => 'build', 'cda' => base64_encode($cda)]);
        $pdf = base64_decode((string) ($result['pdf'] ?? ''), true);
        if (!is_string($pdf) || !str_starts_with($pdf, '%PDF-') || strlen($pdf) > $this->config->maxPdfBytes
            || !hash_equals(hash('sha256', $cda), (string) ($result['cda_sha256'] ?? ''))
            || !hash_equals(hash('sha256', $pdf), (string) ($result['pdf_sha256'] ?? ''))
            || ($result['pdfa'] ?? '') !== '3b') {
            throw new \RuntimeException('Risposta del generatore PDF/A FSE non verificabile.');
        }
        return $pdf;
    }

    /** @return array<string,mixed> Safe technical evidence only. */
    public function check(string $cda, string $pdf, ?string $unsigned = null, string $authorCf = ''): array
    {
        $job = ['operation' => $unsigned === null ? 'pdf' : 'signed', 'cda' => base64_encode($cda), 'pdf' => base64_encode($pdf)];
        if ($unsigned !== null) {
            $job['unsigned'] = base64_encode($unsigned);
            $job['author_cf'] = strtoupper(trim($authorCf));
        }
        $result = $this->run($job);
        if (!hash_equals(hash('sha256', $cda), (string) ($result['cda_sha256'] ?? ''))
            || !hash_equals(hash('sha256', $pdf), (string) ($result['pdf_sha256'] ?? ''))
            || ($result['pdfa'] ?? '') !== '3b'
            || ($unsigned !== null && ($result['signature'] ?? '') !== 'valid')) {
            throw new \RuntimeException('Esito dei controlli documentali FSE non verificabile.');
        }
        return array_intersect_key($result, array_flip(['cda_sha256', 'pdf_sha256', 'pdfa', 'catalog_revision',
            'signature', 'signer_certificate_sha256', 'trust_policy', 'qualified_signature']));
    }

    /** Recheck stored artifacts, including old records created before the strict validator. */
    public function assertForDispatch(int $tenantId, int $documentId, array $document, bool $signed): array
    {
        $cda = $this->storedArtifact($tenantId, $documentId, $document, 'cda');
        $unsigned = $this->storedArtifact($tenantId, $documentId, $document, 'unsigned_pdf');
        $pdf = $signed ? $this->storedArtifact($tenantId, $documentId, $document, 'signed_pdf') : $unsigned;
        return $this->check($cda, $pdf, $signed ? $unsigned : null, (string) ($document['author_cf'] ?? ''));
    }

    public function storedArtifact(int $tenantId, int $documentId, array $document, string $kind): string
    {
        if (!in_array($kind, ['cda', 'unsigned_pdf', 'signed_pdf'], true)) throw new \InvalidArgumentException('Artefatto FSE sconosciuto.');
        $path = (new FseStorageService($this->config))->assertStoredPath((string) ($document[$kind . '_path'] ?? ''), $tenantId, $documentId);
        $contents = file_get_contents($path, false, null, 0, $this->config->maxPdfBytes + 1);
        if (!is_string($contents) || strlen($contents) > $this->config->maxPdfBytes
            || !hash_equals((string) ($document[$kind . '_sha256'] ?? ''), hash('sha256', $contents))) {
            throw new \RuntimeException('Integrità dell’artefatto FSE non verificata: rigenerare la bozza o richiedere assistenza.');
        }
        return $contents;
    }

    public function isConfigured(): bool
    {
        return is_file($this->config->validatorPython) && is_file($this->config->validatorSettings)
            && function_exists('proc_open');
    }

    /** Technical-only self-test: never use a tenant's clinical data or contact a Gateway. */
    public function readiness(bool $deep = false): array
    {
        if (!$this->isConfigured()) return ['runtime' => 'missing', 'artifacts' => 'not_tested', 'trust_material' => 'not_tested'];
        try {
            $job = ['operation' => $deep ? 'health' : 'readiness'];
            if ($deep) $job['cda'] = base64_encode((new FseCdaRsaBuilderService())->build(FseSyntheticDocument::data()));
            $result = $this->run($job);
            // Even administrator-owned worker output is reduced to a fixed diagnostic vocabulary.
            return [
                'runtime' => 'configured',
                'artifacts' => $deep && ($result['artifacts'] ?? '') === 'passed' ? 'passed' : 'not_tested',
                'trust_material' => in_array($result['trust_material'] ?? '', ['configured', 'missing', 'invalid'], true) ? $result['trust_material'] : 'invalid',
                'qualified_signature' => 'not_assessed',
            ];
        } catch (\Throwable $e) {
            return ['runtime' => 'error', 'artifacts' => 'failed', 'trust_material' => 'not_tested'];
        }
    }

    /** @param array<string,string> $job @return array<string,mixed> */
    protected function run(array $job): array
    {
        if (!$this->isConfigured()) {
            throw new \RuntimeException('Validatore locale FSE non configurato: generazione, firma e invio bloccati.');
        }
        $job['settings'] = $this->config->validatorSettings;
        $jobs = new FseValidationJobs();
        $slot = $jobs->acquire($this->config->validatorMaxConcurrent);
        try {
            FseValidationJobs::assertAvailableMemory($this->config->validatorMinAvailableMiB);
            $active = $jobs->create();
        }
        catch (\Throwable $e) { FseValidationJobs::release($slot); throw $e; }
        $directory = $active['directory'];
        $paths = [$directory . '/input.json', $directory . '/output.json', $directory . '/stderr.txt'];
        $process = null;
        try {
            $input = json_encode($job, JSON_THROW_ON_ERROR);
            if (strlen($input) > 64 * 1024 * 1024) throw new \RuntimeException('Dimensione del job FSE non consentita.');
            foreach ($paths as $path) {
                if (file_put_contents($path, '') === false) throw new \RuntimeException('Area privata di validazione FSE non scrivibile.');
                chmod($path, 0600);
            }
            if (file_put_contents($paths[0], $input, LOCK_EX) !== strlen($input)) throw new \RuntimeException('Scrittura job FSE incompleta.');
            $script = dirname(APPPATH, 2) . '/ops/fse-validation/validator.py';
            // Array argv + no shell; sensitive input is in a private file, never on the command line.
            $process = proc_open([$this->config->validatorPython, '-I', '-B', $script],
                [0 => ['file', $paths[0], 'r'], 1 => ['file', $paths[1], 'w'], 2 => ['file', $paths[2], 'w']],
                $pipes, dirname($script), FseValidatorEnvironment::build(), ['bypass_shell' => true]);
            if (!is_resource($process)) throw new \RuntimeException('Avvio del validatore FSE non riuscito.');
            $deadline = microtime(true) + $this->config->validatorTimeout;
            do {
                $status = proc_get_status($process);
                clearstatcache();
                if (microtime(true) > $deadline || filesize($paths[1]) > 24 * 1024 * 1024 || filesize($paths[2]) > 1048576) {
                    proc_terminate($process);
                    throw new \RuntimeException('Controllo documentale FSE incompleto o scaduto: operazione bloccata.');
                }
                if ($status['running']) usleep(20000);
            } while ($status['running']);
            $exit = (int) $status['exitcode'];
            proc_close($process);
            $process = null;
            $result = json_decode((string) file_get_contents($paths[1]), true);
            if ($exit !== 0 || !is_array($result) || ($result['ok'] ?? null) !== true) {
                $code = is_string($result['code'] ?? null) && preg_match('/^[A-Z0-9_]{1,80}$/D', $result['code']) ? $result['code'] : 'VALIDATOR_UNAVAILABLE';
                $rules = array_filter((array) ($result['issues'] ?? []), static fn($r) => is_string($r) && preg_match('/^[A-Za-z0-9_-]{1,40}$/D', $r));
                throw new \RuntimeException('Controlli FSE non superati: ' . $code . ($rules ? ' (' . implode(', ', array_slice($rules, 0, 8)) . ')' : '') . '.');
            }
            return $result;
        } finally {
            if (is_resource($process)) { proc_terminate($process); proc_close($process); }
            FseValidationJobs::release($active['lease']);
            // Windows/antivirus may briefly hold worker output handles after termination.
            // Never mask a failed validation with a cleanup warning or remove unrelated files.
            for ($attempt = 0; $attempt < 3; $attempt++) {
                clearstatcache();
                foreach ($paths as $path) if (is_file($path)) @unlink($path);
                // Retain the marker if payload cleanup failed: safe retention can retry later.
                if (!array_filter($paths, 'file_exists') && is_file($directory . '/.active.lock')) @unlink($directory . '/.active.lock');
                if (@rmdir($directory) || !is_dir($directory)) break;
                usleep(50000);
            }
            if (is_dir($directory)) log_message('warning', 'FSE validator private temporary cleanup incomplete; operator retention check required.');
            FseValidationJobs::release($slot);
        }
    }
}

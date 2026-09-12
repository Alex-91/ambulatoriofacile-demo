<?php

namespace App\Services;

use App\Config\Fse2;
use App\Models\PlatformTenantFseProfilesModel;

class FseHealthcheckService
{
    private FseProfileService $profiles;
    private PlatformTenantFseProfilesModel $model;
    private Fse2 $config;
    private FseArtifactValidationService $validation;

    public function __construct(?FseProfileService $profiles = null, ?PlatformTenantFseProfilesModel $model = null, ?Fse2 $config = null, ?FseArtifactValidationService $validation = null)
    {
        $this->profiles = $profiles ?? new FseProfileService();
        $this->model = $model ?? new PlatformTenantFseProfilesModel();
        $this->config = $config ?? config(Fse2::class);
        $this->validation = $validation ?? new FseArtifactValidationService($this->config);
    }

    /** @return array<string,mixed> */
    public function runForTenant(int $tenantId, bool $deep = true, bool $persist = true, int $profileId = 0): array
    {
        $checks = [];
        foreach (['curl', 'openssl', 'dom'] as $extension) {
            $checks[] = $this->check('PHP ' . $extension, extension_loaded($extension), 'Estensione disponibile', 'Estensione mancante');
        }
        $readiness = $this->validation->readiness($deep);
        $checks[] = $this->check('Runtime documentale', $readiness['runtime'] === 'configured',
            'Runtime locale disponibile', 'Runtime non configurato o non funzionante');
        $checks[] = ['label' => 'Autotest CDA / PDF/A-3b', 'status' => $readiness['artifacts'] === 'passed' ? 'ok' : ($deep ? 'error' : 'warning'),
            'message' => $readiness['artifacts'] === 'passed' ? 'XSD, Schematron e veraPDF superati su documento sintetico. Non è un collaudo ufficiale.' : ($deep ? 'Autotest non superato: controllare il runtime.' : 'Eseguire il controllo completo. Nessun documento clinico viene usato.')];
        $trust = $readiness['trust_material'] ?? 'not_tested';
        $checks[] = ['label' => 'Fiducia e revoca della firma', 'status' => $trust === 'configured' ? 'warning' : 'error',
            'message' => $trust === 'configured' ? 'Materiale fiduciario presente e leggibile; catena, revoca e identità sono verificate su ogni firma.' : 'Mancano materiali fiduciari/revoche validi: la firma resta bloccata.'];
        $checks[] = ['label' => 'Qualifica della firma', 'status' => 'warning', 'message' => 'Qualifica eIDAS non verificata automaticamente: da definire con il servizio di firma effettivo.'];
        try {
            $profile = $this->profiles->runtimeProfileForTenant($tenantId, $profileId);
            $checks[] = $this->check('Profilo', true, 'Profilo presente', '');
        } catch (\Throwable $e) {
            $profile = null;
            $checks[] = $this->check('Profilo', false, '', 'Profilo non disponibile o incompleto.');
        }

        if (is_array($profile)) {
            $state = $profile;
            $state['author_cf'] = (string) ($profile['author_cf'] ?? '');
            foreach ($this->profiles->validate($state, !empty($profile['is_enabled'])) as $error) {
                $checks[] = $this->check('Configurazione', false, '', $error);
            }
            $checks[] = $this->certificatePairCheck($profile, 'mTLS', 'auth_certificate_path', 'auth_private_key_path', 'auth_private_key_passphrase');
            $checks[] = $this->certificatePairCheck($profile, 'JWT', 'signature_certificate_path', 'signature_private_key_path', 'signature_private_key_passphrase');
            $checks[] = ['label' => 'Interruttore invii', 'status' => 'warning', 'message' => !empty($profile['is_enabled'])
                ? 'Attivo in configurazione: non equivale ad accreditamento o operatività.' : 'Disattivato: nessun invio dal profilo.'];
            if (($profile['access_mode'] ?? '') === 'toscana_privati') {
                $checks[] = ['label' => 'Percorso Toscana', 'status' => 'warning', 'message' => 'Invii operativi bloccati nel codice; simulazione offline disponibile.'];
            }
        }
        $checks[] = ['label' => 'Accreditamento nazionale', 'status' => 'warning', 'message' => 'Non attestato da questo controllo. Necessaria conferma ufficiale per prodotto e versione.'];
        $checks[] = ['label' => 'Abilitazione regionale / struttura', 'status' => 'warning', 'message' => 'Da verificare con la Regione per struttura, impianto e regime. Non ereditata dai certificati.'];

        $hasError = count(array_filter($checks, static fn(array $c): bool => $c['status'] === 'error')) > 0;
        $hasWarning = count(array_filter($checks, static fn(array $c): bool => $c['status'] === 'warning')) > 0;
        $status = $hasError ? 'error' : ($hasWarning ? 'warning' : 'ok');
        $message = $status === 'ok' ? 'Configurazione locale FSE pronta.' : ($status === 'warning'
            ? 'Controlli locali eseguiti; restano verifiche e abilitazioni esterne.'
            : 'Configurazione FSE incompleta o non valida.');

        if ($persist && is_array($profile) && (int) ($profile['id_fse_profile'] ?? 0) > 0) {
            $this->model->update((int) $profile['id_fse_profile'], [
                'last_check_status' => $status,
                'last_check_message' => $message,
                'last_check_at' => date('Y-m-d H:i:s'),
            ]);
        }
        return ['status' => $status, 'message' => $message, 'checks' => $checks, 'operational_ready' => false, 'deep' => $deep];
    }

    /** @return array{label:string,status:string,message:string} */
    private function check(string $label, bool $ok, string $success, string $failure): array
    {
        return ['label' => $label, 'status' => $ok ? 'ok' : 'error', 'message' => $ok ? $success : $failure];
    }

    /** @param array<string,mixed> $profile @return array{label:string,status:string,message:string} */
    private function certificatePairCheck(array $profile, string $label, string $certField, string $keyField, string $passphraseField): array
    {
        if (trim((string) ($profile[$certField] ?? '')) === '' || trim((string) ($profile[$keyField] ?? '')) === '') {
            return ['label' => 'Coppia ' . $label, 'status' => 'warning', 'message' => 'Non configurata: necessaria per il Gateway.'];
        }
        try {
            $certPath = $this->config->resolveCertificatePath((string) $profile[$certField]);
            $keyPath = $this->config->resolveCertificatePath((string) $profile[$keyField]);
            $certPem = is_readable($certPath) ? file_get_contents($certPath) : false;
            $keyPem = is_readable($keyPath) ? file_get_contents($keyPath) : false;
            if (!is_string($certPem) || !is_string($keyPem)) throw new \RuntimeException('file non leggibili');
            $cert = openssl_x509_read($certPem);
            $key = openssl_pkey_get_private($keyPem, (string) ($profile[$passphraseField] ?? ''));
            if ($cert === false || $key === false) throw new \RuntimeException('formato PEM o passphrase non validi');
            if (!openssl_x509_check_private_key($cert, $key)) throw new \RuntimeException('certificato e chiave non corrispondono');
            $parsed = openssl_x509_parse($cert);
            if ((int) ($parsed['validFrom_time_t'] ?? PHP_INT_MAX) > time()) throw new \RuntimeException('certificato non ancora valido');
            if ((int) ($parsed['validTo_time_t'] ?? 0) <= time()) throw new \RuntimeException('certificato scaduto');
            if ((int) $parsed['validTo_time_t'] < time() + 30 * 86400) return ['label' => 'Coppia ' . $label, 'status' => 'warning', 'message' => 'Certificato in scadenza entro 30 giorni: pianificare il rinnovo.'];
            return $this->check('Coppia ' . $label, true, 'Certificato valido e chiave corrispondente', '');
        } catch (\Throwable $e) {
            return $this->check('Coppia ' . $label, false, '', $e->getMessage());
        }
    }
}

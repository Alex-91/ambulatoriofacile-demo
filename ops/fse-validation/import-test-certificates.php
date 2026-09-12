<?php
// CLI only. No framework, .env, database, network or machine trust-store changes.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../../rest/app/Services/FseCertificateInspector.php';

use App\Services\FseCertificateInspector;

try {
    if ($argc !== 2 || !is_file($argv[1])) throw new RuntimeException('Uso: php import-test-certificates.php <zip-Sogei>');
    if (filesize($argv[1]) > 1048576) throw new RuntimeException('Archivio certificati troppo grande.');
    $root = realpath(__DIR__ . '/../.local/fse-accreditamento/x509');
    if ($root === false || is_link($root)) throw new RuntimeException('Directory privata CSR non disponibile.');
    $zip = new ZipArchive();
    if ($zip->open($argv[1], ZipArchive::RDONLY) !== true) throw new RuntimeException('Archivio ZIP non valido.');
    $certificates = [];
    try {
        if ($zip->numFiles !== 2) throw new RuntimeException('Attesi solo due certificati pubblici nel pacchetto.');
        for ($i = 0; $i < $zip->numFiles; ++$i) {
            $entry = $zip->statIndex($i);
            if (!is_array($entry) || !preg_match('/^([AS])1#111#([A-Z0-9_-]+)\.pem$/D', $entry['name'], $match)
                || $entry['size'] > 32768 || $entry['size'] < 100) throw new RuntimeException('Nome o dimensione certificato non ammessi.');
            $role = $match[1] === 'A' ? 'auth' : 'sign';
            if (isset($certificates[$role])) throw new RuntimeException('Ruolo certificato duplicato.');
            $pem = $zip->getFromIndex($i);
            if (!is_string($pem)) throw new RuntimeException('Certificato non leggibile.');
            $key = file_get_contents($root . '/ambulatoriofacile-' . $role . '-private.key');
            $csr = file_get_contents($root . '/ambulatoriofacile-' . $role . '.csr');
            if (!is_string($key) || !is_string($csr)) throw new RuntimeException('CSR o chiave originale non disponibile.');
            $metadata = FseCertificateInspector::inspect($pem, $key, '', $csr);
            if ($metadata['common_name'] !== substr($entry['name'], 0, -4)
                || $metadata['issuer_common_name'] !== 'CA Ministero della Salute Test') throw new RuntimeException('Identità test non coerente con il pacchetto.');
            $certificates[$role] = ['pem' => $pem, 'metadata' => $metadata, 'identity' => $match[2]];
        }
    } finally { $zip->close(); }
    if (!isset($certificates['auth'], $certificates['sign']) || $certificates['auth']['identity'] !== $certificates['sign']['identity']) {
        throw new RuntimeException('Coppie autenticazione/firma non coerenti.');
    }
    if (!str_contains($certificates['auth']['metadata']['extended_key_usage'], 'TLS Web Client Authentication')
        || !str_contains($certificates['sign']['metadata']['key_usage'], 'Non Repudiation')) {
        throw new RuntimeException('Utilizzi dei certificati di test non coerenti.');
    }
    $archiveHash = hash_file('sha256', $argv[1]);
    $directory = $root . '/received-' . substr($archiveHash, 0, 16);
    // Do not overwrite previous imports; private keys remain in their original location.
    if (file_exists($directory)) throw new RuntimeException('Pacchetto già importato: ' . basename($directory));
    if (!mkdir($directory, 0700)) throw new RuntimeException('Creazione directory privata non riuscita.');
    $report = ['mode' => 'SOGEI_TEST_CREDENTIALS', 'received_at' => gmdate('c'), 'archive_sha256' => $archiveHash,
        'official_accreditation_evidence' => false, 'regional_authorization' => 'NOT_RECEIVED',
        'issuer_chain_validation' => 'NOT_PERFORMED', 'revocation_validation' => 'NOT_PERFORMED', 'certificates' => []];
    foreach ($certificates as $role => $item) {
        $path = $directory . '/' . $role . '.pem';
        if (file_put_contents($path, $item['pem'], LOCK_EX) !== strlen($item['pem'])) throw new RuntimeException('Scrittura certificato non riuscita.');
        $report['certificates'][$role] = $item['metadata'];
    }
    $reportPath = $directory . '/receipt.json';
    $json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    if (file_put_contents($reportPath, $json, LOCK_EX) !== strlen($json)) throw new RuntimeException('Scrittura ricevuta non riuscita.');
    echo json_encode(['status' => 'CERTIFICATE_KEY_CSR_MATCHED', 'directory' => $directory,
        'expires_at' => $report['certificates']['auth']['valid_to'], 'official_accreditation_evidence' => false], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}

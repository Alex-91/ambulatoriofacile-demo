<?php
namespace App\Services;

/** Private encrypted blobs. Object names never come from user-supplied paths. */
class ClinicalVault
{
    public const MAX_BYTES = 15728640;
    public function __construct(private int $tenantId, private ?FseSecretsService $crypto = null, private ?string $root = null)
    {
        if ($tenantId <= 0) throw new \InvalidArgumentException('Spazio clinico non valido.');
        $this->crypto ??= new FseSecretsService();
        $this->root ??= rtrim(WRITEPATH, '/\\') . '/clinical-private';
    }
    public function seal(string $scope, string $value): string
    {
        return (string) $this->crypto->encrypt(json_encode(['tenant'=>$this->tenantId,'scope'=>$scope,'value'=>base64_encode($value)], JSON_THROW_ON_ERROR));
    }
    public function open(string $scope, string $cipher): string
    {
        $data = json_decode((string) $this->crypto->decrypt($cipher), true, 512, JSON_THROW_ON_ERROR);
        if (($data['tenant'] ?? null) !== $this->tenantId || ($data['scope'] ?? null) !== $scope) throw new \RuntimeException('Contenuto clinico non appartenente al contesto richiesto.');
        $value = base64_decode($data['value'] ?? '', true);
        if ($value === false) throw new \RuntimeException('Contenuto clinico non leggibile.');
        return $value;
    }
    public function put(string $id, string $bytes): string
    {
        if ($bytes === '' || strlen($bytes) > self::MAX_BYTES) throw new \InvalidArgumentException('Dimensione documento non consentita (massimo 15 MB).');
        $path = $this->path($id);
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) throw new \RuntimeException('Archivio clinico non disponibile.');
        if (is_link($dir) || is_link($path)) throw new \RuntimeException('Percorso archivio non ammesso.');
        $file = fopen($path, 'xb');
        if ($file === false) throw new \RuntimeException('Documento già presente o archivio non scrivibile.');
        try {
            $cipher = $this->seal('object:' . $id, $bytes);
            if (fwrite($file, $cipher) !== strlen($cipher)) throw new \RuntimeException('Scrittura documento incompleta.');
            fflush($file);
        } finally { fclose($file); }
        @chmod($path, 0600);
        return hash('sha256', $bytes);
    }
    public function get(string $id, string $sha): string
    {
        $path = $this->path($id);
        if (!is_file($path) || is_link($path) || is_link(dirname($path))) throw new \RuntimeException('Documento non disponibile.');
        $cipher = file_get_contents($path, false, null, 0, self::MAX_BYTES * 2 + 4096);
        if (!is_string($cipher) || strlen($cipher) > self::MAX_BYTES * 2) throw new \RuntimeException('Archivio documento non valido.');
        $bytes = $this->open('object:' . $id, $cipher);
        if (!hash_equals($sha, hash('sha256', $bytes))) throw new \RuntimeException('Integrità documento non verificata.');
        return $bytes;
    }
    private function path(string $id): string
    {
        if (!preg_match('/^[a-f0-9]{32}$/D', $id)) throw new \InvalidArgumentException('Identificativo documento non valido.');
        return $this->root . '/' . $this->tenantId . '/' . $id . '.bin';
    }
}

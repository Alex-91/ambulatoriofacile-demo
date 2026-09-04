<?php

namespace App\Services;

use App\Config\Fse2;

class FseStorageService
{
    private Fse2 $config;

    public function __construct(?Fse2 $config = null)
    {
        $this->config = $config ?? config(Fse2::class);
    }

    public function store(int $tenantId, int $documentId, string $name, string $contents): string
    {
        if ($tenantId <= 0 || $documentId <= 0 || !preg_match('/^[a-z0-9._-]+$/i', $name)) {
            throw new \InvalidArgumentException('Percorso archivio FSE non valido.');
        }
        $directory = rtrim($this->config->tenantStorageRoot, '\\/') . DIRECTORY_SEPARATOR . $tenantId
            . DIRECTORY_SEPARATOR . $this->config->tenantStorageSegment . DIRECTORY_SEPARATOR . $documentId;
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new \RuntimeException('Impossibile creare l’archivio privato FSE.');
        }
        $path = $directory . DIRECTORY_SEPARATOR . $name;
        if (file_put_contents($path, $contents, LOCK_EX) === false) {
            throw new \RuntimeException('Scrittura artefatto FSE non riuscita.');
        }
        @chmod($path, 0600);
        return $path;
    }

    public function assertStoredPath(string $path, int $tenantId, int $documentId): string
    {
        $expected = realpath(rtrim($this->config->tenantStorageRoot, '\\/') . DIRECTORY_SEPARATOR . $tenantId
            . DIRECTORY_SEPARATOR . $this->config->tenantStorageSegment . DIRECTORY_SEPARATOR . $documentId);
        $real = realpath($path);
        if ($expected === false || $real === false || !str_starts_with($real, $expected . DIRECTORY_SEPARATOR)) {
            throw new \RuntimeException('Artefatto FSE non accessibile nello spazio corrente.');
        }
        return $real;
    }
}

<?php
namespace App\Services;

/** Tenant-scoped provider preferences. Never stores credentials or enables signing. */
final class ClinicalSignaturePreferences
{
    public const PROVIDERS=[''=>'Da scegliere','aruba'=>'Aruba','infocert'=>'InfoCert','namirial'=>'Namirial','other'=>'Altro servizio'];
    public function __construct(private int $tenantId, private int $userId) {}
    private function path(): string
    {
        (new ClinicalFeatureService())->assertEnabledForTenant($this->tenantId);
        if ($this->tenantId<=0 || $this->userId<0) throw new \RuntimeException('Profilo non valido.');
        return WRITEPATH.'clinical-signature-preferences/'.$this->tenantId.'-'.$this->userId.'.json';
    }
    public function read(): array
    {
        $path=$this->path();
        return is_file($path) ? json_decode(file_get_contents($path),true,512,JSON_THROW_ON_ERROR) : ['provider'=>'','mode'=>'external'];
    }
    public function save(array $input): void
    {
        $provider=(string)($input['provider']??'');$mode=(string)($input['mode']??'');
        if (!array_key_exists($provider,self::PROVIDERS) || !in_array($mode,['external','remote'],true)) throw new \RuntimeException('Selezionare un servizio e una modalità validi.');
        $path=$this->path();$dir=dirname($path);
        if (!is_dir($dir) && !mkdir($dir,0700,true) && !is_dir($dir)) throw new \RuntimeException('Configurazione non salvata.');
        $temp=tempnam($dir,'pref-');
        try {
            if ($temp===false || !chmod($temp,0600) || file_put_contents($temp,json_encode(compact('provider','mode'),JSON_THROW_ON_ERROR),LOCK_EX)===false || !rename($temp,$path)) throw new \RuntimeException('Configurazione non salvata.');
        } finally { if ($temp && is_file($temp)) unlink($temp); }
    }
}

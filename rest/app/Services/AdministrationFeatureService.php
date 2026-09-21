<?php
namespace App\Services;

class AdministrationFeatureService
{
    public const MODULES=['admin_quotes'=>'Preventivi e listini','admin_payers'=>'Convenzioni, assicurazioni e fondi','admin_compensation'=>'Spettanze professionisti','admin_ssn'=>'Registro amministrativo SSN'];
    public function __construct(private ?TenantFeatureService $features=null) {}
    public function enabled(int $tenant,string $module): bool
    {
        if ($tenant<=0 || !isset(self::MODULES[$module])) return false;
        try {
            $map=($this->features ??=new TenantFeatureService())->resolveEffectiveFeatureMapForTenant($tenant);
            if (empty($map['billing']) || empty($map[$module])) return false;
            if (in_array($module,['admin_payers','admin_ssn'],true) && empty($map['admin_quotes'])) return false;
            return true;
        } catch (\Throwable) { return false; }
    }
    public function available(int $tenant): array
    { return array_filter(self::MODULES,fn($label,$key)=>$this->enabled($tenant,$key),ARRAY_FILTER_USE_BOTH); }
    public function assertEnabled(int $tenant,string $module): void
    { if (!$this->enabled($tenant,$module)) throw new AdministrationException('Modulo amministrativo non abilitato per questo spazio.'); }
}

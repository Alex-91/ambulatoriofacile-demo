<?php
namespace App\Services;

/** Capabilities depend on the space's choices, never on its size or specialty. */
final class BillingCapabilities
{
    public const FEATURES=['billing_services'=>'Prestazioni e listini','billing_agreements'=>'Convenzioni e assicurazioni','billing_compensation'=>'Compensi professionisti'];

    public static function resolve(int $tenantId): array
    {
        $map=(new TenantFeatureService())->resolveEffectiveFeatureMapForTenant($tenantId);
        return self::fromMap($map);
    }

    public static function fromMap(array $map): array
    {
        $out=[];
        foreach (self::FEATURES as $key=>$label) $out[$key]=!empty($map['billing']) && !empty($map[$key]);
        return $out;
    }

    public static function assertCommand(array $capabilities,array $input): void
    {
        $action=$input['action']??''; $kind=$input['kind']??'';
        $feature=match ($action) {
            'catalog'=>match($kind) {'agreement'=>'billing_agreements','rule'=>'billing_compensation',default=>null},
            'tariff','arrival','transition','order','remove_order','invoice'=>null,
            'settle'=>'billing_compensation',
            default=>null,
        };
        if (in_array($action,['tariff','arrival','transition','order','remove_order','invoice'],true) && !array_filter($capabilities)) throw new \DomainException('Gestione prestazioni non attiva per questo spazio.');
        if ($action==='catalog' && !in_array($kind,['agreement','rule'],true) && !array_filter($capabilities)) throw new \DomainException('Cataloghi non attivi per questo spazio.');
        if ($feature && empty($capabilities[$feature])) throw new \DomainException('Funzione non attiva per questo spazio.');
        if ($action==='order' && !empty($input['agreement_id']) && empty($capabilities['billing_agreements'])) throw new \DomainException('Convenzioni non attive per questo spazio.');
    }
}

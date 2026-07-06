<?php

namespace App\Commands;

use App\Models\PlatformFeaturesModel;
use App\Models\PlatformTenantFeaturesModel;
use App\Services\TenantCatalogService;
use App\Services\TenantFeatureService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

class TenantFeatureOverride extends BaseCommand
{
    protected $group = 'Platform';
    protected $name = 'tenant:feature-override';
    protected $description = 'Imposta un override feature esplicito per un tenant sul database platform.';
    protected $usage = 'tenant:feature-override <tenant-id> <feature-key> <0|1>';
    protected $arguments = [
        'tenant-id' => 'ID del tenant.',
        'feature-key' => 'Chiave feature, ad esempio ts_billing.',
        '0|1' => '0 per disabilitare, 1 per abilitare.',
    ];

    public function run(array $params)
    {
        $tenantId = (int) ($params[0] ?? 0);
        $featureKey = trim(strtolower((string) ($params[1] ?? '')));
        $enabledRaw = trim((string) ($params[2] ?? ''));

        if ($tenantId <= 0 || $featureKey === '' || !in_array($enabledRaw, ['0', '1'], true)) {
            CLI::error('Uso: php spark tenant:feature-override <tenant-id> <feature-key> <0|1>');
            return EXIT_ERROR;
        }

        $tenantCatalog = new TenantCatalogService();
        $featuresModel = new PlatformFeaturesModel();
        $tenantFeaturesModel = new PlatformTenantFeaturesModel();
        $tenantFeatureService = new TenantFeatureService();

        $tenant = $tenantCatalog->getTenantById($tenantId);
        if (!is_array($tenant) || (int) ($tenant['id_tenant'] ?? 0) <= 0) {
            CLI::error('Tenant non trovato.');
            return EXIT_ERROR;
        }

        $feature = $featuresModel->findByKey($featureKey);
        if (!is_array($feature) || (int) ($feature['id_feature'] ?? 0) <= 0) {
            CLI::error('Feature non trovata: ' . $featureKey);
            return EXIT_ERROR;
        }

        $enabled = $enabledRaw === '1';
        $saved = $tenantFeaturesModel->setOverride(
            $tenantId,
            (int) ($feature['id_feature'] ?? 0),
            $enabled,
            null,
            'cli_override'
        );

        if (!$saved) {
            CLI::error('Override feature non salvato.');
            return EXIT_ERROR;
        }

        $effectiveMap = $tenantFeatureService->resolveEffectiveFeatureMapForTenant($tenantId);
        $effectiveState = !empty($effectiveMap[$featureKey]);

        CLI::write(
            'Tenant: ' . trim((string) ($tenant['tenant_name'] ?? $tenant['tenant_key'] ?? $tenantId)),
            'green'
        );
        CLI::write(
            'Feature ' . $featureKey . ': override impostato a ' . ($enabled ? '1' : '0')
                . ', stato effettivo ' . ($effectiveState ? 'attivo' : 'disattivo') . '.',
            $effectiveState ? 'green' : 'yellow'
        );

        return EXIT_SUCCESS;
    }
}

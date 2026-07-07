<?php

namespace App\Services;

use App\Libraries\TenantContext;

class BillingTsModuleStatusService
{
    private BillingFeatureService $billingFeatures;
    private TsFeatureService $tsFeatures;

    public function __construct(
        ?BillingFeatureService $billingFeatures = null,
        ?TsFeatureService $tsFeatures = null
    ) {
        $this->billingFeatures = $billingFeatures ?? new BillingFeatureService();
        $this->tsFeatures = $tsFeatures ?? new TsFeatureService();
    }

    /**
     * @return array<string, mixed>
     */
    public function describe(?TenantContext $context = null, int $tenantId = 0): array
    {
        $billingEnabled = false;
        $billingLocalBypass = false;
        $tsEnabled = false;
        $tsLocalBypass = false;

        if ($context !== null && $context->isValid()) {
            $billingEnabled = $this->billingFeatures->isEnabledForContext($context);
            $billingLocalBypass = !$context->allows($this->billingFeatures->featureKey())
                && $this->billingFeatures->allowsLocalTestingBypass($context);
            $tsEnabled = $this->tsFeatures->isEnabledForContext($context);
            $tsLocalBypass = !$tsEnabled && $this->tsFeatures->allowsLocalTestingBypass($context);
            $tsEnabled = $tsEnabled || $tsLocalBypass;
        } elseif ($tenantId > 0) {
            $billingEnabled = $this->billingFeatures->isEnabledForTenant($tenantId);
            $tsEnabled = $this->tsFeatures->isEnabledForTenant($tenantId);
        }

        $integratedEnabled = $billingEnabled && $tsEnabled;
        $modeKey = 'none';
        $modeTitle = 'Moduli non attivi';
        $modeMessage = 'Nessuno dei due moduli risulta attivo per questo spazio.';

        if ($integratedEnabled) {
            $modeKey = 'integrated';
            $modeTitle = 'Modalita integrata';
            $modeMessage = 'Fatturazione e Sistema TS sono attivi insieme: i due moduli restano separati ma possono convivere nello stesso flusso operativo.';
        } elseif ($billingEnabled) {
            $modeKey = 'billing_only';
            $modeTitle = 'Fatturazione standalone';
            $modeMessage = 'La Fatturazione e attiva da sola: lo studio puo gestire documenti cliente in autonomia e collegare il Sistema TS in un secondo momento.';
        } elseif ($tsEnabled) {
            $modeKey = 'ts_only';
            $modeTitle = 'Sistema TS standalone';
            $modeMessage = 'Il Sistema TS e attivo da solo: lo studio puo continuare a preparare e inviare documenti TS anche senza il modulo Fatturazione.';
        }

        return [
            'billing_enabled' => $billingEnabled,
            'billing_local_bypass' => $billingLocalBypass,
            'ts_enabled' => $tsEnabled,
            'ts_local_bypass' => $tsLocalBypass,
            'integrated_enabled' => $integratedEnabled,
            'mode_key' => $modeKey,
            'mode_title' => $modeTitle,
            'mode_message' => $modeMessage,
        ];
    }
}

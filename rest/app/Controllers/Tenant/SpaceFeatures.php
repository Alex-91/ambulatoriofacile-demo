<?php

namespace App\Controllers\Tenant;

use App\Controllers\BaseController;
use App\Services\BillingFeatureService;
use App\Models\AgendaModel;
use App\Services\AgendaTeamColumnColorService;
use App\Services\AgendaProfessionalOrderService;
use App\Services\TenantContextService;
use App\Services\TenantFeatureService;
use App\Services\TsFeatureService;

class SpaceFeatures extends BaseController
{
    private TenantContextService $tenantContext;

    public function __construct()
    {
        helper('portal');
        $this->tenantContext = new TenantContextService();
    }

    public function index()
    {
        if ($guard = $this->ensureAllowed()) {
            return $guard;
        }

        if (!portal_current_path_matches('login/spazio/funzioni')) {
            return redirect()->to(portal_tenant_space_url('funzioni'));
        }

        $context = $this->tenantContext->getCurrentTenant();
        if ($context === null) {
            return $this->sessionExpiredRedirect();
        }

        $agendaProfessionals = (new AgendaModel())->getAllAgendaProfessionals();
        $professionalOrderService = new AgendaProfessionalOrderService();
        $agendaProfessionalOrderSettings = $professionalOrderService
            ->resolveTenantSettings($context->tenantId, $agendaProfessionals);
        $orderedAgendaProfessionals = $professionalOrderService
            ->sortProfessionals($context->tenantId, $agendaProfessionals, false);
        $teamDayColumnColorSettings = (new AgendaTeamColumnColorService())
            ->resolveTenantSettings($context->tenantId, $orderedAgendaProfessionals);
        $billingFeatureService = new BillingFeatureService();
        $tsFeatureService = new TsFeatureService();

        return view('tenant/space_features', [
            'tenantContext' => $context,
            'featureStates' => (new TenantFeatureService())->listFeatureStatesForTenant($context->tenantId),
            'billingWorkspaceAccessible' => $billingFeatureService->isEnabledForContext($context),
            'tsConfigurationAccessible' => $tsFeatureService->isEnabledForContext($context)
                || $tsFeatureService->allowsLocalTestingBypass($context),
            'agendaProfessionalOrderSettings' => $agendaProfessionalOrderSettings,
            'teamDayColumnColorSettings' => $teamDayColumnColorSettings,
            'success' => session()->getFlashdata('success'),
            'errors' => session()->getFlashdata('errors') ?? [],
        ]);
    }

    public function save()
    {
        if ($guard = $this->ensureAllowed()) {
            return $guard;
        }

        $context = $this->tenantContext->getCurrentTenant();
        if ($context === null) {
            return $this->sessionExpiredRedirect();
        }

        try {
            $tenantManagedFeaturesForm = (int) ($this->request->getPost('tenant_managed_features_form') ?? 0) === 1;
            $enabledFeatures = array_values(array_filter(array_map(
                static fn($value): string => trim(strtolower((string) $value)),
                (array) $this->request->getPost('enabled_features')
            )));
            $teamDayColumnColorForm = (int) ($this->request->getPost('team_day_column_color_form') ?? 0) === 1;
            $teamDayColumnColorsEnabled = (int) ($this->request->getPost('team_day_column_colors_enabled') ?? 0) === 1;
            $agendaProfessionalOrderForm = (int) ($this->request->getPost('agenda_professional_order_form') ?? 0) === 1;
            $agendaProfessionalOrderEnabled = (int) ($this->request->getPost('agenda_professional_order_enabled') ?? 0) === 1;
            $agendaProfessionalOrderIds = array_values(array_filter(array_map(
                static fn($value): int => (int) $value,
                (array) $this->request->getPost('agenda_professional_order_ids')
            ), static fn(int $value): bool => $value > 0));
            $teamDayCustomEnabledMap = (array) $this->request->getPost('team_day_column_color_custom_enabled');
            $teamDayColorValueMap = (array) $this->request->getPost('team_day_column_color_value');
            $teamDayCustomColors = [];

            foreach ($teamDayCustomEnabledMap as $doctorId => $enabledFlag) {
                $doctorId = (int) $doctorId;
                if ($doctorId <= 0 || (int) $enabledFlag !== 1) {
                    continue;
                }

                $teamDayCustomColors[$doctorId] = (string) ($teamDayColorValueMap[$doctorId] ?? '');
            }

            $platformUserId = (int) (session()->get('platform_user_id') ?? 0);
            if ($tenantManagedFeaturesForm) {
                (new TenantFeatureService())->saveTenantManagedFeatures($context->tenantId, $enabledFeatures, $platformUserId);
            }
            $agendaProfessionals = (new AgendaModel())->getAllAgendaProfessionals();
            $professionalOrderService = new AgendaProfessionalOrderService();

            if ($agendaProfessionalOrderForm) {
                $professionalOrderService->saveTenantPreferences(
                    $context->tenantId,
                    $agendaProfessionalOrderEnabled,
                    $agendaProfessionalOrderIds,
                    $agendaProfessionals,
                    $platformUserId
                );

                $agendaProfessionals = $professionalOrderService->sortProfessionals(
                    $context->tenantId,
                    $agendaProfessionals,
                    false
                );
            }

            if ($teamDayColumnColorForm) {
                (new AgendaTeamColumnColorService())->saveTenantPreferences(
                    $context->tenantId,
                    $teamDayColumnColorsEnabled,
                    $teamDayCustomColors,
                    $agendaProfessionals,
                    $platformUserId
                );
            }

            $this->tenantContext->activateTenantForPlatformUser($platformUserId, $context->tenantId);
            return redirect()
                ->to(portal_tenant_space_url('funzioni'))
                ->with('success', 'Funzioni dello spazio aggiornate con successo.');
        } catch (\Throwable $e) {
            log_message('error', 'Tenant\\SpaceFeatures::save failed: ' . $e->getMessage());

            return redirect()
                ->to(portal_tenant_space_url('funzioni'))
                ->withInput()
                ->with('errors', ['generic' => $e->getMessage()]);
        }
    }

    private function ensureAllowed()
    {
        if ((bool) (session()->get('isLoggedInConfirmed') ?? false) !== true) {
            return $this->redirectToLogin();
        }

        $context = $this->tenantContext->getCurrentTenant();
        if ($context === null) {
            return $this->sessionExpiredRedirect();
        }

        if ($context->tenantRole !== 'tenant_master') {
            return redirect()->to(site_url('/'))->with('error', 'Solo il responsabile dello studio può gestire le funzioni dello studio.');
        }

        if ((int) (session()->get('platform_user_id') ?? 0) <= 0) {
            return $this->sessionExpiredRedirect();
        }

        return null;
    }
}

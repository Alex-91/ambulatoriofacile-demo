<?php
namespace App\Filters;
use App\Services\{PersonnelAccessService,TenantContextService,LegacyTenantSessionService,TenantCatalogService,TenantDatabaseConnector};
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\{RequestInterface,ResponseInterface};

/** Check every authenticated/pending request, including API and OTP routes. */
class PersonnelAccessFilter implements FilterInterface
{
    public function before(RequestInterface $request,$arguments=null)
    {
        $s=session(); $me=$s->get('utente_sess');
        $id=(int)($me->id_user ?? $s->get('userId') ?? $s->get('id_user') ?? 0);
        if ($id<=0) return null;
        $context=$s->get(LegacyTenantSessionService::SESSION_KEY_PENDING_RUNTIME) ?: $s->get(TenantContextService::SESSION_KEY);
        $tenantId=(int)($context['tenant_id'] ?? 0);
        try {
            if ($tenantId<=0) {
                $db=\Config\Database::connect();
                if (!$db->tableExists('personnel_access_blocks')) return null;
                $tenantId=(int)((new TenantCatalogService())->resolveCurrentRuntimeTenant()['id_tenant'] ?? 0);
                if ($tenantId<=0) throw new \RuntimeException('Spazio non verificabile.');
            }
            $this->assertAccount($tenantId,$id);
        } catch (\Throwable) {
            $s->remove(array_keys($s->get()));
            $s->destroy();
            helper('portal');
            if ($request->isAJAX()) return service('response')->setStatusCode(403)->setJSON(['success'=>false,'message'=>'Accesso non disponibile. Effettua nuovamente il login.']);
            return redirect()->to(portal_public_access_url('login'));
        }
        return null;
    }
    protected function assertAccount(int $tenantId,int $userId): void
    {
        $tenant=(new TenantCatalogService())->getTenantById($tenantId);
        if (!$tenant || empty($tenant['is_active'])) throw new \RuntimeException('Spazio non attivo.');
        $db=(new TenantDatabaseConnector())->connect($tenant);
        (new PersonnelAccessService($db,$tenantId))->assertActive($userId);
    }
    public function after(RequestInterface $request,ResponseInterface $response,$arguments=null) {}
}

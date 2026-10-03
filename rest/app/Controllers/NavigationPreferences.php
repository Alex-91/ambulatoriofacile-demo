<?php
namespace App\Controllers;
use App\Services\UnifiedMenuService;

final class NavigationPreferences extends BaseController
{
    private function allowed(): bool
    {
        helper('session_auth');
        return session_access_is_confirmed() && (new UnifiedMenuService())->tenantId() > 0;
    }
    public function index()
    {
        if (!$this->allowed()) return redirect()->to(site_url('login'));
        $navigation = new UnifiedMenuService();
        return view('navigation_preferences', ['navigation'=>$navigation,'options'=>$navigation->homeOptions(),'selected'=>$navigation->preferredHome()]);
    }
    public function saveHome()
    {
        if (!$this->allowed()) return $this->response->setStatusCode(403);
        try {
            (new UnifiedMenuService())->setHome((string) $this->request->getPost('home_url'));
            return redirect()->to(site_url('preferenze-navigazione'))->with('success','Pagina iniziale salvata.');
        } catch (\Throwable $e) {
            return redirect()->to(site_url('preferenze-navigazione'))->with('error',$e->getMessage());
        }
    }
    public function saveMenu()
    {
        if (!$this->allowed() || !session_has_tenant_master_access()) return $this->response->setStatusCode(403);
        try {
            (new UnifiedMenuService())->setEnabled((string) $this->request->getPost('legacy') !== '1');
            $target = (string) $this->request->getPost('return_to');
            $base = parse_url(site_url());
            $parsed = parse_url($target);
            if (!is_array($parsed) || ($parsed['host'] ?? '') !== ($base['host'] ?? '') || ($parsed['scheme'] ?? '') !== ($base['scheme'] ?? '') || ($parsed['port'] ?? null) !== ($base['port'] ?? null) || isset($parsed['user']) || isset($parsed['pass'])) $target = site_url('preferenze-navigazione');
            return redirect()->to($target)->with('success','Preferenza menu aggiornata per lo spazio.');
        } catch (\Throwable $e) {
            return redirect()->to(site_url('preferenze-navigazione'))->with('error',$e->getMessage());
        }
    }
}

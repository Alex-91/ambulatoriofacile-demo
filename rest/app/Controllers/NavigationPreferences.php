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
        return view('navigation_preferences', ['menuPreferences'=>$navigation,'options'=>$navigation->homeOptions(),'selected'=>$navigation->preferredHome()]);
    }
    public function landing()
    {
        if (!$this->allowed()) return redirect()->to(site_url('login'));
        try { $target=(new UnifiedMenuService())->preferredHome(); }
        catch (\Throwable $e) { $target=null; log_message('error','Navigation landing fallback: '.$e->getMessage()); }
        return redirect()->to($target ?? site_url('agenda'));
    }
    public function section(string $slug)
    {
        if (!$this->allowed()) return redirect()->to(site_url('login'));
        $menu=new UnifiedMenuService();
        if (!$menu->enabled()) return redirect()->to(site_url('agenda'));
        $name=array_search($slug,UnifiedMenuService::SECTION_SLUGS,true);
        $groups=$menu->groups();
        if ($name===false || empty($groups[$name])) return $this->response->setStatusCode(404)->setBody('Sezione non disponibile per questo spazio e profilo.');
        $tiles=UnifiedMenuService::tiles($name,$groups[$name]);
        $selected=(string)$this->request->getGet('sezione');
        if ($selected!=='' && !isset($tiles[$selected])) return $this->response->setStatusCode(404)->setBody('Sezione non disponibile.');
        // Keep old test bookmarks working without an intermediate chooser page.
        $links=$selected!==''?$tiles[$selected]:$groups[$name];
        return redirect()->to($links[0]['href']);
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

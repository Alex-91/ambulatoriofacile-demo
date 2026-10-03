<?php
namespace App\Controllers\Login;
use App\Controllers\BaseController;
use App\Services\{NavigationLayoutService,PlatformAdminAccessService};
final class PlatformNavigationController extends BaseController
{
    private function allowed(): bool
    {
        return session()->get('isLoggedInConfirmed')===true && (new PlatformAdminAccessService())->canAccessPlatformConsole();
    }
    public function index()
    {
        if(!$this->allowed())return $this->response->setStatusCode(403)->setBody('Area riservata al Super Tenant Master.');
        helper(['portal','form']);
        $scope=max(0,(int)$this->request->getGet('spazio'));
        $tenants=\Config\Database::connect('platform')->table('platform_tenants')->get()->getResultArray();
        if($scope && !in_array($scope,array_map('intval',array_column($tenants,'id_tenant')),true))return $this->response->setStatusCode(404)->setBody('Spazio inesistente.');
        return view('admin/platform_navigation',['layout'=>(new NavigationLayoutService())->read($scope),'tenants'=>$tenants,'icons'=>NavigationLayoutService::ICONS]);
    }
    public function save()
    {
        if(!$this->allowed())return $this->response->setStatusCode(403)->setBody('Area riservata al Super Tenant Master.');
        helper('portal');$scope=max(0,(int)$this->request->getPost('scope'));
        try{
            $nodes=json_decode((string)$this->request->getPost('nodes'),true,64,JSON_THROW_ON_ERROR);
            if(!is_array($nodes))throw new \InvalidArgumentException('Disposizione non valida.');
            (new NavigationLayoutService())->save($scope,(int)$this->request->getPost('version'),(int)$this->request->getPost('globalVersion'),$nodes,(int)session()->get('platform_user_id'),(string)$this->request->getPost('action'));
            return redirect()->to(portal_platform_url('menu').'?spazio='.$scope)->with('success','Disposizione del menu salvata.');
        }catch(\DomainException $e){return $this->response->setStatusCode(409)->setBody(esc($e->getMessage()));}
        catch(\InvalidArgumentException|\JsonException $e){return $this->response->setStatusCode(422)->setBody(esc($e->getMessage()));}
    }
}

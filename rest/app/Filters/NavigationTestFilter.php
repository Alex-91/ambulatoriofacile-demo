<?php
namespace App\Filters;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\{RequestInterface,ResponseInterface};
final class NavigationTestFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments=null)
    {
        if ((string) env('AF_NAVIGATION_TEST','0') !== '1') return null;
        $expected=(string) env('AF_TEST_DB_HOST','');
        if ($expected==='' || (string)env('DB_HOST')!==$expected || (string)env('PLATFORM_DB_HOST')!==$expected || !in_array(strtolower((string)env('TENANT_PROVISIONING_FORCE_RUNTIME_OVERRIDE')),['1','true'],true)) {
            return service('response')->setStatusCode(503)->setBody('Ambiente test non isolato: accesso bloccato.');
        }
        $path=trim($request->getUri()->getPath(),'/');
        if (preg_match('~(?:^|/)(?:reset-demo|job-run|whatsapp-reminders/run|api/whatsapp-gateway|api/smsfactor)(?:/|$)~',$path)) return service('response')->setStatusCode(403)->setBody('Invii esterni disattivati nell’ambiente test.');
        return null;
    }
    public function after(RequestInterface $request,ResponseInterface $response,$arguments=null)
    {
        if ((string)env('AF_NAVIGATION_TEST','0')!=='1') return;
        $response->setHeader('X-Robots-Tag','noindex, nofollow');
        if (!str_contains(strtolower($response->getHeaderLine('Content-Type')),'text/html')) return;
        $body=$response->getBody();
        if (!is_string($body)) return;
        $banner='<div role="status" style="position:relative;z-index:1100;padding:8px 16px;background:#fff2cc;color:#503800;text-align:center;font:14px sans-serif">AMBIENTE TEST · Copia separata dei dati · Invii esterni disattivati</div>';
        $response->setBody(preg_replace('/(<body\b[^>]*>)/i','$1'.$banner,$body,1)??$body);
    }
}

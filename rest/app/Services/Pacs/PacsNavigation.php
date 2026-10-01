<?php
namespace App\Services\Pacs;

final class PacsNavigation
{
    public static function links(int $tenantId,bool $master): array
    {
        $links=[];
        if ($tenantId===4 && $master) {
            $links['Richieste di prova']='cartella-clinica/demo-pacs/richieste';
            $links['Immagini dimostrative']='cartella-clinica/demo-pacs';
        }
        $links['Nuova richiesta']='cartella-clinica/diagnostica/nuova-richiesta';
        $links['Lista diagnostica']='cartella-clinica/diagnostica';
        if ($master) {
            $links['Verifica collegamenti']='cartella-clinica/diagnostica/collegamenti';
            $links['Configurazione PACS']='cartella-clinica/configurazione';
        }
        return $links;
    }
    public static function owns(string $route): bool
    {
        $route=trim($route,'/');
        foreach(['cartella-clinica/demo-pacs','cartella-clinica/diagnostica','cartella-clinica/configurazione'] as $prefix) {
            if ($route===$prefix || str_starts_with($route,$prefix.'/')) return true;
        }
        return false;
    }
}

<?php
namespace App\Services;

/** Reorganizes only links already authorized and visible in the saved menu. */
final class AdministrationNavigation
{
    public const AREAS = ['Fatture e documenti', 'Incassi e scadenze', 'Prestazioni e tariffe', 'Compensi professionisti', 'Sistema TS', 'Report ed esportazioni'];

    public static function destination(string $href): ?array
    {
        $path = preg_replace('~^(?:app|demo|login)/~', '', trim((string) parse_url($href, PHP_URL_PATH), '/'));
        parse_str((string) parse_url($href, PHP_URL_QUERY), $q);
        if (preg_match('~^(?:login/)?spazio/fse2~', $path) || str_starts_with($path, 'admin/fse2')) return ['Esami', str_contains($path, 'spazio/') ? 'Configurazione FSE' : 'FSE 2.0', 90];
        if ($path === 'admin/accettazione') return ['Oggi', 'Accettazione', 1];
        if (preg_match('~^(?:login/)?spazio/(?:sistema-ts|fatturazione-ts)$~', $path)) return ['Sistema TS', 'Configurazione', 30];
        if (preg_match('~^admin/(?:sistema-ts|fatturazione-ts)(.*)$~', $path, $m)) return ['Sistema TS', match ($m[1]) { '' => 'Panoramica', '/documenti' => 'Documenti e invii', '/documenti/nuovo' => 'Nuovo documento TS', '/diagnostica' => 'Diagnostica', default => 'Documenti e invii' }, match ($m[1]) { '/documenti' => 0, '' => 10, '/documenti/nuovo' => 20, default => 40 }];
        if ($path === 'admin/fatturazione/gestione') return match ($q['tab'] ?? '') {
            'documenti' => ['Incassi e scadenze', 'Registro movimenti e liquidazioni', 20],
            'report' => ['Report ed esportazioni', 'Prestazioni e compensi', 10],
            'integrazioni' => ['Report ed esportazioni', 'Esportazioni e collegamenti', 20],
            'catalogo' => match ($q['kind'] ?? '') {
                'service' => ['Prestazioni e tariffe', 'Prestazioni', 0],
                'list' => ['Prestazioni e tariffe', 'Listini', 10],
                'agreement' => ['Prestazioni e tariffe', 'Convenzioni', 20],
                'rule' => ['Compensi professionisti', 'Regole di calcolo', 0],
                default => null,
            }, default => null,
        };
        return match ($path) {
            'admin/fatturazione-documenti' => ['Fatture e documenti', 'Tutte le fatture', 0],
            'admin/fatturazione-strutture' => ['Fatture e documenti', 'Strutture e aziende', 10],
            'admin/fatturazione' => ['Fatture e documenti', 'Panoramica', 20],
            'admin/fatturazione-documento' => ['Fatture e documenti', 'Modello documento', 40],
            'spazio/fatturazione', 'login/spazio/fatturazione' => ['Fatture e documenti', 'Impostazioni', 30],
            'admin/fatturazione-scadenzario' => ['Incassi e scadenze', 'Scadenzario', 0],
            'admin/fatturazione-statistiche' => ['Report ed esportazioni', 'Fatturato', 0],
            'admin/fatturazione-commercialista' => ['Report ed esportazioni', 'Commercialista', 30],
            default => null,
        };
    }

    public static function organize(array $tree): array
    {
        $areas = []; $relocated = [];
        $prune = static function (array $nodes) use (&$prune, &$areas, &$relocated): array {
            $out = [];
            foreach ($nodes as $node) {
                if (isset($node['href'])) {
                    $dest = self::destination($node['href']);
                    if ($dest) {
                        [$area, $label, $order] = $dest;
                        $node['label'] = $label; $node['_order'] = $order;
                        if (in_array($area, self::AREAS, true)) $areas[$area][] = $node;
                        else $relocated[$area][] = $node;
                        continue;
                    }
                } else {
                    $node['children'] = $prune($node['children'] ?? []);
                    if (!$node['children']) continue;
                }
                $out[] = $node;
            }
            return $out;
        };
        $out = $prune($tree); $entries = [];
        foreach (self::AREAS as $area) {
            if (empty($areas[$area])) continue;
            usort($areas[$area], static fn($a,$b) => $a['_order'] <=> $b['_order']);
            $entry = $areas[$area][0]; $entry['label'] = $area;
            $entry['area_links'] = $areas[$area];
            $entries[] = $entry;
        }
        // Compensation totals and settlements keep their guarded document/report destinations.
        if (isset($areas['Compensi professionisti'])) {
            foreach ($areas['Report ed esportazioni'] ?? [] as $link) if (str_contains($link['href'], 'tab=report')) $areas['Compensi professionisti'][] = array_replace($link, ['label'=>'Maturati e liquidati']);
            foreach ($areas['Incassi e scadenze'] ?? [] as $link) if (str_contains($link['href'], 'tab=documenti')) $areas['Compensi professionisti'][] = array_replace($link, ['label'=>'Liquidazioni per documento']);
        }
        if ($entries) {
            $found = false;
            foreach ($out as &$node) if (($node['label'] ?? '') === 'Amministrazione' && !isset($node['href'])) { $node['children'] = array_merge($entries, $node['children']); $found = true; } unset($node);
            if (!$found) array_splice($out, min(4,count($out)), 0, [['id'=>'g_administration','label'=>'Amministrazione','icon'=>'Amministrazione','children'=>$entries]]);
        }
        foreach ($relocated as $area=>$links) {
            $found = false;
            foreach ($out as &$node) if (($node['label'] ?? '') === $area && !isset($node['href'])) { $node['children'] = array_merge($links, $node['children']); $found = true; } unset($node);
            if (!$found) $out[] = ['id'=>'g_relocated_'.md5($area),'label'=>$area,'icon'=>$area,'children'=>$links];
        }
        return ['tree'=>$out, 'areas'=>$areas];
    }

    public static function matches(string $href, string $current): bool
    {
        $path = rtrim((string)parse_url($href, PHP_URL_PATH), '/');
        $actual = rtrim((string)parse_url($current, PHP_URL_PATH), '/');
        // The overview is not the parent of the management workspace.
        if (str_ends_with($path, '/admin/fatturazione') && $actual !== $path) return false;
        if ($actual !== $path && !str_starts_with($actual, $path.'/')) return false;
        parse_str((string)parse_url($href,PHP_URL_QUERY),$q);
        parse_str((string)parse_url($current,PHP_URL_QUERY),$actualQ);
        foreach ($q as $key=>$value) if (($actualQ[$key]??null) !== $value) return false;
        return true;
    }
}

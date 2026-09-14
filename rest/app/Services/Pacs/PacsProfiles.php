<?php
namespace App\Services\Pacs;

/** Server profiles coexist with tenant-scoped encrypted profiles managed by the master. */
class PacsProfiles
{
    public function __construct(private ?array $configuration = null,private ?\CodeIgniter\Database\BaseConnection $db=null) {}
    public function forTenant(int $tenantId): array
    {
        $profiles=$this->serverForTenant($tenantId);
        if ($this->configuration!==null) return $profiles;
        $db=$this->db;
        if (!$db) {
            $tenant=(new \App\Services\TenantCatalogService())->getTenantById($tenantId);
            if (!$tenant || empty($tenant['is_active'])) throw new PacsException('Spazio PACS non disponibile.');
            $db=(new \App\Services\TenantDatabaseConnector())->connect($tenant);
        }
        foreach ((new PacsManagedProfiles($db,$tenantId))->all() as $id=>$p) {
            if (isset($profiles[$id])) throw new PacsException('Identificativo PACS duplicato tra server e spazio. Contattare l’assistenza.');
            $profiles[$id]=$p;
        }
        return $profiles;
    }
    public function serverForTenant(int $tenantId): array
    {
        if ($tenantId <= 0) throw new PacsException('Spazio PACS non valido.');
        $config = $this->configuration;
        if ($config === null) {
            $path = getenv('PACS_CONFIG_FILE');
            if (!$path) {
                $json = getenv('PACS_PROFILES_JSON');
                if ($json === false || $json === '') return [];
            } else {
                $real = realpath($path);
                $publicRoot = realpath(ROOTPATH . '..');
                if (!$real || !is_file($real) || is_link($path) || ($publicRoot && str_starts_with(str_replace('\\','/', $real).'/', rtrim(str_replace('\\','/', $publicRoot),'/').'/'))) {
                    throw new PacsException('Configurazione PACS privata non disponibile.');
                }
                $json = file_get_contents($real, false, null, 0, 262145);
            }
            if (!is_string($json) || strlen($json) > 262144) throw new PacsException('Configurazione PACS non valida.');
            try { $config = json_decode($json, true, 32, JSON_THROW_ON_ERROR); }
            catch (\Throwable) { throw new PacsException('Configurazione PACS non valida.'); }
        }
        if (!is_array($config) || !is_array($config['tenants'] ?? null)) throw new PacsException('Configurazione PACS non valida.');
        $rows = $config['tenants'][(string)$tenantId] ?? [];
        if (!is_array($rows) || count($rows) > 20) throw new PacsException('Configurazione PACS non valida.');
        $profiles = [];
        foreach ($rows as $row) {
            if (!is_array($row)) throw new PacsException('Profilo PACS non valido.');
            $id = (string)($row['id'] ?? '');
            if (!preg_match('/^[a-z][a-z0-9_-]{0,39}$/D', $id) || isset($profiles[$id])) throw new PacsException('Identificativo PACS non valido o duplicato.');
            $label = trim((string)($row['label'] ?? ''));
            if ($label === '' || mb_strlen($label) > 100) throw new PacsException('Nome PACS non valido.');
            foreach (['qido_url','wado_url'] as $key) self::url((string)($row[$key] ?? ''));
            $auth = (string)($row['auth'] ?? 'none');
            if (!in_array($auth, ['none','basic','bearer'], true)) throw new PacsException('Autenticazione PACS non supportata.');
            foreach ($auth === 'basic' ? ['username_env','password_env'] : ($auth === 'bearer' ? ['token_env'] : []) as $key) {
                if (!preg_match('/^PACS_[A-Z0-9_]{1,100}$/D', (string)($row[$key] ?? ''))) throw new PacsException('Riferimento credenziale PACS non valido.');
            }
            $viewer = (string)($row['viewer_url'] ?? '');
            if ($viewer !== '') {
                if (substr_count($viewer, '{study}') !== 1) throw new PacsException('Il visualizzatore richiede un solo parametro studio.');
                $parsed = parse_url(str_replace('{study}', '1.2.3', $viewer));
                self::url(str_replace('{study}', '1.2.3', $viewer), true);
                if (str_contains((string)($parsed['host'] ?? ''), '1.2.3') || str_contains((string)($parsed['path'] ?? '').($parsed['query'] ?? ''), '{')) throw new PacsException('Indirizzo visualizzatore non valido.');
                if (str_contains((string)(parse_url($viewer, PHP_URL_HOST) ?? ''), '{')) throw new PacsException('Host visualizzatore non valido.');
            }
            $row['enabled'] = ($row['enabled'] ?? false) === true;
            $row['auth'] = $auth;
            $row['label'] = $label;
            $row['viewer_url'] = $viewer;
            $row['download_enabled'] = ($row['download_enabled'] ?? false) === true;
            $row['id'] = $id;
            $profiles[$id] = $row;
        }
        return $profiles;
    }
    public static function credential(array $profile,string $key): string
    {
        $value=isset($profile['credentials']) ? ($profile['credentials'][$key] ?? '') : getenv($profile[$key.'_env'] ?? '');
        if (!is_string($value) || $value==='' || preg_match('/[\r\n\x00]/',$value)) throw new PacsException('Credenziali PACS non configurate.');
        return $value;
    }
    public static function credentialsReady(array $profile): bool
    {
        try {
            foreach ($profile['auth']==='basic' ? ['username','password'] : ($profile['auth']==='bearer' ? ['token'] : []) as $key) self::credential($profile,$key);
            return true;
        } catch (\Throwable) { return false; }
    }
    public function get(int $tenantId, string $id): array
    {
        $profile = $this->forTenant($tenantId)[$id] ?? null;
        if (!$profile || !$profile['enabled']) throw new PacsException('Collegamento PACS non disponibile.');
        return $profile;
    }
    public static function fingerprint(array $profile): string
    {
        return hash('sha256', json_encode([$profile['id'],rtrim($profile['qido_url'],'/'),rtrim($profile['wado_url'],'/'),(string)($profile['identity_namespace'] ?? '')],JSON_THROW_ON_ERROR));
    }
    public static function url(string $url, bool $query = false): array
    {
        $p = parse_url($url);
        if (!$p || ($p['scheme'] ?? '') !== 'https' || empty($p['host']) || isset($p['user']) || isset($p['pass']) || isset($p['fragment']) || (!$query && isset($p['query'])) || preg_match('/[\x00-\x20\\\\]/', $url)) {
            throw new PacsException('Il PACS richiede un indirizzo HTTPS senza credenziali nell’URL.');
        }
        if (!preg_match('/^[a-zA-Z0-9.-]+$/D', $p['host']) || str_ends_with($p['host'], '.') || !str_contains($p['host'], '.')) throw new PacsException('Host PACS non valido.');
        return $p;
    }
}

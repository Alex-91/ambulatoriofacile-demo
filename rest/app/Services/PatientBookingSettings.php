<?php
namespace App\Services;

use CodeIgniter\Database\BaseConnection;

final class PatientBookingSettings
{
    private BaseConnection $db;
    public function __construct(?BaseConnection $db = null) { $this->db = $db ?? \Config\Database::connect('platform'); }
    public function ready(): bool { return $this->db->tableExists('platform_patient_booking_settings'); }
    public function get(int $tenant): array
    {
        if (!$this->ready()) return ['enabled'=>false,'revision'=>0,'config'=>self::defaults()];
        $row = $this->db->table('platform_patient_booking_settings')->where('id_tenant',$tenant)->get()->getRowArray();
        return ['enabled'=>(bool)($row['enabled']??false),'revision'=>(int)($row['revision']??0),
            'config'=>array_replace(self::defaults(),json_decode($row['config_json']??'{}',true) ?: [])];
    }
    public function enabled(int $tenant): bool
    {
        try { return $tenant > 0 && $this->get($tenant)['enabled']; } catch (\Throwable $e) { return false; }
    }
    public static function defaults(): array
    {
        return ['doctors'=>[], 'visits'=>[], 'locations'=>[], 'telefono'=>'', 'email'=>'', 'privacy_url'=>'',
            'first_available'=>true,'min_notice'=>120,'days'=>90,'cancel_hours'=>24,'workflow'=>[],'editor'=>[],'suspended'=>false];
    }
    public function save(int $tenant, array $config, bool $enabled, int $actor, int $revision): void
    {
        if (!$this->ready()) throw new PatientBookingException('schema_non_installato');
        foreach (['doctors','visits','locations'] as $key) {
            $config[$key]=array_values(array_unique(array_filter(array_map('intval',(array)($config[$key]??[])),static fn($id)=>$id>0)));
        }
        foreach (['min_notice'=>[0,10080],'days'=>[1,365],'cancel_hours'=>[0,168]] as $key=>$range) {
            if (!isset($config[$key]) || filter_var($config[$key],FILTER_VALIDATE_INT)===false || $config[$key]<$range[0] || $config[$key]>$range[1]) throw new PatientBookingException('configurazione_non_valida');
            $config[$key]=(int)$config[$key];
        }
        $config['first_available']=!empty($config['first_available']);
        foreach (['telefono','email','privacy_url'] as $key) $config[$key]=trim((string)($config[$key]??''));
        if ($enabled && (!$config['doctors'] || !$config['visits'] || !$config['locations'] || !filter_var($config['privacy_url'],FILTER_VALIDATE_URL) || !str_starts_with($config['privacy_url'],'https://'))) throw new PatientBookingException('configurazione_non_valida');
        if (($config['email']!=='' && !filter_var($config['email'],FILTER_VALIDATE_EMAIL)) || !preg_match('/^[+0-9 ()-]{0,40}$/D',$config['telefono'])) throw new PatientBookingException('configurazione_non_valida');
        $config=array_intersect_key($config,self::defaults());
        $config['workflow']=PatientBookingWorkflow::validate((array)($config['workflow']??[]),$config);
        $row=['enabled'=>(int)$enabled,'config_json'=>json_encode($config,JSON_THROW_ON_ERROR),'revision'=>$revision+1,'updated_by'=>$actor,'updated_at'=>gmdate('Y-m-d H:i:s')];
        if ($revision===0) {
            $row['id_tenant']=$tenant;
            try { $this->db->table('platform_patient_booking_settings')->insert($row); }
            catch (\Throwable $e) { throw new PatientBookingException('configurazione_cambiata',409); }
        } else {
            $this->db->table('platform_patient_booking_settings')->where('id_tenant',$tenant)->where('revision',$revision)->update($row);
            if ($this->db->affectedRows()!==1) throw new PatientBookingException('configurazione_cambiata',409);
        }
    }
}

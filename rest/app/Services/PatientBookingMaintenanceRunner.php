<?php
namespace App\Services;

use CodeIgniter\Database\BaseConnection;

/** Discovers enabled spaces on every run, including spaces created after deployment. */
final class PatientBookingMaintenanceRunner
{
    private BaseConnection $platform;

    public function __construct(?BaseConnection $platform = null, private ?\Closure $processor = null)
    {
        $this->platform = $platform ?? \Config\Database::connect('platform');
    }

    public function runEnabled(string $patientUrl): array
    {
        if (!(new PatientBookingSettings($this->platform))->ready()) {
            throw new \RuntimeException('Schema piattaforma prenotazioni non installato.');
        }
        $rows = $this->platform->table('platform_patient_booking_settings s')
            ->select('s.id_tenant')->join('platform_tenants t', 't.id_tenant=s.id_tenant')
            ->where('s.enabled', 1)->where('t.is_active', 1)->orderBy('s.id_tenant')->get()->getResultArray();
        $result = ['processed'=>0, 'skipped'=>0, 'failed'=>0, 'spaces'=>[]];
        foreach ($rows as $row) {
            $item = $this->runTenant((int)$row['id_tenant'], $patientUrl);
            $result[$item['status']]++;
            $result['spaces'][] = $item;
        }
        return $result;
    }

    public function runTenant(int $id, string $patientUrl): array
    {
        $result = ['tenant'=>$id, 'status'=>'skipped'];
        try {
            $tenant = $this->platform->table('platform_tenants')->where('id_tenant', $id)->get()->getRowArray();
            $settings = (new PatientBookingSettings($this->platform))->get($id);
            if (!$tenant || empty($tenant['is_active']) || !$settings['enabled']) return $result;
            $counts = $this->processor
                ? ($this->processor)($tenant, $settings, $patientUrl)
                : $this->process($tenant, $settings, $patientUrl);
            return array_replace($result, $counts);
        } catch (\Throwable $e) {
            return ['tenant'=>$id, 'status'=>'failed', 'error'=>get_class($e)];
        }
    }

    private function process(array $tenant, array $settings, string $patientUrl): array
    {
        $db = (new TenantDatabaseConnector())->connect($tenant);
        if (PatientBookingSchema::isFrozenDemoDatabase($db)) throw new \RuntimeException('Demo congelata esclusa.');
        if (!PatientBookingSchema::ready($db)) throw new \RuntimeException('Schema spazio non installato.');
        $lock = 'patient-maintenance-'.substr(hash('sha256', $db->database), 0, 40);
        if (!(int)$db->query('SELECT GET_LOCK(?,0) AS acquired', [$lock])->getRowArray()['acquired']) {
            return ['status'=>'skipped', 'reason'=>'already_running'];
        }
        try {
            $expired = (new PatientBookingAgenda($db, $settings, 0))->expire();
            $notifications = new PatientBookingNotifications($db, $tenant, $settings);
            $notifications->reminders();
            $counts = $notifications->run($patientUrl);
            return ['status'=>$counts['failed'] > 0 ? 'failed' : 'processed', 'expired'=>$expired] + $counts;
        } finally {
            $db->query('SELECT RELEASE_LOCK(?)', [$lock]);
        }
    }
}

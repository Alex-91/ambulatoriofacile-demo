<?php
namespace App\Database\Polyclinic;

use CodeIgniter\Database\Migration;

/** Explicit tenant installation only. No production or GET-time schema changes. */
class CreatePolyclinicAdministration extends Migration
{
    public const TABLES = ['pc_documents', 'pc_catalog', 'pc_tariffs', 'pc_encounters', 'pc_orders', 'pc_document_state', 'pc_credit_allocations', 'pc_payments', 'pc_installments', 'pc_settlements', 'pc_demo_runs', 'pc_audit', 'pc_settings'];

    public function up()
    {
        if (!$this->db->tableExists('pc_documents') && $this->db->tableExists('pc_document_state') && $this->db->table('pc_document_state')->countAllResults()>0) {
            throw new \RuntimeException('Archivio sperimentale precedente rilevato: migrazione dati esplicita richiesta prima di installare il modulo separato.');
        }
        $id = ['type'=>'INT', 'unsigned'=>true, 'auto_increment'=>true];
        $int = ['type'=>'INT', 'default'=>0];
        $text = ['type'=>'TEXT', 'null'=>true];
        $str = static fn(int $n = 190) => ['type'=>'VARCHAR', 'constraint'=>$n, 'default'=>''];
        $date = ['type'=>'DATETIME', 'null'=>true];
        $tables = [
            'pc_documents' => [
                'id_billing_document'=>$id, 'id_client'=>['type'=>'INT','null'=>true],
                'document_number'=>$str(32), 'document_type'=>$str(24), 'issue_date'=>['type'=>'DATE'],
                'due_date'=>['type'=>'DATE','null'=>true], 'payment_date'=>['type'=>'DATE','null'=>true],
                'patient_name'=>$str(), 'patient_tax_code'=>$str(16), 'patient_email'=>$str(),
                'payment_method'=>$str(24), 'payment_status'=>$str(24), 'paid_at'=>$date,
                'line_items_json'=>$text, 'template_snapshot_json'=>$text, 'notes'=>$text,
                'subtotal_amount'=>['type'=>'DECIMAL','constraint'=>'10,2','default'=>0],
                'stamp_duty_amount'=>['type'=>'DECIMAL','constraint'=>'10,2','default'=>0],
                'amount_total'=>['type'=>'DECIMAL','constraint'=>'10,2','default'=>0],
                'vat_rate'=>['type'=>'DECIMAL','constraint'=>'5,2','default'=>0], 'vat_nature'=>$str(16),
                'ts_sync_enabled'=>$int, 'ts_sync_state'=>$str(24), 'local_state'=>$str(20),
                'reminder_count'=>$int, 'created_by'=>['type'=>'INT','null'=>true], 'updated_by'=>['type'=>'INT','null'=>true],
                'created_at'=>$date, 'updated_at'=>$date,
            ],
            'pc_catalog' => ['id'=>$id, 'kind'=>$str(24), 'code'=>$str(40), 'name'=>$str(), 'data_json'=>$text, 'active'=>['type'=>'INT','default'=>1], 'version'=>$int],
            'pc_tariffs' => ['id'=>$id, 'list_id'=>$int, 'service_id'=>$int, 'amount_cents'=>$int, 'version'=>$int],
            'pc_encounters' => ['id'=>$id, 'appointment_id'=>['type'=>'INT','null'=>true], 'patient_id'=>$int, 'patient_name'=>$str(), 'patient_tax_code'=>$str(16), 'doctor_id'=>$int, 'visit_date'=>['type'=>'DATE'], 'state'=>$str(24), 'version'=>$int, 'created_at'=>$date, 'updated_at'=>$date],
            'pc_orders' => ['id'=>$id, 'encounter_id'=>$int, 'service_id'=>$int, 'doctor_id'=>$int, 'agreement_id'=>$int, 'quantity'=>$int, 'unit_cents'=>$int, 'total_cents'=>$int, 'payer_cents'=>$int, 'snapshot_json'=>$text, 'billing_id'=>['type'=>'INT','null'=>true], 'created_at'=>$date],
            'pc_document_state' => ['id'=>$id, 'billing_id'=>$int, 'original_id'=>['type'=>'INT','null'=>true], 'version'=>$int, 'request_key'=>$str(80), 'einvoice_state'=>$str(24), 'einvoice_reference'=>$str(), 'created_at'=>$date],
            'pc_credit_allocations' => ['id'=>$id, 'credit_id'=>$int, 'order_id'=>$int, 'amount_cents'=>$int],
            'pc_payments' => ['id'=>$id, 'billing_id'=>$int, 'amount_cents'=>$int, 'method'=>$str(24), 'payer'=>$str(24), 'payment_date'=>['type'=>'DATE'], 'reference'=>$str(), 'request_key'=>$str(80), 'created_by'=>$int, 'created_at'=>$date],
            'pc_installments' => ['id'=>$id, 'billing_id'=>$int, 'due_date'=>['type'=>'DATE'], 'amount_cents'=>$int],
            'pc_settlements' => ['id'=>$id, 'billing_id'=>$int, 'doctor_id'=>$int, 'amount_cents'=>$int, 'payment_date'=>['type'=>'DATE'], 'reference'=>$str(), 'request_key'=>$str(80), 'created_by'=>$int, 'created_at'=>$date],
            'pc_demo_runs' => ['id'=>$id, 'connector'=>$str(24), 'scenario'=>$str(24), 'result_json'=>$text, 'request_key'=>$str(80), 'created_by'=>$int, 'created_at'=>$date],
            'pc_audit' => ['id'=>$id, 'actor_id'=>$int, 'action'=>$str(40), 'entity_id'=>$int, 'detail_json'=>$text, 'created_at'=>$date],
            'pc_settings' => ['id'=>$id, 'name'=>$str(40), 'data_json'=>$text, 'version'=>$int],
        ];
        foreach ($tables as $table=>$fields) {
            if (in_array($table,['pc_encounters','pc_orders'],true)) $fields['request_key']=$str(80);
            if (isset($fields['request_key'])) $fields['request_hash']=$str(64);
            if ($table==='pc_document_state') $fields['einvoice_xml']=$text;
            if ($this->db->tableExists($table)) continue;
            $this->forge->addField($fields);
            $this->forge->addKey($table==='pc_documents'?'id_billing_document':'id', true);
            if ($table==='pc_documents') $this->forge->addUniqueKey('document_number');
            if ($table==='pc_catalog') $this->forge->addUniqueKey(['kind','code']);
            if ($table==='pc_tariffs') $this->forge->addUniqueKey(['list_id','service_id']);
            if ($table==='pc_encounters') { $this->forge->addUniqueKey('appointment_id'); $this->forge->addKey(['visit_date','state']); }
            if ($table==='pc_orders') $this->forge->addKey(['encounter_id','billing_id']);
            if ($table==='pc_document_state') { $this->forge->addUniqueKey('billing_id'); $this->forge->addKey('original_id'); }
            if ($table==='pc_credit_allocations') $this->forge->addUniqueKey(['credit_id','order_id']);
            if (isset($fields['request_key'])) $this->forge->addUniqueKey('request_key');
            if (in_array($table,['pc_payments','pc_installments','pc_settlements'],true)) $this->forge->addKey('billing_id');
            if ($table==='pc_settings') $this->forge->addUniqueKey('name');
            $this->forge->createTable($table, true);
        }
        if (!$this->db->table('pc_settings')->where('name','write_lock')->countAllResults()) {
            $this->db->table('pc_settings')->insert(['name'=>'write_lock','data_json'=>'{}','version'=>0]);
        }
    }

    public function down()
    {
        // Financial journals require an explicit retention/rollback decision.
        throw new \RuntimeException('Rollback distruttivo non automatico: ripristinare il backup concordato.');
    }
}

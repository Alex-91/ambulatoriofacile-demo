<?php
// app/Models/PushOutboxModel.php
namespace App\Models;

use CodeIgniter\Model;

class PushOutboxModel extends Model
{
    protected $table = 'push_outbox';
    protected $primaryKey = 'id';
    protected $allowedFields = ['endpoint','user_id','device_id','title','body','url','consumed','created_at'];
    public $timestamps = false;

    public function lastPendingByEndpoint(string $endpoint)
    {
        return $this->where(['endpoint'=>$endpoint,'consumed'=>0])
                    ->orderBy('id','DESC')->first();
    }
}

<?php
namespace App\Models;

use CodeIgniter\Model;

class GruppoModel extends Model
{
    protected $table      = 'dap21_gruppo';
    protected $primaryKey = 'id_gruppo';
    protected $allowedFields = ['nome'];
}

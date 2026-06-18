<?php
namespace App\Models;

use CodeIgniter\Model;

class TypeDoctorsModel extends Model
{
    protected $table      = 'dap05_type_doctors';
    protected $primaryKey = 'id_type_doctors';
    protected $allowedFields = ['des_tipo','qualfica'];
}

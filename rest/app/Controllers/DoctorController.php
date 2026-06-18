<?php
namespace App\Controllers;

use App\Models\PersonaleModel;
use CodeIgniter\RESTful\ResourceController;
use App\Libraries\DatabaseConfig;

class DoctorController extends BaseController
{

    protected $db;
    protected $dbConfig;

    public function __construct()
    {
        $this->db = \Config\Database::connect(); // Assegna alla proprietà della classe
        $this->dbConfig = new DatabaseConfig();
        $this->dbConfig->setEncryptionConfig($this->db);
    } 

    public function getDoctors()
    {
        $model = new PersonaleModel();
        $doctors = $model->findAllDecrypted();

        $json = json_encode(
            $this->sanitizeJsonValue($doctors),
            JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
        );

        if ($json === false) {
            $json = json_encode([], JSON_UNESCAPED_UNICODE);
        }

        return $this->response
            ->setHeader('Content-Type', 'application/json; charset=utf-8')
            ->setBody($json ?: '[]');
    }

    private function sanitizeJsonValue($value)
    {
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $value[$key] = $this->sanitizeJsonValue($item);
            }

            return $value;
        }

        if (!is_string($value) || $value === '') {
            return $value;
        }

        if (function_exists('mb_check_encoding') && mb_check_encoding($value, 'UTF-8')) {
            return $value;
        }

        if (function_exists('mb_detect_encoding') && function_exists('mb_convert_encoding')) {
            $encoding = mb_detect_encoding($value, ['UTF-8', 'Windows-1252', 'ISO-8859-1'], true);

            if ($encoding !== false) {
                return mb_convert_encoding($value, 'UTF-8', $encoding);
            }
        }

        if (function_exists('iconv')) {
            $converted = @iconv('ISO-8859-1', 'UTF-8//IGNORE', $value);

            if ($converted !== false) {
                return $converted;
            }
        }

        return utf8_encode($value);
    }
}

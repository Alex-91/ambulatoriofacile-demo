<?php
namespace App\Services;

use CodeIgniter\Database\BaseConnection;

/** Invoked by the explicit installer only; HTTP requests never repair schemas. */
final class PatientBookingSchema
{
    public static function ready(BaseConnection $db): bool
    {
        foreach(['patient_online_bookings','patient_booking_details','patient_booking_contacts','patient_booking_blocks','patient_booking_events','patient_booking_invites','patient_booking_outbox','patient_booking_absences'] as $table)if(!$db->tableExists($table))return false;
        return true;
    }
    public static function platform(BaseConnection $db): void
    {
        $db->query("CREATE TABLE IF NOT EXISTS platform_patient_booking_settings (
            id_tenant INT NOT NULL PRIMARY KEY, enabled TINYINT NOT NULL DEFAULT 0,
            config_json MEDIUMTEXT NOT NULL, revision INT NOT NULL DEFAULT 1,
            updated_by INT NOT NULL, updated_at DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    public static function tenant(BaseConnection $db): void
    {
        $db->query("CREATE TABLE IF NOT EXISTS patient_booking_absences (
            booking_ref CHAR(6) PRIMARY KEY, created_by INT NOT NULL, created_at DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $db->query("CREATE TABLE IF NOT EXISTS patient_booking_contacts (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, author_id INT NOT NULL,
            data_encrypted MEDIUMTEXT NOT NULL, vector_id VARCHAR(32) NOT NULL,
            state VARCHAR(20) NOT NULL DEFAULT 'pending', created_at DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        if($db->tableExists('dap12_agenda_appuntamenti')){
            $state=$db->query("SHOW COLUMNS FROM dap12_agenda_appuntamenti LIKE 'stato'")->getRowArray();
            if($state && str_starts_with(strtolower($state['Type']),'enum(') && !str_contains($state['Type'],"'IN_ATTESA'")){
                $type=substr($state['Type'],0,-1).",'IN_ATTESA')";
                $db->query('ALTER TABLE dap12_agenda_appuntamenti MODIFY stato '.$type.($state['Null']==='YES'?' NULL':' NOT NULL').($state['Default']!==null?' DEFAULT '.$db->escape($state['Default']):''));
            }
        }
        $db->query("CREATE TABLE IF NOT EXISTS patient_booking_events (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, booking_ref CHAR(6) NOT NULL,
            device_hash CHAR(64) NOT NULL, ip_hash CHAR(64) NOT NULL, created_at DATETIME NOT NULL,
            INDEX ix_booking_device(device_hash,created_at), INDEX ix_booking_ip(ip_hash,created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $db->query("CREATE TABLE IF NOT EXISTS patient_booking_invites (
            token_hash CHAR(64) PRIMARY KEY, constraints_json TEXT NOT NULL,
            expires_at DATETIME NOT NULL, used_by CHAR(6) NULL, created_by INT NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $db->query("CREATE TABLE IF NOT EXISTS patient_booking_details (
            booking_ref CHAR(6) PRIMARY KEY, status VARCHAR(20) NOT NULL DEFAULT 'confermata',
            expires_at DATETIME NULL, phone_hash CHAR(64) NULL, email_hash CHAR(64) NULL,
            data_encrypted MEDIUMTEXT NULL, vector_id VARCHAR(32) NOT NULL,
            updated_at DATETIME NOT NULL, sequence INT NOT NULL DEFAULT 0,
            INDEX ix_booking_expiry(status,expires_at), INDEX ix_booking_phone(phone_hash), INDEX ix_booking_email(email_hash)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $db->query("CREATE TABLE IF NOT EXISTS patient_booking_blocks (
            contact_hash CHAR(64) PRIMARY KEY, created_by INT NOT NULL, created_at DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $db->query("CREATE TABLE IF NOT EXISTS patient_booking_outbox (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, booking_ref CHAR(6) NOT NULL,
            event VARCHAR(30) NOT NULL, sequence INT NOT NULL DEFAULT 0,
            state VARCHAR(20) NOT NULL DEFAULT 'pending', attempts INT NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL, sent_at DATETIME NULL,
            UNIQUE KEY uq_booking_event(booking_ref,event,sequence)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $db->query("CREATE TABLE IF NOT EXISTS patient_online_bookings (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            id_appuntamento BIGINT NOT NULL, booking_ref CHAR(6) NOT NULL,
            author_id INT NOT NULL, idempotency_key VARCHAR(64) NOT NULL,
            request_hash CHAR(64) NOT NULL, revision INT NOT NULL,
            created_at DATETIME NOT NULL,
            UNIQUE KEY uq_patient_online_ref (booking_ref),
            UNIQUE KEY uq_patient_online_appointment (id_appuntamento),
            UNIQUE KEY uq_patient_online_retry (author_id,idempotency_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
}

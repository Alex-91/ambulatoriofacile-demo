<?php
namespace App\Services;

use CodeIgniter\Database\BaseConnection;

/** Invoked by the explicit installer only; HTTP requests never repair schemas. */
final class PatientBookingSchema
{
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

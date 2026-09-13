<?php

namespace App\Services;

use CodeIgniter\Database\BaseConnection;

/** Short local DB writes only. Never wrap PDF generation, signature checks or remote calls. */
final class FseLocalPersistenceService
{
    public static function write(BaseConnection $db, callable $operation): mixed
    {
        if (!$db->transStatus() || !$db->transBegin()) {
            throw new \RuntimeException('Transazione locale FSE non disponibile.');
        }
        // CI nested rollback only decrements a counter. A real savepoint also
        // removes this operation if an outer caller catches the exception.
        $savepoint = 'fse_local_' . bin2hex(random_bytes(8));
        $activeSavepoint = false;
        try {
            if ($db->query('SAVEPOINT ' . $savepoint) === false) {
                throw new \RuntimeException('Protezione transazione locale FSE non disponibile.');
            }
            $activeSavepoint = true;
            $result = $operation();
            if (!$db->transStatus() || $db->query('RELEASE SAVEPOINT ' . $savepoint) === false) {
                throw new \RuntimeException('Salvataggio locale FSE non completato.');
            }
            $activeSavepoint = false;
            if (!$db->transCommit()) throw new \RuntimeException('Conferma del salvataggio locale FSE non riuscita.');
            return $result;
        } catch (\Throwable $e) {
            try {
                if ($activeSavepoint) {
                    if ($db->query('ROLLBACK TO SAVEPOINT ' . $savepoint) === false) {
                        throw new \RuntimeException('Rollback locale non verificabile.');
                    }
                    $db->query('RELEASE SAVEPOINT ' . $savepoint);
                }
                if (!$db->transRollback()) $db->close();
            } catch (\Throwable $rollbackError) {
                // Do not leave an outer caller a connection capable of committing
                // a partially rolled-back local operation.
                $db->close();
            }
            throw $e;
        }
    }
}

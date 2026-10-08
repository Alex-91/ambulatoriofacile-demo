<?php
namespace App\Services;

use CodeIgniter\Database\BaseConnection;

final class BillingNumberingService
{
    public static function config(array $value): array
    {
        $mode = (string) ($value['mode'] ?? 'annual');
        $digits = filter_var($value['digits'] ?? 4, FILTER_VALIDATE_INT);
        if (!in_array($mode, ['annual', 'continuous'], true) || $digits === false || $digits < 1 || $digits > 8) {
            throw new \RuntimeException('Numerazione non valida: scegliere annuale o continua e da 1 a 8 cifre.');
        }
        return ['mode' => $mode, 'digits' => $digits];
    }

    /** Must run inside the same transaction as the document write. One mutex per tenant DB. */
    public function lock(BaseConnection $db): void
    {
        if (!$db->table('billing_numbering_lock')->where('id', 1)->set('revision', 'revision + 1', false)->update()
            || $db->affectedRows() !== 1) {
            throw new \RuntimeException('Protezione numerazione non disponibile: salvataggio bloccato.');
        }
    }

    public function allocate(BaseConnection $db, array $template, string $date): string
    {
        $config = self::config((array) ($template['numbering'] ?? []));
        $year = substr($date, 0, 4);
        $scope = $config['mode'] === 'annual' ? $year : 'continuous';
        $row = $db->table('billing_numbering_counters')->where('scope', $scope)->get()->getRowArray();
        $next = (int) ($row['last_number'] ?? 0);
        if (!$row) {
            // Start above the historical maximum, never fill holes left by older software.
            $history = $db->table('billing_documents')->select('document_number, issue_date');
            if ($config['mode'] === 'annual') {
                $history->where('issue_date >=', $year . '-01-01')->where('issue_date <=', $year . '-12-31');
            }
            foreach ($history->get()->getResultArray() as $document) {
                if (str_starts_with((string) $document['document_number'], 'BOZZA-')) continue;
                if (preg_match('/(?:^|[-\/])(\d{1,8})$/D', (string) $document['document_number'], $match)) {
                    $next = max($next, (int) $match[1]);
                }
            }
        }
        $prefix = strtoupper(trim((string) ($template['document_code_prefix'] ?? 'FT')));
        do {
            if (++$next > 99999999) {
                throw new \RuntimeException('Progressivo esaurito. Contattare l’assistenza.');
            }
            $number = $prefix . '-' . ($config['mode'] === 'annual' ? $year . '-' : '')
                . str_pad((string) $next, $config['digits'], '0', STR_PAD_LEFT);
        } while ($db->table('billing_documents')->where('document_number', $number)->countAllResults() > 0);
        $builder = $db->table('billing_numbering_counters');
        $ok = $row ? $builder->where('scope', $scope)->update(['last_number' => $next])
            : $builder->insert(['scope' => $scope, 'last_number' => $next]);
        if (!$ok) {
            throw new \RuntimeException('Prenotazione del numero fattura non riuscita.');
        }
        return $number;
    }
}

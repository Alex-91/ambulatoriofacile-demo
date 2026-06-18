<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;

class Logs extends BaseController
{
    // cartella log
    private string $logDir;

    // prefissi possibili
    private array $prefixes = [
        'logdottorApp-', // tuo vecchio formato
        'log-',          // CI4 default spesso è log-YYYY-MM-DD.log
    ];

    public function __construct()
    {
        $this->logDir = WRITEPATH . 'logs' . DIRECTORY_SEPARATOR;
    }

    public function index()
    {
        $menu_items = session()->get('header_menu_items') ?? [];
        $result = session()->get('menuDataAdmin');
        if (!empty($result['result'])) {
            $menu_items = $result['result'];
        }

        return view('admin/logs_index', [
            'menu_items' => $menu_items,
        ]);
    }

    /**
     * AJAX: legge il log per data (YYYY-MM-DD) + tail opzionale (ultime N righe)
     * Ritorna text/plain (più robusto del JSON per encoding).
     */
    public function read()
    {
        $date = trim((string)($this->request->getGet('date') ?? ''));
        $tail = (int)($this->request->getGet('tail') ?? 0); // 0 = tutto

        if ($date === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return $this->response
                ->setStatusCode(400)
                ->setHeader('Content-Type', 'text/plain; charset=utf-8')
                ->setBody("Data non valida. Usa YYYY-MM-DD.");
        }

        $file = $this->findLogFileForDate($date);
        if (!$file) {
            return $this->response
                ->setStatusCode(404)
                ->setHeader('Content-Type', 'text/plain; charset=utf-8')
                ->setBody("Nessun log trovato per {$date}.");
        }

        // sicurezza: path deve stare nella cartella log
        $real = realpath($file);
        $realDir = realpath($this->logDir);
        if (!$real || !$realDir || strpos($real, $realDir) !== 0) {
            return $this->response
                ->setStatusCode(403)
                ->setHeader('Content-Type', 'text/plain; charset=utf-8')
                ->setBody("Accesso non consentito.");
        }

        try {
            if ($tail > 0) {
                $content = $this->tailFile($real, $tail);
            } else {
                $content = @file_get_contents($real);
                if ($content === false) {
                    throw new \RuntimeException("Impossibile leggere il file.");
                }
            }

            // Normalizza encoding (evita problemi UTF-8)
            if (!mb_check_encoding($content, 'UTF-8')) {
                $content = mb_convert_encoding($content, 'UTF-8', 'UTF-8, ISO-8859-1, Windows-1252');
            }

            return $this->response
                ->setHeader('Content-Type', 'text/plain; charset=utf-8')
                ->setBody($content);

        } catch (\Throwable $e) {
            log_message('error', 'Logs::read error: ' . $e->getMessage());
            return $this->response
                ->setStatusCode(500)
                ->setHeader('Content-Type', 'text/plain; charset=utf-8')
                ->setBody("Errore lettura log.");
        }
    }

    /**
     * Download del log (stessa logica della read).
     */
    public function download()
    {
        $date = trim((string)($this->request->getGet('date') ?? ''));
        if ($date === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return redirect()->to(site_url('admin/logs'));
        }

        $file = $this->findLogFileForDate($date);
        if (!$file || !is_file($file)) {
            return redirect()->to(site_url('admin/logs'));
        }

        $real = realpath($file);
        $realDir = realpath($this->logDir);
        if (!$real || !$realDir || strpos($real, $realDir) !== 0) {
            return redirect()->to(site_url('admin/logs'));
        }

        return $this->response->download($real, null);
    }

    /**
     * (Opzionale) lista date disponibili (scansione cartella).
     * Ritorna JSON robusto.
     */
    public function listDates()
    {
        $dates = [];
        if (is_dir($this->logDir)) {
            $files = glob($this->logDir . '*.log') ?: [];
            foreach ($files as $f) {
                $base = basename($f);
                // prova a estrarre una data YYYY-MM-DD dal nome
                if (preg_match('/(\d{4}-\d{2}-\d{2})/', $base, $m)) {
                    $dates[$m[1]] = true;
                }
            }
        }
        $out = array_keys($dates);
        rsort($out);

        $payload = ['ok' => true, 'dates' => $out];
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

        return $this->response
            ->setHeader('Content-Type', 'application/json; charset=utf-8')
            ->setBody($json);
    }

    /**
     * Cerca il file log per data, provando più prefissi.
     */
    private function findLogFileForDate(string $date): ?string
    {
        foreach ($this->prefixes as $p) {
            $candidate = $this->logDir . $p . $date . '.log';
            if (is_file($candidate)) {
                return $candidate;
            }
        }
        return null;
    }

    /**
     * Tail “efficiente”: prende le ultime N righe senza leggere tutto il file.
     */
    private function tailFile(string $file, int $lines = 500): string
    {
        $lines = max(1, min($lines, 50000)); // limite per sicurezza
        $fp = fopen($file, 'rb');
        if (!$fp) return '';

        $buffer = '';
        $chunkSize = 4096;
        fseek($fp, 0, SEEK_END);
        $pos = ftell($fp);
        $lineCount = 0;

        while ($pos > 0 && $lineCount <= $lines) {
            $readSize = ($pos - $chunkSize) >= 0 ? $chunkSize : $pos;
            $pos -= $readSize;
            fseek($fp, $pos, SEEK_SET);

            $chunk = fread($fp, $readSize);
            if ($chunk === false) break;

            $buffer = $chunk . $buffer;
            $lineCount = substr_count($buffer, "\n");
        }

        fclose($fp);

        $parts = preg_split("/\r\n|\n|\r/", $buffer);
        if (!$parts) return $buffer;

        $tailParts = array_slice($parts, -$lines);
        return implode("\n", $tailParts);
    }
}

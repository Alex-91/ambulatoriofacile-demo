<?php
namespace App\Services;

use Dompdf\Options;

/** Runtime caches belong in writable storage, never in the deployed vendor directory. */
final class BillingPdfOptionsFactory
{
    public function create(int $tenantId): Options
    {
        if ($tenantId <= 0) throw new \InvalidArgumentException('Spazio fatturazione non valido.');
        $root = rtrim(WRITEPATH, '/\\') . '/billing-pdf-runtime/' . $tenantId;
        foreach (['fonts', 'temp'] as $directory) {
            $path = $root . '/' . $directory;
            if ((!is_dir($path) && !mkdir($path, 0700, true) && !is_dir($path)) || !is_writable($path)) {
                throw new \RuntimeException('Storage temporaneo PDF fatturazione non disponibile.');
            }
        }
        $options = new Options();
        $options->setFontCache($root . '/fonts');
        $options->setTempDir($root . '/temp');
        // Preserve existing remote-logo behavior. This is not an outbound-network sandbox.
        $options->setIsRemoteEnabled(true);
        $options->setIsPhpEnabled(false);
        $options->setIsJavascriptEnabled(false);
        return $options;
    }
}

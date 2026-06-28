<?php
declare(strict_types=1);

namespace App\Services;

class DemoDatasetResetService
{
    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function run(array $options = []): array
    {
        $scriptPath = rtrim(ROOTPATH, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'tools' . DIRECTORY_SEPARATOR . 'SeedDemoData.php';
        if (!is_file($scriptPath)) {
            throw new \RuntimeException('Script demo seed non trovato: ' . $scriptPath);
        }

        require_once $scriptPath;

        if (!function_exists('seedDemoDataRun')) {
            throw new \RuntimeException('Funzione seedDemoDataRun non disponibile.');
        }

        $argv = [$scriptPath];

        $this->appendOption($argv, 'env-file', $this->stringOption($options, 'env_file', ['DEMO_SEED_ENV_FILE']));
        $this->appendOption($argv, 'host', $this->stringOption($options, 'host', ['database.default.hostname', 'DB_HOST']));
        $this->appendOption($argv, 'port', $this->stringOption($options, 'port', ['database.default.port', 'DB_PORT']));
        $this->appendOption($argv, 'user', $this->stringOption($options, 'user', ['database.default.username', 'DB_USERNAME']));
        $this->appendOption($argv, 'pass', $this->stringOption($options, 'pass', ['database.default.password', 'DB_PASSWORD']));
        $this->appendOption($argv, 'database', $this->stringOption($options, 'database', ['database.default.database', 'DB_DATABASE']));
        $this->appendOption($argv, 'db-key', $this->stringOption($options, 'db_key', ['DB_ENCRYPTION_KEY', 'database.default.DB_ENCRYPTION_KEY']));
        $this->appendOption($argv, 'db-mode', $this->stringOption($options, 'db_mode', ['DB_ENCRYPTION_MODE']));
        $this->appendOption($argv, 'brand', $this->stringOption($options, 'brand', ['PRODUCT_BRAND_NAME']));
        $this->appendOption($argv, 'demo-password', $this->stringOption($options, 'demo_password', ['DEMO_SEED_PASSWORD']));
        $this->appendOption($argv, 'agenda-start-date', $this->stringOption($options, 'agenda_start_date', ['DEMO_SEED_AGENDA_START_DATE']));
        $this->appendOption($argv, 'agenda-business-days', (string) $this->intOption($options, 'agenda_business_days', ['DEMO_SEED_AGENDA_BUSINESS_DAYS'], 5));

        return \seedDemoDataRun($argv);
    }

    /**
     * @param array<int, string> $argv
     */
    private function appendOption(array &$argv, string $name, string $value): void
    {
        $value = trim($value);
        if ($value === '') {
            return;
        }

        $argv[] = '--' . $name . '=' . $value;
    }

    /**
     * @param array<string, mixed> $options
     * @param array<int, string> $envKeys
     */
    private function stringOption(array $options, string $optionKey, array $envKeys): string
    {
        $directValue = trim((string) ($options[$optionKey] ?? ''));
        if ($directValue !== '') {
            return $directValue;
        }

        foreach ($envKeys as $envKey) {
            $envValue = $this->readEnvValue($envKey);
            if ($envValue !== '') {
                return $envValue;
            }
        }

        return '';
    }

    /**
     * @param array<string, mixed> $options
     * @param array<int, string> $envKeys
     */
    private function intOption(array $options, string $optionKey, array $envKeys, int $fallback): int
    {
        $value = trim((string) ($options[$optionKey] ?? ''));
        if ($value === '') {
            foreach ($envKeys as $envKey) {
                $value = $this->readEnvValue($envKey);
                if ($value !== '') {
                    break;
                }
            }
        }

        $parsed = (int) $value;
        return $parsed > 0 ? $parsed : $fallback;
    }

    private function readEnvValue(string $key): string
    {
        if (function_exists('env')) {
            $value = env($key);
            if ($value !== null && $value !== false && trim((string) $value) !== '') {
                return trim((string) $value);
            }
        }

        $raw = getenv($key);
        if ($raw === false) {
            return '';
        }

        return trim((string) $raw);
    }
}

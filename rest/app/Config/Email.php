<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

class Email extends BaseConfig
{
    public string $fromEmail  = '';
    public string $fromName   = '';
    public string $recipients = '';

    /**
     * The "user agent"
     */
    public string $userAgent = 'CodeIgniter';

    /**
     * The mail sending protocol: mail, sendmail, smtp
     */
    public string $protocol = 'mail';

    /**
     * The server path to Sendmail.
     */
    public string $mailPath = '/usr/sbin/sendmail';

    /**
     * SMTP Server Hostname
     */
    public string $SMTPHost = '';

    /**
     * SMTP Username
     */
    public string $SMTPUser = '';

    /**
     * SMTP Password
     */
    public string $SMTPPass = '';

    /**
     * SMTP Port
     */
    public int $SMTPPort = 25;

    /**
     * SMTP Timeout (in seconds)
     */
    public int $SMTPTimeout = 5;

    /**
     * Enable persistent SMTP connections
     */
    public bool $SMTPKeepAlive = false;

    /**
     * SMTP Encryption.
     *
     * @var string '', 'tls' or 'ssl'. 'tls' will issue a STARTTLS command
     *             to the server. 'ssl' means implicit SSL. Connection on port
     *             465 should set this to ''.
     */
    public string $SMTPCrypto = 'tls';

    /**
     * Enable word-wrap
     */
    public bool $wordWrap = true;

    /**
     * Character count to wrap at
     */
    public int $wrapChars = 76;

    /**
     * Type of mail, either 'text' or 'html'
     */
    public string $mailType = 'text';

    /**
     * Character set (utf-8, iso-8859-1, etc.)
     */
    public string $charset = 'UTF-8';

    /**
     * Whether to validate the email address
     */
    public bool $validate = false;

    /**
     * Email Priority. 1 = highest. 5 = lowest. 3 = normal
     */
    public int $priority = 3;

    /**
     * Newline character. (Use "\r\n" to comply with RFC 822)
     */
    public string $CRLF = "\r\n";

    /**
     * Newline character. (Use "\r\n" to comply with RFC 822)
     */
    public string $newline = "\r\n";

    /**
     * Enable BCC Batch Mode.
     */
    public bool $BCCBatchMode = false;

    /**
     * Number of emails in each BCC batch
     */
    public int $BCCBatchSize = 200;

    /**
     * Enable notify message from server
     */
    public bool $DSN = false;

    public function __construct()
    {
        parent::__construct();

        $smtpHost = $this->envString(['email.SMTPHost', 'EMAIL_SMTP_HOST']);
        $protocol = $this->envString(['email.protocol', 'EMAIL_PROTOCOL']);
        $smtpCrypto = $this->envNullableString(['email.SMTPCrypto', 'EMAIL_SMTP_CRYPTO']);

        $this->fromEmail     = $this->envString(['email.fromEmail', 'EMAIL_FROM_ADDRESS'], $this->fromEmail);
        $this->fromName      = $this->envString(['email.fromName', 'EMAIL_FROM_NAME'], $this->fromName);
        $this->recipients    = $this->envString(['email.recipients', 'EMAIL_RECIPIENTS'], $this->recipients);
        $this->protocol      = $protocol !== '' ? $protocol : ($smtpHost !== '' ? 'smtp' : $this->protocol);
        $this->mailPath      = $this->envString(['email.mailPath', 'EMAIL_MAIL_PATH'], $this->mailPath);
        $this->SMTPHost      = $smtpHost !== '' ? $smtpHost : $this->SMTPHost;
        $this->SMTPUser      = $this->envString(['email.SMTPUser', 'EMAIL_SMTP_USER'], $this->SMTPUser);
        $this->SMTPPass      = $this->envString(['email.SMTPPass', 'EMAIL_SMTP_PASS'], $this->SMTPPass);
        $this->SMTPPort      = $this->envInt(['email.SMTPPort', 'EMAIL_SMTP_PORT'], $this->SMTPPort);
        $this->SMTPTimeout   = $this->envInt(['email.SMTPTimeout', 'EMAIL_SMTP_TIMEOUT'], $this->SMTPTimeout);
        $this->SMTPKeepAlive = $this->envBool(['email.SMTPKeepAlive', 'EMAIL_SMTP_KEEP_ALIVE'], $this->SMTPKeepAlive);

        $normalizedSmtpCrypto = $smtpCrypto !== null ? strtolower(trim($smtpCrypto)) : null;

        if ($this->SMTPPort === 465 && in_array($normalizedSmtpCrypto, ['ssl', 'tls'], true)) {
            // CI4 already uses implicit TLS internally on port 465.
            // Normalizing legacy ssl/tls values avoids transport mismatches with some SMTP providers.
            $normalizedSmtpCrypto = '';
        }

        if ($normalizedSmtpCrypto !== null && $normalizedSmtpCrypto !== '') {
            $this->SMTPCrypto = $normalizedSmtpCrypto;
        } elseif ($this->SMTPPort === 465) {
            // Leave the value empty so the CI4 mailer can use its built-in implicit TLS handling for port 465.
            $this->SMTPCrypto = '';
        } elseif ($this->SMTPPort === 587) {
            // SMTP 587 commonly expects STARTTLS.
            $this->SMTPCrypto = 'tls';
        }

        $this->wordWrap      = $this->envBool(['email.wordWrap', 'EMAIL_WORD_WRAP'], $this->wordWrap);
        $this->wrapChars     = $this->envInt(['email.wrapChars', 'EMAIL_WRAP_CHARS'], $this->wrapChars);
        $this->mailType      = $this->envString(['email.mailType', 'EMAIL_MAIL_TYPE'], $this->mailType);
        $this->charset       = $this->envString(['email.charset', 'EMAIL_CHARSET'], $this->charset);
        $this->validate      = $this->envBool(['email.validate', 'EMAIL_VALIDATE'], $this->validate);
        $this->priority      = $this->envInt(['email.priority', 'EMAIL_PRIORITY'], $this->priority);
        $this->CRLF          = $this->envString(['email.CRLF', 'EMAIL_CRLF'], $this->CRLF);
        $this->newline       = $this->envString(['email.newline', 'EMAIL_NEWLINE'], $this->newline);
        $this->BCCBatchMode  = $this->envBool(['email.BCCBatchMode', 'EMAIL_BCC_BATCH_MODE'], $this->BCCBatchMode);
        $this->BCCBatchSize  = $this->envInt(['email.BCCBatchSize', 'EMAIL_BCC_BATCH_SIZE'], $this->BCCBatchSize);
        $this->DSN           = $this->envBool(['email.DSN', 'EMAIL_DSN'], $this->DSN);

    }

    /**
     * @param list<string> $keys
     */
    private function envString(array $keys, string $default = ''): string
    {
        foreach ($keys as $key) {
            $value = $this->getRawEnvValue($key);
            if ($value === null || $value === '') {
                continue;
            }

            return $value;
        }

        return $default;
    }

    /**
     * @param list<string> $keys
     */
    private function envNullableString(array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = $this->getRawEnvValue($key);
            if ($value === null) {
                continue;
            }

            return $value;
        }

        return null;
    }

    /**
     * @param list<string> $keys
     */
    private function envInt(array $keys, int $default): int
    {
        foreach ($keys as $key) {
            $value = $this->getRawEnvValue($key);
            if ($value === null || $value === '') {
                continue;
            }

            return (int) $value;
        }

        return $default;
    }

    /**
     * @param list<string> $keys
     */
    private function envBool(array $keys, bool $default): bool
    {
        foreach ($keys as $key) {
            $value = $this->getRawEnvValue($key);
            if ($value === null || $value === '') {
                continue;
            }

            $parsed = filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
            return $parsed ?? $default;
        }

        return $default;
    }

    private function getRawEnvValue(string $key): ?string
    {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
        if ($value === false || $value === null) {
            return null;
        }

        return $this->normalizeEnvValue($value);
    }

    /**
     * @param bool|float|int|string $value
     */
    private function normalizeEnvValue($value): string
    {
        $normalized = trim((string) $value);
        $length = strlen($normalized);

        if ($length >= 2) {
            $firstChar = $normalized[0];
            $lastChar = $normalized[$length - 1];

            if (($firstChar === "'" && $lastChar === "'") || ($firstChar === '"' && $lastChar === '"')) {
                $normalized = substr($normalized, 1, -1);
            }
        }

        return trim($normalized);
    }
}

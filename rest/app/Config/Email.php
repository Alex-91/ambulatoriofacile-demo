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
     * Newline character. (Use “\r\n” to comply with RFC 822)
     */
    public string $CRLF = "\r\n";

    /**
     * Newline character. (Use “\r\n” to comply with RFC 822)
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

        $smtpHost = trim((string) env('email.SMTPHost', ''));
        $protocol = trim((string) env('email.protocol', ''));

        $this->fromEmail     = trim((string) env('email.fromEmail', $this->fromEmail));
        $this->fromName      = trim((string) env('email.fromName', $this->fromName));
        $this->recipients    = trim((string) env('email.recipients', $this->recipients));
        $this->protocol      = $protocol !== '' ? $protocol : ($smtpHost !== '' ? 'smtp' : $this->protocol);
        $this->mailPath      = trim((string) env('email.mailPath', $this->mailPath));
        $this->SMTPHost      = $smtpHost !== '' ? $smtpHost : $this->SMTPHost;
        $this->SMTPUser      = trim((string) env('email.SMTPUser', $this->SMTPUser));
        $this->SMTPPass      = (string) env('email.SMTPPass', $this->SMTPPass);
        $this->SMTPPort      = (int) env('email.SMTPPort', $this->SMTPPort);
        $this->SMTPTimeout   = (int) env('email.SMTPTimeout', $this->SMTPTimeout);
        $this->SMTPKeepAlive = $this->envBool('email.SMTPKeepAlive', $this->SMTPKeepAlive);
        $this->SMTPCrypto    = (string) env('email.SMTPCrypto', $this->SMTPCrypto);
        $this->wordWrap      = $this->envBool('email.wordWrap', $this->wordWrap);
        $this->wrapChars     = (int) env('email.wrapChars', $this->wrapChars);
        $this->mailType      = trim((string) env('email.mailType', $this->mailType));
        $this->charset       = trim((string) env('email.charset', $this->charset));
        $this->validate      = $this->envBool('email.validate', $this->validate);
        $this->priority      = (int) env('email.priority', $this->priority);
        $this->CRLF          = (string) env('email.CRLF', $this->CRLF);
        $this->newline       = (string) env('email.newline', $this->newline);
        $this->BCCBatchMode  = $this->envBool('email.BCCBatchMode', $this->BCCBatchMode);
        $this->BCCBatchSize  = (int) env('email.BCCBatchSize', $this->BCCBatchSize);
        $this->DSN           = $this->envBool('email.DSN', $this->DSN);
    }

    private function envBool(string $key, bool $default): bool
    {
        $value = env($key);
        if ($value === null || $value === '') {
            return $default;
        }

        $parsed = filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
        return $parsed ?? $default;
    }
}

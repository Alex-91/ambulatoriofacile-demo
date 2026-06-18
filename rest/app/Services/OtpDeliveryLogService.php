<?php

namespace App\Services;

use App\Models\OtpDeliveryLogModel;

class OtpDeliveryLogService
{
    public const PURPOSE_LOGIN_MFA        = 'login_mfa';
    public const PURPOSE_PASSWORD_RESET   = 'password_reset';
    public const PURPOSE_PASSWORD_EXPIRED = 'password_expired';
    public const PURPOSE_PASSWORD_CHANGE  = 'password_change';

    private OtpDeliveryLogModel $model;

    public function __construct(?OtpDeliveryLogModel $model = null)
    {
        $this->model = $model ?? new OtpDeliveryLogModel();
    }

    public function record(
        string $purpose,
        string $channel,
        bool $success,
        ?int $userId = null,
        ?int $userType = null,
        ?string $errorMessage = null,
        array $meta = []
    ): void {
        if (!$this->model->tableExists()) {
            return;
        }

        $purpose = strtolower(trim($purpose));
        $channel = strtolower(trim($channel));
        $status = $success ? 'success' : 'failed';

        if ($purpose === '' || $channel === '') {
            return;
        }

        $payload = [
            'purpose'       => mb_substr($purpose, 0, 32),
            'channel'       => mb_substr($channel, 0, 16),
            'status'        => $status,
            'user_id'       => ($userId !== null && $userId > 0) ? $userId : null,
            'user_type'     => $userType !== null ? $userType : null,
            'error_message' => $errorMessage !== null && trim($errorMessage) !== ''
                ? mb_substr(trim($errorMessage), 0, 255)
                : null,
            'meta_json'     => !empty($meta)
                ? json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                : null,
            'created_at'    => date('Y-m-d H:i:s'),
        ];

        try {
            $this->model->insert($payload);
        } catch (\Throwable $e) {
            log_message('warning', 'OtpDeliveryLogService record failed: {message}', [
                'message' => $e->getMessage(),
            ]);
        }
    }
}

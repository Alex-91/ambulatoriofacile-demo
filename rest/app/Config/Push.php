<?php
namespace App\Config;

use CodeIgniter\Config\BaseConfig;

class Push extends BaseConfig
{
    public string $vapidPublicKey;
    public string $vapidSubject;

    public array $options = ['TTL'=>300, 'urgency'=>'normal'];

    public string $mode;            // 'remote'
    public string $remoteUrl;       // https://.../api/send
    public string $remoteApiKey;    // X-Push-Key
    public ?string $vercelBypass;   // opzionale

    public function __construct()
    {
        $this->vapidPublicKey = push_vapid_public_key();
        $this->vapidSubject   = trim(env('VAPID_SUBJECT','mailto:admin@example.com'));

        $this->mode         = 'remote';
        $this->remoteUrl    = rtrim((string) env('PUSH_REMOTE_URL',''), '/');
        $this->remoteApiKey = (string) env('PUSH_REMOTE_API_KEY','');
        $this->vercelBypass = env('PUSH_VERCEL_BYPASS_TOKEN'); // può essere null
    }
}

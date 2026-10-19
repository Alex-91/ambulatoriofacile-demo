<?php

namespace App\Services;

use CodeIgniter\Database\BaseConnection;

/** Separate identities: never grant a consultant a legacy doctor/staff session. */
class AccountantDelegationService
{
    private BaseConnection $db;
    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? \Config\Database::connect('platform');
    }

    public function ready(): bool
    {
        return $this->db->tableExists('billing_accountant_delegations')
            && $this->db->tableExists('billing_accountant_logins')
            && $this->db->tableExists('billing_accountant_audit');
    }

    public function forDoctor(int $doctor): array
    {
        return $this->db->table('billing_accountant_delegations')->where('tenant_id', $doctor)->orderBy('id', 'DESC')->get()->getResultArray();
    }

    public function forEmail(string $email): array
    {
        return $this->db->table('billing_accountant_delegations')->where('email', $email)->where('status', 'active')->get()->getResultArray();
    }

    public function invite(int $doctor, string $label, string $email, int $year): array
    {
        $email = strtolower(trim($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254 || $year < 2000 || $year > (int) date('Y')) {
            throw new \InvalidArgumentException('Inserisci un indirizzo email valido e un anno compreso tra il 2000 e quello corrente.');
        }
        $existing = $this->db->table('billing_accountant_delegations')->where('tenant_id', $doctor)->where('email', $email)->get()->getRowArray();
        if ($existing && $existing['status'] === 'active') {
            throw new \InvalidArgumentException('Questa delega è già attiva. Per cambiarla, revocala e invia un nuovo invito.');
        }
        if ($existing && strtotime($existing['created_at']) > time() - 60) {
            throw new \InvalidArgumentException('Attendi un minuto prima di inviare nuovamente l’invito.');
        }
        $token = bin2hex(random_bytes(32));
        $data = ['tenant_id' => $doctor, 'tenant_label' => mb_substr($label, 0, 190), 'email' => $email,
            'from_date' => sprintf('%04d-01-01', $year), 'status' => 'pending', 'token_hash' => hash('sha256', $token),
            'expires_at' => date('Y-m-d H:i:s', time() + 7 * 86400), 'created_at' => date('Y-m-d H:i:s'),
            'accepted_at' => null, 'revoked_at' => null, 'last_access_at' => null];
        if ($existing) {
            $id = (int) $existing['id'];
            $this->db->table('billing_accountant_delegations')->where('id', $id)->update($data);
        } else {
            $this->db->table('billing_accountant_delegations')->insert($data);
            $id = (int) $this->db->insertID();
        }
        $this->audit($id, 'invited', $data['from_date']);
        return ['id' => $id, 'token' => $token, 'email' => $email];
    }

    public function revoke(int $doctor, int $id): void
    {
        $this->db->table('billing_accountant_delegations')->where('id', $id)->where('tenant_id', $doctor)
            ->where('status !=', 'revoked')->update(['status' => 'revoked', 'revoked_at' => date('Y-m-d H:i:s'), 'token_hash' => null]);
        if ($this->db->affectedRows() > 0) {
            $this->audit($id, 'revoked');
        }
    }

    public function accept(string $token): ?string
    {
        if (!preg_match('/^[a-f0-9]{64}$/D', $token)) { return null; }
        $builder = $this->db->table('billing_accountant_delegations');
        $row = $builder->where('token_hash', hash('sha256', $token))->where('status', 'pending')
            ->where('expires_at >', date('Y-m-d H:i:s'))->get()->getRowArray();
        if (!$row) { return null; }
        $builder->where('id', $row['id'])->where('status', 'pending')->where('token_hash', hash('sha256', $token))
            ->where('expires_at >', date('Y-m-d H:i:s'))
            ->update(['status' => 'active', 'token_hash' => null, 'accepted_at' => date('Y-m-d H:i:s')]);
        if ($this->db->affectedRows() !== 1) { return null; }
        $this->audit((int) $row['id'], 'accepted');
        return $row['email'];
    }

    public function loginToken(string $email): ?string
    {
        $email = strtolower(trim($email));
        if (!$this->forEmail($email)) { return null; }
        $recent = $this->db->table('billing_accountant_logins')->where('email', $email)
            ->where('created_at >', date('Y-m-d H:i:s', time() - 300))->countAllResults();
        if ($recent > 0) { return null; }
        $token = bin2hex(random_bytes(32));
        $this->db->table('billing_accountant_logins')->insert(['email' => $email, 'token_hash' => hash('sha256', $token),
            'created_at' => date('Y-m-d H:i:s'), 'expires_at' => date('Y-m-d H:i:s', time() + 900)]);
        return $token;
    }

    public function consumeLogin(string $token): ?string
    {
        if (!preg_match('/^[a-f0-9]{64}$/D', $token)) { return null; }
        $builder = $this->db->table('billing_accountant_logins');
        $row = $builder->where('token_hash', hash('sha256', $token))->where('used_at', null)
            ->where('expires_at >', date('Y-m-d H:i:s'))->get()->getRowArray();
        if (!$row || !$this->forEmail($row['email'])) { return null; }
        $builder->where('id', $row['id'])->where('used_at', null)->where('expires_at >', date('Y-m-d H:i:s'))
            ->update(['used_at' => date('Y-m-d H:i:s')]);
        return $this->db->affectedRows() === 1 ? $row['email'] : null;
    }

    public function grant(string $email, int $doctor): ?array
    {
        // Query on EVERY read/export: revocation must also affect open sessions.
        return $this->db->table('billing_accountant_delegations')->where('email', $email)
            ->where('tenant_id', $doctor)->where('status', 'active')->get()->getRowArray();
    }

    public function audit(int $id, string $event, string $detail = ''): void
    {
        $this->db->table('billing_accountant_audit')->insert(['delegation_id' => $id,
            'event' => $event, 'detail' => $detail, 'created_at' => date('Y-m-d H:i:s')]);
        if ($event === 'view') {
            $this->db->table('billing_accountant_delegations')->where('id', $id)->update(['last_access_at' => date('Y-m-d H:i:s')]);
        }
    }

    public function history(int $doctor): array
    {
        return $this->db->table('billing_accountant_audit a')->select('a.*, d.email')
            ->join('billing_accountant_delegations d', 'd.id = a.delegation_id')->where('d.tenant_id', $doctor)
            ->orderBy('a.id', 'DESC')->limit(100)->get()->getResultArray();
    }

    public function sendLink(string $email, string $url, bool $invitation): bool
    {
        $mailer = \Config\Services::email(null, false);
        $config = config('Email');
        if (!$config->fromEmail) { throw new \RuntimeException('Mittente email non configurato.'); }
        $mailer->setFrom($config->fromEmail, $config->fromName ?: 'AmbulatorioFacile');
        $mailer->setTo($email);
        $mailer->setSubject($invitation ? 'Invito all’area commercialista' : 'Accesso all’area commercialista');
        $mailer->setMailType('text');
        $mailer->setMessage(($invitation
            ? "Hai ricevuto una delega per consultare fatture e report. Accetta entro 7 giorni:\n"
            : "Accedi con questo link personale monouso, valido per 15 minuti:\n") . $url
            . "\n\nNon inoltrare questo link. Se non riconosci la richiesta, ignora questa email.");
        return $mailer->send(false);
    }
}

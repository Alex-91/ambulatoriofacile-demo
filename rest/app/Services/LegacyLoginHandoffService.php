<?php

namespace App\Services;

use App\Libraries\Crypto_helper;
use App\Libraries\SystemUserMask;
use App\Models\AdminMenuModel;
use App\Models\ClientDoctorModel;

class LegacyLoginHandoffService
{
    private const DEFAULT_CLOCK_SKEW = 30;
    private const DEFAULT_MAX_LIFETIME = 120;

    private \CodeIgniter\Database\BaseConnection $db;
    private Crypto_helper $crypto;

    public function __construct(?\CodeIgniter\Database\BaseConnection $db = null)
    {
        $this->db = $db ?? \Config\Database::connect();
        $this->crypto = new Crypto_helper();
    }

    public function authenticateTransferredUser(string $payloadEncoded, string $signatureEncoded): array
    {
        $claims = $this->validateSignedPayload($payloadEncoded, $signatureEncoded);
        return $this->bootstrapSessionForUser($claims);
    }

    public function bootstrapDemoSessionByUserId(int $userId, string $expectedUsername = ''): array
    {
        $result = $this->bootstrapUserById($userId, $expectedUsername);

        if (($result['resp'] ?? 'KO') !== 'OK' || !(bool) ($result['requiresOtp'] ?? false)) {
            return $result;
        }

        $userType = (int) ($result['userType'] ?? 0);
        $result['redirectUrl'] = $this->confirmBootstrappedSession($userType);
        $result['requiresOtp'] = false;

        return $result;
    }

    public function bootstrapUserById(int $userId, string $expectedUsername = ''): array
    {
        return $this->bootstrapSessionForUser([
            'userId' => $userId,
            'username' => $expectedUsername,
        ]);
    }

    private function validateSignedPayload(string $payloadEncoded, string $signatureEncoded): array
    {
        $payloadEncoded = trim($payloadEncoded);
        $signatureEncoded = trim($signatureEncoded);

        if ($payloadEncoded === '' || $signatureEncoded === '') {
            throw new \RuntimeException('Parametri handoff mancanti.');
        }

        $secret = trim((string) env('AUTH_HANDOFF_SECRET', ''));
        if ($secret === '') {
            throw new \RuntimeException('AUTH_HANDOFF_SECRET non configurato.');
        }

        $decodedSignature = $this->base64UrlDecode($signatureEncoded);
        if ($decodedSignature === null) {
            throw new \RuntimeException('Firma handoff non valida.');
        }

        $expectedSignature = hash_hmac('sha256', $payloadEncoded, $secret, true);
        if (!hash_equals($expectedSignature, $decodedSignature)) {
            throw new \RuntimeException('Firma handoff non valida.');
        }

        $decodedPayload = $this->base64UrlDecode($payloadEncoded);
        if ($decodedPayload === null) {
            throw new \RuntimeException('Payload handoff non valido.');
        }

        $claims = json_decode($decodedPayload, true);
        if (!is_array($claims)) {
            throw new \RuntimeException('Payload handoff non valido.');
        }

        $issuer = trim((string) ($claims['iss'] ?? ''));
        $expectedIssuer = trim((string) env('AUTH_HANDOFF_ISSUER', ''));
        if ($expectedIssuer !== '' && $issuer !== $expectedIssuer) {
            throw new \RuntimeException('Issuer handoff non autorizzato.');
        }

        $userId = (int) ($claims['uid'] ?? $claims['userId'] ?? $claims['id_user'] ?? 0);
        if ($userId <= 0) {
            throw new \RuntimeException('Utente handoff non valido.');
        }

        $issuedAt = (int) ($claims['iat'] ?? 0);
        $expiresAt = (int) ($claims['exp'] ?? 0);
        $nonce = trim((string) ($claims['nonce'] ?? ''));

        if ($issuedAt <= 0 || $expiresAt <= 0 || $expiresAt <= $issuedAt) {
            throw new \RuntimeException('Intervallo handoff non valido.');
        }

        if (strlen($nonce) < 16) {
            throw new \RuntimeException('Nonce handoff non valido.');
        }

        $clockSkew = max(0, (int) env('AUTH_HANDOFF_CLOCK_SKEW', self::DEFAULT_CLOCK_SKEW));
        $maxLifetime = max(30, (int) env('AUTH_HANDOFF_MAX_LIFETIME', self::DEFAULT_MAX_LIFETIME));
        $now = time();

        if ($issuedAt > ($now + $clockSkew)) {
            throw new \RuntimeException('Handoff non ancora valido.');
        }

        if ($expiresAt < ($now - $clockSkew)) {
            throw new \RuntimeException('Handoff scaduto.');
        }

        if (($expiresAt - $issuedAt) > ($maxLifetime + $clockSkew)) {
            throw new \RuntimeException('Durata handoff eccessiva.');
        }

        $this->reserveNonce($nonce, max(60, ($expiresAt - $now) + $clockSkew + 5));

        return [
            'userId' => $userId,
            'issuer' => $issuer,
            'username' => trim((string) ($claims['username'] ?? $claims['usr'] ?? '')),
            'iat' => $issuedAt,
            'exp' => $expiresAt,
            'nonce' => $nonce,
        ];
    }

    private function reserveNonce(string $nonce, int $ttl): void
    {
        $cache = cache();
        $key = 'auth_handoff_nonce_' . sha1($nonce);

        if ($cache->get($key) !== null) {
            throw new \RuntimeException('Handoff gia utilizzato.');
        }

        if (!$cache->save($key, '1', max(60, min(600, $ttl)))) {
            throw new \RuntimeException('Impossibile riservare il nonce handoff.');
        }
    }

    private function bootstrapSessionForUser(array $claims): array
    {
        $user = $this->findUserById((int) $claims['userId']);
        if ($user === null) {
            throw new \RuntimeException('Utente handoff non trovato.');
        }

        $expectedUsername = trim((string) ($claims['username'] ?? ''));
        if ($expectedUsername !== '' && !$this->isCompatibleLegacyUsername($expectedUsername, (string) $user['username'])) {
            throw new \RuntimeException('Utente handoff non coerente.');
        }

        $this->resetAuthSession();

        $session = session();
        $session->set([
            'isLoggedIn' => true,
            'userId' => (int) $user['id_user'],
            'id_user' => (int) $user['id_user'],
            'username' => (string) $user['username'],
            'tipoUser' => (int) $user['tipo_user'],
            'loginSource' => 'legacy_handoff',
        ]);

        if ((string) ($user['resp'] ?? '') === 'SCADENZA') {
            $this->prepareExpiredPasswordFlow($user);

            return [
                'resp' => 'SCADENZA',
                'redirectUrl' => 'auth',
                'requiresOtp' => true,
                'userType' => (int) $user['tipo_user'],
            ];
        }

        if ((string) ($user['resp'] ?? '') !== 'OK') {
            throw new \RuntimeException('Stato utente non gestito per handoff.');
        }

        $userType = (int) $user['tipo_user'];

        if ($userType === 1) {
            $this->hydrateAdminSession((int) $user['id_user']);

            return [
                'resp' => 'OK',
                'redirectUrl' => 'admin',
                'requiresOtp' => false,
                'userType' => $userType,
            ];
        }

        if ($userType === 2) {
            $this->hydratePersonaleSession((int) $user['id_user'], $userType);
        } elseif ($userType === 3) {
            $this->hydrateClientSession((int) $user['id_user']);
        } elseif ($userType === 4) {
            $this->hydrateLegacySegSession((int) $user['id_user'], $userType);
        } else {
            throw new \RuntimeException('Tipo utente non supportato per handoff.');
        }

        $this->ensureOtpBootstrapState((int) $user['id_user'], $userType);

        return [
            'resp' => 'OK',
            'redirectUrl' => 'auth',
            'requiresOtp' => true,
            'userType' => $userType,
        ];
    }

    private function findUserById(int $userId): ?array
    {
        $sql = "SELECT a.*,
                       CASE WHEN datascadenza <= NOW() THEN 'SCADENZA' ELSE 'OK' END AS resp
                FROM dap01_users a
                WHERE a.id_user = ?
                LIMIT 1";

        $row = $this->db->query($sql, [$userId])->getRowArray();
        return $row ?: null;
    }

    private function resetAuthSession(): void
    {
        $session = session();
        $session->regenerate(true);
        $session->remove([
            'isLoggedIn',
            'isLoggedInConfirmed',
            'is_admin',
            'admin',
            'userId',
            'id_user',
            'username',
            'tipoUser',
            'nome_visualizzato',
            'cellulare',
            'utente_sess',
            'menuData',
            'menuAgenda',
            'menuDataAdmin',
            'tenant_app_admin',
            'requireDoctorSelection',
            'selectedDoctorId',
            'forcePwdChange',
            'pwd_userId',
            'pwd_username',
            'pwd_expired_flow',
            'otp_ok_for_expired',
            'reset_flow',
            'otp_ok_for_reset',
            'otp',
            'badge_posta_unread',
            'badge_chat_unread',
            'header_nav_items',
            'header_menu_items',
            'schede_access_map',
            'schede_data',
            'nav_refresh_meta',
            'otp_identity',
            'loginSource',
            'is_admin_arrow_login',
            'admin_arrow_username',
            TenantContextService::SESSION_KEY,
            LegacyTenantSessionService::SESSION_KEY_PENDING_SELECTION,
            LegacyTenantSessionService::SESSION_KEY_PENDING_RUNTIME,
        ]);
    }

    private function prepareExpiredPasswordFlow(array $user): void
    {
        $cellulare = $this->resolveCellulareByUserType((int) $user['id_user'], (int) $user['tipo_user']);

        $payload = [
            'forcePwdChange' => 1,
            'pwd_expired_flow' => 1,
            'pwd_userId' => (int) $user['id_user'],
            'pwd_username' => (string) $user['username'],
        ];

        if ($cellulare !== '') {
            $payload['cellulare'] = $cellulare;
        }

        session()->set($payload);
        if ($cellulare === '') {
            session()->remove('cellulare');
            log_message('info', '[LegacyLoginHandoffService] Password scaduta senza cellulare per userId={userId}; uso otp_identity uid fallback.', [
                'userId' => (int) $user['id_user'],
            ]);
        }

        $this->syncOtpIdentity((int) $user['id_user'], $cellulare);
        session()->remove('otp_ok_for_expired');
        session()->remove('otp');
    }

    private function hydrateAdminSession(int $userId): void
    {
        $sql = "SELECT a.tipo, a.id_user, a.id_personale,
                       " . $this->crypto->decrypt('a.nome') . ",
                       " . $this->crypto->decrypt('a.cognome') . ",
                       " . $this->crypto->decrypt('a.cellulare') . ",
                       " . $this->crypto->decrypt('a.email') . ",
                       " . $this->crypto->decrypt('a.qualifica') . ",
                       CONCAT(" . $this->crypto->decrypt_concat('a.qualifica') . ", ' ', " .
                           $this->crypto->decrypt_concat('a.cognome') . ", ' ', " .
                           $this->crypto->decrypt_concat('a.nome') . ") AS nome_completo
                FROM dap03_personale a
                WHERE id_user = ?
                LIMIT 1";

        $admin = $this->db->query($sql, [$userId])->getRowArray();
        if (!$admin) {
            throw new \RuntimeException('Profilo amministratore non trovato.');
        }

        $obj = new \stdClass();
        $obj->id_user = (int) $admin['id_user'];
        $obj->id_personale = (int) $admin['id_personale'];
        $obj->id_utente = (int) $admin['id_personale'];
        $obj->nome = (string) $admin['nome'];
        $obj->cognome = (string) $admin['cognome'];
        $obj->cellulare = (string) $admin['cellulare'];
        $obj->email = (string) $admin['email'];
        $obj->qualifica = (string) $admin['qualifica'];
        $obj->nome_completo = (string) $admin['nome_completo'];
        $obj->tipo = 1;
        $obj->tipo_pers = (int) $admin['tipo'];
        $obj->da_dottore = 0;
        $obj->tabella = 'dap10_message';
        $obj->tabella_reply = 'dap10_message_reply';

        $menuAdmin = (new AdminMenuModel())->getAdminMenu();

        session()->set([
            'nome_visualizzato' => trim($obj->nome . ' ' . $obj->cognome),
            'cellulare' => $obj->cellulare,
            'admin' => 1,
            'is_admin' => true,
            'isLoggedInConfirmed' => true,
            'utente_sess' => $obj,
            'menuDataAdmin' => ['result' => $menuAdmin],
        ]);
    }

    private function hydratePersonaleSession(int $userId, int $userType): void
    {
        $sql = "SELECT a.sostituto,
                       a.tipo,
                       CASE WHEN a.tipo = 1 THEN 'P'
                            WHEN a.tipo = 2 THEN 'I'
                            WHEN a.tipo = 3 THEN 'S'
                            ELSE '' END AS tipo_stringa,
                       a.id_user,
                       a.id_personale,
                       " . $this->crypto->decrypt('a.nome') . ",
                       " . $this->crypto->decrypt('a.cognome') . ",
                       " . $this->crypto->decrypt('a.cellulare') . ",
                       " . $this->crypto->decrypt('a.email') . ",
                       " . $this->crypto->decrypt('a.qualifica') . ",
                       CONCAT(" . $this->crypto->decrypt_concat('a.qualifica') . ", ' ', " .
                           $this->crypto->decrypt_concat('a.cognome') . ", ' ', " .
                           $this->crypto->decrypt_concat('a.nome') . ") AS nome_completo
                FROM dap03_personale a
                WHERE id_user = ?
                  AND ((((titolare = 1 AND sostituto = 0)
                      OR (titolare = 0 AND sostituto = 1)
                      OR (titolare = 1 AND sostituto = 1)) AND a.tipo = 1)
                       OR (a.tipo != 1 AND titolare = 0 AND sostituto = 0))
                LIMIT 1";

        $personale = $this->db->query($sql, [$userId])->getRowArray();
        if (!$personale) {
            $fallbackSql = "SELECT a.sostituto,
                                   a.tipo,
                                   CASE WHEN a.tipo = 1 THEN 'P'
                                        WHEN a.tipo = 2 THEN 'I'
                                        WHEN a.tipo = 3 THEN 'S'
                                        ELSE '' END AS tipo_stringa,
                                   a.id_user,
                                   a.id_personale,
                                   " . $this->crypto->decrypt('a.nome') . ",
                                   " . $this->crypto->decrypt('a.cognome') . ",
                                   " . $this->crypto->decrypt('a.cellulare') . ",
                                   " . $this->crypto->decrypt('a.email') . ",
                                   " . $this->crypto->decrypt('a.qualifica') . ",
                                   CONCAT(" . $this->crypto->decrypt_concat('a.qualifica') . ", ' ', " .
                                       $this->crypto->decrypt_concat('a.cognome') . ", ' ', " .
                                       $this->crypto->decrypt_concat('a.nome') . ") AS nome_completo
                            FROM dap03_personale a
                            WHERE id_user = ?
                            LIMIT 1";
            $personale = $this->db->query($fallbackSql, [$userId])->getRowArray();
        }
        if (!$personale) {
            throw new \RuntimeException('Profilo personale non trovato.');
        }

        $obj = new \stdClass();
        $obj->id_user = (int) $personale['id_user'];
        $obj->id_personale = (int) $personale['id_personale'];
        $obj->id_utente = (int) $personale['id_personale'];
        $obj->nome = (string) $personale['nome'];
        $obj->cognome = (string) $personale['cognome'];
        $obj->cellulare = (string) $personale['cellulare'];
        $obj->email = (string) $personale['email'];
        $obj->qualifica = (string) $personale['qualifica'];
        $obj->nome_completo = (string) $personale['nome_completo'];
        $obj->tipo = $userType;
        $obj->tipo_pers = (int) $personale['tipo'];
        $obj->tipo_stringa = (string) $personale['tipo_stringa'];
        $obj->sostituto = (int) $personale['sostituto'];
        $obj->da_dottore = 0;
        $obj->tabella = 'dap10_message';
        $obj->tabella_reply = 'dap10_message_reply';

        session()->set([
            'nome_visualizzato' => $obj->nome_completo,
            'cellulare' => $obj->cellulare,
            'utente_sess' => $obj,
        ]);
    }

    private function hydrateClientSession(int $userId): void
    {
        $sql = "SELECT a.id_user,
                       a.id_client,
                       a.id_personale,
                       " . $this->crypto->decrypt('a.nome') . ",
                       " . $this->crypto->decrypt('a.cognome') . ",
                       " . $this->crypto->decrypt('a.cellulare') . ",
                       " . $this->crypto->decrypt('a.email') . ",
                       " . $this->crypto->decrypt('a.indirizzo') . ",
                       " . $this->crypto->decrypt('a.citta') . ",
                       " . $this->crypto->decrypt('a.provincia') . ",
                       " . $this->crypto->decrypt('a.codice_fiscale') . "
                FROM dap02_clients a
                WHERE a.id_user = ?
                LIMIT 1";

        $client = $this->db->query($sql, [$userId])->getRowArray();
        if (!$client) {
            throw new \RuntimeException('Profilo cliente non trovato.');
        }

        $doctorLink = (new ClientDoctorModel())->getPreferredDoctorLinkForClient(
            (int)$client['id_client'],
            (int)($client['id_personale'] ?? 0)
        );
        if ((int)($doctorLink['relation_count'] ?? 0) > 1) {
            log_message('warning', '[LegacyLoginHandoffService] Relazioni duplicate in dap09_client_doctor per id_client={idClient}; uso id_dot={idDot} source={source}', [
                'idClient' => (int)$client['id_client'],
                'idDot' => (int)($doctorLink['id_dot'] ?? 0),
                'source' => (string)($doctorLink['source'] ?? 'none'),
            ]);
        }

        $obj = new \stdClass();
        $obj->id_user = (int) $client['id_user'];
        $obj->id_client = (int) $client['id_client'];
        $obj->nome = (string) $client['nome'];
        $obj->cognome = (string) $client['cognome'];
        $obj->cellulare = (string) $client['cellulare'];
        $obj->email = (string) $client['email'];
        $obj->indirizzo = (string) $client['indirizzo'];
        $obj->citta = (string) $client['citta'];
        $obj->provincia = (string) $client['provincia'];
        $obj->codice_fiscale = (string) $client['codice_fiscale'];
        $obj->id_doctor = (int)($doctorLink['id_dot'] ?? 0);
        $obj->tipo = 3;
        $obj->tabella = ' dap10_message';
        $obj->tabella_reply = ' dap10_message_reply';
        $obj->da_dottore = 1;
        $obj->id_utente = (int) $client['id_client'];
        if (SystemUserMask::isMaskedClientId((int) $obj->id_client)) {
            $obj->nome = SystemUserMask::SYSTEM_USER_LABEL;
            $obj->cognome = '';
            $obj->nome_completo = SystemUserMask::SYSTEM_USER_LABEL;
        }
        $displayName = SystemUserMask::getMaskedClientDisplayName(
            (int) $obj->id_client,
            trim($obj->nome . ' ' . $obj->cognome)
        );

        session()->set([
            'nome_visualizzato' => $displayName,
            'cellulare' => $obj->cellulare,
            'utente_sess' => $obj,
        ]);
    }

    private function hydrateLegacySegSession(int $userId, int $userType): void
    {
        $seg = $this->db->query(
            'SELECT id_user, id_inf, nome, cognome FROM dap13_seg WHERE id_user = ? LIMIT 1',
            [$userId]
        )->getRowArray();

        if (!$seg) {
            throw new \RuntimeException('Profilo segreteria legacy non trovato.');
        }

        $dotRows = $this->db->query(
            'SELECT id_dot FROM dap14_seg_dot WHERE id_inf = ?',
            [(int) $seg['id_inf']]
        )->getResultArray();

        $idPersonale = array_map(static fn(array $row): int => (int) $row['id_dot'], $dotRows);

        $obj = new \stdClass();
        $obj->id_user = (int) $seg['id_user'];
        $obj->nome = (string) $seg['nome'];
        $obj->cognome = (string) $seg['cognome'];
        $obj->id_inf = (int) $seg['id_inf'];
        $obj->tipo = $userType;
        $obj->tabella = ' dap10_message_seg';
        $obj->tabella_reply = ' dap10_message_reply_seg';
        $obj->da_dottore = 0;
        $obj->id_personale = empty($idPersonale) ? '' : '(' . implode(',', $idPersonale) . ')';

        session()->set([
            'nome_visualizzato' => trim($obj->nome . ' ' . $obj->cognome),
            'utente_sess' => $obj,
        ]);
    }

    private function ensureOtpBootstrapState(int $userId, int $userType): void
    {
        $session = session();
        $user = $session->get('utente_sess');

        if (!is_object($user)) {
            throw new \RuntimeException('Sessione handoff incompleta.');
        }

        $cellulare = trim((string) ($session->get('cellulare') ?? ''));
        if ($cellulare === '') {
            $cellulare = $this->resolveCellulareByUserType($userId, $userType);
        }

        if ($cellulare !== '') {
            $user->cellulare = $cellulare;
            $session->set('cellulare', $cellulare);
        } else {
            $user->cellulare = '';
            $session->remove('cellulare');
            log_message('info', '[LegacyLoginHandoffService] OTP bootstrap senza cellulare per userId={userId}, tipoUser={tipoUser}; uso fallback uid.', [
                'userId' => $userId,
                'tipoUser' => $userType,
            ]);
        }

        $this->syncOtpIdentity($userId, $cellulare);
        $session->set('utente_sess', $user);

        if (trim((string) ($session->get('nome_visualizzato') ?? '')) === '') {
            $displayName = trim((string) ($user->nome_completo ?? trim(($user->nome ?? '') . ' ' . ($user->cognome ?? ''))));
            if ($userType === 3) {
                $displayName = SystemUserMask::getMaskedClientDisplayName((int) ($user->id_client ?? 0), $displayName);
            }
            if ($displayName !== '') {
                $session->set('nome_visualizzato', $displayName);
            }
        }
    }

    private function confirmBootstrappedSession(int $userType): string
    {
        if ((bool) session()->get('isLoggedIn') !== true) {
            return '';
        }

        $menuAgenda = (new \App\Models\MenuModel())->getMenuAgenda();
        session()->set('menuAgenda', $menuAgenda);
        session()->set('isLoggedInConfirmed', true);

        (new SessionNavigationService())->refreshCurrentSession(true);

        if ($userType === 2) {
            $utente = session()->get('utente_sess');
            if ($utente && isset($utente->id_personale)) {
                $row = $this->db->query("
                    SELECT COUNT(*) AS totale
                    FROM dap18_sostituto
                    WHERE id_personale = ?
                      AND CURDATE() BETWEEN data_inizio AND data_fine
                ", [(int) $utente->id_personale])->getRowArray();

                if ((int) ($row['totale'] ?? 0) > 0) {
                    return 'sostituzioni';
                }
            }
        }

        return '';
    }

    private function resolveCellulareByUserType(int $userId, int $userType): string
    {
        if ($userType === 3) {
            $sql = "SELECT " . $this->crypto->decrypt('b.cellulare') . "
                    FROM dap02_clients b
                    WHERE b.id_user = ?
                    LIMIT 1";
        } else {
            $sql = "SELECT " . $this->crypto->decrypt('b.cellulare') . "
                    FROM dap03_personale b
                    WHERE b.id_user = ?
                    LIMIT 1";
        }

        $row = $this->db->query($sql, [$userId])->getRowArray();
        if (!$row) {
            return '';
        }

        $firstValue = reset($row);
        return trim((string) $firstValue);
    }

    private function syncOtpIdentity(int $userId, string $cellulare): void
    {
        $identity = trim($cellulare);
        if ($identity === '' && $userId > 0) {
            $identity = 'uid:' . $userId;
        }

        session()->remove('otp_identity');
        if ($identity !== '') {
            session()->set('otp_identity', $identity);
        }
    }

    private function base64UrlDecode(string $value): ?string
    {
        if ($value === '' || preg_match('/[^A-Za-z0-9\-_]/', $value)) {
            return null;
        }

        $remainder = strlen($value) % 4;
        if ($remainder > 0) {
            $value .= str_repeat('=', 4 - $remainder);
        }

        $decoded = base64_decode(strtr($value, '-_', '+/'), true);
        return $decoded === false ? null : $decoded;
    }

    private function isCompatibleLegacyUsername(string $expectedUsername, string $actualUsername): bool
    {
        $expectedUsername = trim($expectedUsername);
        $actualUsername = trim($actualUsername);

        if ($expectedUsername === '' || $actualUsername === '') {
            return false;
        }

        if (strcasecmp($expectedUsername, $actualUsername) === 0) {
            return true;
        }

        if (!str_contains($expectedUsername, '->')) {
            return false;
        }

        $parts = array_values(array_filter(array_map('trim', explode('->', $expectedUsername))));
        if (empty($parts)) {
            return false;
        }

        $legacyTarget = (string) end($parts);
        return $legacyTarget !== '' && strcasecmp($legacyTarget, $actualUsername) === 0;
    }
}
